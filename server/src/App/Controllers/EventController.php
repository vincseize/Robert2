<?php
declare(strict_types=1);

namespace Robert2\API\Controllers;

use DI\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Robert2\API\Controllers\Traits\WithCrud;
use Robert2\API\Controllers\Traits\WithPdf;
use Robert2\API\Errors\ValidationException;
use Robert2\API\Models\Event;
use Robert2\API\Models\Material;
use Robert2\API\Models\Park;
use Robert2\API\Services\Auth;
use Robert2\API\Services\I18n;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotFoundException;
use Slim\Http\Response;
use Slim\Http\ServerRequest as Request;

class EventController extends BaseController
{
    use WithCrud;
    use WithPdf;

    private I18n $i18n;

    public function __construct(Container $container, I18n $i18n)
    {
        parent::__construct($container);

        $this->i18n = $i18n;
    }

    // ——————————————————————————————————————————————————————
    // —
    // —    Actions
    // —
    // ——————————————————————————————————————————————————————

    public function getAll(Request $request, Response $response): Response
    {
        $search = $request->getQueryParam('search', null);
        $exclude = $request->getQueryParam('exclude', null);

        if ($search) {
            $query = (new Event)
                ->addSearch($search)
                ->select(['id', 'title', 'start_date', 'end_date', 'location'])
                ->whereHas('materials');

            if ($exclude) {
                $query->where('id', '<>', $exclude);
            }

            $count = $query->count();
            $results = $query->orderBy('start_date', 'desc')->limit(10)->get();
            return $response->withJson(['count' => $count, 'data' => $results]);
        }

        $startDate = $request->getQueryParam('start', null);
        $endDate = $request->getQueryParam('end', null);

        // - Limitation de la période récupérable (en mois)
        $maxMonth = 3.5;
        $maxTime = 60 * 60 * 24 * 30 * $maxMonth;
        $diffTime = strtotime($endDate) - strtotime($startDate);
        if ($diffTime > $maxTime) {
            throw new HttpException(
                $request,
                sprintf('The retrieval period for events may not exceed %s months.', $maxMonth),
                416
            );
        }

        $query = Event::inPeriod($startDate, $endDate)
            ->with('Beneficiaries')
            ->with('Technicians')
            ->with(['Materials' => function ($q) {
                $q->orderBy('name', 'asc');
            }]);

        $deleted = (bool)$request->getQueryParam('deleted', false);
        if ($deleted) {
            $query->onlyTrashed();
        }

        $events = $query->get();

        $concurrentEvents = Event::inPeriod($startDate, $endDate)
            ->with('Materials')
            ->get()->toArray();

        foreach ($events as $event) {
            $event->__cachedConcurrentEvents = array_values(
                array_filter($concurrentEvents, function ($otherEvent) use ($event) {
                    $startDate = new \DateTime($event->start_date);
                    $otherStartDate = new \DateTime($otherEvent['start_date']);
                    $endDate = new \DateTime($event->end_date);
                    $otherEndDate = new \DateTime($otherEvent['end_date']);
                    return (
                        $event->id !== $otherEvent['id'] &&
                        $startDate <= $otherEndDate &&
                        $endDate >= $otherStartDate
                    );
                })
            );
        }

        $data = $events->each
            ->setAppends([
                'has_missing_materials',
                'has_not_returned_materials',
            ])
            ->toArray();

        $useMultipleParks = Park::count() > 1;
        foreach ($data as $index => $event) {
            $data[$index]['parks'] = $useMultipleParks
                ? Event::getParks($event['materials'])
                : null;
        }

        return $response->withJson(compact('data'));
    }

    public function getOne(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        if (!Event::staticExists($id)) {
            throw new HttpNotFoundException($request);
        }

        $eventData = $this->_getFormattedEvent($id, true);
        return $response->withJson($eventData);
    }

    public function getMissingMaterials(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $result = Event::findOrFail($id)->missingMaterials();
        return $response->withJson($result ?: []);
    }

    public function create(Request $request, Response $response): Response
    {
        $postData = (array)$request->getParsedBody();
        if (!isset($postData['user_id'])) {
            $postData['user_id'] = Auth::user()->id;
        }
        $id = $this->_saveEvent(null, $postData);

        return $response->withJson($this->_getFormattedEvent($id), SUCCESS_CREATED);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        if (!Event::staticExists($id)) {
            throw new HttpNotFoundException($request);
        }

        $postData = (array)$request->getParsedBody();
        $id = $this->_saveEvent($id, $postData);

        return $response->withJson($this->_getFormattedEvent($id), SUCCESS_OK);
    }

    public function duplicate(Request $request, Response $response): Response
    {
        $originalId = (int)$request->getAttribute('id');
        $postData = (array)$request->getParsedBody();

        try {
            $newEvent = Event::duplicate($originalId, $postData);
        } catch (ModelNotFoundException $e) {
            throw new HttpNotFoundException($request);
        }

        return $response->withJson($this->_getFormattedEvent($newEvent->id), SUCCESS_CREATED);
    }

    public function updateMaterialReturn(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        if (!Event::staticExists($id)) {
            throw new HttpNotFoundException($request);
        }

        $data = (array)$request->getParsedBody();
        $this->_saveReturnQuantities($id, $data);

        return $response->withJson($this->_getFormattedEvent($id), SUCCESS_OK);
    }

    public function updateMaterialTerminate(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        if (!Event::staticExists($id)) {
            throw new HttpNotFoundException($request);
        }

        $data = (array)$request->getParsedBody();
        $this->_saveReturnQuantities($id, $data);

        Event::staticEdit($id, [
            'is_confirmed' => true,
            'is_return_inventory_done' => true,
        ]);

        $this->_setBrokenMaterialsQuantities($data);

        return $response->withJson($this->_getFormattedEvent($id), SUCCESS_OK);
    }

    // ——————————————————————————————————————————————————————
    // —
    // —    Internal Methods
    // —
    // ——————————————————————————————————————————————————————

    protected function _saveEvent(?int $id, array $postData): int
    {
        if (empty($postData)) {
            throw new \InvalidArgumentException(
                "Missing request data to process validation",
                ERROR_VALIDATION
            );
        }

        $event = Event::staticEdit($id, $postData);

        return $event->id;
    }

    protected function _saveReturnQuantities(int $id, array $data): void
    {
        $event = Event::find($id);

        $eventMaterials = $event->Materials()->get()->toArray();
        $eventMaterialsQuantities = [];
        foreach ($eventMaterials as $material) {
            $eventMaterialsQuantities[$material['id']] = $material['pivot']['quantity'];
        };

        $quantities = [];
        $errors = [];
        foreach ($data as $quantity) {
            if (!array_key_exists('id', $quantity)) {
                continue;
            }
            $materialId = $quantity['id'];

            if (!array_key_exists('actual', $quantity) || !is_integer($quantity['actual'])) {
                $errors[] = [
                    'id' => $materialId,
                    'message' => $this->i18n->translate('returned-quantity-not-valid'),
                ];
                continue;
            }
            $actual = (int)$quantity['actual'];

            if (!array_key_exists('broken', $quantity) || !is_integer($quantity['broken'])) {
                $errors[] = [
                    'id' => $materialId,
                    'message' => $this->i18n->translate('broken-quantity-not-valid'),
                ];
                continue;
            }
            $broken = (int)$quantity['broken'];

            if ($actual < 0 || $broken < 0) {
                $errors[] = [
                    'id' => $materialId,
                    'message' => $this->i18n->translate('quantities-cannot-be-negative'),
                ];
                continue;
            }

            if ($actual > $eventMaterialsQuantities[$materialId]) {
                $errors[] = [
                    'id' => $materialId,
                    'message' => $this->i18n->translate(
                        'returned-quantity-cannot-be-greater-than-output-quantity'
                    ),
                ];
                continue;
            }

            if ($broken > $actual) {
                $errors[] = [
                    'id' => $materialId,
                    'message' => $this->i18n->translate(
                        'broken-quantity-cannot-be-greater-than-returned-quantity'
                    ),
                ];
                continue;
            }

            $quantities[$materialId] = [
                'quantity_returned' => $actual,
                'quantity_broken' => $broken,
            ];
        }

        if (!empty($errors)) {
            $error = new ValidationException();
            $error->setValidationErrors($errors);
            throw $error;
        }

        $event->Materials()->sync($quantities);
    }

    protected function _setBrokenMaterialsQuantities(array $data): void
    {
        foreach ($data as $quantities) {
            $broken = (int)$quantities['broken'];
            if ($broken === 0) {
                continue;
            }

            $material = Material::find($quantities['id']);
            if (!$material) {
                continue;
            }

            $material->out_of_order_quantity += (int)$quantities['broken'];
            $material->save();
        }
    }

    protected function _getFormattedEvent(int $id, bool $withDetails = false): array
    {
        $model = (new Event)
            ->with('User')
            ->with('Technicians')
            ->with('Beneficiaries')
            ->with('Materials')
            ->with('Bills')
            ->with('Estimates')
            ->find($id);

        if ($withDetails) {
            $model = $model->setAppends([
                'has_missing_materials',
                'has_not_returned_materials',
            ]);
        }

        $result = $model->toArray();
        if ($model->bills) {
            $result['bills'] = $model->bills;
        }
        if ($model->estimates) {
            $result['estimates'] = $model->estimates;
        }

        return $result;
    }
}

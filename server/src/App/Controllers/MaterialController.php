<?php
declare(strict_types=1);

namespace Robert2\API\Controllers;

use DI\Container;
use Illuminate\Support\Carbon;
use Robert2\API\Config\Config;
use Robert2\API\Controllers\Traits\Taggable;
use Robert2\API\Controllers\Traits\WithCrud;
use Robert2\API\Controllers\Traits\FileResponse;
use Robert2\Lib\Pdf\Pdf;
use Robert2\Lib\Domain\MaterialsData;
use Robert2\API\Models\Document;
use Robert2\API\Models\Event;
use Robert2\API\Models\Material;
use Robert2\API\Models\Category;
use Robert2\API\Models\Park;
use Robert2\API\Services\I18n;
use Slim\Exception\HttpNotFoundException;
use Slim\Http\Response;
use Slim\Http\ServerRequest as Request;
use Slim\HttpCache\CacheProvider as HttpCacheProvider;

class MaterialController extends BaseController
{
    use WithCrud, FileResponse, Taggable {
        Taggable::getAll insteadof WithCrud;
    }

    /** @var I18n */
    private $i18n;

    /** @var HttpCacheProvider */
    private $httpCache;

    public function __construct(Container $container, I18n $i18n, HttpCacheProvider $httpCache)
    {
        parent::__construct($container);

        $this->i18n = $i18n;
        $this->httpCache = $httpCache;
    }

    // ——————————————————————————————————————————————————————
    // —
    // —    Getters
    // —
    // ——————————————————————————————————————————————————————

    public function getAll(Request $request, Response $response): Response
    {
        $paginated = (bool)$request->getQueryParam('paginated', true);
        $searchTerm = $request->getQueryParam('search', null);
        $searchField = $request->getQueryParam('searchBy', null);
        $parkId = $request->getQueryParam('park', null);
        $categoryId = $request->getQueryParam('category', null);
        $subCategoryId = $request->getQueryParam('subCategory', null);
        $dateForQuantities = $request->getQueryParam('dateForQuantities', null);
        $withDeleted = (bool)$request->getQueryParam('deleted', false);
        $tags = $request->getQueryParam('tags', []);
        $withEvents = (bool)$request->getQueryParam('with-events', false);

        $options = [];
        if ($parkId) {
            $options['park_id'] = (int)$parkId;
        }
        if ($categoryId) {
            $options['category_id'] = $categoryId !== 'uncategorized'
                ? (int)$categoryId
                : null;
        }
        if ($subCategoryId) {
            $options['sub_category_id'] = (int)$subCategoryId;
        }

        $orderBy = $request->getQueryParam('orderBy', null);
        $ascending = (bool)$request->getQueryParam('ascending', true);

        $model = (new Material)
            ->setOrderBy($orderBy, $ascending)
            ->setSearch($searchTerm, $searchField);

        if (empty($options) && empty($tags)) {
            $model = $model->getAll($withDeleted);
        } else {
            $model = $model->getAllFilteredOrTagged($options, $tags, $withDeleted);
        }
        if ($withEvents) {
            $model
                ->with('Events', function ($query) {
                    $query->where('end_date', '>=', Carbon::now());
                })
                ->get();
        }

        if ($paginated) {
            $results = $this->paginate($request, $model);
        } else {
            $results = ['data' => $model->get()->toArray()];
        }

        // - Filtre des quantités pour une date ou une période donnée
        if (!is_array($dateForQuantities)) {
            $dateForQuantities = array_fill_keys(['start', 'end'], $dateForQuantities);
        }
        if (empty($dateForQuantities['start']) ||
            !is_string($dateForQuantities['start']) ||
            empty($dateForQuantities['end']) ||
            !is_string($dateForQuantities['end'])
        ) {
            $dateForQuantities = array_fill_keys(['start', 'end'], null);
        }

        $results['data'] = Material::recalcQuantitiesForPeriod(
            $results['data'],
            $dateForQuantities['start'],
            $dateForQuantities['end']
        );

        if (!$paginated) {
            $results = $results['data'];
        }

        return $response->withJson($results);
    }

    public function getAllPdf(Request $request, Response $response): Response
    {
        $onlyParkId = $request->getQueryParam('park', null);

        $categories = Category::get()->toArray();
        $parks = Park::with('materials')->get()->each->setAppends([])->toArray();

        $parksMaterials = [];
        foreach ($parks as $park) {
            if ($onlyParkId && (int)$onlyParkId !== $park['id']) {
                continue;
            }

            if (empty($park['materials'])) {
                continue;
            }

            $materialsData = new MaterialsData(array_values($park['materials']));
            $materialsData->setParks($parks)->setCategories($categories);
            $parksMaterials[] = [
                'id' => $park['id'],
                'name' => $park['name'],
                'materials' => $materialsData->getBySubCategories(true),
            ];
        }

        usort($parksMaterials, function ($a, $b) {
            return strcmp($a['name'], $b['name'] ?: '');
        });

        $company = Config::getSettings('companyData');

        $parkOnlyName = null;
        if ($onlyParkId) {
            $parkKey = array_search($onlyParkId, array_column($parks, 'id'));
            if ($parkKey !== false) {
                $parkOnlyName = $parks[$parkKey]['name'];
            }
        }

        $fileName = sprintf(
            '%s-%s-%s.pdf',
            slugify($this->i18n->translate('materials-list')),
            slugify($parkOnlyName ?: $company['name']),
            (new \DateTime())->format('Y-m-d')
        );
        if (Config::getEnv() === 'test') {
            $fileName = sprintf('TEST-%s', $fileName);
        }

        $data = [
            'locale' => Config::getSettings('defaultLang'),
            'company' => $company,
            'parkOnlyName' => $parkOnlyName,
            'currency' => Config::getSettings('currency')['iso'],
            'parksMaterialsList' => $parksMaterials,
        ];

        $fileContent = Pdf::createFromTemplate('materials-list-default', $data);

        return $this->_responseWithFile($response, $fileName, $fileContent);
    }

    public function getAllWhileEvent(Request $request, Response $response): Response
    {
        $eventId = (int)$request->getAttribute('eventId');

        $currentEvent = Event::find($eventId);
        if (!$currentEvent) {
            throw new HttpNotFoundException($request);
        }

        $results = (new Material)
            ->setOrderBy('reference', true)
            ->getAll()
            ->get()
            ->toArray();

        if ($results && count($results) > 0) {
            $results = Material::recalcQuantitiesForPeriod(
                $results,
                $currentEvent->start_date,
                $currentEvent->end_date,
                $eventId
            );
        }

        return $response->withJson($results);
    }

    public function getAllDocuments(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $model = Material::find($id);
        if (!$model) {
            throw new HttpNotFoundException($request);
        }

        return $response->withJson($model->documents, SUCCESS_OK);
    }

    public function getParkAll(Request $request, Response $response): Response
    {
        $parkId = (int)$request->getAttribute('parkId');
        if (!Park::staticExists($parkId)) {
            throw new HttpNotFoundException($request);
        }

        $materials = Material::getParkAll($parkId);
        return $response->withJson($materials);
    }

    public function getEvents(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $material = Material::find($id);
        if (!$material) {
            throw new HttpNotFoundException($request);
        }

        $collection = [];
        $useMultipleParks = Park::count() > 1;
        foreach ($material->Events()->get() as $event) {
            $event = $event->setAppends([
                'has_missing_materials',
                'has_not_returned_materials',
            ]);

            $collection[] = array_replace($event->toArray(), [
                'parks' => $useMultipleParks
                    ? Event::getParks($event['materials'])
                    : null
            ]);
        }

        return $response->withJson($collection, SUCCESS_OK);
    }

    public function getPicture(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        $model = Material::find($id);
        if (!$model) {
            throw new HttpNotFoundException($request);
        }

        $picturePath = $model->picture_real_path;
        if (!$picturePath) {
            throw new HttpNotFoundException($request, "Il n'y a pas d'image pour ce matériel.");
        }

        /** @var Response $response */
        $response = $this->httpCache->denyCache($response);
        return $response->withFile($picturePath);
    }

    // ------------------------------------------------------
    // -
    // -    Setters
    // -
    // ------------------------------------------------------

    public function create(Request $request, Response $response): Response
    {
        $postData = (array)$request->getParsedBody();
        $result = $this->_saveMaterial(null, $postData);
        return $response->withJson($result, SUCCESS_CREATED);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        if (!Material::staticExists($id)) {
            throw new HttpNotFoundException($request);
        }

        $postData = (array)$request->getParsedBody();
        $result = $this->_saveMaterial($id, $postData);
        return $response->withJson($result, SUCCESS_OK);
    }

    public function handleUploadDocuments(Request $request, Response $response): Response
    {
        $id = (int)$request->getAttribute('id');
        if (!Material::staticExists($id)) {
            throw new HttpNotFoundException($request);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $destDirectory = Document::getFilePath($id);

        if (count($uploadedFiles) === 0) {
            throw new \Exception($this->i18n->translate('no-uploaded-files'));
        }

        $files = [];
        $errors = [];
        foreach ($uploadedFiles as $file) {
            $error = $file->getError();
            if ($error !== UPLOAD_ERR_OK) {
                $errors[$file->getClientFilename()] = $this->i18n->translate(
                    'upload-failed-error-code',
                    [$error],
                );
                continue;
            }

            $fileSize = $file->getSize();
            if ($fileSize > Config::getSettings('maxFileUploadSize')) {
                $errors[$file->getClientFilename()] = $this->i18n->translate('file-exceeds-max-size');
                continue;
            }

            $fileType = $file->getClientMediaType();
            if (!in_array($fileType, Config::getSettings('authorizedFileTypes'))) {
                $errors[$file->getClientFilename()] = $this->i18n->translate('file-type-not-allowed');
                continue;
            }

            $filename = moveUploadedFile($destDirectory, $file);
            if (!$filename) {
                $errors[$file->getClientFilename()] = $this->i18n->translate('saving-uploaded-file-failed');
                continue;
            }

            $files[] = [
                'material_id' => $id,
                'name' => $filename,
                'type' => $fileType,
                'size' => $file->getSize(),
            ];
        }

        foreach ($files as $document) {
            try {
                Document::updateOrCreate(
                    ['material_id' => $id, 'name' => $document['name']],
                    $document
                );
            } catch (\Exception $e) {
                $filePath = Document::getFilePath($id, $document['name']);
                unlink($filePath);
                $errors[$document['name']] = $this->i18n->translate(
                    'document-cannot-be-saved-in-db',
                    [$e->getMessage()],
                );
            }
        }

        if (count($errors) > 0) {
            throw new \Exception(implode("\n", $errors));
        }

        return $response->withJson($files, SUCCESS_OK);
    }

    // ------------------------------------------------------
    // —
    // —    Internal Methods
    // —
    // ------------------------------------------------------

    protected function _saveMaterial(?int $id, $postData): array
    {
        if (!is_array($postData) || empty($postData)) {
            throw new \InvalidArgumentException(
                "Missing request data to process validation",
                ERROR_VALIDATION
            );
        }

        if (array_key_exists('stock_quantity', $postData)) {
            $stockQuantity = $postData['stock_quantity'];
            if ($stockQuantity !== null && (int)$stockQuantity < 0) {
                $postData['stock_quantity'] = 0;
            }
        }

        if (array_key_exists('out_of_order_quantity', $postData)) {
            $stockQuantity = (int)($postData['stock_quantity'] ?? 0);
            $outOfOrderQuantity = (int)$postData['out_of_order_quantity'];
            if ($outOfOrderQuantity > $stockQuantity) {
                $outOfOrderQuantity = $stockQuantity;
                $postData['out_of_order_quantity'] = $outOfOrderQuantity;
            }
            if ($outOfOrderQuantity <= 0) {
                $postData['out_of_order_quantity'] = null;
            }
        }

        $result = Material::staticEdit($id, $postData);

        if (isset($postData['attributes'])) {
            $attributes = [];
            foreach ($postData['attributes'] as $attribute) {
                if (empty($attribute['value'])) {
                    continue;
                }

                $attributes[$attribute['id']] = [
                    'value' => (string)$attribute['value']
                ];
            }
            $result->Attributes()->sync($attributes);
        }

        return Material::find($result->id)->toArray();
    }
}

<?php
namespace Robert2\Tests;

final class CategoriesTest extends ApiTestCase
{
    public function testGetCategories()
    {
        $this->client->get('/api/categories');
        $this->assertStatusCode(SUCCESS_OK);
        $this->assertResponseData([
            'pagination' => [
                'currentPage' => 1,
                'perPage' => $this->settings['maxItemsPerPage'],
                'total' => ['items' => 4, 'pages' => 1],
            ],
            'data' => [
                [
                    'id' => 4,
                    'name' => 'Décors',
                    'created_at' => null,
                    'updated_at' => null,
                    'sub_categories' => [],
                ],
                [
                    'id' => 2,
                    'name' => 'light',
                    'created_at' => null,
                    'updated_at' => null,
                    'sub_categories' => [
                        [
                            'id' => 4,
                            'name' => 'dimmers',
                            'category_id' => 2,
                        ],
                        [
                            'id' => 3,
                            'name' => 'projectors',
                            'category_id' => 2,
                        ],
                    ],
                ],
                [
                    'id' => 1,
                    'name' => 'sound',
                    'created_at' => null,
                    'updated_at' => null,
                    'sub_categories' => [
                        [
                            'id' => 1,
                            'name' => 'mixers',
                            'category_id' => 1,
                        ],
                        [
                            'id' => 2,
                            'name' => 'processors',
                            'category_id' => 1,
                        ],
                    ],
                ],
                [
                    'id' => 3,
                    'name' => 'transport',
                    'created_at' => null,
                    'updated_at' => null,
                    'sub_categories' => [],
                ],
            ],
        ]);
    }

    public function testGetCategorieNotFound()
    {
        $this->client->get('/api/categories/999');
        $this->assertNotFound();
    }

    public function testGetCategory()
    {
        $this->client->get('/api/categories/1');
        $this->assertStatusCode(SUCCESS_OK);
        $this->assertResponseData([
            'id' => 1,
            'name' => 'sound',
            'created_at' => null,
            'updated_at' => null,
            'sub_categories' => [
                [
                    'id' => 1,
                    'name' => 'mixers',
                    'category_id' => 1,
                ],
                [
                    'id' => 2,
                    'name' => 'processors',
                    'category_id' => 1,
                ],
            ],
        ]);
    }

    public function testCreateCategory()
    {
        $this->client->post('/api/categories', ['name' => 'New Category']);
        $this->assertStatusCode(SUCCESS_CREATED);
        $this->assertResponseData([
            'id' => 5,
            'name' => 'New Category',
            'sub_categories' => [],
            'created_at' => 'fakedTestContent',
            'updated_at' => 'fakedTestContent',
        ], ['created_at', 'updated_at']);
    }

    public function testUpdateCategoryNoData()
    {
        $this->client->put('/api/categories/1', []);
        $this->assertStatusCode(ERROR_VALIDATION);
        $this->assertErrorMessage("Missing request data to process validation");
    }

    public function testUpdateCategoryNotFound()
    {
        $this->client->put('/api/categories/999', ['name' => '__inexistant__']);
        $this->assertNotFound();
    }

    public function testUpdateCategory()
    {
        $this->client->put('/api/categories/1', ['name' => 'Sound edited']);
        $this->assertStatusCode(SUCCESS_OK);
        $this->assertResponseData([
            'id' => 1,
            'name' => 'Sound edited',
            'created_at' => null,
            'updated_at' => 'fakedTestContent',
            'sub_categories' => [
                [
                    'id' => 1,
                    'name' => 'mixers',
                    'category_id' => 1,
                ],
                [
                    'id' => 2,
                    'name' => 'processors',
                    'category_id' => 1,
                ],
            ],
        ], ['updated_at']);
    }

    public function testDeleteCategory()
    {
        $this->client->delete('/api/categories/3');
        $this->assertStatusCode(SUCCESS_OK);
        $this->assertResponseData(['destroyed' => true]);

        $this->client->get('/api/categories/3');
        $this->assertNotFound();
    }
}

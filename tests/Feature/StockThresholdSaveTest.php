<?php

namespace Tests\Feature;

use App\Http\Controllers\StockThresholdController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockThresholdSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'threshold_test', 'database.connections.threshold_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::statement('CREATE TABLE tb_products (id INTEGER PRIMARY KEY, is_active INTEGER)');
        DB::statement('CREATE TABLE tb_product_store_thresholds (id INTEGER PRIMARY KEY, product_id INTEGER, store_id INTEGER, min_stock INTEGER, max_stock INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(product_id, store_id))');
        DB::table('tb_products')->insert(['id' => 1, 'is_active' => 1]);
        DB::table('tb_product_store_thresholds')->insert(['product_id' => 1, 'store_id' => 1, 'min_stock' => 2, 'max_stock' => 10]);
    }

    private function save(array $data)
    {
        $user = new User();
        $user->forceFill(['id' => 'admin', 'roles' => 'superadmin']);
        $request = Request::create('/stock-threshold', 'POST', array_merge(['store_id' => 1], $data));
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        return app(StockThresholdController::class)->save($request);
    }

    public function test_json_payload_updates_existing_and_creates_new_limits(): void
    {
        DB::table('tb_products')->insert(['id' => 2, 'is_active' => 1]);
        $response = $this->save(['expected_count' => 2, 'items_payload' => json_encode([
            1 => ['min_stock' => '5', 'max_stock' => '20'],
            2 => ['min_stock' => '0', 'max_stock' => '30'],
        ])]);
        $this->assertNotNull($response->getSession()->get('success'));
        $this->assertEquals(5, DB::table('tb_product_store_thresholds')->where('product_id', 1)->value('min_stock'));
        $this->assertEquals(30, DB::table('tb_product_store_thresholds')->where('product_id', 2)->value('max_stock'));
    }

    public function test_empty_submission_does_not_report_success(): void
    {
        $response = $this->save(['expected_count' => 0]);
        $this->assertNull($response->getSession()->get('success'));
        $this->assertNotNull($response->getSession()->get('warning'));
    }

    public function test_truncated_payload_is_rejected_without_partial_update(): void
    {
        $response = $this->save(['expected_count' => 2, 'items' => [1 => ['min_stock' => 5, 'max_stock' => 20]]]);
        $this->assertNotNull($response->getSession()->get('error'));
        $this->assertEquals(2, DB::table('tb_product_store_thresholds')->value('min_stock'));
    }

    public function test_legacy_form_still_saves_zero_and_leaves_other_store_unchanged(): void
    {
        DB::table('tb_product_store_thresholds')->insert(['product_id' => 1, 'store_id' => 2, 'min_stock' => 9, 'max_stock' => 99]);
        $this->save(['expected_count' => 1, 'items' => [1 => ['min_stock' => 0, 'max_stock' => 0]]]);
        $this->assertEquals(0, DB::table('tb_product_store_thresholds')->where('store_id', 1)->value('max_stock'));
        $this->assertEquals(99, DB::table('tb_product_store_thresholds')->where('store_id', 2)->value('max_stock'));
    }
    public function test_large_json_batch_saves_every_changed_product(): void
    {
        $products = [];
        $items = [];
        for ($id = 2; $id <= 601; $id++) {
            $products[] = ['id' => $id, 'is_active' => 1];
            $items[$id] = ['min_stock' => 3, 'max_stock' => 12];
        }
        DB::table('tb_products')->insert($products);
        $response = $this->save(['expected_count' => 600, 'items_payload' => json_encode($items)]);
        $this->assertNotNull($response->getSession()->get('success'));
        $this->assertEquals(600, DB::table('tb_product_store_thresholds')->where('min_stock', 3)->count());
    }

    public function test_invalid_stock_value_is_rejected_before_any_write(): void
    {
        try {
            $this->save(['items_payload' => json_encode([1 => ['min_stock' => -1, 'max_stock' => 30]])]);
            $this->fail('Negative stock limit was accepted.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('items.1.min_stock', $error->errors());
        }
        $this->assertEquals(2, DB::table('tb_product_store_thresholds')->value('min_stock'));
    }

}

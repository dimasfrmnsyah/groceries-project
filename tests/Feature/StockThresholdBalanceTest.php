<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderStockController;
use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\StockThresholdController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockThresholdBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'threshold_test', 'database.connections.threshold_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        foreach ([
            'CREATE TABLE tb_stores (id INTEGER PRIMARY KEY, store_name TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_products (id INTEGER PRIMARY KEY, product_code TEXT, product_name TEXT, is_active INTEGER, purchase_price NUMERIC)',
            'CREATE TABLE tb_product_store_thresholds (id INTEGER PRIMARY KEY, product_id INTEGER, store_id INTEGER, min_stock INTEGER, max_stock INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tb_product_store_prices (product_id INTEGER, store_id INTEGER, purchase_price NUMERIC)',
            'CREATE TABLE tb_purchases (id INTEGER PRIMARY KEY, store_id INTEGER)',
            'CREATE TABLE tb_incoming_goods (product_id INTEGER, purchase_id INTEGER, stock INTEGER, is_pending_stock INTEGER)',
            'CREATE TABLE tb_sells (id INTEGER PRIMARY KEY, store_id INTEGER, date TEXT, created_at TEXT)',
            'CREATE TABLE tb_outgoing_goods (product_id INTEGER, sell_id INTEGER, quantity_out INTEGER, is_pending_stock INTEGER, date TEXT, created_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('tb_stores')->insert(['id' => 1, 'store_name' => 'Toko A']);
        DB::table('tb_products')->insert(['id' => 1, 'product_code' => 'B001', 'product_name' => 'Beras', 'is_active' => 1, 'purchase_price' => 10000]);
        DB::table('tb_product_store_thresholds')->insert(['product_id' => 1, 'store_id' => 1, 'min_stock' => 1, 'max_stock' => 1]);
        DB::table('tb_purchases')->insert(['id' => 1, 'store_id' => 1]);
        DB::table('tb_incoming_goods')->insert(['product_id' => 1, 'purchase_id' => 1, 'stock' => 1, 'is_pending_stock' => 0]);
        DB::table('tb_sells')->insert(['id' => 1, 'store_id' => 1, 'date' => now()->toDateString()]);
    }

    private function request(array $data = [], string $method = 'GET'): Request
    {
        $user = new User();
        $user->forceFill(['id' => 'admin', 'roles' => 'superadmin']);
        $request = Request::create('/stock-threshold', $method, $data);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    private function sellLastItem(int $pending = 0): void
    {
        DB::table('tb_outgoing_goods')->insert([
            'product_id' => 1, 'sell_id' => 1, 'quantity_out' => 1,
            'is_pending_stock' => $pending, 'date' => now()->toDateString(),
        ]);
    }

    public function test_threshold_page_shows_zero_after_last_item_is_sold(): void
    {
        $controller = app(StockThresholdController::class);
        $request = $this->request(['store' => 1]);
        $this->assertEquals(1, $controller->index($request)->getData()['rows']->first()->stock_system);
        $this->sellLastItem();
        $this->assertEquals(0, $controller->index($request)->getData()['rows']->first()->stock_system);
        $item = app(OrderStockController::class)->index($request)->getData()['items']->first();
        $this->assertEquals(0, $item->stock_system);
        $this->assertSame(1, $item->po_qty);
    }

    private function balances(Request $request): array
    {
        $request->headers->set('Accept', 'application/json');
        $response = app(StockThresholdController::class)->index($request);
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));

        return $response->getData(true);
    }

    public function test_live_balance_matches_product_stock_after_purchase_and_sale_to_zero(): void
    {
        $request = $this->request(['store' => 1]);
        $this->assertSame(1, $this->balances($request)['rows'][0]['stock_system']);
        DB::table('tb_incoming_goods')->insert(['product_id' => 1, 'purchase_id' => 1, 'stock' => 2, 'is_pending_stock' => 0]);
        $payload = $this->balances($request);
        $this->assertSame(1, $payload['store_id']);
        $this->assertSame(3, $payload['rows'][0]['stock_system']);
        $master = app(ProductStockController::class)->data($request)->getData(true)['data'];
        $this->assertEquals(3, $master[0]['stock_system']);
        $this->sellLastItem();
        $this->assertSame(2, $this->balances($request)['rows'][0]['stock_system']);
        $master = app(ProductStockController::class)->data($request)->getData(true)['data'];
        $this->assertEquals(2, $master[0]['stock_system']);
        DB::table('tb_outgoing_goods')->update(['quantity_out' => 3]);
        $this->assertSame(0, $this->balances($request)['rows'][0]['stock_system']);
        // Master Stock only lists positive balances; Min/Max must retain zero rows.
        $this->assertCount(0, app(ProductStockController::class)->data($request)->getData(true)['data']);
    }

    public function test_live_balance_counts_only_posted_movements_in_selected_store(): void
    {
        DB::statement('ALTER TABLE tb_incoming_goods ADD COLUMN store_id INTEGER');
        DB::statement('ALTER TABLE tb_incoming_goods ADD COLUMN deleted_at TEXT');
        DB::statement('ALTER TABLE tb_outgoing_goods ADD COLUMN deleted_at TEXT');
        DB::table('tb_incoming_goods')->insert([
            ['product_id' => 1, 'store_id' => 2, 'stock' => 20, 'is_pending_stock' => 0, 'deleted_at' => null],
            ['product_id' => 1, 'store_id' => 1, 'stock' => 10, 'is_pending_stock' => 1, 'deleted_at' => null],
            ['product_id' => 1, 'store_id' => 1, 'stock' => 10, 'is_pending_stock' => 0, 'deleted_at' => now()],
        ]);
        $request = $this->request(['store' => 1]);
        $this->sellLastItem(1);
        $this->assertSame(1, $this->balances($request)['rows'][0]['stock_system']);
        DB::table('tb_outgoing_goods')->update(['is_pending_stock' => 0]);
        $this->assertSame(0, $this->balances($request)['rows'][0]['stock_system']);
        DB::table('tb_outgoing_goods')->update(['deleted_at' => now()]);
        $this->assertSame(1, $this->balances($request)['rows'][0]['stock_system']);
    }

    public function test_live_balance_respects_staff_store_access_and_search(): void
    {
        $request = $this->request(['store' => 2]);
        $user = new User();
        $user->forceFill(['id' => 'staff', 'roles' => 'staff', 'store_id' => 1]);
        $request->setUserResolver(fn () => $user);
        $this->assertSame(1, $this->balances($request)['store_id']);
        $request->query->set('q', 'missing');
        $this->assertSame([], $this->balances($request)['rows']);
    }

    public function test_limits_can_be_saved_from_one_to_zero(): void
    {
        $response = app(StockThresholdController::class)->save($this->request([
            'store_id' => 1, 'expected_count' => 1,
            'items_payload' => json_encode([1 => ['min_stock' => '0', 'max_stock' => '0']]),
        ], 'POST'));
        $this->assertNotNull($response->getSession()->get('success'));
        $limits = DB::table('tb_product_store_thresholds')->first();
        $this->assertEquals(0, $limits->min_stock);
        $this->assertEquals(0, $limits->max_stock);
    }
}

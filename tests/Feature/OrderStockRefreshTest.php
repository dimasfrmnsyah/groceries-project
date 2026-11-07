<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderStockController;
use App\Http\Controllers\ProductStockController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderStockRefreshTest extends TestCase
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
        $request = Request::create('/order-stock', $method, $data);
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

    private function items(Request $request): array
    {
        $request->headers->set('Accept', 'application/json');
        $response = app(OrderStockController::class)->index($request);
        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $payload = $response->getData(true);
        $this->assertSame(1, $payload['store_id']);

        return $payload['items'];
    }

    public function test_order_stock_and_suggested_po_change_when_last_item_is_sold(): void
    {
        $request = $this->request(['store' => 1]);
        $before = $this->items($request)[0];
        $this->assertEquals(1, $before['stock_system']);
        $this->assertSame(0, $before['po_qty']);
        $this->sellLastItem();
        $after = $this->items($request)[0];
        $this->assertEquals(0, $after['stock_system']);
        $this->assertSame(1, $after['po_qty']);
    }

    public function test_purchase_removes_restocked_product_and_new_sale_brings_it_back(): void
    {
        $request = $this->request(['store' => 1]);
        $this->assertCount(1, $this->items($request));
        DB::table('tb_incoming_goods')->insert(['product_id' => 1, 'purchase_id' => 1, 'stock' => 2, 'is_pending_stock' => 0]);
        $this->assertSame([], $this->items($request));
        $master = app(ProductStockController::class)->data($request)->getData(true)['data'];
        $this->assertEquals(3, $master[0]['stock_system']);
        $this->sellLastItem();
        DB::table('tb_outgoing_goods')->update(['quantity_out' => 2]);
        $item = $this->items($request)[0];
        $this->assertEquals(1, $item['stock_system']);
        $master = app(ProductStockController::class)->data($request)->getData(true)['data'];
        $this->assertEquals($master[0]['stock_system'], $item['stock_system']);
    }

    public function test_pending_sale_does_not_reduce_order_stock_until_posted(): void
    {
        $request = $this->request(['store' => 1]);
        $this->sellLastItem(1);
        $this->assertEquals(1, $this->items($request)[0]['stock_system']);
        DB::table('tb_outgoing_goods')->update(['is_pending_stock' => 0]);
        $this->assertEquals(0, $this->items($request)[0]['stock_system']);
    }

    public function test_live_order_items_respect_staff_store_access(): void
    {
        DB::table('tb_purchases')->insert(['id' => 2, 'store_id' => 2]);
        DB::table('tb_incoming_goods')->insert(['product_id' => 1, 'purchase_id' => 2, 'stock' => 100, 'is_pending_stock' => 0]);
        $request = $this->request(['store' => 2]);
        $user = new User();
        $user->forceFill(['id' => 'staff', 'roles' => 'staff', 'store_id' => 1]);
        $request->setUserResolver(fn () => $user);
        $this->assertEquals(1, $this->items($request)[0]['stock_system']);
    }

    private function seedSearchProducts(): void
    {
        DB::table('tb_products')->insert([
            ['id' => 2, 'product_code' => '08993058300807', 'product_name' => 'Komik Jeruk Nipis', 'is_active' => 1, 'purchase_price' => 1700],
            ['id' => 3, 'product_code' => '08993058300821', 'product_name' => 'Komik Stok Aman', 'is_active' => 1, 'purchase_price' => 1700],
        ]);
        DB::table('tb_product_store_thresholds')->insert([
            ['product_id' => 2, 'store_id' => 1, 'min_stock' => 5, 'max_stock' => 35],
            ['product_id' => 3, 'store_id' => 1, 'min_stock' => 5, 'max_stock' => 35],
        ]);
        DB::table('tb_incoming_goods')->insert(['product_id' => 3, 'purchase_id' => 1, 'stock' => 30, 'is_pending_stock' => 0]);
    }

    public function test_search_filters_by_name_and_barcode_without_including_safe_stock(): void
    {
        $this->seedSearchProducts();
        foreach ([' jeruk ', '08993058300807', 'komik', '089930583008'] as $search) {
            $items = $this->items($this->request(['store' => 1, 'q' => $search]));
            $this->assertSame([2], array_column($items, 'id'));
        }
        $view = app(OrderStockController::class)->index($this->request(['store' => 1, 'q' => ' jeruk ']))->getData();
        $this->assertSame('jeruk', $view['search']);
        $this->assertSame([2], $view['items']->pluck('id')->all());
    }

    public function test_search_with_no_match_is_empty_and_clearing_search_restores_order_items(): void
    {
        $this->seedSearchProducts();
        $this->assertSame([], $this->items($this->request(['store' => 1, 'q' => 'missing'])));
        $this->assertSame([1, 2], array_column($this->items($this->request(['store' => 1, 'q' => '  '])), 'id'));
    }

    public function test_export_uses_the_same_search_filter(): void
    {
        $this->seedSearchProducts();
        \Maatwebsite\Excel\Facades\Excel::fake();
        app(OrderStockController::class)->export($this->request(['store' => 1, 'q' => 'jeruk']));
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('order-stock-store-1.xlsx', function ($export) {
            $this->assertSame([
                ['Kode' => '08993058300807', 'Produk' => 'Komik Jeruk Nipis', 'PO' => 35],
            ], $export->collection()->all());
            return true;
        });
    }
}

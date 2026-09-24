<?php

namespace Tests\Feature;

use App\Http\Controllers\TbSalesController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A dedicated in-memory connection never touches the shop's database.
        config([
            'database.default' => 'cashier_test',
            'database.connections.cashier_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ],
            'sync.enabled' => false,
        ]);
        foreach ([
            'CREATE TABLE tb_stores (id INTEGER PRIMARY KEY, is_online INTEGER, deleted_at TEXT)',
            'CREATE TABLE tb_products (id INTEGER PRIMARY KEY, product_name TEXT, selling_price NUMERIC, is_active INTEGER)',
            'CREATE TABLE tb_product_store_prices (id INTEGER PRIMARY KEY, product_id INTEGER, store_id INTEGER)',
            'CREATE TABLE tb_purchases (id INTEGER PRIMARY KEY, store_id INTEGER)',
            'CREATE TABLE tb_incoming_goods (id INTEGER PRIMARY KEY, purchase_id INTEGER, product_id INTEGER, stock INTEGER)',
            'CREATE TABLE tb_sells (id INTEGER PRIMARY KEY, no_invoice TEXT, store_id INTEGER, created_by INTEGER, idempotency_key TEXT UNIQUE, date TEXT, total_price NUMERIC, payment_amount NUMERIC, customer_id INTEGER, uuid TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_outgoing_goods (id INTEGER PRIMARY KEY, product_id INTEGER, sell_id INTEGER, store_id INTEGER, date TEXT, quantity_out INTEGER, discount NUMERIC, recorded_by TEXT, created_by INTEGER, source_type TEXT, is_pending_stock INTEGER, uuid TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('tb_stores')->insert(['id' => 1, 'is_online' => 1]);
        DB::table('tb_products')->insert(['id' => 1, 'product_name' => 'Beras', 'selling_price' => 10000, 'is_active' => 1]);
        DB::table('tb_purchases')->insert(['id' => 1, 'store_id' => 1]);
        DB::table('tb_incoming_goods')->insert(['purchase_id' => 1, 'product_id' => 1, 'stock' => 10]);
        $user = new User();
        $user->forceFill(['id' => 1, 'name' => 'Kasir', 'roles' => 'kasir', 'store_id' => 1]);
        $this->actingAs($user);
    }

    private function pay(string $key = 'payment-1', int $qty = 2): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/sales', 'POST', ['data' => [
            'transaction_date' => '2026-09-24',
            'store_id' => 1,
            'customer_money' => 50000,
            'idempotency_key' => $key,
            'products' => [['id' => 1, 'qty' => $qty]],
        ]]);
        $request->setUserResolver(fn () => auth()->user());

        return app(TbSalesController::class)->store($request);
    }

    public function test_online_payment_succeeds_on_first_attempt_and_retry_does_not_duplicate_it(): void
    {
        $response = $this->pay();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertTrue($response->getData()->success);
        $this->assertFalse($response->getData()->stock_pending);
        $retry = $this->pay();
        $this->assertSame(200, $retry->getStatusCode());
        $this->assertTrue($retry->getData()->duplicate);
        $this->assertSame($response->getData()->sell_id, $retry->getData()->sell_id);
        $this->assertSame(1, DB::table('tb_sells')->count());
        $this->assertSame(1, DB::table('tb_outgoing_goods')->count());
        $this->assertEquals(2, DB::table('tb_outgoing_goods')->sum('quantity_out'));
        $this->assertEquals(0, DB::table('tb_outgoing_goods')->value('is_pending_stock'));
    }

    public function test_offline_payment_succeeds_on_first_attempt_with_pending_stock(): void
    {
        DB::table('tb_stores')->where('id', 1)->update(['is_online' => 0]);
        $response = $this->pay();
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertTrue($response->getData()->success);
        $this->assertTrue($response->getData()->stock_pending);
        $this->assertSame(1, DB::table('tb_sells')->count());
        $this->assertEquals(1, DB::table('tb_outgoing_goods')->value('is_pending_stock'));
    }

    public function test_reusing_a_key_for_a_different_cart_is_rejected(): void
    {
        $this->pay();
        $response = $this->pay(qty: 3);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(1, DB::table('tb_sells')->count());
        $this->assertEquals(2, DB::table('tb_outgoing_goods')->sum('quantity_out'));
    }
}

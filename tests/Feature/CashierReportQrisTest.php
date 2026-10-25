<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierReportQrisTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'report_qris', 'database.connections.report_qris' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'sync.enabled' => false]);
        foreach ([
            'CREATE TABLE users (id TEXT PRIMARY KEY, name TEXT, store_id INTEGER, roles TEXT, deleted_at TEXT)',
            'CREATE TABLE daily_revenues (id INTEGER PRIMARY KEY, user_id TEXT, store_id INTEGER, date TEXT, amount NUMERIC, qr NUMERIC, pengeluaran NUMERIC, denominations TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_sells (id INTEGER PRIMARY KEY, created_by TEXT, store_id INTEGER, date TEXT, created_at TEXT, total_price NUMERIC, payment_method TEXT, no_invoice TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_outgoing_goods (id INTEGER PRIMARY KEY, sell_id INTEGER, created_by TEXT, recorded_by TEXT, store_id INTEGER, date TEXT, quantity_out INTEGER, uuid TEXT, product_id INTEGER, discount NUMERIC, description TEXT, created_at TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_products (id INTEGER PRIMARY KEY, product_name TEXT, selling_price NUMERIC, deleted_at TEXT)',
            'CREATE TABLE attendances (id INTEGER PRIMARY KEY, user_id TEXT, store_id INTEGER, checked_in_at TEXT, checked_out_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('users')->insert(['id' => 'cashier', 'name' => 'Diva', 'store_id' => 1, 'roles' => 'kasir']);
        DB::table('daily_revenues')->insert(['id' => 1, 'user_id' => 'cashier', 'store_id' => 1,
            'date' => '2026-10-25', 'amount' => 100000, 'qr' => 0]);
        $user = new User();
        $user->forceFill(['id' => 'admin', 'roles' => 'superadmin']);
        $this->actingAs($user);
    }

    private function sale(int $id, string $method = 'qris', array $overrides = []): void
    {
        DB::table('tb_sells')->insert(array_merge([
            'id' => $id, 'created_by' => 'cashier', 'store_id' => 1, 'date' => '2026-10-25',
            'created_at' => '2026-10-25 12:00:00', 'total_price' => 20000,
            'payment_method' => $method, 'no_invoice' => 'INV-'.$id,
        ], $overrides));
        DB::table('tb_outgoing_goods')->insert(['id' => $id, 'sell_id' => $id,
            'created_by' => $overrides['created_by'] ?? 'cashier', 'recorded_by' => 'Diva',
            'store_id' => $overrides['store_id'] ?? 1,
            'date' => $overrides['date'] ?? '2026-10-25', 'quantity_out' => 1]);
    }

    private function report(): array
    {
        return app(ReportController::class)->detail(Request::create('/report/detail/1'), 1)->getData();
    }

    public function test_qris_is_read_from_sales_once_per_invoice_not_logout_input(): void
    {
        $this->sale(1);
        $this->sale(2, 'cash');
        DB::table('tb_outgoing_goods')->insert(['id' => 3, 'sell_id' => 1,
            'created_by' => 'cashier', 'recorded_by' => 'Diva', 'store_id' => 1,
            'date' => '2026-10-25', 'quantity_out' => 2]);
        $data = $this->report();
        $this->assertEquals(20000, $data['qrisTotal'] ?? null);
        $this->assertEquals(0, $data['revenue']->qr);
    }

    public function test_other_cashier_same_name_other_store_and_other_date_are_excluded(): void
    {
        $this->sale(1);
        $this->sale(2, overrides: ['created_by' => 'other']);
        $this->sale(3, overrides: ['store_id' => 2]);
        $this->sale(4, overrides: ['date' => '2026-10-26']);
        $this->assertEquals(20000, $this->report()['qrisTotal'] ?? null);
    }

    public function test_deleted_adjustment_and_orphan_invoices_do_not_inflate_qris(): void
    {
        $this->sale(1);
        $this->sale(2, overrides: ['deleted_at' => '2026-10-25']);
        $this->sale(3, overrides: ['no_invoice' => 'SO-ADJ-3']);
        $this->sale(4);
        DB::table('tb_outgoing_goods')->where('sell_id', 4)->delete();
        $this->sale(5);
        DB::table('tb_outgoing_goods')->where('sell_id', 5)->update(['deleted_at' => '2026-10-25']);
        $this->assertEquals(20000, $this->report()['qrisTotal'] ?? null);
    }

    public function test_after_midnight_qris_follows_shift_start_date(): void
    {
        DB::table('attendances')->insert(['user_id' => 'cashier', 'store_id' => 1,
            'checked_in_at' => '2026-10-25 10:00:00', 'checked_out_at' => '2026-10-25 19:00:00']);
        $this->sale(1, overrides: ['date' => '2026-10-26', 'created_at' => '2026-10-25 17:30:00']);
        $this->assertEquals(20000, $this->report()['qrisTotal'] ?? null);
    }

    public function test_manual_qr_is_preserved_without_adding_it_to_transaction_total(): void
    {
        $this->sale(1);
        DB::table('daily_revenues')->update(['qr' => 15000]);
        $data = $this->report();
        $this->assertEquals(20000, $data['qrisTotal'] ?? null);
        $this->assertEquals(15000, $data['revenue']->qr);
        $this->assertEquals(15000, DB::table('daily_revenues')->value('qr'));
    }

    public function test_missing_payment_method_column_is_unavailable_not_invented(): void
    {
        DB::statement('ALTER TABLE tb_sells DROP COLUMN payment_method');
        $data = $this->report();
        $this->assertArrayHasKey('qrisTotal', $data);
        $this->assertNull($data['qrisTotal']);
    }
    public function test_cash_only_sales_show_zero_qris(): void
    {
        $this->sale(1, 'cash');
        $this->assertSame(0.0, $this->report()['qrisTotal']);
    }

    public function test_legacy_name_and_user_store_fallback_still_work(): void
    {
        $this->sale(1, overrides: ['created_by' => null]);
        DB::table('tb_outgoing_goods')->update(['created_by' => null]);
        DB::table('daily_revenues')->update(['store_id' => null]);
        $this->assertSame(20000.0, $this->report()['qrisTotal']);
    }

    public function test_detail_items_and_qris_share_the_same_shift_and_cashier_scope(): void
    {
        DB::table('attendances')->insert(['user_id' => 'cashier', 'store_id' => 1,
            'checked_in_at' => '2026-10-25 10:00:00', 'checked_out_at' => '2026-10-25 19:00:00']);
        $this->sale(1, overrides: ['date' => '2026-10-26', 'created_at' => '2026-10-25 17:30:00']);
        $this->sale(2, overrides: ['created_by' => 'other']);
        $response = app(ReportController::class)->detailData(Request::create('/report/detail/1/data'), 1);
        $this->assertSame([1], array_column($response->getData(true)['data'], 'sell_id'));
        $this->assertSame(20000.0, $this->report()['qrisTotal']);
    }

    public function test_cashier_page_shows_own_daily_qris_for_the_active_shift(): void
    {
        DB::statement('CREATE TABLE tb_stores (id INTEGER PRIMARY KEY, store_name TEXT, deleted_at TEXT)');
        DB::statement('CREATE TABLE tb_customers (id INTEGER PRIMARY KEY, store_id INTEGER, deleted_at TEXT)');
        DB::table('tb_stores')->insert(['id' => 1, 'store_name' => 'Toko']);
        DB::table('attendances')->insert(['user_id' => 'cashier', 'store_id' => 1,
            'checked_in_at' => '2026-10-25 10:00:00', 'checked_out_at' => null]);
        $this->sale(1, overrides: ['date' => '2026-10-26', 'created_at' => '2026-10-25 17:15:00']);
        $this->sale(2, overrides: ['created_by' => 'other']);
        $this->sale(3, 'cash');
        $this->actingAs(User::findOrFail('cashier'));
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-26 00:30:00', 'Asia/Jakarta'));
        try {
            $request = Request::create('/sales');
            $request->setUserResolver(fn () => auth()->user());
            $data = app(\App\Http\Controllers\TbSalesController::class)->index($request)->getData();
            $this->assertSame(20000.0, $data['cashierQrisTotal'] ?? null);
            $this->assertSame('2026-10-25', $data['cashierQrisDate'] ?? null);
            DB::table('attendances')->update(['checked_out_at' => '2026-10-25 17:20:00']);
            $data = app(\App\Http\Controllers\TbSalesController::class)->index($request)->getData();
            $this->assertSame('2026-10-26', $data['cashierQrisDate']);
            $this->assertSame(0.0, $data['cashierQrisTotal']);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

}

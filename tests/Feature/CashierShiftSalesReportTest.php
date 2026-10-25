<?php

namespace Tests\Feature;

use App\Http\Controllers\CashierMonthlyReportController;
use App\Http\Controllers\DailySalesReportController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierShiftSalesReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'shift_test', 'database.connections.shift_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'sync.enabled' => false]);
        foreach ([
            'CREATE TABLE attendances (id INTEGER PRIMARY KEY, user_id TEXT, store_id INTEGER, checked_in_at TEXT, checked_out_at TEXT)',
            'CREATE TABLE tb_sells (id INTEGER PRIMARY KEY, store_id INTEGER, created_by TEXT, date TEXT, created_at TEXT, total_price NUMERIC, no_invoice TEXT, customer_id INTEGER)',
            'CREATE TABLE tb_outgoing_goods (id INTEGER PRIMARY KEY, sell_id INTEGER, product_id INTEGER, date TEXT, created_at TEXT, recorded_by TEXT, quantity_out INTEGER, discount NUMERIC, deleted_at TEXT)',
            'CREATE TABLE tb_stores (id INTEGER PRIMARY KEY, store_name TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_products (id INTEGER PRIMARY KEY, product_code TEXT, product_name TEXT, type_id INTEGER, selling_price NUMERIC, purchase_price NUMERIC)',
            'CREATE TABLE tb_types (id INTEGER PRIMARY KEY, type_name TEXT)',
            'CREATE TABLE tb_customers (id INTEGER PRIMARY KEY)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('tb_stores')->insert(['id' => 1, 'store_name' => 'Toko']);
        DB::table('tb_products')->insert(['id' => 1, 'product_code' => 'A', 'product_name' => 'Beras', 'selling_price' => 10000, 'purchase_price' => 8000]);
        // 31 October 17:00 WIB through 1 November 02:00 WIB, stored in UTC.
        DB::table('attendances')->insert(['user_id' => 'cashier', 'store_id' => 1,
            'checked_in_at' => '2026-10-31 10:00:00', 'checked_out_at' => '2026-10-31 19:00:00']);
        $this->sale(1, '2026-10-31', '2026-10-31 11:00:00');
        $this->sale(2, '2026-11-01', '2026-10-31 17:30:00');
        $this->sale(3, '2026-11-01', '2026-10-31 19:00:00');
        $user = new User();
        $user->forceFill(['id' => 'cashier', 'roles' => 'kasir', 'store_id' => 1, 'name' => 'Kasir']);
        $this->actingAs($user);
    }

    private function sale(int $id, string $date, ?string $createdAt, string $user = 'cashier', int $store = 1): void
    {
        DB::table('tb_sells')->insert(['id' => $id, 'date' => $date.' 00:00:00', 'created_at' => $createdAt,
            'created_by' => $user, 'store_id' => $store, 'total_price' => 10000, 'no_invoice' => 'INV-'.$id]);
        DB::table('tb_outgoing_goods')->insert(['id' => $id, 'sell_id' => $id, 'date' => $date,
            'created_at' => $createdAt, 'recorded_by' => 'Kasir', 'quantity_out' => 1, 'discount' => 0, 'product_id' => 1]);
    }

    private function dailyIds(string $date): array
    {
        $controller = app(DailySalesReportController::class);
        $query = (new \ReflectionMethod($controller, 'filteredSalesQuery'))->invoke(
            $controller, 1, Carbon::parse($date), Carbon::parse($date), 'Kasir', 'all'
        );
        return $query->orderBy('s.id')->pluck('s.id')->all();
    }

    public function test_daily_report_uses_start_date_and_does_not_count_next_day_twice(): void
    {
        $this->assertSame([1, 2], $this->dailyIds('2026-10-31'));
        $this->assertSame([3], $this->dailyIds('2026-11-01'));
        $this->assertSame('2026-11-01 00:00:00', DB::table('tb_sells')->where('id', 2)->value('date'));
    }

    public function test_open_shift_also_keeps_sales_on_start_date(): void
    {
        DB::table('attendances')->update(['checked_out_at' => null]);
        $this->assertSame([1, 2, 3], $this->dailyIds('2026-10-31'));
    }

    public function test_unmatched_user_store_or_timestamp_falls_back_to_transaction_date(): void
    {
        $this->sale(4, '2026-11-01', '2026-10-31 17:30:00', 'other');
        $this->sale(5, '2026-11-01', null);
        DB::table('attendances')->update(['store_id' => 2]);
        $this->assertSame([2, 3, 4, 5], $this->dailyIds('2026-11-01'));
    }

    public function test_installation_without_attendance_table_keeps_legacy_dates(): void
    {
        DB::statement('DROP TABLE attendances');
        $this->assertSame([2, 3], $this->dailyIds('2026-11-01'));
    }

    public function test_monthly_cashier_detail_uses_same_shift_date(): void
    {
        $request = Request::create('/report/cashier-monthly/detail/data', 'GET', [
            'month' => '2026-10', 'cashier_key' => 'kasir', 'length' => -1,
        ]);
        $request->setUserResolver(fn () => auth()->user());
        $response = app(CashierMonthlyReportController::class)->detailData($request);
        $rows = $response->getData(true)['data'];
        $this->assertEqualsCanonicalizing([1, 2], array_column($rows, 'sell_id'));
        $this->assertSame(['2026-10-31'], array_values(array_unique(array_column($rows, 'sale_date'))));
    }
    public function test_monthly_summary_counts_each_invoice_once_in_the_shift_month(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT',
            fn ($date, $format) => substr($date, 0, 7), 2);
        DB::table('tb_outgoing_goods')->insert(['id' => 10, 'sell_id' => 2, 'product_id' => 1,
            'recorded_by' => 'Kasir', 'quantity_out' => 1, 'date' => '2026-11-01']);
        $request = Request::create('/report/cashier-monthly/data', 'GET', [
            'month_from' => '2026-10', 'month_to' => '2026-11', 'length' => -1,
        ]);
        $request->setUserResolver(fn () => auth()->user());
        $payload = app(CashierMonthlyReportController::class)->data($request)->getData(true);
        $this->assertEquals(20000, $payload['totals']['total_from']);
        $this->assertEquals(10000, $payload['totals']['total_to']);
        $this->assertEquals(2, $payload['data'][0]['trx_from']);
    }

    public function test_daily_export_matches_shift_date_filter(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $request = Request::create('/report/sales/today/export', 'GET', [
            'date_from' => '2026-10-31', 'date_to' => '2026-10-31', 'cashier' => 'Kasir',
        ]);
        $request->setUserResolver(fn () => auth()->user());
        app(DailySalesReportController::class)->export($request);
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('Sales-Detail-20261031-20261031.xlsx',
            function ($export) {
                $rows = $export->array();
                $this->assertSame(['INV-1', 'INV-2'], array_column($rows, 1));
                $this->assertSame(['2026-10-31', '2026-10-31'], array_column($rows, 0));
                return true;
            });
    }

    public function test_shift_start_date_uses_wib_and_matches_the_correct_cashier(): void
    {
        // 1 November 01:00 WIB has an October UTC date.
        DB::table('attendances')->delete();
        DB::table('attendances')->insert(['user_id' => 'cashier', 'store_id' => 1,
            'checked_in_at' => '2026-10-31 18:00:00', 'checked_out_at' => '2026-11-01 02:00:00']);
        $this->sale(4, '2026-11-02', '2026-10-31 20:00:00');
        $this->sale(5, '2026-11-02', '2026-10-31 20:00:00', 'other');
        $this->assertSame([2, 3, 4], $this->dailyIds('2026-11-01'));
        $this->assertSame([5], $this->dailyIds('2026-11-02'));
    }

}

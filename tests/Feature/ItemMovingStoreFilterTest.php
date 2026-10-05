<?php

namespace Tests\Feature;

use App\Http\Controllers\ItemMovingController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemMovingStoreFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'moving_test', 'database.connections.moving_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        foreach ([
            'CREATE TABLE tb_master_menuses (id INTEGER PRIMARY KEY, menu_path TEXT, is_active INTEGER)',
            'CREATE TABLE tb_master_menu_roles (menu_id INTEGER, role_name TEXT)',
            'CREATE TABLE tb_stores (id INTEGER PRIMARY KEY, store_name TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_products (id INTEGER PRIMARY KEY, product_code TEXT, product_name TEXT, is_active INTEGER)',
            'CREATE TABLE tb_product_store_thresholds (product_id INTEGER, store_id INTEGER, min_stock NUMERIC, max_stock NUMERIC)',
            'CREATE TABLE tb_purchases (id INTEGER PRIMARY KEY, store_id INTEGER)',
            'CREATE TABLE tb_incoming_goods (product_id INTEGER, purchase_id INTEGER, stock INTEGER)',
            'CREATE TABLE tb_sells (id INTEGER PRIMARY KEY, store_id INTEGER, no_invoice TEXT, date TEXT, created_at TEXT)',
            'CREATE TABLE tb_outgoing_goods (product_id INTEGER, sell_id INTEGER, quantity_out INTEGER, date TEXT, created_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('tb_stores')->insert([['id' => 1, 'store_name' => 'Toko A'], ['id' => 2, 'store_name' => 'Toko B']]);
        DB::table('tb_products')->insert(['id' => 1, 'product_code' => 'B001', 'product_name' => 'Beras', 'is_active' => 1]);
        DB::table('tb_purchases')->insert([['id' => 1, 'store_id' => 1], ['id' => 2, 'store_id' => 2]]);
        DB::table('tb_incoming_goods')->insert([['product_id' => 1, 'purchase_id' => 1, 'stock' => 10], ['product_id' => 1, 'purchase_id' => 2, 'stock' => 25]]);
    }

    private function rows(string $role, array $filters)
    {
        $user = new User();
        $user->forceFill(['id' => 1, 'roles' => $role, 'store_id' => 1]);
        $request = Request::create('/item-moving', 'GET', $filters);
        $request->setUserResolver(fn () => $user);
        return app(ItemMovingController::class)->index($request)->getData()['rows'];
    }

    public function test_all_stores_keeps_product_stock_separate_by_store(): void
    {
        $rows = $this->rows('superadmin', ['store' => 'all']);
        $this->assertCount(2, $rows);
        $this->assertEquals([1 => 10, 2 => 25], $rows->pluck('stock_system', 'store_id')->all());
        $this->assertSame(['Toko A', 'Toko B'], $rows->pluck('store_name')->all());
    }

    public function test_all_stores_respects_user_store_access(): void
    {
        $rows = $this->rows('staff', ['store' => 'all']);
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows->first()->store_id);
    }

    public function test_single_store_and_search_and_category_filters_still_work(): void
    {
        $this->assertEquals(25, $this->rows('superadmin', ['store' => 2])->first()->stock_system);
        $this->assertCount(2, $this->rows('superadmin', ['store' => 'all', 'q' => 'Beras', 'category' => 'unsold']));
        $this->assertCount(0, $this->rows('superadmin', ['store' => 'all', 'q' => 'missing']));
        $this->assertCount(0, $this->rows('superadmin', ['store' => 'all', 'category' => 'fast']));
    }
    public function test_recent_sales_exclude_product_from_dead_category_only_in_selling_store(): void
    {
        $date = now('Asia/Jakarta')->subDay()->toDateString();
        DB::table('tb_sells')->insert(['id' => 1, 'store_id' => 1, 'no_invoice' => 'INV-001', 'date' => $date]);
        DB::table('tb_outgoing_goods')->insert(['product_id' => 1, 'sell_id' => 1, 'quantity_out' => 3, 'date' => $date]);
        $this->assertCount(0, $this->rows('superadmin', ['store' => 1, 'category' => 'dead']));
        $dead = $this->rows('superadmin', ['store' => 2, 'category' => 'dead']);
        $this->assertCount(1, $dead);
        $this->assertSame(2, $dead->first()->store_id);
        $this->assertSame('normal', $this->rows('superadmin', ['store' => 1])->first()->moving_category);
    }

    public function test_sales_older_than_six_months_still_count_as_dead(): void
    {
        $date = now('Asia/Jakarta')->subMonthsNoOverflow(7)->toDateString();
        DB::table('tb_sells')->insert(['id' => 1, 'store_id' => 1, 'no_invoice' => 'INV-002', 'date' => $date]);
        DB::table('tb_outgoing_goods')->insert(['product_id' => 1, 'sell_id' => 1, 'quantity_out' => 3, 'date' => $date]);
        $row = $this->rows('superadmin', ['store' => 1, 'category' => 'dead'])->first();
        $this->assertNotNull($row);
        $this->assertSame($date, $row->last_sale_at);
    }

    public function test_all_stores_excludes_product_from_dead_when_any_accessible_store_has_stock(): void
    {
        DB::table('tb_incoming_goods')->where('purchase_id', 1)->update(['stock' => 0]);
        $this->assertCount(0, $this->rows('superadmin', ['store' => 'all', 'category' => 'dead']));
        $this->assertCount(2, $this->rows('superadmin', ['store' => 'all', 'category' => 'unsold']));
        $this->assertCount(1, $this->rows('superadmin', ['store' => 1, 'category' => 'dead']));
        // Stock in an inaccessible store must not affect this user's report.
        $this->assertCount(1, $this->rows('staff', ['store' => 'all', 'category' => 'dead']));
    }

    public function test_all_stores_keeps_dead_when_no_store_has_positive_stock(): void
    {
        DB::table('tb_incoming_goods')->update(['stock' => 0]);
        $this->assertCount(2, $this->rows('superadmin', ['store' => 'all', 'category' => 'dead']));
    }

    public function test_stocked_dead_product_is_unsold_in_all_stores_but_remains_dead_per_store(): void
    {
        DB::table('tb_product_store_thresholds')->insert(['product_id' => 1, 'store_id' => 1, 'min_stock' => 35, 'max_stock' => 45]);
        $rows = $this->rows('superadmin', ['store' => 'all'])->keyBy('store_id');
        $this->assertSame('unsold', $rows[1]->moving_category);
        $this->assertSame('unsold', $rows[2]->moving_category);
        $this->assertSame('dead', $this->rows('superadmin', ['store' => 1])->first()->moving_category);
    }

    public function test_unsold_filter_resets_when_switching_to_single_store(): void
    {
        $rows = $this->rows('superadmin', ['store' => 1, 'category' => 'unsold']);
        $this->assertCount(1, $rows);
        $this->assertSame('dead', $rows->first()->moving_category);
    }

    public function test_recently_sold_product_is_not_classified_as_unsold(): void
    {
        $date = now('Asia/Jakarta')->subDay()->toDateString();
        DB::table('tb_sells')->insert(['id' => 1, 'store_id' => 1, 'no_invoice' => 'INV-003', 'date' => $date]);
        DB::table('tb_outgoing_goods')->insert(['product_id' => 1, 'sell_id' => 1, 'quantity_out' => 1, 'date' => $date]);
        $rows = $this->rows('superadmin', ['store' => 'all', 'category' => 'unsold']);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows->first()->store_id);
        $normal = $this->rows('superadmin', ['store' => 'all', 'category' => 'normal']);
        $this->assertCount(1, $normal);
        $this->assertSame(1, $normal->first()->store_id);
    }

}

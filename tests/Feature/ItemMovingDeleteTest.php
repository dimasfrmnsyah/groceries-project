<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemMovingDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'delete_test', 'database.connections.delete_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::statement('CREATE TABLE tb_products (id INTEGER PRIMARY KEY, product_name TEXT)');
        DB::statement('CREATE TABLE tb_product_store_prices (product_id INTEGER, store_id INTEGER)');
        DB::statement('CREATE TABLE tb_product_store_thresholds (product_id INTEGER, store_id INTEGER)');
        DB::table('tb_products')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        DB::table('tb_product_store_prices')->insert([['product_id' => 1, 'store_id' => 1], ['product_id' => 1, 'store_id' => 2]]);
        DB::table('tb_product_store_thresholds')->insert(['product_id' => 1, 'store_id' => 2]);
        $user = new User();
        $user->forceFill(['id' => 1, 'roles' => 'superadmin']);
        $this->actingAs($user);
    }

    public function test_deletes_unique_selected_master_products_and_store_settings(): void
    {
        $this->deleteJson(route('item-moving.delete-products'), ['product_ids' => [1, 1, 2]])
            ->assertOk()->assertJson(['deleted' => 2]);
        $this->assertSame([3], DB::table('tb_products')->pluck('id')->all());
        $this->assertSame(0, DB::table('tb_product_store_prices')->count());
        $this->assertSame(0, DB::table('tb_product_store_thresholds')->count());
    }

    public function test_invalid_selection_does_not_delete_any_product(): void
    {
        $this->deleteJson(route('item-moving.delete-products'), ['product_ids' => [1, 99]])->assertUnprocessable();
        $this->assertSame(3, DB::table('tb_products')->count());
        $this->deleteJson(route('item-moving.delete-products'), ['product_ids' => []])->assertUnprocessable();
    }
    public function test_role_without_master_product_access_cannot_delete(): void
    {
        DB::statement('CREATE TABLE tb_master_menuses (id INTEGER PRIMARY KEY, menu_path TEXT, is_active INTEGER)');
        DB::statement('CREATE TABLE tb_master_menu_roles (menu_id INTEGER, role_name TEXT)');
        DB::table('tb_master_menuses')->insert(['id' => 1, 'menu_path' => 'item-moving.index', 'is_active' => 1]);
        DB::table('tb_master_menu_roles')->insert(['menu_id' => 1, 'role_name' => 'moving-only']);
        auth()->user()->roles = 'moving-only';
        $this->deleteJson(route('item-moving.delete-products'), ['product_ids' => [1]])->assertForbidden();
        $this->assertSame(3, DB::table('tb_products')->count());
    }

    public function test_failure_rolls_back_the_entire_selection(): void
    {
        DB::unprepared("CREATE TRIGGER block_product_delete BEFORE DELETE ON tb_products WHEN OLD.id = 2 BEGIN SELECT RAISE(ABORT, 'Product is referenced'); END");
        $this->deleteJson(route('item-moving.delete-products'), ['product_ids' => [1, 2]])->assertStatus(409);
        $this->assertSame(3, DB::table('tb_products')->count());
        $this->assertSame(2, DB::table('tb_product_store_prices')->count());
        $this->assertSame(1, DB::table('tb_product_store_thresholds')->count());
    }

}

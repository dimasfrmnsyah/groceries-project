<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnsoldProductDeleteTest extends TestCase
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

    public function test_can_delete_one_master_product_from_unsold_menu(): void
    {
        $this->deleteJson('/unsold-products/1')->assertOk();
        $this->assertDatabaseMissing('tb_products', ['id' => 1]);
        $this->assertDatabaseHas('tb_products', ['id' => 2]);
        $this->assertDatabaseMissing('tb_product_store_prices', ['product_id' => 1]);
        $this->assertDatabaseMissing('tb_product_store_thresholds', ['product_id' => 1]);
    }

    public function test_delete_denies_role_without_master_access(): void
    {
        DB::statement('CREATE TABLE tb_master_menuses (id INTEGER PRIMARY KEY, menu_path TEXT, is_active INTEGER)');
        DB::statement('CREATE TABLE tb_master_menu_roles (menu_id INTEGER, role_name TEXT)');
        DB::table('tb_master_menuses')->insert(['id' => 1, 'menu_path' => 'unsold-products.index', 'is_active' => 1]);
        DB::table('tb_master_menu_roles')->insert(['menu_id' => 1, 'role_name' => 'unsold-reader']);
        auth()->user()->roles = 'unsold-reader';
        $this->deleteJson('/unsold-products/1')->assertForbidden();
        $this->deleteJson('/unsold-products', ['product_ids' => [1, 2]])->assertForbidden();
        $this->assertDatabaseHas('tb_products', ['id' => 1]);
    }

    public function test_missing_product_returns_not_found(): void
    {
        $this->deleteJson('/unsold-products/999')->assertNotFound();
    }
    public function test_bulk_delete_removes_only_selected_unique_products(): void
    {
        $this->deleteJson('/unsold-products', ['product_ids' => [1, 1, 2]])->assertOk()->assertJson(['deleted' => 2]);
        $this->assertSame([3], DB::table('tb_products')->pluck('id')->all());
        $this->assertSame(0, DB::table('tb_product_store_prices')->count());
    }

    public function test_bulk_delete_rejects_empty_or_invalid_selection(): void
    {
        $this->deleteJson('/unsold-products', ['product_ids' => []])->assertUnprocessable();
        $this->deleteJson('/unsold-products', ['product_ids' => [1, 999]])->assertUnprocessable();
        $this->assertSame(3, DB::table('tb_products')->count());
    }

    public function test_bulk_delete_rolls_back_on_failure(): void
    {
        DB::unprepared("CREATE TRIGGER block_delete BEFORE DELETE ON tb_products WHEN OLD.id = 2 BEGIN SELECT RAISE(ABORT, 'Referenced'); END");
        $this->deleteJson('/unsold-products', ['product_ids' => [1, 2]])->assertStatus(409);
        $this->assertSame(3, DB::table('tb_products')->count());
        $this->assertSame(2, DB::table('tb_product_store_prices')->count());
    }

}

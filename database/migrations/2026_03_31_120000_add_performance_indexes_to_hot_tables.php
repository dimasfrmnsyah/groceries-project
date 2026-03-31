<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('tb_sells', ['store_id', 'date'], 'idx_tb_sells_store_date');
        $this->addIndexIfMissing('tb_sells', ['date'], 'idx_tb_sells_date');
        $this->addIndexIfMissing('tb_sells', ['no_invoice'], 'idx_tb_sells_invoice');
        $this->addIndexIfMissing('tb_sells', ['customer_id'], 'idx_tb_sells_customer');
        $this->addIndexIfMissing('tb_sells', ['seller_id', 'date'], 'idx_tb_sells_seller_date');

        $this->addIndexIfMissing('tb_outgoing_goods', ['sell_id', 'product_id'], 'idx_tb_outgoing_sell_product');
        $this->addIndexIfMissing('tb_outgoing_goods', ['product_id'], 'idx_tb_outgoing_product');
        $this->addIndexIfMissing('tb_outgoing_goods', ['date'], 'idx_tb_outgoing_date');

        $this->addIndexIfMissing('tb_incoming_goods', ['purchase_id', 'product_id'], 'idx_tb_incoming_purchase_product');
        $this->addIndexIfMissing('tb_incoming_goods', ['product_id'], 'idx_tb_incoming_product');
        $this->addIndexIfMissing('tb_incoming_goods', ['store_id', 'product_id'], 'idx_tb_incoming_store_product');

        $this->addIndexIfMissing('tb_purchases', ['store_id'], 'idx_tb_purchases_store');
        $this->addIndexIfMissing('tb_purchases', ['supplier_id'], 'idx_tb_purchases_supplier');

        $this->addIndexIfMissing('tb_products', ['product_code'], 'idx_tb_products_code');
        $this->addIndexIfMissing('tb_products', ['product_name'], 'idx_tb_products_name');

        $this->addIndexIfMissing('tb_customers', ['store_id'], 'idx_tb_customers_store');

        $this->addIndexIfMissing('tb_master_menuses', ['menu_path', 'is_active'], 'idx_tb_master_menus_path_active');
        $this->addIndexIfMissing('tb_master_menu_roles', ['role_name', 'menu_id'], 'idx_tb_master_menu_roles_role_menu');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('tb_sells', 'idx_tb_sells_store_date');
        $this->dropIndexIfExists('tb_sells', 'idx_tb_sells_date');
        $this->dropIndexIfExists('tb_sells', 'idx_tb_sells_invoice');
        $this->dropIndexIfExists('tb_sells', 'idx_tb_sells_customer');
        $this->dropIndexIfExists('tb_sells', 'idx_tb_sells_seller_date');

        $this->dropIndexIfExists('tb_outgoing_goods', 'idx_tb_outgoing_sell_product');
        $this->dropIndexIfExists('tb_outgoing_goods', 'idx_tb_outgoing_product');
        $this->dropIndexIfExists('tb_outgoing_goods', 'idx_tb_outgoing_date');

        $this->dropIndexIfExists('tb_incoming_goods', 'idx_tb_incoming_purchase_product');
        $this->dropIndexIfExists('tb_incoming_goods', 'idx_tb_incoming_product');
        $this->dropIndexIfExists('tb_incoming_goods', 'idx_tb_incoming_store_product');

        $this->dropIndexIfExists('tb_purchases', 'idx_tb_purchases_store');
        $this->dropIndexIfExists('tb_purchases', 'idx_tb_purchases_supplier');

        $this->dropIndexIfExists('tb_products', 'idx_tb_products_code');
        $this->dropIndexIfExists('tb_products', 'idx_tb_products_name');

        $this->dropIndexIfExists('tb_customers', 'idx_tb_customers_store');

        $this->dropIndexIfExists('tb_master_menuses', 'idx_tb_master_menus_path_active');
        $this->dropIndexIfExists('tb_master_menu_roles', 'idx_tb_master_menu_roles_role_menu');
    }

    private function addIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        if ($this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table) || !$this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return !empty($result);
    }
};

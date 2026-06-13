<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addStoreId('tb_accounting_entries', true);
        $this->addStoreId('tb_budgets');
        $this->addStoreId('tb_expenses');
        $this->addStoreId('tb_supplier_debts');

        if (Schema::hasTable('tb_accounting_entries') && Schema::hasColumn('tb_accounting_entries', 'store_id')) {
            DB::statement("
                UPDATE tb_accounting_entries e
                JOIN tb_sells s ON s.id = e.source_id
                SET e.store_id = s.store_id
                WHERE e.source_type = 'sales' AND e.store_id IS NULL
            ");
            if (Schema::hasTable('tb_customer_receivables')) {
                DB::statement("
                    UPDATE tb_accounting_entries e
                    JOIN tb_customer_receivables r ON r.id = e.source_id
                    SET e.store_id = r.store_id
                    WHERE e.source_type = 'receivable_payment' AND e.store_id IS NULL
                ");
            }
            if (Schema::hasTable('tb_supplier_debts')) {
                DB::statement("
                    UPDATE tb_accounting_entries e
                    JOIN tb_supplier_debts d ON d.id = e.source_id
                    SET e.store_id = d.store_id
                    WHERE e.source_type = 'supplier_debt_payment' AND e.store_id IS NULL
                ");
            }
        }

        if (Schema::hasTable('tb_supplier_debts') && Schema::hasTable('tb_purchases') && Schema::hasColumn('tb_supplier_debts', 'store_id')) {
            DB::statement("
                UPDATE tb_supplier_debts d
                JOIN tb_purchases p ON p.id = d.purchase_id
                SET d.store_id = p.store_id
                WHERE d.store_id IS NULL
            ");
        }

        if (Schema::hasTable('tb_accounting_entries') && Schema::hasTable('tb_supplier_debts') && Schema::hasColumn('tb_accounting_entries', 'store_id')) {
            DB::statement("
                UPDATE tb_accounting_entries e
                JOIN tb_supplier_debts d ON d.id = e.source_id
                SET e.store_id = d.store_id
                WHERE e.source_type = 'supplier_debt_payment' AND e.store_id IS NULL
            ");
        }
    }

    public function down(): void
    {
        foreach (['tb_supplier_debts', 'tb_expenses', 'tb_budgets', 'tb_accounting_entries'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'store_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('store_id');
                });
            }
        }
    }

    private function addStoreId(string $table, bool $withDateIndex = false): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'store_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($withDateIndex) {
            $blueprint->unsignedBigInteger('store_id')->nullable()->after('date');
            if ($withDateIndex) {
                $blueprint->index(['store_id', 'date']);
            }
        });
    }
};

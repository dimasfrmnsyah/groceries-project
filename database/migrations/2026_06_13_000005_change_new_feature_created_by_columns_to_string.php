<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'created_by')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `created_by` VARCHAR(255) NULL");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'created_by')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `created_by` BIGINT UNSIGNED NULL");
            }
        }
    }

    private function tables(): array
    {
        return [
            'tb_stock_transfers',
            'tb_accounting_entries',
            'tb_budgets',
            'tb_expenses',
            'tb_customer_receivables',
            'tb_supplier_debts',
            'tb_cash_opnames',
        ];
    }
};

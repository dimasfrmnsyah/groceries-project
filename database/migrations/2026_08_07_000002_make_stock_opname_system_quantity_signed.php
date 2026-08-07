<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tb_stock_opnames', 'system_quantity')) {
            DB::statement('ALTER TABLE `tb_stock_opnames` MODIFY `system_quantity` BIGINT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tb_stock_opnames', 'system_quantity')) {
            DB::statement('ALTER TABLE `tb_stock_opnames` MODIFY `system_quantity` BIGINT UNSIGNED NULL');
        }
    }
};

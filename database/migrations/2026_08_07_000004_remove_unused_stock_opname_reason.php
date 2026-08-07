<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tb_stock_opnames')) {
            return;
        }

        Schema::table('tb_stock_opnames', function (Blueprint $table) {
            if (Schema::hasColumn('tb_stock_opnames', 'reason')) {
                $table->dropColumn('reason');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tb_stock_opnames')) {
            return;
        }

        Schema::table('tb_stock_opnames', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_stock_opnames', 'reason')) {
                $table->string('reason', 255)->nullable()->after('system_quantity');
            }
        });
    }
};

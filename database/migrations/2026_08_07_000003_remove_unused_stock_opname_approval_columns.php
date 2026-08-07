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
            foreach (['approved_by', 'approved_at'] as $column) {
                if (Schema::hasColumn('tb_stock_opnames', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tb_stock_opnames')) {
            return;
        }

        Schema::table('tb_stock_opnames', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_stock_opnames', 'approved_by')) {
                $table->uuid('approved_by')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('tb_stock_opnames', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }
};

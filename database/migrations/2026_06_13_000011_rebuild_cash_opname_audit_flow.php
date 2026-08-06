<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tb_cash_opnames')) {
            return;
        }

        Schema::table('tb_cash_opnames', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_cash_opnames', 'cashier_name')) {
                $table->string('cashier_name')->nullable()->after('date');
            }
            if (!Schema::hasColumn('tb_cash_opnames', 'audited_at')) {
                $table->dateTime('audited_at')->nullable()->after('cashier_name');
            }
            if (!Schema::hasColumn('tb_cash_opnames', 'running_turnover')) {
                $table->decimal('running_turnover', 18, 2)->default(0)->after('store_id');
            }
            if (!Schema::hasColumn('tb_cash_opnames', 'difference')) {
                $table->decimal('difference', 18, 2)->default(0)->after('nominal');
            }
            if (!Schema::hasColumn('tb_cash_opnames', 'denominations')) {
                $table->json('denominations')->nullable()->after('difference');
            }
        });

        DB::table('tb_cash_opnames')->whereNull('audited_at')->update([
            'audited_at' => DB::raw('COALESCE(created_at, CONCAT(date, " 23:59:59"))'),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tb_cash_opnames')) {
            return;
        }

        Schema::table('tb_cash_opnames', function (Blueprint $table) {
            foreach (['audited_at', 'denominations'] as $column) {
                if (Schema::hasColumn('tb_cash_opnames', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

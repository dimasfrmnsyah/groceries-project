<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_revenues')) {
            return;
        }

        Schema::table('daily_revenues', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_revenues', 'store_id')) {
                $table->unsignedBigInteger('store_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('daily_revenues', 'denominations')) {
                $table->json('denominations')->nullable()->after('amount');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('daily_revenues')) {
            return;
        }

        Schema::table('daily_revenues', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('daily_revenues', 'denominations')) {
                $columns[] = 'denominations';
            }
            if (Schema::hasColumn('daily_revenues', 'store_id')) {
                $columns[] = 'store_id';
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};

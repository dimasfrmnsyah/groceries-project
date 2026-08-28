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
            if (!Schema::hasColumn('daily_revenues', 'qr')) {
                $table->decimal('qr', 15, 2)->nullable()->default(0)->after('amount');
            }
            if (!Schema::hasColumn('daily_revenues', 'pengeluaran')) {
                $table->decimal('pengeluaran', 15, 2)->nullable()->default(0)->after('qr');
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
            if (Schema::hasColumn('daily_revenues', 'pengeluaran')) {
                $columns[] = 'pengeluaran';
            }
            if (Schema::hasColumn('daily_revenues', 'qr')) {
                $columns[] = 'qr';
            }
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};

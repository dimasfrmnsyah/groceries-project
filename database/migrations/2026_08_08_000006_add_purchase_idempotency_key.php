<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tb_purchases', 'idempotency_key')) {
            Schema::table('tb_purchases', function (Blueprint $table) {
                $table->string('idempotency_key', 64)->nullable()->unique()->after('uuid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tb_purchases', 'idempotency_key')) {
            Schema::table('tb_purchases', function (Blueprint $table) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tb_products', 'is_active')) {
            Schema::table('tb_products', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('description')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tb_products', 'is_active')) {
            Schema::table('tb_products', function (Blueprint $table) {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            });
        }
    }
};

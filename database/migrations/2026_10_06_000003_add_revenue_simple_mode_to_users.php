<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'revenue_simple_mode')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('revenue_simple_mode')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'revenue_simple_mode')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('revenue_simple_mode'));
        }
    }
};

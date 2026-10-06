<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_cash_opnames', function (Blueprint $table) {
            $table->decimal('qr_amount', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tb_cash_opnames', function (Blueprint $table) {
            $table->dropColumn('qr_amount');
        });
    }
};

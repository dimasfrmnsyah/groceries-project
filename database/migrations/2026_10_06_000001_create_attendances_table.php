<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->unsignedBigInteger('store_id');
            $table->string('employee_name');
            // NULL for completed shifts; the unique key prevents two active shifts.
            $table->uuid('active_user_id')->nullable()->unique();
            $table->uuid('request_key')->unique();
            $table->dateTime('checked_in_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('normal_seconds')->nullable();
            $table->unsignedBigInteger('overtime_seconds')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'checked_in_at']);
            $table->index(['user_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};

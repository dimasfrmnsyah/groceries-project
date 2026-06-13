<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number')->unique();
            $table->string('account_name');
            $table->string('account_type')->default('kas');
            $table->boolean('is_active')->default(1);
            $table->timestamps();
        });

        Schema::create('tb_accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_accounting_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['store_id', 'date']);
            $table->index(['date', 'direction']);
        });

        Schema::create('tb_budgets', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('category')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('category')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_customer_receivables', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('quantity')->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('status')->default('open');
            $table->unsignedBigInteger('sell_id')->nullable();
            $table->string('description')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_supplier_debts', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->decimal('budget_amount', 18, 2)->default(0);
            $table->decimal('purchase_amount', 18, 2)->default(0);
            $table->decimal('debt_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('status')->default('open');
            $table->string('description')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tb_cash_opnames', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->decimal('nominal', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        DB::table('tb_accounting_accounts')->insert([
            'account_number' => '1',
            'account_name' => 'Kas',
            'account_type' => 'kas',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tb_accounting_settings')->insert([
            'setting_key' => 'sales_account_id',
            'account_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_opnames');
        Schema::dropIfExists('tb_supplier_debts');
        Schema::dropIfExists('tb_customer_receivables');
        Schema::dropIfExists('tb_expenses');
        Schema::dropIfExists('tb_budgets');
        Schema::dropIfExists('tb_accounting_entries');
        Schema::dropIfExists('tb_accounting_settings');
        Schema::dropIfExists('tb_accounting_accounts');
    }
};

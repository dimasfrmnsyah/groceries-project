<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_sells', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_sells', 'created_by')) {
                $table->uuid('created_by')->nullable()->after('store_id');
            }
            if (!Schema::hasColumn('tb_sells', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->unique()->after('uuid');
            }
        });

        Schema::table('tb_outgoing_goods', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_outgoing_goods', 'created_by')) {
                $table->uuid('created_by')->nullable()->after('recorded_by');
            }
            if (!Schema::hasColumn('tb_outgoing_goods', 'source_type')) {
                $table->string('source_type', 40)->nullable()->after('description');
            }
            $table->index(['sell_id', 'product_id'], 'idx_outgoing_sell_product_audit');
            $table->index(['source_type', 'created_at'], 'idx_outgoing_source_created');
        });

        Schema::table('tb_incoming_goods', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_incoming_goods', 'created_by')) {
                $table->uuid('created_by')->nullable()->after('description');
            }
            if (!Schema::hasColumn('tb_incoming_goods', 'source_type')) {
                $table->string('source_type', 40)->nullable()->after('created_by');
            }
        });

        Schema::table('tb_stock_opnames', function (Blueprint $table) {
            if (!Schema::hasColumn('tb_stock_opnames', 'system_quantity')) {
                $table->bigInteger('system_quantity')->nullable()->after('physical_quantity');
            }
            if (!Schema::hasColumn('tb_stock_opnames', 'created_by')) {
                $table->uuid('created_by')->nullable()->after('system_quantity');
            }
        });

        if (!Schema::hasTable('tb_store_status_logs')) {
            Schema::create('tb_store_status_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->uuid('user_id')->nullable();
                $table->boolean('from_online')->nullable();
                $table->boolean('to_online');
                $table->text('offline_note')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->index(['store_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_store_status_logs');

        foreach (['tb_stock_opnames', 'tb_incoming_goods', 'tb_outgoing_goods', 'tb_sells'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $columns = [
                    'tb_stock_opnames' => ['system_quantity', 'created_by'],
                    'tb_incoming_goods' => ['created_by', 'source_type'],
                    'tb_outgoing_goods' => ['created_by', 'source_type'],
                    'tb_sells' => ['created_by', 'idempotency_key'],
                ][$tableName];

                foreach ($columns as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

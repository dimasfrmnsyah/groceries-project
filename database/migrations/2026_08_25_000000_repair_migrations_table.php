<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair legacy databases where Laravel's migration id was imported without
     * its primary key/auto-increment definition.
     */
    public function up(): void
    {
        if (!Schema::hasTable('migrations') || !Schema::hasColumn('migrations', 'id')) {
            return;
        }

        $hasPrimaryKey = !empty(DB::select(
            "SHOW INDEX FROM `migrations` WHERE Key_name = 'PRIMARY'"
        ));

        if (!$hasPrimaryKey) {
            DB::statement('ALTER TABLE `migrations` ADD PRIMARY KEY (`id`)');
        }

        DB::statement(
            'ALTER TABLE `migrations` MODIFY `id` INT UNSIGNED NOT NULL AUTO_INCREMENT'
        );
    }

    public function down(): void
    {
        // Do not remove migration tracking integrity during rollback.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tb_master_roles')) {
            return;
        }

        $roleNames = collect(['superadmin', 'admin', 'staff']);

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'roles')) {
            $roleNames = $roleNames->merge(DB::table('users')->whereNotNull('roles')->pluck('roles'));
        }

        if (Schema::hasTable('tb_master_menu_roles') && Schema::hasColumn('tb_master_menu_roles', 'role_name')) {
            $roleNames = $roleNames->merge(
                DB::table('tb_master_menu_roles')->whereNotNull('role_name')->pluck('role_name')
            );
        }

        $roleNames = $roleNames
            ->map(fn ($role) => Str::of((string) $role)->trim()->lower()->slug('_')->toString())
            ->filter()
            ->unique()
            ->values();

        DB::transaction(function () use ($roleNames) {
            foreach ($roleNames as $roleName) {
                $exists = DB::table('tb_master_roles')
                    ->whereRaw('LOWER(TRIM(role_name)) = ?', [$roleName])
                    ->exists();

                if (!$exists) {
                    DB::table('tb_master_roles')->insert([
                        'role_name' => $roleName,
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Role lama sengaja dipertahankan agar user dan akses menu tidak menjadi orphan.
    }
};

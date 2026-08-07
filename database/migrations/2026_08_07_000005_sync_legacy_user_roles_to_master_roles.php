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

        $roleNames = collect();
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'roles')) {
            $roleNames = $roleNames->merge(DB::table('users')->whereNotNull('roles')->pluck('roles'));
        }
        if (Schema::hasTable('tb_master_menu_roles') && Schema::hasColumn('tb_master_menu_roles', 'role_name')) {
            $roleNames = $roleNames->merge(DB::table('tb_master_menu_roles')->whereNotNull('role_name')->pluck('role_name'));
        }

        $roleNames = $roleNames
            ->map(fn ($role) => Str::of((string) $role)->trim()->lower()->slug('_')->toString())
            ->filter()
            ->unique()
            ->values();

        $existing = DB::table('tb_master_roles')
            ->pluck('role_name')
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->filter()
            ->all();

        DB::transaction(function () use ($roleNames, $existing) {
            foreach ($roleNames as $roleName) {
                if (in_array($roleName, $existing, true)) {
                    continue;
                }

                DB::table('tb_master_roles')->insert([
                    'role_name' => $roleName,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Skema permission lama memakai role_id. Isi role_name agar
            // permission lama tetap terbaca oleh MenuHelper yang berbasis nama role.
            if (Schema::hasTable('tb_master_menu_roles')
                && Schema::hasColumn('tb_master_menu_roles', 'role_id')
                && Schema::hasColumn('tb_master_menu_roles', 'role_name')) {
                $roleNamesById = DB::table('tb_master_roles')->pluck('role_name', 'id');
                foreach (DB::table('tb_master_menu_roles')->whereNull('role_name')->whereNotNull('role_id')->get() as $menuRole) {
                    $roleName = $roleNamesById[(int) $menuRole->role_id] ?? null;
                    if ($roleName) {
                        DB::table('tb_master_menu_roles')->where('id', $menuRole->id)->update([
                            'role_name' => strtolower(trim((string) $roleName)),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Jangan menghapus role karena mungkin sudah dipakai user.
    }
};

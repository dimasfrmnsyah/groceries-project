<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tb_master_menuses')) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $settingsId = DB::table('tb_master_menuses')
                ->whereRaw('LOWER(TRIM(menu_name)) = ?', ['settings'])
                ->value('id');

            if (!$settingsId) {
                $settings = [
                    'menu_name' => 'Settings',
                    'menu_path' => null,
                    'menu_icon' => 'bx bx-category',
                    'parent_id' => null,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (Schema::hasColumn('tb_master_menuses', 'sort')) {
                    $settings['sort'] = 90;
                }
                $settingsId = DB::table('tb_master_menuses')->insertGetId($settings);
            }

            $menuId = DB::table('tb_master_menuses')
                ->where('menu_path', 'settings.roles.index')
                ->value('id');

            $menu = [
                'menu_name' => 'Kelola Role',
                'menu_icon' => 'bx bx-user-check',
                'parent_id' => $settingsId,
                'is_active' => 1,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('tb_master_menuses', 'sort')) {
                $menu['sort'] = 10;
            }

            if ($menuId) {
                DB::table('tb_master_menuses')->where('id', $menuId)->update($menu);
            } else {
                $menuId = DB::table('tb_master_menuses')->insertGetId(array_merge($menu, [
                    'menu_path' => 'settings.roles.index',
                    'created_at' => $now,
                ]));
            }

            if (Schema::hasTable('tb_master_menu_roles') && Schema::hasColumn('tb_master_menu_roles', 'role_name')) {
                DB::table('tb_master_menu_roles')->updateOrInsert(
                    ['menu_id' => $menuId, 'role_name' => 'superadmin'],
                    ['role_id' => null, 'created_at' => $now, 'updated_at' => $now]
                );
            }
        });

        Cache::forget('menu_active_routes');
        Cache::forget('menu_allowed_routes:superadmin');
    }

    public function down(): void
    {
        if (!Schema::hasTable('tb_master_menuses')) {
            return;
        }

        $menuId = DB::table('tb_master_menuses')
            ->where('menu_path', 'settings.roles.index')
            ->value('id');

        if ($menuId && Schema::hasTable('tb_master_menu_roles')) {
            DB::table('tb_master_menu_roles')->where('menu_id', $menuId)->delete();
        }
        if ($menuId) {
            DB::table('tb_master_menuses')->where('id', $menuId)->delete();
        }

        Cache::forget('menu_active_routes');
        Cache::forget('menu_allowed_routes:superadmin');
    }
};

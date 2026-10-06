<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $now = now();
            DB::table('tb_master_menuses')->updateOrInsert(['menu_path' => 'attendance.index'], [
                'menu_name' => 'Absensi', 'parent_id' => null, 'menu_icon' => 'bx bx-time-five',
                'sort' => 85, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $id = DB::table('tb_master_menuses')->where('menu_path', 'attendance.index')->value('id');
            foreach (['admin', 'superadmin'] as $role) {
                DB::table('tb_master_menu_roles')->updateOrInsert(['menu_id' => $id, 'role_name' => $role], [
                    'role_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        $this->clearCaches();
    }

    public function down(): void
    {
        $ids = DB::table('tb_master_menuses')->where('menu_path', 'attendance.index')->pluck('id');
        DB::table('tb_master_menu_roles')->whereIn('menu_id', $ids)->delete();
        DB::table('tb_master_menuses')->whereIn('id', $ids)->delete();
        $this->clearCaches();
    }

    private function clearCaches(): void
    {
        Cache::forget('menu_active_routes');
        foreach (['admin', 'superadmin'] as $role) {
            Cache::forget('menu_allowed_routes:' . $role);
            Cache::forget('sidebar_menus:' . $role);
        }
    }
};

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
            $parentId = DB::table('tb_master_menuses')->whereNull('parent_id')
                ->whereIn('menu_name', ['Stok', 'Stock'])->value('id');
            if (!$parentId) {
                $parentId = DB::table('tb_master_menuses')->insertGetId([
                    'menu_name' => 'Stok', 'menu_path' => null, 'menu_icon' => 'bx bx-package',
                    'sort' => 45, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('tb_master_menuses')->updateOrInsert(['menu_path' => 'unsold-products.index'], [
                'menu_name' => 'Barang Tidak Laku', 'parent_id' => $parentId,
                'menu_icon' => 'bx bx-package', 'sort' => 48, 'is_active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $menuId = DB::table('tb_master_menuses')->where('menu_path', 'unsold-products.index')->value('id');
            foreach ([$parentId, $menuId] as $id) {
                DB::table('tb_master_menu_roles')->updateOrInsert(['menu_id' => $id, 'role_name' => 'superadmin'], [
                    'role_id' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
        $this->clearMenuCaches();
    }

    public function down(): void
    {
        $ids = DB::table('tb_master_menuses')->where('menu_path', 'unsold-products.index')->pluck('id');
        DB::table('tb_master_menu_roles')->whereIn('menu_id', $ids)->delete();
        DB::table('tb_master_menuses')->whereIn('id', $ids)->delete();
        $this->clearMenuCaches();
    }

    private function clearMenuCaches(): void
    {
        Cache::forget('menu_active_routes');
        foreach (DB::table('tb_master_menu_roles')->distinct()->pluck('role_name') as $role) {
            $role = strtolower(trim((string) $role));
            Cache::forget('menu_allowed_routes:'.$role);
            Cache::forget('sidebar_menus:'.$role);
        }
    }
};

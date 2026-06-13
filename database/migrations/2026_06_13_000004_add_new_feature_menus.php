<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $stockParentId = DB::table('tb_master_menuses')
            ->where('menu_name', 'Stok')
            ->value('id');

        if (!$stockParentId) {
            $stockParentId = DB::table('tb_master_menuses')->insertGetId([
                'menu_name' => 'Stok',
                'menu_path' => null,
                'menu_icon' => 'bx bx-package',
                'sort' => 45,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $accountingParentId = DB::table('tb_master_menuses')
            ->where('menu_name', 'Accounting')
            ->value('id');

        if (!$accountingParentId) {
            $accountingParentId = DB::table('tb_master_menuses')->insertGetId([
                'menu_name' => 'Accounting',
                'menu_path' => null,
                'menu_icon' => 'bx bx-wallet',
                'sort' => 80,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $menus = [
            ['Item Moving', 'item-moving.index', 'bx bx-trending-up', $stockParentId, 46],
            ['Transfer Stok', 'stock-transfer.index', 'bx bx-transfer', $stockParentId, 47],
            ['Account Bank/Kas', 'accounting.accounts.index', 'bx bx-credit-card', $accountingParentId, 81],
            ['Buku Kas', 'accounting.cash-book.index', 'bx bx-book', $accountingParentId, 82],
            ['Budgeting', 'accounting.budgeting.index', 'bx bx-money', $accountingParentId, 83],
            ['Pengeluaran', 'accounting.expenses.index', 'bx bx-receipt', $accountingParentId, 84],
            ['Piutang Pelanggan', 'accounting.receivables.index', 'bx bx-user-check', $accountingParentId, 85],
            ['Hutang Supplier', 'accounting.supplier-debts.index', 'bx bx-store', $accountingParentId, 86],
            ['Cash Opname', 'accounting.cash-opname.index', 'bx bx-calculator', $accountingParentId, 87],
        ];

        foreach ($menus as [$name, $route, $icon, $parentId, $sort]) {
            $menuId = DB::table('tb_master_menuses')->where('menu_path', $route)->value('id');
            if (!$menuId) {
                $menuId = DB::table('tb_master_menuses')->insertGetId([
                    'menu_name' => $name,
                    'menu_path' => $route,
                    'menu_icon' => $icon,
                    'parent_id' => $parentId,
                    'sort' => $sort,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('tb_master_menu_roles')->updateOrInsert(
                ['menu_id' => $menuId, 'role_name' => 'superadmin'],
                ['role_id' => null, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        foreach ([$stockParentId, $accountingParentId] as $menuId) {
            DB::table('tb_master_menu_roles')->updateOrInsert(
                ['menu_id' => $menuId, 'role_name' => 'superadmin'],
                ['role_id' => null, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        $routes = [
            'item-moving.index',
            'stock-transfer.index',
            'accounting.accounts.index',
            'accounting.cash-book.index',
            'accounting.budgeting.index',
            'accounting.expenses.index',
            'accounting.receivables.index',
            'accounting.supplier-debts.index',
            'accounting.cash-opname.index',
        ];

        $ids = DB::table('tb_master_menuses')->whereIn('menu_path', $routes)->pluck('id');
        DB::table('tb_master_menu_roles')->whereIn('menu_id', $ids)->delete();
        DB::table('tb_master_menuses')->whereIn('id', $ids)->delete();
    }
};

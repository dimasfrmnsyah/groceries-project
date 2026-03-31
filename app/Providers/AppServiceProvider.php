<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\tb_master_menus;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        View::composer('layouts.sidebar', function ($view) {
            try {
                $user = Auth::user();
                $role = $user ? strtolower(trim($user->roles)) : '';
                if ($role === '') {
                    $view->with('sidebarMenus', collect());
                    return;
                }

                $menus = Cache::remember(
                    'sidebar_menus:'.$role,
                    now()->addMinutes(10),
                    fn () => tb_master_menus::treeAllowedForRoleName($role)
                );
                $view->with('sidebarMenus', $menus);
            } catch (\Throwable $e) {
                Log::error('SIDEBAR_ERROR', ['msg' => $e->getMessage()]);
                $view->with('sidebarMenus', collect());
            }
        });

        View::composer('layouts.header', function ($view) {
            try {
                $view->with('lowStockItemsGlobal', collect());
            } catch (\Throwable $e) {
                Log::error('LOW_STOCK_HEADER', ['msg' => $e->getMessage()]);
                $view->with('lowStockItemsGlobal', collect());
            }
        });
    }

    private function lowStockItems(int $storeId)
    {
        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('ig.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'store_id'),
                fn ($q) => $q->where(function ($qq) use ($storeId) {
                    $qq->where('ig.store_id', $storeId)
                       ->orWhereExists(function ($ex) use ($storeId) {
                           $ex->select(DB::raw(1))
                              ->from('tb_purchases as pur')
                              ->whereColumn('pur.id', 'ig.purchase_id')
                              ->where('pur.store_id', $storeId);
                       });
                })->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                    fn ($q2) => $q2->where(function ($w) {
                        $w->whereNull('ig.is_pending_stock')
                          ->orWhere('ig.is_pending_stock', 0);
                    })),
                fn ($q) => $q->join('tb_purchases as pur', 'ig.purchase_id', '=', 'pur.id')
                             ->where('pur.store_id', $storeId)
                             ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                                 fn ($q2) => $q2->where(function ($w) {
                                     $w->whereNull('ig.is_pending_stock')
                                       ->orWhere('ig.is_pending_stock', 0);
                                 }))
            )
            ->select('ig.product_id', DB::raw('SUM(ig.stock) AS total_in'))
            ->groupBy('ig.product_id');

        $outgoingSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as sl', 'og.sell_id', '=', 'sl.id')
            ->where('sl.store_id', $storeId)
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('og.deleted_at')
            )
            ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('og.is_pending_stock')
                           ->orWhere('og.is_pending_stock', 0);
                    });
                })
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) AS total_out'))
            ->groupBy('og.product_id');

        return DB::table('tb_products as p')
            ->join('tb_product_store_thresholds as sp', function ($join) use ($storeId) {
                $join->on('sp.product_id', '=', 'p.id')
                     ->where('sp.store_id', '=', $storeId);
            })
            ->join('tb_stores as st_store', 'st_store.id', '=', 'sp.store_id')
            ->leftJoinSub($incomingSub, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn ($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                'sp.store_id',
                'st_store.store_name',
                'sp.min_stock',
                'sp.max_stock',
                DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) as stock_system'),
                DB::raw('GREATEST(COALESCE(sp.max_stock,0) - (COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)),0) as po_qty')
            )
            ->whereNotNull('sp.min_stock')
            ->whereRaw('COALESCE(sp.min_stock,0) > 0')
            ->whereRaw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) <= COALESCE(sp.min_stock, 0)')
            ->orderBy('p.product_name')
            ->get();
    }

    private function lowStockAllStores()
    {
        $storeIds = DB::table('tb_product_store_thresholds')
            ->select('store_id')
            ->distinct()
            ->pluck('store_id')
            ->filter();

        $all = collect();
        foreach ($storeIds as $sid) {
            try {
                $all = $all->merge($this->lowStockItems((int)$sid));
            } catch (\Throwable $e) {
                Log::error('LOW_STOCK_ALL_STORES_ITEM', ['store_id' => $sid, 'msg' => $e->getMessage()]);
            }
        }

        return $all;
    }
}

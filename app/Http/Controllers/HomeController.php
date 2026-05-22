<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class HomeController extends Controller
{
public function index(Request $request)
{
    $range = $request->get('range', 'monthly');
    $selectedStoreId = $request->get('store', null);

    $dateFromReq = $request->get('date_from');
    $dateToReq   = $request->get('date_to');

    // normalize date range (optional -> nullable Carbon)
    $dateFrom = $dateFromReq ? Carbon::createFromFormat('Y-m-d', $dateFromReq)->startOfDay() : null;
    $dateTo   = $dateToReq   ? Carbon::createFromFormat('Y-m-d', $dateToReq)->endOfDay()   : null;

    // kalau hanya salah satu yang diisi
    if ($dateFrom && !$dateTo) {
        $dateTo = (clone $dateFrom)->endOfDay();
    } elseif (!$dateFrom && $dateTo) {
        $dateFrom = (clone $dateTo)->startOfDay();
    }

    $user = Auth::user();
    $isSuperadmin = $user && strtolower((string) ($user->roles ?? '')) === 'superadmin';
    $storeId = store_access_resolve_id($request, $user, ['store']);
    $selectedStoreId = $storeId;

    $stores = store_access_can_select($user)
        ? store_access_list($user)
        : collect();

    $usingSpecificRange = $dateFrom && $dateTo;
    $hasSellerIdColumn  = Schema::hasColumn('tb_sells', 'seller_id');
    $hasInvoiceColumn   = Schema::hasColumn('tb_sells', 'no_invoice');

    // Abaikan transaksi penyesuaian stok opname (seller_id=1 atau invoice SO-ADJ)
    $excludeStockOpname = function ($query) use ($hasSellerIdColumn, $hasInvoiceColumn) {
        if ($hasSellerIdColumn) {
            $query->where(function ($q) {
                $q->whereNull('s.seller_id')
                  ->orWhere('s.seller_id', '!=', 1);
            });
        }

        if ($hasInvoiceColumn) {
            $query->where(function ($q) {
                $q->whereNull('s.no_invoice')
                  ->orWhere('s.no_invoice', 'not like', 'SO-ADJ-%');
            });
        }
    };

    // ===== Labels & Grouping =====
    if ($usingSpecificRange) {
        // label per hari dari dateFrom..dateTo
        $labels = collect();
        $cursor = (clone $dateFrom)->startOfDay();
        while ($cursor->lte($dateTo)) {
            $labels->push($cursor->format('Y-m-d'));
            $cursor->addDay();
        }
        // group by DATE untuk sejajarkan omzet & HPP
        $groupBySalesSql = "DATE(s.date)";
        $groupBySales = DB::raw("DATE(s.date) as group_val");
        $groupByHpp   = DB::raw("DATE(s.date) as group_val");
    } else {
        // pakai logic lama (daily/weekly/monthly/yearly)
        switch ($range) {
            case 'daily':
                $labels = collect(range(6, 0))->map(fn($i) => Carbon::today()->subDays($i)->format('Y-m-d'));
                $groupBySalesSql = "DATE(s.date)";
                $groupBySales = DB::raw("DATE(s.date) as group_val");
                $groupByHpp   = DB::raw("DATE(s.date) as group_val");
                break;

            case 'weekly':
                $labels = collect(['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4']);
                break;

            case 'yearly':
                $startYear = now()->year - 4;
                $labels = collect(range($startYear, now()->year))->map(fn($y) => (string) $y);
                $groupBySalesSql = "YEAR(s.date)";
                $groupBySales = DB::raw("YEAR(s.date) as group_val");
                $groupByHpp   = DB::raw("YEAR(s.date) as group_val");
                break;

            case 'monthly':
            default:
                $labels = collect(range(5, 0))->map(fn($i) => now()->subMonths($i)->format('Y-m'));
                $groupBySalesSql = "DATE_FORMAT(s.date, '%Y-%m')";
                $groupBySales = DB::raw("DATE_FORMAT(s.date, '%Y-%m') as group_val");
                $groupByHpp   = DB::raw("DATE_FORMAT(s.date, '%Y-%m') as group_val");
                break;
        }
    }

    // ===== Base queries =====
    $salesQuery = DB::table('tb_sells as s')
        ->join('tb_outgoing_goods as og', 'og.sell_id', '=', 's.id')
        ->when(
            Schema::hasColumn('tb_sells', 'deleted_at'),
            fn ($q) => $q->whereNull('s.deleted_at')
        )
        ->when(
            Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
            fn ($q) => $q->whereNull('og.deleted_at')
        )
        ->select();
    $hppBase = DB::table('tb_outgoing_goods as og')
        ->join('tb_sells as s', 'og.sell_id', '=', 's.id')
        ->join('tb_products as p', 'og.product_id', '=', 'p.id')
        ->when(
            Schema::hasColumn('tb_sells', 'deleted_at'),
            fn ($q) => $q->whereNull('s.deleted_at')
        )
        ->when(
            Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
            fn ($q) => $q->whereNull('og.deleted_at')
        );

    $excludeStockOpname($salesQuery);
    $excludeStockOpname($hppBase);

    if ($storeId) {
        $salesQuery->where('s.store_id', $storeId);
        $hppBase->where('s.store_id', $storeId);
    }

    // Terapkan filter tanggal spesifik bila ada
    if ($usingSpecificRange) {
        $salesQuery->whereBetween('s.date', [$dateFrom, $dateTo]);
        $hppBase->whereBetween('s.date', [$dateFrom, $dateTo]);
    }

    // ===== Perhitungan =====
    if (!$usingSpecificRange && $range === 'weekly') {
        // logic mingguan lama tetap
        $start = now()->subDays(27)->startOfDay();
        $end   = now()->endOfDay();

        $salesRawQuery = DB::table('tb_sells as s')
            ->join('tb_outgoing_goods as og', 'og.sell_id', '=', 's.id')
            ->when(
                Schema::hasColumn('tb_sells', 'deleted_at'),
                fn ($q) => $q->whereNull('s.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('og.deleted_at')
            )
            ->when($storeId, fn($q) => $q->where('s.store_id', $storeId))
            ->whereBetween('s.date', [$start, $end])
            ->selectRaw('s.id, s.date, s.total_price')
            ->groupBy('s.id', 's.date', 's.total_price');
        $excludeStockOpname($salesRawQuery);
        $salesRaw = $salesRawQuery->get();

        $hppRaw = (clone $hppBase)
            ->whereBetween('s.date', [$start, $end])
            ->get(['s.date', DB::raw('og.quantity_out as qty'), DB::raw('p.purchase_price as cogs_unit')]);

        $sales = collect([0,0,0,0]);
        $hpp   = collect([0,0,0,0]);

        foreach ($salesRaw as $row) {
            $diff  = now()->diffInDays(Carbon::parse($row->date));
            $index = intdiv($diff, 7);
            if ($index < 4) $sales[3 - $index] += (float)$row->total_price;
        }

        foreach ($hppRaw as $row) {
            $diff  = now()->diffInDays(Carbon::parse($row->date));
            $index = intdiv($diff, 7);
            if ($index < 4) $hpp[3 - $index] += (float)$row->qty * (float)$row->cogs_unit;
        }
    } else {
        // OMZET by buckets
        $salesSub = (clone $salesQuery)
            ->select('s.id', 's.date', 's.total_price')
            ->groupBy('s.id', 's.date', 's.total_price');

        $bucketedSales = DB::query()
            ->fromSub($salesSub, 's')
            ->selectRaw($groupBySalesSql.' as group_val, s.total_price as total_price');

        $salesData = DB::query()
            ->fromSub($bucketedSales, 'bucketed_sales')
            ->selectRaw('group_val, SUM(total_price) as total')
            ->groupBy('group_val')
            ->pluck('total', 'group_val');

        // HPP (COGS) by buckets
        $hppData = (clone $hppBase)
            ->select($groupByHpp, DB::raw('SUM(og.quantity_out * p.purchase_price) as total'))
            ->when($usingSpecificRange, fn($q) => $q->whereBetween('s.date', [$dateFrom, $dateTo]))
            ->groupBy('group_val')
            ->pluck('total', 'group_val');

        $sales = $labels->map(fn($label) => (float) ($salesData[$label] ?? 0));
        $hpp   = $labels->map(fn($label) => (float) ($hppData[$label] ?? 0));
    }

    $laba = $sales->map(fn($val, $i) => $val - ($hpp[$i] ?? 0));
    $totalOmset = $sales->sum();
    $totalHpp   = $hpp->sum();
    $totalLaba  = $laba->sum();

    // ===== Top 5 Produk (ikut filter tanggal & store) =====
    $topProductsQuery = DB::table('tb_outgoing_goods as og')
        ->join('tb_products as p', 'og.product_id', '=', 'p.id')
        ->join('tb_sells as s', 'og.sell_id', '=', 's.id')
        ->when(
            Schema::hasColumn('tb_sells', 'deleted_at'),
            fn ($q) => $q->whereNull('s.deleted_at')
        )
        ->when(
            Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
            fn ($q) => $q->whereNull('og.deleted_at')
        )
        ->select('p.product_name', DB::raw('SUM(og.quantity_out) as total_sold'))
        ->groupBy('p.product_name')
        ->orderByDesc('total_sold')
        ->limit(5);

    $excludeStockOpname($topProductsQuery);

    if ($storeId) {
        $topProductsQuery->where('s.store_id', $storeId);
    }
    if ($usingSpecificRange) {
        $topProductsQuery->whereBetween('s.date', [$dateFrom, $dateTo]);
    }

        $topProducts = $topProductsQuery->get();
        $lowStockItems = collect();

    return view('home', [
        'stores'          => $stores,
        'selectedStoreId' => $selectedStoreId,
        'range'           => $range,
        'labels'          => $labels->values(),
        'omsetData'       => $sales->values(),
        'hppData'         => $hpp->values(),
        'labaData'        => $laba->values(),
        'totalOmset'      => $totalOmset,
        'totalHpp'        => $totalHpp,
        'totalLaba'       => $totalLaba,
        'topProducts'     => $topProducts,
        'lowStockItems'   => $lowStockItems,
    ]);
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
                    })
                             ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                                 function ($q2) {
                                     $q2->where(function ($qq) {
                                         $qq->whereNull('ig.is_pending_stock')
                                            ->orWhere('ig.is_pending_stock', 0);
                                     });
                                 }),
                fn ($q) => $q->join('tb_purchases as pur', 'ig.purchase_id', '=', 'pur.id')
                             ->where('pur.store_id', $storeId)
                             ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                                 function ($q2) {
                                     $q2->where(function ($qq) {
                                         $qq->whereNull('ig.is_pending_stock')
                                            ->orWhere('ig.is_pending_stock', 0);
                                     });
                                 })
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
            ->leftJoinSub($incomingSub, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn ($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                'sp.min_stock',
                'sp.max_stock',
                DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) as stock_system')
            )
            ->whereNotNull('sp.min_stock')
            ->whereRaw('COALESCE(sp.min_stock,0) > 0')
            ->whereRaw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) <= COALESCE(sp.min_stock, 0)')
            ->orderBy('p.product_name')
            ->get();
    }

    private function lowStockAllStores()
    {
        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->join('tb_purchases as pur', 'ig.purchase_id', '=', 'pur.id')
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('ig.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('ig.is_pending_stock')
                           ->orWhere('ig.is_pending_stock', 0);
                    });
                }
            )
            ->select('pur.store_id', 'ig.product_id', DB::raw('SUM(ig.stock) AS total_in'))
            ->groupBy('pur.store_id', 'ig.product_id');

        $outgoingSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as sl', 'og.sell_id', '=', 'sl.id')
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('og.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('og.is_pending_stock')
                           ->orWhere('og.is_pending_stock', 0);
                    });
                }
            )
            ->select('sl.store_id', 'og.product_id', DB::raw('SUM(og.quantity_out) AS total_out'))
            ->groupBy('sl.store_id', 'og.product_id');

        return DB::table('tb_products as p')
            ->join('tb_product_store_thresholds as sp', 'sp.product_id', '=', 'p.id')
            ->join('tb_stores as st', 'st.id', '=', 'sp.store_id')
            ->leftJoinSub($incomingSub, 'incoming', function ($join) {
                $join->on('incoming.product_id', '=', 'p.id')
                     ->on('incoming.store_id', '=', 'sp.store_id');
            })
            ->leftJoinSub($outgoingSub, 'outgoing', function ($join) {
                $join->on('outgoing.product_id', '=', 'p.id')
                     ->on('outgoing.store_id', '=', 'sp.store_id');
            })
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                'sp.store_id',
                'st.store_name',
                'sp.min_stock',
                'sp.max_stock',
                DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) as stock_system')
            )
            ->whereNotNull('sp.min_stock')
            ->whereRaw('COALESCE(sp.min_stock,0) > 0')
            ->whereRaw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) <= COALESCE(sp.min_stock, 0)')
            ->orderBy('st.store_name')
            ->orderBy('p.product_name')
            ->get();
    }

}

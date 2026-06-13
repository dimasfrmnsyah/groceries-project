<?php

namespace App\Http\Controllers;

use App\Models\tb_stores;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Yajra\DataTables\Facades\DataTables;

class StoreMonthlyReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canSelectStore = store_access_can_select($user);
        $stores = $canSelectStore ? store_access_list($user) : collect();
        $selectedStoreId = store_access_resolve_id($request, $user, ['store']);

        $currentStoreName = $selectedStoreId
            ? tb_stores::where('id', $selectedStoreId)->value('store_name')
            : null;

        $defaultTo = now('Asia/Jakarta')->startOfMonth();
        $defaultFrom = (clone $defaultTo)->subMonth();

        return view('pages.admin.report.store-monthly', [
            'stores'           => $stores,
            'isSuperadmin'     => $canSelectStore,
            'selectedStoreId'  => $selectedStoreId,
            'currentStoreName' => $currentStoreName,
            'defaultMonthFrom' => $defaultFrom->format('Y-m'),
            'defaultMonthTo'   => $defaultTo->format('Y-m'),
        ]);
    }

    public function data(Request $request)
    {
        $user = $request->user();
        $storeId = store_access_resolve_id($request, $user, ['store']);

        [$startMonth, $endMonth] = $this->resolveMonthRange(
            $request->get('month_from'),
            $request->get('month_to')
        );

        $monthFrom = $startMonth->format('Y-m');
        $monthTo = $endMonth->format('Y-m');

        $startDate = $startMonth->copy()->startOfMonth();
        $endDate = $endMonth->copy()->endOfMonth();

        $hasSellerIdColumn = Schema::hasColumn('tb_sells', 'seller_id');
        $hasInvoiceColumn  = Schema::hasColumn('tb_sells', 'no_invoice');

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
                      ->orWhere(function ($qq) {
                          $qq->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                             ->where('s.no_invoice', 'not like', 'AR-%')
                             ->where('s.no_invoice', 'not like', 'TRF-%');
                      });
                });
            }
        };

        $dateExpr = 'COALESCE(s.date, s.created_at)';
        $monthExpr = "DATE_FORMAT($dateExpr, '%Y-%m')";
        $targetMonths = array_values(array_unique([$monthFrom, $monthTo]));

        $salesBase = DB::table('tb_sells as s')
            ->leftJoin('tb_stores as st', 'st.id', '=', 's.store_id')
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->whereBetween(DB::raw($dateExpr), [$startDate->startOfDay(), $endDate->endOfDay()])
            ->whereIn(DB::raw($monthExpr), $targetMonths)
            ->selectRaw("
                s.id as sell_id,
                s.store_id as store_id,
                st.store_name as store_name,
                $monthExpr as sale_month,
                s.total_price as total_price
            ");

        $excludeStockOpname($salesBase);

        $monthly = DB::query()
            ->fromSub($salesBase, 'sales')
            ->selectRaw('
                store_id,
                MIN(store_name) as store_name,
                SUM(CASE WHEN sale_month = ? THEN total_price ELSE 0 END) as total_from,
                SUM(CASE WHEN sale_month = ? THEN total_price ELSE 0 END) as total_to,
                SUM(CASE WHEN sale_month = ? THEN 1 ELSE 0 END) as trx_from,
                SUM(CASE WHEN sale_month = ? THEN 1 ELSE 0 END) as trx_to
            ', [$monthFrom, $monthTo, $monthFrom, $monthTo])
            ->groupBy('store_id')
            ->orderBy('store_name');

        $rows = $monthly->get();
        $rowsWithMetrics = $this->applyMetrics($rows);
        $totals = $this->buildTotals($rowsWithMetrics, $startMonth, $endMonth);

        return DataTables::of($rowsWithMetrics)
            ->addIndexColumn()
            ->addColumn('action', function ($row) use ($monthFrom, $monthTo) {
                $params = [
                    'store' => $row->store_id ?? '',
                    'month_from' => $monthFrom,
                    'month_to' => $monthTo,
                ];
                $detailUrl = route('report.store.monthly.detail') . '?' . http_build_query($params);
                return '<a href="' . e($detailUrl) . '" class="btn btn-sm btn-success">Detail</a>';
            })
            ->rawColumns(['action'])
            ->with(['totals' => $totals])
            ->toJson();
    }

    public function detail(Request $request)
    {
        $user = $request->user();
        $storeId = store_access_resolve_id($request, $user, ['store']);
        $currentStoreName = $storeId
            ? tb_stores::where('id', $storeId)->value('store_name')
            : null;

        [$startMonth, $endMonth] = $this->resolveMonthRange(
            $request->get('month_from'),
            $request->get('month_to')
        );

        return view('pages.admin.report.store-monthly-detail', [
            'storeId' => $storeId,
            'storeName' => $currentStoreName,
            'monthFrom' => $startMonth->format('Y-m'),
            'monthTo' => $endMonth->format('Y-m'),
        ]);
    }

    public function detailData(Request $request)
    {
        $user = $request->user();
        $storeId = store_access_resolve_id($request, $user, ['store']);

        $monthValue = $request->get('month');
        $month = $this->tryParseMonth($monthValue, 'Asia/Jakarta');

        if (!$storeId || !$month) {
            return DataTables::of(collect())->toJson();
        }

        $startDate = $month->copy()->startOfMonth();
        $endDate = $month->copy()->endOfMonth();

        $hasSellerIdColumn = Schema::hasColumn('tb_sells', 'seller_id');
        $hasInvoiceColumn = Schema::hasColumn('tb_sells', 'no_invoice');

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
                      ->orWhere(function ($qq) {
                          $qq->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                             ->where('s.no_invoice', 'not like', 'AR-%')
                             ->where('s.no_invoice', 'not like', 'TRF-%');
                      });
                });
            }
        };

        $dateExpr = 'COALESCE(s.date, s.created_at)';
        $invoiceSelect = $hasInvoiceColumn ? 's.no_invoice' : 'NULL as no_invoice';

        $query = DB::table('tb_sells as s')
            ->leftJoin('tb_stores as st', 'st.id', '=', 's.store_id')
            ->where('s.store_id', $storeId)
            ->whereBetween(DB::raw($dateExpr), [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw("
                s.id as sell_id,
                $invoiceSelect,
                st.store_name as store_name,
                DATE($dateExpr) as sale_date,
                s.total_price as total_price,
                s.created_at as created_at
            ");

        $excludeStockOpname($query);

        $query->orderByDesc('created_at');

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('action', function ($row) {
                $url = route('sell.detail', $row->sell_id);
                return '<a href="' . e($url) . '" class="btn btn-sm btn-primary" target="_blank">Detail</a>';
            })
            ->rawColumns(['action'])
            ->toJson();
    }

    private function resolveMonthRange(?string $from, ?string $to): array
    {
        $tz = 'Asia/Jakarta';
        $now = now($tz)->startOfMonth();

        $start = $this->tryParseMonth($from, $tz);
        $end = $this->tryParseMonth($to, $tz);

        if (!$start && !$end) {
            $end = $now;
            $start = (clone $now)->subMonth();
        } elseif ($start && !$end) {
            $end = (clone $start);
        } elseif (!$start && $end) {
            $start = (clone $end);
        }

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    private function tryParseMonth(?string $value, string $tz): ?Carbon
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m', $value, $tz)->startOfMonth();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function applyMetrics($rows)
    {
        return collect($rows)->map(function ($item) {
            $from = (float) ($item->total_from ?? 0);
            $to = (float) ($item->total_to ?? 0);
            $item->delta = $to - $from;
            $item->mom_growth = $from > 0 ? (($to - $from) / $from) * 100 : null;
            return $item;
        })->values();
    }

    private function buildTotals($rows, Carbon $startMonth, Carbon $endMonth): array
    {
        $rows = collect($rows);

        $storeCount = $rows->pluck('store_id')->unique()->count();
        $totalFrom = (float) $rows->sum('total_from');
        $totalTo = (float) $rows->sum('total_to');
        $sameMonth = $startMonth->format('Y-m') === $endMonth->format('Y-m');
        $totalSales = $sameMonth ? $totalFrom : ($totalFrom + $totalTo);
        $monthsCount = $startMonth->diffInMonths($endMonth) + 1;

        $avgPerStoreMonth = ($storeCount > 0 && $monthsCount > 0)
            ? ($totalSales / ($storeCount * $monthsCount))
            : 0;

        return [
            'total_sales' => $totalSales,
            'total_from' => $totalFrom,
            'total_to' => $totalTo,
            'avg_store_month' => $avgPerStoreMonth,
            'store_count' => $storeCount,
            'months_count' => $monthsCount,
            'month_from' => $startMonth->format('Y-m'),
            'month_to' => $endMonth->format('Y-m'),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\tb_stores;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Yajra\DataTables\Facades\DataTables;

class CashierMonthlyReportController extends Controller
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

        return view('pages.admin.report.cashier-monthly', [
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

        if (!Schema::hasColumn('tb_outgoing_goods', 'recorded_by')) {
            return DataTables::of(collect())
                ->with(['totals' => $this->emptyTotals($request)])
                ->toJson();
        }

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

        $dateExpr = 'COALESCE(og.date, s.date, og.created_at, s.created_at)';
        $monthExpr = "DATE_FORMAT($dateExpr, '%Y-%m')";
        $cashierKeyExpr = "LOWER(TRIM(og.recorded_by))";
        $targetMonths = array_values(array_unique([$monthFrom, $monthTo]));

        $salesBase = DB::table('tb_sells as s')
            ->join('tb_outgoing_goods as og', 'og.sell_id', '=', 's.id')
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('og.deleted_at')
            )
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->whereBetween(DB::raw($dateExpr), [$startDate->startOfDay(), $endDate->endOfDay()])
            ->whereIn(DB::raw($monthExpr), $targetMonths)
            ->whereNotNull('og.recorded_by')
            ->whereRaw('COALESCE(TRIM(og.recorded_by), "") <> ""')
            ->whereRaw('LOWER(COALESCE(TRIM(og.recorded_by), "")) != ?', ['stock opname']);

        $excludeStockOpname($salesBase);

        $perSale = $salesBase
            ->selectRaw("
                s.id as sell_id,
                $monthExpr as sale_month,
                $cashierKeyExpr as cashier_key,
                MIN(TRIM(og.recorded_by)) as cashier_name,
                s.total_price as total_price
            ")
            ->groupBy(
                's.id',
                DB::raw($monthExpr),
                DB::raw($cashierKeyExpr),
                's.total_price'
            );

        $monthly = DB::query()
            ->fromSub($perSale, 'sales')
            ->selectRaw('
                cashier_key,
                MIN(cashier_name) as cashier_name,
                SUM(CASE WHEN sale_month = ? THEN total_price ELSE 0 END) as total_from,
                SUM(CASE WHEN sale_month = ? THEN total_price ELSE 0 END) as total_to,
                SUM(CASE WHEN sale_month = ? THEN 1 ELSE 0 END) as trx_from,
                SUM(CASE WHEN sale_month = ? THEN 1 ELSE 0 END) as trx_to
            ', [$monthFrom, $monthTo, $monthFrom, $monthTo])
            ->groupBy('cashier_key')
            ->orderBy('cashier_name');

        $rows = $monthly->get();
        $rowsWithMetrics = $this->applyMetrics($rows);
        $totals = $this->buildTotals($rowsWithMetrics, $startMonth, $endMonth);

        return DataTables::of($rowsWithMetrics)
            ->addIndexColumn()
            ->addColumn('action', function ($row) use ($monthFrom, $monthTo, $storeId) {
                $params = [
                    'cashier_key' => $row->cashier_key ?? '',
                    'cashier_name' => $row->cashier_name ?? '',
                    'month_from' => $monthFrom,
                    'month_to' => $monthTo,
                ];
                if ($storeId) {
                    $params['store'] = $storeId;
                }
                $detailUrl = route('report.cashier.monthly.detail') . '?' . http_build_query($params);
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

        $cashierKey = trim(strtolower((string) $request->get('cashier_key')));
        $cashierName = trim((string) $request->get('cashier_name'));

        [$startMonth, $endMonth] = $this->resolveMonthRange(
            $request->get('month_from'),
            $request->get('month_to')
        );

        return view('pages.admin.report.cashier-monthly-detail', [
            'cashierKey' => $cashierKey,
            'cashierName' => $cashierName,
            'monthFrom' => $startMonth->format('Y-m'),
            'monthTo' => $endMonth->format('Y-m'),
            'storeId' => $storeId,
            'currentStoreName' => $currentStoreName,
        ]);
    }

    public function detailData(Request $request)
    {
        $user = $request->user();
        $storeId = store_access_resolve_id($request, $user, ['store']);

        if (!Schema::hasColumn('tb_outgoing_goods', 'recorded_by')) {
            return DataTables::of(collect())->toJson();
        }

        $cashierKey = trim(strtolower((string) $request->get('cashier_key')));
        $monthValue = $request->get('month');
        $month = $this->tryParseMonth($monthValue, 'Asia/Jakarta');

        if ($cashierKey === '' || !$month) {
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

        $dateExpr = 'COALESCE(og.date, s.date, og.created_at, s.created_at)';
        $invoiceSelect = $hasInvoiceColumn ? 's.no_invoice' : 'NULL as no_invoice';

        $query = DB::table('tb_sells as s')
            ->join('tb_outgoing_goods as og', 'og.sell_id', '=', 's.id')
            ->leftJoin('tb_stores as st', 'st.id', '=', 's.store_id')
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('og.deleted_at')
            )
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->whereBetween(DB::raw($dateExpr), [$startDate->startOfDay(), $endDate->endOfDay()])
            ->whereRaw('LOWER(TRIM(og.recorded_by)) = ?', [$cashierKey])
            ->selectRaw("
                s.id as sell_id,
                $invoiceSelect,
                st.store_name as store_name,
                DATE($dateExpr) as sale_date,
                s.total_price as total_price,
                MAX(og.created_at) as last_activity
            ");

        $excludeStockOpname($query);

        $groupBy = [
            's.id',
            'st.store_name',
            DB::raw("DATE($dateExpr)"),
            's.total_price',
        ];
        if ($hasInvoiceColumn) {
            $groupBy[] = 's.no_invoice';
        }
        $query->groupBy($groupBy)->orderByDesc('last_activity');

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

        $cashierCount = $rows->pluck('cashier_key')->unique()->count();
        $totalFrom = (float) $rows->sum('total_from');
        $totalTo = (float) $rows->sum('total_to');
        $sameMonth = $startMonth->format('Y-m') === $endMonth->format('Y-m');
        $totalSales = $sameMonth ? $totalFrom : ($totalFrom + $totalTo);
        $monthsCount = $startMonth->diffInMonths($endMonth) + 1;

        $avgPerCashierMonth = ($cashierCount > 0 && $monthsCount > 0)
            ? ($totalSales / ($cashierCount * $monthsCount))
            : 0;

        return [
            'total_sales' => $totalSales,
            'total_from' => $totalFrom,
            'total_to' => $totalTo,
            'avg_cashier_month' => $avgPerCashierMonth,
            'cashier_count' => $cashierCount,
            'months_count' => $monthsCount,
            'month_from' => $startMonth->format('Y-m'),
            'month_to' => $endMonth->format('Y-m'),
        ];
    }

    private function emptyTotals(Request $request): array
    {
        [$startMonth, $endMonth] = $this->resolveMonthRange(
            $request->get('month_from'),
            $request->get('month_to')
        );

        $monthsCount = $startMonth->diffInMonths($endMonth) + 1;

        return [
            'total_sales' => 0,
            'total_from' => 0,
            'total_to' => 0,
            'avg_cashier_month' => 0,
            'cashier_count' => 0,
            'months_count' => $monthsCount,
            'month_from' => $startMonth->format('Y-m'),
            'month_to' => $endMonth->format('Y-m'),
        ];
    }
}

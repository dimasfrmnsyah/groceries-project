<?php

namespace App\Http\Controllers;

use App\Models\tb_outgoing_goods;
use App\Support\StockLedger;
use App\Models\tb_sell;
use App\Models\tb_stores;
use App\Models\tb_types;
use App\Exports\ArrayExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;

class DailySalesReportController extends Controller
{
    public function index(Request $request)
    {
        $user         = $request->user();
        $isSuperadmin = strtolower((string) ($user?->roles)) === 'superadmin';
        $stores       = store_access_can_select($user)
            ? store_access_list($user)
            : collect();

        $selectedStoreId = store_access_resolve_id($request, $user, ['store']);

        $currentStoreName = $selectedStoreId
            ? tb_stores::where('id', $selectedStoreId)->value('store_name')
            : null;

        $today             = now('Asia/Jakarta')->toDateString();
        $defaultDateFrom   = $this->tryParseDate($request->get('date_from'), 'Asia/Jakarta')?->toDateString() ?? $today;
        $defaultDateTo     = $this->tryParseDate($request->get('date_to'), 'Asia/Jakarta')?->toDateString() ?? $defaultDateFrom;
        $selectedTypeId    = filter_var($request->get('type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $initialCashier    = (string) $request->get('cashier', '');
        $initialSourceMode = in_array($request->get('source_mode'), ['online', 'offline'], true)
            ? $request->get('source_mode')
            : 'all';
        $cashiers          = $this->availableCashiers($selectedStoreId, $defaultDateFrom, $defaultDateTo, $initialSourceMode);
        // Sembunyikan total penjualan untuk role staff/kasir
        $isCashierRole     = in_array(strtolower((string)($user?->roles)), ['kasir','cashier','staff']);

        return view('pages.admin.report.sales-today', [
            'stores'           => $stores,
            'isSuperadmin'     => store_access_can_select($user),
            'selectedStoreId'  => $selectedStoreId,
            'currentStoreName' => $currentStoreName,
            'defaultDateFrom'  => $defaultDateFrom,
            'defaultDateTo'    => $defaultDateTo,
            'cashiers'         => $cashiers,
            'types'             => tb_types::query()->orderBy('type_name')->get(['id', 'type_name']),
            'selectedTypeId'    => $selectedTypeId,
            'initialCashier'    => $initialCashier,
            'initialSourceMode' => $initialSourceMode,
            'hideSalesTotal'    => $isCashierRole,
        ]);
    }

    public function data(Request $request)
    {
        $user         = $request->user();
        $storeId      = store_access_resolve_id($request, $user, ['store']);

        [$startDate, $endDate] = $this->resolveDateRange($request->get('date_from'), $request->get('date_to'));
        $cashier      = $request->get('cashier');
        $typeId       = filter_var($request->get('type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $sourceMode   = $request->get('source_mode');
        $sourceMode   = in_array($sourceMode, ['online', 'offline'], true) ? $sourceMode : 'all';

        $filteredQuery = $this->filteredSalesQuery(
            $storeId,
            $startDate,
            $endDate,
            $cashier,
            $sourceMode,
            $typeId
        );

        if ($typeId) {
            return $this->productTypeData($request, $filteredQuery, $startDate, $endDate, $storeId, $sourceMode);
        }

        $baseQuery = (clone $filteredQuery)
            ->selectRaw('
                p.id as product_id,
                p.product_code,
                p.product_name,
                st.store_name,
                s.store_id,
                tb_outgoing_goods.recorded_by,
                DATE(s.date) as activity_date,
                MAX(COALESCE(tb_outgoing_goods.created_at, s.created_at)) as latest_activity,
                SUM(tb_outgoing_goods.quantity_out) as quantity_out,
                SUM(tb_outgoing_goods.discount) as discount,
                COALESCE(p.selling_price, 0) as unit_price,
                SUM(COALESCE(tb_outgoing_goods.quantity_out,0) * COALESCE(p.selling_price,0) - COALESCE(tb_outgoing_goods.discount,0)) as line_total,
                SUM(COALESCE(tb_outgoing_goods.quantity_out,0) * COALESCE(p.purchase_price,0)) as line_hpp,
                GROUP_CONCAT(DISTINCT s.id ORDER BY s.id DESC) as sell_ids,
                GROUP_CONCAT(DISTINCT s.no_invoice ORDER BY s.no_invoice DESC) as invoices
            ')
            ->groupBy(
                'p.id',
                'p.product_code',
                'p.product_name',
                'st.store_name',
                's.store_id',
                'tb_outgoing_goods.recorded_by',
                DB::raw('DATE(s.date)'),
                'p.selling_price',
                'p.purchase_price'
            );

        $summaryRows = (clone $baseQuery)->get();
        $salesTotalQuery = tb_outgoing_goods::query()
            ->join('tb_sells as s', 's.id', '=', 'tb_outgoing_goods.sell_id')
            ->when(
                Schema::hasColumn('tb_sells', 'deleted_at'),
                fn ($q) => $q->whereNull('s.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('tb_outgoing_goods.deleted_at')
            )
            ->whereBetween('tb_outgoing_goods.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->where(function ($q) {
                $q->whereNull('s.no_invoice')
                  ->orWhere(function ($qq) {
                      $qq->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                         ->where('s.no_invoice', 'not like', 'AR-%')
                         ->where('s.no_invoice', 'not like', 'TRF-%');
                  });
            })
            ->when(Schema::hasColumn('tb_outgoing_goods','recorded_by'),
                fn($q) => $q->whereRaw('LOWER(COALESCE(TRIM(tb_outgoing_goods.recorded_by), "")) != ?', ['stock opname'])
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock') && $sourceMode !== 'all',
                fn ($q) => $q->where('tb_outgoing_goods.is_pending_stock', $sourceMode === 'offline' ? 1 : 0)
            )
            ->whereBetween('s.date', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->when(
                $cashier && Schema::hasColumn('tb_outgoing_goods', 'recorded_by'),
                fn ($q) => $q->where('tb_outgoing_goods.recorded_by', $cashier)
            )
            ->selectRaw('s.id as sell_id, s.total_price as total_price')
            ->groupBy('s.id', 's.total_price');
        $salesTotal = DB::query()
            ->fromSub($salesTotalQuery, 'sales_total')
            ->sum('total_price');

        $totals = [
            'items'    => $summaryRows->count(),
            'quantity' => (float)$summaryRows->sum('quantity_out'),
            'sales'    => (float)$salesTotal,
            'hpp'      => (float)$summaryRows->sum('line_hpp'),
            'discount' => (float)$summaryRows->sum('discount'),
        ];

        $cashiers = $this->availableCashiers($storeId, $startDate->toDateString(), $endDate->toDateString(), $sourceMode);

        // order by grouped/selected columns only to satisfy ONLY_FULL_GROUP_BY
        $dataQuery = (clone $baseQuery)
            ->orderByDesc('latest_activity')
            ->orderBy('tb_outgoing_goods.recorded_by')
            ->orderBy('p.product_name');

        return DataTables::eloquent($dataQuery)
            ->addIndexColumn()
            ->editColumn('store_name', fn ($row) => $row->store_name ?? '-')
            ->addColumn('action', function ($row) {
                $ids = array_filter(array_map('trim', explode(',', $row->sell_ids ?? ''))); // buang kosong
                $invoices = array_map('trim', explode(',', $row->invoices ?? ''));
                if (empty($ids)) {
                    return '<span class="text-muted">-</span>';
                }
                $buttons = '';
                foreach ($ids as $idx => $sid) {
                    if (!$sid) continue;
                    $inv = $invoices[$idx] ?? ('INV-'.$sid);
                    $url = route('sell.detail', $sid);
                    $buttons .= '<a href="'.e($url).'" class="badge bg-primary me-1" target="_blank">'.e($inv).'</a>';
                }
                return '<div class="d-flex flex-wrap gap-1">'.($buttons ?: '<span class="text-muted">-</span>').'</div>';
            })
            ->filter(function ($query) use ($request) {
                $search = $request->input('search.value');
                if ($search) {
                    $like = '%' . $search . '%';
                    $query->where(function ($q) use ($like) {
                        $q->where('s.no_invoice', 'like', $like)
                          ->orWhere('st.store_name', 'like', $like)
                          ->orWhere('tb_outgoing_goods.recorded_by', 'like', $like)
                          ->orWhere('p.product_name', 'like', $like)
                          ->orWhere('p.product_code', 'like', $like);
                    });
                }
            })
            ->rawColumns(['action'])
            ->with([
                'report_mode' => 'detail',
                'totals'      => $totals,
                'cashiers'    => $cashiers,
                'date_range'  => [
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                ],
            ])
            ->toJson();
    }

    public function export(Request $request)
    {
        $user         = $request->user();
        $storeId      = store_access_resolve_id($request, $user, ['store']);

        [$startDate, $endDate] = $this->resolveDateRange($request->get('date_from'), $request->get('date_to'));
        $cashier      = $request->get('cashier');
        $typeId       = filter_var($request->get('type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $sourceMode   = $request->get('source_mode');
        $sourceMode   = in_array($sourceMode, ['online', 'offline'], true) ? $sourceMode : 'all';

        if ($typeId) {
            $filteredQuery = $this->filteredSalesQuery(
                $storeId,
                $startDate,
                $endDate,
                $cashier,
                $sourceMode,
                $typeId
            );

            return $this->exportProductType($filteredQuery, $startDate, $endDate);
        }

        $activityField = 's.date';
        $select = [
            'tb_outgoing_goods.id',
            's.id as sell_id',
            's.no_invoice',
            'st.store_name',
            's.store_id',
            'tb_outgoing_goods.recorded_by',
            'p.product_code',
            'p.product_name',
            'tb_outgoing_goods.quantity_out',
            'tb_outgoing_goods.discount',
            DB::raw('COALESCE(p.selling_price, 0) as unit_price'),
            DB::raw('COALESCE(tb_outgoing_goods.quantity_out,0) * COALESCE(p.selling_price,0) - COALESCE(tb_outgoing_goods.discount,0) as line_total'),
            DB::raw($activityField.' as activity_at'),
        ];
        if (Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock')) {
            $select[] = 'tb_outgoing_goods.is_pending_stock';
        } else {
            $select[] = DB::raw('NULL as is_pending_stock');
        }

        $rows = tb_outgoing_goods::query()
            ->join('tb_sells as s', 's.id', '=', 'tb_outgoing_goods.sell_id')
            ->leftJoin('tb_products as p', 'p.id', '=', 'tb_outgoing_goods.product_id')
            ->leftJoin('tb_stores as st', 'st.id', '=', 's.store_id')
            ->leftJoin('tb_customers as c', 'c.id', '=', 's.customer_id')
            ->when(
                Schema::hasColumn('tb_sells', 'deleted_at'),
                fn ($q) => $q->whereNull('s.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('tb_outgoing_goods.deleted_at')
            )
            ->whereBetween('tb_outgoing_goods.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->when($typeId, fn ($q) => $q->where('p.type_id', $typeId))
            // abaikan penyesuaian stock opname (invoice dibuat otomatis)
            ->where(function ($q) {
                $q->whereNull('s.no_invoice')
                  ->orWhere(function ($qq) {
                      $qq->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                         ->where('s.no_invoice', 'not like', 'AR-%')
                         ->where('s.no_invoice', 'not like', 'TRF-%');
                  });
            })
            // abaikan pencatatan khusus stock opname
            ->when(
                Schema::hasColumn('tb_outgoing_goods','recorded_by'),
                fn($q) => $q->whereRaw('LOWER(COALESCE(TRIM(tb_outgoing_goods.recorded_by), "")) != ?', ['stock opname'])
            )
            // filter mode toko: online (potong stok) vs offline (pending stok opname)
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock') && $sourceMode !== 'all',
                fn ($q) => $q->where('tb_outgoing_goods.is_pending_stock', $sourceMode === 'offline' ? 1 : 0)
            )
            ->whereBetween(
                DB::raw($activityField),
                [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()]
            )
            ->when(
                $cashier && Schema::hasColumn('tb_outgoing_goods', 'recorded_by'),
                fn ($q) => $q->where('tb_outgoing_goods.recorded_by', $cashier)
            )
            ->select($select)
            ->orderBy('activity_at')
            ->orderBy('tb_outgoing_goods.id')
            ->get()
            ->map(function ($row) {
                $date = $row->activity_at ? Carbon::parse($row->activity_at)->format('Y-m-d') : '';
                $invoice = $row->no_invoice ?: ('INV-' . $row->sell_id);
                $mode = $row->is_pending_stock === null
                    ? '-'
                    : ((int)$row->is_pending_stock === 1 ? 'Offline' : 'Online');

                return [
                    $date,
                    $invoice,
                    $row->store_name ?? '-',
                    $row->recorded_by ?? '-',
                    $row->product_code ?? '',
                    $row->product_name ?? '',
                    (int) $row->quantity_out,
                    (float) $row->unit_price,
                    (float) $row->discount,
                    (float) $row->line_total,
                    $mode,
                ];
            })
            ->values()
            ->all();

        $headings = [
            'Tanggal',
            'No Invoice',
            'Toko',
            'Kasir',
            'Kode Produk',
            'Produk',
            'Qty',
            'Harga',
            'Diskon',
            'Subtotal',
            'Mode',
        ];

        $dateLabel = $startDate->format('Ymd') . '-' . $endDate->format('Ymd');
        $filename = 'Sales-Detail-' . $dateLabel . '.xlsx';

        return Excel::download(new ArrayExport($rows, $headings), $filename);
    }

    private function filteredSalesQuery(
        ?int $storeId,
        Carbon $startDate,
        Carbon $endDate,
        ?string $cashier,
        string $sourceMode,
        ?int $typeId = null
    ) {
        return tb_outgoing_goods::query()
            ->join('tb_sells as s', 's.id', '=', 'tb_outgoing_goods.sell_id')
            ->leftJoin('tb_products as p', 'p.id', '=', 'tb_outgoing_goods.product_id')
            ->leftJoin('tb_types as t', 't.id', '=', 'p.type_id')
            ->leftJoin('tb_stores as st', 'st.id', '=', 's.store_id')
            ->leftJoin('tb_customers as c', 'c.id', '=', 's.customer_id')
            ->when(
                Schema::hasColumn('tb_sells', 'deleted_at'),
                fn ($q) => $q->whereNull('s.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('tb_outgoing_goods.deleted_at')
            )
            ->whereBetween('tb_outgoing_goods.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->when($typeId, fn ($q) => $q->where('p.type_id', $typeId))
            ->where(function ($q) {
                $q->whereNull('s.no_invoice')
                    ->orWhere(function ($qq) {
                        $qq->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                            ->where('s.no_invoice', 'not like', 'AR-%')
                            ->where('s.no_invoice', 'not like', 'TRF-%');
                    });
            })
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'recorded_by'),
                fn ($q) => $q->whereRaw('LOWER(COALESCE(TRIM(tb_outgoing_goods.recorded_by), "")) != ?', ['stock opname'])
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock') && $sourceMode !== 'all',
                fn ($q) => $q->where('tb_outgoing_goods.is_pending_stock', $sourceMode === 'offline' ? 1 : 0)
            )
            ->whereBetween('s.date', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->when(
                $cashier && Schema::hasColumn('tb_outgoing_goods', 'recorded_by'),
                fn ($q) => $q->where('tb_outgoing_goods.recorded_by', $cashier)
            );
    }

    private function productTypeData(
        Request $request,
        $filteredQuery,
        Carbon $startDate,
        Carbon $endDate,
        ?int $storeId,
        string $sourceMode
    ) {
        $productQuery = (clone $filteredQuery)
            ->selectRaw('
                p.id as product_id,
                p.product_code,
                p.product_name,
                COALESCE(t.type_name, "-") as type_name,
                SUM(COALESCE(tb_outgoing_goods.quantity_out, 0)) as quantity_out,
                SUM(
                    COALESCE(tb_outgoing_goods.quantity_out, 0) * COALESCE(p.selling_price, 0)
                    - COALESCE(tb_outgoing_goods.discount, 0)
                ) as total_sales,
                COALESCE(p.purchase_price, 0) as unit_hpp,
                SUM(COALESCE(tb_outgoing_goods.quantity_out, 0) * COALESCE(p.purchase_price, 0)) as total_hpp
            ')
            ->groupBy(
                'p.id',
                'p.product_code',
                'p.product_name',
                't.type_name',
                'p.purchase_price'
            )
            ->orderBy('p.product_name');

        $summaryRows = (clone $productQuery)->get();
        $cashiers = $this->availableCashiers(
            $storeId,
            $startDate->toDateString(),
            $endDate->toDateString(),
            $sourceMode
        );

        return DataTables::eloquent($productQuery)
            ->addIndexColumn()
            ->filter(function ($query) use ($request) {
                $search = $request->input('search.value');
                if (!$search) {
                    return;
                }

                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('p.product_code', 'like', $like)
                        ->orWhere('p.product_name', 'like', $like)
                        ->orWhere('t.type_name', 'like', $like);
                });
            })
            ->with([
                'report_mode' => 'type',
                'totals' => [
                    'items' => $summaryRows->count(),
                    'quantity' => (float) $summaryRows->sum('quantity_out'),
                    'hpp' => (float) $summaryRows->sum('total_hpp'),
                    'sales' => (float) $summaryRows->sum('total_sales'),
                    'discount' => 0,
                ],
                'cashiers' => $cashiers,
                'date_range' => [$startDate->toDateString(), $endDate->toDateString()],
            ])
            ->toJson();
    }

    private function exportProductType($filteredQuery, Carbon $startDate, Carbon $endDate)
    {
        $rows = (clone $filteredQuery)
            ->selectRaw('
                p.product_code,
                p.product_name,
                COALESCE(t.type_name, "-") as type_name,
                SUM(COALESCE(tb_outgoing_goods.quantity_out, 0)) as quantity_out,
                COALESCE(p.purchase_price, 0) as unit_hpp,
                SUM(COALESCE(tb_outgoing_goods.quantity_out, 0) * COALESCE(p.purchase_price, 0)) as total_hpp
            ')
            ->groupBy('p.id', 'p.product_code', 'p.product_name', 't.type_name', 'p.purchase_price')
            ->orderBy('p.product_name')
            ->get()
            ->map(fn ($row) => [
                $row->product_code ?? '',
                $row->product_name ?? '',
                $row->type_name ?? '-',
                (int) $row->quantity_out,
                (float) $row->unit_hpp,
                (float) $row->total_hpp,
            ])
            ->values()
            ->all();

        $totalHpp = (float) collect($rows)->sum(fn ($row) => (float) $row[5]);
        $rows[] = ['', '', '', '', 'TOTAL HPP', $totalHpp];

        $headings = [
            'Kode Produk',
            'Nama Produk',
            'Tipe',
            'Qty Terjual',
            'HPP Satuan',
            'Total HPP',
        ];

        $dateLabel = $startDate->format('Ymd') . '-' . $endDate->format('Ymd');
        return Excel::download(
            new ArrayExport($rows, $headings),
            'Sales-Product-Type-' . $dateLabel . '.xlsx'
        );
    }

    private function resolveDateRange(?string $from, ?string $to): array
    {
        $tz   = 'Asia/Jakarta';
        $now  = now($tz);

        $start = $this->tryParseDate($from, $tz)?->startOfDay();
        $end   = $this->tryParseDate($to, $tz)?->endOfDay();

        if (!$start && !$end) {
            $start = $now->copy()->startOfDay();
            $end   = $now->copy()->endOfDay();
        } elseif ($start && !$end) {
            $end = $start->copy()->endOfDay();
        } elseif (!$start && $end) {
            $start = $end->copy()->startOfDay();
        }

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    private function tryParseDate(?string $value, string $tz): ?Carbon
    {
        if (!$value) return null;
        try {
            return Carbon::createFromFormat('Y-m-d', $value, $tz);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function availableCashiers(?int $storeId, string $startDate, string $endDate, string $sourceMode = 'all'): array
    {
        if (!Schema::hasColumn('tb_outgoing_goods', 'recorded_by')) {
            return [];
        }

        $start = Carbon::createFromFormat('Y-m-d', $startDate, 'Asia/Jakarta')->startOfDay();
        $end   = Carbon::createFromFormat('Y-m-d', $endDate, 'Asia/Jakarta')->endOfDay();

        return tb_outgoing_goods::query()
            ->join('tb_sells as s', 's.id', '=', 'tb_outgoing_goods.sell_id')
            ->select('tb_outgoing_goods.recorded_by')
            ->when(
                Schema::hasColumn('tb_sells', 'deleted_at'),
                fn ($q) => $q->whereNull('s.deleted_at')
            )
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->whereRaw('LOWER(COALESCE(TRIM(tb_outgoing_goods.recorded_by), "")) != ?', ['stock opname'])
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock') && $sourceMode !== 'all',
                fn ($q) => $q->where('tb_outgoing_goods.is_pending_stock', $sourceMode === 'offline' ? 1 : 0)
            )
            ->whereBetween('tb_outgoing_goods.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
            ->whereNotNull('recorded_by')
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('s.date', [$start, $end]);
            })
            ->groupBy('recorded_by')
            ->orderBy('recorded_by')
            ->pluck('recorded_by')
            ->filter()
            ->values()
            ->all();
    }
}

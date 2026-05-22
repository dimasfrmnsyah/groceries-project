<?php

namespace App\Http\Controllers;

use App\Models\tb_daily_revenues;
use App\Models\tb_outgoing_goods;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $isSuperadmin = strtolower((string) ($user?->roles)) === 'superadmin';

        $stores = store_access_can_select($user)
            ? store_access_list($user)
            : collect();
        $selectedStoreId = store_access_resolve_id($request, $user, ['store']);

        // untuk badge tampilan
        $currentStoreName = null;
        if ($selectedStoreId) {
            $currentStoreName = \App\Models\tb_stores::where('id',$selectedStoreId)->value('store_name');
        }

        return view('pages.admin.report.index', [
            'stores'          => $stores,
            'selectedStoreId' => $selectedStoreId,
            'currentStoreName'=> $currentStoreName,
        ]);
    }

 public function indexData(Request $request)
{
    $user         = auth()->user();
    $isSuperadmin = strtolower((string) ($user?->roles)) === 'superadmin';
    $storeId      = store_access_resolve_id($request, $user, ['store']);

    if ($isSuperadmin && empty($storeId)) {
        return \Yajra\DataTables\Facades\DataTables::of(collect())
            ->with(['totals' => ['amount' => 0, 'status' => 0]])
            ->toJson();
    }
    if (!$storeId) {
        return \Yajra\DataTables\Facades\DataTables::of(collect())
            ->with(['totals' => ['amount' => 0, 'status' => 0]])
            ->toJson();
    }

    // --- Range tanggal (default: hari ini) ---
    $dateFrom = $request->query('date_from');
    $dateTo   = $request->query('date_to');

    if (!$dateFrom && !$dateTo) {
        $start = \Carbon\Carbon::today('Asia/Jakarta')->startOfDay();
        $end   = \Carbon\Carbon::today('Asia/Jakarta')->endOfDay();
    } else {
        $start = \Carbon\Carbon::parse($dateFrom ?: $dateTo, 'Asia/Jakarta')->startOfDay();
        $end   = \Carbon\Carbon::parse($dateTo   ?: $dateFrom, 'Asia/Jakarta')->endOfDay();
        if ($start->gt($end)) { [$start, $end] = [$end, $start]; }
    }
    $startStr = $start->toDateString(); // YYYY-MM-DD
    $endStr   = $end->toDateString();

    $revenueRows = \App\Models\tb_daily_revenues::with('user:id,name,store_id')
        ->select(['id', 'user_id', 'amount', 'date', 'created_at'])
        ->whereHas('user', fn($q) => $q->where('store_id', $storeId))
        ->whereDate('date', '>=', $startStr)
        ->whereDate('date', '<=', $endStr)
        ->orderBy('date')
        ->orderBy('created_at')
        ->get();

    $hasSellerIdColumn = schemaHasColumn('tb_sells', 'seller_id');
    $hasInvoiceColumn  = schemaHasColumn('tb_sells', 'no_invoice');

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

    // Ambil transaksi per nota (pakai total_price agar konsisten dengan halaman home)
    $salesRawQuery = DB::table('tb_sells as s')
        ->join('tb_outgoing_goods as og', 'og.sell_id', '=', 's.id')
        ->when(
            schemaHasColumn('tb_sells', 'deleted_at'),
            fn ($q) => $q->whereNull('s.deleted_at')
        )
        ->when(
            schemaHasColumn('tb_outgoing_goods', 'deleted_at'),
            fn ($q) => $q->whereNull('og.deleted_at')
        )
        ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
        ->whereBetween('s.date', [$start, $end])
        ->selectRaw('s.id, s.date, s.total_price, s.created_at, MAX(og.created_at) as og_created_at, MAX(og.recorded_by) as recorded_by')
        ->groupBy('s.id', 's.date', 's.total_price', 's.created_at');

    $excludeStockOpname($salesRawQuery);
    $salesRaw = $salesRawQuery->get();

    // Kelompokkan transaksi per kasir+tanggal memakai total invoice kasir.
    $salesByCashierDate = [];
    $cashierNamesByKey = [];
    foreach ($salesRaw as $sale) {
        $dateKey = $sale->date
            ? \Carbon\Carbon::parse($sale->date)->toDateString()
            : \Carbon\Carbon::parse($sale->created_at)->toDateString();
        $norm  = $this->normalizeName($sale->recorded_by);
        $total = (float) $sale->total_price;
        $key = $dateKey.'|'.$norm;
        $salesByCashierDate[$key] = ($salesByCashierDate[$key] ?? 0) + $total;
        $cashierNamesByKey[$key] = trim((string) $sale->recorded_by);
    }

    $rows = [];
    $matchedSalesKeys = [];
    $revenueGroups = $revenueRows->groupBy(function ($row) {
        $dateKey = $row->date instanceof \Carbon\Carbon
            ? $row->date->toDateString()
            : \Carbon\Carbon::parse($row->date)->toDateString();

        return $dateKey.'|'.$this->normalizeName(optional($row->user)->name);
    });

    foreach ($revenueGroups as $key => $group) {
        $first = $group->first();
        $dateKey = $first->date instanceof \Carbon\Carbon
            ? $first->date->toDateString()
            : \Carbon\Carbon::parse($first->date)->toDateString();
        $amount = (float) $group->sum('amount');
        $omset = (float) ($salesByCashierDate[$key] ?? 0);
        if (array_key_exists($key, $salesByCashierDate)) {
            $matchedSalesKeys[$key] = true;
        }

        $rows[] = [
            'id' => $first->id,
            'name' => optional($first->user)->name ?? '-',
            'amount' => $amount,
            'omset' => $omset,
            'date' => $dateKey,
            'status' => $amount - $omset,
            'action' => '<div class="d-flex justify-content-center">
                <a href="'.route('report.detail', $first->id).'" class="btn btn-sm btn-success me-1">
                    Detail Penjualan <i class="bx bx-right-arrow-alt"></i>
                </a>
            </div>',
        ];
    }

    foreach ($salesByCashierDate as $key => $omset) {
        if (isset($matchedSalesKeys[$key])) {
            continue;
        }

        [$dateKey] = explode('|', $key, 2);
        $cashierName = $cashierNamesByKey[$key] ?: 'Belum input setoran';
        $rows[] = [
            'id' => null,
            'name' => $cashierName.' (belum input setoran)',
            'amount' => 0,
            'omset' => (float) $omset,
            'date' => $dateKey,
            'status' => 0 - (float) $omset,
            'action' => '<span class="text-muted">Belum ada setoran</span>',
        ];
    }

    $totalAmount = (float) collect($rows)->sum('amount');
    $totalOmset = (float) collect($rows)->sum('omset');
    $totalStatus = $totalAmount - $totalOmset;

    return DataTables::of(collect($rows))
        ->addIndexColumn()
        ->rawColumns(['action'])
        ->with(['totals' => [
            'amount' => $totalAmount,
            'omset'  => $totalOmset,
            'status' => $totalStatus,
        ]])
        ->toJson();
}

  public function detail(Request $request, $id)
{
    // SELECT list dinamis
    $select = ['id','user_id','amount','date'];
    if (schemaHasColumn('daily_revenues','store_id')) {
        $select[] = 'store_id';
    }

    $revenue = tb_daily_revenues::with('user:id,name')
        ->select($select)
        ->findOrFail($id);

    return view('pages.admin.report.detail', [
        'revenue' => $revenue,
        'cashier' => optional($revenue->user)->name ?? '-',
    ]);
}

public function detailData(Request $request, $id)
{
    $select = ['id','user_id','amount','date'];
    if (schemaHasColumn('daily_revenues','store_id')) {
        $select[] = 'store_id';
    }

    $revenue = tb_daily_revenues::with('user:id,name,store_id')
        ->select($select)
        ->findOrFail($id);

    $filterDate  = \Carbon\Carbon::parse($revenue->date)->toDateString();
    $cashierName = trim(optional($revenue->user)->name ?? '');
    $storeId     = schemaHasColumn('daily_revenues','store_id') ? $revenue->store_id : optional($revenue->user)->store_id;

    $query = tb_outgoing_goods::query()
        ->select('id','uuid','product_id','sell_id','date','quantity_out','discount','recorded_by','description','created_at')
        ->whereDate('date', $filterDate)
        ->when($cashierName !== '', fn($q) => $q->whereRaw('LOWER(TRIM(recorded_by)) = ?', [strtolower($cashierName)]))
        ->when(schemaHasColumn('tb_outgoing_goods','store_id') && $storeId, fn($q) => $q->where('store_id', $storeId));

    if (method_exists(\App\Models\tb_outgoing_goods::class, 'product')) {
        $query->with('product:id,product_name as name,selling_price as price');
    }

    return DataTables::eloquent($query)
        ->addIndexColumn()
        ->addColumn('product_name', fn($row) => optional($row->product)->name ?? $row->product_id)
        ->addColumn('price', fn($row) => (float) (optional($row->product)->price ?? 0))
        ->addColumn('subtotal', function ($row) {
            $price = (float) (optional($row->product)->price ?? 0);
            $qty   = (int)   ($row->quantity_out ?? 0);
            $disc  = (float) ($row->discount ?? 0);
            return max(0, ($price * $qty) - $disc);
        })
        ->toJson();
}
private function normalizeName(?string $name): string
{
    $n = mb_strtolower(trim((string) $name), 'UTF-8');
    return str_replace([' ', '.', '-', '_'], '', $n);
}
}


/**
 * Helper kecil untuk cek kolom ada (hindari error kalau struktur beda)
 */
if (! function_exists('schemaHasColumn')) {
    function schemaHasColumn(string $table, string $column): bool {
        try {
            return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

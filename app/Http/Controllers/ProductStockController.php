<?php

namespace App\Http\Controllers;

use App\Models\tb_stores;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\Facades\DataTables;
use App\Exports\ProductStockExport;
use App\Support\StockLedger;

class ProductStockController extends Controller
{
    public function index(Request $request)
    {
        $user         = $request->user();
        $isSuperadmin = strtolower((string) ($user?->roles)) === 'superadmin';
        $stores       = store_access_can_select($user)
            ? store_access_list($user)
            : collect();

        $selectedStoreId = store_access_resolve_id($request, $user, ['store']);
        $currentStore    = $selectedStoreId
            ? tb_stores::where('id', $selectedStoreId)->value('store_name')
            : null;

        return view('pages.admin.master.stock-overview', [
            'isSuperadmin'    => store_access_can_select($user),
            'stores'          => $stores,
            'selectedStoreId' => $selectedStoreId,
            'currentStore'    => $currentStore,
        ]);
    }

    public function data(Request $request)
    {
        $user         = $request->user();
        $storeId      = store_access_resolve_id($request, $user, ['store']);

        if (!$storeId) {
            return DataTables::of(collect())->toJson();
        }

        $base = $this->stockBaseQuery($storeId);

        return DataTables::of($base)
            ->addIndexColumn()
            ->filter(function ($query) use ($request) {
                $search = $request->input('search.value');
                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $like = '%' . $search . '%';
                        $q->where('p.product_name', 'like', $like)
                          ->orWhere('p.product_code', 'like', $like);
                    });
                }
            })
            ->toJson();
    }

    public function export(Request $request)
    {
        $user         = $request->user();
        $storeId      = store_access_resolve_id($request, $user, ['store']);

        if (!$storeId) {
            return back()->with('error', 'Pilih toko terlebih dahulu.');
        }

        $query = $this->stockBaseQuery($storeId);

        $search = (string) ($request->input('search') ?? $request->input('search.value') ?? '');
        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('p.product_name', 'like', $like)
                  ->orWhere('p.product_code', 'like', $like);
            });
        }

        $items = $query->get();
        $exportRows = $items->map(function ($row) {
            return [
                'Kode'        => $row->product_code,
                'Produk'      => $row->product_name,
                'Stok sistem' => (int) $row->stock_system,
            ];
        });

        $storeName = DB::table('tb_stores')->where('id', $storeId)->value('store_name');
        $safeStore = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $storeName);
        $safeStore = trim($safeStore, '-');
        $safeStore = $safeStore !== '' ? $safeStore : 'Toko';
        $filename = 'Stock-' . $safeStore . '-' . now('Asia/Jakarta')->format('Y-m-d-H.i') . '.xlsx';
        return Excel::download(new ProductStockExport($exportRows), $filename);
    }

    private function stockBaseQuery(int $storeId)
    {
        $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'deleted_at'),
                fn($q) => $q->whereNull('ig.deleted_at')
            )
            ->when(
                $hasIncomingStore,
                fn($q) => $q->where(function ($qq) use ($storeId) {
                    $qq->where('ig.store_id', $storeId)
                       ->orWhereExists(function ($ex) use ($storeId) {
                           $ex->select(DB::raw(1))
                              ->from('tb_purchases as pur')
                              ->whereColumn('pur.id', 'ig.purchase_id')
                              ->where('pur.store_id', $storeId);
                       });
                }),
                fn($q) => $q->join('tb_purchases as pur', 'ig.purchase_id', '=', 'pur.id')
                           ->where('pur.store_id', $storeId)
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
            ->whereBetween('ig.stock', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
            ->select('ig.product_id', DB::raw('SUM(ig.stock) AS total_in'))
            ->groupBy('ig.product_id');

        $outgoingSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as sl', 'og.sell_id', '=', 'sl.id')
            ->where('sl.store_id', $storeId)
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn($q) => $q->whereNull('og.deleted_at')
            )
            ->whereBetween('og.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY]);
        StockLedger::applyOutgoingBalanceFilter(
            $outgoingSub,
            Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'),
            'og',
            'sl'
        );
        $outgoingSub
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) AS total_out'))
            ->groupBy('og.product_id');

        return DB::table('tb_products as p')
            ->where('p.is_active', 1)
            ->leftJoinSub($incomingSub, 'incoming', fn($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) as stock_system')
            )
            ->where(DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0))'), '>', 0)
            ->orderBy('p.product_name');
    }
}

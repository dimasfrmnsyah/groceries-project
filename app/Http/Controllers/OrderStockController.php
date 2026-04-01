<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Exports\OrderStockExport;

class OrderStockController extends Controller
{
    public function summary(Request $request)
    {
        $user = $request->user();
        $role = strtolower((string) ($user?->roles ?? ''));
        $requestedStoreId = (int) $request->input('store_id', $request->input('store', 0));

        if ($role === 'superadmin' && $requestedStoreId <= 0) {
            $payload = Cache::remember('order_stock_summary:all', now()->addSeconds(60), function () {
                return $this->buildAllStoresSummaryPayload();
            });

            return response()->json($payload);
        }

        $storeId = $role === 'superadmin'
            ? ($requestedStoreId > 0 ? $requestedStoreId : null)
            : store_access_resolve_id($request, $user, ['store_id', 'store']);

        if (!$storeId) {
            return response()->json([
                'success' => true,
                'count' => 0,
                'groups' => [],
            ]);
        }

        $payload = Cache::remember('order_stock_summary:store:'.$storeId, now()->addSeconds(60), function () use ($storeId) {
            return $this->buildStoreSummaryPayload($storeId);
        });

        return response()->json($payload);
    }

    public function index(Request $request)
    {
        $user         = $request->user();
        $isSuperadmin = strtolower((string) ($user?->roles)) === 'superadmin';
        $storeId      = store_access_resolve_id($request, $user, ['store']);
        $canSelectStore = store_access_can_select($user);

        $stores = $canSelectStore
            ? store_access_list($user)
            : collect();

        if ($isSuperadmin && !$storeId) {
            return view('pages.admin.order-stock.index', [
                'stores'       => $stores,
                'selected'     => null,
                'items'        => collect(),
                'isSuperadmin' => $canSelectStore,
                'currentStore' => null,
            ]);
        }

        if (!$storeId) {
            return redirect()->back()->with('warning', 'Pilih toko terlebih dahulu.');
        }

        $items = $this->lowStockQuery($storeId)->get()->map(function ($row) {
            $row->po_qty = max(0, ((int)$row->max_stock) - ((int)$row->stock_system));
            return $row;
        });
        $currentStore = DB::table('tb_stores')->where('id', $storeId)->value('store_name');

        return view('pages.admin.order-stock.index', [
            'stores'       => $stores,
            'selected'     => $storeId,
            'items'        => $items,
            'isSuperadmin' => $canSelectStore,
            'currentStore' => $currentStore,
        ]);
    }

    public function restock(Request $request)
    {
        $user         = $request->user();
        $storeId      = store_access_resolve_id($request, $user, ['store_id']);
        if (!$storeId) return back()->with('error', 'Pilih toko terlebih dahulu.');

        $items = array_filter($request->input('items', []), fn ($v) => $v !== null && $v !== '');
        if (empty($items)) return back()->with('warning', 'Tidak ada produk yang dipilih.');

        $poInput = $request->input('po_qty', []);

        $products = $this->lowStockQuery($storeId)
            ->whereIn('p.id', $items)
            ->whereNotNull('st.max_stock')
            ->get()
            ->map(function ($row) use ($poInput) {
                $defaultPo   = max(0, ((int)$row->max_stock) - ((int)$row->stock_system));
                $inputCustom = (int)($poInput[$row->id] ?? $defaultPo);
                $row->po_qty = max(0, $inputCustom);
                return $row;
            });

        $restockRows   = [];
        $totalPurchase = 0;
        $now           = now();
        $storeOnline   = (int) DB::table('tb_stores')->where('id', $storeId)->value('is_online') === 1;
        $isPendingStock = $storeOnline ? 0 : 1;

        foreach ($products as $row) {
            $needed = (int)$row->po_qty;
            if ($needed <= 0) continue;

            $restockRows[] = [
                'product_id' => (int)$row->id,
                'qty'        => $needed,
                'price'      => (float)$row->purchase_price,
            ];
            $totalPurchase += $needed * (float)$row->purchase_price;
        }

        if (empty($restockRows)) {
            return back()->with('warning', 'Semua stok sudah maksimal.');
        }

        DB::beginTransaction();
        try {
            $purchaseId = DB::table('tb_purchases')->insertGetId([
                'supplier_id' => null,
                'store_id'    => $storeId,
                'total_price' => 0,
                'created_by'  => $user?->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            $rows = [];
            foreach ($restockRows as $row) {
                $payload = [
                    'purchase_id'  => $purchaseId,
                    'product_id'   => $row['product_id'],
                    'stock'        => $row['qty'],
                    'description'  => 'Restock hingga stok maksimum',
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
                if (Schema::hasColumn('tb_incoming_goods', 'store_id')) {
                    $payload['store_id'] = $storeId;
                }
                if (Schema::hasColumn('tb_incoming_goods', 'is_pending_stock')) {
                    $payload['is_pending_stock'] = $isPendingStock;
                }
                $rows[] = $payload;
            }

            DB::table('tb_incoming_goods')->insert($rows);
            DB::table('tb_purchases')->where('id', $purchaseId)->update([
                'total_price' => $totalPurchase,
                'updated_at'  => $now,
            ]);

            DB::commit();
            return back()->with('success', 'Stok berhasil diatur ke nilai maksimum.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        $user         = $request->user();
        $storeId      = store_access_resolve_id($request, $user, ['store']);
        if (!$storeId) return back()->with('error', 'Pilih toko terlebih dahulu.');

        $items = $this->lowStockQuery($storeId)->get()->map(function ($row) {
            $row->po_qty = max(0, ((int)$row->max_stock) - ((int)$row->stock_system));
            return $row;
        });

        $exportRows = $items->map(function ($row) {
            return [
                'Kode'   => $row->product_code,
                'Produk' => $row->product_name,
                'PO'     => $row->po_qty,
            ];
        });

        $filename = 'order-stock-store-'.$storeId.'.xlsx';
        return Excel::download(new OrderStockExport($exportRows), $filename);
    }

    private function buildStoreSummaryPayload(int $storeId): array
    {
        $storeName = DB::table('tb_stores')->where('id', $storeId)->value('store_name') ?? 'Toko';

        $items = $this->lowStockQuery($storeId)
            ->get()
            ->map(function ($row) use ($storeId, $storeName) {
                return [
                    'id' => (int) $row->id,
                    'product_code' => $row->product_code,
                    'product_name' => $row->product_name,
                    'store_id' => $storeId,
                    'store_name' => $storeName,
                    'min_stock' => $row->min_stock,
                    'max_stock' => $row->max_stock,
                    'stock_system' => $row->stock_system,
                    'po_qty' => max(0, ((int) $row->max_stock) - ((int) $row->stock_system)),
                ];
            })
            ->values();

        return [
            'success' => true,
            'count' => $items->count(),
            'groups' => [[
                'store_id' => $storeId,
                'store_name' => $storeName,
                'items' => $items->all(),
            ]],
        ];
    }

    private function buildAllStoresSummaryPayload(): array
    {
        $storeIds = DB::table('tb_product_store_thresholds')
            ->select('store_id')
            ->distinct()
            ->pluck('store_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $groups = collect();

        foreach ($storeIds as $storeId) {
            $storeName = DB::table('tb_stores')->where('id', $storeId)->value('store_name') ?? 'Toko';
            $items = $this->lowStockQuery($storeId)
                ->get()
                ->map(function ($row) use ($storeId, $storeName) {
                    return [
                        'id' => (int) $row->id,
                        'product_code' => $row->product_code,
                        'product_name' => $row->product_name,
                        'store_id' => $storeId,
                        'store_name' => $storeName,
                        'min_stock' => $row->min_stock,
                        'max_stock' => $row->max_stock,
                        'stock_system' => $row->stock_system,
                        'po_qty' => max(0, ((int) $row->max_stock) - ((int) $row->stock_system)),
                    ];
                })
                ->values();

            if ($items->isEmpty()) {
                continue;
            }

            $groups->push([
                'store_id' => $storeId,
                'store_name' => $storeName,
                'items' => $items->all(),
            ]);
        }

        return [
            'success' => true,
            'count' => $groups->sum(fn ($group) => count($group['items'] ?? [])),
            'groups' => $groups->values()->all(),
        ];
    }

    private function lowStockQuery(int $storeId)
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
            ->join('tb_product_store_thresholds as st', function ($join) use ($storeId) {
                $join->on('st.product_id', '=', 'p.id')
                     ->where('st.store_id', '=', $storeId);
            })
            ->leftJoin('tb_product_store_prices as psp', function ($join) use ($storeId) {
                $join->on('psp.product_id', '=', 'p.id')
                     ->where('psp.store_id', '=', $storeId);
            })
            ->leftJoinSub($incomingSub, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn ($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
            'st.min_stock',
            'st.max_stock',
            DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) as stock_system'),
            DB::raw('COALESCE(psp.purchase_price, p.purchase_price) as purchase_price')
        )
        ->whereNotNull('st.min_stock')
        ->whereRaw('COALESCE(st.min_stock,0) > 0')
        ->whereRaw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) <= COALESCE(st.min_stock, 0)')
        ->orderBy('p.product_name');
    }
}

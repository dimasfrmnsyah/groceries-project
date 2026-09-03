<?php

namespace App\Http\Controllers;

use App\Models\tb_sell;
use App\Models\tb_products;
use App\Models\tb_outgoing_goods;
use App\Models\tb_stores;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\Facades\DataTables;

class TbSellController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $role = strtolower((string) ($user->roles ?? ''));
        $storeId = $request->filled('store_id') ? (int) $request->input('store_id') : null;
        $saleStatus = $request->input('status', 'online');
        if (!in_array($saleStatus, ['all', 'online', 'offline'], true)) {
            $saleStatus = 'online';
        }

        $hasPendingStock = Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock');
        $hasOutgoingDeleted = Schema::hasColumn('tb_outgoing_goods', 'deleted_at');
        $pendingExists = function ($query) use ($hasPendingStock, $hasOutgoingDeleted) {
            if (!$hasPendingStock) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->from('tb_outgoing_goods as pending_og')
                ->whereColumn('pending_og.sell_id', 'tb_sells.id')
                ->where('pending_og.is_pending_stock', 1)
                ->when($hasOutgoingDeleted, fn ($q) => $q->whereNull('pending_og.deleted_at'));
        };

        $query = tb_sell::query()
            ->with('store')
            ->select('tb_sells.*')
            // Adjustment stock opname tetap tersimpan sebagai ledger, tetapi
            // tidak boleh tampil sebagai penjualan kasir biasa.
            ->where(function ($q) {
                $q->whereNull('no_invoice')
                    ->orWhere('no_invoice', 'not like', 'SO-ADJ-%');
            })
            ->orderByDesc('id');

        // Penjualan offline sudah memiliki header dan invoice untuk audit, tetapi
        // baru dianggap masuk daftar penjualan online setelah movement stoknya
        // dilepas. Data lama tanpa flag pending tetap dianggap online.
        if ($saleStatus === 'online') {
            $query->whereNotExists($pendingExists);
        } elseif ($saleStatus === 'offline') {
            $query->whereExists($pendingExists);
        }

        if ($hasPendingStock) {
            $query->addSelect([
                'sale_pending' => DB::table('tb_outgoing_goods as pending_status')
                    ->selectRaw('1')
                    ->whereColumn('pending_status.sell_id', 'tb_sells.id')
                    ->where('pending_status.is_pending_stock', 1)
                    ->when($hasOutgoingDeleted, fn ($q) => $q->whereNull('pending_status.deleted_at'))
                    ->limit(1),
            ]);
        }
        if ($role !== 'superadmin') {
            $allowed = store_access_ids($user);
            $query->when(!empty($allowed), fn ($q) => $q->whereIn('store_id', $allowed))
                ->when(empty($allowed), fn ($q) => $q->whereRaw('1 = 0'));

            if ($storeId && in_array($storeId, $allowed, true)) {
                $query->where('store_id', $storeId);
            }
        } elseif ($storeId) {
            $query->where('store_id', $storeId);
        }

        if ($request->ajax()) {
            return DataTables::eloquent($query)
                ->filterColumn('store.store_name', function ($query, $keyword) {
                    $query->whereHas('store', function ($q) use ($keyword) {
                        $q->where('store_name', 'like', '%' . $keyword . '%');
                    });
                })
                ->orderColumn('store.store_name', function ($query, $order) {
                    $query->leftJoin('tb_stores as stores', 'tb_sells.store_id', '=', 'stores.id')
                        ->orderBy('stores.store_name', $order);
                })
                ->addColumn('action', function ($sells) {
                    return '
                <div class="d-flex justify-content-center">
                    <a href="/sell/detail/' . $sells->id . '" class="btn btn-sm btn-primary me-1">
                       Detail <i class="bx bx-right-arrow-alt"></i>
                    </a>
                </div>';
                })
                ->addColumn('status', function ($sells) {
                    return (int) ($sells->sale_pending ?? 0) === 1
                        ? '<span class="badge bg-warning text-dark">Offline / Pending</span>'
                        : '<span class="badge bg-success">Online</span>';
                })
                ->rawColumns(['action', 'status'])
                ->make(true);
        }

        $stores = store_access_list($user);
        $canSelectStore = store_access_can_select($user) || in_array($role, ['superadmin', 'admin'], true);
        $selectedStoreId = $storeId;

        return view('pages.admin.sell.index', compact(
            'stores',
            'canSelectStore',
            'selectedStoreId',
            'saleStatus'
        ));
    }

    public function detail($id)
    {
        $user = auth()->user();
        $role = strtolower((string) ($user->roles ?? ''));
        $allowed = store_access_ids($user);

        $sell = tb_sell::with(['store', 'creator:id,name'])
            ->when($role !== 'superadmin', function ($query) use ($allowed) {
                if (!empty($allowed)) {
                    $query->whereIn('store_id', $allowed);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->findOrFail($id);

        $outgoingGoods = \App\Models\tb_outgoing_goods::with('product')
            ->where('sell_id', $sell->id)
            ->get();

        $isPending = $outgoingGoods->contains(function ($movement) {
            return (int) ($movement->is_pending_stock ?? 0) === 1;
        });

        [$products, $priceData] = $this->loadProductsAndPrices((int) $sell->store_id);

        return view('pages.admin.sell.detail-readonly', compact('sell', 'outgoingGoods', 'isPending'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(tb_sell $tb_sell)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return $this->editById($id);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        return $this->updateById($request, $id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(tb_sell $tb_sell)
    {
        //
    }

    public function editById($id)
    {
        abort(403, 'Invoice yang sudah dibayar tidak dapat diedit. Gunakan proses reversal dengan persetujuan supervisor.');
    }

    public function updateById(Request $request, $id)
    {
        abort(403, 'Invoice yang sudah dibayar tidak dapat diubah. Gunakan proses reversal dengan persetujuan supervisor.');
    }

    private function resolveSellingPrice(tb_products $product, int $storeId, int $qty): float
    {
        $pricing = $product->priceForStore($storeId);
        $base = (float) ($pricing['selling_price'] ?? 0);
        $productDiscount = (float) ($pricing['product_discount'] ?? 0);
        $unitPrice = $base - $productDiscount;

        $tiers = $pricing['tier_prices'] ?? null;
        if (is_array($tiers) && !empty($tiers)) {
            $tiers = collect($tiers)
                ->mapWithKeys(fn ($price, $minQty) => [(int) $minQty => (float) $price])
                ->sortKeys();
            foreach ($tiers as $minQty => $tierPrice) {
                if ($qty >= $minQty) {
                    $unitPrice = (float) $tierPrice;
                }
            }
        }

        return $unitPrice;
    }

    private function loadProductsAndPrices(int $storeId): array
    {
        $products = tb_products::with('storePrices')
            ->orderBy('product_name')
            ->get();

        $priceData = $products->mapWithKeys(function ($product) use ($storeId) {
            $override = $product->storePrices->firstWhere('store_id', $storeId);
            return [
                $product->id => [
                    'base' => (float) ($override->selling_price ?? $product->selling_price ?? 0),
                    'discount' => (float) ($override->product_discount ?? $product->product_discount ?? 0),
                    'tiers' => $override->tier_prices ?? $product->tier_prices ?? [],
                ],
            ];
        })->toArray();

        return [$products, $priceData];
    }
}

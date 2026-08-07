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
        $query = tb_sell::with('store')
            ->orderByDesc('id');
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
                        ->orderBy('stores.store_name', $order)
                        ->select('tb_sells.*');
                })
                ->addColumn('action', function ($sells) {
                    return '
                <div class="d-flex justify-content-center">
                    <a href="/sell/detail/' . $sells->id . '" class="btn btn-sm btn-primary me-1">
                       Detail <i class="bx bx-right-arrow-alt"></i>
                    </a>
                </div>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        $stores = store_access_list($user);
        $canSelectStore = store_access_can_select($user) || in_array($role, ['superadmin', 'admin'], true);
        $selectedStoreId = $storeId;

        return view('pages.admin.sell.index', compact('stores', 'canSelectStore', 'selectedStoreId'));
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

        [$products, $priceData] = $this->loadProductsAndPrices((int) $sell->store_id);

        return view('pages.admin.sell.detail-readonly', compact('sell', 'outgoingGoods'));
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

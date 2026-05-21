<?php

namespace App\Http\Controllers;

use App\Models\tb_sell;
use App\Models\tb_products;
use App\Models\tb_outgoing_goods;
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
                       Detail / Edit <i class="bx bx-right-arrow-alt"></i> 
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

        $sell = tb_sell::with('store')
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

        return view('pages.admin.sell.detail', compact('sell', 'outgoingGoods', 'products', 'priceData'));
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
        $user = auth()->user();
        $role = strtolower((string) ($user->roles ?? ''));
        $allowed = store_access_ids($user);

        $sell = tb_sell::with(['store', 'outgoing_goods.product'])
            ->when($role !== 'superadmin', function ($query) use ($allowed) {
                if (!empty($allowed)) {
                    $query->whereIn('store_id', $allowed);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->findOrFail($id);

        $outgoingGoods = $sell->outgoing_goods;
        [$products, $priceData] = $this->loadProductsAndPrices((int) $sell->store_id);

        return view('pages.admin.sell.edit', compact('sell', 'outgoingGoods', 'products', 'priceData'));
    }

    public function updateById(Request $request, $id)
    {
        $user = auth()->user();
        $role = strtolower((string) ($user->roles ?? ''));
        $allowed = store_access_ids($user);

        $sell = tb_sell::with(['outgoing_goods.product'])
            ->when($role !== 'superadmin', function ($query) use ($allowed) {
                if (!empty($allowed)) {
                    $query->whereIn('store_id', $allowed);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->findOrFail($id);

        $existingItems = $request->input('items_existing', []);
        $newItems = $request->input('items_new', []);

        if (empty($existingItems) && empty($newItems)) {
            DB::beginTransaction();
            try {
                foreach ($sell->outgoing_goods as $outgoing) {
                    $outgoing->delete();
                }
                $sell->delete();
                DB::commit();
                return redirect()->route('sell.index')
                    ->with('success', 'Invoice dihapus karena semua item dihapus.');
            } catch (\Exception $e) {
                DB::rollBack();
                return redirect()->back()->with('error', $e->getMessage())->withInput();
            }
        }

        $rules = [
            'date' => 'required|date',
            'payment_amount' => 'nullable|numeric|min:0',
        ];
        if (!empty($existingItems)) {
            $rules['items_existing'] = 'array';
            $rules['items_existing.*.qty'] = 'required|integer|min:1';
        }
        if (!empty($newItems)) {
            $rules['items_new'] = 'array';
            $rules['items_new.*.product_id'] = 'required|integer|exists:tb_products,id';
            $rules['items_new.*.qty'] = 'required|integer|min:1';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        try {
            $sell->date = $request->input('date');
            $sell->save();

            $storeId = (int) $sell->store_id;
            $hasOutgoingStore = Schema::hasColumn('tb_outgoing_goods', 'store_id');
            $hasPendingStock = Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock');
            $totalPrice = 0;
            $existingMap = is_array($existingItems) ? $existingItems : [];
            foreach ($sell->outgoing_goods as $outgoing) {
                if (!array_key_exists($outgoing->id, $existingMap)) {
                    $outgoing->delete();
                    continue;
                }

                $qty = (int) ($existingMap[$outgoing->id]['qty'] ?? 0);
                $outgoing->quantity_out = $qty;
                $outgoing->date = $sell->date;
                if ($hasPendingStock) {
                    $outgoing->is_pending_stock = 0;
                }
                if ($hasOutgoingStore) {
                    $outgoing->store_id = $storeId;
                }
                $outgoing->save();

                $product = $outgoing->product;
                if ($product) {
                    $unitPrice = $this->resolveSellingPrice($product, $storeId, $qty);
                    $discount = (float) ($outgoing->discount ?? 0);
                    $totalPrice += ($unitPrice * $qty) - $discount;
                }
            }

            $newItemsArray = is_array($newItems) ? $newItems : [];
            foreach ($newItemsArray as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $qty = (int) ($item['qty'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                $product = tb_products::find($productId);
                if (!$product) {
                    throw new \Exception('Produk tidak ditemukan.');
                }

                $payload = [
                    'product_id' => $productId,
                    'sell_id' => $sell->id,
                    'date' => $sell->date,
                    'quantity_out' => $qty,
                    'discount' => 0,
                    'recorded_by' => $user->name,
                    'description' => $item['description'] ?? null,
                ];
                if ($hasPendingStock) {
                    $payload['is_pending_stock'] = 0;
                }
                if ($hasOutgoingStore) {
                    $payload['store_id'] = $storeId;
                }
                tb_outgoing_goods::create($payload);

                $unitPrice = $this->resolveSellingPrice($product, $storeId, $qty);
                $totalPrice += ($unitPrice * $qty);
            }

            $sell->total_price = $totalPrice;
            $paymentInput = $request->input('payment_amount');
            if ($paymentInput === null || $paymentInput === '') {
                $sell->payment_amount = $totalPrice;
            } else {
                $sell->payment_amount = (float) $paymentInput;
            }
            $sell->save();

            DB::commit();
            return redirect()->route('sell.detail', $sell->id)->with('success', 'Penjualan berhasil diperbarui.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }
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

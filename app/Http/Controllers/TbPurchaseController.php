<?php

namespace App\Http\Controllers;

use App\Models\tb_purchase;
use App\Models\tb_suppliers;
use App\Models\tb_products;
use App\Models\tb_incoming_goods;
use App\Models\tb_stores;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Yajra\DataTables\Facades\DataTables;

class TbPurchaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $isSuperadmin = strtolower((string) ($user?->roles)) === 'superadmin';
        $purchases = tb_purchase::with(relations: ['supplier','store','creator:id,name'])
            ->when(!$isSuperadmin, function ($query) use ($user) {
                $allowed = store_access_ids($user);
                if (!empty($allowed)) {
                    $query->whereIn('store_id', $allowed);
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('id')
            ->get();


        if ($request->ajax()) {
            return DataTables::of($purchases)
            ->addColumn('supplier_name', function ($purchase) {
                $supplier = $purchase->supplier;
                if (!$supplier) {
                    return '-';
                }
                return ($supplier->code ?? null) === 'SO-ADJ' ? '-' : $supplier->name;
            })
            ->addColumn('store_name', function ($purchase) {
                return $purchase->store?->store_name ?? '-';
            })
            ->addColumn('creator_name', function ($purchase) {
                return $purchase->creator?->name ?? '-';
            })
            ->addColumn('action', function ($purchases) {
                return '
                <div class="d-flex justify-content-center">
                    <a href="/purchase/edit/'.$purchases->id.'" class="btn btn-sm btn-success me-1">
                       Edit <i class="bx bx-right-arrow-alt"></i> 
                    </a>
                </div>';
            })
            ->rawColumns(['action'])
            ->make(true);
        }
        return view('pages.admin.purchase.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $suppliers = tb_suppliers::query()
            ->where('code', '!=', 'SO-ADJ')
            ->orderBy('name')
            ->get();
        $products = tb_products::all();
        $stores = store_access_list(auth()->user()); 
    
     
    
        return view('pages.admin.purchase.create', compact('suppliers', 'products', 'stores'));
    }
    
    public function store(Request $request)
{
    $user = auth()->user();
    $storeId = store_access_resolve_id($request, $user, ['store_id']);
    if (!$storeId) {
        return back()->with('error', 'Store wajib dipilih.');
    }

    $validated = $request->validate([
        'supplier_id' => 'required|integer|exists:tb_suppliers,id',
        'products' => 'required|array|min:1',
        'products.*.product_id' => 'required|integer|exists:tb_products,id',
        'products.*.stock' => 'required|integer|min:1',
        'products.*.description' => 'nullable|string',
    ]);

    $productPrices = tb_products::whereIn('id', collect($validated['products'])->pluck('product_id')->all())
        ->pluck('purchase_price', 'id');
    $totalPrice = collect($validated['products'])->sum(function ($product) use ($productPrices) {
        return ((float) ($productPrices[$product['product_id']] ?? 0)) * ((int) $product['stock']);
    });

    DB::beginTransaction();
    try {
        // Simpan ke tb_purchase
        $purchase = tb_purchase::create([
            'supplier_id' => $validated['supplier_id'],
            'store_id' => $storeId,
            'total_price' => $totalPrice,
            'created_by' => auth()->id(),
        ]);
        $storeOnline = (int) tb_stores::where('id', $storeId)->value('is_online') === 1;
        $isPendingStock = $storeOnline ? 0 : 1;
        $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
        $hasPendingStock = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock');
        
        // Simpan produk ke tb_incoming_goods
        foreach ($validated['products'] as $product) {
            $payload = [
                'purchase_id' => $purchase->id, // ✅ Perbaikan: tambahkan purchase_id
                'product_id' => $product['product_id'],
                'stock' => $product['stock'],
                'description' => $product['description'] ?? null, // Jika null, tetap bisa disimpan
            ];
            if ($hasPendingStock) {
                $payload['is_pending_stock'] = $isPendingStock;
            }
            if ($hasIncomingStore) {
                $payload['store_id'] = $storeId;
            }
            tb_incoming_goods::create($payload);
        }

        DB::commit();
        return redirect()->route('purchase.index')->with('success', 'Data pembelian berhasil disimpan!');
    } catch (\Exception $e) {
        DB::rollBack();
        return back()->with('error', 'Terjadi kesalahan saat menyimpan data: ' . $e->getMessage());
    }
}

    
    
    

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $purchase = tb_purchase::with(['incomingGoods.product', 'supplier'])->findOrFail($id);

        $suppliers = tb_suppliers::query()
            ->where('code', '!=', 'SO-ADJ')
            ->orderBy('name')
            ->get();
        $stores = store_access_list(auth()->user());
        $products = tb_products::all();
    
        return view('pages.admin.purchase.edit', compact('purchase', 'suppliers', 'stores', 'products'));
    }
    

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $storeId = store_access_resolve_id($request, $user, ['store_id']);
        if (!$storeId) {
            return back()->with('error', 'Store wajib dipilih.');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:tb_suppliers,id',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:tb_products,id',
            'products.*.stock' => 'required|integer|min:1',
            'products.*.description' => 'nullable|string',
        ]);

        $productPrices = tb_products::whereIn('id', collect($validated['products'])->pluck('product_id')->all())
            ->pluck('purchase_price', 'id');
        $totalPrice = collect($validated['products'])->sum(function ($product) use ($productPrices) {
            return ((float) ($productPrices[$product['product_id']] ?? 0)) * ((int) $product['stock']);
        });

        DB::beginTransaction();
        try {
            // Ambil data pembelian yang ingin diperbarui
            $purchase = tb_purchase::findOrFail($id);
    
            // Update data pembelian
            $purchase->update([
                'supplier_id' => $validated['supplier_id'],
                'store_id' => $storeId,
                'total_price' => $totalPrice,
            ]);

            $storeOnline = (int) tb_stores::where('id', $storeId)->value('is_online') === 1;
            $isPendingStock = $storeOnline ? 0 : 1;
            $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
            $hasPendingStock = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock');
    
            // Hapus semua produk lama sebelum menyimpan yang baru
            tb_incoming_goods::where('purchase_id', $id)->delete();
    
            // Simpan produk baru yang diinputkan user
            foreach ($validated['products'] as $product) {
                $payload = [
                    'purchase_id' => $id,
                    'product_id' => $product['product_id'],
                    'stock' => $product['stock'],
                    'description' => $product['description'] ?? null,
                ];
                if ($hasPendingStock) {
                    $payload['is_pending_stock'] = $isPendingStock;
                }
                if ($hasIncomingStore) {
                    $payload['store_id'] = $storeId;
                }
                tb_incoming_goods::create($payload);
            }
    
            DB::commit();
            return redirect()->route('purchase.index')->with('success', 'Pembelian berhasil diperbarui!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }
    }
    
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            tb_purchase::where('id', $id)->delete();
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil dihapus',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghapus data',
            ]);
        }
    }
}

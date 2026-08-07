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
                return '<span class="text-muted">Immutable</span>';
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
        $this->authorizePurchaseManager();
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
    $this->authorizePurchaseManager();
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
        'supplier_budget' => 'nullable|numeric|min:0',
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
        $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
        $hasPendingStock = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock');
        tb_stores::where('id', $storeId)->lockForUpdate()->firstOrFail();
        
        // Simpan produk ke tb_incoming_goods
        foreach ($validated['products'] as $product) {
            $payload = [
                'purchase_id' => $purchase->id, // ✅ Perbaikan: tambahkan purchase_id
                'product_id' => $product['product_id'],
                'stock' => $product['stock'],
                'description' => $product['description'] ?? null, // Jika null, tetap bisa disimpan
                'created_by' => $user->id,
                'source_type' => 'purchase',
            ];
            if ($hasPendingStock) {
                $payload['is_pending_stock'] = 0;
            }
            if ($hasIncomingStore) {
                $payload['store_id'] = $storeId;
            }
            tb_incoming_goods::create($payload);
        }

        $supplierBudget = (float) ($validated['supplier_budget'] ?? 0);
        if ($supplierBudget > 0 && $totalPrice > $supplierBudget && Schema::hasTable('tb_supplier_debts')) {
            $debtPayload = [
                'date' => now('Asia/Jakarta')->toDateString(),
                'supplier_id' => $validated['supplier_id'],
                'purchase_id' => $purchase->id,
                'budget_amount' => $supplierBudget,
                'purchase_amount' => $totalPrice,
                'debt_amount' => $totalPrice - $supplierBudget,
                'paid_amount' => 0,
                'status' => 'open',
                'description' => 'Otomatis dari pembelian',
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('tb_supplier_debts', 'store_id')) {
                $debtPayload['store_id'] = $storeId;
            }
            DB::table('tb_supplier_debts')->insert($debtPayload);
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
        abort(403, 'Pembelian yang sudah tersimpan tidak dapat diedit. Buat movement koreksi melalui stock opname.');
    }
    

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        abort(403, 'Pembelian yang sudah tersimpan tidak dapat diubah. Buat movement koreksi melalui stock opname.');
    }
    
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        abort(403, 'Pembelian tidak dapat dihapus karena merupakan bagian dari ledger stok.');
    }

    private function authorizePurchaseManager(): void
    {
        $role = strtolower((string) (auth()->user()?->roles ?? ''));
        abort_unless(
            in_array($role, ['superadmin', 'admin'], true),
            403,
            'Hanya admin atau superadmin yang boleh membuat pembelian.'
        );
    }
}

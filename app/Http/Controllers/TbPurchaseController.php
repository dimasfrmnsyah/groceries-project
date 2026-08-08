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
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
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
            ->addColumn('action', function ($purchase) {
                return '<a href="'.route('purchase.edit', $purchase->id).'" class="btn btn-sm btn-warning me-1">Edit</a>'
                    .'<form action="'.route('purchase.destroy', $purchase->id).'" method="POST" class="d-inline" onsubmit="return confirm(\'Hapus pembelian ini?\')">'
                    .csrf_field().method_field('DELETE')
                    .'<button type="submit" class="btn btn-sm btn-danger">Hapus</button></form>';
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
        'idempotency_key' => 'nullable|string|max:64',
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

    $idempotencyKey = $validated['idempotency_key'] ?? (string) Str::uuid();
    if (tb_purchase::where('idempotency_key', $idempotencyKey)->exists()) {
        return redirect()->route('purchase.index')->with('success', 'Pembelian sudah tersimpan sebelumnya.');
    }

    DB::beginTransaction();
    try {
        tb_stores::where('id', $storeId)->lockForUpdate()->firstOrFail();
        // Simpan ke tb_purchase
        $purchase = tb_purchase::create([
            'supplier_id' => $validated['supplier_id'],
            'store_id' => $storeId,
            'total_price' => $totalPrice,
            'created_by' => auth()->id(),
            'idempotency_key' => $idempotencyKey,
        ]);
        $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
        $hasPendingStock = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock');
        
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
    } catch (QueryException $e) {
        DB::rollBack();
        if (str_contains(strtolower($e->getMessage()), 'idempotency_key')) {
            return redirect()->route('purchase.index')->with('success', 'Pembelian sudah tersimpan sebelumnya.');
        }
        return back()->with('error', 'Terjadi kesalahan saat menyimpan data.');
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
        $this->authorizePurchaseManager();
        $purchase = tb_purchase::with(['incomingGoods.product', 'supplier', 'store'])->findOrFail($id);
        abort_unless(in_array((int) $purchase->store_id, store_access_ids(auth()->user()), true), 403);
        $suppliers = tb_suppliers::where('code', '!=', 'SO-ADJ')->orderBy('name')->get();
        $products = tb_products::all();
        $stores = store_access_list(auth()->user());
        return view('pages.admin.purchase.edit', compact('purchase', 'suppliers', 'products', 'stores'));
    }
    

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $this->authorizePurchaseManager();
        $purchase = tb_purchase::findOrFail($id);
        abort_unless(in_array((int) $purchase->store_id, store_access_ids(auth()->user()), true), 403);

        $validated = $request->validate([
            'supplier_id' => 'required|integer|exists:tb_suppliers,id',
            'store_id' => 'required|integer|in:'.$purchase->store_id,
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|integer|exists:tb_products,id',
            'products.*.stock' => 'required|integer|min:1',
            'products.*.description' => 'nullable|string',
        ]);
        $productPrices = tb_products::whereIn('id', collect($validated['products'])->pluck('product_id')->all())
            ->pluck('purchase_price', 'id');
        $totalPrice = collect($validated['products'])->sum(fn ($p) =>
            (float) ($productPrices[$p['product_id']] ?? 0) * (int) $p['stock']);

        DB::beginTransaction();
        try {
            $purchase = tb_purchase::with('incomingGoods')->lockForUpdate()->findOrFail($id);
            tb_stores::where('id', $purchase->store_id)->lockForUpdate()->firstOrFail();
            $oldIds = $purchase->incomingGoods->pluck('id')->all();

            $checkedProductIds = collect($validated['products'])->pluck('product_id')
                ->merge($purchase->incomingGoods->pluck('product_id'))
                ->unique();
            foreach ($checkedProductIds as $productId) {
                $incoming = DB::table('tb_incoming_goods as ig')
                    ->join('tb_purchases as p', 'p.id', '=', 'ig.purchase_id')
                    ->where('p.store_id', $purchase->store_id)
                    ->where('ig.product_id', $productId)
                    ->whereNull('ig.deleted_at')
                    ->whereNotIn('ig.id', $oldIds)
                    ->sum('ig.stock');
                $outgoing = DB::table('tb_outgoing_goods as og')
                    ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
                    ->where('s.store_id', $purchase->store_id)
                    ->where('og.product_id', $productId)
                    ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
                    ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'), fn ($q) => $q->where('og.is_pending_stock', 0))
                    ->sum('og.quantity_out');
                $newStock = collect($validated['products'])->where('product_id', $productId)->sum('stock');
                if ((int) $incoming - (int) $outgoing + (int) $newStock < 0) {
                    throw new \RuntimeException('Produk tidak dapat dikurangi karena sebagian stoknya sudah terjual.');
                }
            }

            $purchase->update(['supplier_id' => $validated['supplier_id'], 'total_price' => $totalPrice]);
            $purchase->incomingGoods()->delete();
            foreach ($validated['products'] as $product) {
                $payload = [
                    'purchase_id' => $purchase->id,
                    'product_id' => $product['product_id'],
                    'stock' => $product['stock'],
                    'description' => $product['description'] ?? null,
                    'created_by' => auth()->id(),
                    'source_type' => 'purchase_edit',
                ];
                if (Schema::hasColumn('tb_incoming_goods', 'is_pending_stock')) {
                    $payload['is_pending_stock'] = 0;
                }
                if (Schema::hasColumn('tb_incoming_goods', 'store_id')) {
                    $payload['store_id'] = $purchase->store_id;
                }
                tb_incoming_goods::create($payload);
            }
            if (Schema::hasTable('tb_supplier_debts')) {
                $debt = DB::table('tb_supplier_debts')->where('purchase_id', $purchase->id)->first();
                if ($debt && (float) $debt->paid_amount > 0) {
                    throw new \RuntimeException('Pembelian tidak dapat diubah karena hutang supplier sudah memiliki pembayaran.');
                }
                if ($debt) {
                    $budget = (float) $debt->budget_amount;
                    DB::table('tb_supplier_debts')->where('id', $debt->id)->update([
                        'supplier_id' => $validated['supplier_id'],
                        'purchase_amount' => $totalPrice,
                        'debt_amount' => max(0, $totalPrice - $budget),
                        'updated_at' => now(),
                    ]);
                }
            }
            DB::commit();
            return redirect()->route('purchase.index')->with('success', 'Pembelian berhasil diperbarui.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->withInput()->with('error', $e->getMessage());
        }
    }
    
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $this->authorizePurchaseManager();
        $purchase = tb_purchase::findOrFail($id);
        abort_unless(in_array((int) $purchase->store_id, store_access_ids(auth()->user()), true), 403);

        DB::beginTransaction();
        try {
            $purchase = tb_purchase::with('incomingGoods')->lockForUpdate()->findOrFail($id);
            tb_stores::where('id', $purchase->store_id)->lockForUpdate()->firstOrFail();
            $oldIds = $purchase->incomingGoods->pluck('id')->all();

            if (Schema::hasTable('tb_supplier_debts')) {
                $debt = DB::table('tb_supplier_debts')->where('purchase_id', $purchase->id)->lockForUpdate()->first();
                if ($debt && (float) $debt->paid_amount > 0) {
                    throw new \RuntimeException('Pembelian tidak dapat dihapus karena hutang supplier sudah memiliki pembayaran.');
                }
            }

            foreach ($purchase->incomingGoods->pluck('product_id')->unique() as $productId) {
                $incoming = DB::table('tb_incoming_goods as ig')
                    ->join('tb_purchases as p', 'p.id', '=', 'ig.purchase_id')
                    ->where('p.store_id', $purchase->store_id)
                    ->where('ig.product_id', $productId)
                    ->whereNull('ig.deleted_at')
                    ->whereNotIn('ig.id', $oldIds)
                    ->sum('ig.stock');
                $outgoing = DB::table('tb_outgoing_goods as og')
                    ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
                    ->where('s.store_id', $purchase->store_id)
                    ->where('og.product_id', $productId)
                    ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
                    ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'), fn ($q) => $q->where('og.is_pending_stock', 0))
                    ->sum('og.quantity_out');
                if ((int) $incoming - (int) $outgoing < 0) {
                    throw new \RuntimeException('Pembelian tidak dapat dihapus karena stoknya sudah dipakai penjualan.');
                }
            }

            $purchase->incomingGoods()->delete();
            $purchase->delete();
            if (isset($debt) && $debt) {
                DB::table('tb_supplier_debts')->where('id', $debt->id)->delete();
            }
            DB::commit();
            return redirect()->route('purchase.index')->with('success', 'Pembelian berhasil dihapus.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->route('purchase.index')->with('error', $e->getMessage());
        }
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

<?php

namespace App\Http\Controllers;

use App\Models\tb_products;
use App\Models\tb_types;
use App\Models\tb_brands;
use App\Models\tb_units;
use App\Models\tb_stores;
use App\Models\tb_product_store_price;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
class TbProductsController extends Controller
{
    public function index(Request $request)
{
    if ($request->ajax()) {
        $rows = DB::table('tb_products as p')
            ->leftJoin('tb_types  as t', 't.id',  '=', 'p.type_id')
            ->leftJoin('tb_brands as b', 'b.id',  '=', 'p.brand_id')
            ->leftJoin('tb_units  as u', 'u.id',  '=', 'p.unit_id')
            ->selectRaw('
                p.id,
                p.product_code,
                p.product_name,
                COALESCE(t.type_name,  "-")  as type_name,
                COALESCE(b.brand_name, "-")  as brand_name,
                COALESCE(u.unit_name, "-")  as unit_name,
                p.purchase_price,
                p.selling_price,
                p.tier_prices,
                p.is_active,
                COALESCE(p.description, "-") as description
            ')
            ->orderByDesc('p.id')
            ->get();

        // format standard DataTables (simple) — tanpa paging server-side
        return response()->json([
            'data' => $rows,
        ]);
    }

    return view('pages.admin.master.manage_product.index');
}

    public function toggleStatus(Request $request, $id)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $product = tb_products::findOrFail($id);
        $product->update([
            'is_active' => (int) $data['is_active'],
        ]);

        return response()->json([
            'message' => 'Status produk berhasil diperbarui.',
            'is_active' => (bool) $product->fresh()->is_active,
        ]);
    }

    public function create()
    {
        return view('pages.admin.master.manage_product.create', [
            'types'  => tb_types::all(),
            'brands' => tb_brands::all(),
            'units'  => tb_units::all(),
            'stores' => tb_stores::all(),
        ]);
    }

  public function store(Request $request)
{
    // 1) Bersihkan baris tier kosong (qty & price dua-duanya kosong/null)
    $tiers = collect($request->input('tier_prices', []))
        ->filter(function ($row) {
            return ($row['qty'] ?? '') !== '' || ($row['price'] ?? '') !== '';
        })
        ->values()
        ->all();

    // merge kembali hasil bersih
    $request->merge(['tier_prices' => $tiers]);

    // 2) Validasi — sekarang aman karena kalau kosong, array-nya [] (tidak memicu nested rules)
    $data = $request->validate([
        'product_code'     => 'required|string|max:255|unique:tb_products,product_code',
        'product_name'     => 'required|string|max:255',
        'type_id'          => 'required|exists:tb_types,id',
        'brand_id'         => 'required|exists:tb_brands,id',
        'unit_id'          => 'required|exists:tb_units,id',
        'purchase_price'   => 'required|numeric|min:0',
        'selling_price'    => 'required|numeric|min:0',
        'product_discount' => 'nullable|numeric|min:0',
        'description'      => 'nullable|string',
        'is_active'        => 'nullable|boolean',
        'tier_prices'          => 'nullable|array',
        'tier_prices.*.qty'    => 'required|integer|min:1',
        'tier_prices.*.price'  => 'required|numeric|min:0',
        'store_prices'                 => 'nullable|array',
        'store_prices.*.store_id'      => 'nullable|integer|exists:tb_stores,id',
        'store_prices.*.purchase_price'=> 'nullable|numeric|min:0',
        'store_prices.*.selling_price' => 'nullable|numeric|min:0',
        'store_prices.*.product_discount' => 'nullable|numeric|min:0',
    ]);

    // 3) Normalisasi ke map qty=>price kalau ada isinya
    if (!empty($data['tier_prices'])) {
        $map = [];
        foreach ($data['tier_prices'] as $row) {
            $map[(int)$row['qty']] = (float)$row['price'];
        }
        ksort($map);
        $data['tier_prices'] = $map;
    }

    $data['is_active'] = (int) ($data['is_active'] ?? 1);

    DB::beginTransaction();
    try {
        $product = tb_products::create($data);
        $this->syncStorePrices($product->id, $request->input('store_prices', []));
        DB::commit();
        return redirect()->route('master-product.index')->with('success', 'Produk berhasil ditambahkan');
    } catch (\Throwable $e) {
        DB::rollBack();
        return back()->withInput()->with('error', $e->getMessage());
    }
}


    public function edit($id)
    {
        return view('pages.admin.master.manage_product.create', [
            'product' => tb_products::with('storePrices')->findOrFail($id),
            'types'   => tb_types::all(),
            'brands'  => tb_brands::all(),
            'units'   => tb_units::all(),
            'stores'  => tb_stores::all(),
        ]);
    }

   public function update(Request $request, $id)
{
    $tiers = collect($request->input('tier_prices', []))
        ->filter(function ($row) {
            return ($row['qty'] ?? '') !== '' || ($row['price'] ?? '') !== '';
        })
        ->values()
        ->all();

    $request->merge(['tier_prices' => $tiers]);

    $data = $request->validate([
        'product_code'     => 'required|string|max:255|unique:tb_products,product_code,'.$id,
        'product_name'     => 'required|string|max:255',
        'type_id'          => 'required|exists:tb_types,id',
        'brand_id'         => 'required|exists:tb_brands,id',
        'unit_id'          => 'required|exists:tb_units,id',
        'purchase_price'   => 'required|numeric|min:0',
        'selling_price'    => 'required|numeric|min:0',
        'product_discount' => 'nullable|numeric|min:0',
        'description'      => 'nullable|string',
        'is_active'        => 'nullable|boolean',
        'tier_prices'          => 'nullable|array',
        'tier_prices.*.qty'    => 'required|integer|min:1',
        'tier_prices.*.price'  => 'required|numeric|min:0',
        'store_prices'                 => 'nullable|array',
        'store_prices.*.store_id'      => 'nullable|integer|exists:tb_stores,id',
        'store_prices.*.purchase_price'=> 'nullable|numeric|min:0',
        'store_prices.*.selling_price' => 'nullable|numeric|min:0',
        'store_prices.*.product_discount' => 'nullable|numeric|min:0',
    ]);

    if (!empty($data['tier_prices'])) {
        $map = [];
        foreach ($data['tier_prices'] as $row) {
            $map[(int)$row['qty']] = (float)$row['price'];
        }
        ksort($map);
        $data['tier_prices'] = $map;
    } else {
        $data['tier_prices'] = null; // benar-benar kosong
    }

    $data['is_active'] = (int) ($data['is_active'] ?? 1);

    DB::beginTransaction();
    try {
        tb_products::findOrFail($id)->update($data);
        $this->syncStorePrices($id, $request->input('store_prices', []));
        DB::commit();
        return redirect()->route('master-product.index')->with('success', 'Produk berhasil diperbarui');
    } catch (\Throwable $e) {
        DB::rollBack();
        return back()->withInput()->with('error', $e->getMessage());
    }
}

    public function show($id)
    {
        $product = tb_products::find($id);
        abort_if(!$product, 404);
        return view('pages.admin.master.manage_product.preview', compact('product'));
    }

    public function destroy($id)
    {
        try {
            $product = tb_products::findOrFail($id);

            DB::beginTransaction();
            // hapus override harga toko (cascade juga sudah di FK, tapi eksplisit agar pasti)
            \App\Models\tb_product_store_price::where('product_id', $product->id)->delete();
            // hapus detail incoming/outgoing yang refer ke produk? (opsional) -> dibiarkan jika ada constraint

            $product->delete();
            DB::commit();

            return response()->json(['message' => 'Produk dihapus']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls'
        ]);

        $file = $request->file('file');
        $data = Excel::toArray([], $file)[0];
        array_shift($data);

        $importedProducts = [];
        foreach ($data as $row) {
            $typeName = trim($row[2] ?? '');
            $brandName = trim($row[3] ?? '');
            $unitName = trim($row[4] ?? '');

            $type = $typeName ? tb_types::firstOrCreate(
                ['type_name' => $typeName],
                ['description' => $typeName]
            ) : null;

            $brand = $brandName ? tb_brands::firstOrCreate(
                ['brand_name' => $brandName],
                ['description' => $brandName]
            ) : null;

            $unit = $unitName ? tb_units::firstOrCreate(
                ['unit_name' => $unitName],
                ['description' => $unitName]
            ) : null;

            $purchasePrice = is_numeric($row[5] ?? null) ? (float) $row[5] : 0;
            $sellingPrice = is_numeric($row[6] ?? null) ? (float) $row[6] : 0;
            $importedProducts[] = [
                'product_code' => $row[0] ?? '',
                'product_name' => $row[1] ?? '',
                'type_id' => $type->id ?? null,
                'brand_id' => $brand->id ?? null,
                'unit_id' => $unit->id ?? null,
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'description' => $row[7] ?? ''
            ];
        }

        session(['imported_products' => $importedProducts]);
        session()->save();
        return view('pages.admin.master.manage_product.preview', data: compact('importedProducts'));
    }



    public function saveImported()
    {
        $importedProducts = session('imported_products', []);
        $failedImports = [];

        foreach ($importedProducts as $product) {
            try {
                tb_products::create([
                    'product_code' => $product['product_code'],
                    'product_name' => $product['product_name'],
                    'type_id' => $product['type_id'],
                    'brand_id' => $product['brand_id'],
                    'unit_id' => $product['unit_id'],
                    'purchase_price' => $product['purchase_price'] ?? 0,
                    'selling_price' => $product['selling_price'] ?? 0,
                    'description' => $product['description'] ?? '',
                ]);
            } catch (\Exception $e) {

                $failedImports[] = $product;
            }
        }

        session()->forget('imported_products');


        if (!empty($failedImports)) {
            session(['failed_imports' => $failedImports]);
            return redirect()->route('master-product.index')->with('warning', 'Beberapa produk gagal diimpor.');
        }

        return redirect()->route('master-product.index')->with('success', 'Produk berhasil diimpor!');
    }

    /**
     * Simpan override harga per toko, hapus yang tidak dikirim.
     */
    private function syncStorePrices(int $productId, array $rows): void
    {
        $rows = array_filter($rows ?? [], function ($row) {
            return !empty($row['store_id']);
        });

        $base = DB::table('tb_products')
            ->where('id', $productId)
            ->select('purchase_price', 'selling_price', 'product_discount')
            ->first();

        $keepIds = [];
        foreach ($rows as $row) {
            $storeId = (int)($row['store_id'] ?? 0);
            if ($storeId <= 0) continue;

            $existing = DB::table('tb_product_store_prices')
                ->where('product_id', $productId)
                ->where('store_id', $storeId)
                ->first();

            $purchaseInput = $row['purchase_price'] ?? null;
            $sellingInput  = $row['selling_price'] ?? null;
            $discountInput = $row['product_discount'] ?? null;
            $minInput      = $row['min_stock'] ?? null;
            $maxInput      = $row['max_stock'] ?? null;

            $hasPrice    = ($purchaseInput !== null && $purchaseInput !== '');
            $hasSelling  = ($sellingInput !== null && $sellingInput !== '');
            $hasDiscount = ($discountInput !== null && $discountInput !== '');
            $hasMin      = ($minInput !== null && $minInput !== '');
            $hasMax      = ($maxInput !== null && $maxInput !== '');

            if (!($hasPrice || $hasSelling || $hasDiscount || $hasMin || $hasMax)) {
                continue;
            }

            $minStock = $hasMin ? (int)$minInput : null;
            $maxStock = $hasMax ? (int)$maxInput : null;
            if ($minStock !== null && $maxStock !== null && $maxStock < $minStock) {
                $maxStock = $minStock;
            }

            // if only min/max provided and record exists, update min/max only
            if ($existing && !$hasPrice && !$hasSelling && !$hasDiscount) {
                DB::table('tb_product_store_prices')
                    ->where('id', $existing->id)
                    ->update([
                        'min_stock'  => $minStock,
                        'max_stock'  => $maxStock,
                        'updated_at' => now(),
                    ]);
                $keepIds[] = $existing->id;
                continue;
            }

            $purchasePrice = $hasPrice
                ? (float)$purchaseInput
                : ($existing->purchase_price ?? $base->purchase_price ?? 0);
            $sellingPrice = $hasSelling
                ? (float)$sellingInput
                : ($existing->selling_price ?? $base->selling_price ?? 0);
            $productDiscount = $hasDiscount
                ? (float)$discountInput
                : ($existing->product_discount ?? $base->product_discount ?? null);

            $payload = [
                'purchase_price'   => $purchasePrice,
                'selling_price'    => $sellingPrice,
                'product_discount' => $productDiscount,
                'min_stock'        => $minStock,
                'max_stock'        => $maxStock,
            ];

            $record = tb_product_store_price::updateOrCreate(
                ['product_id' => $productId, 'store_id' => $storeId],
                $payload
            );
            $keepIds[] = $record->id;
        }

        if (!empty($keepIds)) {
            tb_product_store_price::where('product_id', $productId)
                ->whereNotIn('id', $keepIds)
                ->delete();
        } else {
            tb_product_store_price::where('product_id', $productId)->delete();
        }
    }
}

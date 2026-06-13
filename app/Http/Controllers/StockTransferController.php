<?php

namespace App\Http\Controllers;

use App\Models\tb_incoming_goods;
use App\Models\tb_outgoing_goods;
use App\Models\tb_products;
use App\Models\tb_purchase;
use App\Models\tb_sell;
use App\Models\tb_stores;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $stores = store_access_list($user);
        $products = tb_products::orderBy('product_name')->get();
        $transfers = DB::table('tb_stock_transfers as tr')
            ->join('tb_products as p', 'p.id', '=', 'tr.product_id')
            ->join('tb_stores as fs', 'fs.id', '=', 'tr.from_store_id')
            ->join('tb_stores as ts', 'ts.id', '=', 'tr.to_store_id')
            ->leftJoin('users as u', 'u.id', '=', 'tr.created_by')
            ->select('tr.*', 'p.product_code', 'p.product_name', 'fs.store_name as from_store', 'ts.store_name as to_store', 'u.name as creator_name')
            ->orderByDesc('tr.id')
            ->limit(100)
            ->get();

        return view('pages.admin.stock-transfer.index', compact('stores', 'products', 'transfers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'product_id' => 'required|integer|exists:tb_products,id',
            'from_store_id' => 'required|integer|different:to_store_id|exists:tb_stores,id',
            'to_store_id' => 'required|integer|exists:tb_stores,id',
            'quantity' => 'required|integer|min:1',
            'description' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $allowedStores = store_access_ids($user);
        if (!empty($allowedStores) && (!in_array((int) $data['from_store_id'], $allowedStores, true) || !in_array((int) $data['to_store_id'], $allowedStores, true))) {
            return back()->with('error', 'Toko tidak ada dalam akses user.');
        }

        $stock = $this->currentStock((int) $data['from_store_id'], (int) $data['product_id']);
        if ($stock < (int) $data['quantity']) {
            return back()->with('error', 'Stok toko asal tidak cukup. Stok tersedia: '.$stock);
        }

        DB::transaction(function () use ($data, $user) {
            $product = tb_products::findOrFail($data['product_id']);
            $date = $data['date'];
            $qty = (int) $data['quantity'];

            $sell = tb_sell::create([
                'no_invoice' => 'TRF-OUT-'.now('Asia/Jakarta')->format('YmdHis'),
                'store_id' => $data['from_store_id'],
                'date' => $date,
                'total_price' => 0,
                'payment_amount' => 0,
                'customer_id' => 0,
            ]);

            $outPayload = [
                'product_id' => $product->id,
                'sell_id' => $sell->id,
                'date' => $date,
                'quantity_out' => $qty,
                'discount' => 0,
                'recorded_by' => $user?->name ?? 'system',
                'description' => 'Transfer stok ke toko ID '.$data['to_store_id'],
            ];
            if (Schema::hasColumn('tb_outgoing_goods', 'store_id')) $outPayload['store_id'] = $data['from_store_id'];
            if (Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock')) $outPayload['is_pending_stock'] = 0;
            tb_outgoing_goods::create($outPayload);

            $purchase = tb_purchase::create([
                'supplier_id' => null,
                'store_id' => $data['to_store_id'],
                'total_price' => 0,
                'created_by' => $user?->id,
            ]);

            $inPayload = [
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'stock' => $qty,
                'description' => 'Transfer stok dari toko ID '.$data['from_store_id'],
            ];
            if (Schema::hasColumn('tb_incoming_goods', 'store_id')) $inPayload['store_id'] = $data['to_store_id'];
            if (Schema::hasColumn('tb_incoming_goods', 'is_pending_stock')) $inPayload['is_pending_stock'] = 0;
            tb_incoming_goods::create($inPayload);

            DB::table('tb_stock_transfers')->insert([
                'date' => $date,
                'product_id' => $product->id,
                'from_store_id' => $data['from_store_id'],
                'to_store_id' => $data['to_store_id'],
                'quantity' => $qty,
                'sell_id' => $sell->id,
                'purchase_id' => $purchase->id,
                'description' => $data['description'] ?? null,
                'created_by' => $user?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'Transfer stok berhasil disimpan.');
    }

    private function currentStock(int $storeId, int $productId): int
    {
        $incoming = DB::table('tb_incoming_goods as ig')
            ->when(Schema::hasColumn('tb_incoming_goods', 'deleted_at'), fn ($q) => $q->whereNull('ig.deleted_at'))
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'store_id'),
                fn ($q) => $q->where('ig.store_id', $storeId),
                fn ($q) => $q->join('tb_purchases as p', 'p.id', '=', 'ig.purchase_id')->where('p.store_id', $storeId)
            )
            ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('ig.is_pending_stock')
                       ->orWhere('ig.is_pending_stock', 0);
                });
            })
            ->where('ig.product_id', $productId)
            ->sum('ig.stock');

        $outgoing = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
            ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
            ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('og.is_pending_stock')
                       ->orWhere('og.is_pending_stock', 0);
                });
            })
            ->where('s.store_id', $storeId)
            ->where('og.product_id', $productId)
            ->sum('og.quantity_out');

        return max(0, (int) $incoming - (int) $outgoing);
    }
}

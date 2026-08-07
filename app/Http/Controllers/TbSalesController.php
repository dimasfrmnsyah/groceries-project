<?php

namespace App\Http\Controllers;

use App\Models\tb_customers;
use App\Models\tb_outgoing_goods;
use App\Models\tb_products;
use App\Models\tb_sell;
use App\Models\tb_stores;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class TbSalesController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $user_id = $user->id;
        $role = strtolower((string) ($user->roles ?? ''));
        $store_id = store_access_resolve_id($request, $user, ['store_id']);
        $current_month = Carbon::now()->format('m');
        $current_year = Carbon::now()->format('Y');
        $count_invoice = 0;
        if ($store_id) {
            $count_invoice = tb_sell::where('store_id', $store_id)
                ->whereMonth('date', $current_month)
                ->whereYear('date', $current_year)
                ->count();
        }
        $invoce_number = 'INV-'.$current_year.$current_month.str_pad($count_invoice+1, 4, '0', STR_PAD_LEFT);

        $user = User::where('id', $user_id)->with('store')->first();

        if ($role === 'superadmin') {
            $customers = $store_id ? tb_customers::where('store_id', $store_id)->get() : tb_customers::all();
        } else if (in_array($role, ['staff', 'admin', 'kasir', 'cashier'], true)) {
            $customers = $store_id ? tb_customers::where('store_id', $store_id)->get() : collect();
        } else {
            $customers = collect();
        }

        $stores = store_access_list($user);

        return view('pages.admin.sales.index', [
            'user' => $user,
            'invoice_number' => $invoce_number,
            'customers' => $customers,
            'stores' => $stores,
            'selectedStoreId' => $store_id,
        ]);
    }

    public function store(Request $request)
    {
        $input = $request->input('data', []);
        $validator = Validator::make($input, [
            'transaction_date' => 'required|date',
            'customer_money' => 'required|numeric|min:0',
            'customer_id' => 'nullable|integer',
            'idempotency_key' => 'nullable|string|max:64',
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|integer|exists:tb_products,id',
            'products.*.qty' => 'required|integer|min:1|max:100000',
        ]);

        if($validator->fails()) {
            // dd($validator->errors());
            return response()->json($validator->errors(), 422);
        }
        $user = auth()->user();
        $store_id = store_access_resolve_id($request, $user, ['data.store_id', 'store_id']);
        if (!$store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Store wajib dipilih.'
                ], 422);
        }

        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = (string) Str::uuid();
        }

        $existing = tb_sell::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'message' => 'Transaksi sudah diproses sebelumnya.',
                'sell_id' => $existing->id,
                'invoice' => $existing->no_invoice,
            ]);
        }

        $requestedQtyByProduct = collect($input['products'])
            ->groupBy('id')
            ->map(function ($items) {
                return $items->sum(fn ($item) => (int) ($item['qty'] ?? 0));
            });

        try {
            $sell = DB::transaction(function () use ($input, $user, $store_id, $idempotencyKey, $requestedQtyByProduct) {
                // Semua movement toko dikunci pada baris toko yang sama. Ini membuat dua
                // kasir tidak dapat membaca saldo yang sama lalu menjual stok yang sama.
                $store = tb_stores::where('id', $store_id)->lockForUpdate()->firstOrFail();
                $productIds = $requestedQtyByProduct->keys()->map(fn ($id) => (int) $id)->all();
                $products = tb_products::with('storePrices')->whereIn('id', $productIds)->get()->keyBy('id');
                $availableStock = $this->currentStockByProductIds($store_id, $productIds);
                $customerId = (int) ($input['customer_id'] ?? 0);
                if ($customerId > 0 && !tb_customers::where('id', $customerId)->where('store_id', $store_id)->exists()) {
                    throw new \InvalidArgumentException('Customer tidak valid untuk toko ini.');
                }

                foreach ($requestedQtyByProduct as $productId => $qty) {
                    $stock = (int) ($availableStock[$productId] ?? 0);
                    if ($stock < 0 || $qty > $stock) {
                        $productName = $products[$productId]->product_name ?? 'Produk';
                        throw new \InvalidArgumentException(
                            "Stok {$productName} hanya {$stock}. Qty tidak boleh lebih dari stok tersedia."
                        );
                    }
                }

                $totalPrice = 0.0;
                foreach ($requestedQtyByProduct as $productId => $qty) {
                    $product = $products[$productId] ?? null;
                    if (!$product) {
                        throw new \InvalidArgumentException('Produk tidak ditemukan.');
                    }
                    $totalPrice += $this->resolveSellingPrice($product, $store_id, (int) $qty) * (int) $qty;
                }

                $paymentAmount = (float) ($input['customer_money'] ?? 0);
                if ($paymentAmount < $totalPrice) {
                    throw new \InvalidArgumentException('Uang pembayaran kurang dari total transaksi.');
                }

                $sell = tb_sell::create([
                    'no_invoice' => 'INV-'.now('Asia/Jakarta')->format('YmdHisv').'-'.Str::upper(Str::random(6)),
                    'store_id' => $store_id,
                    'created_by' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'date' => $input['transaction_date'],
                    'total_price' => $totalPrice,
                    'payment_amount' => $paymentAmount,
                    'customer_id' => $customerId,
                ]);

                foreach ($requestedQtyByProduct as $productId => $qty) {
                    $payload = [
                        'product_id' => (int) $productId,
                        'sell_id' => $sell->id,
                        'date' => $input['transaction_date'],
                        'quantity_out' => (int) $qty,
                        // Diskon dari browser tidak dipercaya. Diskon produk/tier dihitung server.
                        'discount' => 0,
                        'recorded_by' => $user->name,
                        'created_by' => $user->id,
                        'source_type' => 'sale',
                        // Penjualan selalu mengurangi stok. Status toko tidak boleh menjadi bypass.
                        'is_pending_stock' => 0,
                    ];
                    if (Schema::hasColumn('tb_outgoing_goods', 'store_id')) {
                        $payload['store_id'] = $store_id;
                    }
                    tb_outgoing_goods::create($payload);
                }

                AccountingController::postSalesLedger($sell->id);
                return $sell;
            }, 3);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diproses',
                'sell_id' => $sell->id,
                'invoice' => $sell->no_invoice,
            ]);
        } catch (QueryException $e) {
            // Unique idempotency_key menangani retry/request ganda yang datang bersamaan.
            $existing = tb_sell::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'Transaksi sudah diproses sebelumnya.',
                    'sell_id' => $existing->id,
                    'invoice' => $existing->no_invoice,
                ]);
            }
            return response()->json([
                'success' => false,
                'message' => 'Transaksi gagal disimpan.',
            ], 500);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Transaksi gagal disimpan.',
            ], 500);
        }
    }

    private function currentStockByProductIds(int $storeId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
        $hasPendingIn = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock');
        $hasPendingOut = Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock');
        $hasIncomingDeleted = Schema::hasColumn('tb_incoming_goods', 'deleted_at');
        $hasOutgoingDeleted = Schema::hasColumn('tb_outgoing_goods', 'deleted_at');

        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->when($hasIncomingDeleted, fn ($q) => $q->whereNull('ig.deleted_at'))
            ->when(
                $hasIncomingStore,
                fn ($q) => $q->where(function ($qq) use ($storeId) {
                    $qq->where('ig.store_id', $storeId)
                        ->orWhereExists(function ($ex) use ($storeId) {
                            $ex->select(DB::raw(1))
                                ->from('tb_purchases as p')
                                ->whereColumn('p.id', 'ig.purchase_id')
                                ->where('p.store_id', $storeId);
                        });
                }),
                fn ($q) => $q->join('tb_purchases as p', 'ig.purchase_id', '=', 'p.id')
                    ->where('p.store_id', $storeId)
            )
            ->when($hasPendingIn, function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('ig.is_pending_stock')
                        ->orWhere('ig.is_pending_stock', 0);
                });
            })
            ->select('ig.product_id', DB::raw('SUM(ig.stock) as total_in'))
            ->groupBy('ig.product_id');

        $outgoingSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as sl', 'og.sell_id', '=', 'sl.id')
            ->when($hasOutgoingDeleted, fn ($q) => $q->whereNull('og.deleted_at'))
            ->where('sl.store_id', $storeId)
            ->when($hasPendingOut, function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('og.is_pending_stock')
                        ->orWhere('og.is_pending_stock', 0);
                });
            })
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) as total_out'))
            ->groupBy('og.product_id');

        $stockExpression = '(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0))';

        return DB::table('tb_products as p')
            ->leftJoinSub($incomingSub, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn ($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->whereIn('p.id', $productIds)
            ->select('p.id', DB::raw($stockExpression.' as current_stock'))
            ->pluck('current_stock', 'id')
            ->map(fn ($stock) => (int) $stock)
            ->all();
    }

    private function resolveSellingPrice(tb_products $product, int $storeId, int $qty): float
    {
        $pricing = $product->priceForStore($storeId);
        $base = (float) ($pricing['selling_price'] ?? 0);
        $productDiscount = (float) ($pricing['product_discount'] ?? 0);
        $unitPrice = max(0, $base - $productDiscount);

        $tiers = collect($pricing['tier_prices'] ?? [])
            ->mapWithKeys(fn ($price, $minQty) => [(int) $minQty => (float) $price])
            ->sortKeys();
        foreach ($tiers as $minQty => $tierPrice) {
            if ($qty >= $minQty) {
                $unitPrice = max(0, (float) $tierPrice);
            }
        }

        return $unitPrice;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\tb_customers;
use App\Models\tb_outgoing_goods;
use App\Models\tb_sell;
use App\Models\tb_stores;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

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
        $validator = Validator::make($request->data, [
            'transaction_date' => 'required',
            'customer_money' => 'required',
            'customer_id' => 'nullable',
            'products' => 'required|array|min:1',
            'products.*.id' => 'required|integer|exists:tb_products,id',
            'products.*.qty' => 'required|integer|min:1',
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

        $requestedQtyByProduct = collect($request->data['products'])
            ->groupBy('id')
            ->map(function ($items) {
                return $items->sum(fn ($item) => (int) ($item['qty'] ?? 0));
            });

        $availableStock = $this->currentStockByProductIds($store_id, $requestedQtyByProduct->keys()->all());
        $productNames = DB::table('tb_products')
            ->whereIn('id', $requestedQtyByProduct->keys()->all())
            ->pluck('product_name', 'id');

        foreach ($requestedQtyByProduct as $productId => $qty) {
            $stock = (int) ($availableStock[$productId] ?? 0);
            if ($qty > $stock) {
                $productName = $productNames[$productId] ?? 'Produk';
                return response()->json([
                    'success' => false,
                    'message' => "Stok {$productName} hanya {$stock}. Qty tidak boleh lebih dari {$stock}."
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $storeOnline = (int) tb_stores::where('id', $store_id)->value('is_online') === 1;
            $isPendingStock = $storeOnline ? 0 : 1;
            $hasOutgoingStore = Schema::hasColumn('tb_outgoing_goods', 'store_id');
            $hasPendingStock = Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock');

            $sell = tb_sell::create([
                'no_invoice' => $request->data['no_invoice'],
                'store_id' => $store_id,
                'date' => $request->data['transaction_date'],
                'total_price' => $request->data['total_price'],
                'payment_amount' => $request->data['customer_money'],
                'customer_id' => $request->data['customer_id'] ?? 0

            ]);

            foreach($request->data['products'] as $product) {
                $payload = [
                    'product_id' => $product['id'],
                    'sell_id' => $sell->id,
                    'date' => $request->data['transaction_date'],
                    'quantity_out' => $product['qty'],
                    'discount' => $product['discount'],
                    'recorded_by' => $user->name,
                    // 'description' => $product['description']
                ];
                if ($hasPendingStock) {
                    $payload['is_pending_stock'] = $isPendingStock;
                }
                if ($hasOutgoingStore) {
                    $payload['store_id'] = $store_id;
                }
                tb_outgoing_goods::create($payload);
            }
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil di proses'
            ]);
        } catch(\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
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
            ->map(fn ($stock) => max(0, (int) $stock))
            ->all();
    }
}

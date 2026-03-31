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
            'products.*.id' => 'required|integer',
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
}

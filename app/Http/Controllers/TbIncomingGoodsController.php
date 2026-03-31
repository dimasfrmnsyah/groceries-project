<?php

namespace App\Http\Controllers;

use App\Models\tb_incoming_goods;
use App\Models\tb_products;
use App\Models\tb_stores;
use App\Models\tb_suppliers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Yajra\DataTables\Facades\DataTables;

class TbIncomingGoodsController extends Controller
{

    public function options(Request $request)
    {
        try {
            $search = trim((string) ($request->input('search_term', $request->input('term', ''))));
            $type = strtolower((string) $request->input('type', ''));
            $isDataTable = $request->filled('draw');
            $user = $request->user();
            $role = strtolower((string) ($user?->roles ?? ''));

            if ($role === 'superadmin') {
                $storeId = (int) $request->input('store_id');
            } else {
                $storeId = store_access_resolve_id($request, $user, ['store_id']);
            }

            if (!$storeId) {
                return $this->emptyOptionsResponse($request);
            }

            $query = $this->optionsBaseQuery($storeId);
            $this->applyProductSearch($query, $search, $type);

            if ($isDataTable) {
                return DataTables::of($query)
                    ->editColumn('tier_prices', fn ($row) => $this->normalizeTierPrices($row->tier_prices))
                    ->toJson();
            }

            $rows = $query
                ->limit($type === 'barcode' ? 5 : 50)
                ->get()
                ->map(fn ($row) => $this->formatOptionRow($row))
                ->values();

            return response()->json([
                'success' => true,
                'data' => $rows,
            ]);
        } catch (\Throwable $e) {
            Log::error('incoming_goods.options failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->failedOptionsResponse($request);
        }
    }

    public function index(Request $request)
    {
        $incomingGoods = tb_incoming_goods::all();
        if($request->ajax()) {
            DataTables::of($incomingGoods)
                        ->addColumn('action', function($incomingGood) {
                            return '<a href="/incoming-goods/edit/'.$incomingGood->id.'" class="btn btn-sm btn-success"><i class="bx bx-pencil me-0"></i>
                                    </a>
                                    <a href="javascript:void(0)" onClick="confirmDelete('.$incomingGood->id.')" class="btn btn-sm btn-danger"><i class="bx bx-trash me-0"></i>
                                    </a>
                                    ';
                        })
                        ->rawColumns(['action'])
                        ->make(true);
        }

        return view('pages.admin.manage_incoming_good.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()

    {
        $actor = auth()->user();
        $stores = strtolower((string) ($actor?->roles)) === 'superadmin'
            ? tb_stores::all()
            : store_access_list($actor);
        $products = tb_products:: all();
        $suppliers = tb_suppliers::all();
        return view('pages.admin.manage_incoming_good.create', [
                                                                'stores' => $stores,
                                                                'products' => $products,
                                                                'suppliers' => $suppliers
                                                            ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required',
            'supplier_id' => 'required',
            'store_id' => 'required',
            'stock' => 'required',
            'type' => 'required',
            'description' => 'nullable',
            'paid_of_date' => 'required|date'
        ]);
        $actor = auth()->user();
        if (strtolower((string) ($actor?->roles)) !== 'superadmin') {
            $allowed = store_access_ids($actor);
            if (!in_array((int) $data['store_id'], $allowed, true)) {
                return redirect()->back()->with('error', 'Store tidak diizinkan.');
            }
        }
        if (Schema::hasColumn('tb_incoming_goods', 'is_pending_stock')) {
            $storeOnline = (int) tb_stores::where('id', $data['store_id'])->value('is_online') === 1;
            $data['is_pending_stock'] = $storeOnline ? 0 : 1;
        }

        DB::beginTransaction();
        try {
            tb_incoming_goods::create($data);
            DB::commit();
            return redirect()->route('incoming-goods.index')->with('success', 'Barang masuk berhasil dibuat');
        } catch(\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $actor = auth()->user();
        $stores = strtolower((string) ($actor?->roles)) === 'superadmin'
            ? tb_stores::all()
            : store_access_list($actor);
        $products = tb_products:: all();
        $suppliers = tb_suppliers::all();
        $incomingGood = tb_incoming_goods::where('id', $id)->first();

        return view('pages.admin.manage_incoming_good.create', [
                                                                'incomingGood' => $incomingGood,
                                                                'stores' => $stores,
                                                                'products' => $products,
                                                                'suppliers' => $suppliers
                                                                ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'product_id' => 'required',
            'supplier_id' => 'required',
            'store_id' => 'required',
            'stock' => 'required',
            'type' => 'required',
            'description' => 'nullable',
            'paid_of_date' => 'required|date'
        ]);
        $actor = auth()->user();
        if (strtolower((string) ($actor?->roles)) !== 'superadmin') {
            $allowed = store_access_ids($actor);
            if (!in_array((int) $data['store_id'], $allowed, true)) {
                return redirect()->back()->with('error', 'Store tidak diizinkan.');
            }
        }
        if (Schema::hasColumn('tb_incoming_goods', 'is_pending_stock')) {
            $storeOnline = (int) tb_stores::where('id', $data['store_id'])->value('is_online') === 1;
            $data['is_pending_stock'] = $storeOnline ? 0 : 1;
        }

        DB::beginTransaction();
        try {
            tb_incoming_goods::where('id', $id)->update($data);
            DB::commit();
            return redirect()->route('incoming-goods.index')->with('success', 'Barang masuk berhasil dibuat');
        } catch(\Exception $e){
            DB::rollBack();
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            tb_incoming_goods::where('id', $id)->delete();
            DB::commit();
            return response()->json([
                'success'=>true,
                'message'=>'Produk berhasil dihapus',
            ]);
        } catch(\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success'=>false,
                'message'=>'Produk gagal dihapus',
            ]);
        }
    }

    private function emptyOptionsResponse(Request $request)
    {
        if ($request->filled('draw')) {
            return response()->json([
                'draw' => (int) $request->input('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [],
        ]);
    }

    private function failedOptionsResponse(Request $request)
    {
        if ($request->filled('draw')) {
            return response()->json([
                'draw' => (int) $request->input('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => 'Gagal memuat data item.',
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat data item.',
        ], 500);
    }

    private function optionsBaseQuery(int $storeId)
    {
        $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
        $hasPendingIn = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock');
        $hasPendingOut = Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock');
        $hasIncomingDeleted = Schema::hasColumn('tb_incoming_goods', 'deleted_at');
        $hasOutgoingDeleted = Schema::hasColumn('tb_outgoing_goods', 'deleted_at');

        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->when($hasIncomingDeleted, fn($q) => $q->whereNull('ig.deleted_at'))
            ->when(
                $hasIncomingStore,
                fn($q) => $q->where(function ($qq) use ($storeId) {
                    $qq->where('ig.store_id', $storeId)
                       ->orWhereExists(function ($ex) use ($storeId) {
                           $ex->select(DB::raw(1))
                              ->from('tb_purchases as p')
                              ->whereColumn('p.id', 'ig.purchase_id')
                              ->where('p.store_id', $storeId);
                       });
                }),
                fn($q) => $q->join('tb_purchases as p', 'ig.purchase_id', '=', 'p.id')
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
            ->when($hasOutgoingDeleted, fn($q) => $q->whereNull('og.deleted_at'))
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
            ->leftJoin('tb_units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('tb_types as t', 't.id', '=', 'p.type_id')
            ->leftJoin('tb_brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('tb_product_store_prices as psp', function ($join) use ($storeId) {
                $join->on('psp.product_id', '=', 'p.id')
                    ->where('psp.store_id', '=', $storeId);
            })
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                DB::raw($stockExpression.' as current_stock'),
                DB::raw("COALESCE(u.unit_name, '-') as unit_name"),
                DB::raw("COALESCE(t.type_name, '-') as type_name"),
                DB::raw('COALESCE(psp.selling_price, p.selling_price, 0) as price'),
                DB::raw('COALESCE(psp.product_discount, p.product_discount, 0) as product_discount'),
                DB::raw('(COALESCE(psp.selling_price, p.selling_price, 0) - COALESCE(psp.product_discount, p.product_discount, 0)) as selling_price'),
                DB::raw('COALESCE(psp.tier_prices, p.tier_prices) as tier_prices'),
                DB::raw("COALESCE(b.brand_name, '-') as brand_name")
            )
            ->where(DB::raw($stockExpression), '>', 0)
            ->orderBy('p.product_name');
    }

    private function applyProductSearch($query, string $search, string $type): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($q) use ($search, $type) {
            $like = '%'.$search.'%';

            if ($type === 'barcode') {
                $q->where('p.product_code', $search)
                    ->orWhere('p.product_name', $search)
                    ->orWhere('p.product_code', 'like', $like);
                return;
            }

            $q->where('p.product_name', 'like', $like)
                ->orWhere('p.product_code', 'like', $like);
        });
    }

    private function formatOptionRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'product_code' => $row->product_code,
            'product_name' => $row->product_name,
            'current_stock' => (int) $row->current_stock,
            'unit_name' => $row->unit_name,
            'type_name' => $row->type_name,
            'price' => (float) $row->price,
            'product_discount' => (float) $row->product_discount,
            'selling_price' => (float) $row->selling_price,
            'tier_prices' => $this->normalizeTierPrices($row->tier_prices),
            'brand_name' => $row->brand_name,
        ];
    }

    private function normalizeTierPrices($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\MenuHelper;
use App\Support\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnsoldProductController extends Controller
{
    public function index(Request $request)
    {
        $role = strtolower(trim((string) ($request->user()?->roles ?? '')));
        abort_unless($role === 'superadmin' || MenuHelper::roleHasRoute('unsold-products.index', $role), 403);
        $search = trim((string) $request->query('q', ''));

        // Use the same incoming-store membership as the existing stock report.
        $incoming = DB::table('tb_incoming_goods as ig')
            ->leftJoin('tb_purchases as pur', 'pur.id', '=', 'ig.purchase_id')
            ->join('tb_stores as st', function ($join) {
                $join->on('st.id', '=', 'pur.store_id');
                if (Schema::hasColumn('tb_incoming_goods', 'store_id')) {
                    $join->orOn('st.id', '=', 'ig.store_id');
                }
            })
            ->whereNull('st.deleted_at')
            ->when(Schema::hasColumn('tb_incoming_goods', 'deleted_at'), fn ($q) => $q->whereNull('ig.deleted_at'))
            ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'), fn ($q) => $q->where(fn ($q) => $q->whereNull('ig.is_pending_stock')->orWhere('ig.is_pending_stock', 0)))
            ->whereBetween('ig.stock', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
            ->selectRaw('ig.product_id, st.id as store_id, SUM(ig.stock) as quantity')
            ->groupBy('ig.product_id', 'st.id');

        $outgoing = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
            ->join('tb_stores as st', 'st.id', '=', 's.store_id')
            ->whereNull('st.deleted_at')
            ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
            ->whereBetween('og.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY]);
        StockLedger::applyOutgoingBalanceFilter($outgoing, Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'));
        $outgoing->selectRaw('og.product_id, st.id as store_id, -SUM(og.quantity_out) as quantity')
            ->groupBy('og.product_id', 'st.id');

        // Group per store first: +5 in one store and -5 in another is not zero everywhere.
        $nonZeroBalances = DB::query()->fromSub($incoming->unionAll($outgoing), 'movements')
            ->select('product_id', 'store_id')->groupBy('product_id', 'store_id')
            ->havingRaw('SUM(quantity) <> 0');

        $products = DB::table('tb_products as p')->where('p.is_active', 1)
            ->whereNotExists(function ($query) use ($nonZeroBalances) {
                $query->selectRaw('1')->fromSub($nonZeroBalances, 'balances')
                    ->whereColumn('balances.product_id', 'p.id');
            })
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('p.product_code', 'like', '%'.$search.'%')->orWhere('p.product_name', 'like', '%'.$search.'%')))
            ->select('p.id', 'p.product_code', 'p.product_name')
            ->orderBy('p.product_name')->orderBy('p.id')->paginate(50)->withQueryString();

        return view('pages.admin.unsold-products.index', compact('products', 'search'));
    }
}

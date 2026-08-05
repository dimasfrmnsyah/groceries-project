<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ItemMovingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $storeId = store_access_resolve_id($request, $user, ['store']);
        $category = $request->get('category', 'all');
        $basis = $request->get('basis', 'monthly');
        $search = trim((string) $request->get('q', ''));

        $stores = store_access_can_select($user) ? store_access_list($user) : collect();
        $toStores = $storeId
            ? store_access_list($user)->where('id', '!=', $storeId)->values()
            : collect();

        $rows = $storeId ? $this->movingRows($storeId, $basis, $search) : collect();
        if ($category !== 'all') {
            $rows = $rows->where('moving_category', $category)->values();
        }

        return view('pages.admin.item-moving.index', compact(
            'stores',
            'toStores',
            'storeId',
            'category',
            'basis',
            'search',
            'rows'
        ));
    }

    private function movingRows(int $storeId, string $basis, string $search)
    {
        $now = now('Asia/Jakarta');
        $sixStart = $now->copy()->subMonthsNoOverflow(6)->startOfDay();
        $threeStart = $now->copy()->subMonthsNoOverflow(3)->startOfDay();
        $deadBefore = $now->copy()->subMonthsNoOverflow(6)->startOfDay();
        $divisorSix = $basis === 'weekly' ? 26 : 6;
        $divisorThree = $basis === 'weekly' ? 13 : 3;

        $salesDate = 'COALESCE(og.date, s.date, og.created_at, s.created_at)';

        $salesSix = $this->salesSub($storeId, $sixStart, $now, $salesDate)
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) as total_sold'), DB::raw('MAX('.$salesDate.') as last_sale_at'))
            ->groupBy('og.product_id');

        $salesThree = $this->salesSub($storeId, $threeStart, $now, $salesDate)
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) as total_sold'))
            ->groupBy('og.product_id');

        $lastSales = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
            ->where('s.store_id', $storeId)
            ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
            ->when(Schema::hasColumn('tb_sells', 'deleted_at'), fn ($q) => $q->whereNull('s.deleted_at'))
            ->where(function ($q) {
                $q->whereNull('s.no_invoice')
                  ->orWhere(function ($qq) {
                      $qq->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                         ->where('s.no_invoice', 'not like', 'AR-%')
                         ->where('s.no_invoice', 'not like', 'TRF-%');
                  });
            })
            ->select('og.product_id', DB::raw('MAX('.$salesDate.') as last_sale_at'))
            ->groupBy('og.product_id');

        $stockSub = $this->stockSub($storeId);

        $rows = DB::table('tb_products as p')
            ->leftJoin('tb_product_store_thresholds as th', function ($join) use ($storeId) {
                $join->on('th.product_id', '=', 'p.id')->where('th.store_id', '=', $storeId);
            })
            ->leftJoinSub($salesSix, 's6', fn ($join) => $join->on('s6.product_id', '=', 'p.id'))
            ->leftJoinSub($salesThree, 's3', fn ($join) => $join->on('s3.product_id', '=', 'p.id'))
            ->leftJoinSub($lastSales, 'last_sales', fn ($join) => $join->on('last_sales.product_id', '=', 'p.id'))
            ->leftJoinSub($stockSub, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'p.id'))
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                DB::raw('COALESCE(th.min_stock, 0) as min_stock'),
                DB::raw('COALESCE(th.max_stock, 0) as max_stock'),
                DB::raw('COALESCE(stock.stock_system, 0) as stock_system'),
                DB::raw('COALESCE(s6.total_sold, 0) / '.$divisorSix.' as avg_six'),
                DB::raw('COALESCE(s3.total_sold, 0) / '.$divisorThree.' as avg_three'),
                DB::raw('last_sales.last_sale_at as last_sale_at')
            )
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(function ($qq) use ($like) {
                    $qq->where('p.product_name', 'like', $like)
                        ->orWhere('p.product_code', 'like', $like);
                });
            })
            ->orderBy('p.product_name')
            ->get();

        return $rows->map(function ($row) use ($deadBefore) {
            $lastSale = $row->last_sale_at ? Carbon::parse($row->last_sale_at, 'Asia/Jakarta') : null;
            $min = (float) $row->min_stock;
            $max = (float) $row->max_stock;
            $avgSix = (float) $row->avg_six;
            $avgThree = (float) $row->avg_three;

            if (!$lastSale || $lastSale->lt($deadBefore)) {
                $row->moving_category = 'dead';
            } elseif ($max > 0 && $avgThree > $max) {
                $row->moving_category = 'fast';
            } elseif ($min > 0 && $avgSix < $min) {
                $row->moving_category = 'slow';
            } else {
                $row->moving_category = 'normal';
            }

            return $row;
        });
    }

    private function salesSub(int $storeId, $start, $end, string $dateExpression)
    {
        return DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
            ->where('s.store_id', $storeId)
            ->whereBetween(DB::raw($dateExpression), [$start, $end])
            ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
            ->when(Schema::hasColumn('tb_sells', 'deleted_at'), fn ($q) => $q->whereNull('s.deleted_at'))
            ->where(function ($q) {
                $q->whereNull('s.no_invoice')
                    ->orWhere(function ($qq) {
                        $qq->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                           ->where('s.no_invoice', 'not like', 'AR-%')
                           ->where('s.no_invoice', 'not like', 'TRF-%');
                    });
            });
    }

    private function stockSub(int $storeId)
    {
        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->when(Schema::hasColumn('tb_incoming_goods', 'deleted_at'), fn ($q) => $q->whereNull('ig.deleted_at'))
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'store_id'),
                fn ($q) => $q->where(function ($qq) use ($storeId) {
                    $qq->where('ig.store_id', $storeId)
                        ->orWhereExists(function ($ex) use ($storeId) {
                            $ex->select(DB::raw(1))
                                ->from('tb_purchases as pur')
                                ->whereColumn('pur.id', 'ig.purchase_id')
                                ->where('pur.store_id', $storeId);
                        });
                }),
                fn ($q) => $q->join('tb_purchases as p', 'p.id', '=', 'ig.purchase_id')->where('p.store_id', $storeId)
            )
            ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('ig.is_pending_stock')->orWhere('ig.is_pending_stock', 0);
                });
            })
            ->select('ig.product_id', DB::raw('SUM(ig.stock) as total_in'))
            ->groupBy('ig.product_id');

        $outgoingSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as s', 's.id', '=', 'og.sell_id')
            ->where('s.store_id', $storeId)
            ->when(Schema::hasColumn('tb_outgoing_goods', 'deleted_at'), fn ($q) => $q->whereNull('og.deleted_at'))
            ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('og.is_pending_stock')->orWhere('og.is_pending_stock', 0);
                });
            })
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) as total_out'))
            ->groupBy('og.product_id');

        return DB::table('tb_products as p')
            ->leftJoinSub($incomingSub, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn ($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->select('p.id as product_id', DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) as stock_system'));
    }
}

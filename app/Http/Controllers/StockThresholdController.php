<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockThresholdController extends Controller
{
    public function index(Request $request)
    {
        $user         = $request->user();
        $isSuperadmin = strtolower((string) ($user?->roles)) === 'superadmin';
        $storeId      = store_access_resolve_id($request, $user, ['store']);
        $search       = trim((string)$request->get('q', ''));

        $stores = store_access_can_select($user)
            ? store_access_list($user)
            : collect();

        if (!$storeId && $isSuperadmin) {
            return view('pages.admin.threshold.index', [
                'stores' => $stores,
                'storeId'=> null,
                'rows'   => collect(),
                'search' => $search,
            ]);
        }

        if (!$storeId) {
            return redirect()->back()->with('warning', 'Pilih toko terlebih dahulu.');
        }

        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('ig.deleted_at')
            )
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
                })->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                    fn ($q2) => $q2->where(function ($w) {
                        $w->whereNull('ig.is_pending_stock')
                          ->orWhere('ig.is_pending_stock', 0);
                    })),
                fn ($q) => $q->join('tb_purchases as pur', 'ig.purchase_id', '=', 'pur.id')
                             ->where('pur.store_id', $storeId)
                             ->when(Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                                 fn ($q2) => $q2->where(function ($w) {
                                     $w->whereNull('ig.is_pending_stock')
                                       ->orWhere('ig.is_pending_stock', 0);
                                 }))
            )
            ->select('ig.product_id', DB::raw('SUM(ig.stock) AS total_in'))
            ->groupBy('ig.product_id');

        $outgoingSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as sl', 'og.sell_id', '=', 'sl.id')
            ->where('sl.store_id', $storeId)
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('og.deleted_at')
            )
            ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('og.is_pending_stock')
                           ->orWhere('og.is_pending_stock', 0);
                    });
                })
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) AS total_out'))
            ->groupBy('og.product_id');

        $avgDays = 30;
        $avgStart = now('Asia/Jakarta')->subDays($avgDays - 1)->startOfDay();
        $avgEnd = now('Asia/Jakarta')->endOfDay();
        $dateExpr = 'COALESCE(og.date, sl.date, og.created_at, sl.created_at)';
        $hasSellerIdColumn = Schema::hasColumn('tb_sells', 'seller_id');
        $hasInvoiceColumn = Schema::hasColumn('tb_sells', 'no_invoice');

        $excludeStockOpname = function ($query) use ($hasSellerIdColumn, $hasInvoiceColumn) {
            if ($hasSellerIdColumn) {
                $query->where(function ($q) {
                    $q->whereNull('sl.seller_id')
                      ->orWhere('sl.seller_id', '!=', 1);
                });
            }
            if ($hasInvoiceColumn) {
                $query->where(function ($q) {
                    $q->whereNull('sl.no_invoice')
                      ->orWhere(function ($qq) {
                          $qq->where('sl.no_invoice', 'not like', 'SO-ADJ-%')
                             ->where('sl.no_invoice', 'not like', 'AR-%')
                             ->where('sl.no_invoice', 'not like', 'TRF-%');
                      });
                });
            }
        };

        $avgSalesSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as sl', 'og.sell_id', '=', 'sl.id')
            ->when($storeId, fn ($q) => $q->where('sl.store_id', $storeId))
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('og.deleted_at')
            )
            ->when(Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('og.is_pending_stock')
                           ->orWhere('og.is_pending_stock', 0);
                    });
                })
            ->whereBetween(DB::raw($dateExpr), [$avgStart, $avgEnd])
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) AS total_sold'))
            ->groupBy('og.product_id');

        $excludeStockOpname($avgSalesSub);

        $rows = DB::table('tb_products as p')
            ->leftJoin('tb_product_store_thresholds as st', function ($join) use ($storeId) {
                $join->on('st.product_id', '=', 'p.id')
                     ->where('st.store_id', '=', $storeId);
            })
            ->leftJoinSub($incomingSub, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn ($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->leftJoinSub($avgSalesSub, 'avg_sales', fn ($join) => $join->on('avg_sales.product_id', '=', 'p.id'))
            ->select(
                'p.id',
                'p.product_code',
                'p.product_name',
                'st.min_stock',
                'st.max_stock',
                DB::raw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) as stock_system'),
                DB::raw('COALESCE(avg_sales.total_sold, 0) / '.$avgDays.' as avg_daily_sales')
            )
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($qq) use ($like) {
                    $qq->where('p.product_name', 'like', $like)
                       ->orWhere('p.product_code', 'like', $like);
                });
            })
            ->orderBy('p.product_name')
            ->get();

        return view('pages.admin.threshold.index', [
            'stores' => $stores,
            'storeId'=> $storeId,
            'rows'   => $rows,
            'search' => $search,
        ]);
    }

    public function save(Request $request)
    {
        $user         = $request->user();
        $storeId      = store_access_resolve_id($request, $user, ['store_id']);

        if (!$storeId) return back()->with('error', 'Store wajib dipilih.');

        $items = $request->input('items', []);
        $expectedCount = (int) $request->input('expected_count', 0);
        if ($expectedCount > 0 && count($items) < $expectedCount) {
            return back()->with('error', 'Koneksi tidak stabil. Data yang diterima tidak lengkap, silakan simpan ulang.');
        }

        DB::beginTransaction();
        try {
            foreach ($items as $pid => $row) {
                $pid = (int)$pid;
                if ($pid <= 0 || !is_array($row)) continue;

                $existing = DB::table('tb_product_store_thresholds')
                    ->where('product_id', $pid)
                    ->where('store_id', $storeId)
                    ->first();

                $hasMinKey = array_key_exists('min_stock', $row);
                $hasMaxKey = array_key_exists('max_stock', $row);
                $minInput = $hasMinKey ? $row['min_stock'] : null;
                $maxInput = $hasMaxKey ? $row['max_stock'] : null;
                $minEmpty = $hasMinKey && $this->isEmptyStockInput($minInput);
                $maxEmpty = $hasMaxKey && $this->isEmptyStockInput($maxInput);

                $minStock = $hasMinKey
                    ? ($minEmpty ? ($existing->min_stock ?? null) : $this->normalizeStockValue($minInput))
                    : ($existing->min_stock ?? null);
                $maxStock = $hasMaxKey
                    ? ($maxEmpty ? ($existing->max_stock ?? null) : $this->normalizeStockValue($maxInput))
                    : ($existing->max_stock ?? null);

                if (!$hasMinKey && !$hasMaxKey) {
                    continue;
                }

                if (!$existing && $minEmpty && $maxEmpty) {
                    continue;
                }

                if (!$minEmpty && !$maxEmpty && $minStock !== null && $maxStock !== null && $maxStock < $minStock) {
                    $maxStock = $minStock;
                }

                if ($existing) {
                    DB::table('tb_product_store_thresholds')
                        ->where('id', $existing->id)
                        ->update([
                            'min_stock'      => $minStock,
                            'max_stock'      => $maxStock,
                            'updated_at'     => now(),
                        ]);
                } else {
                    if ($minStock === null && $maxStock === null) {
                        continue;
                    }
                    DB::table('tb_product_store_thresholds')->insert([
                        'product_id'      => $pid,
                        'store_id'        => $storeId,
                        'min_stock'       => $minStock,
                        'max_stock'       => $maxStock,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
            }
            DB::commit();
            return back()->with('success', 'Batas stok berhasil disimpan.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    private function normalizeStockValue($value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '' || is_array($value) || !is_numeric($value)) {
            return null;
        }
        $intValue = (int)$value;
        return $intValue < 0 ? 0 : $intValue;
    }

    private function isEmptyStockInput($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        return false;
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\tb_stores;
use App\Models\tb_products;
use App\Models\tb_stock_opnames;

class InventoryController extends Controller
{
    private const MAX_STOCK_OPNAME_QUANTITY = 1000000;

    public function index(Request $request)
    {
        $user     = auth()->user();
        $role = strtolower((string) ($user->roles ?? ''));
        $storeId  = store_access_resolve_id($request, $user, ['store_id']);
        $canSelectStore = store_access_can_select($user);

        if ($role === 'superadmin' && !$storeId) {
            $query  = collect();
            $stores = $canSelectStore ? store_access_list($user) : collect();
            $draftQuantities = [];
            return view('pages.admin.inventory.index', compact('query', 'stores', 'storeId', 'draftQuantities'));
        }

        $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'deleted_at'),
                fn($q) => $q->whereNull('ig.deleted_at')
            )
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
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('ig.is_pending_stock')
                           ->orWhere('ig.is_pending_stock', 0);
                    });
                }
            )
            ->select('ig.product_id', DB::raw('SUM(ig.stock) AS total_in'))
            ->groupBy('ig.product_id');

        $outgoingSub = DB::table('tb_outgoing_goods as og')
            ->join('tb_sells as sl', 'og.sell_id', '=', 'sl.id')
            ->where('sl.store_id', $storeId)
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'deleted_at'),
                fn($q) => $q->whereNull('og.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('og.is_pending_stock')
                           ->orWhere('og.is_pending_stock', 0);
                    });
                }
            )
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) AS total_out'))
            ->groupBy('og.product_id');

        $query = DB::table('tb_products as pr')
            ->join('tb_stores as s', function ($join) use ($storeId) {
                $join->on('s.id', '=', DB::raw((int)$storeId));
            })
            ->leftJoin('tb_product_store_prices as sp', function ($join) use ($storeId) {
                $join->on('sp.product_id', '=', 'pr.id')
                     ->where('sp.store_id', '=', $storeId);
            })
            ->leftJoinSub($incomingSub, 'in_sum', function ($join) {
                $join->on('in_sum.product_id', '=', 'pr.id');
            })
            ->leftJoinSub($outgoingSub, 'out_sum', function ($join) {
                $join->on('out_sum.product_id', '=', 'pr.id');
            })
            ->leftJoin('tb_stock_opnames as so', function ($join) use ($storeId) {
                $join->on('so.product_id', '=', 'pr.id')
                     ->where('so.store_id', '=', $storeId);
            })
            ->select(
                'pr.id as product_id',
                'pr.product_code',
                'pr.product_name',
                's.id as store_id',
                's.store_name',
                DB::raw('COALESCE(sp.purchase_price, pr.purchase_price) as purchase_price'),
                DB::raw('COALESCE(sp.selling_price, pr.selling_price) as selling_price'),
                DB::raw('COALESCE(in_sum.total_in, 0) - COALESCE(out_sum.total_out, 0) as system_stock_raw'),
                DB::raw('so.physical_quantity as physical_quantity')
            )
            ->orderBy('pr.product_name')
            ->get();

        $stores = $canSelectStore ? store_access_list($user) : collect();
        $draftQuantities = [];
        if ($request->boolean('back')) {
            $preview = $request->session()->pull('inventory.stock_opname_preview');
            if ($preview && (int)($preview['store_id'] ?? 0) === (int)$storeId) {
                foreach (($preview['items'] ?? []) as $item) {
                    $draftQuantities[(int)$item['product_id']] = (int)$item['physical_quantity'];
                }
            }
        }
        return view('pages.admin.inventory.index', compact('query', 'stores', 'storeId', 'draftQuantities'));
    }

    public function adjustStock(Request $request)
    {
        $request->validate([
            'product_name'       => 'required|string',
            'store_name'         => 'required|string',
            'physical_quantity'  => 'required|integer|min:0',
        ]);

        $product = tb_products::where('product_name', $request->product_name)->firstOrFail();
        $store   = tb_stores::where('store_name', $request->store_name)->firstOrFail();

        tb_stock_opnames::updateOrCreate(
            ['product_id' => $product->id, 'store_id' => $store->id],
            ['physical_quantity' => (int)$request->physical_quantity]
        );

        return response()->json(['message' => 'Stock opname berhasil disimpan']);
    }

    /**
     * Endpoint V3: FULL RAW SQL untuk write, tanpa Query Builder
     * Route: inventory.adjustStockBulkV3
     */
    public function adjustStockBulkV3(Request $request)
    {
        $ver = 'adj-v15-nobuilder';

        if ($request->boolean('use_session_items')) {
            $token = (string) $request->input('preview_token');
            $sessionToken = (string) $request->session()->get('inventory.stock_opname_preview.token');
            if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
                $message = "[$ver] Token ringkasan tidak valid atau sudah diproses";
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }
                return redirect()->back()->with('error', $message);
            }
            if ($request->session()->get('inventory.stock_opname_preview.processing')) {
                $message = "[$ver] Ringkasan sedang diproses. Silakan tunggu.";
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }
                return redirect()->back()->with('error', $message);
            }
            $request->session()->put('inventory.stock_opname_preview.processing', true);
        }

        try {
            $items = $this->parseStockItems($request, true);
        } catch (\InvalidArgumentException $e) {
            if ($request->boolean('use_session_items')) {
                $request->session()->forget('inventory.stock_opname_preview.processing');
            }
            $message = "[$ver] ".$e->getMessage();
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }
            return redirect()->back()->with('error', $message);
        }

        if (empty($items)) {
            $storeId = (int) ($request->session()->get('inventory.stock_opname_preview.store_id') ?? $request->input('store_id') ?? 0);
            if ($storeId <= 0) {
                if ($request->boolean('use_session_items')) {
                    $request->session()->forget('inventory.stock_opname_preview.processing');
                }
                $message = "[$ver] store_id tidak ditemukan";
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 422);
                }
                return redirect()->back()->with('error', $message);
            }

            $message = "[$ver] Tidak ada perubahan stok untuk disimpan.";
            $request->session()->forget('inventory.stock_opname_preview');
            if ($request->expectsJson()) {
                return response()->json(['message' => $message]);
            }
            return redirect()
                ->route('inventory.index', ['store_id' => $storeId])
                ->with('success', $message);
        }

        $storeIds = array_values(array_unique(array_filter(array_column($items, 'store_id'))));
        if (count($storeIds) !== 1) {
            if ($request->boolean('use_session_items')) {
                $request->session()->forget('inventory.stock_opname_preview.processing');
            }
            $message = "[$ver] Multiple/invalid store_id";
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }
            return redirect()->back()->with('error', $message);
        }
        $storeId = (int)$storeIds[0];

        try {
            $userId = auth()->id();
            DB::transaction(function () use ($items, $userId, $storeId) {
                $now = now();

                // Saat toko offline, adjustment disimpan pending dan dilepas saat toko online kembali.
                $storeOnline = (int) DB::table('tb_stores')->where('id', $storeId)->value('is_online') === 1;
                $isPending = $storeOnline ? 0 : 1;

                $incomingDeletedSql = Schema::hasColumn('tb_incoming_goods', 'deleted_at')
                    ? ' AND ig.deleted_at IS NULL'
                    : '';
                $outgoingDeletedSql = Schema::hasColumn('tb_outgoing_goods', 'deleted_at')
                    ? ' AND og.deleted_at IS NULL'
                    : '';
                $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
                $hasOutgoingStore = Schema::hasColumn('tb_outgoing_goods', 'store_id');
                $incomingPendingSql = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock')
                    ? ' AND (ig.is_pending_stock IS NULL OR ig.is_pending_stock = 0)'
                    : '';
                $outgoingPendingSql = Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock')
                    ? ' AND (og.is_pending_stock IS NULL OR og.is_pending_stock = 0)'
                    : '';

                $supplier = DB::select('SELECT id FROM tb_suppliers WHERE code = ? LIMIT 1', ['SO-ADJ']);
                $supplierId = $supplier ? (int)$supplier[0]->id : null;
                if (!$supplierId) {
                    DB::insert(
                        'INSERT INTO tb_suppliers (`code`,`name`,`address`,`created_at`,`updated_at`)
                         VALUES (?,?,?,?,?)',
                        ['SO-ADJ', 'Stock Opname Adjustment', null, $now, $now]
                    );
                    $supplierId = (int)DB::getPdo()->lastInsertId();
                }

                $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));
                if (empty($productIds)) return;

                $ph = fn($n) => implode(',', array_fill(0, $n, '?'));

                $paramsIn = array_merge([$storeId], $productIds);
                $rowsIn = DB::select(
                    'SELECT ig.product_id, SUM(ig.stock) AS total_in
                       FROM tb_incoming_goods ig
                       JOIN tb_purchases p ON ig.purchase_id = p.id
                      WHERE p.store_id = ? AND ig.product_id IN ('.$ph(count($productIds)).')'.$incomingDeletedSql.$incomingPendingSql.'
                   GROUP BY ig.product_id',
                    $paramsIn
                );
                $incoming = [];
                foreach ($rowsIn as $r) { $incoming[(int)$r->product_id] = (int)$r->total_in; }

                $paramsOut = array_merge([$storeId], $productIds);
                $rowsOut = DB::select(
                    'SELECT og.product_id, SUM(og.quantity_out) AS total_out
                       FROM tb_outgoing_goods og
                       JOIN tb_sells sl ON og.sell_id = sl.id
                      WHERE sl.store_id = ? AND og.product_id IN ('.$ph(count($productIds)).')'.$outgoingDeletedSql.$outgoingPendingSql.'
                   GROUP BY og.product_id',
                    $paramsOut
                );
                $outgoing = [];
                foreach ($rowsOut as $r) { $outgoing[(int)$r->product_id] = (int)$r->total_out; }

                $rowsPrice = DB::select(
                    'SELECT p.id, COALESCE(sp.purchase_price, p.purchase_price) AS purchase_price
                       FROM tb_products p
                       LEFT JOIN tb_product_store_prices sp
                         ON sp.product_id = p.id AND sp.store_id = ?
                      WHERE p.id IN ('.$ph(count($productIds)).')',
                    array_merge([$storeId], $productIds)
                );
                $prices = [];
                foreach ($rowsPrice as $r) { $prices[(int)$r->id] = (int)$r->purchase_price; }

                $purchaseId    = null;
                $totalPurchase = 0;
                $sellId        = null;

                foreach ($items as $it) {
                    $pid  = (int)$it['product_id'];
                    $phys = (int)$it['physical_quantity'];
                    if ($pid <= 0) continue;

                    $system = (int)($incoming[$pid] ?? 0) - (int)($outgoing[$pid] ?? 0);
                    $this->assertReasonableStockAdjustment($pid, $system, $phys);
                    $minus  = max(0, $system - $phys);
                    $price  = (int)($prices[$pid] ?? 0);

                    if ($minus > 0) {
                        if ($sellId === null) {
                            DB::insert(
                                'INSERT INTO tb_sells (`no_invoice`,`store_id`,`date`,`total_price`,`payment_amount`,`created_at`,`updated_at`)
                                 VALUES (?,?,?,?,?,?,?)',
                                ['SO-ADJ-OUT-'.date('YmdHis'), $storeId, $now, 0, 0, $now, $now]
                            );
                            $sellId = (int)DB::getPdo()->lastInsertId();
                        }
                        $outColumns = ['product_id', 'sell_id', 'date', 'quantity_out', 'discount', 'recorded_by', 'description', 'is_pending_stock'];
                        $outValues = [$pid, $sellId, $now, $minus, 0, 'Stock Opname', 'Stock Opname (-)', $isPending];
                        if ($hasOutgoingStore) {
                            $outColumns[] = 'store_id';
                            $outValues[] = $storeId;
                        }
                        $outColumns = array_merge($outColumns, ['created_at', 'updated_at']);
                        $outValues = array_merge($outValues, [$now, $now]);

                        DB::insert(
                            'INSERT INTO tb_outgoing_goods (`'.implode('`,`', $outColumns).'`) VALUES ('.implode(',', array_fill(0, count($outColumns), '?')).')',
                            $outValues
                        );
                    }

                    DB::statement(
                        'INSERT INTO tb_stock_opnames
                          (`product_id`,`store_id`,`physical_quantity`,`created_at`,`updated_at`)
                          VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE
                          physical_quantity = VALUES(physical_quantity),
                          updated_at = VALUES(updated_at)',
                        [$pid, $storeId, $phys, $now, $now]
                    );

                    $plus = max(0, $phys - $system);
                    if ($plus > 0) {
                        if ($purchaseId === null) {
                            DB::insert(
                                'INSERT INTO tb_purchases (`supplier_id`,`store_id`,`total_price`,`created_by`,`created_at`,`updated_at`)
                                 VALUES (?,?,?,?,?,?)',
                                [$supplierId, $storeId, 0, $userId, $now, $now]
                            );
                            $purchaseId = (int)DB::getPdo()->lastInsertId();
                        }

                        $totalPurchase += $plus * $price;

                        $inColumns = ['purchase_id', 'product_id', 'stock', 'description', 'is_pending_stock'];
                        $inValues = [$purchaseId, $pid, $plus, 'Stock Opname (+)', $isPending];
                        if ($hasIncomingStore) {
                            $inColumns[] = 'store_id';
                            $inValues[] = $storeId;
                        }
                        $inColumns = array_merge($inColumns, ['created_at', 'updated_at']);
                        $inValues = array_merge($inValues, [$now, $now]);

                        DB::insert(
                            'INSERT INTO tb_incoming_goods (`'.implode('`,`', $inColumns).'`) VALUES ('.implode(',', array_fill(0, count($inColumns), '?')).')',
                            $inValues
                        );
                    }
                }

                if ($purchaseId !== null) {
                    DB::update(
                        'UPDATE tb_purchases SET total_price = ?, updated_at = ? WHERE id = ?',
                        [$totalPurchase, $now, $purchaseId]
                    );
                }

                if ($sellId !== null) {
                    DB::update(
                        'UPDATE tb_sells SET total_price = ?, payment_amount = ?, updated_at = ? WHERE id = ?',
                        [0, 0, $now, $sellId]
                    );
                }
            });

            $message = "[$ver] Stock opname tersimpan. Pembelian dibuat jika ada penambahan stok.";
            $request->session()->forget('inventory.stock_opname_preview');

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                ]);
            }

            return redirect()
                ->route('inventory.index', ['store_id' => $storeId])
                ->with('success', $message);
        } catch (\Throwable $e) {
            if ($request->boolean('use_session_items')) {
                $request->session()->forget('inventory.stock_opname_preview.processing');
            }
            Log::error('adjustStockBulkV3 error', [
                'ver' => $ver, 'err' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
            $message = "[$ver] Gagal menyimpan: ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine();
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message
                ], 500);
            }
            return redirect()->back()->with('error', $message);
        }
    }

    public function adjustStockPreview(Request $request)
    {
        $ver = 'adj-v15-nobuilder';
        try {
            $items = $this->parseStockItems($request, true);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => "[$ver] ".$e->getMessage()], 422);
        }

        $storeId = 0;
        if (!empty($items)) {
            $storeIds = array_values(array_unique(array_filter(array_column($items, 'store_id'))));
            if (count($storeIds) !== 1) {
                return response()->json(['message' => "[$ver] Multiple/invalid store_id"], 422);
            }
            $storeId = (int)$storeIds[0];
        } else {
            $storeId = (int)$request->input('store_id', 0);
            if ($storeId <= 0) {
                return response()->json(['message' => "[$ver] store_id tidak ditemukan"], 422);
            }
        }

        $totalItems = (int)$request->input('total_items', 0);
        $summary = $this->buildStockSummary($items, $storeId, $totalItems > 0 ? $totalItems : null);
        $token = bin2hex(random_bytes(16));
        $request->session()->put('inventory.stock_opname_preview', [
            'items' => $items,
            'summary' => $summary,
            'store_id' => $storeId,
            'token' => $token,
        ]);

        return response()->json([
            'redirect_url' => route('inventory.adjustStockPreviewPage'),
        ]);
    }

    public function adjustStockPreviewPage(Request $request)
    {
        $preview = $request->session()->get('inventory.stock_opname_preview');
        if (!$preview) {
            return redirect()->route('inventory.index');
        }

        $summary = $preview['summary'] ?? [];

        return view('pages.admin.inventory.preview', [
            'summary' => $summary,
            'changes' => $summary['changes'] ?? [],
            'items' => $preview['items'] ?? [],
            'previewToken' => $preview['token'] ?? null,
        ]);
    }

    public function refreshCsrf(Request $request)
    {
        return response()
            ->json(['token' => csrf_token()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function normalizeNegativeStock(Request $request)
    {
        $user = $request->user();
        $storeId = store_access_resolve_id($request, $user, ['store_id']);

        if (!$storeId) {
            return redirect()->back()->with('error', 'Pilih toko terlebih dahulu.');
        }

        $storeOnline = (int) DB::table('tb_stores')->where('id', $storeId)->value('is_online') === 1;
        if (!$storeOnline) {
            return redirect()->back()->with('error', 'Toko offline. Set online dulu untuk normalisasi stok minus.');
        }

        $incomingSub = DB::table('tb_incoming_goods as ig')
            ->join('tb_purchases as p', 'ig.purchase_id', '=', 'p.id')
            ->where('p.store_id', $storeId)
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'deleted_at'),
                fn ($q) => $q->whereNull('ig.deleted_at')
            )
            ->when(
                Schema::hasColumn('tb_incoming_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('ig.is_pending_stock')
                           ->orWhere('ig.is_pending_stock', 0);
                    });
                }
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
            ->when(
                Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock'),
                function ($q) {
                    $q->where(function ($qq) {
                        $qq->whereNull('og.is_pending_stock')
                           ->orWhere('og.is_pending_stock', 0);
                    });
                }
            )
            ->select('og.product_id', DB::raw('SUM(og.quantity_out) AS total_out'))
            ->groupBy('og.product_id');

        $negativeRows = DB::table('tb_products as p')
            ->leftJoinSub($incomingSub, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'p.id'))
            ->leftJoinSub($outgoingSub, 'outgoing', fn ($join) => $join->on('outgoing.product_id', '=', 'p.id'))
            ->leftJoin('tb_product_store_prices as sp', function ($join) use ($storeId) {
                $join->on('sp.product_id', '=', 'p.id')
                     ->where('sp.store_id', '=', $storeId);
            })
            ->select(
                'p.id',
                DB::raw('COALESCE(incoming.total_in, 0) as total_in'),
                DB::raw('COALESCE(outgoing.total_out, 0) as total_out'),
                DB::raw('COALESCE(sp.purchase_price, p.purchase_price) as purchase_price')
            )
            ->whereRaw('(COALESCE(incoming.total_in, 0) - COALESCE(outgoing.total_out, 0)) < 0')
            ->get();

        if ($negativeRows->isEmpty()) {
            return redirect()
                ->route('inventory.index', ['store_id' => $storeId])
                ->with('success', 'Tidak ada stok minus untuk dinormalisasi.');
        }

        $now = now();
        DB::transaction(function () use ($negativeRows, $storeId, $now) {
            $supplier = DB::table('tb_suppliers')->select('id')->where('code', 'SO-ADJ')->first();
            $supplierId = $supplier ? (int) $supplier->id : null;
            if (!$supplierId) {
                DB::table('tb_suppliers')->insert([
                    'code' => 'SO-ADJ',
                    'name' => 'Stock Opname Adjustment',
                    'address' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $supplierId = (int) DB::getPdo()->lastInsertId();
            }

            $purchaseId = DB::table('tb_purchases')->insertGetId([
                'supplier_id' => $supplierId,
                'store_id' => $storeId,
                'total_price' => 0,
                'created_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $hasIncomingStore = Schema::hasColumn('tb_incoming_goods', 'store_id');
            $hasPendingStock = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock');
            $rows = [];
            $totalPurchase = 0;

            foreach ($negativeRows as $row) {
                $net = (int) $row->total_in - (int) $row->total_out;
                if ($net >= 0) {
                    continue;
                }
                $qty = abs($net);
                $price = (int) ($row->purchase_price ?? 0);
                $totalPurchase += $qty * $price;

                $payload = [
                    'purchase_id' => $purchaseId,
                    'product_id' => (int) $row->id,
                    'stock' => $qty,
                    'description' => 'Normalisasi stok minus',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($hasPendingStock) {
                    $payload['is_pending_stock'] = 0;
                }
                if ($hasIncomingStore) {
                    $payload['store_id'] = $storeId;
                }
                $rows[] = $payload;
            }

            if (!empty($rows)) {
                DB::table('tb_incoming_goods')->insert($rows);
            }

            DB::table('tb_purchases')->where('id', $purchaseId)->update([
                'total_price' => $totalPurchase,
                'updated_at' => $now,
            ]);
        });

        $productCount = $negativeRows->count();
        $totalQty = $negativeRows->sum(function ($row) {
            $net = (int) $row->total_in - (int) $row->total_out;
            return $net < 0 ? abs($net) : 0;
        });

        return redirect()
            ->route('inventory.index', ['store_id' => $storeId])
            ->with('success', "Normalisasi selesai: {$productCount} produk, total {$totalQty} unit ditambahkan.");
    }

    private function parseStockItems(Request $request, bool $allowEmpty = false): array
    {
        if ($request->boolean('use_session_items')) {
            $preview = $request->session()->get('inventory.stock_opname_preview');
            $previewItems = is_array($preview) ? ($preview['items'] ?? null) : null;
            if (!is_array($previewItems) || (empty($previewItems) && !$allowEmpty)) {
                throw new \InvalidArgumentException('Data preview tidak ditemukan atau sudah kedaluwarsa');
            }
            if (empty($previewItems)) {
                return [];
            }
            return array_values(array_map(function ($row) {
                if (is_object($row)) $row = (array)$row;
                return [
                    'product_id'        => (int)($row['product_id'] ?? 0),
                    'store_id'          => (int)($row['store_id'] ?? 0),
                    'physical_quantity' => $this->normalizePhysicalQuantity($row['physical_quantity'] ?? 0),
                ];
            }, $previewItems));
        }

        $items = $request->input('items');
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $items = $decoded;
            }
        }

        // Jika items tidak disediakan sama sekali (bukan array kosong), fallback ke input form.
        if ($items === null || $items === '') {
            $pids = (array)$request->input('product_id', []);
            $phys = (array)$request->input('physical_quantity', []);
            $sids = $request->input('store_id', []);
            Log::info('inventory.parseStockItems: items missing, fallback to form inputs', [
                'route' => optional($request->route())->getName(),
                'user_id' => auth()->id(),
                'store_id' => $request->input('store_id'),
                'product_id_count' => count($pids),
                'physical_quantity_count' => count($phys),
                'store_id_is_array' => is_array($sids),
            ]);
            if (!is_array($sids)) {
                $sids = $pids ? array_fill(0, count($pids), (int)$sids) : [];
            } else {
                $sids = (array)$sids;
            }
            if (count($pids) !== count($sids) || count($pids) !== count($phys)) {
                Log::warning('inventory.parseStockItems: payload invalid (form inputs)', [
                    'route' => optional($request->route())->getName(),
                    'user_id' => auth()->id(),
                    'store_id' => $request->input('store_id'),
                    'product_id_count' => count($pids),
                    'store_id_count' => count($sids),
                    'physical_quantity_count' => count($phys),
                ]);
                throw new \InvalidArgumentException('Payload tidak valid');
            }
            $items = [];
            foreach ($pids as $i => $pid) {
                $items[] = [
                    'product_id'        => (int)$pid,
                    'store_id'          => (int)($sids[$i] ?? 0),
                    'physical_quantity' => $this->normalizePhysicalQuantity($phys[$i] ?? 0),
                ];
            }
        }

        if (!is_array($items) || empty($items)) {
            if ($allowEmpty) {
                return [];
            }
            Log::warning('inventory.parseStockItems: payload invalid (items)', [
                'route' => optional($request->route())->getName(),
                'user_id' => auth()->id(),
                'store_id' => $request->input('store_id'),
                'items_type' => gettype($items),
                'items_count' => is_array($items) ? count($items) : null,
            ]);
            throw new \InvalidArgumentException('Payload tidak valid (items)');
        }

        return array_values(array_map(function ($row) {
            if (is_object($row)) $row = (array)$row;
            return [
                'product_id'        => (int)($row['product_id'] ?? 0),
                'store_id'          => (int)($row['store_id'] ?? 0),
                'physical_quantity' => $this->normalizePhysicalQuantity($row['physical_quantity'] ?? 0),
            ];
        }, $items));
    }

    private function buildStockSummary(array $items, int $storeId, ?int $totalItemsOverride = null): array
    {
        $now = now();
        $incomingDeletedSql = Schema::hasColumn('tb_incoming_goods', 'deleted_at')
            ? ' AND ig.deleted_at IS NULL'
            : '';
        $outgoingDeletedSql = Schema::hasColumn('tb_outgoing_goods', 'deleted_at')
            ? ' AND og.deleted_at IS NULL'
            : '';
        $incomingPendingSql = Schema::hasColumn('tb_incoming_goods', 'is_pending_stock')
            ? ' AND (ig.is_pending_stock IS NULL OR ig.is_pending_stock = 0)'
            : '';
        $outgoingPendingSql = Schema::hasColumn('tb_outgoing_goods', 'is_pending_stock')
            ? ' AND (og.is_pending_stock IS NULL OR og.is_pending_stock = 0)'
            : '';

        $storeRow = DB::table('tb_stores')
            ->select('store_name')
            ->where('id', $storeId)
            ->first();

        $summary = [
            'store_id' => $storeId,
            'store_name' => $storeRow ? $storeRow->store_name : null,
            'submitted_at' => $now->toDateTimeString(),
            'changes' => [],
            'total_items' => $totalItemsOverride ?? count($items),
            'changed_items' => 0,
            'total_minus_qty' => 0,
            'total_plus_qty' => 0,
            'total_minus_value' => 0,
            'total_plus_value' => 0,
        ];

        $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));
        if (empty($productIds)) {
            return $summary;
        }

        $ph = fn($n) => implode(',', array_fill(0, $n, '?'));

        $paramsIn = array_merge([$storeId], $productIds);
        $rowsIn = DB::select(
            'SELECT ig.product_id, SUM(ig.stock) AS total_in
               FROM tb_incoming_goods ig
               JOIN tb_purchases p ON ig.purchase_id = p.id
              WHERE p.store_id = ? AND ig.product_id IN ('.$ph(count($productIds)).')'.$incomingDeletedSql.$incomingPendingSql.'
           GROUP BY ig.product_id',
            $paramsIn
        );
        $incoming = [];
        foreach ($rowsIn as $r) { $incoming[(int)$r->product_id] = (int)$r->total_in; }

        $paramsOut = array_merge([$storeId], $productIds);
        $rowsOut = DB::select(
            'SELECT og.product_id, SUM(og.quantity_out) AS total_out
               FROM tb_outgoing_goods og
               JOIN tb_sells sl ON og.sell_id = sl.id
              WHERE sl.store_id = ? AND og.product_id IN ('.$ph(count($productIds)).')'.$outgoingDeletedSql.$outgoingPendingSql.'
           GROUP BY og.product_id',
            $paramsOut
        );
        $outgoing = [];
        foreach ($rowsOut as $r) { $outgoing[(int)$r->product_id] = (int)$r->total_out; }

        $rowsProduct = DB::select(
            'SELECT id, product_code, product_name
               FROM tb_products
              WHERE id IN ('.$ph(count($productIds)).')',
            $productIds
        );
        $products = [];
        foreach ($rowsProduct as $r) {
            $products[(int)$r->id] = [
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
            ];
        }

        $rowsPrice = DB::select(
            'SELECT p.id, COALESCE(sp.purchase_price, p.purchase_price) AS purchase_price
               FROM tb_products p
               LEFT JOIN tb_product_store_prices sp
                 ON sp.product_id = p.id AND sp.store_id = ?
              WHERE p.id IN ('.$ph(count($productIds)).')',
            array_merge([$storeId], $productIds)
        );
        $prices = [];
        foreach ($rowsPrice as $r) { $prices[(int)$r->id] = (int)$r->purchase_price; }

        foreach ($items as $it) {
            $pid  = (int)$it['product_id'];
            $phys = (int)$it['physical_quantity'];
            if ($pid <= 0) continue;

            $system = (int)($incoming[$pid] ?? 0) - (int)($outgoing[$pid] ?? 0);
            $this->assertReasonableStockAdjustment($pid, $system, $phys);
            $minus  = max(0, $system - $phys);
            $plus   = max(0, $phys - $system);
            $price  = (int)($prices[$pid] ?? 0);

            $summary['total_minus_qty'] += $minus;
            $summary['total_plus_qty'] += $plus;
            $summary['total_minus_value'] += $minus * $price;
            $summary['total_plus_value'] += $plus * $price;

            if ($minus > 0 || $plus > 0) {
                $product = $products[$pid] ?? ['product_code' => null, 'product_name' => 'Produk #'.$pid];
                $summary['changes'][] = [
                    'product_id' => $pid,
                    'product_code' => $product['product_code'],
                    'product_name' => $product['product_name'],
                    'system_stock' => $system,
                    'physical_quantity' => $phys,
                    'minus_qty' => $minus,
                    'plus_qty' => $plus,
                    'purchase_price' => $price,
                    'minus_value' => $minus * $price,
                    'plus_value' => $plus * $price,
                ];
                $summary['changed_items']++;
            }
        }

        $summary['net_value'] = $summary['total_plus_value'] - $summary['total_minus_value'];

        return $summary;
    }

    private function normalizePhysicalQuantity($value): int
    {
        $quantity = (int) $value;
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Jumlah fisik tidak boleh negatif');
        }
        if ($quantity > self::MAX_STOCK_OPNAME_QUANTITY) {
            throw new \InvalidArgumentException('Jumlah fisik terlalu besar: '.$quantity);
        }

        return $quantity;
    }

    private function assertReasonableStockAdjustment(int $productId, int $systemStock, int $physicalQuantity): void
    {
        $max = self::MAX_STOCK_OPNAME_QUANTITY;
        $adjustment = abs($physicalQuantity - $systemStock);

        if (abs($systemStock) > $max || $adjustment > $max) {
            throw new \InvalidArgumentException(
                "Stok produk {$productId} tidak wajar. Sistem={$systemStock}, fisik={$physicalQuantity}. Periksa data mutasi sebelum stock opname."
            );
        }
    }
}

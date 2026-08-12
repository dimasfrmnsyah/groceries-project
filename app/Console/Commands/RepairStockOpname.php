<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairStockOpname extends Command
{
    protected $signature = 'inventory:repair-stock-opname
        {--apply : Soft-delete batch stock opname yang jelas abnormal}
        {--threshold=10000 : Batas jumlah unit per baris yang dianggap abnormal}';

    protected $description = 'Audit dan karantina batch stock opname abnormal tanpa menghapus permanen';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $sellIds = DB::table('tb_sells as s')
            ->join('tb_outgoing_goods as og', 'og.sell_id', '=', 's.id')
            ->whereNull('s.deleted_at')
            ->whereNull('og.deleted_at')
            ->where('s.no_invoice', 'like', 'SO-ADJ-OUT-%')
            ->where('og.recorded_by', 'Stock Opname')
            ->where('og.quantity_out', '>', $threshold)
            ->pluck('s.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $purchaseIds = DB::table('tb_purchases as p')
            ->join('tb_suppliers as sp', 'sp.id', '=', 'p.supplier_id')
            ->join('tb_incoming_goods as ig', 'ig.purchase_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->whereNull('ig.deleted_at')
            ->where('sp.code', 'SO-ADJ')
            ->where('ig.description', 'Stock Opname (+)')
            ->where('ig.stock', '>', $threshold)
            ->pluck('p.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        // Satu proses opname bisa membuat pasangan nota keluar dan pembelian
        // pada timestamp yang sama. Karantina pasangan agar tidak menyisakan
        // setengah ledger.
        foreach ($sellIds->all() as $sellId) {
            $sell = DB::table('tb_sells')->where('id', $sellId)->first(['store_id', 'created_at']);
            if (!$sell) {
                continue;
            }
            $paired = DB::table('tb_purchases as p')
                ->join('tb_suppliers as sp', 'sp.id', '=', 'p.supplier_id')
                ->whereNull('p.deleted_at')
                ->where('sp.code', 'SO-ADJ')
                ->where('p.store_id', $sell->store_id)
                ->where('p.created_at', $sell->created_at)
                ->pluck('p.id');
            $purchaseIds = $purchaseIds->merge($paired);
        }

        foreach ($purchaseIds->unique()->values()->all() as $purchaseId) {
            $purchase = DB::table('tb_purchases')->where('id', $purchaseId)->first(['store_id', 'created_at']);
            if (!$purchase) {
                continue;
            }
            $paired = DB::table('tb_sells')
                ->whereNull('deleted_at')
                ->where('no_invoice', 'like', 'SO-ADJ-OUT-%')
                ->where('store_id', $purchase->store_id)
                ->where('created_at', $purchase->created_at)
                ->pluck('id');
            $sellIds = $sellIds->merge($paired);
        }

        $sellIds = $sellIds->unique()->values();
        $purchaseIds = $purchaseIds->unique()->values();
        $outgoing = $sellIds->isEmpty()
            ? ['lines' => 0, 'quantity' => 0]
            : [
                'lines' => DB::table('tb_outgoing_goods')->whereIn('sell_id', $sellIds)->whereNull('deleted_at')->count(),
                'quantity' => DB::table('tb_outgoing_goods')->whereIn('sell_id', $sellIds)->whereNull('deleted_at')->sum('quantity_out'),
            ];
        $incoming = $purchaseIds->isEmpty()
            ? ['lines' => 0, 'quantity' => 0]
            : [
                'lines' => DB::table('tb_incoming_goods')->whereIn('purchase_id', $purchaseIds)->whereNull('deleted_at')->count(),
                'quantity' => DB::table('tb_incoming_goods')->whereIn('purchase_id', $purchaseIds)->whereNull('deleted_at')->sum('stock'),
            ];

        $this->table(['Data', 'Jumlah'], [
            ['Batch nota keluar opname', $sellIds->count()],
            ['Baris keluar yang dikarantina', $outgoing['lines']],
            ['Unit keluar yang dikarantina', number_format((int) $outgoing['quantity'])],
            ['Batch pembelian opname', $purchaseIds->count()],
            ['Baris masuk yang dikarantina', $incoming['lines']],
            ['Unit masuk yang dikarantina', number_format((int) $incoming['quantity'])],
        ]);
        $this->line('Sell IDs: '.($sellIds->implode(', ') ?: '-'));
        $this->line('Purchase IDs: '.($purchaseIds->implode(', ') ?: '-'));

        if (!$this->option('apply')) {
            $this->warn('Mode audit saja. Tidak ada data yang diubah. Gunakan --apply setelah backup database.');
            return self::SUCCESS;
        }

        if ($sellIds->isEmpty() && $purchaseIds->isEmpty()) {
            $this->info('Tidak ada batch abnormal yang perlu dikarantina.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Soft-delete batch abnormal tersebut sekarang?')) {
            $this->info('Dibatalkan. Tidak ada data yang diubah.');
            return self::SUCCESS;
        }

        $now = now();
        DB::transaction(function () use ($sellIds, $purchaseIds, $now) {
            if ($sellIds->isNotEmpty()) {
                DB::table('tb_outgoing_goods')
                    ->whereIn('sell_id', $sellIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('tb_sells')
                    ->whereIn('id', $sellIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            if ($purchaseIds->isNotEmpty()) {
                DB::table('tb_incoming_goods')
                    ->whereIn('purchase_id', $purchaseIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('tb_purchases')
                    ->whereIn('id', $purchaseIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
        });

        $this->info('Batch abnormal berhasil dikarantina. Lakukan stock opname fisik ulang setelah verifikasi.');
        return self::SUCCESS;
    }
}

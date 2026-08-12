<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairStockOpname extends Command
{
    protected $signature = 'inventory:repair-stock-opname
        {--apply : Soft-delete baris stock opname yang jelas abnormal}
        {--threshold=10000 : Batas jumlah unit per baris yang dianggap abnormal}';

    protected $description = 'Audit dan karantina batch stock opname abnormal tanpa menghapus permanen';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('threshold'));
        $outgoingIds = DB::table('tb_sells as s')
            ->join('tb_outgoing_goods as og', 'og.sell_id', '=', 's.id')
            ->whereNull('s.deleted_at')
            ->whereNull('og.deleted_at')
            ->where('s.no_invoice', 'like', 'SO-ADJ-OUT-%')
            ->where('og.recorded_by', 'Stock Opname')
            ->where(function ($query) use ($threshold) {
                $query->where('og.quantity_out', '>', $threshold)
                    ->orWhere('og.quantity_out', '<', 0);
            })
            ->pluck('og.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $incomingIds = DB::table('tb_purchases as p')
            ->join('tb_suppliers as sp', 'sp.id', '=', 'p.supplier_id')
            ->join('tb_incoming_goods as ig', 'ig.purchase_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->whereNull('ig.deleted_at')
            ->where('sp.code', 'SO-ADJ')
            ->where('ig.description', 'Stock Opname (+)')
            ->where(function ($query) use ($threshold) {
                $query->where('ig.stock', '>', $threshold)
                    ->orWhere('ig.stock', '<', 0);
            })
            ->pluck('ig.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $sellIds = $outgoingIds->isEmpty()
            ? collect()
            : DB::table('tb_outgoing_goods')
                ->whereIn('id', $outgoingIds)
                ->pluck('sell_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        $purchaseIds = $incomingIds->isEmpty()
            ? collect()
            : DB::table('tb_incoming_goods')
                ->whereIn('id', $incomingIds)
                ->pluck('purchase_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

        $outgoing = $outgoingIds->isEmpty()
            ? ['lines' => 0, 'quantity' => 0]
            : [
                'lines' => $outgoingIds->count(),
                'quantity' => DB::table('tb_outgoing_goods')->whereIn('id', $outgoingIds)->sum('quantity_out'),
            ];
        $incoming = $incomingIds->isEmpty()
            ? ['lines' => 0, 'quantity' => 0]
            : [
                'lines' => $incomingIds->count(),
                'quantity' => DB::table('tb_incoming_goods')->whereIn('id', $incomingIds)->sum('stock'),
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
        DB::transaction(function () use ($outgoingIds, $incomingIds, $sellIds, $purchaseIds, $now) {
            if ($outgoingIds->isNotEmpty()) {
                DB::table('tb_outgoing_goods')
                    ->whereIn('id', $outgoingIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            if ($incomingIds->isNotEmpty()) {
                DB::table('tb_incoming_goods')
                    ->whereIn('id', $incomingIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }

            // Jangan menghapus parent yang masih memiliki detail normal.
            // Parent hanya dikarantina jika seluruh movement aktifnya sudah kosong.
            if ($sellIds->isNotEmpty()) {
                $emptySellIds = DB::table('tb_sells as s')
                    ->whereIn('s.id', $sellIds)
                    ->whereNull('s.deleted_at')
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('tb_outgoing_goods as og')
                            ->whereColumn('og.sell_id', 's.id')
                            ->whereNull('og.deleted_at');
                    })
                    ->pluck('s.id');
                DB::table('tb_sells')
                    ->whereIn('id', $emptySellIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            if ($purchaseIds->isNotEmpty()) {
                $emptyPurchaseIds = DB::table('tb_purchases as p')
                    ->whereIn('p.id', $purchaseIds)
                    ->whereNull('p.deleted_at')
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('tb_incoming_goods as ig')
                            ->whereColumn('ig.purchase_id', 'p.id')
                            ->whereNull('ig.deleted_at');
                    })
                    ->pluck('p.id');
                DB::table('tb_purchases')
                    ->whereIn('id', $emptyPurchaseIds)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
        });

        $this->info('Batch abnormal berhasil dikarantina. Lakukan stock opname fisik ulang setelah verifikasi.');
        return self::SUCCESS;
    }
}

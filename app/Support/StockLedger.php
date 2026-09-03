<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

final class StockLedger
{
    /**
     * Nilai maksimum satu mutasi stok yang dianggap valid untuk operasional.
     *
     * Nilai di luar rentang ini tetap tersimpan untuk audit, tetapi tidak ikut
     * menghitung saldo, PO otomatis, atau validasi stok transaksi.
     */
    public const MAX_MOVEMENT_QUANTITY = 100000;

    /**
     * Hanya movement yang sudah aktif yang mengurangi saldo stok.
     *
     * Penjualan yang dibuat saat toko offline tetap disimpan sebagai audit,
     * tetapi belum masuk saldo sampai proses online/sinkronisasi mengubah
     * is_pending_stock menjadi 0.
     */
    public static function applyOutgoingBalanceFilter(
        Builder $query,
        bool $hasPendingStock,
        string $outgoingAlias = 'og',
        string $sellAlias = 's'
    ): Builder {
        if (!$hasPendingStock) {
            return $query;
        }

        // Data lama tanpa flag pending tetap dihitung karena nilainya NULL.
        // Data baru/offline dengan flag 1 baru dihitung setelah disinkronkan.
        return $query->where(function ($scope) use ($outgoingAlias) {
            $scope->whereNull($outgoingAlias.'.is_pending_stock')
                ->orWhere($outgoingAlias.'.is_pending_stock', 0);
        });
    }

    /**
     * Hanya hitung nota yang seluruh movement stoknya sudah diposting.
     * Header penjualan tetap disimpan saat offline untuk audit, tetapi tidak
     * masuk omzet operasional sampai toko kembali online.
     */
    public static function applyFinalizedSaleFilter(
        Builder $query,
        bool $hasPendingStock,
        string $sellAlias = 's',
        bool $hasOutgoingDeleted = false
    ): Builder {
        if (!$hasPendingStock) {
            return $query;
        }

        return $query->whereNotExists(function ($pending) use ($sellAlias, $hasOutgoingDeleted) {
            $pending->from('tb_outgoing_goods as pending_sale')
                ->whereColumn('pending_sale.sell_id', $sellAlias.'.id')
                ->where('pending_sale.is_pending_stock', 1)
                ->when($hasOutgoingDeleted, fn ($q) => $q->whereNull('pending_sale.deleted_at'));
        });
    }
}

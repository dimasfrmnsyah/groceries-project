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
     * Penjualan normal selalu mengurangi saldo stok, termasuk penjualan lama
     * yang pernah tersimpan sebagai pending saat toko offline. Pending tetap
     * dipertahankan hanya untuk movement yang bukan penjualan normal.
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

        $hasSourceType = Schema::hasColumn('tb_outgoing_goods', 'source_type');
        $hasInvoice = Schema::hasColumn('tb_sells', 'no_invoice');
        $pendingColumn = $outgoingAlias.'.is_pending_stock';
        $sourceColumn = $outgoingAlias.'.source_type';
        $invoiceColumn = $sellAlias.'.no_invoice';

        return $query->where(function ($scope) use (
            $pendingColumn,
            $outgoingAlias,
            $hasSourceType,
            $hasInvoice,
            $sourceColumn,
            $invoiceColumn
        ) {
            $scope->whereNull($pendingColumn)->orWhere($pendingColumn, 0);

            // Kompatibilitas data lama: sebelum source_type/is_pending_stock
            // dipakai, penjualan normal dibedakan dari SO/AR/transfer melalui
            // nomor invoice dan actor pencatat. Source type yang sudah eksplisit
            // harus menjadi sumber kebenaran agar movement non-penjualan tidak
            // ikut mengurangi stok hanya karena invoice-nya tidak standar.
            $scope->orWhere(function ($sale) use (
                $outgoingAlias,
                $hasSourceType,
                $hasInvoice,
                $sourceColumn,
                $invoiceColumn
            ) {
                if ($hasSourceType) {
                    $sale->where(function ($typed) use (
                        $outgoingAlias,
                        $hasInvoice,
                        $sourceColumn,
                        $invoiceColumn
                    ) {
                        $typed->where($sourceColumn, 'sale')
                            ->orWhere(function ($legacy) use (
                                $outgoingAlias,
                                $hasInvoice,
                                $sourceColumn,
                                $invoiceColumn
                            ) {
                                $legacy->whereNull($sourceColumn);
                                self::applyLegacySaleIdentity(
                                    $legacy,
                                    $outgoingAlias,
                                    $hasInvoice,
                                    $invoiceColumn
                                );
                            });
                    });
                    return;
                }

                self::applyLegacySaleIdentity($sale, $outgoingAlias, $hasInvoice, $invoiceColumn);
            });
        });
    }

    private static function applyLegacySaleIdentity(
        Builder $query,
        string $outgoingAlias,
        bool $hasInvoice,
        string $invoiceColumn
    ): void {
        $notStockOpname = function ($actor) use ($outgoingAlias) {
            $actor->whereRaw(
                'LOWER(TRIM(COALESCE('.$outgoingAlias.'.recorded_by, ""))) <> ?',
                ['stock opname']
            );
        };

        if ($hasInvoice) {
            $query->where(function ($invoice) use ($invoiceColumn, $notStockOpname) {
                $invoice->whereNull($invoiceColumn)
                    ->orWhere(function ($normal) use ($invoiceColumn) {
                        $normal->where($invoiceColumn, 'not like', 'SO-ADJ-%')
                            ->where($invoiceColumn, 'not like', 'AR-%')
                            ->where($invoiceColumn, 'not like', 'TRF-%');
                    });
            });
        }

        $notStockOpname($query);
    }
}

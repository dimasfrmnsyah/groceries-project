<?php

namespace App\Support;

final class StockLedger
{
    /**
     * Nilai maksimum satu mutasi stok yang dianggap valid untuk operasional.
     *
     * Nilai di luar rentang ini tetap tersimpan untuk audit, tetapi tidak ikut
     * menghitung saldo, PO otomatis, atau validasi stok transaksi.
     */
    public const MAX_MOVEMENT_QUANTITY = 100000;
}

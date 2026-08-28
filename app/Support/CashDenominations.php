<?php

namespace App\Support;

final class CashDenominations
{
    /**
     * Daftar pecahan bersama untuk cash opname dan setoran/logout kasir.
     * Jangan duplikasi daftar ini di view atau controller lain.
     */
    public static function all(): array
    {
        return [
            'note_100000' => ['label' => 'Rp 100.000', 'value' => 100000, 'kind' => 'Uang kertas'],
            'note_50000' => ['label' => 'Rp 50.000', 'value' => 50000, 'kind' => 'Uang kertas'],
            'note_20000' => ['label' => 'Rp 20.000', 'value' => 20000, 'kind' => 'Uang kertas'],
            'note_10000' => ['label' => 'Rp 10.000', 'value' => 10000, 'kind' => 'Uang kertas'],
            'note_5000' => ['label' => 'Rp 5.000', 'value' => 5000, 'kind' => 'Uang kertas'],
            'note_2000' => ['label' => 'Rp 2.000', 'value' => 2000, 'kind' => 'Uang kertas'],
            'note_1000' => ['label' => 'Rp 1.000', 'value' => 1000, 'kind' => 'Uang kertas'],
            'coin_1000' => ['label' => 'Rp 1.000', 'value' => 1000, 'kind' => 'Uang logam'],
            'coin_500' => ['label' => 'Rp 500', 'value' => 500, 'kind' => 'Uang logam'],
            'coin_200' => ['label' => 'Rp 200', 'value' => 200, 'kind' => 'Uang logam'],
            'coin_100' => ['label' => 'Rp 100', 'value' => 100, 'kind' => 'Uang logam'],
        ];
    }
}

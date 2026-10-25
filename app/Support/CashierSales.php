<?php

namespace App\Support;

use App\Models\tb_outgoing_goods;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CashierSales
{
    public static function currentDate(string $userId, ?int $storeId): string
    {
        $now = now('UTC');
        if ($storeId && Schema::hasTable('attendances')) {
            $start = DB::table('attendances')
                ->where('user_id', $userId)->where('store_id', $storeId)
                ->whereNull('checked_out_at')->where('checked_in_at', '<=', $now)
                ->orderByDesc('checked_in_at')->value('checked_in_at');
            if ($start) {
                return Carbon::parse($start, 'UTC')->setTimezone('Asia/Jakarta')->toDateString();
            }
        }
        return $now->setTimezone('Asia/Jakarta')->toDateString();
    }

    public static function qrisTotal(string $userId, string $cashierName, ?int $storeId, string $date): ?float
    {
        if (!Schema::hasColumn('tb_sells', 'payment_method')) {
            return null;
        }
        $sales = self::query($userId, $cashierName, $storeId, $date)
            ->where('s.payment_method', 'qris')->select('s.id', 's.total_price')->distinct();
        return (float) DB::query()->fromSub($sales, 'qris_sales')->sum('total_price');
    }

    public static function query(string $userId, string $cashierName, ?int $storeId, string $date): Builder
    {
        $hasSellerIdColumn = Schema::hasColumn('tb_sells', 'seller_id');
        $hasInvoiceColumn = Schema::hasColumn('tb_sells', 'no_invoice');
        $hasSellCreatedByColumn = Schema::hasColumn('tb_sells', 'created_by');
        $hasOutgoingCreatedByColumn = Schema::hasColumn('tb_outgoing_goods', 'created_by');

        return tb_outgoing_goods::query()
            ->joinSub(SalesReportDate::query(), 's', 's.id', '=', 'tb_outgoing_goods.sell_id')
            ->where('s.report_date', $date)
            ->whereBetween('tb_outgoing_goods.quantity_out', [0, StockLedger::MAX_MOVEMENT_QUANTITY])
            ->when(Schema::hasColumn('tb_sells', 'deleted_at'), fn ($q) => $q->whereNull('s.deleted_at'))
            ->where('s.store_id', $storeId ?: 0)
            ->where(function ($identity) use ($cashierName, $userId, $hasSellCreatedByColumn, $hasOutgoingCreatedByColumn) {
                // Names are a legacy fallback only. A known different user ID wins.
                $matchMovement = function ($movement) use ($cashierName, $userId, $hasOutgoingCreatedByColumn) {
                    $matchName = function ($name) use ($cashierName) {
                        $name->whereRaw('LOWER(TRIM(COALESCE(tb_outgoing_goods.recorded_by, ""))) = ?', [strtolower($cashierName)])
                            ->whereRaw('? <> ?', [$cashierName, '']);
                    };
                    if ($hasOutgoingCreatedByColumn) {
                        $movement->where('tb_outgoing_goods.created_by', $userId)
                            ->orWhere(function ($legacy) use ($matchName) {
                                $legacy->whereRaw("NULLIF(TRIM(tb_outgoing_goods.created_by), '') IS NULL")
                                    ->where($matchName);
                            });
                    } else {
                        $movement->where($matchName);
                    }
                };
                if ($hasSellCreatedByColumn) {
                    $identity->where('s.created_by', $userId)
                        ->orWhere(function ($legacy) use ($matchMovement) {
                            $legacy->whereRaw("NULLIF(TRIM(s.created_by), '') IS NULL")
                                ->where($matchMovement);
                        });
                } else {
                    $identity->where($matchMovement);
                }
            })
            ->when($hasSellerIdColumn, fn ($q) => $q->where(fn ($scope) => $scope->whereNull('s.seller_id')->orWhere('s.seller_id', '!=', 1)))
            ->when($hasInvoiceColumn, function ($q) {
                $q->where(function ($scope) {
                    $scope->whereNull('s.no_invoice')->orWhere(function ($invoice) {
                        $invoice->where('s.no_invoice', 'not like', 'SO-ADJ-%')
                            ->where('s.no_invoice', 'not like', 'AR-%')
                            ->where('s.no_invoice', 'not like', 'TRF-%');
                    });
                });
            });

    }
}

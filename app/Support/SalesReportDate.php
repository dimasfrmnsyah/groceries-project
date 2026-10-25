<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesReportDate
{
    /**
     * Keep invoice dates intact; only cashier sales reports use the shift's WIB date.
     * Match immutable user IDs and the sale's store, never a cashier's display name.
     */
    public static function query(): Builder
    {
        $date = 'DATE(report_sale.date)';
        if (Schema::hasTable('attendances')
            && Schema::hasColumn('tb_sells', 'created_by')
            && Schema::hasColumn('tb_sells', 'created_at')) {
            // Both attendance and Eloquent creation timestamps are stored in UTC.
            $shiftDate = DB::connection()->getDriverName() === 'sqlite'
                ? "DATE(report_shift.checked_in_at, '+7 hours')"
                : 'DATE(DATE_ADD(report_shift.checked_in_at, INTERVAL 7 HOUR))';
            $date = "COALESCE((
                SELECT $shiftDate FROM attendances AS report_shift
                WHERE report_shift.user_id = report_sale.created_by
                    AND report_shift.store_id = report_sale.store_id
                    AND report_shift.checked_in_at <= report_sale.created_at
                    AND (report_shift.checked_out_at IS NULL
                        OR report_sale.created_at < report_shift.checked_out_at)
                ORDER BY report_shift.checked_in_at DESC, report_shift.id DESC
                LIMIT 1
            ), $date)";
        }

        return DB::table('tb_sells as report_sale')
            ->select('report_sale.*')
            ->selectRaw("$date as report_date");
    }
}

<?php

namespace App\Support;

use App\Models\Attendance;
use Illuminate\Database\Eloquent\Builder;

class AttendanceDailyReport
{
    public static function query(Builder $sessions): Builder
    {
        // Stored timestamps are UTC. WIB is UTC+7 and has no daylight saving.
        $date = $sessions->getConnection()->getDriverName() === 'sqlite'
            ? "date(checked_in_at, '+7 hours')"
            : 'DATE(DATE_ADD(checked_in_at, INTERVAL 7 HOUR))';
        $days = (clone $sessions)->reorder()->select('user_id', 'store_id')
            ->selectRaw($date . ' as attendance_date, MAX(employee_name) as employee_name, '
                . 'COUNT(*) as session_count, MIN(checked_in_at) as checked_in_at, '
                . 'SUM(CASE WHEN checked_out_at IS NULL THEN 1 ELSE 0 END) as open_count, '
                . 'CASE WHEN COUNT(checked_out_at) < COUNT(*) THEN NULL ELSE MAX(checked_out_at) END as checked_out_at, '
                . 'SUM(duration_seconds) as duration_seconds')
            ->groupBy('user_id', 'store_id')->groupByRaw($date);

        return Attendance::query()->fromSub($days, 'attendance_days')->select('attendance_days.*')
            ->selectRaw('CASE WHEN duration_seconds > 28800 THEN 28800 ELSE duration_seconds END as normal_seconds, '
                . 'CASE WHEN duration_seconds IS NULL THEN NULL WHEN duration_seconds > 28800 THEN duration_seconds - 28800 ELSE 0 END as overtime_seconds');
    }
}

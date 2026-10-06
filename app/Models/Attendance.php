<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    public const NORMAL_SECONDS = 8 * 60 * 60;
    public const TIMEZONE = 'Asia/Jakarta';
    public const CASHIER_ROLES = ['staff', 'kasir', 'cashier'];

    protected $guarded = ['id'];

    protected $casts = [
        'duration_seconds' => 'integer',
        'normal_seconds' => 'integer',
        'overtime_seconds' => 'integer',
    ];

    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        return sprintf('%d jam %02d menit %02d detik', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    public function localTime(string $column): ?string
    {
        return $this->$column
            ? CarbonImmutable::parse($this->$column, 'UTC')->setTimezone(self::TIMEZONE)->format('d/m/Y H:i:s') . ' WIB'
            : null;
    }

    public function description(): string
    {
        if (!$this->checked_out_at) {
            return 'Belum absen keluar';
        }
        if ($this->overtime_seconds > 0) {
            return 'Lembur';
        }
        return $this->duration_seconds < self::NORMAL_SECONDS ? 'Kurang dari 8 jam' : 'Normal';
    }

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'checked_in_at' => $this->localTime('checked_in_at'),
            'checked_out_at' => $this->localTime('checked_out_at'),
            'duration_seconds' => $this->duration_seconds,
            'normal_seconds' => $this->normal_seconds,
            'overtime_seconds' => $this->overtime_seconds,
            'duration' => self::formatDuration($this->duration_seconds),
            'normal' => self::formatDuration($this->normal_seconds),
            'overtime' => self::formatDuration($this->overtime_seconds),
            'status' => $this->description(),
        ];
    }
}

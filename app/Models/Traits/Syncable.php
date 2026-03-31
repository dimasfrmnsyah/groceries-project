<?php
namespace App\Models\Traits;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait Syncable
{
    public static function bootSyncable()
    {
        // Pastikan semua model punya uuid saat create
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

        // Logger adaptif: aman untuk segala bentuk tabel sync_changes
        $log = function ($model, string $op) {
            if (!config('sync.enabled', false)) {
                return;
            }

            // Jika tabel sync_changes belum ada, diamkan saja
            if (!Schema::hasTable('sync_changes')) {
                return;
            }

            // Deteksi kolom sync_changes sekali
            $cols = [];
            try {
                $cols = Schema::getColumnListing('sync_changes');
            } catch (\Throwable $e) {
                return;
            }

            // Pastikan ada uuid untuk row; gunakan fallback untuk log jika model belum terisi
            $rowUuid = $model->uuid ?: (string) Str::uuid();
            if (empty($model->uuid)) {
                $model->uuid = $rowUuid;
            }

            // Build data minimal
            $data = [
                'table'    => $model->getTable(),
                'row_uuid' => $rowUuid,
            ];

            if (in_array('changed_at', $cols, true)) {
                $data['changed_at'] = now();
            }

            // Kolom aksi yang tersedia (op atau action)
            if (in_array('op', $cols, true)) {
                $data['op'] = $op;
            } elseif (in_array('action', $cols, true)) {
                $data['action'] = $op;
            }

            // Payload hanya jika kolomnya ada
            if (in_array('payload', $cols, true) && $op === 'upsert') {
                $payload = $model->toArray();
                // pastikan string JSON
                $data['payload'] = is_string($payload)
                    ? $payload
                    : json_encode($payload, JSON_UNESCAPED_UNICODE);
            }

            // Device id jika kolomnya ada
            if (in_array('device_id', $cols, true)) {
                $data['device_id'] = request()->header('X-Device-Id');
            }

            // Insert aman via Query Builder
            DB::table('sync_changes')->insert($data);
        };

        static::created(fn ($m) => $log($m, 'upsert'));
        static::updated(fn ($m) => $log($m, 'upsert'));
        static::deleted(fn ($m) => $log($m, 'delete'));
        static::restored(fn ($m) => $log($m, 'upsert')); // soft delete dibatalkan
    }
}

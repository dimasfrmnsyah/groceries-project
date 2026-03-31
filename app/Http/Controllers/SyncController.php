<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\SyncService;
class SyncController extends Controller
{
    protected function ensureSyncEnabled()
    {
        abort_unless(config('sync.enabled', false), 404);
    }

    // ========= FULL EXPORT: dump semua baris per tabel (paginated) =========
    public function export(Request $req)
    {
        $this->ensureSyncEnabled();

        $tables = array_values(array_filter(array_map('trim', explode(',', (string) $req->query('tables', '')))));
        $limit  = max(1, min((int) $req->query('limit', 5000), 20000));
        $offset = max(0, (int) $req->query('offset', 0));

        if (empty($tables)) {
            return response()->json(['error'=>true,'message'=>'tables parameter required'], 400);
        }

        $data = [];
        $total = 0;

        foreach ($tables as $tbl) {
            if (!Schema::hasTable($tbl) || !Schema::hasColumn($tbl, 'uuid')) {
                $data[$tbl] = ['count'=>0, 'rows'=>[]];
                continue;
            }

            $rows = DB::table($tbl)
                ->orderBy('id') // atau orderBy('uuid') bila tak ada id
                ->limit($limit)
                ->offset($offset)
                ->get()
                ->map(fn($r)=>(array)$r)
                ->all();

            $cnt = count($rows);
            $total += $cnt;
            $data[$tbl] = ['count'=>$cnt, 'rows'=>$rows];
        }

        return response()->json([
            'tables'      => $tables,
            'limit'       => $limit,
            'offset'      => $offset,
            'next_offset' => $total > 0 ? $offset + $limit : null,
            'data'        => $data,
        ]);
    }

    // ========= PULL: baca change log; jika tak ada action/op → asumsikan upsert =========
    public function pull(Request $req)
    {
        $this->ensureSyncEnabled();

        $since  = $req->query('since');
        $cursor = (int) $req->query('cursor', 0);
        $tables = $req->query('tables');
        $limit  = min((int) $req->query('limit', 10000), 50000);

        $ts = $since ? Carbon::parse($since) : null;

        $q = DB::table('sync_changes')
            ->when($tables, fn($qq) => $qq->whereIn('table', array_map('trim', explode(',', $tables))))
            ->when($ts, function ($qq) use ($ts, $cursor) {
                $qq->where(function ($w) use ($ts, $cursor) {
                    $w->where('changed_at','>',$ts)
                      ->orWhere(function ($eq) use ($ts, $cursor) {
                          $eq->where('changed_at','=',$ts);
                          if ($cursor > 0) $eq->where('id','>', $cursor);
                      });
                });
            })
            ->orderBy('changed_at')->orderBy('id')
            ->limit($limit);

        $changes = $q->get();

        // deteksi sekali: apakah ada kolom action/op?
        $cols = [];
        try { $cols = Schema::getColumnListing('sync_changes'); } catch (\Throwable $e) {}
        $hasAction = in_array('action', $cols, true);
        $hasOp     = in_array('op',     $cols, true);

        $out = [];
        foreach ($changes as $c) {
            $tbl  = $c->table;
            $uuid = $c->row_uuid;

            // kalau tak ada kolom aksi, default 'upsert'
            $op = 'upsert';
            if ($hasAction && isset($c->action) && $c->action) $op = $c->action;
            elseif ($hasOp && isset($c->op) && $c->op)         $op = $c->op;

            $payload = [];
            if ($op !== 'delete' && Schema::hasTable($tbl) && Schema::hasColumn($tbl,'uuid')) {
                $row = DB::table($tbl)->where('uuid', $uuid)->first();
                if ($row) $payload = (array) $row;
            }

            $out[$tbl][] = [
                'op'       => $op,
                'row_uuid' => $uuid,
                'payload'  => $payload,
                'at'       => optional($c->changed_at)->toIso8601String(),
            ];
        }

        $last = $changes->last();

        return response()->json([
            'since'       => $since,
            'cursor'      => $cursor,
            'count'       => $changes->count(),
            'changes'     => $out,
            'next_since'  => optional($last)->changed_at?->toIso8601String(),
            'next_cursor' => optional($last)->id,
        ]);
    }

    // ========= PUSH: upsert/delete; CATAT LOG TANPA kolom action/op =========
    public function push(Request $req)
    {
        $this->ensureSyncEnabled();

        $deviceId = $req->header('X-Device-Id');
        $ops = $req->input('operations', []);

        $applied = [];
        $rejected = [];

        DB::beginTransaction();
        try {
            foreach ($ops as $op) {
                $operationId = $op['operation_id'] ?? null;
                $table = $op['table'] ?? null;
                $row   = $op['row'] ?? [];
                $verb  = $op['op'] ?? 'upsert';

                if (!$operationId || !$table) {
                    $rejected[] = ['op'=>$op,'reason'=>'invalid_payload'];
                    continue;
                }

                if (DB::table('sync_operations')->where('operation_id',$operationId)->exists()) {
                    $applied[] = ['operation_id'=>$operationId,'status'=>'duplicate_ignored'];
                    continue;
                }

                if (!Schema::hasTable($table)) {
                    $rejected[] = ['op'=>$op,'reason'=>'unknown_table','table'=>$table];
                    continue;
                }
                if (!Schema::hasColumn($table,'uuid')) {
                    $rejected[] = ['op'=>$op,'reason'=>'missing_uuid_column','table'=>$table];
                    continue;
                }

                $uuid = $row['uuid'] ?? null;
                if (!$uuid) {
                    $rejected[] = ['op'=>$op,'reason'=>'missing_uuid','table'=>$table];
                    continue;
                }

                if ($verb === 'delete') {
                    if (Schema::hasColumn($table,'deleted_at')) {
                        DB::table($table)->where('uuid',$uuid)->update(['deleted_at'=>now()]);
                    } else {
                        DB::table($table)->where('uuid',$uuid)->delete();
                    }
                    $this->appendChangeNoAction($table, $uuid);
                } else {
                    unset($row['id']);
                    foreach (['created_at','updated_at','deleted_at'] as $ts) {
                        if (!empty($row[$ts])) {
                            try { $row[$ts]=Carbon::parse($row[$ts])->format('Y-m-d H:i:s'); } catch (\Throwable $e) {}
                        }
                    }
                    try {
                        $tcols = Schema::getColumnListing($table);
                        $row = array_intersect_key($row, array_flip($tcols));
                    } catch (\Throwable $e) {}

                    $row['uuid'] = $uuid;
                    DB::table($table)->updateOrInsert(['uuid'=>$uuid], $row);

                    $this->appendChangeNoAction($table, $uuid);
                }

                DB::table('sync_operations')->insert([
                    'operation_id' => $operationId,
                    'table'        => $table,
                    'row_uuid'     => $uuid,
                    'device_id'    => $deviceId,
                    'applied_at'   => now(),
                    'result'       => json_encode(['status'=>'ok']),
                ]);

                $applied[] = ['operation_id'=>$operationId,'status'=>'ok'];
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error'=>true,'message'=>$e->getMessage()], 500);
        }

        return response()->json(['applied'=>$applied,'rejected'=>$rejected]);
    }

    // Catat change log TANPA kolom action/op (aman utk semua skema lama)
    protected function appendChangeNoAction(string $tableName, string $rowUuid): void
    {
        DB::table('sync_changes')->insert([
            'table'      => $tableName,
            'row_uuid'   => $rowUuid,
            'changed_at' => now(),
        ]);
    }
    public function manual(SyncService $sync)
    {
        $this->ensureSyncEnabled();

        $sum = $sync->runFullResync();
        return back()->with('status', "Sinkronisasi selesai. Pulled: {$sum['pulled']}, Pushed: {$sum['pushed']}");
    }
}

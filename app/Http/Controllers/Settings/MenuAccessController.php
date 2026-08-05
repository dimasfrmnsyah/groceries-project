<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MenuAccessController extends Controller
{
    public function index(Request $request)
    {
        $roles = $this->roles();
        $currentRole = strtolower(trim((string) $request->query('role', 'superadmin')));
        if (!in_array($currentRole, $roles->all(), true)) {
            $currentRole = $roles->first() ?? 'superadmin';
        }

        $allowedIds = DB::table('tb_master_menu_roles')
            ->whereRaw('LOWER(TRIM(role_name)) = ?', [$currentRole])
            ->pluck('menu_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $allowSet = array_flip($allowedIds);

        $all = DB::table('tb_master_menuses')
            ->where('is_active', 1)
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        $build = function ($pid) use (&$build, $all) {
            $items = $all[$pid] ?? collect();
            return $items->map(function ($m) use ($build) {
                return (object) [
                    'id'       => (int) $m->id,
                    'name'     => $m->menu_name,
                    'path'     => $m->menu_path,
                    'icon'     => $m->menu_icon,
                    'children' => $build($m->id),
                ];
            });
        };

        return view('settings.access.index', [
            'roles'       => $roles,
            'currentRole' => $currentRole,
            'nodes'       => $build(null),
            'allowSet'    => $allowSet,
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'role_name' => ['required', 'string'],
            'menu'      => ['array'],
        ]);

        $role = strtolower(trim($data['role_name']));
        if (!in_array($role, $this->roles()->all(), true)) {
            return back()->withErrors('Role tidak dikenal.');
        }

        if ($role === 'superadmin') {
            $allMenuIds = DB::table('tb_master_menuses')
                ->where('is_active', 1)
                ->pluck('id')
                ->toArray();

            DB::transaction(function () use ($allMenuIds, $role) {
                DB::table('tb_master_menu_roles')
                    ->whereRaw('LOWER(role_name)=?', [$role])
                    ->delete();

                if ($allMenuIds) {
                    $now  = now();
                    $rows = array_map(fn ($id) => [
                        'menu_id'    => $id,
                        'role_name'  => $role,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $allMenuIds);

                    DB::table('tb_master_menu_roles')->insert($rows);
                }
            });
            Cache::forget('menu_allowed_routes:'.$role);

            return redirect()
                ->route('settings.access.index', ['role' => $role])
                ->with('ok', 'Superadmin selalu memiliki akses penuh.');
        }

        $selectedIds = array_map('intval', array_keys($data['menu'] ?? []));
        $menuIds     = $this->expandWithChildren($selectedIds);

        DB::transaction(function () use ($role, $menuIds) {
            DB::table('tb_master_menu_roles')
                ->whereRaw('LOWER(role_name)=?', [$role])
                ->delete();

            if ($menuIds) {
                $now  = now();
                $rows = array_map(fn ($id) => [
                    'menu_id'    => $id,
                    'role_name'  => $role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $menuIds);

                DB::table('tb_master_menu_roles')->insert($rows);
            }
        });
        Cache::forget('menu_allowed_routes:'.$role);

        return redirect()
            ->route('settings.access.index', ['role' => $role])
            ->with('ok', 'Akses berhasil disimpan untuk role: ' . $role);
    }

    private function expandWithChildren(array $selectedIds): array
    {
        if (!$selectedIds) {
            return [];
        }

        $childrenMap = DB::table('tb_master_menuses')
            ->where('is_active', 1)
            ->select('id', 'parent_id')
            ->get()
            ->groupBy('parent_id');

        $stack  = $selectedIds;
        $result = [];

        while ($stack) {
            $id = array_pop($stack);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = true;

            foreach ($childrenMap[$id] ?? [] as $child) {
                $stack[] = (int) $child->id;
            }
        }

        return array_keys($result);
    }

    private function roles()
    {
        $roles = DB::table('tb_master_roles')
            ->where('is_active', 1)
            ->orderBy('role_name')
            ->pluck('role_name')
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->filter()
            ->unique()
            ->values();

        if (!$roles->contains('superadmin')) {
            $roles->prepend('superadmin');
        }

        return $roles;
    }
}

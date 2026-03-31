<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class MenuHelper
{
    public static function firstAllowedRouteFor(?string $roleName = null): ?string
    {
        foreach (self::allowedRouteNames($roleName) as $routeName) {
            if ($routeName && Route::has($routeName)) {
                return $routeName;
            }
        }

        return null;
    }

    public static function roleHasRoute(string $routeName, ?string $roleName = null): bool
    {
        $routeName = trim($routeName);
        if ($routeName === '') return false;

        $role = strtolower(trim((string) ($roleName ?: (Auth::user()->roles ?? ''))));
        if ($role === '') return false;

        return in_array($routeName, self::allowedRouteNames($role), true);
    }

    public static function activeRouteNames(): array
    {
        return Cache::remember('menu_active_routes', now()->addMinutes(10), function () {
            return DB::table('tb_master_menuses')
                ->where('is_active', 1)
                ->whereNotNull('menu_path')
                ->where('menu_path', '<>', '')
                ->pluck('menu_path')
                ->filter()
                ->map(fn ($route) => trim((string) $route))
                ->unique()
                ->values()
                ->all();
        });
    }

    private static function allowedRouteNames(?string $roleName = null): array
    {
        $role = strtolower(trim((string) ($roleName ?: (Auth::user()->roles ?? ''))));
        if ($role === '') {
            return [];
        }

        return Cache::remember('menu_allowed_routes:'.$role, now()->addMinutes(10), function () use ($role) {
            $q = DB::table('tb_master_menuses as m')
                ->join('tb_master_menu_roles as r', 'r.menu_id', '=', 'm.id')
                ->whereRaw('LOWER(TRIM(r.role_name)) = ?', [$role])
                ->where('m.is_active', 1)
                ->whereNotNull('m.menu_path')
                ->where('m.menu_path', '<>', '');

            if (Schema::hasColumn('tb_master_menuses', 'sort')) {
                $q->orderBy('m.sort')->orderBy('m.id');
            } else {
                $q->orderBy('m.id');
            }

            return $q->pluck('m.menu_path')
                ->filter()
                ->map(fn ($route) => trim((string) $route))
                ->unique()
                ->values()
                ->all();
        });
    }
}

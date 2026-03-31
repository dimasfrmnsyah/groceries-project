<?php

namespace App\Http\Middleware;

use App\Support\MenuHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class MenuAccessMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $roleName = trim(strtolower((string) ($user->roles ?? '')));
        if ($roleName === 'superadmin') {
            return $next($request);
        }

        $routeName = optional($request->route())->getName();
        if (!$routeName) {
            return $next($request);
        }

        if (!in_array($routeName, MenuHelper::activeRouteNames(), true)) {
            return $next($request);
        }

        if (MenuHelper::roleHasRoute($routeName, $roleName)) {
            return $next($request);
        }

        $warning = 'Akses ke menu tersebut telah dicabut. Anda dialihkan ke menu yang masih tersedia.';

        $fallbackRoute = MenuHelper::firstAllowedRouteFor($roleName);
        if ($fallbackRoute && Route::has($fallbackRoute) && $fallbackRoute !== $routeName) {
            return redirect()->route($fallbackRoute)->with('warning', $warning);
        }

        if (MenuHelper::roleHasRoute('home', $roleName) && Route::has('home') && $routeName !== 'home') {
            return redirect()->route('home')->with('warning', $warning);
        }

        abort(403, 'Anda tidak memiliki akses ke menu ini.');
    }
}

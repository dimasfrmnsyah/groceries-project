<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use App\Support\MenuHelper;
class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
      /**
     * Determine where to redirect users after login based on allowed menus.
     */
    protected function redirectTo(): string
    {
        try {
            if (Route::has('sales.index') && MenuHelper::roleHasRoute('sales.index')) {
                return route('sales.index');
            }

            $routeName = MenuHelper::firstAllowedRouteFor();
            if ($routeName && Route::has($routeName)) {
                return route($routeName);
            }
        } catch (\Throwable $e) {
            Log::error('login.redirect failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return '/';
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('auth')->only('logout');
    }
}

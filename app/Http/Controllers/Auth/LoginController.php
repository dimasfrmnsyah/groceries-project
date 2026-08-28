<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
     * Jangan izinkan logout bawaan melewati kewajiban setoran kasir terkunci.
     * Tombol normal memakai StaffController, tetapi endpoint /logout juga
     * harus aman jika dipanggil langsung atau dari browser lama.
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $role = strtolower(trim((string) ($user?->roles ?? '')));

        if ($user
            && in_array($role, ['staff', 'kasir', 'cashier'], true)
            && Schema::hasColumn('users', 'is_lock')
            && (bool) $user->is_lock) {
            return redirect()->back()->with('revenue_error', 'Akun kasir terkunci. Masukkan pendapatan harian terlebih dahulu.');
        }

        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->loggedOut($request) ?: redirect('/');
    }

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

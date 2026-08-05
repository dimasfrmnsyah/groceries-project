<?php
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use App\Models\tb_stores;
use App\Models\User;

if (!function_exists('resp_success')) {
    function resp_success(string $message = "", mixed $data = null, $cookie = null): JsonResponse
    {
        $response = response()->json([
            "status" => "OK",
            "messages" => $message,
            "data" => $data ?? [],
            "errors" => []
        ], 200);

        if($cookie){
            $response->cookie($cookie);
        }

        return $response;
    }
}

if (!function_exists('resp_error')) {
    function resp_error(string $message = "", mixed $data = null, int $code = 400): JsonResponse
    {
        return response()->json([
            "status" => "ERROR",
            "messages" => $message,
            "data" => [],
            "errors" => $data ?? [],
        ], $code);
    }
}

if (!function_exists('handle_exception')) {
    function handle_exception($e): JsonResponse
    {
        $message = "Error";
        $code = 500;

        if ($e instanceof Exception) {
            $message = $e->getMessage();
            $code = 404;
        }

        dd($e);

        // if($e !instanceof Exception){
        //     $message = $e->getMess
        // }

        return response()->json([
            "status" => "ERROR",
            "messages" => $message,
            "data" => [],
            "errors" => [],
        ], $code);
    }
}

if (!function_exists('store_access_ids')) {
    function store_access_ids(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $role = strtolower((string) ($user->roles ?? ''));
        if ($role === 'superadmin') {
            return tb_stores::query()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $ids = [];
        if (Schema::hasTable('user_stores')) {
            $ids = array_merge($ids, $user->stores()
                ->pluck('tb_stores.id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }
        if (Schema::hasTable('user_store')) {
            $legacyIds = DB::table('user_store')
                ->where('user_id', $user->id)
                ->pluck('store_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $legacyIds);
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $primary = $user->store_id ? (int) $user->store_id : null;
        $staffRoles = ['staff', 'kasir', 'cashier'];
        if (in_array($role, $staffRoles, true)) {
            if ($primary) {
                return [$primary];
            }
            return !empty($ids) ? [(int) $ids[0]] : [];
        }

        if (!empty($ids)) {
            return $ids;
        }

        return $primary ? [$primary] : [];
    }
}

if (!function_exists('store_access_list')) {
    function store_access_list(?User $user)
    {
        if (!$user) {
            return collect();
        }

        $role = strtolower((string) ($user->roles ?? ''));
        if ($role === 'superadmin') {
            return tb_stores::all();
        }

        $ids = store_access_ids($user);
        if (empty($ids)) {
            return collect();
        }

        return tb_stores::whereIn('id', $ids)->get();
    }
}

if (!function_exists('store_access_can_select')) {
    function store_access_can_select(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $role = strtolower((string) ($user->roles ?? ''));
        if ($role === 'superadmin') {
            return true;
        }

        if (in_array($role, ['staff', 'kasir', 'cashier'], true)) {
            return false;
        }

        return count(store_access_ids($user)) > 1;
    }
}

if (!function_exists('store_access_resolve_id')) {
    function store_access_resolve_id(Request $request, ?User $user, array $keys = ['store_id', 'store']): ?int
    {
        if (!$user) {
            return null;
        }

        $requested = null;
        foreach ($keys as $key) {
            $value = $request->input($key);
            if ($value !== null && $value !== '') {
                $requested = (int) $value;
                break;
            }
        }

        $role = strtolower((string) ($user->roles ?? ''));
        if ($role === 'superadmin') {
            return $requested ?: null;
        }

        $allowed = store_access_ids($user);
        if ($requested && in_array($requested, $allowed, true)) {
            return $requested;
        }

        $primary = $user->store_id ? (int) $user->store_id : null;
        if ($primary && in_array($primary, $allowed, true)) {
            return $primary;
        }

        return !empty($allowed) ? (int) $allowed[0] : null;
    }
}

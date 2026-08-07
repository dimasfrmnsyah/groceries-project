<?php

namespace App\Http\Controllers;

use App\Models\tb_stores;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Support\MenuHelper;
use Yajra\DataTables\Facades\DataTables;

class TbUserController extends Controller
{

    public function index(Request $request)
    {
        $this->authorizeUserManager();
        $user = auth()->user();
        $role = strtolower((string) ($user->roles ?? ''));
        $allowedStoreIds = store_access_ids($user);

        $users = \App\Models\User::with(['store', 'stores'])
            ->when($role !== 'superadmin', function ($query) use ($allowedStoreIds) {
                if (!empty($allowedStoreIds)) {
                    $query->where(function ($q) use ($allowedStoreIds) {
                        $q->whereIn('store_id', $allowedStoreIds)
                            ->orWhereHas('stores', function ($sq) use ($allowedStoreIds) {
                                $sq->whereIn('tb_stores.id', $allowedStoreIds);
                            });
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }
            })
            ->get();

        $users->each(function ($row) {
            $storeNames = $row->stores?->pluck('store_name')->filter()->values() ?? collect();
            if ($storeNames->isEmpty() && $row->store) {
                $storeNames = collect([$row->store->store_name]);
            }
            $row->store_names = $storeNames->implode(', ');
        });

        if ($request->ajax()) {
            return DataTables::of($users)
                ->addColumn('action', function ($user) {
                    return '<a href="/user/edit/' . $user->id . '" class="btn btn-sm btn-success">
                                <i class="bx bx-pencil me-0"></i>
                            </a>
                            <a href="javascript:void(0)" onClick="confirmDelete(\'' . $user->id . '\')" class="btn btn-sm btn-danger">
                                <i class="bx bx-trash me-0"></i>
                            </a>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('pages.admin.manage_user.index');
    }


    public function create()
    {
        $this->authorizeUserManager();
        $user = auth()->user();
        $role = strtolower((string) ($user->roles ?? ''));

        if ($role === 'superadmin') {
            $stores = tb_stores::all();
        } else {
            $stores = store_access_list($user);
        }
        return view('pages.admin.manage_user.create', [
            'stores' => $stores,
            'availableRoles' => $this->availableRoles(),
            'selectedStoreIds' => [],
        ]);
    }

    /**
     * Store the newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->authorizeUserManager();
        $request->merge(['roles' => strtolower(trim((string) $request->input('roles')))]);
        $data = $request->validate([
            'name' => 'required',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|min:8|confirmed',
            'roles' => ['required', Rule::in($this->assignableRoles())],
            'store_id' => 'nullable|integer|exists:tb_stores,id',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'integer|exists:tb_stores,id',
        ]);

        $storeIds = collect($request->input('store_ids', []))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        if ($storeIds->isEmpty() && $request->filled('store_id')) {
            $storeIds = collect([(int) $request->input('store_id')]);
        }

        $role = strtolower((string) $data['roles']);
        $actor = auth()->user();
        if ($actor && strtolower((string) $actor->roles) !== 'superadmin') {
            $allowed = store_access_ids($actor);
            if ($storeIds->isNotEmpty() && array_diff($storeIds->all(), $allowed)) {
                return redirect()->back()->withErrors(['store_ids' => 'Store tidak diizinkan untuk akun ini.']);
            }
        }
        if (in_array($role, ['staff', 'kasir', 'cashier'], true) && $storeIds->count() > 1) {
            return redirect()->back()->withErrors(['store_ids' => 'Role staff/kasir hanya boleh memiliki 1 store.']);
        }

        $data['store_id'] = $storeIds->isNotEmpty() ? $storeIds->first() : null;
        unset($data['store_ids']);

        DB::beginTransaction();
        try {
            $data['password'] = Hash::make($data['password']);
            $created = User::create($data);
            if (Schema::hasTable('user_stores')) {
                $created->stores()->sync($storeIds->all());
            }
            if (Schema::hasTable('user_store')) {
                DB::table('user_store')->where('user_id', $created->id)->delete();
                foreach ($storeIds as $sid) {
                    $payload = ['user_id' => $created->id, 'store_id' => (int) $sid];
                    if (Schema::hasColumn('user_store', 'created_at')) {
                        $payload['created_at'] = now();
                    }
                    if (Schema::hasColumn('user_store', 'updated_at')) {
                        $payload['updated_at'] = now();
                    }
                    DB::table('user_store')->insert($payload);
                }
            }
            DB::commit();
            return redirect()->route('user.index')->with('success', 'User berahasil dibuat');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function edit($id)
    {
        $this->authorizeUserManager();
        $actor = auth()->user();
        $stores = strtolower((string) ($actor?->roles)) === 'superadmin'
            ? tb_stores::all()
            : store_access_list($actor);
        $user = User::with('stores')->where('id', $id)->first();
        if (!$user) {
            abort(404, 'User tidak ditemukan.');
        }
        if (strtolower((string) $actor?->roles) !== 'superadmin'
            && strtolower((string) $user->roles) === 'superadmin') {
            abort(403, 'Hanya superadmin yang boleh mengelola akun superadmin.');
        }
        $selectedStoreIds = $user?->stores?->pluck('id')->all() ?? [];
        if (empty($selectedStoreIds) && Schema::hasTable('user_store')) {
            $selectedStoreIds = DB::table('user_store')
                ->where('user_id', $id)
                ->pluck('store_id')
                ->map(fn ($sid) => (int) $sid)
                ->all();
        }
        if (empty($selectedStoreIds) && $user?->store_id) {
            $selectedStoreIds = [(int) $user->store_id];
        }
        return view('pages.admin.manage_user.create', [
            'user' => $user,
            'stores' => $stores,
            'selectedStoreIds' => $selectedStoreIds,
            'availableRoles' => $this->availableRoles($user?->roles),
        ]);
    }

    /**
     * Update the resource in storage.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeUserManager();
        $user = User::findOrFail($id);
        if (strtolower((string) auth()->user()?->roles) !== 'superadmin'
            && strtolower((string) $user->roles) === 'superadmin') {
            abort(403, 'Hanya superadmin yang boleh mengelola akun superadmin.');
        }
        $request->merge(['roles' => strtolower(trim((string) $request->input('roles')))]);
        $data = $request->validate([
            'name' => 'required',
            'email' => 'required|string|email|max:255|unique:users,email,' . $id,
            'roles' => ['required', Rule::in($this->assignableRoles($user->roles))],
            'store_id' => 'nullable|integer|exists:tb_stores,id',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'integer|exists:tb_stores,id',
        ]);

        $storeIds = collect($request->input('store_ids', []))
            ->filter()
            ->map(fn ($sid) => (int) $sid)
            ->unique()
            ->values();
        if ($storeIds->isEmpty() && $request->filled('store_id')) {
            $storeIds = collect([(int) $request->input('store_id')]);
        }

        $role = strtolower((string) $data['roles']);
        $actor = auth()->user();
        if ($actor && strtolower((string) $actor->roles) !== 'superadmin') {
            $allowed = store_access_ids($actor);
            if ($storeIds->isNotEmpty() && array_diff($storeIds->all(), $allowed)) {
                return redirect()->back()->withErrors(['store_ids' => 'Store tidak diizinkan untuk akun ini.']);
            }
        }
        if (in_array($role, ['staff', 'kasir', 'cashier'], true) && $storeIds->count() > 1) {
            return redirect()->back()->withErrors(['store_ids' => 'Role staff/kasir hanya boleh memiliki 1 store.']);
        }

        $data['store_id'] = $storeIds->isNotEmpty() ? $storeIds->first() : null;
        unset($data['store_ids']);

        DB::beginTransaction();
        try {
            User::where('id', $id)->update($data);
            if (Schema::hasTable('user_stores')) {
                $user = User::find($id);
                if ($user) {
                    $user->stores()->sync($storeIds->all());
                }
            }
            if (Schema::hasTable('user_store')) {
                DB::table('user_store')->where('user_id', $id)->delete();
                foreach ($storeIds as $sid) {
                    $payload = ['user_id' => $id, 'store_id' => (int) $sid];
                    if (Schema::hasColumn('user_store', 'created_at')) {
                        $payload['created_at'] = now();
                    }
                    if (Schema::hasColumn('user_store', 'updated_at')) {
                        $payload['updated_at'] = now();
                    }
                    DB::table('user_store')->insert($payload);
                }
            }
            DB::commit();
            return redirect()->route('user.index')->with('success', 'User berahasil diperbaharui');
        } catch (\Exception $e) {
            dd($e->getMessage());
            DB::rollBack();
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function updatePassword(Request $request, $id)
    {
        $this->authorizeUserManager();
        $data = $request->validate([
            'new_password' => 'required|min:8|confirmed'
        ]);
        // dd(auth()->user()->password);
        DB::beginTransaction();
        try {
            User::where('id', $id)->update([
                'password' => Hash::make($request->new_password)
            ]);
            DB::commit();
            return redirect()->route('user.index')->with('success', 'Password berhasil diperbaharui');
        } catch (\Exception $e) {
            DB::rollBack();
            dd('error');

            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove the resource from storage.
     */
    public function destroy($id)
    {
        $this->authorizeUserManager();
        DB::beginTransaction();
        try {
            $actor = auth()->user();
            if ($actor && (string) $actor->id === (string) $id) {
                return response()->json(['status' => 'error', 'message' => 'Tidak bisa menghapus user yang sedang login.'], 422);
            }

            $user = User::withTrashed()->where('id', $id)->first();
            if (!$user) {
                return response()->json(['status' => 'error', 'message' => 'User tidak ditemukan.'], 404);
            }

            if (Schema::hasColumn('users', 'deleted_at')) {
                $user->delete();
            } else {
                if (Schema::hasTable('user_stores')) {
                    $user->stores()->detach();
                }
                if (Schema::hasTable('user_store')) {
                    DB::table('user_store')->where('user_id', $user->id)->delete();
                }
                $user->forceDelete();
            }
            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'User berhasil dihapus']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function availableRoles(?string $currentRole = null)
    {
        if (Schema::hasTable('tb_master_roles')) {
            $masterRoles = DB::table('tb_master_roles')
                ->pluck('role_name')
                ->map(fn ($role) => strtolower(trim((string) $role)))
                ->filter()
                ->unique();
            $roles = DB::table('tb_master_roles')
                ->where('is_active', 1)
                ->orderBy('role_name')
                ->pluck('role_name');

            // User lama yang sudah memakai role legacy tetap muncul sampai
            // migration rekonsiliasi membuat master role-nya.
            if (Schema::hasTable('users')) {
                $legacyRoles = DB::table('users')
                    ->whereNotNull('roles')
                    ->pluck('roles')
                    ->map(fn ($role) => strtolower(trim((string) $role)))
                    ->filter()
                    ->reject(fn ($role) => $masterRoles->contains($role));
                $roles = $roles->merge($legacyRoles);
            }
        } else {
            // Fallback tetap berasal dari data database, bukan daftar role hardcode.
            $roles = DB::table('users')
                ->whereNotNull('roles')
                ->distinct()
                ->pluck('roles');
        }

        $actorRole = strtolower((string) auth()->user()?->roles);
        if ($currentRole && strtolower($currentRole) !== 'superadmin') {
            $roles->push($currentRole);
        }

        if ($actorRole === 'superadmin') {
            $roles->prepend('superadmin');
        }

        return $roles
            ->map(fn ($role) => strtolower(trim((string) $role)))
            ->filter()
            ->reject(fn ($role) => $actorRole !== 'superadmin' && $role === 'superadmin')
            ->unique()
            ->values();
    }

    private function assignableRoles(?string $currentRole = null): array
    {
        return $this->availableRoles($currentRole)->all();
    }

    private function authorizeUserManager(): void
    {
        $user = auth()->user();
        $role = strtolower((string) ($user?->roles ?? ''));
        abort_unless(
            $role === 'superadmin' || MenuHelper::roleHasRoute('user.index', $role),
            403,
            'Anda tidak memiliki permission untuk mengelola user.'
        );
    }
}

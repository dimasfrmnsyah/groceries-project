<?php

namespace App\Http\Controllers;

use App\Models\TbMasterRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class TbMasterRolesController extends Controller
{
    public function index(Request $request)
    {
        try {
            $roles = TbMasterRole::all();
            if($request->ajax()) {
            return DataTables::of($roles)
                        ->addColumn('action', function ($role) {
                        return '<a href="/settings/roles/edit/'.$role->id.'" class="btn btn-sm btn-success"><i class="bx bx-pencil me-0"></i>
                        </a>
                        <a href="javascript:void(0)" onClick="confirmDelete('.$role->id.')" class="btn btn-sm btn-danger"><i class="bx bx-trash me-0"></i>
                        </a>
                        ';
                    })
                    ->rawColumns(['action'])
                    ->make(true);
        };
            return view('pages.admin.settings.roles.index');
            
        } catch(\Exception $e) {

        }
    }

    public function store(Request $request)
    {
        $request->merge(['role_name' => $this->normalizeRoleName($request->input('role_name'))]);
        $data = $request->validate([
            'role_name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', 'unique:tb_master_roles,role_name'],
        ]);
        $data['is_active'] = 1;
        TbMasterRole::create($data);

        return redirect()->route('settings.roles.index')
            ->with('success', 'Role berhasil dibuat dan sudah tersedia pada form user.');
    }
    
    public function create(Request $request) 
    {
        return view('pages.admin.settings.roles.create');
    }

    public function edit(Request $request, $id)
    {
        $role = TbMasterRole::find($id);
        return view('pages.admin.settings.roles.create', compact('role'));
    }

    public function update(Request $request, $id)
    {
        $role = TbMasterRole::findOrFail($id);
        $oldName = strtolower(trim((string) $role->role_name));
        $request->merge(['role_name' => $this->normalizeRoleName($request->input('role_name'))]);
        $data = $request->validate([
            'role_name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/', Rule::unique('tb_master_roles', 'role_name')->ignore($role->id)],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($role, $data, $oldName) {
            $role->update($data);

            if ($oldName !== $data['role_name']) {
                DB::table('users')->whereRaw('LOWER(TRIM(roles)) = ?', [$oldName])->update(['roles' => $data['role_name']]);
                if (Schema::hasTable('tb_master_menu_roles') && Schema::hasColumn('tb_master_menu_roles', 'role_name')) {
                    DB::table('tb_master_menu_roles')
                        ->whereRaw('LOWER(TRIM(role_name)) = ?', [$oldName])
                        ->update(['role_name' => $data['role_name'], 'updated_at' => now()]);
                }
            }
        });
        Cache::forget('menu_allowed_routes:'.$oldName);
        Cache::forget('menu_allowed_routes:'.$data['role_name']);

        return redirect()->route('settings.roles.index')->with('success', 'Role berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $role = TbMasterRole::findOrFail($id);
        $roleName = strtolower(trim((string) $role->role_name));

        if ($roleName === 'superadmin') {
            return resp_error('Role superadmin tidak dapat dihapus.');
        }

        if (DB::table('users')->whereRaw('LOWER(TRIM(roles)) = ?', [$roleName])->exists()) {
            return resp_error('Role masih digunakan oleh user. Pindahkan user ke role lain terlebih dahulu.');
        }

        DB::transaction(function () use ($role, $roleName) {
            if (Schema::hasTable('tb_master_menu_roles')) {
                DB::table('tb_master_menu_roles')->where('role_id', $role->id)->delete();
                if (Schema::hasColumn('tb_master_menu_roles', 'role_name')) {
                    DB::table('tb_master_menu_roles')->whereRaw('LOWER(TRIM(role_name)) = ?', [$roleName])->delete();
                }
            }
            $role->delete();
        });
        Cache::forget('menu_allowed_routes:'.$roleName);

        return resp_success('Role berhasil dihapus.');
    }

    private function normalizeRoleName($name): string
    {
        return Str::of((string) $name)->trim()->lower()->slug('_')->toString();
    }
}

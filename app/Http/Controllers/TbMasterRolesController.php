<?php

namespace App\Http\Controllers;

use App\Models\TbMasterRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
         $data = $request->validate([
            'role_name' => 'required|string|max:100',
        ]);
        DB::beginTransaction();
        try {
            TbMasterRole::create($data);
            DB::commit();
            return redirect('/settings/roles')->with('success', 'Data berhasil dikirim!');


        } catch(\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        } 
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
         $data = $request->validate([
            'role_name' => 'required|string|max:100',
            'is_active' => 'required'
        ]);

        DB::beginTransaction();
        try {
            TbMasterRole::where('id', $id)->update($data);
            DB::commit();
            return redirect('/settings/roles')->with('success', 'Data berhasil diperbaharui!');

        } catch(\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());

        }
    }

    public function destroy($id)
    {
        try {
            TbMasterRole::where('id', $id)->delete();

            return resp_success('Data berhasil dihapus');
        } catch(\Exception $e) {
            return resp_error($e->getMessage());
        }
    }

}

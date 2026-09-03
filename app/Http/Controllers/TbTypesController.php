<?php

namespace App\Http\Controllers;

use App\Models\tb_types;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class TbTypesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $types = tb_types::all();
        if($request->ajax()) {
            return DataTables::of($types)
                    ->addColumn('action', function ($type) {
                        return '<a href="/master-type/edit/'.$type->id.'" class="btn btn-sm btn-success"><i class="bx bx-pencil me-0"></i>
                        </a>
                        <a href="javascript:void(0)" onClick="confirmDelete('.$type->id.')" class="btn btn-sm btn-danger"><i class="bx bx-trash me-0"></i>
                        </a>
                        ';
                    })
                    ->rawColumns(['action'])
                    ->make(true);
        }
        return view('pages.admin.master.manage_type.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('pages.admin.master.manage_type.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        
        $validated = $request->validate([
            'type_name' => 'required',
            'description' => 'nullable'
        ]);

        DB::beginTransaction();
        try {
            
            tb_types::create($validated);
            DB::commit();
            return redirect('/master-type')->with('success', 'Data berhasil dikirim!');
        } catch(\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $type = tb_types::where('id', $id)->first();
        return view('pages.admin.master.manage_type.create', ['type'=>$type]);

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'type_name' => 'required',
            'description' => 'nullable',
        ]);

        DB::beginTransaction();
        try {
            tb_types::where('id', $id)->update($data);
            DB::commit();
            return redirect('/master-type')->with('success', 'Data berhasil diperbaharui!');
        } catch(\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $type = tb_types::find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis tidak ditemukan atau sudah dihapus.',
            ], 404);
        }

        try {
            DB::transaction(function () use ($type) {
                // Model menggunakan SoftDeletes, jadi data historis tetap aman.
                $type->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Jenis berhasil dinonaktifkan.',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Jenis gagal dihapus. Silakan coba lagi atau periksa log aplikasi.',
            ], 500);
        }
    }
}

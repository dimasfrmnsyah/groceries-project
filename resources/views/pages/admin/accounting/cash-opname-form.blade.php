@extends('layouts.app')

@section('content')
@php($isEdit = $mode === 'edit')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Cash Opname</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('accounting.cash-opname.index') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $isEdit ? 'Edit' : 'Tambah' }}</li>
            </ol>
        </nav>
    </div>
    <div class="ms-auto">
        <a href="{{ route('accounting.cash-opname.index') }}" class="btn btn-secondary">Kembali</a>
    </div>
</div>

<h6 class="mb-0 text-uppercase">{{ $isEdit ? 'Edit' : 'Tambah' }} Cash Opname</h6>
<hr>
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
@if($errors->any()) <div class="alert alert-danger">Form masih error. Cek input yang ditandai.</div> @endif

<div class="row">
    <div class="col-xl-8 mx-auto">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $isEdit ? route('accounting.cash-opname.update', $row->id) : route('accounting.cash-opname.store') }}">
                    @csrf
                    @if($isEdit) @method('PUT') @endif
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal</label>
                            <input type="date" name="date" value="{{ old('date', $row->date ?? now('Asia/Jakarta')->toDateString()) }}" class="form-control" required>
                            @error('date') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Toko</label>
                            <select name="store_id" class="form-select" required>
                                <option value="">Pilih Toko</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}" @selected((int)old('store_id', $row->store_id ?? 0) === (int)$store->id)>{{ $store->store_name }}</option>
                                @endforeach
                            </select>
                            @error('store_id') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nominal Uang</label>
                            <input type="number" name="nominal" value="{{ old('nominal', $row->nominal ?? '') }}" min="0" step="0.01" class="form-control" required>
                            @error('nominal') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Keterangan</label>
                            <input name="description" value="{{ old('description', $row->description ?? '') }}" class="form-control">
                            @error('description') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 text-end">
                            <button class="btn btn-primary">{{ $isEdit ? 'Update' : 'Simpan' }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

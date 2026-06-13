@extends('layouts.app')

@section('content')
@php($isEdit = $mode === 'edit')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Account Bank / Kas</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('accounting.accounts.index') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $isEdit ? 'Edit' : 'Tambah' }}</li>
            </ol>
        </nav>
    </div>
    <div class="ms-auto"><a href="{{ route('accounting.accounts.index') }}" class="btn btn-secondary">Kembali</a></div>
</div>

<h6 class="mb-0 text-uppercase">{{ $isEdit ? 'Edit' : 'Tambah' }} Account</h6>
<hr>
@if($errors->any()) <div class="alert alert-danger">Form masih error. Cek input yang ditandai.</div> @endif

<div class="row">
    <div class="col-xl-8 mx-auto">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $isEdit ? route('accounting.accounts.update', $account->id) : route('accounting.accounts.store') }}">
                    @csrf
                    @if($isEdit) @method('PUT') @endif
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nomor Account</label>
                            <input name="account_number" value="{{ old('account_number', $account->account_number ?? '') }}" class="form-control" required>
                            @error('account_number') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nama Account</label>
                            <input name="account_name" value="{{ old('account_name', $account->account_name ?? '') }}" class="form-control" required>
                            @error('account_name') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tipe</label>
                            <input name="account_type" value="{{ old('account_type', $account->account_type ?? 'kas') }}" class="form-control" required>
                            @error('account_type') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        @if($isEdit)
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select name="is_active" class="form-select" required>
                                    <option value="1" @selected((int)old('is_active', $account->is_active) === 1)>Aktif</option>
                                    <option value="0" @selected((int)old('is_active', $account->is_active) === 0)>Nonaktif</option>
                                </select>
                                @error('is_active') <div class="text-danger">{{ $message }}</div> @enderror
                            </div>
                        @endif
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

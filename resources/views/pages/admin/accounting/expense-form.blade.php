@extends('layouts.app')

@section('content')
@php
    $isEdit = $mode === 'edit';
@endphp
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Pengeluaran</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('accounting.expenses.index') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $isEdit ? 'Edit' : 'Tambah' }}</li>
            </ol>
        </nav>
    </div>
    <div class="ms-auto">
        <a href="{{ route('accounting.expenses.index') }}" class="btn btn-secondary">Kembali</a>
    </div>
</div>

<h6 class="mb-0 text-uppercase">{{ $isEdit ? 'Edit' : 'Tambah' }} Pengeluaran</h6>
<hr>
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
@if($errors->any()) <div class="alert alert-danger">Form masih error. Cek input yang ditandai.</div> @endif

<div class="row">
    <div class="{{ $isEdit ? 'col-xl-9' : 'col-12' }} mx-auto">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $isEdit ? route('accounting.expenses.update', $row->id) : route('accounting.expenses.store') }}">
                    @csrf
                    @if($isEdit) @method('PUT') @endif
                    @if($isEdit)
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
                            <label class="form-label">Account</label>
                            <select name="account_id" class="form-select">
                                <option value="">Pilih Account</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}" @selected((int)old('account_id', $row->account_id ?? 0) === (int)$account->id)>{{ $account->account_number }} - {{ $account->account_name }}</option>
                                @endforeach
                            </select>
                            @error('account_id') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kategori</label>
                            <input name="category" value="{{ old('category', $row->category ?? '') }}" class="form-control">
                            @error('category') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nominal</label>
                            <input type="number" name="amount" value="{{ old('amount', $row->amount ?? '') }}" min="0" step="0.01" class="form-control" required>
                            @error('amount') <div class="text-danger">{{ $message }}</div> @enderror
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
                    @else
                        @php
                            $bulkFields = [
                                ['name' => 'date', 'label' => 'Tanggal', 'type' => 'date', 'default' => now('Asia/Jakarta')->toDateString(), 'required' => true, 'carry' => true, 'width' => '155px'],
                                ['name' => 'account_id', 'label' => 'Account', 'type' => 'select', 'placeholder' => 'Pilih Account', 'carry' => true, 'width' => '230px', 'options' => $accounts->map(fn ($item) => ['value' => $item->id, 'label' => $item->account_number.' - '.$item->account_name])->all()],
                                ['name' => 'category', 'label' => 'Kategori', 'type' => 'text', 'maxLength' => 120, 'width' => '180px'],
                                ['name' => 'amount', 'label' => 'Nominal', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'required' => true, 'align' => 'end', 'width' => '160px'],
                                ['name' => 'description', 'label' => 'Keterangan', 'type' => 'text', 'maxLength' => 255, 'placeholder' => 'Opsional', 'width' => '240px'],
                            ];
                        @endphp
                        @include('pages.admin.accounting.partials.bulk-entry-fields', ['bulkKey' => 'expense', 'bulkTitle' => 'Daftar Pengeluaran', 'bulkUsesStore' => true])
                    @endif
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

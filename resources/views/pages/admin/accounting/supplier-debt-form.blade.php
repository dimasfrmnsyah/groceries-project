@extends('layouts.app')

@section('content')
@php
    $isEdit = $mode === 'edit';
@endphp
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Hutang Supplier</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('accounting.supplier-debts.index') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $isEdit ? 'Edit' : 'Tambah' }}</li>
            </ol>
        </nav>
    </div>
    <div class="ms-auto">
        <a href="{{ route('accounting.supplier-debts.index') }}" class="btn btn-secondary">Kembali</a>
    </div>
</div>

<h6 class="mb-0 text-uppercase">{{ $isEdit ? 'Edit' : 'Tambah' }} Hutang Supplier</h6>
<hr>
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
@if($errors->any()) <div class="alert alert-danger">Form masih error. Cek input yang ditandai.</div> @endif

<div class="row">
    <div class="{{ $isEdit ? 'col-xl-9' : 'col-12' }} mx-auto">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ $isEdit ? route('accounting.supplier-debts.update', $row->id) : route('accounting.supplier-debts.store') }}">
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
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">Pilih Supplier</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected((int)old('supplier_id', $row->supplier_id ?? 0) === (int)$supplier->id)>{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                            @error('supplier_id') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Budget</label>
                            <input type="number" name="budget_amount" value="{{ old('budget_amount', $row->budget_amount ?? '') }}" min="0" step="0.01" class="form-control" required>
                            @error('budget_amount') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Belanja</label>
                            <input type="number" name="purchase_amount" value="{{ old('purchase_amount', $row->purchase_amount ?? '') }}" min="0" step="0.01" class="form-control" required>
                            @error('purchase_amount') <div class="text-danger">{{ $message }}</div> @enderror
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
                                ['name' => 'supplier_id', 'label' => 'Supplier', 'type' => 'select', 'placeholder' => 'Pilih Supplier', 'carry' => true, 'width' => '220px', 'options' => $suppliers->map(fn ($item) => ['value' => $item->id, 'label' => $item->name])->all()],
                                ['name' => 'budget_amount', 'label' => 'Budget', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'required' => true, 'align' => 'end', 'width' => '160px'],
                                ['name' => 'purchase_amount', 'label' => 'Belanja', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'required' => true, 'align' => 'end', 'width' => '160px'],
                                ['name' => 'description', 'label' => 'Keterangan', 'type' => 'text', 'maxLength' => 255, 'placeholder' => 'Opsional', 'width' => '240px'],
                            ];
                        @endphp
                        @include('pages.admin.accounting.partials.bulk-entry-fields', ['bulkKey' => 'supplier-debt', 'bulkTitle' => 'Daftar Hutang Supplier', 'bulkUsesStore' => true])
                    @endif
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

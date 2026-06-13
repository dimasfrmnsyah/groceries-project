@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Accounting</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('home') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">Cash Opname</li>
            </ol>
        </nav>
    </div>
    <div class="ms-auto">
        <a href="{{ route('accounting.cash-opname.create') }}" class="btn btn-primary">Tambah Cash Opname</a>
    </div>
</div>

<h6 class="mb-0 text-uppercase">Cash Opname</h6>
<hr>
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Filter Toko</label>
                <select name="store" class="form-select">
                    <option value="">Semua toko</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected((int)$selectedStoreId === (int)$store->id)>{{ $store->store_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-secondary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead><tr><th>Tanggal</th><th>Toko</th><th class="text-end">Nominal</th><th>Keterangan</th><th style="width: 140px;">Aksi</th></tr></thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>{{ $row->store_name ?? '-' }}</td>
                            <td class="text-end">{{ number_format($row->nominal, 0, ',', '.') }}</td>
                            <td>{{ $row->description ?? '-' }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('accounting.cash-opname.edit', $row->id) }}" class="btn btn-sm btn-success"><i class="bx bx-pencil me-0"></i></a>
                                    <form method="POST" action="{{ route('accounting.cash-opname.destroy', $row->id) }}" onsubmit="return confirm('Hapus cash opname ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-danger"><i class="bx bx-trash me-0"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center">Belum ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

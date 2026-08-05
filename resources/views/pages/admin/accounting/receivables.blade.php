@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Accounting</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('home') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">Piutang Pelanggan</li>
            </ol>
        </nav>
    </div>
    <div class="ms-auto">
        <a href="{{ route('accounting.receivables.create') }}" class="btn btn-primary">Tambah Piutang</a>
    </div>
</div>

<h6 class="mb-0 text-uppercase">Piutang Pelanggan</h6>
<hr>
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

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
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Toko</th>
                        <th>Pelanggan</th>
                        <th>Produk</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-end">Terbayar</th>
                        <th>Status</th>
                        <th style="width: 190px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>{{ $row->store_name ?? '-' }}</td>
                            <td>{{ $row->customer_name ?? '-' }}</td>
                            <td>[{{ $row->product_code }}] {{ $row->product_name }}</td>
                            <td class="text-end">{{ $row->quantity }}</td>
                            <td class="text-end">{{ number_format($row->amount, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->paid_amount, 0, ',', '.') }}</td>
                            <td>{{ $row->status }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    @if($row->status !== 'paid')
                                        <a href="{{ route('accounting.receivables.payment', $row->id) }}" class="btn btn-sm btn-primary">Bayar</a>
                                    @endif
                                    <a href="{{ route('accounting.receivables.edit', $row->id) }}" class="btn btn-sm btn-success"><i class="bx bx-pencil me-0"></i></a>
                                    <form method="POST" action="{{ route('accounting.receivables.destroy', $row->id) }}" onsubmit="return confirm('Hapus piutang ini? Stok dari piutang akan dikembalikan.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-danger"><i class="bx bx-trash me-0"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center">Belum ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

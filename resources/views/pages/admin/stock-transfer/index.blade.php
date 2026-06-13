@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Transfer Stok Antar Toko</div>
</div>

@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

<div class="card mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('stock-transfer.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-2">
                <label class="form-label">Tanggal</label>
                <input type="date" name="date" value="{{ now('Asia/Jakarta')->toDateString() }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Produk</label>
                <select name="product_id" class="form-select" required>
                    <option value="">-- pilih produk --</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}">[{{ $product->product_code }}] {{ $product->product_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Dari Toko</label>
                <select name="from_store_id" class="form-select" required>
                    <option value="">-- asal --</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}">{{ $store->store_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Ke Toko</label>
                <select name="to_store_id" class="form-select" required>
                    <option value="">-- tujuan --</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}">{{ $store->store_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Qty</label>
                <input type="number" name="quantity" min="1" value="1" class="form-control" required>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Transfer</button>
            </div>
            <div class="col-md-12">
                <input type="text" name="description" class="form-control" placeholder="Keterangan opsional">
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Produk</th>
                        <th>Dari</th>
                        <th>Ke</th>
                        <th>Qty</th>
                        <th>Oleh</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transfers as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>[{{ $row->product_code }}] {{ $row->product_name }}</td>
                            <td>{{ $row->from_store }}</td>
                            <td>{{ $row->to_store }}</td>
                            <td class="text-end">{{ number_format($row->quantity, 0, ',', '.') }}</td>
                            <td>{{ $row->creator_name ?? '-' }}</td>
                            <td>{{ $row->description ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center">Belum ada transfer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Kategori Item Moving</div>
</div>

@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Toko</label>
                <select name="store" class="form-select" onchange="this.form.submit()">
                    <option value="">-- Pilih Toko --</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected((int)$storeId === (int)$store->id)>{{ $store->store_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Kategori</label>
                <select name="category" class="form-select" onchange="this.form.submit()">
                    <option value="all" @selected($category === 'all')>Semua</option>
                    <option value="fast" @selected($category === 'fast')>Fast Moving</option>
                    <option value="slow" @selected($category === 'slow')>Slow Moving</option>
                    <option value="dead" @selected($category === 'dead')>Dead Moving</option>
                    <option value="normal" @selected($category === 'normal')>Normal</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Avg</label>
                <select name="basis" class="form-select" onchange="this.form.submit()">
                    <option value="monthly" @selected($basis === 'monthly')>Per Bulan</option>
                    <option value="weekly" @selected($basis === 'weekly')>Per Minggu</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Cari Produk</label>
                <input type="text" name="q" value="{{ $search }}" class="form-control" placeholder="Kode / nama produk">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

@if(!$storeId)
    <div class="alert alert-info">Pilih toko untuk melihat kategori item.</div>
@else
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Produk</th>
                        <th>Stok</th>
                        <th>Min</th>
                        <th>Max</th>
                        <th>Avg 6 Bulan</th>
                        <th>Avg 3 Bulan</th>
                        <th>Terakhir Jual</th>
                        <th>Kategori</th>
                        <th>Transfer</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row->product_code }}</td>
                            <td>{{ $row->product_name }}</td>
                            <td class="text-end">{{ number_format($row->stock_system, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->min_stock, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->max_stock, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->avg_six, 2, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($row->avg_three, 2, ',', '.') }}</td>
                            <td>{{ $row->last_sale_at ? \Carbon\Carbon::parse($row->last_sale_at)->format('Y-m-d') : '-' }}</td>
                            <td>
                                @php
                                    $badge = ['fast' => 'success', 'slow' => 'warning', 'dead' => 'danger', 'normal' => 'secondary'][$row->moving_category] ?? 'secondary';
                                @endphp
                                <span class="badge bg-{{ $badge }}">{{ strtoupper($row->moving_category) }}</span>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('stock-transfer.store') }}" class="d-flex gap-1">
                                    @csrf
                                    <input type="hidden" name="date" value="{{ now('Asia/Jakarta')->toDateString() }}">
                                    <input type="hidden" name="from_store_id" value="{{ $storeId }}">
                                    <input type="hidden" name="product_id" value="{{ $row->id }}">
                                    <input type="number" name="quantity" class="form-control form-control-sm" min="1" max="{{ max(1, (int)$row->stock_system) }}" value="1" style="width:80px">
                                    <select name="to_store_id" class="form-select form-select-sm" style="width:150px" required>
                                        <option value="">Tujuan</option>
                                        @foreach($toStores as $store)
                                            <option value="{{ $store->id }}">{{ $store->store_name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-primary">Pindah</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center">Tidak ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif
@endsection

@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Accounting</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('home') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">Buku Kas</li>
            </ol>
        </nav>
    </div>
</div>

<h6 class="mb-0 text-uppercase">Buku Kas</h6>
<hr>
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Toko</label>
                <select name="store" class="form-select">
                    <option value="">Semua toko</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected((int)$selectedStoreId === (int)$store->id)>{{ $store->store_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2"><label class="form-label">Dari</label><input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Sampai</label><input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control"></div>
            <div class="col-md-3">
                <label class="form-label">Sumber</label>
                <select name="source_type" class="form-select">
                    <option value="">Semua</option>
                    @foreach(['sales','budgeting','expense','receivable_payment','supplier_debt_payment'] as $type)
                        <option value="{{ $type }}" @selected(request('source_type')===$type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-secondary w-100">Filter</button></div>
        </form>
    </div>
</div>
<div class="row mb-3">
    <div class="col-md-6"><div class="card"><div class="card-body">Masuk: <strong>Rp {{ number_format($totalIn, 0, ',', '.') }}</strong></div></div></div>
    <div class="col-md-6"><div class="card"><div class="card-body">Keluar: <strong>Rp {{ number_format($totalOut, 0, ',', '.') }}</strong></div></div></div>
</div>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead><tr><th>Tanggal</th><th>Toko</th><th>Account Snapshot</th><th>Sumber</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th>Keterangan</th></tr></thead>
                <tbody>
                    @forelse($entries as $row)
                        <tr>
                            <td>{{ $row->date }}</td>
                            <td>{{ $row->store_name ?? '-' }}</td>
                            <td>{{ $row->account_number }} - {{ $row->account_name }}</td>
                            <td>{{ $row->source_type }} #{{ $row->source_id }}</td>
                            <td class="text-end">{{ $row->direction === 'in' ? number_format($row->amount, 0, ',', '.') : '-' }}</td>
                            <td class="text-end">{{ $row->direction === 'out' ? number_format($row->amount, 0, ',', '.') : '-' }}</td>
                            <td>{{ $row->description }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center">Belum ada data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

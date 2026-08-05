@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Accounting</div>
    <div class="ps-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 p-0">
                <li class="breadcrumb-item"><a href="{{ route('home') }}"><i class="bx bx-home-alt"></i></a></li>
                <li class="breadcrumb-item active" aria-current="page">Account Bank / Kas</li>
            </ol>
        </nav>
    </div>
    <div class="ms-auto">
        <a href="{{ route('accounting.accounts.create') }}" class="btn btn-primary">Tambah Account</a>
    </div>
</div>

<h6 class="mb-0 text-uppercase">Account Bank / Kas</h6>
<hr>
@if(session('success')) <div class="alert alert-success">{{ session('success') }}</div> @endif

<div class="card mb-3">
    <div class="card-body">
        <form method="POST" action="{{ route('accounting.settings.update') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-6">
                <label class="form-label">Account default untuk penjualan</label>
                <select name="sales_account_id" class="form-select" required>
                    @foreach($accounts->where('is_active', 1) as $account)
                        <option value="{{ $account->id }}" @selected((int)$salesAccountId === (int)$account->id)>
                            {{ $account->account_number }} - {{ $account->account_name }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">Perubahan hanya untuk transaksi berikutnya. Buku kas lama tetap memakai snapshot account saat transaksi dibuat.</small>
            </div>
            <div class="col-md-2"><button class="btn btn-success w-100">Simpan</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle">
                <thead><tr><th>Nomor</th><th>Nama</th><th>Tipe</th><th>Status</th><th style="width: 140px;">Aksi</th></tr></thead>
                <tbody>
                    @forelse($accounts as $account)
                        <tr>
                            <td>{{ $account->account_number }}</td>
                            <td>{{ $account->account_name }}</td>
                            <td>{{ $account->account_type }}</td>
                            <td>{{ $account->is_active ? 'Aktif' : 'Nonaktif' }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('accounting.accounts.edit', $account->id) }}" class="btn btn-sm btn-success"><i class="bx bx-pencil me-0"></i></a>
                                    <form method="POST" action="{{ route('accounting.accounts.destroy', $account->id) }}" onsubmit="return confirm('Hapus atau nonaktifkan account ini?')">
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

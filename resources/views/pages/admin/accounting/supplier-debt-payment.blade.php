@extends('layouts.app')

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">Hutang Supplier</div>
    <div class="ms-auto"><a href="{{ route('accounting.supplier-debts.index', ['store' => $row->store_id]) }}" class="btn btn-secondary">Kembali</a></div>
</div>

<h6 class="mb-0 text-uppercase">Pembayaran Hutang Supplier</h6>
<hr>
@if(session('error')) <div class="alert alert-danger">{{ session('error') }}</div> @endif
@if($errors->any()) <div class="alert alert-danger">Form masih error. Cek input yang ditandai.</div> @endif

<div class="row">
    <div class="col-xl-8 mx-auto">
        <div class="card">
            <div class="card-body">
                <div class="mb-3">
                    <div><strong>Toko:</strong> {{ $row->store_name }}</div>
                    <div><strong>Supplier:</strong> {{ $row->supplier_name ?? '-' }}</div>
                    <div><strong>Sisa:</strong> Rp {{ number_format(max(0, $row->debt_amount - $row->paid_amount), 0, ',', '.') }}</div>
                </div>
                <form method="POST" action="{{ route('accounting.supplier-debts.pay', $row->id) }}">
                    @csrf
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Account</label>
                            <select name="account_id" class="form-select">
                                <option value="">Pilih Account</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->account_number }} - {{ $account->account_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nominal Bayar</label>
                            <input type="number" name="paid_amount" min="0.01" step="0.01" class="form-control" required>
                            @error('paid_amount') <div class="text-danger">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 text-end">
                            <button class="btn btn-primary">Simpan Pembayaran</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

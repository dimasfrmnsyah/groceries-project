@extends('layouts.app')

@section('css')
<link href="{{ asset('assets/plugins/datatable/css/dataTables.bootstrap5.min.css') }}" rel="stylesheet" />
@endsection

@section('content')
<div class="alert alert-info">
    Tanggal penjualan mengikuti tanggal Absen Masuk (WIB), termasuk transaksi setelah tengah malam dalam shift yang sama.
    Transaksi tanpa catatan shift memakai tanggal transaksi.
</div>
@php
  $sameMonth = ($monthFrom ?? '') === ($monthTo ?? '');
  $monthFromLabel = $monthFrom ? \Carbon\Carbon::createFromFormat('Y-m', $monthFrom)->format('M Y') : '-';
  $monthToLabel = $monthTo ? \Carbon\Carbon::createFromFormat('Y-m', $monthTo)->format('M Y') : '-';
@endphp

<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
  <div class="breadcrumb-title pe-3">Detail Penjualan Kasir</div>
</div>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <a href="{{ route('report.cashier.monthly', ['store' => $storeId]) }}" class="btn btn-outline-secondary btn-sm">
      Kembali
    </a>
    <div><strong>Kasir:</strong> {{ $cashierName ?: ($cashierKey ?: '-') }}</div>
    <div><strong>Periode:</strong> {{ $monthFromLabel }} - {{ $monthToLabel }}</div>
    @if($currentStoreName)
      <div><strong>Toko:</strong> {{ $currentStoreName }}</div>
    @endif
  </div>
</div>

@if(!$cashierKey)
  <div class="alert alert-warning">Kasir tidak valid.</div>
@else
  <input type="hidden" id="detail_cashier_key" value="{{ $cashierKey }}">
  <input type="hidden" id="detail_store" value="{{ $storeId ?? '' }}">
  <input type="hidden" id="detail_month_from" value="{{ $monthFrom }}">
  <input type="hidden" id="detail_month_to" value="{{ $monthTo }}">

  <div class="card mb-3">
    <div class="card-body">
      <h6 class="mb-3">Penjualan Bulan {{ $monthFromLabel }}</h6>
      <div class="table-responsive">
        <table id="table_month_from" class="table table-striped table-bordered w-100">
          <thead>
            <tr>
              <th>No</th>
              <th>Tanggal</th>
              <th>No Invoice</th>
              <th>Toko</th>
              <th>Total</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  @if(!$sameMonth)
  <div class="card">
    <div class="card-body">
      <h6 class="mb-3">Penjualan Bulan {{ $monthToLabel }}</h6>
      <div class="table-responsive">
        <table id="table_month_to" class="table table-striped table-bordered w-100">
          <thead>
            <tr>
              <th>No</th>
              <th>Tanggal</th>
              <th>No Invoice</th>
              <th>Toko</th>
              <th>Total</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
  @endif
@endif
@endsection

@section('scripts')
<script src="{{ asset('assets/plugins/datatable/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/plugins/datatable/js/dataTables.bootstrap5.min.js') }}"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>

<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';

  const cashierKey = $('#detail_cashier_key').val();
  const store = $('#detail_store').val();
  const monthFrom = $('#detail_month_from').val();
  const monthTo = $('#detail_month_to').val();

  const formatCurrency = (v) => {
    const rounded = Math.round(Number(v ?? 0));
    return 'Rp ' + rounded.toLocaleString('id-ID');
  };

  function buildTable(tableId, monthValue) {
    return $(tableId).DataTable({
      processing: true,
      serverSide: true,
      ajax: {
        url: "{{ route('report.cashier.monthly.detail.data') }}",
        data: function (d) {
          d.cashier_key = cashierKey || '';
          d.month = monthValue || '';
          d.store = store || '';
        }
      },
      columns: [
        { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable:false, searchable:false },
        {
          data: 'sale_date',
          name: 'sale_date',
          render: function (data) {
            return data ? moment(data).format('DD MMM YYYY') : '-';
          }
        },
        { data: 'no_invoice', name: 'no_invoice', render: d => d || '-' },
        { data: 'store_name', name: 'store_name', defaultContent: '-' },
        {
          data: 'total_price',
          name: 'total_price',
          className: 'text-end',
          render: function (data, type) {
            if (type === 'display' || type === 'filter') {
              return formatCurrency(data);
            }
            return data;
          }
        },
        { data: 'action', name: 'action', orderable:false, searchable:false, className: 'text-center' }
      ],
      order: [[1, 'desc']],
      pageLength: 25,
      language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' }
    });
  }

  if (cashierKey && monthFrom) {
    buildTable('#table_month_from', monthFrom);
  }
  if (cashierKey && monthTo && monthTo !== monthFrom && $('#table_month_to').length) {
    buildTable('#table_month_to', monthTo);
  }
});
</script>
@endsection

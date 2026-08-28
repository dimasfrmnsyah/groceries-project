@extends('layouts.app')

@section('css')
<link href="{{ asset('assets/plugins/datatable/css/dataTables.bootstrap5.min.css') }}" rel="stylesheet" />
@endsection

@section('content')
<div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
  <div class="breadcrumb-title pe-3">Detail Pendapatan Kasir</div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap gap-4">
      <div><strong>Kasir:</strong> {{ $cashier }}</div>
      <div><strong>Tanggal:</strong> {{ \Carbon\Carbon::parse($revenue->date)->format('d M Y') }}</div>
      <div><strong>Total (Daily):</strong> Rp {{ number_format($revenue->amount, 0, ',', '.') }}</div>
      <div><strong>QR:</strong> Rp {{ number_format((float) ($revenue->qr ?? 0), 0, ',', '.') }}</div>
      <div><strong>Pengeluaran:</strong> Rp {{ number_format((float) ($revenue->pengeluaran ?? 0), 0, ',', '.') }}</div>
    </div>
  </div>
</div>

@php
  $revenueDenominations = is_array($revenue->denominations ?? null)
      ? $revenue->denominations
      : (json_decode((string) ($revenue->denominations ?? ''), true) ?: []);
  $revenueDenominationOptions = \App\Support\CashDenominations::all();
@endphp
@if($revenueDenominations)
<div class="card mb-3">
  <div class="card-body">
    <h6 class="mb-3 text-uppercase">Rincian Uang Saat Logout</h6>
    <div class="row g-2">
      @foreach($revenueDenominationOptions as $key => $option)
        @if((int) ($revenueDenominations[$key] ?? 0) > 0)
          <div class="col-md-4 col-sm-6">
            <div class="border rounded p-2 d-flex justify-content-between gap-2">
              <span>{{ $option['label'] }}</span>
              <strong>{{ number_format((int) $revenueDenominations[$key], 0, ',', '.') }}</strong>
            </div>
          </div>
        @endif
      @endforeach
    </div>
  </div>
</div>
@endif

<div class="card">
  <div class="card-body">
    <h6 class="mb-2 text-uppercase">Rincian Barang Keluar (tb_outgoing_goods)</h6>
    <div class="table-responsive">
      <table id="table_detail" class="table table-striped table-bordered w-100">
        <thead>
          <tr>
            <th style="width:60px">No</th>
            <th>Produk</th>
            <th style="width:100px">Qty</th>
            <th style="width:140px">Harga</th>
            <th style="width:140px">Diskon</th>
            <th style="width:160px">Subtotal</th>
            <th style="width:140px">Tanggal</th>
            <th style="width:180px">Recorded By</th>
          </tr>
        </thead>
        <tbody></tbody>
        <tfoot>
          <tr>
            <th colspan="5" class="text-end">Total</th>
            <th id="tfoot_total" colspan="3">Rp 0</th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('assets/plugins/datatable/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/plugins/datatable/js/dataTables.bootstrap5.min.js') }}"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>

<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#table_detail').on('error.dt', function(e, settings, techNote, message) {
    console.log('DataTables error (detail):', message);
  });

  const table = $('#table_detail').DataTable({
    processing: true,
    serverSide: true,
    ajax: "{{ route('report.detail.data', $revenue->id) }}",
    columns: [
      { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable:false, searchable:false },
      { data: 'product_name', name: 'product_name', defaultContent: '-' },
      { data: 'quantity_out', name: 'quantity_out', className: 'text-end',
        render: (data, type) => (type === 'display' || type === 'filter')
          ? Number(data ?? 0).toLocaleString('id-ID') : data
      },
      {
        data: 'price', name: 'price', className: 'text-end',
        render: (data, type) => (type === 'display' || type === 'filter')
          ? 'Rp ' + Number(data ?? 0).toLocaleString('id-ID') : data
      },
      {
        data: 'discount', name: 'discount', className: 'text-end',
        render: (data, type) => (type === 'display' || type === 'filter')
          ? 'Rp ' + Number(data ?? 0).toLocaleString('id-ID') : data
      },
      {
        data: 'subtotal', name: 'subtotal', orderable:false, searchable:false, className: 'text-end',
        render: (data, type) => (type === 'display' || type === 'filter')
          ? 'Rp ' + Number(data ?? 0).toLocaleString('id-ID') : data
      },
      {
        data: 'date', name: 'date',
        render: (data) => data ? moment(data).format('DD MMM YYYY') : '-'
      },
      { data: 'recorded_by', name: 'recorded_by' },
    ],
    order: [[6, 'desc']], // urutkan berdasarkan tanggal
    pageLength: 25,
    language: {
      url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/id.json'
    },
    drawCallback: function() {
      let total = 0;
      this.api().column(5, {page: 'current'}).data().each(v => total += Number(v || 0));
      $('#tfoot_total').text('Rp ' + total.toLocaleString('id-ID'));
    }
  });
});
</script>
@endsection

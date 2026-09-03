@extends('layouts.app')
@section('css')
<link href="{{asset('assets/plugins/datatable/css/dataTables.bootstrap5.min.css')}}" rel="stylesheet" />
@endsection
@section('content')
    <!--breadcrumb-->
    <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
        <div class="breadcrumb-title pe-3">Toko</div>
        <div class="ps-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 p-0">
                    <li class="breadcrumb-item"><a href="{{route('store.index')}}"><i class="bx bx-home-alt"></i></a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">Index</li>
                </ol>
            </nav>
        </div>
        <div class="ms-auto">
            <div class="btn-group">
                <a href="{{route('store.create')}}" class="btn btn-success">
                    + Tambah
                </a>
                
            </div>
        </div>
    </div>
    <h6 class="mb-0 text-uppercase">Kelola Toko</h6>
    <hr/>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="table-brand" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Alamat Toko</th>
                            <th>Nama Toko</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

@endsection
@section('scripts')
<script src="{{asset('assets/plugins/datatable/js/jquery.dataTables.min.js')}}"></script>
<script src="{{asset('assets/plugins/datatable/js/dataTables.bootstrap5.min.js')}}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    const confirmDelete = (id) => {
    let token = $("meta[name='csrf-token']").attr("content");
    Swal.fire({
        title: 'Apakah Anda yakin?',
        text: "Data akan dihapus permanen!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Ya, hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: `/store/delete/${id}`,
                type: 'DELETE',
                data: {
                    _token: token, 
                },
                success: function(response) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sukses',
                        text: response.message,
                    });
                    $('#table-brand').DataTable().ajax.reload(); 
                },
                error: function(err) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: err.responseJSON.message || 'Terjadi kesalahan saat menghapus data!',
                    });
                }
            });
        }
    });
};

const toggleOnline = (storeId, desiredStatus) => {
    const note = !desiredStatus ? prompt('Catatan offline (opsional):', '') : '';
    $.ajax({
        url: `/store/${storeId}/toggle-online`,
        type: 'POST',
        data: {
            _token: $("meta[name='csrf-token']").attr("content"),
            is_online: desiredStatus ? 1 : 0,
            offline_note: note
        },
        success: function (resp) {
            let message = resp.message || 'Status diperbarui';
            if (desiredStatus && resp.released?.sales > 0) {
                message += ` ${resp.released.sales} penjualan pending dan ${resp.released.outgoing_rows} baris stok sudah diposting.`;
            }
            Swal.fire({ icon: 'success', title: 'Berhasil', text: message })
                .then(() => window.location.reload());
        },
        error: function (err) {
            Swal.fire({
                icon: 'error',
                title: 'Oops...',
                text: err.responseJSON?.message || 'Gagal memperbarui status'
            });
        }
    });
}
    $(document).ready(function() {
        $('#table-brand').DataTable({
            processing: true,
            serverSide: true,
            ajax: "{{url('/store')}}",
            columns: [
                {data: null,
                    render: function(data, type, row, meta){
                        return meta.row +meta.settings._iDisplayStart + 1;
                    }
                },
                {data:'store_address', name:'store_address'},
                {data:'store_name', name:'store_name'},
                {data:'status', name:'status', orderable:false, searchable:false,
                    render: function(data, type, row) {
                        // fallback to compute if html not provided
                        if (data && data.includes('badge')) return data;
                        const online = row.is_online;
                        return online
                            ? '<span class="badge bg-success">Online</span>'
                            : '<span class="badge bg-secondary">Offline</span>';
                    }
                },
                { data: 'action', name: 'action', orderable: false, searchable: false, className:'text-end' }

            ]
        })
    });

      @if(session('success'))
          Swal.fire({
              icon: 'success',
              title: 'Success!',
              text: '{{ session('success') }}',
          });
      @endif
</script>
@endsection

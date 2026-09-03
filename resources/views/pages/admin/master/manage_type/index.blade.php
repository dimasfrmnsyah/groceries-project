@extends('layouts.app')
@section('css')
<link href="{{asset('assets/plugins/datatable/css/dataTables.bootstrap5.min.css')}}" rel="stylesheet" />
@endsection
@section('content')
    <!--breadcrumb-->
    <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
        <div class="breadcrumb-title pe-3">Jenis</div>
        <div class="ps-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 p-0">
                    <li class="breadcrumb-item"><a href="{{route('master-types.index')}}"><i class="bx bx-home-alt"></i></a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">Index</li>
                </ol>
            </nav>
        </div>
        <div class="ms-auto">
            <div class="btn-group">
                <a href="{{route('master-types.create')}}" class="btn btn-success">
                    + Tambah
                </a>
                
            </div>
        </div>
    </div>
    <h6 class="mb-0 text-uppercase">Kelola Jenis</h6>
    <hr/>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="table-type" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Nama Tipe</th>
                            <th>Keterangan</th>
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

<script>
    const confirmDelete = (id) => {
    let token = $("meta[name='csrf-token']").attr("content");
    Swal.fire({
        title: 'Apakah Anda yakin?',
        text: "Jenis akan dinonaktifkan dari daftar pilihan. Data produk dan riwayat transaksi tetap aman.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Ya, hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: `/master-type/delete/${id}`,
                type: 'DELETE',
                data: {
                    _token: token, 
                },
                success: function(response) {
                    if (!response.success) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Tidak dapat dihapus',
                            text: response.message || 'Terjadi kesalahan saat menghapus data!',
                        });
                        return;
                    }

                    Swal.fire({
                        icon: 'success',
                        title: 'Sukses',
                        text: response.message,
                    }).then(() => {
                        $('#table-type').DataTable().ajax.reload(null, false);
                    });
                },
                error: function(err) {
                    Swal.fire({
                        icon: 'error',
                        title: err.status === 409 ? 'Tidak dapat dihapus' : 'Oops...',
                        text: err.responseJSON?.message || 'Terjadi kesalahan saat menghapus data!',
                    });
                }
            });
        }
    });
};
    $(document).ready(function() {
        $('#table-type').DataTable({
            processing: true,
            serverSide: true,
            ajax: "{{url('/master-type')}}",
            columns: [
                {data: null,
                    render: function(data, type, row, meta){
                        return meta.row +meta.settings._iDisplayStart + 1;
                    }
                },
                {data:'type_name', name:'type_name'},
                {data:'description', name:'description'},
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

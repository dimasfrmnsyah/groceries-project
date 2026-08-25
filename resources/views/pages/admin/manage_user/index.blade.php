@extends('layouts.app')
@section('css')
<link href="{{asset('assets/plugins/datatable/css/dataTables.bootstrap5.min.css')}}" rel="stylesheet" />
@endsection
@section('content')
    <!--breadcrumb-->
    <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
        <div class="breadcrumb-title pe-3">User</div>
        <div class="ps-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 p-0">
                    <li class="breadcrumb-item"><a href="{{route('user.index')}}"><i class="bx bx-home-alt"></i></a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">Index</li>
                </ol>
            </nav>
        </div>
        <div class="ms-auto">
            <div class="btn-group">
                <a href="{{route('user.create')}}" class="btn btn-success">
                    + Tambah
                </a>
            </div>
        </div>
    </div>
    <h6 class="mb-0 text-uppercase">Kelola User</h6>
    <hr/>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="table-type" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Nama User</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Lock Pendapatan</th>
                            <th>Store</th>
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
    const runDelete = () => {
        $.ajax({
            url: `/user/delete/${id}`,
            type: 'POST',
            headers: { 'X-CSRF-TOKEN': token },
            data: {
                _method: 'DELETE',
                _token: token,
            },
            success: function(response) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sukses',
                        text: response.message,
                    });
                }
                $('#table-type').DataTable().ajax.reload();
            },
            error: function(err) {
                const message = err.responseJSON?.message || 'Terjadi kesalahan saat menghapus data!';
                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Oops...',
                        text: message,
                    });
                } else {
                    alert(message);
                }
            }
        });
    };
    if (!window.Swal) {
        if (confirm('Data akan dihapus permanen! Lanjutkan?')) runDelete();
        return;
    }
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
            runDelete();
        }
    });
};
    $(document).ready(function() {
        $('#table-type').DataTable({
            processing: true,
            serverSide: true,
            ajax: "{{url('/user')}}",
            columns: [
                {data: null,
                    render: function(data, type, row, meta){
                        return meta.row +meta.settings._iDisplayStart + 1;
                    }
                },
                {data:'name', name:'name'},
                {data:'email', name:'email'},
                {data:'roles', name:'roles'},
                {
                    data: 'is_lock',
                    name: 'is_lock',
                    orderable: false,
                    searchable: false,
                    render: function(data) {
                        return Number(data) === 1
                            ? '<span class="badge bg-warning text-dark">Locked</span>'
                            : '<span class="badge bg-light text-secondary border">Tidak</span>';
                    }
                },
                {data:'store_names', name:'store_names', defaultContent: '-',},
                {data: 'action', name: 'action', orderable: false, searchable: false, className:'text-end'}

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

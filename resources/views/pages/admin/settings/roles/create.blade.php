@extends('layouts.app')
@section('css')
@section('content')
    <!--breadcrumb-->
    <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
        <div class="breadcrumb-title pe-3">Role</div>
        <div class="ps-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 p-0">
                    <li class="breadcrumb-item"><a href="{{route('settings.roles.index')}}"><i class="bx bx-home-alt"></i></a>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">{{isset($role) ? 'Edit' : 'Create'}}</li>
                </ol>
            </nav>
        </div>
        <div class="ms-auto">
        </div>
    </div>
    <div class="row">
      <div class="col-xl-9 mx-auto">
        <h6 class="mb-0 text-uppercase">{{ isset($role) ? 'Edit' : 'Tambah'}} Role</h6>
        <hr/>
        <div class="card">
          <div class="card-body">
            <form action="{{isset($role) ? route('settings.roles.update', $role->id) : route('settings.roles.store')}}" method="POST">
            <div class="row">
                @csrf
                @if(isset($role))
                  @method('PUT') 
                @endif
                <div class="col-6 mb-3">
                  <label for="name_type">Nama Role</label>
                  <input class="form-control form-control" 
                  type="text" 
                  name="role_name" 
                  value="{{ old('role_name', $role->role_name ?? '') }}"
                  placeholder="Contoh: supervisor gudang"
                  required>

                  <small class="text-muted">Nama disimpan dalam format lowercase, misalnya <code>supervisor_gudang</code>.</small>

                  @error('role_name')
                      <div class="text-danger">{{ $message }}</div>
                  @enderror
                </div>
                @if(isset($role))
                <div class="col-6 mb-3">
                  <label for="description">Status</label>
                  <select class="form-select" name="is_active" >
                    <option value="1" {{ (isset($role) && $role->is_active == 1) || old('is_active') == '1' ? 'selected' : '' }}>Aktif</option>
                    <option value="0" {{ (isset($role) && $role->is_active == 0) || old('is_active') == '0' ? 'selected' : '' }}>Non Aktif</option>
                  </select>
                  @error('is_active')
                      <div class="text-danger">{{ $message }}</div>
                  @enderror
                </div>
                @endif

                <div class="col-12 text-end">
                  <button class="btn btn-primary" type="submit">{{isset($role) ? 'Update' : 'Tambah'}}</button>
                </div>
            </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    @section('scripts')
    <script>
      @if(session('success'))
          Swal.fire({
              icon: 'success',
              title: 'Success!',
              text: '{{ session('success') }}',
          });
      @endif

      @if($errors->any())
          Swal.fire({
              icon: 'error',
              title: 'Oops...',
              text: 'Form yang anda inputkan error', // Menampilkan pesan error pertama
          });
      @endif
    </script>
    @endsection
@endsection

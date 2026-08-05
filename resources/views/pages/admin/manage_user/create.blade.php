@extends('layouts.app')
@section('css')
@section('content')
  <!--breadcrumb-->
  <div class="page-breadcrumb d-none d-sm-flex align-items-center mb-3">
    <div class="breadcrumb-title pe-3">User</div>
    <div class="ps-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0 p-0">
      <li class="breadcrumb-item"><a href="{{route('user.index')}}"><i class="bx bx-home-alt"></i></a>
      </li>
      <li class="breadcrumb-item active" aria-current="page">{{isset($user) ? 'Edit' : 'Create'}}</li>
      </ol>
    </nav>
    </div>
    <div class="ms-auto">
    </div>
  </div>
  <div class="row">
    <div class="col-xl-9 mx-auto">
    <h6 class="mb-0 text-uppercase">{{ isset($user) ? 'Edit' : 'Tambah'}} User</h6>
    <hr />
    <div class="card">
      <div class="card-body">
      <form action="{{isset($user) ? route('user.update', $user->id) : route('user.store')}}" method="POST">
        <div class="row">
        @csrf
        @if(isset($user))
      @method('PUT')
      @endif
        <div class="col-6 mb-3">
          <label for="name">Nama User</label>
          <input class="form-control" type="text" name="name"
          value="{{ isset($user) ? $user->name : old('name') }}">

          @error('name')
        <div class="text-danger">{{ $message }}</div>
      @enderror
        </div>
        <div class="col-6 mb-3">
          <label for="name">Email</label>
          <input class="form-control" type="email" name="email"
          value="{{ isset($user) ? $user->email : old('email') }}">

          @error('email')
        <div class="text-danger">{{ $message }}</div>
      @enderror
        </div>
        @if(!isset($user))
        <div class="col-6 mb-3">
        <label for="name">Password</label>
        <input class="form-control" type="password" name="password"
        value="{{ isset($user) ? $user->password : old('password') }}">

        @error('password')
        <div class="text-danger">{{ $message }}</div>
      @enderror
        </div>
        <div class="col-6 mb-3">
        <label for="name">Retype Password</label>
        <input class="form-control" type="password" name="password_confirmation"
        value="{{ isset($user) ? $user->password : old('password_confirmation') }}">

        @error('password_confirmation')
        <div class="text-danger">{{ $message }}</div>
      @enderror
        </div>
        @endif
        <div class="col-6 mb-3">
          <div class="d-flex align-items-center justify-content-between gap-2">
            <label for="role-select" class="mb-0">Role</label>
            @if(strtolower((string) auth()->user()->roles) === 'superadmin')
              <a href="{{ route('settings.roles.create') }}" class="small text-primary">
                <i class="bx bx-plus-circle me-1"></i>Tambah role baru
              </a>
            @endif
          </div>
          <select class="form-select" name="roles" id="role-select">
            <option value="">Pilih Role</option>
            @php
              $selectedRole = strtolower((string) old('roles', $user->roles ?? ''));
            @endphp
            @foreach($availableRoles as $availableRole)
              <option value="{{ $availableRole }}" @selected($selectedRole === $availableRole)>{{ ucwords(str_replace(['-', '_'], ' ', $availableRole)) }}</option>
            @endforeach
          </select>


          @error('roles')
        <div class="text-danger">{{ $message }}</div>
      @enderror
        </div>
        <div class="col-6 mb-3">
          <label for="name">Store</label>
          @php
            $selectedStoreIdCollection = collect(old('store_ids', $selectedStoreIds ?? []))
                ->filter()
                ->map(fn ($id) => (int) $id);
            if ($selectedStoreIdCollection->isEmpty() && old('store_id')) {
                $selectedStoreIdCollection = collect([(int) old('store_id')]);
            }
            if ($selectedStoreIdCollection->isEmpty() && isset($user) && $user->store_id) {
                $selectedStoreIdCollection = collect([(int) $user->store_id]);
            }
            $selectedStoreIdSingle = $selectedStoreIdCollection->first();
          @endphp
          <div id="store-multi-wrap" class="border rounded p-2" style="max-height: 220px; overflow: auto;">
            @foreach ($stores as $store)
              <div class="form-check">
                <input class="form-check-input store-checkbox"
                       type="checkbox"
                       name="store_ids[]"
                       id="store-check-{{ $store->id }}"
                       value="{{ $store->id }}"
                       {{ $selectedStoreIdCollection->contains((int) $store->id) ? 'checked' : '' }}>
                <label class="form-check-label" for="store-check-{{ $store->id }}">
                  {{ $store->store_name }}
                </label>
              </div>
            @endforeach
          </div>
          <div id="store-single-wrap" class="d-none">
            <select name="store_ids[]" class="form-select" id="store-single">
              <option value="">Pilih Toko</option>
              @foreach ($stores as $store)
                <option value="{{ $store->id }}" {{ (int) $selectedStoreIdSingle === (int) $store->id ? 'selected' : '' }}>
                  {{ $store->store_name }}
                </option>
              @endforeach
            </select>
          </div>
          <small class="text-muted" id="store-help">Admin bisa pilih lebih dari satu toko (klik satu-satu).</small>

          @error('store_ids')
        <div class="text-danger">{{ $message }}</div>
      @enderror
          @error('store_id')
        <div class="text-danger">{{ $message }}</div>
      @enderror
        </div>

        <div class="col-12 text-end">
          <button class="btn btn-primary" type="submit">{{isset($user) ? 'Update' : 'Tambah'}}</button>
        </div>
        </div>
      </form>

      </div>
    </div>
    @if(isset($user))
    <div class="card">
      <div class="card-body">
      <form action="{{route('user.update.password', $user->id)}}" method="POST">
      <div class="row">
      @csrf
      @method('PUT')
      <div class="col-12 mb-3">
        <label for="name">Password</label>
        <input class="form-control" type="password" name="new_password" value="">

        @error('new_password')
      <div class="text-danger">{{ $message }}</div>
      @enderror
      </div>
      <div class="col-12 mb-3">
        <label for="name">Retype Password</label>
        <input class="form-control" type="password" name="new_password_confirmation" value="">

        @error('new_password_confirmation')
      <div class="text-danger">{{ $message }}</div>
      @enderror
      </div>
      <div class="col-12 text-end">
        <button class="btn btn-primary" type="submit">Update Password</button>
      </div>
      </div>
      </form>
      </div>
    </div>
    @endif
    </div>
  </div>

  @section('scripts')
    <script>
    const roleSelect = document.getElementById('role-select');
    const storeMultiWrap = document.getElementById('store-multi-wrap');
    const storeSingleWrap = document.getElementById('store-single-wrap');
    const storeSingleSelect = document.getElementById('store-single');
    const storeCheckboxes = document.querySelectorAll('.store-checkbox');
    const storeHelp = document.getElementById('store-help');

    const setStoreMode = (role) => {
      if (!storeHelp || !storeMultiWrap || !storeSingleWrap || !storeSingleSelect) return;
      const normalized = String(role || '').toLowerCase();
      const isAdmin = normalized === 'admin';
      const isStaff = ['staff', 'kasir', 'cashier'].includes(normalized);
      const isSuperadmin = normalized === 'superadmin';

      if (isSuperadmin) {
        storeMultiWrap.classList.add('d-none');
        storeSingleWrap.classList.add('d-none');
        storeCheckboxes.forEach((cb) => { cb.disabled = true; });
        storeSingleSelect.disabled = true;
        storeHelp.textContent = 'Superadmin otomatis akses semua toko.';
      } else if (isAdmin) {
        storeMultiWrap.classList.remove('d-none');
        storeSingleWrap.classList.add('d-none');
        storeCheckboxes.forEach((cb) => { cb.disabled = false; });
        storeSingleSelect.disabled = true;
        const selectedValue = storeSingleSelect.value;
        if (selectedValue) {
          const match = Array.from(storeCheckboxes).find((cb) => cb.value === selectedValue);
          if (match) match.checked = true;
        }
        storeHelp.textContent = 'Admin bisa pilih lebih dari satu toko (klik satu-satu).';
      } else if (isStaff) {
        storeMultiWrap.classList.add('d-none');
        storeSingleWrap.classList.remove('d-none');
        storeCheckboxes.forEach((cb) => { cb.disabled = true; });
        storeSingleSelect.disabled = false;
        const firstChecked = Array.from(storeCheckboxes).find((cb) => cb.checked);
        if (firstChecked) {
          storeSingleSelect.value = firstChecked.value;
        }
        storeHelp.textContent = 'Staff hanya boleh 1 toko.';
      } else {
        storeMultiWrap.classList.add('d-none');
        storeSingleWrap.classList.remove('d-none');
        storeCheckboxes.forEach((cb) => { cb.disabled = true; });
        storeSingleSelect.disabled = false;
        storeHelp.textContent = 'Pilih satu toko.';
      }
    };

    if (roleSelect) {
      setStoreMode(roleSelect.value);
      roleSelect.addEventListener('change', (e) => setStoreMode(e.target.value));
    }

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

@extends('layouts.app')

@section('content')
@php
    $duration = fn ($seconds) => \App\Models\Attendance::formatDuration($seconds === null ? null : (int) $seconds);
    $storeNames = $stores->pluck('store_name', 'id');
@endphp
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <div>
        <h4 class="mb-1"><i class="bx bx-time-five me-1"></i> Absensi Karyawan</h4>
        <p class="text-muted mb-0">Absensi harian dan rekap lembur bulanan</p>
    </div>
    <span class="badge bg-light text-dark border px-3 py-2">Jam normal: 8 jam / hari · WIB</span>
</div>
@if($errors->any())
    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
@endif
<div class="card">
    <div class="card-body">
        <form method="GET" action="{{ route('attendance.index') }}" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="attendance-month" class="form-label">Bulan masuk</label>
                <input id="attendance-month" type="month" name="month" class="form-control" value="{{ $month }}" required>
            </div>
            <div class="col-md-3">
                <label for="attendance-store" class="form-label">Toko</label>
                <select id="attendance-store" name="store" class="form-select">
                    <option value="">Semua toko yang dapat diakses</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" @selected((int) $storeId === (int) $store->id)>{{ $store->store_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="attendance-employee" class="form-label">Karyawan</label>
                <select id="attendance-employee" name="employee" class="form-select">
                    <option value="">Semua karyawan</option>
                    @foreach($employees as $person)
                        <option value="{{ $person->user_id }}" @selected((string) $employee === (string) $person->user_id)>{{ $person->employee_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="bx bx-filter-alt"></i> Tampilkan</button>
                <a href="{{ route('attendance.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-start border-4 border-primary"><div class="card-body">
        <p class="text-muted mb-2">Hari selesai</p>
        <h4 class="mb-0">{{ (int) $totals->shift_count - (int) $totals->open_count }}</h4>
    </div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-start border-4 border-info"><div class="card-body">
        <p class="text-muted mb-2">Total durasi kerja</p>
        <h5 class="mb-0">{{ $duration($totals->duration_seconds) }}</h5>
    </div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-start border-4 border-success"><div class="card-body">
        <p class="text-muted mb-2">Total lembur · {{ $month }}</p>
        <h5 class="text-success mb-0">{{ $duration($totals->overtime_seconds) }}</h5>
    </div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-start border-4 border-warning"><div class="card-body">
        <p class="text-muted mb-2">Belum absen keluar</p>
        <h4 class="mb-0">{{ (int) $totals->open_count }}</h4>
    </div></div></div>
</div>
<div class="alert alert-info d-flex gap-2" role="note">
    <i class="bx bx-info-circle fs-5"></i>
    <div>Rekap mengikuti tanggal <strong>absen masuk dalam WIB</strong>. Sesi karyawan pada tanggal dan toko yang sama digabung menjadi satu absensi harian.
        Durasi dijumlahkan dari sesi yang sudah selesai; jeda antar-sesi tidak dihitung. Jam normal maksimal 8 jam per hari, selebihnya lembur.
        Sesi tetap aktif melewati tengah malam sampai karyawan menekan Absen Keluar.</div>
</div>
<div class="card">
    <div class="card-body">
        <h5 class="mb-3">Rekap per karyawan <span class="text-muted fw-normal">· {{ $month }}</span></h5>
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead class="table-light"><tr>
                    <th scope="col">Karyawan</th><th scope="col">Hari selesai</th><th scope="col">Belum keluar</th>
                    <th scope="col">Total kerja</th><th scope="col">Jam normal</th><th scope="col">Total lembur</th><th scope="col">Detail</th>
                </tr></thead>
                <tbody>
                @forelse($summaries as $summary)
                    <tr>
                        <td class="fw-semibold">{{ $summary->employee_name }}</td>
                        <td>{{ (int) $summary->shift_count - (int) $summary->open_count }}</td>
                        <td>{{ (int) $summary->open_count }}</td>
                        <td class="text-nowrap">{{ $duration($summary->duration_seconds) }}</td>
                        <td class="text-nowrap">{{ $duration($summary->normal_seconds) }}</td>
                        <td class="text-nowrap fw-semibold text-success">{{ $duration($summary->overtime_seconds) }}</td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('attendance.index', ['month' => $month, 'store' => $storeId, 'employee' => $summary->user_id]) }}#attendance-details">Lihat harian</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">Belum ada absensi untuk filter yang dipilih.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $summaries->links('pagination::bootstrap-5') }}
    </div>
</div>
<div class="card" id="attendance-details">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Detail absensi</h5><span class="text-muted">{{ $rows->total() }} absensi harian</span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-bordered align-middle">
                <thead class="table-light"><tr>
                    <th scope="col">Karyawan / Toko</th><th scope="col">Tanggal masuk</th><th scope="col">Masuk pertama</th><th scope="col">Keluar terakhir</th>
                    <th scope="col">Durasi kerja</th><th scope="col">Jam normal</th><th scope="col">Lembur</th><th scope="col">Keterangan</th>
                </tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td><span class="fw-semibold">{{ $row->employee_name }}</span><small class="d-block text-muted">{{ $storeNames[$row->store_id] ?? 'Toko tidak tersedia' }}</small></td>
                        <td class="text-nowrap">{{ \Carbon\CarbonImmutable::parse($row->attendance_date)->format('d/m/Y') }}<small class="d-block text-muted">{{ $row->session_count }} sesi</small></td>
                        <td class="text-nowrap">{{ $row->localTime('checked_in_at') }}</td>
                        <td class="text-nowrap">{{ $row->localTime('checked_out_at') ?? '—' }}</td>
                        <td class="text-nowrap">{{ $duration($row->duration_seconds) }}</td>
                        <td class="text-nowrap">{{ $duration($row->normal_seconds) }}</td>
                        <td class="text-nowrap">{{ $duration($row->overtime_seconds) }}</td>
                        <td>
                            <span class="badge {{ !$row->checked_out_at ? 'bg-warning text-dark' : ($row->overtime_seconds > 0 ? 'bg-success' : 'bg-secondary') }}">{{ $row->description() }}</span>
                            <details class="mt-2">
                                <summary class="text-primary" style="cursor:pointer">Rincian {{ $row->session_count }} sesi</summary>
                                <ol class="ps-3 mt-2 mb-0">
                                    @foreach($row->sessions as $session)
                                        <li class="mb-2 small text-nowrap">
                                            {{ $session->localTime('checked_in_at') }}<br>
                                            → {{ $session->localTime('checked_out_at') ?? 'Belum absen keluar' }}<br>
                                            Durasi: {{ $duration($session->duration_seconds) }}
                                        </li>
                                    @endforeach
                                </ol>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">Belum ada riwayat absensi.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links('pagination::bootstrap-5') }}
    </div>
</div>
@endsection

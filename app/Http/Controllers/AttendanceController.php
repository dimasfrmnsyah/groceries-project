<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\User;
use App\Support\AttendanceDailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    private function cashier(Request $request): void
    {
        abort_unless(in_array(strtolower(trim((string) $request->user()->roles)), Attendance::CASHIER_ROLES, true), 403);
    }

    public function status(Request $request)
    {
        $this->cashier($request);
        $query = Attendance::where('user_id', $request->user()->id);
        $row = (clone $query)->whereNull('checked_out_at')->first()
            ?? $query->orderByDesc('id')->first();

        return response()->json(['attendance' => $row ? $this->summary($row) : null])->header('Cache-Control', 'no-store');
    }

    public function checkIn(Request $request)
    {
        $this->cashier($request);
        $data = $request->validate(['request_key' => 'required|uuid']);
        $user = $request->user();

        $row = DB::transaction(function () use ($user, $data) {
            // All check-in/out writes for this employee serialize on the user row.
            $lockedUser = DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            abort_unless($lockedUser, 403);
            $existing = Attendance::where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless((string) $existing->user_id === (string) $user->id, 409, 'Permintaan absensi tidak valid. Muat ulang status.');
                return $existing;
            }
            abort_if(Attendance::where('active_user_id', $user->id)->exists(), 409, 'Masih ada absensi aktif. Silakan absen keluar terlebih dahulu.');
            $storeId = store_access_resolve_id(new Request(), $user);
            abort_unless($storeId, 422, 'Akun belum memiliki toko. Hubungi admin.');

            return Attendance::create([
                'user_id' => $user->id,
                'active_user_id' => $user->id,
                'store_id' => $storeId,
                'employee_name' => $user->name,
                'request_key' => $data['request_key'],
                'checked_in_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
        }, 3);

        return response()->json(['attendance' => $this->summary($row)]);
    }

    public function checkOut(Request $request)
    {
        $this->cashier($request);
        $data = $request->validate([
            'attendance_id' => 'required|integer|min:1',
            'overtime_minutes' => 'required|integer|min:0|max:525600',
        ], ['overtime_minutes.required' => 'Isi durasi lembur dalam menit, atau 0 jika tidak lembur.']);
        $user = $request->user();
        $row = DB::transaction(function () use ($user, $data) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $row = Attendance::where('user_id', $user->id)->whereKey($data['attendance_id'])->lockForUpdate()->firstOrFail();
            if ($row->checked_out_at) {
                return $row;
            }
            $end = now('UTC');
            $start = CarbonImmutable::parse($row->checked_in_at, 'UTC');
            abort_if($end->lt($start), 409, 'Waktu server lebih awal dari waktu masuk. Silakan hubungi admin.');
            $seconds = (int) $start->diffInSeconds($end);
            $overtime = (int) $data['overtime_minutes'] * 60;
            if ($overtime > $seconds) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'overtime_minutes' => 'Lembur tidak boleh melebihi durasi sesi kerja.',
                ]);
            }
            $row->update([
                'checked_out_at' => $end->format('Y-m-d H:i:s'),
                'active_user_id' => null,
                'duration_seconds' => $seconds,
                'normal_seconds' => min($seconds - $overtime, Attendance::NORMAL_SECONDS),
                'overtime_seconds' => $overtime,
            ]);
            return $row;
        }, 3);

        return response()->json(['attendance' => $this->summary($row)]);
    }

    private function summary(Attendance $row): array
    {
        $start = CarbonImmutable::parse($row->checked_in_at, 'UTC')->setTimezone(Attendance::TIMEZONE)->startOfDay();
        $sessions = Attendance::where('user_id', $row->user_id)->where('store_id', $row->store_id)
            ->where('checked_in_at', '>=', $start->utc()->format('Y-m-d H:i:s'))
            ->where('checked_in_at', '<', $start->addDay()->utc()->format('Y-m-d H:i:s'));
        $daily = AttendanceDailyReport::query($sessions)->firstOrFail();

        return array_merge($row->summary(), ['daily' => array_merge($daily->summary(), [
            'date' => $daily->attendance_date,
            'session_count' => (int) $daily->session_count,
        ])]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array(strtolower(trim((string) $user->roles)), ['admin', 'superadmin'], true), 403);
        $filters = $request->validate([
            'month' => 'nullable|date_format:Y-m',
            'store' => 'nullable|integer|min:1',
            'employee' => 'nullable|string|max:255',
        ]);
        $month = $filters['month'] ?? now(Attendance::TIMEZONE)->format('Y-m');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, Attendance::TIMEZONE);
        $stores = store_access_list($user);
        $allowedIds = $stores->pluck('id')->map(fn ($id) => (int) $id)->all();
        $storeId = isset($filters['store']) ? (int) $filters['store'] : null;
        abort_if($storeId && !in_array($storeId, $allowedIds, true), 403);
        $employee = $filters['employee'] ?? null;

        $scope = Attendance::query()->whereIn('store_id', $allowedIds)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));
        // Attendance snapshots retain employee history after rename/deactivation.
        $historicalEmployees = (clone $scope)->select('user_id')->selectRaw('MAX(employee_name) as employee_name')
            ->groupBy('user_id')->get();
        $visibleStoreIds = $storeId ? [$storeId] : $allowedIds;
        $currentEmployees = User::query()
            ->whereIn(DB::raw('LOWER(TRIM(roles))'), Attendance::CASHIER_ROLES)
            ->where(function ($query) use ($visibleStoreIds) {
                $query->whereIn('store_id', $visibleStoreIds)->orWhereNull('store_id')->orWhere('store_id', 0);
            })
            ->get(['id', 'name', 'roles', 'store_id'])
            ->filter(function ($person) use ($visibleStoreIds) {
                // Match the same primary/fallback store rules used for check-in.
                $ids = $person->store_id ? [(int) $person->store_id] : store_access_ids($person);
                return count(array_intersect($ids, $visibleStoreIds)) > 0;
            })
            ->map(fn ($person) => (object) ['user_id' => $person->id, 'employee_name' => $person->name]);
        $employees = $currentEmployees->keyBy('user_id')
            ->union(collect($historicalEmployees->all())->keyBy('user_id'))->sortBy('employee_name')->values();
        $query = (clone $scope)
            ->where('checked_in_at', '>=', $start->utc()->format('Y-m-d H:i:s'))
            ->where('checked_in_at', '<', $start->addMonth()->utc()->format('Y-m-d H:i:s'))
            ->when($employee, fn ($q) => $q->where('user_id', $employee));
        $daily = AttendanceDailyReport::query($query);
        $dailyQuery = DB::query()->fromSub($daily, 'daily_totals');
        $aggregate = 'COUNT(*) as shift_count, '
            . 'SUM(CASE WHEN checked_out_at IS NULL THEN 1 ELSE 0 END) as open_count, '
            . 'COALESCE(SUM(duration_seconds), 0) as duration_seconds, '
            . 'COALESCE(SUM(normal_seconds), 0) as normal_seconds, '
            . 'COALESCE(SUM(overtime_seconds), 0) as overtime_seconds';
        $totals = (clone $dailyQuery)->selectRaw($aggregate)->first();
        $summaries = (clone $dailyQuery)->select('user_id')->selectRaw('MAX(employee_name) as employee_name, ' . $aggregate)
            ->groupBy('user_id')->orderBy('employee_name')->paginate(20, ['*'], 'summary_page')->withQueryString();
        $rows = $daily->orderByDesc('attendance_date')->orderBy('user_id')->orderBy('store_id')->paginate(25)->withQueryString();
        // Fetch original sessions only for the displayed daily rows, inside the same access scope.
        if ($rows->isNotEmpty()) {
            $sessionRows = (clone $query)->where(function ($outer) use ($rows) {
                foreach ($rows as $day) {
                    $start = CarbonImmutable::parse($day->attendance_date, Attendance::TIMEZONE);
                    $outer->orWhere(function ($q) use ($day, $start) {
                        $q->where('user_id', $day->user_id)->where('store_id', $day->store_id)
                            ->where('checked_in_at', '>=', $start->utc()->format('Y-m-d H:i:s'))
                            ->where('checked_in_at', '<', $start->addDay()->utc()->format('Y-m-d H:i:s'));
                    });
                }
            })->orderBy('checked_in_at')->get();
            foreach ($rows as $day) {
                $day->setRelation('sessions', $sessionRows->filter(fn ($session) =>
                    (string) $session->user_id === (string) $day->user_id
                    && (int) $session->store_id === (int) $day->store_id
                    && CarbonImmutable::parse($session->checked_in_at, 'UTC')->setTimezone(Attendance::TIMEZONE)->toDateString() === $day->attendance_date
                ));
            }
        }

        return view('pages.admin.attendance.index', compact('month', 'stores', 'storeId', 'employee', 'employees', 'totals', 'summaries', 'rows'));
    }
}

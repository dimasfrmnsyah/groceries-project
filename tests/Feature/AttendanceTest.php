<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'attendance_test', 'database.connections.attendance_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'sync.enabled' => false, 'app.timezone' => 'UTC']);
        foreach ([
            'CREATE TABLE users (id TEXT PRIMARY KEY, name TEXT, roles TEXT, store_id INTEGER, deleted_at TEXT)',
            'CREATE TABLE tb_stores (id INTEGER PRIMARY KEY, store_name TEXT, deleted_at TEXT)',
            'CREATE TABLE user_stores (user_id TEXT, store_id INTEGER)',
            'CREATE TABLE tb_master_menuses (id INTEGER PRIMARY KEY, menu_name TEXT, menu_path TEXT, menu_icon TEXT, parent_id INTEGER, sort INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tb_master_menu_roles (menu_id INTEGER, role_name TEXT, role_id INTEGER, created_at TEXT, updated_at TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('tb_stores')->insert([['id' => 1, 'store_name' => 'Toko A'], ['id' => 2, 'store_name' => 'Toko B']]);
        $this->actingAs($this->user('staff-1', 'staff', 1));
        // Real migration, isolated from the shop database.
        $path = database_path('migrations/2026_10_06_000001_create_attendances_table.php');
        if (file_exists($path)) {
            (require $path)->up();
        }
        Carbon::setTestNow(Carbon::parse('2026-10-06 08:00:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $id, string $role, ?int $store): User
    {
        DB::table('users')->updateOrInsert(['id' => $id], ['name' => $id, 'roles' => $role, 'store_id' => $store]);
        return User::findOrFail($id);
    }

    private function checkIn(?string $key = null)
    {
        return $this->postJson('/attendance/check-in', ['request_key' => $key ?? (string) Str::uuid()]);
    }

    public function test_each_cashier_role_can_check_in_and_status_survives_a_new_session(): void
    {
        foreach (['staff', 'kasir', 'cashier'] as $role) {
            $user = $this->user($role, $role, 1);
            $this->actingAs($user);
            $id = $this->checkIn()->assertOk()->json('attendance.id');
            $this->app['auth']->forgetGuards();
            $this->actingAs($user->fresh());
            $this->getJson('/attendance/status')->assertOk()->assertJsonPath('attendance.id', $id)
                ->assertJsonPath('attendance.status', 'Belum absen keluar');
        }
        $this->assertSame(3, Attendance::count());
    }

    public function test_duplicate_check_in_and_stale_retries_do_not_start_new_shifts(): void
    {
        $key = (string) Str::uuid();
        $id = $this->checkIn($key)->assertOk()->json('attendance.id');
        $this->checkIn($key)->assertOk()->assertJsonPath('attendance.id', $id);
        $this->checkIn()->assertStatus(409);
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk();
        $this->checkIn($key)->assertOk()->assertJsonPath('attendance.id', $id);
        $this->assertSame(1, Attendance::count());
        $newId = $this->checkIn()->assertOk()->json('attendance.id');
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk();
        $this->assertNull(Attendance::findOrFail($newId)->checked_out_at);
    }

    public function test_nine_and_half_hours_is_eight_normal_and_ninety_minutes_overtime(): void
    {
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(Carbon::parse('2026-10-06 17:30:00', 'Asia/Jakarta'));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 90])->assertOk()
            ->assertJsonPath('attendance.duration_seconds', 34200)
            ->assertJsonPath('attendance.normal_seconds', 28800)
            ->assertJsonPath('attendance.overtime_seconds', 5400)
            ->assertJsonPath('attendance.status', 'Lembur');
        Carbon::setTestNow(Carbon::parse('2026-10-06 18:30:00', 'Asia/Jakarta'));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk()
            ->assertJsonPath('attendance.duration_seconds', 34200);
    }

    public function test_short_and_exactly_eight_hour_shifts_have_no_overtime(): void
    {
        foreach ([60 => 'Kurang dari 8 jam', 28800 => 'Normal'] as $seconds => $status) {
            $id = $this->checkIn()->assertOk()->json('attendance.id');
            Carbon::setTestNow(now()->addSeconds($seconds));
            $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk()
                ->assertJsonPath('attendance.normal_seconds', $seconds)
                ->assertJsonPath('attendance.overtime_seconds', 0)
                ->assertJsonPath('attendance.status', $status);
        }
    }

    public function test_shift_remains_open_across_midnight_and_month_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 22:00:00', 'Asia/Jakarta'));
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00', 'Asia/Jakarta'));
        $this->getJson('/attendance/status')->assertOk()->assertJsonPath('attendance.id', $id)
            ->assertJsonPath('attendance.checked_out_at', null);
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 180])->assertOk()
            ->assertJsonPath('attendance.duration_seconds', 39600)
            ->assertJsonPath('attendance.overtime_seconds', 10800);
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->get('/attendance?month=2026-10')->assertOk()->assertViewHas('totals', fn ($v) => (int) $v->overtime_seconds === 10800);
        $this->get('/attendance?month=2026-11')->assertOk()->assertViewHas('totals', fn ($v) => (int) $v->overtime_seconds === 0);
    }

    public function test_admin_report_scopes_stores_and_employee_and_excludes_unfinished_hours(): void
    {
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addHours(10));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 120])->assertOk();
        $this->checkIn()->assertOk();
        $this->actingAs($this->user('staff-2', 'cashier', 2));
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addHours(12));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 240])->assertOk();
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->get('/attendance?month=2026-10')->assertOk()
            ->assertViewHas('totals', fn ($v) => (int) $v->overtime_seconds === 7200 && (int) $v->open_count === 1)
            ->assertViewHas('rows', fn ($v) => $v->total() === 1)
            ->assertSee('staff-1')->assertDontSee('staff-2');
        $this->get('/attendance?month=2026-10&store=2')->assertForbidden();
        $this->get('/attendance?month=2026-10&employee=staff-2')->assertOk()
            ->assertViewHas('rows', fn ($v) => $v->total() === 0);
        $this->actingAs($this->user('super', 'superadmin', null));
        $this->get('/attendance?month=2026-10')->assertOk()
            ->assertViewHas('totals', fn ($v) => (int) $v->overtime_seconds === 21600);
        $this->get('/attendance?month=2026-10&employee=staff-2')->assertOk()
            ->assertViewHas('totals', fn ($v) => (int) $v->overtime_seconds === 14400);
    }

    public function test_permissions_and_identity_are_enforced_on_server(): void
    {
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        $this->get('/attendance')->assertForbidden();
        $this->actingAs($this->user('other', 'kasir', 1));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertNotFound();
        foreach (['admin', 'superadmin', 'warehouse'] as $role) {
            $this->actingAs($this->user($role, $role, 1));
            $this->checkIn()->assertForbidden();
            $this->getJson('/attendance/status')->assertForbidden();
            $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertForbidden();
        }
        $this->actingAs($this->user('no-store', 'staff', null));
        $this->checkIn()->assertStatus(422);
    }

    public function test_validation_and_unauthenticated_access(): void
    {
        $this->postJson('/attendance/check-in', ['request_key' => 'bad'])->assertUnprocessable();
        $this->postJson('/attendance/check-out')->assertUnprocessable();
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->getJson('/attendance?month=2026-99')->assertUnprocessable();
        auth()->logout();
        $this->getJson('/attendance/status')->assertUnauthorized();
        $this->checkIn()->assertUnauthorized();
        $this->get('/attendance')->assertRedirect('/login');
    }

    public function test_cashier_layout_includes_attendance_and_existing_revenue_logout(): void
    {
        $this->view('layouts.app')->assertSee('id="attendance-widget"', false)
            ->assertSee('id="attendance-modal"', false)
            ->assertSee('id="revenueForm"', false)
            ->assertSee(route('staff.submitRevenueAndLogout'), false);
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->view('layouts.app')->assertDontSee('id="attendance-widget"', false);
    }

    public function test_menu_migration_grants_admin_access_and_can_be_rolled_back(): void
    {
        $migration = require database_path('migrations/2026_10_06_000002_add_attendance_menu.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('tb_master_menuses')->where('menu_path', 'attendance.index')->count());
        $this->assertSame(['admin', 'superadmin'], DB::table('tb_master_menu_roles')->orderBy('role_name')->pluck('role_name')->all());
        $migration->down();
        $this->assertSame(0, DB::table('tb_master_menu_roles')->count());
    }

    public function test_employee_dropdown_lists_cashiers_before_their_first_attendance(): void
    {
        $this->user('new-cashier', 'kasir', 1);
        $this->user('outside-cashier', 'cashier', 2);
        $this->user('warehouse-worker', 'warehouse', 1);
        $this->user('pivot-cashier', 'staff', null);
        DB::table('user_stores')->insert(['user_id' => 'pivot-cashier', 'store_id' => 1]);
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->get('/attendance')->assertOk()->assertViewHas('employees', function ($employees) {
            return $employees->pluck('user_id')->sort()->values()->all() === ['new-cashier', 'pivot-cashier', 'staff-1'];
        })->assertSee('new-cashier')->assertDontSee('outside-cashier')->assertDontSee('warehouse-worker');
        $this->actingAs($this->user('super', 'superadmin', null));
        $this->get('/attendance?store=2')->assertOk()->assertViewHas('employees', function ($employees) {
            return $employees->pluck('user_id')->all() === ['outside-cashier'];
        });
    }

    public function test_month_filter_uses_wib_not_utc_and_keeps_historical_employees(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00', 'Asia/Jakarta'));
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addHours(9));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 60])->assertOk();
        DB::table('users')->where('id', 'staff-1')->update(['deleted_at' => now()]);
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->get('/attendance?month=2026-11')->assertOk()->assertSee('staff-1')
            ->assertViewHas('totals', fn ($v) => (int) $v->overtime_seconds === 3600);
        $this->get('/attendance?month=2026-10')->assertOk()
            ->assertViewHas('rows', fn ($v) => $v->total() === 0);
    }

    public function test_same_day_sessions_merge_and_sum_manual_overtime(): void
    {
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'Asia/Jakarta'));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-06 13:00:00', 'Asia/Jakarta'));
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(Carbon::parse('2026-10-06 18:00:00', 'Asia/Jakarta'));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 60])->assertOk()
            ->assertJsonPath('attendance.daily.duration_seconds', 32400)
            ->assertJsonPath('attendance.daily.overtime_seconds', 3600);
        $this->assertSame(2, Attendance::count());
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->get('/attendance?month=2026-10')->assertOk()
            ->assertViewHas('rows', fn ($v) => $v->total() === 1 && (int) $v->first()->session_count === 2)
            ->assertViewHas('totals', fn ($v) => (int) $v->shift_count === 1 && (int) $v->duration_seconds === 32400
                && (int) $v->normal_seconds === 28800 && (int) $v->overtime_seconds === 3600);
    }

    public function test_different_wib_dates_remain_separate_even_on_same_utc_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 23:00:00', 'Asia/Jakarta'));
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addMinutes(30));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:00:00', 'Asia/Jakarta'));
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addMinutes(30));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk();
        $this->actingAs($this->user('admin', 'admin', 1));
        $this->get('/attendance?month=2026-10')->assertOk()->assertViewHas('rows', fn ($v) => $v->total() === 2);
    }
    public function test_long_shift_with_manual_zero_has_no_automatic_overtime(): void
    {
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addHours(10));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 0])->assertOk()
            ->assertJsonPath('attendance.overtime_seconds', 0)
            ->assertJsonPath('attendance.daily.overtime_seconds', 0);
    }

    public function test_manual_overtime_is_required_and_must_be_nonnegative_integer(): void
    {
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addHours(2));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id])->assertUnprocessable();
        foreach ([-1, 1.5] as $minutes) {
            $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => $minutes])->assertUnprocessable();
        }
        $this->assertNull(Attendance::findOrFail($id)->checked_out_at);
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 30])->assertOk()
            ->assertJsonPath('attendance.overtime_seconds', 1800)
            ->assertJsonPath('attendance.daily.overtime_seconds', 1800);
    }

    public function test_manual_overtime_can_exceed_session_without_negative_normal_hours(): void
    {
        $id = $this->checkIn()->assertOk()->json('attendance.id');
        Carbon::setTestNow(now()->addHours(1));
        $this->postJson('/attendance/check-out', ['attendance_id' => $id, 'overtime_minutes' => 150])->assertOk()
            ->assertJsonPath('attendance.duration_seconds', 3600)
            ->assertJsonPath('attendance.overtime_seconds', 9000)
            ->assertJsonPath('attendance.normal_seconds', 0)
            ->assertJsonPath('attendance.daily.overtime_seconds', 9000)
            ->assertJsonPath('attendance.daily.normal_seconds', 0);
    }

}

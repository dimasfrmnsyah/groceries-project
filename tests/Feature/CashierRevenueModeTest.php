<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CashDenominations;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashierRevenueModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'revenue_test', 'database.connections.revenue_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'sync.enabled' => false]);
        foreach ([
            'CREATE TABLE users (id TEXT PRIMARY KEY, uuid TEXT, name TEXT, email TEXT, password TEXT, roles TEXT, store_id INTEGER, is_lock INTEGER DEFAULT 0, deleted_at TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE TABLE tb_stores (id INTEGER PRIMARY KEY, store_name TEXT, deleted_at TEXT)',
            'CREATE TABLE daily_revenues (id INTEGER PRIMARY KEY, uuid TEXT, user_id TEXT, store_id INTEGER, date TEXT, amount NUMERIC, qr NUMERIC, pengeluaran NUMERIC, denominations TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_sells (id INTEGER PRIMARY KEY, store_id INTEGER, date TEXT, created_by TEXT, total_price NUMERIC, no_invoice TEXT, deleted_at TEXT)',
            'CREATE TABLE tb_outgoing_goods (id INTEGER PRIMARY KEY, sell_id INTEGER, created_by TEXT, recorded_by TEXT, quantity_out INTEGER, deleted_at TEXT)',
            'CREATE TABLE tb_master_menuses (id INTEGER PRIMARY KEY, menu_path TEXT, is_active INTEGER)',
            'CREATE TABLE tb_master_menu_roles (menu_id INTEGER, role_name TEXT)',
        ] as $sql) {
            DB::statement($sql);
        }
        DB::table('tb_stores')->insert(['id' => 1, 'store_name' => 'Toko A']);
        DB::table('users')->insert(['id' => 'cashier', 'name' => 'Kasir', 'email' => 'kasir@example.test', 'roles' => 'staff', 'store_id' => 1]);
        $migration = database_path('migrations/2026_10_06_000003_add_revenue_simple_mode_to_users.php');
        if (file_exists($migration)) (require $migration)->up();
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'Asia/Jakarta'));
        DB::table('tb_sells')->insert(['id' => 1, 'store_id' => 1, 'date' => '2026-10-06', 'created_by' => 'cashier', 'total_price' => 125000, 'no_invoice' => 'INV-TEST']);
        DB::table('tb_outgoing_goods')->insert(['id' => 1, 'sell_id' => 1, 'created_by' => 'cashier', 'recorded_by' => 'Kasir', 'quantity_out' => 1]);
        $this->actingAs(User::findOrFail('cashier'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mode(bool $simple, bool $locked): void
    {
        DB::table('users')->where('id', 'cashier')->update(['revenue_simple_mode' => $simple, 'is_lock' => $locked]);
        $this->actingAs(User::findOrFail('cashier'));
    }

    private function details(int $amount = 125000): array
    {
        $counts = array_fill_keys(array_keys(CashDenominations::all()), 0);
        $counts['note_100000'] = 1;
        return ['amount' => $amount, 'denominations' => $counts, 'qr' => 20000, 'pengeluaran' => 5000];
    }

    public function test_simple_mode_defaults_on_for_existing_and_new_users(): void
    {
        $this->assertTrue(User::findOrFail('cashier')->revenue_simple_mode);
        DB::table('users')->insert(['id' => 'new', 'name' => 'New', 'roles' => 'kasir']);
        $this->assertTrue(User::findOrFail('new')->revenue_simple_mode);
    }

    public function test_simple_unlocked_mode_accepts_total_without_denominations(): void
    {
        $this->mode(true, false);
        $this->post('/staff/logout-revenue', ['amount' => 100000])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertEquals(100000, DB::table('daily_revenues')->value('amount'));
        $this->assertNull(DB::table('daily_revenues')->value('denominations'));
    }

    public function test_simple_locked_mode_rejects_mismatch_and_accepts_matching_total(): void
    {
        $this->mode(true, true);
        $this->from('/')->post('/staff/logout-revenue', ['amount' => 100000])->assertRedirect('/')->assertSessionHas('revenue_error');
        $this->assertAuthenticated();
        $this->assertSame(0, DB::table('daily_revenues')->count());
        $this->post('/staff/logout-revenue', ['amount' => 125000])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertEquals(125000, DB::table('daily_revenues')->value('amount'));
    }

    public function test_detailed_mode_requires_pecahan_even_when_unlocked_and_cannot_be_overridden_by_request(): void
    {
        $this->mode(false, false);
        $this->postJson('/staff/logout-revenue', ['amount' => 125000, 'revenue_simple_mode' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('denominations');
        $this->postJson('/staff/logout-revenue', ['amount' => 125000, 'denominations' => ['note_100000' => 1]])->assertUnprocessable();
        $this->assertAuthenticated();
        $this->assertSame(0, DB::table('daily_revenues')->count());
    }

    public function test_detailed_unlocked_mode_rejects_forged_total_but_does_not_require_sales_match(): void
    {
        $this->mode(false, false);
        $this->postJson('/staff/logout-revenue', $this->details(999999))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $details = $this->details(105000);
        $details['qr'] = 0;
        $this->post('/staff/logout-revenue', $details)->assertRedirect('/login');
        $this->assertGuest();
        $this->assertEquals(105000, DB::table('daily_revenues')->value('amount'));
        $this->assertEquals(1, json_decode(DB::table('daily_revenues')->value('denominations'), true)['note_100000']);
    }

    public function test_detailed_locked_mode_requires_matching_breakdown_total_and_sales(): void
    {
        $this->mode(false, true);
        $this->postJson('/staff/logout-revenue', $this->details(1))->assertUnprocessable();
        $details = $this->details(105000);
        $details['qr'] = 0;
        $this->from('/')->post('/staff/logout-revenue', $details)->assertSessionHas('revenue_error');
        $this->assertSame(0, DB::table('daily_revenues')->count());
        $this->post('/staff/logout-revenue', $this->details())->assertRedirect('/login');
        $this->assertGuest();
        $row = DB::table('daily_revenues')->first();
        $this->assertEquals(125000, $row->amount);
        $this->assertEquals(20000, $row->qr);
        $this->assertEquals(5000, $row->pengeluaran);
    }

    public function test_simple_mode_does_not_invent_breakdown_from_stale_browser_fields(): void
    {
        $this->mode(true, true);
        $details = $this->details();
        $details['denominations']['note_100000'] = 9;
        $this->post('/staff/logout-revenue', $details)->assertRedirect('/login');
        $row = DB::table('daily_revenues')->first();
        $this->assertEquals(125000, $row->amount);
        $this->assertNull($row->denominations);
        $this->assertEquals(0, $row->qr);
        $this->assertEquals(0, $row->pengeluaran);
    }

    public function test_amount_is_required_and_must_be_nonnegative_money(): void
    {
        $this->mode(true, false);
        foreach ([null, '', -1, 'abc', '1.234', '1e3', '1000000000000'] as $amount) {
            $this->postJson('/staff/logout-revenue', ['amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->post('/staff/logout-revenue', ['amount' => 0])->assertRedirect('/login');
    }

    public function test_live_match_check_still_checks_simple_amount(): void
    {
        $this->mode(true, true);
        $this->getJson('/check-daily-revenue?amount=125000')->assertOk()->assertJsonPath('matches', true);
        $this->getJson('/check-daily-revenue?amount=0')->assertOk()->assertJsonPath('matches', false);
    }

    public function test_direct_logout_cannot_skip_required_breakdown(): void
    {
        $this->mode(false, false);
        $this->from('/')->post('/logout')->assertRedirect('/')->assertSessionHas('revenue_error');
        $this->assertAuthenticated();
    }

    public function test_cashier_modal_only_renders_the_configured_input_mode(): void
    {
        $this->mode(true, true);
        $this->view('layouts.header')->assertSee('Total pendapatan')->assertSee('type="number" name="amount"', false)
            ->assertDontSee('name="denominations[', false);
        $this->mode(false, true);
        $this->view('layouts.header')->assertSee('name="denominations[', false)->assertSee('name="qr"', false);
    }

    private function admin(): void
    {
        DB::table('users')->insert(['id' => 'admin', 'name' => 'Admin', 'email' => 'admin@example.test', 'roles' => 'superadmin']);
        $this->actingAs(User::findOrFail('admin'));
    }

    public function test_manager_can_save_both_settings_independently(): void
    {
        $this->admin();
        foreach ([[true, true], [false, true], [false, false], [true, false]] as [$simple, $locked]) {
            $this->put('/user/update/cashier', [
                'name' => 'Kasir', 'email' => 'kasir@example.test', 'roles' => 'staff', 'store_id' => 1,
                'revenue_simple_mode' => (int) $simple, 'is_lock' => (int) $locked,
            ])->assertRedirect(route('user.index'));
            $user = User::findOrFail('cashier');
            $this->assertSame($simple, $user->revenue_simple_mode);
            $this->assertSame($locked, $user->is_lock);
        }
    }

    public function test_create_defaults_to_simple_mode_and_rejects_staff_setting_changes(): void
    {
        $this->post('/user/store', [])->assertForbidden();
        $this->admin();
        $this->post('/user/store', [
            'name' => 'New Cashier', 'email' => 'new@example.test', 'roles' => 'staff', 'store_id' => 1,
            'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
        ])->assertRedirect(route('user.index'));
        $this->assertTrue(User::where('email', 'new@example.test')->firstOrFail()->revenue_simple_mode);
    }

    public function test_validation_error_marks_revenue_form_for_reopening(): void
    {
        $this->mode(false, false);
        $this->from('/')->post('/staff/logout-revenue', ['amount' => 125000])
            ->assertRedirect('/')->assertSessionHasErrors('denominations')->assertSessionHas('revenue_form', true);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Note;
use App\Models\Settings;
use App\Models\User;
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Review round 48: (a) current yabs.sh moved the is-a-VM answer to os.vm
 * (systemd-detect-virt string) and made cpu.virt a BOOLEAN meaning "host
 * CPU has vmx/svm" — the parser still derived `vm` from cpu.virt,
 * inverting Virtualized for a KVM guest without nested virt AND for bare
 * metal with VT-x. (b) Settings::getSettings() cached the create()d model
 * on fresh installs, which carries no column defaults — every attribute
 * read null for a week. (c) notes index truncated by bytes, splitting
 * multibyte characters into U+FFFD. (d) import:servers exited 0 when
 * every row failed. (e) the servers-page delete form action was relative
 * (POSTed to /servers/servers/{id} on a trailing-slash page load).
 */
class Round48RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $cpu_virt, array $os_extra = []): array
    {
        return [
            'version' => 'v2025', 'time' => '20260710-120000',
            'os' => array_merge([
                'arch' => 'x86_64', 'distro' => 'Debian 12', 'kernel' => '6.1.0',
                'uptime' => '5 days, 4 hours',
            ], $os_extra),
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => array_merge([
                'model' => 'EPYC', 'cores' => 4, 'freq' => '2299.998', 'aes' => 1,
            ], $cpu_virt),
            'mem' => ['ram' => 4014080, 'swap' => 524288, 'disk' => 49283072],
        ];
    }

    public function test_vm_flag_derives_from_os_vm_on_modern_payloads()
    {
        $ingest = new YabsIngestService();

        // KVM guest without nested virt: cpu.virt boolean false, os.vm "KVM"
        $parsed = $ingest->parse($this->payload(['virt' => false], ['vm' => 'KVM']), 'r48srv01');
        $this->assertSame(1, $parsed['yabs']['vm'], 'KVM guest must be Virtualized: Yes');

        // Bare metal with VT-x: cpu.virt boolean true, os.vm "NONE"
        $parsed = $ingest->parse($this->payload(['virt' => true], ['vm' => 'NONE']), 'r48srv02');
        $this->assertSame(0, $parsed['yabs']['vm'], 'bare metal must be Virtualized: No');

        // Legacy string shape (no os.vm) still works both ways
        $parsed = $ingest->parse($this->payload(['virt' => 'KVM']), 'r48srv03');
        $this->assertSame(1, $parsed['yabs']['vm']);
        $parsed = $ingest->parse($this->payload(['virt' => 'none']), 'r48srv04');
        $this->assertSame(0, $parsed['yabs']['vm']);
    }

    public function test_fresh_install_settings_carry_their_column_defaults()
    {
        // No settings row + cold cache: getSettings() must cache a model
        // WITH the column defaults, not the attribute-less create() return
        Cache::flush();
        $this->assertSame(0, Settings::count());

        $settings = Settings::getSettings();

        $this->assertNotNull($settings->sort_on);
        $this->assertNotNull($settings->due_soon_amount);
        $this->assertNotNull($settings->default_currency);
    }

    public function test_note_truncation_is_multibyte_safe()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1]);
        // 79 ASCII bytes then a multibyte char: byte-truncation at 80 would
        // split it into U+FFFD
        Note::create(['id' => 'r48note1', 'service_id' => 'r48svc01', 'note' => str_repeat('a', 79) . 'ü end of note']);

        $response = $this->actingAs($user)->get('/notes');
        $response->assertStatus(200);
        $this->assertStringNotContainsString("\u{FFFD}", $response->getContent());
    }

    public function test_import_exits_nonzero_when_rows_fail()
    {
        $csv = tempnam(sys_get_temp_dir(), 'r48') . '.csv';
        file_put_contents($csv, "COMPANY,HOSTNAME\nBadCo,broken-row\n");

        $this->artisan('import:servers', ['file' => $csv])->assertExitCode(1);

        @unlink($csv);
    }

    public function test_servers_delete_form_action_is_absolute()
    {
        $partial = file_get_contents(resource_path('views/servers/partials/status-js.blade.php'));
        $this->assertStringContainsString("'/servers/' + this.modal_id", $partial);
    }
}

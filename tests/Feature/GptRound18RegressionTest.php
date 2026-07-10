<?php

namespace Tests\Feature;

use App\Models\Disk;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for the GPT round-18 finding: persist() caught EVERY
 * UniqueConstraintViolationException in the ingest transaction as a replay
 * and returned success — but the transaction also writes disk_speed and
 * network_speed, which carry their own unique constraints. A payload
 * repeating an iperf location within the matched mode collided on
 * network_speed (id, server_id, location), rolled back the entire ingest,
 * and was reported "Successfully added YABS" with nothing stored.
 *
 * Two-part fix: the parser dedupes iperf locations (first entry wins), and
 * the catch treats the loss as a replay ONLY when the (server_id,
 * output_date) run row actually exists — anything else is a failure.
 */
class GptRound18RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        $server_id = Str::random(8);
        (new Pricing)->insertPricing(1, $server_id, 'USD', 5.00, 1, '2027-01-01');
        Disk::insertDisk($server_id, 50, 'GB', 'SSD');

        return Server::create([
            'id' => $server_id, 'hostname' => 'gpt18.example.com', 'server_type' => 1,
            'os_id' => OS::create(['name' => 'OS ' . $server_id])->id,
            'provider_id' => Providers::create(['name' => 'P' . $server_id])->id,
            'location_id' => Locations::create(['name' => 'L' . $server_id])->id,
            'ram' => 4, 'ram_type' => 'GB', 'ram_as_mb' => 4096, 'disk' => 50, 'disk_type' => 'GB',
            'disk_as_gb' => 50, 'cpu' => 4, 'has_yabs' => 0, 'active' => 1, 'owned_since' => '2026-01-01',
        ]);
    }

    private function yabsPayload(): array
    {
        return [
            'version' => 'v2024-06-09', 'time' => '20260705-120000',
            'os' => ['distro' => 'Debian 13', 'kernel' => '6.1.0', 'uptime' => 432000],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'AMD EPYC', 'cores' => 4, 'freq' => '2299.998', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 4014080, 'swap' => 524288, 'disk' => 49283072],
        ];
    }

    public function test_repeated_iperf_location_ingests_once_instead_of_claiming_phantom_success()
    {
        Settings::firstOrCreate(['id' => 1]);
        $server = $this->makeServer();

        $payload = $this->yabsPayload();
        $payload['iperf'] = [
            ['mode' => 'IPv4', 'loc' => 'Clouvider | London, UK', 'send' => '9.42 Gbits/sec', 'recv' => '9.53 Gbits/sec'],
            ['mode' => 'IPv4', 'loc' => 'Clouvider | London, UK', 'send' => '9.10 Gbits/sec', 'recv' => '9.20 Gbits/sec'],
        ];

        $this->postJson(
            URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $server->id]),
            $payload
        )->assertStatus(200);

        // Pre-fix: 200 "Successfully added" with NOTHING stored (the second
        // network insert rolled back the whole transaction).
        $this->assertSame(1, DB::table('yabs')->where('server_id', $server->id)->count());
        $this->assertSame(1, DB::table('network_speed')->where('server_id', $server->id)->count());
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'has_yabs' => 1]);
    }

    public function test_child_table_uniqueness_failure_is_not_misreported_as_a_replay()
    {
        Settings::firstOrCreate(['id' => 1]);
        $server = $this->makeServer();
        $ingest = new YabsIngestService();

        // Hand-built parse result with two identical network rows — the
        // shape the parser can no longer produce, standing in for any
        // child-table collision (e.g. an 8-char id collision).
        $parsed = $ingest->parse(array_merge($this->yabsPayload(), [
            'iperf' => [['mode' => 'IPv4', 'loc' => 'Somewhere', 'send' => '1.00 Gbits/sec', 'recv' => '1.00 Gbits/sec']],
        ]), $server->id);
        $this->assertNotNull($parsed);
        $parsed['network_speeds'][] = $parsed['network_speeds'][0];

        $this->assertFalse($ingest->persist($parsed),
            'a rolled-back ingest must not be reported as a successful replay');
        $this->assertSame(0, DB::table('yabs')->where('server_id', $server->id)->count());
    }

    public function test_nginx_serves_the_browser_security_headers()
    {
        $conf = file_get_contents(base_path('docker/nginx.conf'));

        $this->assertStringContainsString('add_header X-Content-Type-Options "nosniff" always;', $conf);
        $this->assertStringContainsString('add_header X-Frame-Options "SAMEORIGIN" always;', $conf);
        $this->assertStringContainsString('add_header Referrer-Policy "strict-origin-when-cross-origin" always;', $conf);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'",
                     "form-action 'self'", "frame-ancestors 'self'", "img-src 'self' data:",
                     // Vue 2's standalone build compiles in-DOM templates with
                     // new Function(): without unsafe-eval every Vue-managed
                     // index (delete modal) blanks after first paint.
                     "script-src 'self' 'unsafe-inline' 'unsafe-eval'"] as $directive) {
            $this->assertStringContainsString($directive, $conf, "CSP lost its $directive directive");
        }

        // nginx add_header inheritance: a location block declaring its own
        // add_header silently drops every server-level header above — all
        // add_header lines must sit before the first location block.
        $firstLocation = strpos($conf, 'location /');
        $this->assertNotFalse($firstLocation);
        $this->assertStringNotContainsString('add_header', substr($conf, $firstLocation),
            'an add_header inside a location block silently disables the server-level security headers');
    }

    public function test_a_genuine_replay_is_still_idempotent_success()
    {
        Settings::firstOrCreate(['id' => 1]);
        $server = $this->makeServer();
        $ingest = new YabsIngestService();

        $first = $ingest->parse($this->yabsPayload(), $server->id);
        $this->assertTrue($ingest->persist($first));

        // Same run, new random ids — the yabs unique index fires and the
        // existing run row proves it a replay.
        $second = $ingest->parse($this->yabsPayload(), $server->id);
        $this->assertTrue($ingest->persist($second));
        $this->assertSame(1, DB::table('yabs')->where('server_id', $server->id)->count());
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Disk;
use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportServersCommand extends Command
{
    protected $signature = 'import:servers {file} {--domain-suffix= : Domain appended to each hostname, e.g. example.com}';
    protected $description = 'Import servers from a CSV file';

    private array $locationMap = [
        'USA (HOUSTON)' => 'USA (Houston, TX)',
        'USA (Kansas City)' => 'USA (Kansas City, MO)',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("File not found: $file");
            return 1;
        }

        $rows = array_map(function ($line) {
            return str_getcsv($line);
        }, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        // Excel exports prepend a UTF-8 BOM to the first header; padded
        // headers mis-key array_combine and every row then fails.
        $headers = array_map(fn ($h) => trim($h, "\xEF\xBB\xBF \t\r"), array_shift($rows));

        // Pre-create Debian 13 OS
        $os = OS::firstOrCreate(['name' => 'Debian 13']);

        $count = 0;
        $errors = 0;

        foreach ($rows as $i => $row) {
            if (count($row) !== count($headers)) {
                $this->warn("Row " . ($i + 2) . ": column count mismatch, skipping");
                $errors++;
                continue;
            }

            $data = array_combine($headers, $row);

            try {
                $this->importServer($data, $os);
                $count++;
            } catch (\Exception $e) {
                $this->error("Row " . ($i + 2) . " (" . ($data['HOSTNAME'] ?? '?') . "): " . $e->getMessage());
                $errors++;
            }
        }

        Server::serverRelatedCacheForget();
        // New OS/providers/locations created during import must not stay hidden
        // behind warm lookup caches.
        Cache::forget('operating_systems');
        Cache::forget('providers');
        Cache::forget('locations');

        $this->info("Imported $count servers." . ($errors > 0 ? " $errors errors." : ""));

        return 0;
    }

    private function importServer(array $data, OS $os): void
    {
        // Provider
        $provider = Providers::firstOrCreate(['name' => trim($data['COMPANY'])]);

        // Location (normalize)
        $locName = trim($data['LOCATION']);
        $locName = $this->locationMap[$locName] ?? $locName;
        $location = Locations::firstOrCreate(['name' => $locName]);

        // Hostname
        $hostname = trim($data['HOSTNAME']);
        if ($suffix = $this->option('domain-suffix')) {
            $hostname .= '.' . ltrim($suffix, '.');
        }

        // RAM
        [$ram, $ramType, $ramAsMb] = $this->parseRam(trim($data['RAM']));

        // Disks
        $disks = $this->parseDisks(trim($data['SSD DISK'] ?? ''), trim($data['HDD DISK'] ?? ''));

        // First disk for backward compat columns
        $firstDisk = $disks[0] ?? ['size' => 0, 'unit' => 'GB', 'media' => 'SSD'];
        $totalDiskGb = array_sum(array_map(
            fn($d) => $d['unit'] === 'TB' ? $d['size'] * 1024 : $d['size'],
            $disks
        ));

        // Bandwidth
        $bandwidth = $this->parseBandwidth(trim($data['BANDWIDTH'] ?? ''));

        // Term
        $term = $this->parseTerm(trim($data['PERIOD']));

        // Price (from COST column)
        $price = floatval(str_replace(['$', ','], '', trim($data['COST'])));

        // Currency — pricings.currency is char(3); normalize and fall back to
        // USD for anything that isn't a 3-letter code (would overflow on MySQL).
        $currency = strtoupper(trim($data['CURRENCY'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }

        // Active/Cancelled
        $cancelled = strtolower(trim($data['Cancelled'] ?? ''));
        $active = ($cancelled === 'x') ? 0 : 1;

        // Next due date
        $nextDueDate = $this->parseNextDueDate(trim($data['Renews'] ?? ''));

        // Owned since
        $ownedSince = $this->calcOwnedSince($nextDueDate, $term);

        // Create server
        $serverId = Str::random(8);

        // Atomic per row: without this, a failure on the pricing/IP writes
        // (e.g. a bad value MySQL rejects) left an orphaned server + disks.
        DB::transaction(function () use ($serverId, $hostname, $os, $provider, $location, $ram, $ramType, $ramAsMb, $firstDisk, $totalDiskGb, $data, $bandwidth, $active, $ownedSince, $disks, $currency, $price, $term, $nextDueDate) {
            // Pricing FIRST: servers.id has an FK to pricings.service_id
            // (servers_fk_pricing), checked immediately by InnoDB. SQLite
            // silently drops ALTER TABLE FKs, so it hides the wrong order.
            (new Pricing())->insertPricing(1, $serverId, $currency, $price, $term, $nextDueDate, $active);

            Server::create([
                'id' => $serverId,
                'hostname' => $hostname,
                'server_type' => 1, // KVM
                'os_id' => $os->id,
                'provider_id' => $provider->id,
                'location_id' => $location->id,
                'ram' => $ram,
                'ram_type' => $ramType,
                'ram_as_mb' => $ramAsMb,
                'disk' => $firstDisk['size'],
                'disk_type' => $firstDisk['unit'],
                'disk_as_gb' => $totalDiskGb,
                'cpu' => intval($data['VCPU']),
                'bandwidth' => $bandwidth,
                'ssh' => 22,
                'active' => $active,
                'show_public' => 0,
                'was_promo' => 1,
                'owned_since' => $ownedSince,
            ]);

            foreach ($disks as $d) {
                Disk::insertDisk($serverId, $d['size'], $d['unit'], $d['media']);
            }

            $this->resolveAndInsertIPs($serverId, $hostname);
        });

        $status = $active ? 'active' : 'inactive';
        $this->line("  Imported: $hostname ($status)");
    }

    private function parseRam(string $ram): array
    {
        if (preg_match('/^(\d+)\s*(GB|MB)$/i', $ram, $m)) {
            $value = intval($m[1]);
            $type = strtoupper($m[2]);
            $asMb = ($type === 'GB') ? $value * 1024 : $value;
            return [$value, $type, $asMb];
        }

        // Default fallback
        return [intval($ram), 'MB', intval($ram)];
    }

    private function parseDisks(string $ssd, string $hdd): array
    {
        $disks = [];

        if ($ssd !== '') {
            $parsed = $this->parseDiskValue($ssd);
            if ($parsed) {
                $disks[] = ['size' => $parsed['size'], 'unit' => $parsed['unit'], 'media' => 'SSD'];
            }
        }

        if ($hdd !== '') {
            $parsed = $this->parseDiskValue($hdd);
            if ($parsed) {
                $disks[] = ['size' => $parsed['size'], 'unit' => $parsed['unit'], 'media' => 'HDD'];
            }
        }

        return $disks;
    }

    private function parseDiskValue(string $val): ?array
    {
        if (preg_match('/^(\d+)\s*(TB|GB)$/i', trim($val), $m)) {
            return ['size' => intval($m[1]), 'unit' => strtoupper($m[2])];
        }
        return null;
    }

    private function parseBandwidth(string $bw): int
    {
        $bw = strtoupper(trim($bw));

        // "25TB OUT (UNLIMITED IN)" → extract leading amount as GB; empty/UNLIMITED → 0
        if (preg_match('/^(\d+)\s*(TB|GB)/', $bw, $m)) {
            return intval($m[1]) * ($m[2] === 'TB' ? 1000 : 1);
        }

        return 0;
    }

    private function parseTerm(string $period): int
    {
        return match (strtoupper(trim($period))) {
            '1M' => 1,
            '1Y' => 4,
            '2Y' => 5,
            '3Y' => 6,
            '1 TIME' => 7,
            default => 1,
        };
    }

    private function parseNextDueDate(string $renews): ?string
    {
        $renews = trim($renews);

        if ($renews === '' || strtoupper($renews) === 'N/A') {
            return null;
        }

        try {
            return Carbon::createFromFormat('m/d/y', $renews)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calcOwnedSince(?string $nextDueDate, int $term): ?string
    {
        if ($nextDueDate === null || $term === 7) {
            return null;
        }

        $date = Carbon::parse($nextDueDate);

        return match ($term) {
            1 => $date->subMonthsNoOverflow(1)->format('Y-m-d'),
            2 => $date->subMonthsNoOverflow(3)->format('Y-m-d'),
            3 => $date->subMonthsNoOverflow(6)->format('Y-m-d'),
            4 => $date->subYear()->format('Y-m-d'),
            5 => $date->subYears(2)->format('Y-m-d'),
            6 => $date->subYears(3)->format('Y-m-d'),
            default => null,
        };
    }

    private function resolveAndInsertIPs(string $serverId, string $hostname): void
    {
        // IPv4
        try {
            $a = dns_get_record($hostname, DNS_A);
            if (!empty($a[0]['ip'])) {
                IPs::insertIP($serverId, $a[0]['ip']);
            }
        } catch (\Exception $e) {
            // DNS resolution failed, skip
        }

        // IPv6
        try {
            $aaaa = dns_get_record($hostname, DNS_AAAA);
            if (!empty($aaaa[0]['ipv6'])) {
                IPs::insertIP($serverId, $aaaa[0]['ipv6']);
            }
        } catch (\Exception $e) {
            // DNS resolution failed, skip
        }
    }
}

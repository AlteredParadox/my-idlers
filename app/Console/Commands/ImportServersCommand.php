<?php

namespace App\Console\Commands;

use App\Models\Disk;
use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Exceptions\ImportRowException;
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

    /** Resolved once on the first imported row; constant for the whole run */
    private ?int $debianOsId = null;

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
                $this->importServer($data);
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

        // Exit codes are part of the contract (file-not-found returns 1):
        // a scripted/cron run must not report success when rows failed.
        return $errors > 0 ? 1 : 0;
    }

    /** Same invariants the controllers enforce (shared rule sources). */
    private function assertRowValid(array $values): void
    {
        $validator = \Illuminate\Support\Facades\Validator::make($values, [
            ...Pricing::webValidationRules(),
            'cpu' => 'required|integer|min:1|max:1024',
            'ram_as_mb' => 'required|integer|min:0|max:100000000',
            'disk_as_gb' => 'required|integer|min:0|max:100000000',
            'bandwidth' => 'required|integer|min:0|max:100000000',
            // Text fields mirror the web rules: import could otherwise create
            // provider/location/hostname values the UI itself would reject
            'provider_name' => 'required|string|min:2|max:255',
            'location_name' => 'required|string|min:2|max:255',
            'hostname' => 'required|string|min:5|max:255',
        ]);

        if ($validator->fails()) {
            throw new ImportRowException($validator->errors()->first());
        }
    }

    private function importServer(array $data): void
    {
        // Provider/location NAMES only — the rows are created inside the
        // per-row transaction after validation, so a rejected row can't
        // strand orphan catalog entries (a bad CSV used to populate the
        // provider/location dropdowns while importing zero servers).
        $providerName = trim($data['COMPANY']);
        $locName = trim($data['LOCATION']);
        $locName = $this->locationMap[$locName] ?? $locName;

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

        // Price (from COST column) — validate the RAW string before casting:
        // floatval('abc') is 0.00, which satisfies price|min:0.
        $rawCost = str_replace(['$', ','], '', trim($data['COST'] ?? ''));
        if (!preg_match('/^\d+(\.\d+)?$/', $rawCost)) {
            throw new ImportRowException("unparseable COST '" . trim($data['COST'] ?? '') . "'");
        }
        $price = (float) $rawCost;

        // Currency — empty defaults to USD (sparse CSVs); anything else must
        // pass the shared convertible-currency validation below. Silently
        // normalizing unknown codes to USD hid bad rows.
        $currency = strtoupper(trim($data['CURRENCY'] ?? ''));
        if ($currency === '') {
            $currency = 'USD';
        }

        // Active/Cancelled
        $cancelled = strtolower(trim($data['Cancelled'] ?? ''));
        $active = ($cancelled === 'x') ? 0 : 1;

        // Next due date
        $nextDueDate = $this->parseNextDueDate(trim($data['Renews'] ?? ''));

        // Owned since
        $ownedSince = $this->calcOwnedSince($nextDueDate, $term);

        // VCPU — strict digits before casting: intval('2 cores') is 2 and
        // intval('') is 0, both hiding a malformed cell.
        $rawCpu = trim($data['VCPU'] ?? '');
        if (!preg_match('/^\d+$/', $rawCpu)) {
            throw new ImportRowException("unparseable VCPU '$rawCpu'");
        }
        $cpu = (int) $rawCpu;

        // The web/API paths validate these invariants; import must not be a
        // bypass. Rejects the row (reported + counted) instead of persisting
        // 1:1 currency conversions or out-of-domain specs.
        $this->assertRowValid([
            'price' => $price,
            'currency' => $currency,
            'payment_term' => $term,
            'next_due_date' => $nextDueDate,
            'cpu' => $cpu,
            'ram_as_mb' => $ramAsMb,
            'disk_as_gb' => $totalDiskGb,
            'bandwidth' => $bandwidth,
            'provider_name' => $providerName,
            'location_name' => $locName,
            'hostname' => $hostname,
        ]);

        // Create server
        $serverId = Str::random(8);

        // Atomic per row: without this, a failure on the pricing/IP writes
        // (e.g. a bad value MySQL rejects) left an orphaned server + disks.
        DB::transaction(function () use ($serverId, $hostname, $providerName, $locName, $ram, $ramType, $ramAsMb, $firstDisk, $totalDiskGb, $bandwidth, $active, $ownedSince, $disks, $currency, $price, $term, $nextDueDate, $cpu) {
            $provider = Providers::firstOrCreate(['name' => $providerName]);
            $location = Locations::firstOrCreate(['name' => $locName]);
            // Lazy: a completely invalid import must not add the OS row either.
            // Memoized after the first row — the OS is the same constant for
            // every import, no need to re-select it per row.
            $this->debianOsId ??= OS::firstOrCreate(['name' => 'Debian 13'])->id;

            // Pricing FIRST: servers.id has an FK to pricings.service_id
            // (servers_fk_pricing), checked immediately by InnoDB. SQLite
            // silently drops ALTER TABLE FKs, so it hides the wrong order.
            (new Pricing())->insertPricing(1, $serverId, $currency, $price, $term, $nextDueDate, $active);

            Server::create([
                'id' => $serverId,
                'hostname' => $hostname,
                'server_type' => 1, // KVM
                'os_id' => $this->debianOsId,
                'provider_id' => $provider->id,
                'location_id' => $location->id,
                'ram' => $ram,
                'ram_type' => $ramType,
                'ram_as_mb' => $ramAsMb,
                'disk' => $firstDisk['size'],
                'disk_type' => $firstDisk['unit'],
                'disk_as_gb' => $totalDiskGb,
                'cpu' => $cpu,
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

        // Plain number = MB; anything else is a malformed cell, not 0 MB
        if (preg_match('/^\d+$/', $ram)) {
            return [intval($ram), 'MB', intval($ram)];
        }

        throw new ImportRowException("unparseable RAM '$ram'");
    }

    private function parseDisks(string $ssd, string $hdd): array
    {
        $disks = [];

        // Non-empty unparseable cells reject the row: silently dropping them
        // imported zero-disk servers.
        if ($ssd !== '') {
            $parsed = $this->parseDiskValue($ssd);
            if (!$parsed) {
                throw new ImportRowException("unparseable SSD DISK '$ssd'");
            }
            $disks[] = ['size' => $parsed['size'], 'unit' => $parsed['unit'], 'media' => 'SSD'];
        }

        if ($hdd !== '') {
            $parsed = $this->parseDiskValue($hdd);
            if (!$parsed) {
                throw new ImportRowException("unparseable HDD DISK '$hdd'");
            }
            $disks[] = ['size' => $parsed['size'], 'unit' => $parsed['unit'], 'media' => 'HDD'];
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

        // "25TB OUT (UNLIMITED IN)" → extract leading amount as GB
        if (preg_match('/^(\d+)\s*(TB|GB)/', $bw, $m)) {
            return intval($m[1]) * ($m[2] === 'TB' ? 1000 : 1);
        }

        // Empty / unmetered variants legitimately mean "no cap" (stored 0);
        // anything else is a malformed cell, not unlimited bandwidth.
        if ($bw === '' || str_contains($bw, 'UNLIMITED') || str_contains($bw, 'UNMETERED')) {
            return 0;
        }

        throw new ImportRowException("unparseable BANDWIDTH '$bw'");
    }

    private function parseTerm(string $period): int
    {
        // Unknown periods used to default to monthly — a '12M' typo silently
        // became term 1 and doDueSoon advanced the due date every month.
        return match (strtoupper(trim($period))) {
            '1M' => 1,
            '1Y' => 4,
            '2Y' => 5,
            '3Y' => 6,
            '1 TIME' => 7,
            default => throw new ImportRowException("unparseable PERIOD '" . trim($period) . "'"),
        };
    }

    private function parseNextDueDate(string $renews): ?string
    {
        $renews = trim($renews);

        if ($renews === '' || strtoupper($renews) === 'N/A') {
            return null;
        }

        // Strict m/d/y: an invalid non-empty cell used to become null and
        // pass the nullable date rule. hasFormat rejects out-of-range fields
        // (e.g. month 13 in 13/45/26); a valid-but-rolling date like 02/30/25
        // still normalizes, which is acceptable for this import.
        if (!Carbon::hasFormat($renews, 'm/d/y')) {
            throw new ImportRowException("unparseable Renews '$renews'");
        }

        return Carbon::createFromFormat('m/d/y', $renews)->format('Y-m-d');
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

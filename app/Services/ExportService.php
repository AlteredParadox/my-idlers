<?php

namespace App\Services;

use App\Exceptions\ExportException;
use App\Models\DNS;
use App\Models\Domains;
use App\Models\Misc;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use Illuminate\Support\Collection;

class ExportService
{
    /**
     * Valid export formats
     */
    protected const VALID_FORMATS = ['json', 'csv'];

    public const ERROR_INVALID_FORMAT = 'Invalid format. Supported formats: json, csv';

    public const MIME_JSON = 'application/json';

    public const MIME_CSV = 'text/csv';

    protected ExportTransformer $transformer;

    protected CsvFormatter $csv;

    public function __construct(?ExportTransformer $transformer = null, ?CsvFormatter $csv = null)
    {
        $this->transformer = $transformer ?? new ExportTransformer();
        $this->csv = $csv ?? new CsvFormatter();
    }

    /**
     * Validate export format
     *
     * @param string $format
     * @return bool
     */
    public function isValidFormat($format): bool
    {
        // Accept mixed: ?format[]=x arrives as an array; treat non-strings as
        // invalid (400) rather than letting a TypeError 500 the request.
        return is_string($format) && in_array(strtolower($format), self::VALID_FORMATS, true);
    }

    /**
     * Export servers with all related data (YABS, pricing, IPs)
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportServers(string $format): array
    {
        $format = strtolower($format);
        
        // Fetch all servers with relationships
        $servers = Server::with([
            'location',
            'provider',
            'os',
            'price',
            'ips',
            'yabs',
            'yabs.disk_speed',
            'yabs.network_speed'
        ])->get();

        // Transform server data
        $exportData = $servers->map(function ($server) {
            return $this->transformer->transformServerForExport($server);
        });

        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "servers_export_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->transformer->getServerCsvHeaders()),
            'filename' => "servers_export_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Export domains with pricing data
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportDomains(string $format): array
    {
        $format = strtolower($format);

        // Fetch all domains with relationships
        $domains = Domains::with(['provider', 'price'])->get();

        // Transform domain data
        $exportData = $domains->map(function ($domain) {
            return $this->transformer->transformDomainForExport($domain);
        });

        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "domains_export_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->transformer->getDomainCsvHeaders()),
            'filename' => "domains_export_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Export shared hosting with pricing and IPs
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportShared(string $format): array
    {
        $format = strtolower($format);

        // Fetch all shared hosting with relationships
        $sharedHosting = Shared::with(['location', 'provider', 'price', 'ips'])->get();

        // Transform shared hosting data
        $exportData = $sharedHosting->map(function ($shared) {
            return $this->transformer->transformSharedForExport($shared);
        });

        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "shared_hosting_export_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->transformer->getSharedCsvHeaders()),
            'filename' => "shared_hosting_export_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Export reseller hosting with pricing and IPs
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportReseller(string $format): array
    {
        $format = strtolower($format);

        // Fetch all reseller hosting with relationships
        $resellerHosting = Reseller::with(['location', 'provider', 'price', 'ips'])->get();

        // Transform reseller hosting data
        $exportData = $resellerHosting->map(function ($reseller) {
            return $this->transformer->transformResellerForExport($reseller);
        });

        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "reseller_hosting_export_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->transformer->getResellerCsvHeaders()),
            'filename' => "reseller_hosting_export_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Export seedboxes with pricing
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportSeedboxes(string $format): array
    {
        $format = strtolower($format);

        // Fetch all seedboxes with relationships
        $seedboxes = SeedBoxes::with(['location', 'provider', 'price'])->get();

        // Transform seedbox data
        $exportData = $seedboxes->map(function ($seedbox) {
            return $this->transformer->transformSeedboxForExport($seedbox);
        });

        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "seedboxes_export_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->transformer->getSeedboxCsvHeaders()),
            'filename' => "seedboxes_export_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Export DNS records
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportDns(string $format): array
    {
        $format = strtolower($format);

        // Fetch all DNS records
        $dnsRecords = DNS::all();

        // Transform DNS data
        $exportData = $dnsRecords->map(function ($dns) {
            return $this->transformer->transformDnsForExport($dns);
        });

        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "dns_export_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->transformer->getDnsCsvHeaders()),
            'filename' => "dns_export_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Export miscellaneous services with pricing
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportMisc(string $format): array
    {
        $format = strtolower($format);

        // Fetch all misc services with relationships
        $miscServices = Misc::with(['price'])->get();

        // Transform misc service data
        $exportData = $miscServices->map(function ($misc) {
            return $this->transformer->transformMiscForExport($misc);
        });

        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "misc_services_export_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->transformer->getMiscCsvHeaders()),
            'filename' => "misc_services_export_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Transform collection to JSON string with pretty-print formatting
     *
     * @param Collection|array $data
     * @return string
     */
    protected function toJson($data): string
    {
        if ($data instanceof Collection) {
            $data = $data->toArray();
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Export all data combined
     * For JSON: returns combined data with metadata
     * For CSV: returns ZIP file with separate CSV files for each service type
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportAll(string $format): array
    {
        $format = strtolower($format);
        $timestamp = date('Y-m-d_His');

        // Fetch all data for each service type
        $servers = Server::with([
            'location',
            'provider',
            'os',
            'price',
            'ips',
            'yabs',
            'yabs.disk_speed',
            'yabs.network_speed'
        ])->get();

        $domains = Domains::with(['provider', 'price'])->get();
        $shared = Shared::with(['location', 'provider', 'price', 'ips'])->get();
        $reseller = Reseller::with(['location', 'provider', 'price', 'ips'])->get();
        $seedboxes = SeedBoxes::with(['location', 'provider', 'price'])->get();
        $dns = DNS::all();
        $misc = Misc::with(['price'])->get();

        // Transform all data
        $sections = [
            'servers' => $servers->map(fn($server) => $this->transformer->transformServerForExport($server))->toArray(),
            'domains' => $domains->map(fn($domain) => $this->transformer->transformDomainForExport($domain))->toArray(),
            'shared' => $shared->map(fn($s) => $this->transformer->transformSharedForExport($s))->toArray(),
            'reseller' => $reseller->map(fn($r) => $this->transformer->transformResellerForExport($r))->toArray(),
            'seedboxes' => $seedboxes->map(fn($sb) => $this->transformer->transformSeedboxForExport($sb))->toArray(),
            'dns' => $dns->map(fn($d) => $this->transformer->transformDnsForExport($d))->toArray(),
            'misc' => $misc->map(fn($m) => $this->transformer->transformMiscForExport($m))->toArray(),
        ];

        if ($format === 'json') {
            return $this->exportAllAsJson($sections, $timestamp);
        }

        // CSV format - create ZIP with separate CSV files
        return $this->exportAllAsCsvZip($sections, $timestamp);
    }

    /**
     * Export all data as JSON with metadata
     *
     * @param array $sections data arrays keyed by section name (servers, domains, ...)
     * @param string $timestamp
     * @return array{data: string, filename: string, content_type: string}
     */
    protected function exportAllAsJson(array $sections, string $timestamp): array
    {
        $exportData = [
            'export_metadata' => [
                'export_date' => date('c'), // ISO 8601 format
                'version' => '4.1.0',
                'counts' => array_map('count', $sections),
            ],
        ] + $sections;

        return [
            'data' => $this->toJson($exportData),
            'filename' => "my_idlers_export_{$timestamp}.json",
            'content_type' => self::MIME_JSON
        ];
    }

    /**
     * Export all data as a ZIP file containing separate CSV files
     *
     * @param array $sections data arrays keyed by section name (servers, domains, ...)
     * @param string $timestamp
     * @return array{data: string, filename: string, content_type: string}
     */
    protected function exportAllAsCsvZip(array $sections, string $timestamp): array
    {
        // Create a temporary file for the ZIP
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');

        try {
            $zip = new \ZipArchive();

            if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new ExportException('Failed to create export archive.');
            }

            // Add each service type as a separate CSV file
            $csvFiles = [
                'servers' => ['servers.csv', $this->transformer->getServerCsvHeaders()],
                'domains' => ['domains.csv', $this->transformer->getDomainCsvHeaders()],
                'shared' => ['shared_hosting.csv', $this->transformer->getSharedCsvHeaders()],
                'reseller' => ['reseller_hosting.csv', $this->transformer->getResellerCsvHeaders()],
                'seedboxes' => ['seedboxes.csv', $this->transformer->getSeedboxCsvHeaders()],
                'dns' => ['dns.csv', $this->transformer->getDnsCsvHeaders()],
                'misc' => ['misc_services.csv', $this->transformer->getMiscCsvHeaders()],
            ];

            foreach ($csvFiles as $section => [$filename, $headers]) {
                $zip->addFromString($filename, $this->csv->toCsv($sections[$section], $headers));
            }

            // Add metadata file
            $metadata = [
                'export_date' => date('c'),
                'version' => '4.1.0',
                'counts' => array_map('count', $sections),
            ];
            $zip->addFromString(
                'metadata.json',
                json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $zip->close();

            return [
                'data' => file_get_contents($tempFile),
                'filename' => "my_idlers_export_{$timestamp}.zip",
                'content_type' => 'application/zip'
            ];
        } finally {
            // Never leave the temp file behind, even if a CSV/zip step throws
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}

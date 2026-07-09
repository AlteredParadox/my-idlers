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

    /**
     * Version stamped into export metadata
     */
    protected const EXPORT_VERSION = '4.1.0+ap.3';

    /**
     * Everything needed to export one section. Order matters: it is the
     * section order of the combined JSON export, its metadata counts, and
     * the CSVs inside the combined ZIP.
     */
    protected const EXPORTABLES = [
        'servers' => [
            'model' => Server::class,
            'with' => ['location', 'provider', 'os', 'price', 'ips', 'yabs', 'yabs.disk_speed', 'yabs.network_speed'],
            'transform' => 'transformServerForExport',
            'headers' => 'getServerCsvHeaders',
            'file_prefix' => 'servers_export',
            'csv_name' => 'servers.csv',
        ],
        'domains' => [
            'model' => Domains::class,
            'with' => ['provider', 'price'],
            'transform' => 'transformDomainForExport',
            'headers' => 'getDomainCsvHeaders',
            'file_prefix' => 'domains_export',
            'csv_name' => 'domains.csv',
        ],
        'shared' => [
            'model' => Shared::class,
            'with' => ['location', 'provider', 'price', 'ips'],
            'transform' => 'transformSharedForExport',
            'headers' => 'getSharedCsvHeaders',
            'file_prefix' => 'shared_hosting_export',
            'csv_name' => 'shared_hosting.csv',
        ],
        'reseller' => [
            'model' => Reseller::class,
            'with' => ['location', 'provider', 'price', 'ips'],
            'transform' => 'transformResellerForExport',
            'headers' => 'getResellerCsvHeaders',
            'file_prefix' => 'reseller_hosting_export',
            'csv_name' => 'reseller_hosting.csv',
        ],
        'seedboxes' => [
            'model' => SeedBoxes::class,
            'with' => ['location', 'provider', 'price', 'ips'],
            'transform' => 'transformSeedboxForExport',
            'headers' => 'getSeedboxCsvHeaders',
            'file_prefix' => 'seedboxes_export',
            'csv_name' => 'seedboxes.csv',
        ],
        'dns' => [
            'model' => DNS::class,
            'with' => [],
            'transform' => 'transformDnsForExport',
            'headers' => 'getDnsCsvHeaders',
            'file_prefix' => 'dns_export',
            'csv_name' => 'dns.csv',
        ],
        'misc' => [
            'model' => Misc::class,
            'with' => ['price'],
            'transform' => 'transformMiscForExport',
            'headers' => 'getMiscCsvHeaders',
            'file_prefix' => 'misc_services_export',
            'csv_name' => 'misc_services.csv',
        ],
    ];

    protected ExportTransformer $transformer;

    protected CsvFormatter $csv;

    protected ExportCsvHeaders $headers;

    public function __construct(?ExportTransformer $transformer = null, ?CsvFormatter $csv = null, ?ExportCsvHeaders $headers = null)
    {
        $this->transformer = $transformer ?? new ExportTransformer();
        $this->csv = $csv ?? new CsvFormatter();
        $this->headers = $headers ?? new ExportCsvHeaders();
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
        return $this->exportSection('servers', $format);
    }

    /**
     * Export domains with pricing data
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportDomains(string $format): array
    {
        return $this->exportSection('domains', $format);
    }

    /**
     * Export shared hosting with pricing and IPs
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportShared(string $format): array
    {
        return $this->exportSection('shared', $format);
    }

    /**
     * Export reseller hosting with pricing and IPs
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportReseller(string $format): array
    {
        return $this->exportSection('reseller', $format);
    }

    /**
     * Export seedboxes with pricing
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportSeedboxes(string $format): array
    {
        return $this->exportSection('seedboxes', $format);
    }

    /**
     * Export DNS records
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportDns(string $format): array
    {
        return $this->exportSection('dns', $format);
    }

    /**
     * Export miscellaneous services with pricing
     *
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    public function exportMisc(string $format): array
    {
        return $this->exportSection('misc', $format);
    }

    /**
     * Export one section as JSON or CSV
     *
     * @param string $section key into EXPORTABLES
     * @param string $format 'json' or 'csv'
     * @return array{data: string, filename: string, content_type: string}
     */
    protected function exportSection(string $section, string $format): array
    {
        $format = strtolower($format);
        $cfg = self::EXPORTABLES[$section];
        $exportData = $this->sectionData($section);
        $timestamp = date('Y-m-d_His');

        if ($format === 'json') {
            return [
                'data' => $this->toJson($exportData),
                'filename' => "{$cfg['file_prefix']}_{$timestamp}.json",
                'content_type' => self::MIME_JSON
            ];
        }

        // CSV format
        return [
            'data' => $this->csv->toCsv($exportData, $this->headers->{$cfg['headers']}()),
            'filename' => "{$cfg['file_prefix']}_{$timestamp}.csv",
            'content_type' => self::MIME_CSV
        ];
    }

    /**
     * Fetch one section with its relationships and transform each row
     *
     * @param string $section key into EXPORTABLES
     * @return Collection
     */
    protected function sectionData(string $section): Collection
    {
        $cfg = self::EXPORTABLES[$section];

        return $cfg['model']::with($cfg['with'])->get()
            ->map(fn($row) => $this->transformer->{$cfg['transform']}($row));
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

        $sections = [];
        foreach (array_keys(self::EXPORTABLES) as $section) {
            $sections[$section] = $this->sectionData($section)->toArray();
        }

        if ($format === 'json') {
            return $this->exportAllAsJson($sections, $timestamp);
        }

        // CSV format - create ZIP with separate CSV files
        return $this->exportAllAsCsvZip($sections, $timestamp);
    }

    /**
     * Metadata block for combined exports
     *
     * @param array $sections data arrays keyed by section name
     * @return array
     */
    protected function exportMetadata(array $sections): array
    {
        return [
            'export_date' => date('c'), // ISO 8601 format
            'version' => self::EXPORT_VERSION,
            'counts' => array_map('count', $sections),
        ];
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
        $exportData = ['export_metadata' => $this->exportMetadata($sections)] + $sections;

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
            foreach (self::EXPORTABLES as $section => $cfg) {
                $zip->addFromString(
                    $cfg['csv_name'],
                    $this->csv->toCsv($sections[$section], $this->headers->{$cfg['headers']}())
                );
            }

            // Add metadata file
            $zip->addFromString(
                'metadata.json',
                json_encode($this->exportMetadata($sections), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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

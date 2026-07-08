<?php

namespace App\Services;

/**
 * CSV column lists for each export section. Split from ExportTransformer
 * (which builds the row data) — the transform methods and their header
 * lists must stay in sync per section.
 */
class ExportCsvHeaders
{
    /**
     * CSV columns shared by every section that exports pricing data.
     */
    private const PRICING_CSV_HEADERS = [
        'pricing_price',
        'pricing_currency',
        'pricing_term',
        'pricing_term_name',
        'pricing_as_usd',
        'pricing_usd_per_month',
        'pricing_next_due_date',
    ];

    /**
     * Get CSV headers for DNS export
     *
     * @return array
     */
    public function getDnsCsvHeaders(): array
    {
        return [
            'id',
            'hostname',
            'dns_type',
            'address',
            'server_id',
            'domain_id'
        ];
    }

    /**
     * Get CSV headers for misc services export
     *
     * @return array
     */
    public function getMiscCsvHeaders(): array
    {
        return array_merge(
            ['id', 'name', 'active', 'owned_since'],
            self::PRICING_CSV_HEADERS
        );
    }

    /**
     * Get CSV headers for seedbox export
     *
     * @return array
     */
    public function getSeedboxCsvHeaders(): array
    {
        return array_merge(
            [
                'id',
                'title',
                'hostname',
                'seed_box_type',
                'disk',
                'disk_type',
                'disk_as_gb',
                'bandwidth',
                'port_speed',
                'was_promo',
                'transferrable',
                'active',
                'owned_since',
                'location_id',
                'location_name',
                'provider_id',
                'provider_name',
                'ips',
            ],
            self::PRICING_CSV_HEADERS
        );
    }

    /**
     * Get CSV headers for reseller hosting export
     *
     * @return array
     */
    public function getResellerCsvHeaders(): array
    {
        return $this->hostingCsvHeaders('reseller_type', ['accounts']);
    }

    /**
     * Get CSV headers for shared hosting export
     *
     * @return array
     */
    public function getSharedCsvHeaders(): array
    {
        return $this->hostingCsvHeaders('shared_type');
    }

    /**
     * Shared/reseller hosting exports are identical apart from the type
     * column and reseller's extra `accounts` column (inserted after the type).
     *
     * @param string $typeField
     * @param array $extra extra columns inserted after the type column
     * @return array
     */
    private function hostingCsvHeaders(string $typeField, array $extra = []): array
    {
        return array_merge(
            ['id', 'main_domain', $typeField],
            $extra,
            [
                'disk',
                'disk_type',
                'disk_as_gb',
                'bandwidth',
                'domains_limit',
                'subdomains_limit',
                'ftp_limit',
                'email_limit',
                'db_limit',
                'was_promo',
                'transferrable',
                'active',
                'owned_since',
                'location_id',
                'location_name',
                'provider_id',
                'provider_name',
                'ips',
            ],
            self::PRICING_CSV_HEADERS
        );
    }

    /**
     * Get CSV headers for domain export
     *
     * @return array
     */
    public function getDomainCsvHeaders(): array
    {
        return array_merge(
            [
                'id',
                'domain',
                'extension',
                'full_domain',
                'ns1',
                'ns2',
                'ns3',
                'transferrable',
                'active',
                'owned_since',
                'provider_id',
                'provider_name',
            ],
            self::PRICING_CSV_HEADERS
        );
    }

    /**
     * Get CSV headers for server export
     *
     * @return array
     */
    public function getServerCsvHeaders(): array
    {
        return array_merge(
            [
                'id',
                'hostname',
                'server_type',
                'server_type_name',
                'cpu',
                'ram',
                'ram_type',
                'ram_as_mb',
                'disk',
                'disk_type',
                'disk_as_gb',
                'bandwidth',
                'ssh',
                'transferrable',
                'active',
                'owned_since',
                'os_id',
                'os_name',
                'location_id',
                'location_name',
                'provider_id',
                'provider_name',
                'ips',
            ],
            self::PRICING_CSV_HEADERS,
            ['yabs']
        );
    }
}

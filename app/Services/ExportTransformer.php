<?php

namespace App\Services;

use App\Models\DNS;
use App\Models\Domains;
use App\Models\Misc;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;

class ExportTransformer
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
     * Transform a single DNS record for export
     *
     * @param DNS $dns
     * @return array
     */
    public function transformDnsForExport(DNS $dns): array
    {
        return [
            'id' => $dns->id,
            'hostname' => $dns->hostname,
            'dns_type' => $dns->dns_type,
            'address' => $dns->address,
            'server_id' => $dns->server_id,
            'domain_id' => $dns->domain_id,
        ];
    }


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
     * Transform a single misc service model for export
     *
     * @param Misc $misc
     * @return array
     */
    public function transformMiscForExport(Misc $misc): array
    {
        return [
            'id' => $misc->id,
            'name' => $misc->name,
            'active' => $misc->active,
            'owned_since' => $misc->owned_since,
            'pricing' => $this->priceArray($misc->price),
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
     * Transform a single seedbox model for export
     *
     * @param SeedBoxes $seedbox
     * @return array
     */
    public function transformSeedboxForExport(SeedBoxes $seedbox): array
    {
        return [
            'id' => $seedbox->id,
            'title' => $seedbox->title,
            'hostname' => $seedbox->hostname,
            'seed_box_type' => $seedbox->seed_box_type,
            'disk' => $seedbox->disk,
            'disk_type' => $seedbox->disk_type,
            'disk_as_gb' => $seedbox->disk_as_gb,
            'bandwidth' => $seedbox->bandwidth,
            'port_speed' => $seedbox->port_speed,
            'was_promo' => $seedbox->was_promo,
            'transferrable' => $seedbox->transferrable,
            'active' => $seedbox->active,
            'owned_since' => $seedbox->owned_since,
            'location' => $this->idName($seedbox->location),
            'provider' => $this->idName($seedbox->provider),
            'ips' => $this->ipList($seedbox->ips),
            'pricing' => $this->priceArray($seedbox->price),
        ];
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
     * Transform a single reseller hosting model for export
     *
     * @param Reseller $reseller
     * @return array
     */
    public function transformResellerForExport(Reseller $reseller): array
    {
        return $this->hostingData($reseller, 'reseller_type', ['accounts' => $reseller->accounts]);
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
     * Transform a single shared hosting model for export
     *
     * @param Shared $shared
     * @return array
     */
    public function transformSharedForExport(Shared $shared): array
    {
        return $this->hostingData($shared, 'shared_type');
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
     * column and reseller's extra `accounts` field (inserted after the type).
     *
     * @param Shared|Reseller $model
     * @param string $typeField
     * @param array $extra extra fields inserted after the type column
     * @return array
     */
    private function hostingData($model, string $typeField, array $extra = []): array
    {
        return [
                'id' => $model->id,
                'main_domain' => $model->main_domain,
                $typeField => $model->{$typeField},
            ]
            + $extra
            + [
                'disk' => $model->disk,
                'disk_type' => $model->disk_type,
                'disk_as_gb' => $model->disk_as_gb,
                'bandwidth' => $model->bandwidth,
                'domains_limit' => $model->domains_limit,
                'subdomains_limit' => $model->subdomains_limit,
                'ftp_limit' => $model->ftp_limit,
                'email_limit' => $model->email_limit,
                'db_limit' => $model->db_limit,
                'was_promo' => $model->was_promo,
                'transferrable' => $model->transferrable,
                'active' => $model->active,
                'owned_since' => $model->owned_since,
                'location' => $this->idName($model->location),
                'provider' => $this->idName($model->provider),
                'ips' => $this->ipList($model->ips),
                'pricing' => $this->priceArray($model->price),
            ];
    }


    /**
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
     * Transform a single domain model for export
     *
     * @param Domains $domain
     * @return array
     */
    public function transformDomainForExport(Domains $domain): array
    {
        return [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'extension' => $domain->extension,
            'full_domain' => $domain->domain . '.' . $domain->extension,
            'ns1' => $domain->ns1,
            'ns2' => $domain->ns2,
            'ns3' => $domain->ns3,
            'transferrable' => $domain->transferrable,
            'active' => $domain->active,
            'owned_since' => $domain->owned_since,
            'provider' => $this->idName($domain->provider),
            'pricing' => $this->priceArray($domain->price),
        ];
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
     * Transform a single server model for export
     *
     * @param Server $server
     * @return array
     */
    public function transformServerForExport(Server $server): array
    {
        return [
            'id' => $server->id,
            'hostname' => $server->hostname,
            'server_type' => $server->server_type,
            'server_type_name' => Server::serviceServerType($server->server_type ?? 0, false),
            'cpu' => $server->cpu,
            'ram' => $server->ram,
            'ram_type' => $server->ram_type,
            'ram_as_mb' => $server->ram_as_mb,
            'disk' => $server->disk,
            'disk_type' => $server->disk_type,
            'disk_as_gb' => $server->disk_as_gb,
            'bandwidth' => $server->bandwidth,
            'ssh' => $server->ssh,
            'transferrable' => $server->transferrable,
            'active' => $server->active,
            'owned_since' => $server->owned_since,
            'os' => $this->idName($server->os),
            'location' => $this->idName($server->location),
            'provider' => $this->idName($server->provider),
            'ips' => $this->ipList($server->ips),
            'pricing' => $this->priceArray($server->price),
            'yabs' => $server->yabs->map(function ($yabs) {
                return $this->transformYabsForExport($yabs);
            })->toArray(),
        ];
    }


    /**
     * Transform YABS data for export including disk_speed and network_speed
     *
     * @param \App\Models\Yabs $yabs
     * @return array
     */
    public function transformYabsForExport($yabs): array
    {
        $data = [
            'id' => $yabs->id,
            'output_date' => $yabs->output_date,
            'cpu_model' => $yabs->cpu_model,
            'cpu_cores' => $yabs->cpu_cores,
            'cpu_freq' => $yabs->cpu_freq,
            'aes' => $yabs->aes,
            'vm' => $yabs->vm,
            'gb5_single' => $yabs->gb5_single,
            'gb5_multi' => $yabs->gb5_multi,
            'gb6_single' => $yabs->gb6_single,
            'gb6_multi' => $yabs->gb6_multi,
            'ram' => $yabs->ram,
            'ram_type' => $yabs->ram_type,
            'disk' => $yabs->disk,
            'disk_type' => $yabs->disk_type,
        ];

        // Add disk speed data
        $data['disk_speed'] = $yabs->disk_speed ? [
            'd_4k' => $yabs->disk_speed->d_4k,
            'd_4k_type' => $yabs->disk_speed->d_4k_type,
            'd_64k' => $yabs->disk_speed->d_64k,
            'd_64k_type' => $yabs->disk_speed->d_64k_type,
            'd_512k' => $yabs->disk_speed->d_512k,
            'd_512k_type' => $yabs->disk_speed->d_512k_type,
            'd_1m' => $yabs->disk_speed->d_1m,
            'd_1m_type' => $yabs->disk_speed->d_1m_type,
        ] : null;

        // Add network speed data
        $data['network_speed'] = $yabs->network_speed->map(function ($ns) {
            return [
                'location' => $ns->location,
                'send' => $ns->send,
                'send_type' => $ns->send_type,
                'receive' => $ns->receive,
                'receive_type' => $ns->receive_type,
            ];
        })->toArray();

        return $data;
    }


    /**
     * Get human-readable term name from term ID
     *
     * @param int|null $term
     * @return string
     */
    public function getTermName(?int $term): string
    {
        return match ($term) {
            1 => 'Monthly',
            2 => 'Quarterly',
            3 => 'Semi-Annually',
            4 => 'Yearly',
            5 => 'Biennially',
            6 => 'Triennially',
            7 => 'One time',
            default => 'Unknown'
        };
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


    /**
     * Pricing sub-array shared by every priced section.
     *
     * @param \App\Models\Pricing|null $price
     * @return array|null
     */
    private function priceArray($price): ?array
    {
        return $price ? [
            'price' => $price->price,
            'currency' => $price->currency,
            'term' => $price->term,
            'term_name' => $this->getTermName($price->term),
            'as_usd' => $price->as_usd,
            'usd_per_month' => $price->usd_per_month,
            'next_due_date' => $price->next_due_date
        ] : null;
    }


    /**
     * id/name sub-array for location/provider/os relations.
     *
     * @param object|null $model
     * @return array|null
     */
    private function idName($model): ?array
    {
        return $model ? [
            'id' => $model->id,
            'name' => $model->name
        ] : null;
    }


    /**
     * Assigned IP addresses as address/is_ipv4 pairs.
     *
     * @param \Illuminate\Support\Collection $ips
     * @return array
     */
    private function ipList($ips): array
    {
        return $ips->map(function ($ip) {
            return [
                'address' => $ip->address,
                'is_ipv4' => $ip->is_ipv4
            ];
        })->toArray();
    }
}

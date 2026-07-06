<?php

namespace App\Services;

use App\Models\DNS;
use App\Models\Domains;
use App\Models\Misc;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use App\Models\Yabs;

class ExportTransformer
{

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
        $data = [
            'id' => $misc->id,
            'name' => $misc->name,
            'owned_since' => $misc->owned_since,
        ];

        // Add pricing data
        $data['pricing'] = $misc->price ? [
            'price' => $misc->price->price,
            'currency' => $misc->price->currency,
            'term' => $misc->price->term,
            'term_name' => $this->getTermName($misc->price->term),
            'as_usd' => $misc->price->as_usd,
            'usd_per_month' => $misc->price->usd_per_month,
            'next_due_date' => $misc->price->next_due_date
        ] : null;

        return $data;
    }


    /**
     * Get CSV headers for misc services export
     *
     * @return array
     */
    public function getMiscCsvHeaders(): array
    {
        return [
            'id',
            'name',
            'owned_since',
            'pricing_price',
            'pricing_currency',
            'pricing_term',
            'pricing_term_name',
            'pricing_as_usd',
            'pricing_usd_per_month',
            'pricing_next_due_date'
        ];
    }


    /**
     * Transform a single seedbox model for export
     *
     * @param SeedBoxes $seedbox
     * @return array
     */
    public function transformSeedboxForExport(SeedBoxes $seedbox): array
    {
        $data = [
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
        ];

        // Add location relationship
        $data['location'] = $seedbox->location ? [
            'id' => $seedbox->location->id,
            'name' => $seedbox->location->name
        ] : null;

        // Add provider relationship
        $data['provider'] = $seedbox->provider ? [
            'id' => $seedbox->provider->id,
            'name' => $seedbox->provider->name
        ] : null;

        // Add pricing data
        $data['pricing'] = $seedbox->price ? [
            'price' => $seedbox->price->price,
            'currency' => $seedbox->price->currency,
            'term' => $seedbox->price->term,
            'term_name' => $this->getTermName($seedbox->price->term),
            'as_usd' => $seedbox->price->as_usd,
            'usd_per_month' => $seedbox->price->usd_per_month,
            'next_due_date' => $seedbox->price->next_due_date
        ] : null;

        return $data;
    }


    /**
     * Get CSV headers for seedbox export
     *
     * @return array
     */
    public function getSeedboxCsvHeaders(): array
    {
        return [
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
            'pricing_price',
            'pricing_currency',
            'pricing_term',
            'pricing_term_name',
            'pricing_as_usd',
            'pricing_usd_per_month',
            'pricing_next_due_date'
        ];
    }


    /**
     * Transform a single reseller hosting model for export
     *
     * @param Reseller $reseller
     * @return array
     */
    public function transformResellerForExport(Reseller $reseller): array
    {
        $data = [
            'id' => $reseller->id,
            'main_domain' => $reseller->main_domain,
            'reseller_type' => $reseller->reseller_type,
            'accounts' => $reseller->accounts,
            'disk' => $reseller->disk,
            'disk_type' => $reseller->disk_type,
            'disk_as_gb' => $reseller->disk_as_gb,
            'bandwidth' => $reseller->bandwidth,
            'domains_limit' => $reseller->domains_limit,
            'subdomains_limit' => $reseller->subdomains_limit,
            'ftp_limit' => $reseller->ftp_limit,
            'email_limit' => $reseller->email_limit,
            'db_limit' => $reseller->db_limit,
            'was_promo' => $reseller->was_promo,
            'transferrable' => $reseller->transferrable,
            'active' => $reseller->active,
            'owned_since' => $reseller->owned_since,
        ];

        // Add location relationship
        $data['location'] = $reseller->location ? [
            'id' => $reseller->location->id,
            'name' => $reseller->location->name
        ] : null;

        // Add provider relationship
        $data['provider'] = $reseller->provider ? [
            'id' => $reseller->provider->id,
            'name' => $reseller->provider->name
        ] : null;

        // Add IP addresses
        $data['ips'] = $reseller->ips->map(function ($ip) {
            return [
                'address' => $ip->address,
                'is_ipv4' => $ip->is_ipv4
            ];
        })->toArray();

        // Add pricing data
        $data['pricing'] = $reseller->price ? [
            'price' => $reseller->price->price,
            'currency' => $reseller->price->currency,
            'term' => $reseller->price->term,
            'term_name' => $this->getTermName($reseller->price->term),
            'as_usd' => $reseller->price->as_usd,
            'usd_per_month' => $reseller->price->usd_per_month,
            'next_due_date' => $reseller->price->next_due_date
        ] : null;

        return $data;
    }


    /**
     * Get CSV headers for reseller hosting export
     *
     * @return array
     */
    public function getResellerCsvHeaders(): array
    {
        return [
            'id',
            'main_domain',
            'reseller_type',
            'accounts',
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
            'pricing_price',
            'pricing_currency',
            'pricing_term',
            'pricing_term_name',
            'pricing_as_usd',
            'pricing_usd_per_month',
            'pricing_next_due_date'
        ];
    }


    /**
     * Transform a single shared hosting model for export
     *
     * @param Shared $shared
     * @return array
     */
    public function transformSharedForExport(Shared $shared): array
    {
        $data = [
            'id' => $shared->id,
            'main_domain' => $shared->main_domain,
            'shared_type' => $shared->shared_type,
            'disk' => $shared->disk,
            'disk_type' => $shared->disk_type,
            'disk_as_gb' => $shared->disk_as_gb,
            'bandwidth' => $shared->bandwidth,
            'domains_limit' => $shared->domains_limit,
            'subdomains_limit' => $shared->subdomains_limit,
            'ftp_limit' => $shared->ftp_limit,
            'email_limit' => $shared->email_limit,
            'db_limit' => $shared->db_limit,
            'was_promo' => $shared->was_promo,
            'transferrable' => $shared->transferrable,
            'active' => $shared->active,
            'owned_since' => $shared->owned_since,
        ];

        // Add location relationship
        $data['location'] = $shared->location ? [
            'id' => $shared->location->id,
            'name' => $shared->location->name
        ] : null;

        // Add provider relationship
        $data['provider'] = $shared->provider ? [
            'id' => $shared->provider->id,
            'name' => $shared->provider->name
        ] : null;

        // Add IP addresses
        $data['ips'] = $shared->ips->map(function ($ip) {
            return [
                'address' => $ip->address,
                'is_ipv4' => $ip->is_ipv4
            ];
        })->toArray();

        // Add pricing data
        $data['pricing'] = $shared->price ? [
            'price' => $shared->price->price,
            'currency' => $shared->price->currency,
            'term' => $shared->price->term,
            'term_name' => $this->getTermName($shared->price->term),
            'as_usd' => $shared->price->as_usd,
            'usd_per_month' => $shared->price->usd_per_month,
            'next_due_date' => $shared->price->next_due_date
        ] : null;

        return $data;
    }


    /**
     * Get CSV headers for shared hosting export
     *
     * @return array
     */
    public function getSharedCsvHeaders(): array
    {
        return [
            'id',
            'main_domain',
            'shared_type',
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
            'pricing_price',
            'pricing_currency',
            'pricing_term',
            'pricing_term_name',
            'pricing_as_usd',
            'pricing_usd_per_month',
            'pricing_next_due_date'
        ];
    }


    /**
     * Transform a single domain model for export
     *
     * @param Domains $domain
     * @return array
     */
    public function transformDomainForExport(Domains $domain): array
    {
        $data = [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'extension' => $domain->extension,
            'full_domain' => $domain->domain . '.' . $domain->extension,
            'ns1' => $domain->ns1,
            'ns2' => $domain->ns2,
            'ns3' => $domain->ns3,
            'transferrable' => $domain->transferrable,
            'owned_since' => $domain->owned_since,
        ];

        // Add provider relationship
        $data['provider'] = $domain->provider ? [
            'id' => $domain->provider->id,
            'name' => $domain->provider->name
        ] : null;

        // Add pricing data
        $data['pricing'] = $domain->price ? [
            'price' => $domain->price->price,
            'currency' => $domain->price->currency,
            'term' => $domain->price->term,
            'term_name' => $this->getTermName($domain->price->term),
            'as_usd' => $domain->price->as_usd,
            'usd_per_month' => $domain->price->usd_per_month,
            'next_due_date' => $domain->price->next_due_date
        ] : null;

        return $data;
    }


    /**
     * Get CSV headers for domain export
     *
     * @return array
     */
    public function getDomainCsvHeaders(): array
    {
        return [
            'id',
            'domain',
            'extension',
            'full_domain',
            'ns1',
            'ns2',
            'ns3',
            'transferrable',
            'owned_since',
            'provider_id',
            'provider_name',
            'pricing_price',
            'pricing_currency',
            'pricing_term',
            'pricing_term_name',
            'pricing_as_usd',
            'pricing_usd_per_month',
            'pricing_next_due_date'
        ];
    }


    /**
     * Transform a single server model for export
     *
     * @param Server $server
     * @return array
     */
    public function transformServerForExport(Server $server): array
    {
        $data = [
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
        ];

        // Add OS relationship
        $data['os'] = $server->os ? [
            'id' => $server->os->id,
            'name' => $server->os->name
        ] : null;

        // Add location relationship
        $data['location'] = $server->location ? [
            'id' => $server->location->id,
            'name' => $server->location->name
        ] : null;

        // Add provider relationship
        $data['provider'] = $server->provider ? [
            'id' => $server->provider->id,
            'name' => $server->provider->name
        ] : null;

        // Add IP addresses
        $data['ips'] = $server->ips->map(function ($ip) {
            return [
                'address' => $ip->address,
                'is_ipv4' => $ip->is_ipv4
            ];
        })->toArray();

        // Add pricing data
        $data['pricing'] = $server->price ? [
            'price' => $server->price->price,
            'currency' => $server->price->currency,
            'term' => $server->price->term,
            'term_name' => $this->getTermName($server->price->term),
            'as_usd' => $server->price->as_usd,
            'usd_per_month' => $server->price->usd_per_month,
            'next_due_date' => $server->price->next_due_date
        ] : null;

        // Add YABS data with disk_speed and network_speed
        $data['yabs'] = $server->yabs->map(function ($yabs) {
            return $this->transformYabsForExport($yabs);
        })->toArray();

        return $data;
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
        return [
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
            'pricing_price',
            'pricing_currency',
            'pricing_term',
            'pricing_term_name',
            'pricing_as_usd',
            'pricing_usd_per_month',
            'pricing_next_due_date',
            'yabs'
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Database\Eloquent\Builder;

class Server extends Model
{
    use HasFactory;

    protected $table = 'servers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'hostname', 'ipv4', 'ipv6', 'server_type', 'os_id', 'location_id', 'provider_id',
        'ram', 'disk', 'ram_type', 'disk_type', 'ns1', 'ns2', 'label', 'bandwidth', 'ram_as_mb', 'disk_as_gb',
        'has_yabs', 'was_promo', 'transferrable', 'owned_since', 'ssh', 'active', 'show_public', 'cpu', 'cpu_model', 'link_speed', 'network_type'];

    // MySQL returns tinyint/int columns as strings; cast so the strict
    // `=== 1` checks in the edit/detail views work in production.
    protected $casts = [
        'server_type' => 'integer',
        'has_yabs' => 'integer',
        'was_promo' => 'integer',
        'transferrable' => 'integer',
        'active' => 'integer',
        'show_public' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('order', function (Builder $builder) {
            $array = Settings::orderByProcess(Session::get('sort_on') ?? 2);//created_at desc if not set
            if (!in_array(Session::get('sort_on'), [3, 4, 5, 6], true)) {
                $builder->orderBy($array[0], $array[1]);
            }
        });
    }

    public static function allServers()
    {//All servers and relationships (no using joins)
        return Cache::remember("all_servers", now()->addMonth(1), function () {
            $query = Server::with(['location', 'provider', 'os', 'price', 'ips', 'disks', 'yabs', 'yabs.disk_speed', 'yabs.network_speed', 'labels']);
            if (in_array(Session::get('sort_on'), [3, 4, 5, 6], true)) {
                $options = Settings::orderByProcess(Session::get('sort_on'));
                $query->orderBy(Pricing::select("pricings.$options[0]")->whereColumn("pricings.service_id", "servers.id"), $options[1]);
            }
            return $query->get();
        });
    }

    public static function server(string $server_id): ?Server
    {//Single server and relationships (no using joins)
        return Cache::remember("server.$server_id", now()->addMonth(1), function () use ($server_id) {
            return Server::where('id', $server_id)
                ->with(['location', 'provider', 'os', 'price', 'ips', 'disks', 'yabs', 'yabs.disk_speed', 'yabs.network_speed', 'labels'])->first();
        });
    }

    public static function allActiveServers()
    {//All ACTIVE servers and relationships replaces activeServersDataIndexPage()
        return Cache::remember("all_active_servers", now()->addMonth(1), function () {
            $query = Server::where('active', 1)
                ->with(['location', 'provider', 'os', 'ips', 'disks', 'yabs', 'yabs.disk_speed', 'yabs.network_speed', 'labels', 'price']);
            if (in_array(Session::get('sort_on'), [3, 4, 5, 6], true)) {
                $options = Settings::orderByProcess(Session::get('sort_on'));
                $query->orderBy(Pricing::select("pricings.$options[0]")->whereColumn("pricings.service_id", "servers.id"), $options[1]);
            }
            return $query->get();
        });
    }

    public static function allNonActiveServers()
    {//All NON ACTIVE servers and relationships replaces nonActiveServersDataIndexPage()
        return Cache::remember("non_active_servers", now()->addMonth(1), function () {
            return Server::where('active', 0)
                ->with(['location', 'provider', 'os', 'price', 'ips', 'disks', 'yabs', 'yabs.disk_speed', 'yabs.network_speed', 'labels'])
                ->get();
        });
    }

    public static function allPublicServers()
    {//server data that will be publicly viewable (values in settings)
        return Cache::remember("public_server_data", now()->addMonth(1), function () {
            return Server::where('show_public', 1)
                ->with(['location', 'provider', 'os', 'price', 'ips', 'disks', 'yabs', 'yabs.disk_speed', 'yabs.network_speed', 'labels'])
                ->get();
        });
    }

    public static function serviceServerType(int $type, bool $short = true): string
    {
        return match ($type) {
            1 => "KVM",
            2 => "OVZ",
            3 => $short ? "DEDI" : "Dedicated",
            4 => "LXC",
            6 => "VMware",
            7 => "NAT",
            default => $short ? "SEMI-DEDI" : "Semi-dedicated",
        };
    }

    public static function osIntToIcon(?int $os, ?string $os_name): string
    {
        // Tolerate a null OS (e.g. its record was deleted while a server still
        // references it) so the servers index / public page don't fatal.
        $os_name = (string) $os_name;
        $name = strtolower(str_replace(' ', '', $os_name));
        $icon = match (true) {
            $name === "none" => "fas fa-expand",
            str_contains($name, "centos") => "fa-brands fa-centos os-icon",
            str_contains($name, "debian") => "fa-brands fa-debian os-icon",
            str_contains($name, "fedora") => "fa-brands fa-fedora os-icon",
            str_contains($name, "freebsd") => "fa-brands fa-freebsd os-icon",
            str_contains($name, "openbsd") => "fa-brands fa-linux os-icon",
            str_contains($name, "ubuntu") => "fa-brands fa-ubuntu os-icon",
            str_contains($name, "windows") => "fa-brands fa-windows os-icon",
            str_contains($name, "opensuse") => "fa-brands fa-opensuse os-icon",
            str_contains($name, "redhat") => "fa-brands fa-redhat os-icon",
            str_contains($name, "linux") => "fa-brands fa-linux os-icon",
            default => "fa-solid fa-compact-disc os-icon",//OTHER ISO CUSTOM etc
        };

        return "<i class='{$icon}' title='" . e($os_name) . "'></i>";
    }


    public static function serverRelatedCacheForget(): void
    {
        Cache::forget('all_servers');//All servers
        Cache::forget('services_count');//Main page services_count cache
        Cache::forget('due_soon');//Main page due_soon cache
        Cache::forget('recently_added');//Main page recently_added cache
        Cache::forget('all_active_servers');//all active servers cache
        Cache::forget('non_active_servers');//all non active servers cache
        Cache::forget('servers_summary');//servers summary cache
        Cache::forget('public_server_data');//public servers
        Cache::forget('services_count_all');
        Cache::forget('pricing_breakdown');
        Cache::forget('all_active_pricing');
    }

    public static function serverSpecificCacheForget(string $server_id): void
    {
        Cache::forget("server.$server_id");//Will replace one below
        Cache::forget("note.$server_id");//note caches embed the server relation
        Cache::forget('all_notes');
    }

    public static function serverYabsAmount(string $server_id): int
    {//Returns amount of YABS a server has
        return Yabs::where('server_id', $server_id)->count();
    }

    public function yabs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        // Newest first: yabs ids are random char(8), so without an order
        // MySQL returns rows in arbitrary PK order and yabs[0] positional
        // access across the views showed a stale run after a new ingest.
        return $this->hasMany(Yabs::class, 'server_id', 'id')->orderByDesc('output_date');
    }

    public function ips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IPs::class, 'service_id', 'id');
    }

    public function location(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Locations::class, 'id', 'location_id');
    }

    public function provider(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Providers::class, 'id', 'provider_id');
    }

    public function os(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(OS::class, 'id', 'os_id');
    }

    public function price(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Pricing::class, 'service_id', 'id');
    }

    public function disks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Disk::class, 'server_id', 'id');
    }

    public function labels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LabelsAssigned::class, 'service_id', 'id');
    }

    public function note(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Note::class, 'service_id', 'id');
    }

}

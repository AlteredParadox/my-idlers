<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DNS extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'hostname', 'dns_type', 'address', 'server_id', 'domain_id', 'shared_id', 'reseller_id'];

    public static $dnsTypes = ['A', 'AAAA', 'DNAME', 'MX', 'NS', 'SOA', 'TXT', 'URI'];

    public static function dnsCount()
    {
        return Cache::remember('dns_count', now()->addMonth(1), function () {
            return DNS::count();
        });
    }

    public function note(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Note::class, 'service_id', 'id');
    }

    // DNS records take up to 4 labels through the web form (DNSController
    // syncs LabelsAssigned) — the relation exists so exports can read them
    public function labels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LabelsAssigned::class, 'service_id', 'id');
    }

}

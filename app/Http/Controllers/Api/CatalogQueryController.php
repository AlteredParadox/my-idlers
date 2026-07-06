<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\NetworkSpeed;
use App\Models\OS;
use App\Models\Pricing;
use Illuminate\Support\Facades\DB;

class CatalogQueryController extends Controller
{

    protected function getAllPricing()
    {
        $pricing = Pricing::all()->toJson(JSON_PRETTY_PRINT);
        return response($pricing, 200);
    }


    protected function getPricing($id)
    {
        $pricing = Pricing::where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($pricing, 200);
    }


    protected function getAllNetworkSpeeds()
    {
        $ns = NetworkSpeed::all()->toJson(JSON_PRETTY_PRINT);
        return response($ns, 200);
    }


    protected function getNetworkSpeeds($id)
    {
        $ns = NetworkSpeed::where('server_id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($ns, 200);
    }


    protected function getAllLabels()
    {
        $labels = Labels::all()->toJson(JSON_PRETTY_PRINT);
        return response($labels, 200);
    }


    protected function getLabel($id)
    {
        $label = Labels::where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($label, 200);
    }


    protected function getAllDns()
    {
        $dns = DB::table('d_n_s')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($dns, 200);
    }


    protected function getDns($id)
    {
        $dns = DB::table('d_n_s')
            ->where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($dns, 200);
    }


    protected function getAllLocations()
    {
        $locations = DB::table('locations')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($locations, 200);
    }


    protected function getLocation($id)
    {
        $location = DB::table('locations')
            ->where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($location, 200);
    }


    protected function getAllProviders()
    {
        $providers = DB::table('providers')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($providers, 200);
    }


    protected function getProvider($id)
    {
        $providers = DB::table('providers')
            ->where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($providers, 200);
    }


    protected function getAllSettings()
    {
        $settings = DB::table('settings')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($settings, 200);
    }


    protected function getAllOs()
    {
        $os = OS::allOS();
        $os = json_encode($os, JSON_PRETTY_PRINT);
        return response($os, 200);
    }


    protected function getOs($id)
    {
        $os = DB::table('os as o')
            ->where('o.id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($os, 200);
    }


    protected function getAllIPs()
    {
        $ip = IPs::all()->toJson(JSON_PRETTY_PRINT);
        return response($ip, 200);
    }


    protected function getIP($id)
    {
        $ip = DB::table('ips as i')
            ->where('i.id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($ip, 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domains;
use App\Models\Misc;
use App\Models\Note;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use App\Models\Yabs;

class ServiceQueryController extends Controller
{
    protected function getAllServers()
    {
        $servers = Server::allServers()->toJson(JSON_PRETTY_PRINT);
        return response($servers, 200);
    }


    protected function getServer($id)
    {
        $server = Server::server($id)->toJson(JSON_PRETTY_PRINT);
        return response($server, 200);
    }


    protected function getAllShared()
    {
        $shared = Shared::allSharedHosting()->toJson(JSON_PRETTY_PRINT);
        return response($shared, 200);
    }


    protected function getShared($id)
    {
        $shared = Shared::sharedHosting($id)->toJson(JSON_PRETTY_PRINT);
        return response($shared, 200);
    }


    protected function getAllReseller()
    {
        $reseller = Reseller::allResellerHosting()->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }


    protected function getReseller($id)
    {
        $reseller = Reseller::resellerHosting($id)->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }


    protected function getAllSeedbox()
    {
        $reseller = SeedBoxes::allSeedboxes()->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }


    protected function getSeedbox($id)
    {
        $reseller = SeedBoxes::seedbox($id)->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }


    protected function getAllDomains()
    {
        $domains = Domains::allDomains()->toJson(JSON_PRETTY_PRINT);
        return response($domains, 200);
    }


    protected function getDomains($id)
    {
        $domain = Domains::domain($id)->toJson(JSON_PRETTY_PRINT);
        return response($domain, 200);
    }


    protected function getAllMisc()
    {
        $misc = Misc::allMisc()->toJson(JSON_PRETTY_PRINT);
        return response($misc, 200);
    }


    protected function getMisc($id)
    {
        $misc = Misc::misc($id)->toJson(JSON_PRETTY_PRINT);
        return response($misc, 200);
    }


    public function getAllYabs()
    {
        $yabs = Yabs::allYabs()->toJson(JSON_PRETTY_PRINT);
        return response($yabs, 200);
    }


    protected function getYabs($id)
    {
        $yabs = Yabs::yabs($id)->toJson(JSON_PRETTY_PRINT);
        return response($yabs, 200);
    }


    protected function getNote($id)
    {
        $note = Note::where('id', $id)->firstOrFail('note')->pluck('note');
        return response($note, 200)->header('Content-Type', 'text/plain');
    }
}

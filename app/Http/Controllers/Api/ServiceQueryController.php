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
    private const ERROR_NOT_FOUND = 'Not found';

    protected function getAllServers()
    {
        $servers = Server::allServers()->toJson(JSON_PRETTY_PRINT);
        return response($servers, 200);
    }


    protected function getServer($id)
    {
        $record = Server::server($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($record->toJson(JSON_PRETTY_PRINT), 200);
    }


    protected function getAllShared()
    {
        $shared = Shared::allSharedHosting()->toJson(JSON_PRETTY_PRINT);
        return response($shared, 200);
    }


    protected function getShared($id)
    {
        $record = Shared::sharedHosting($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($record->toJson(JSON_PRETTY_PRINT), 200);
    }


    protected function getAllReseller()
    {
        $reseller = Reseller::allResellerHosting()->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }


    protected function getReseller($id)
    {
        $record = Reseller::resellerHosting($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($record->toJson(JSON_PRETTY_PRINT), 200);
    }


    protected function getAllSeedbox()
    {
        $reseller = SeedBoxes::allSeedboxes()->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }


    protected function getSeedbox($id)
    {
        $record = SeedBoxes::seedbox($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($record->toJson(JSON_PRETTY_PRINT), 200);
    }


    protected function getAllDomains()
    {
        $domains = Domains::allDomains()->toJson(JSON_PRETTY_PRINT);
        return response($domains, 200);
    }


    protected function getDomains($id)
    {
        $record = Domains::domain($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($record->toJson(JSON_PRETTY_PRINT), 200);
    }


    protected function getAllMisc()
    {
        $misc = Misc::allMisc()->toJson(JSON_PRETTY_PRINT);
        return response($misc, 200);
    }


    protected function getMisc($id)
    {
        $record = Misc::misc($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($record->toJson(JSON_PRETTY_PRINT), 200);
    }


    public function getAllYabs()
    {
        $yabs = Yabs::allYabs()->toJson(JSON_PRETTY_PRINT);
        return response($yabs, 200);
    }


    protected function getYabs($id)
    {
        $record = Yabs::yabs($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($record->toJson(JSON_PRETTY_PRINT), 200);
    }


    protected function getNote($id)
    {
        $note = Note::where('id', $id)->firstOrFail(['note'])->note;
        return response($note, 200)->header('Content-Type', 'text/plain');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RespondsWithJsonString;
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
    use RespondsWithJsonString;

    private const ERROR_NOT_FOUND = 'Not found';

    protected function getAllServers()
    {
        $servers = Server::allServers()->toJson(JSON_PRETTY_PRINT);
        return $this->jsonString($servers);
    }


    protected function getServer($id)
    {
        $record = Server::server($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return $this->jsonString($record->toJson(JSON_PRETTY_PRINT));
    }


    protected function getAllShared()
    {
        $shared = Shared::allSharedHosting()->toJson(JSON_PRETTY_PRINT);
        return $this->jsonString($shared);
    }


    protected function getShared($id)
    {
        $record = Shared::sharedHosting($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return $this->jsonString($record->toJson(JSON_PRETTY_PRINT));
    }


    protected function getAllReseller()
    {
        $reseller = Reseller::allResellerHosting()->toJson(JSON_PRETTY_PRINT);
        return $this->jsonString($reseller);
    }


    protected function getReseller($id)
    {
        $record = Reseller::resellerHosting($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return $this->jsonString($record->toJson(JSON_PRETTY_PRINT));
    }


    protected function getAllSeedbox()
    {
        $reseller = SeedBoxes::allSeedboxes()->toJson(JSON_PRETTY_PRINT);
        return $this->jsonString($reseller);
    }


    protected function getSeedbox($id)
    {
        $record = SeedBoxes::seedbox($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return $this->jsonString($record->toJson(JSON_PRETTY_PRINT));
    }


    protected function getAllDomains()
    {
        $domains = Domains::allDomains()->toJson(JSON_PRETTY_PRINT);
        return $this->jsonString($domains);
    }


    protected function getDomains($id)
    {
        $record = Domains::domain($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return $this->jsonString($record->toJson(JSON_PRETTY_PRINT));
    }


    protected function getAllMisc()
    {
        $misc = Misc::allMisc()->toJson(JSON_PRETTY_PRINT);
        return $this->jsonString($misc);
    }


    protected function getMisc($id)
    {
        $record = Misc::misc($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return $this->jsonString($record->toJson(JSON_PRETTY_PRINT));
    }


    public function getAllYabs()
    {
        $yabs = Yabs::allYabs()->toJson(JSON_PRETTY_PRINT);
        return $this->jsonString($yabs);
    }


    protected function getYabs($id)
    {
        $record = Yabs::yabs($id);
        if (is_null($record)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return $this->jsonString($record->toJson(JSON_PRETTY_PRINT));
    }


    protected function getNote($id)
    {
        // first(), not firstOrFail(): a miss must answer with this API's
        // JSON 404 shape, not the framework's ModelNotFound response.
        $note = Note::where('id', $id)->first(['note']);
        if (is_null($note)) {
            return response()->json(['error' => self::ERROR_NOT_FOUND], 404);
        }
        return response($note->note, 200)->header('Content-Type', 'text/plain');
    }
}

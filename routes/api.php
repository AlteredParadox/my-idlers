<?php

use App\Http\Controllers\Api\CatalogQueryController;
use App\Http\Controllers\Api\ServerManagementController;
use App\Http\Controllers\Api\ServiceQueryController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Signed webhook — the one route NOT behind auth:api
Route::middleware(['throttle:4', 'signed'])->post('yabs/{server}', [ServerManagementController::class, 'storeYabs'])->name('api.store-yabs');

Route::middleware('auth:api')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('dns/', [CatalogQueryController::class, 'getAllDns']);
    Route::get('dns/{id}', [CatalogQueryController::class, 'getDns']);

    Route::get('domains/', [ServiceQueryController::class, 'getAllDomains']);
    Route::get('domains/{id}', [ServiceQueryController::class, 'getDomains']);

    $serverById = 'servers/{id}';
    Route::get('servers', [ServiceQueryController::class, 'getAllServers']);
    Route::get($serverById, [ServiceQueryController::class, 'getServer']);

    Route::post('servers', [ServerManagementController::class, 'storeServer']);
    Route::put($serverById, [ServerManagementController::class, 'updateServer']);
    Route::delete($serverById, [ServerManagementController::class, 'destroyServer']);

    Route::get('IPs/', [CatalogQueryController::class, 'getAllIPs']);
    Route::get('IPs/{id}', [CatalogQueryController::class, 'getIP']);

    Route::get('labels/', [CatalogQueryController::class, 'getAllLabels']);
    Route::get('labels/{id}', [CatalogQueryController::class, 'getLabel']);

    Route::get('locations/', [CatalogQueryController::class, 'getAllLocations']);
    Route::get('locations/{id}', [CatalogQueryController::class, 'getLocation']);

    Route::get('misc/', [ServiceQueryController::class, 'getAllMisc']);
    Route::get('misc/{id}', [ServiceQueryController::class, 'getMisc']);

    Route::get('networkSpeeds/', [CatalogQueryController::class, 'getAllNetworkSpeeds']);
    Route::get('networkSpeeds/{id}', [CatalogQueryController::class, 'getNetworkSpeeds']);

    Route::get('os/', [CatalogQueryController::class, 'getAllOs']);
    Route::get('os/{id}', [CatalogQueryController::class, 'getOs']);

    Route::get('pricing/', [CatalogQueryController::class, 'getAllPricing']);
    Route::get('pricing/{id}', [CatalogQueryController::class, 'getPricing']);
    Route::put('pricing/{id}', [ServerManagementController::class, 'updatePricing']);

    Route::get('providers/', [CatalogQueryController::class, 'getAllProviders']);
    Route::get('providers/{id}', [CatalogQueryController::class, 'getProvider']);

    Route::get('reseller/', [ServiceQueryController::class, 'getAllReseller']);
    Route::get('reseller/{id}', [ServiceQueryController::class, 'getReseller']);

    Route::get('seedbox/', [ServiceQueryController::class, 'getAllSeedbox']);
    Route::get('seedbox/{id}', [ServiceQueryController::class, 'getSeedbox']);

    Route::get('settings/', [CatalogQueryController::class, 'getAllSettings']);

    Route::get('shared/', [ServiceQueryController::class, 'getAllShared']);
    Route::get('shared/{id}', [ServiceQueryController::class, 'getShared']);

    Route::get('online/{hostname}', [ToolsController::class, 'checkHostIsUp']);

    Route::get('prometheus/status', [ToolsController::class, 'prometheusStatus']);
    Route::get('prometheus/detail/{hostname}/{period}/{back}', [ToolsController::class, 'prometheusDetail'])
        ->where(['hostname' => '[a-zA-Z0-9.:_-]+', 'period' => '[0-9]+[hdmy]', 'back' => '[0-9]+']);

    Route::get('dns/{domainName}/{type}', [ToolsController::class, 'getIpForDomain'])
        ->where(['domainName' => '[a-zA-Z0-9._-]+', 'type' => 'A|AAAA']);

    Route::get('yabs/', [ServiceQueryController::class, 'getAllYabs']);
    Route::get('yabs/{id}', [ServiceQueryController::class, 'getYabs']);

    Route::get('note/{id}', [ServiceQueryController::class, 'getNote']);

    // Export routes
    Route::get('export/servers', [ApiController::class, 'exportServers']);
    Route::get('export/domains', [ApiController::class, 'exportDomains']);
    Route::get('export/shared', [ApiController::class, 'exportShared']);
    Route::get('export/reseller', [ApiController::class, 'exportReseller']);
    Route::get('export/seedboxes', [ApiController::class, 'exportSeedboxes']);
    Route::get('export/dns', [ApiController::class, 'exportDns']);
    Route::get('export/misc', [ApiController::class, 'exportMisc']);
    Route::get('export/all', [ApiController::class, 'exportAll']);
});

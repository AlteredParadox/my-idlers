<?php

use Illuminate\Http\JsonResponse;
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

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:api')->get('dns/', 'App\Http\Controllers\Api\CatalogQueryController@getAllDns');
Route::middleware('auth:api')->get('dns/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getDns');

Route::middleware('auth:api')->get('domains/', 'App\Http\Controllers\Api\ServiceQueryController@getAllDomains');
Route::middleware('auth:api')->get('domains/{id}', 'App\Http\Controllers\Api\ServiceQueryController@getDomains');

Route::middleware('auth:api')->get('servers', 'App\Http\Controllers\Api\ServiceQueryController@getAllServers');
$serverById = 'servers/{id}';
Route::middleware('auth:api')->get($serverById, 'App\Http\Controllers\Api\ServiceQueryController@getServer');

Route::middleware('auth:api')->post('servers', 'App\Http\Controllers\Api\ServerManagementController@storeServer');
Route::middleware('auth:api')->put($serverById, 'App\Http\Controllers\Api\ServerManagementController@updateServer');
Route::middleware('auth:api')->delete($serverById, 'App\Http\Controllers\Api\ServerManagementController@destroyServer');

Route::middleware('auth:api')->get('IPs/', 'App\Http\Controllers\Api\CatalogQueryController@getAllIPs');
Route::middleware('auth:api')->get('IPs/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getIP');

Route::middleware('auth:api')->get('labels/', 'App\Http\Controllers\Api\CatalogQueryController@getAllLabels');
Route::middleware('auth:api')->get('labels/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getLabel');

Route::middleware('auth:api')->get('locations/', 'App\Http\Controllers\Api\CatalogQueryController@getAllLocations');
Route::middleware('auth:api')->get('locations/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getLocation');

Route::middleware('auth:api')->get('misc/', 'App\Http\Controllers\Api\ServiceQueryController@getAllMisc');
Route::middleware('auth:api')->get('misc/{id}', 'App\Http\Controllers\Api\ServiceQueryController@getMisc');

Route::middleware('auth:api')->get('networkSpeeds/', 'App\Http\Controllers\Api\CatalogQueryController@getAllNetworkSpeeds');
Route::middleware('auth:api')->get('networkSpeeds/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getNetworkSpeeds');

Route::middleware('auth:api')->get('os/', 'App\Http\Controllers\Api\CatalogQueryController@getAllOs');
Route::middleware('auth:api')->get('os/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getOs');

Route::middleware('auth:api')->get('pricing/', 'App\Http\Controllers\Api\CatalogQueryController@getAllPricing');
Route::middleware('auth:api')->get('pricing/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getPricing');
Route::middleware('auth:api')->put('pricing/{id}', 'App\Http\Controllers\Api\ServerManagementController@updatePricing');

Route::middleware('auth:api')->get('providers/', 'App\Http\Controllers\Api\CatalogQueryController@getAllProviders');
Route::middleware('auth:api')->get('providers/{id}', 'App\Http\Controllers\Api\CatalogQueryController@getProvider');

Route::middleware('auth:api')->get('reseller/', 'App\Http\Controllers\Api\ServiceQueryController@getAllReseller');
Route::middleware('auth:api')->get('reseller/{id}', 'App\Http\Controllers\Api\ServiceQueryController@getReseller');

Route::middleware('auth:api')->get('seedbox/', 'App\Http\Controllers\Api\ServiceQueryController@getAllSeedbox');
Route::middleware('auth:api')->get('seedbox/{id}', 'App\Http\Controllers\Api\ServiceQueryController@getSeedbox');

Route::middleware('auth:api')->get('settings/', 'App\Http\Controllers\Api\CatalogQueryController@getAllSettings');

Route::middleware('auth:api')->get('shared/', 'App\Http\Controllers\Api\ServiceQueryController@getAllShared');
Route::middleware('auth:api')->get('shared/{id}', 'App\Http\Controllers\Api\ServiceQueryController@getShared');


Route::middleware('auth:api')->get('online/{hostname}', 'App\Http\Controllers\Api\ToolsController@checkHostIsUp');

Route::middleware('auth:api')->get('prometheus/status', 'App\Http\Controllers\Api\ToolsController@prometheusStatus');
Route::middleware('auth:api')->get('prometheus/detail/{hostname}/{period}/{back}', 'App\Http\Controllers\Api\ToolsController@prometheusDetail')
    ->where(['hostname' => '[a-zA-Z0-9._-]+', 'period' => '[0-9]+[hdmy]', 'back' => '[0-9]+']);

Route::middleware('auth:api')->get('dns/{domainName}/{type}', 'App\Http\Controllers\Api\ToolsController@getIpForDomain')
    ->where(['domainName' => '[a-zA-Z0-9._-]+', 'type' => 'A|AAAA']);

Route::middleware(['throttle:4', 'signed'])->post('yabs/{server}', 'App\Http\Controllers\Api\ServerManagementController@storeYabs')->name('api.store-yabs');
Route::middleware('auth:api')->get('yabs/', 'App\Http\Controllers\Api\ServiceQueryController@getAllYabs');
Route::middleware('auth:api')->get('yabs/{id}', 'App\Http\Controllers\Api\ServiceQueryController@getYabs');

Route::middleware('auth:api')->get('note/{id}', 'App\Http\Controllers\Api\ServiceQueryController@getNote');

// Export routes
Route::middleware('auth:api')->group(function () {
    Route::get('export/servers', [App\Http\Controllers\ApiController::class, 'exportServers']);
    Route::get('export/domains', [App\Http\Controllers\ApiController::class, 'exportDomains']);
    Route::get('export/shared', [App\Http\Controllers\ApiController::class, 'exportShared']);
    Route::get('export/reseller', [App\Http\Controllers\ApiController::class, 'exportReseller']);
    Route::get('export/seedboxes', [App\Http\Controllers\ApiController::class, 'exportSeedboxes']);
    Route::get('export/dns', [App\Http\Controllers\ApiController::class, 'exportDns']);
    Route::get('export/misc', [App\Http\Controllers\ApiController::class, 'exportMisc']);
    Route::get('export/all', [App\Http\Controllers\ApiController::class, 'exportAll']);
});

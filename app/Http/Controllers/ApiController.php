<?php

namespace App\Http\Controllers;

use App\Models\Domains;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\Misc;
use App\Models\NetworkSpeed;
use App\Models\Note;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use App\Models\Yabs;
use App\Services\ExportService;
use App\Services\PrometheusService;
use DataTables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    /**
     * The export service instance.
     *
     * @var ExportService
     */
    protected ExportService $exportService;

    /**
     * Create a new controller instance.
     *
     * @param ExportService $exportService
     */
    public function __construct(ExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * API endpoint for exporting servers
     * GET /api/export/servers?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportServers(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportServers($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting domains
     * GET /api/export/domains?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportDomains(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportDomains($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting shared hosting
     * GET /api/export/shared?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportShared(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportShared($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting reseller hosting
     * GET /api/export/reseller?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportReseller(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportReseller($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting seedboxes
     * GET /api/export/seedboxes?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportSeedboxes(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportSeedboxes($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting DNS records
     * GET /api/export/dns?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportDns(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportDns($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting misc services
     * GET /api/export/misc?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportMisc(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportMisc($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting all data
     * GET /api/export/all?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportAll(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        $export = $this->exportService->exportAll($format);

        return $this->createExportResponse($export);
    }

    /**
     * Create a response with appropriate headers for API export
     *
     * @param array{data: string, filename: string, content_type: string} $export
     * @return \Illuminate\Http\Response
     */
    protected function createExportResponse(array $export): \Illuminate\Http\Response
    {
        return response($export['data'], 200)
            ->header('Content-Type', $export['content_type'])
            ->header('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
    }
}

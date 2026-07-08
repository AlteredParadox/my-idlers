<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use Illuminate\Http\Request;

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
     */
    public function exportServers(Request $request)
    {
        return $this->handleExport($request, 'exportServers');
    }

    /**
     * API endpoint for exporting domains
     * GET /api/export/domains?format=json|csv
     */
    public function exportDomains(Request $request)
    {
        return $this->handleExport($request, 'exportDomains');
    }

    /**
     * API endpoint for exporting shared hosting
     * GET /api/export/shared?format=json|csv
     */
    public function exportShared(Request $request)
    {
        return $this->handleExport($request, 'exportShared');
    }

    /**
     * API endpoint for exporting reseller hosting
     * GET /api/export/reseller?format=json|csv
     */
    public function exportReseller(Request $request)
    {
        return $this->handleExport($request, 'exportReseller');
    }

    /**
     * API endpoint for exporting seedboxes
     * GET /api/export/seedboxes?format=json|csv
     */
    public function exportSeedboxes(Request $request)
    {
        return $this->handleExport($request, 'exportSeedboxes');
    }

    /**
     * API endpoint for exporting DNS records
     * GET /api/export/dns?format=json|csv
     */
    public function exportDns(Request $request)
    {
        return $this->handleExport($request, 'exportDns');
    }

    /**
     * API endpoint for exporting misc services
     * GET /api/export/misc?format=json|csv
     */
    public function exportMisc(Request $request)
    {
        return $this->handleExport($request, 'exportMisc');
    }

    /**
     * API endpoint for exporting all data
     * GET /api/export/all?format=json|csv
     */
    public function exportAll(Request $request)
    {
        return $this->handleExport($request, 'exportAll');
    }

    /**
     * Validate the requested format and run one ExportService export method
     *
     * @param Request $request
     * @param string $method ExportService method name
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    protected function handleExport(Request $request, string $method)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        return $this->createExportResponse($this->exportService->{$method}($format));
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

<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param ExportService $exportService
     */
    public function __construct(
        protected ExportService $exportService
    ) {}

    /**
     * Export servers data
     */
    public function servers(Request $request)
    {
        return $this->handleExport($request, 'exportServers');
    }

    /**
     * Export domains data
     */
    public function domains(Request $request)
    {
        return $this->handleExport($request, 'exportDomains');
    }

    /**
     * Export shared hosting data
     */
    public function shared(Request $request)
    {
        return $this->handleExport($request, 'exportShared');
    }

    /**
     * Export reseller hosting data
     */
    public function reseller(Request $request)
    {
        return $this->handleExport($request, 'exportReseller');
    }

    /**
     * Export seedboxes data
     */
    public function seedboxes(Request $request)
    {
        return $this->handleExport($request, 'exportSeedboxes');
    }

    /**
     * Export DNS records
     */
    public function dns(Request $request)
    {
        return $this->handleExport($request, 'exportDns');
    }

    /**
     * Export misc services data
     */
    public function misc(Request $request)
    {
        return $this->handleExport($request, 'exportMisc');
    }

    /**
     * Export all data (global export)
     */
    public function all(Request $request)
    {
        return $this->handleExport($request, 'exportAll');
    }

    /**
     * Validate the requested format and run one ExportService export method
     *
     * @param Request $request
     * @param string $method ExportService method name
     * @return StreamedResponse|\Illuminate\Http\JsonResponse
     */
    protected function handleExport(Request $request, string $method)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => ExportService::ERROR_INVALID_FORMAT
            ], 400);
        }

        return $this->createStreamedResponse($this->exportService->{$method}($format));
    }

    /**
     * Create a StreamedResponse with appropriate headers for file download
     *
     * @param array{data: string, filename: string, content_type: string} $export
     * @return StreamedResponse
     */
    protected function createStreamedResponse(array $export): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($export) {
                echo $export['data'];
            },
            200,
            [
                'Content-Type' => $export['content_type'],
                'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );
    }
}

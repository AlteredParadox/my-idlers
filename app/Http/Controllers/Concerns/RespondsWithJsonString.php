<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Response;

/**
 * These API endpoints serialize to a JSON string themselves (they want
 * JSON_PRETTY_PRINT, which response()->json() only reaches through its
 * $options argument and which Eloquent's toJson() already applies), so the
 * string goes back through the generic response() helper.
 *
 * The generic helper sets no Content-Type, and Symfony then defaults the
 * response to `text/html; charset=UTF-8`. A browser navigating to such an
 * endpoint parses the payload as HTML, so any stored `<img onerror=...>`
 * inside a record becomes an active element in the application's own origin.
 * `X-Content-Type-Options: nosniff` does not help: the server is not being
 * sniffed, it is actively declaring HTML.
 */
trait RespondsWithJsonString
{
    /**
     * Return an already-serialized JSON string with an honest media type.
     */
    protected function jsonString(string $json, int $status = 200): Response
    {
        return response($json, $status)
            ->header('Content-Type', 'application/json; charset=UTF-8');
    }
}

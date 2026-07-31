<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * nginx logs to container stdout, which a log shipper -- or anyone with
 * `docker logs` -- can read. The stock `combined` format logs the whole
 * request line, and two live capabilities were landing there in plaintext:
 * password-reset tokens (in the PATH) and signed-URL signatures for YABS
 * ingestion (in the QUERY). Either is enough to act as the user until it
 * expires.
 */
class AccessLogSanitisationTest extends TestCase
{
    private function conf(): string
    {
        return file_get_contents(base_path('docker/nginx.conf'));
    }

    public function test_the_access_log_uses_the_sanitised_format()
    {
        $conf = $this->conf();

        $this->assertStringContainsString('access_log /dev/stdout idlers_sanitised;', $conf);
        $this->assertStringNotContainsString("access_log /dev/stdout;\n", $conf, 'the default combined format logs the query string');
    }

    public function test_the_log_format_omits_the_raw_request_line()
    {
        $conf = $this->conf();

        preg_match('/log_format idlers_sanitised(.*?);/s', $conf, $m);
        $this->assertNotEmpty($m, 'no idlers_sanitised log_format defined');

        // $request is method + URI + protocol, query string included -- the
        // whole reason this format exists is to not log it.
        $this->assertStringNotContainsString('$request ', $m[1]);
        $this->assertStringContainsString('$idlers_logged_path', $m[1]);
    }

    public function test_secret_bearing_paths_are_redacted()
    {
        $conf = $this->conf();

        // Tokens live in the path for these two routes, so dropping the query
        // string is not enough on its own.
        $this->assertMatchesRegularExpression('#\~\^/reset-password/\.\s+"/reset-password/\[redacted\]"#', $conf);
        $this->assertMatchesRegularExpression('#\~\^/verify-email/\.\s+"/verify-email/\[redacted\]"#', $conf);
    }

    public function test_the_query_string_is_stripped_before_logging()
    {
        // Signed YABS URLs carry expires+signature as query parameters; the
        // map keeps only what precedes the '?'.
        $this->assertStringContainsString('map $request_uri $idlers_request_path', $this->conf());
        $this->assertStringContainsString('[^?]*', $this->conf());
    }

    /**
     * The maps must sit outside the server block: `map` is only legal in
     * http context, and nginx refuses to start otherwise.
     */
    public function test_maps_are_declared_before_the_server_block()
    {
        $conf = $this->conf();

        $firstServer = strpos($conf, "\nserver {");
        $this->assertNotFalse($firstServer);

        foreach (['map $request_uri', 'map $idlers_request_path', 'log_format idlers_sanitised'] as $directive) {
            $at = strpos($conf, $directive);
            $this->assertNotFalse($at, "missing $directive");
            $this->assertLessThan($firstServer, $at, "$directive must be in http context, not inside server{}");
        }
    }
}

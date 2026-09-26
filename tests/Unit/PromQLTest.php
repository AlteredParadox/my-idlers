<?php

namespace Tests\Unit;

use App\Services\PromQL;
use PHPUnit\Framework\TestCase;

class PromQLTest extends TestCase
{
    public function test_regex_alternation_escapes_metacharacters_and_the_string_literal()
    {
        // Dots are RE2 metacharacters; the escaping backslash is then doubled
        // for the PromQL string literal, so the server sees `10\.0\.0\.1:9100`.
        $this->assertSame('10\\\\.0\\\\.0\\\\.1:9100', PromQL::regexAlternation(['10.0.0.1:9100']));
        // A literal `|` in a value must not become an alternation of its own.
        $this->assertSame('a\\\\|b|c', PromQL::regexAlternation(['a|b', 'c']));
        // Quotes and backslashes go through the string-literal escaping.
        $this->assertSame('x\\"y|\\\\\\\\z', PromQL::regexAlternation(['x"y', '\\z']));
        // Anchored by Prometheus, so a plain hostname needs no ^ or $.
        $this->assertSame('web1:9100|db(2):9100', str_replace('\\', '', PromQL::regexAlternation(['web1:9100', 'db(2):9100'])));
    }
}

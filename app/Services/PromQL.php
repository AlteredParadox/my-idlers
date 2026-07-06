<?php

namespace App\Services;

/**
 * Shared low-level PromQL/host helpers used by both the status path
 * (PrometheusService::statusPayload) and the detail path
 * (PrometheusInstanceResolver / detailPayload).
 */
final class PromQL
{
    /**
     * Escape a label value for safe embedding in a PromQL `instance="..."`
     * string literal. Prometheus instance labels are admin-controlled in
     * practice, but a stray quote or backslash would break the selector.
     */
    public static function quote(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    public static function isUp(array $result): bool
    {
        return isset($result['value'][1]) && $result['value'][1] === '1';
    }

    /**
     * The single host-matching truth table, mirrored by the list views' JS:
     * full equality or whole-short-label equality on either side. Exact-label
     * semantics only — a prefix test would let web10 answer for web1. If
     * either side is an IP, only exact equality counts: short-label logic
     * would let a bare-first-label candidate ('192') match a dotted IP.
     * The list and the detail resolver MUST accept the same shapes or the
     * index shows live monitoring while the detail page 404s.
     */
    public static function hostMatches(string $stored, string $candidate): bool
    {
        if ($stored === '' || $candidate === '') {
            return false;
        }

        if (filter_var($stored, FILTER_VALIDATE_IP) !== false
            || filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $stored === $candidate;
        }

        return $candidate === $stored
            || $candidate === explode('.', $stored)[0]
            || $stored === explode('.', $candidate)[0]
            || str_starts_with($stored, $candidate . '.');
    }
}

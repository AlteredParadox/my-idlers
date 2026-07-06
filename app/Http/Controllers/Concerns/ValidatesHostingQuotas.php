<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/** Validation helpers shared by shared- and reseller-hosting store/update. */
trait ValidatesHostingQuotas
{
    /**
     * Collect + validate the dedicated IP plus any ipN fields (IPs assigned
     * via the IPs page round-trip through the edit form — syncing only the
     * one dedicated_ip slot silently deleted the others and their notes).
     * Must run BEFORE any write: failing after the service update would
     * leave persisted changes with every cache forget skipped.
     */
    private function collectSubmittedIps(Request $request): array
    {
        $submitted = is_null($request->dedicated_ip) ? [] : [$request->dedicated_ip];
        $extra = [];
        foreach ($request->all() as $key => $value) {
            if (preg_match('/^ip\d+$/', $key) && !is_null($value)) {
                $extra[$key] = $value;
            }
        }
        $request->validate(array_fill_keys(array_keys($extra), 'ip'));

        return array_merge($submitted, array_values($extra));
    }

    private function hostingQuotaRules(): array
    {
        return [
            'disk' => 'integer|min:0|max:1000000',
            'domains' => 'integer|min:0|max:1000000',
            'sub_domains' => 'integer|min:0|max:1000000',
            'bandwidth' => 'integer|min:0|max:100000000',
            'link_speed' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'email' => 'integer|min:0|max:1000000',
            'ftp' => 'integer|min:0|max:1000000',
            'db' => 'integer|min:0|max:1000000',
        ];
    }
}

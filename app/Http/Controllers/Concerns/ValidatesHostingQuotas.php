<?php

namespace App\Http\Controllers\Concerns;

/** The quota rules shared by shared- and reseller-hosting store/update. */
trait ValidatesHostingQuotas
{
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

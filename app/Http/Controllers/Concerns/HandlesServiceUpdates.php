<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Labels;
use App\Models\Pricing;
use Illuminate\Http\Request;

/** Post-write helpers shared by the service-type controllers. */
trait HandlesServiceUpdates
{
    /**
     * The update-path tail every service type runs: re-write the pricing
     * row, then replace the label assignments.
     */
    private function syncPricingAndLabels(Request $request, string $service_id, int $is_active): void
    {
        (new Pricing())->updatePricing($service_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date, $is_active);

        Labels::deleteLabelsAssignedTo($service_id);
        Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $service_id);
    }

    /** Link speeds are stored in Mbps; Gbps submissions convert on the way in. */
    private function linkSpeedAsMbps(Request $request)
    {
        if (!$request->link_speed) {
            return null;
        }

        return $request->link_speed_type === 'Gbps' ? $request->link_speed * 1000 : $request->link_speed;
    }
}

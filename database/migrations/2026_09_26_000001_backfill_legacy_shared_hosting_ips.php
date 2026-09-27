<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh installations never had these columns. On upgraded installations,
        // however, editing the original create-table migration did not remove them
        // or move their data into the table now used by the application.
        if (!Schema::hasColumn('shared_hosting', 'ip')) {
            return;
        }

        DB::table('shared_hosting')
            ->whereNotNull('ip')
            ->where('ip', '<>', '')
            ->select(['id', 'ip'])
            ->orderBy('id')
            ->chunk(100, function ($sharedHosting): void {
                foreach ($sharedHosting as $shared) {
                    if (DB::table('ips')
                        ->where('service_id', $shared->id)
                        ->where('address', $shared->ip)
                        ->exists()) {
                        continue;
                    }

                    do {
                        $id = Str::random(8);
                    } while (DB::table('ips')->where('id', $id)->exists());

                    DB::table('ips')->insert([
                        'id' => $id,
                        'service_id' => $shared->id,
                        'address' => $shared->ip,
                        'is_ipv4' => filter_var($shared->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 0 : 1,
                        'active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Backfilled rows are indistinguishable from IPs added through the UI.
        // Retaining them is safer than deleting user inventory on rollback.
    }
};

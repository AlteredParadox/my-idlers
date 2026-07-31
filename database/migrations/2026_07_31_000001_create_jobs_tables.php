<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing tables for the database queue.
 *
 * Password-reset mail is dispatched to the queue so the SMTP conversation
 * leaves the public HTTP request: inline delivery both held a web worker for
 * the length of an SMTP stall and made /forgot-password answer measurably
 * faster for an address that does not exist than for one that does, which is
 * an account-existence oracle the generic response body cannot hide.
 *
 * failed_jobs is created alongside deliberately: a queue whose failures vanish
 * is worse than no queue, because password-reset mail would stop silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');
    }
};

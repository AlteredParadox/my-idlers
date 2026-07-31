<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The framework's reset notification, made queueable.
 *
 * /forgot-password answers with the same generic message whether or not the
 * address exists, but on the default sync queue it does the SMTP conversation
 * INLINE for a real account and returns immediately for an unknown one. That
 * timing difference is an account-existence oracle the generic wording cannot
 * hide.
 *
 * With a real queue configured (QUEUE_CONNECTION=database/redis + a worker)
 * the send moves off the request and both branches return in the same time.
 * On QUEUE_CONNECTION=sync this behaves exactly as before -- sync executes
 * inline -- so it is a no-op for the documented default rather than a silent
 * requirement to run a worker.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use InteractsWithQueue;
}

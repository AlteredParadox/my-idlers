<?php

/*
 * Guest GETs are throttled as well as their POST counterparts.
 *
 * Every request through the `web` group persists a session row with the
 * shipped database driver -- measured at one row per fresh cookie-less
 * request, including for a 404 -- so an anonymous caller that discards
 * cookies grows the sessions table for as long as it keeps asking. That is
 * Laravel's session handling, not something this app forces: disabling the
 * settings-snapshot middleware entirely changes nothing, and these pages
 * cannot drop their session anyway because the login/reset forms need a
 * CSRF token bound to one.
 *
 * So the bound is a rate limit, not a redesign: growth becomes at most
 * rate x SESSION_LIFETIME, which the session GC lottery then prunes.
 */

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/register', [RegisteredUserController::class, 'create'])
                ->middleware(['guest', 'throttle:guest-pages'])
                ->name('register');

Route::post('/register', [RegisteredUserController::class, 'store'])
                ->middleware(['guest', 'throttle:credentials']);

Route::get('/login', [AuthenticatedSessionController::class, 'create'])
                ->middleware(['guest', 'throttle:guest-pages'])
                ->name('login');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
                ->middleware('guest');

Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
                ->middleware(['guest', 'throttle:guest-pages'])
                ->name('password.request');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
                ->middleware(['guest', 'throttle:credentials'])
                ->name('password.email');

Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
                ->middleware(['guest', 'throttle:guest-pages'])
                ->name('password.reset');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
                ->middleware(['guest', 'throttle:credentials'])
                ->name('password.update');

Route::get('/verify-email', [EmailVerificationPromptController::class, '__invoke'])
                ->middleware('auth')
                ->name('verification.notice');

Route::get('/verify-email/{id}/{hash}', [VerifyEmailController::class, '__invoke'])
                ->middleware(['auth', 'signed', 'throttle:credentials'])
                ->name('verification.verify');

Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
                ->middleware(['auth', 'throttle:credentials'])
                ->name('verification.send');

Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])
                ->middleware('auth')
                ->name('password.confirm');

// Throttled like every other credential-checking POST in this file: without
// it this endpoint is an unlimited password oracle for anyone holding a
// session, which is exactly the position the confirm screen exists to test.
Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store'])
                ->middleware(['auth', 'throttle:credentials']);

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
                ->middleware('auth')
                ->name('logout');

<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

// C-03 (2026-07-02) — Registration routes REMOVED.
// The app is internal-facing (21st Century AV staff + read-only client
// accounts); public self-service signup is not a required flow, and its
// combination with the "shared workspace" project access model
// (ProjectController::authorizeProject grants any authenticated user full
// access to every project) meant a random internet signup could read/write
// every client project. Admins add new users via /admin/users/create
// (Admin\UserController) instead.
//
// If registration is ever required, restore the routes here AND consider:
//   1. throttle:5,60 on both endpoints
//   2. email-domain allow-list validation
//   3. is_active=false default with admin approval workflow
//   4. reCAPTCHA / hCaptcha on the create form
//
// The root URL (`/`) redirects to /login for guests and /dashboard for
// authenticated users (routes/web.php:45), so there's no landing page
// public "Register" link to worry about hiding — the redirect happens
// before any view is rendered.
//
// Security audit reference: .planning/audits/security-audit-2026-05-17.md
// finding C-03.

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

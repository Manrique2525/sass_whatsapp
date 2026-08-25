<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Invitations\InvitationWebController;
use App\Http\Controllers\Settings\AnalyticsSettingsController;
use App\Http\Controllers\Settings\BillingSettingsController;
use App\Http\Controllers\Settings\BusinessProfileSettingsController;
use App\Http\Controllers\Settings\ContactSettingsController;
use App\Http\Controllers\Settings\ConversationsController;
use App\Http\Controllers\Settings\FaqSettingsController;
use App\Http\Controllers\Settings\FlowEditorSettingsController;
use App\Http\Controllers\Settings\FlowsSettingsController;
use App\Http\Controllers\Settings\LeadSettingsController;
use App\Http\Controllers\Settings\UserSettingsController;
use App\Http\Controllers\Settings\WhatsAppSettingsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/health', HealthController::class);

Route::get('invitations/{token}', [InvitationWebController::class, 'show'])
    ->middleware('throttle:invitation')
    ->name('invitations.show');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:auth-login')
        ->name('login.store');

    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:auth-register')
        ->name('register.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:auth-password')
        ->name('password.email');

    Route::get('reset-password', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:auth-password')
        ->name('password.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('verify-email', EmailVerificationPromptController::class)->name('verification.notice');
    Route::post('email/resend', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)
        ->middleware('verified')
        ->name('dashboard');

    Route::get('settings/users', [UserSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.users');

    Route::get('settings/business-profile', [BusinessProfileSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.business-profile');

    Route::get('settings/whatsapp', [WhatsAppSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.whatsapp');

    Route::get('settings/contacts', [ContactSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.contacts');

    Route::get('settings/conversations', [ConversationsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.conversations');

    Route::get('settings/flows', [FlowsSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.flows');

    Route::get('settings/flows/{chatbot}/{flow}', [FlowEditorSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.flows.editor');

    Route::get('settings/faq', [FaqSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.faq');

    Route::get('settings/leads', [LeadSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.leads');

    Route::get('settings/analytics', [AnalyticsSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.analytics');

    Route::get('settings/billing', [BillingSettingsController::class, 'show'])
        ->middleware(['verified', 'tenant'])
        ->name('settings.billing');
});

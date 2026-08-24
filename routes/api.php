<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\ChatbotController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FaqController;
use App\Http\Controllers\Api\V1\FlowController;
use App\Http\Controllers\Api\V1\FlowExecutionController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\KnowledgeBaseController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MemberInvitationController;
use App\Http\Controllers\Api\V1\MessagesController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TriggerController;
use App\Http\Controllers\Api\V1\UsageController;
use App\Http\Controllers\Api\V1\WhatsAppController;
use App\Http\Controllers\Api\Webhooks\FlowWebhookController;
use App\Http\Controllers\Api\Webhooks\StripeWebhookController;
use App\Http\Controllers\Api\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks de WhatsApp (públicos, sin auth Bearer): autenticados por
// verificación GET (hub.verify_token) y firma X-Hub-Signature-256.
Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle']);

Route::post('webhooks/flows/{trigger}', [FlowWebhookController::class, 'handle'])
    ->middleware('throttle:flow-webhook');

// Stripe webhook (public, authenticated by Stripe-Signature, no auth/tenant middleware).
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth-register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');

    Route::post('auth/forgot-password', [PasswordController::class, 'forgot'])
        ->middleware('throttle:auth-password');

    Route::post('auth/reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:auth-password');

    // Invitaciones públicas (el token en el enlace ES la credencial).
    Route::get('invitations/{token}', [InvitationController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);

        Route::prefix('tenants')->group(function (): void {
            Route::get('/', [TenantController::class, 'index'])
                ->middleware('can:viewAny,'.Tenant::class);

            // La autorización efectiva la aplican los Application Services +
            // controller: no-miembro/no-activo -> 404; suspendido -> 409
            // (ocultar existencia, ver ADR-010/023). Las policies quedan como
            // capa programática (authorize()) y para el index.
            Route::post('{tenant}/switch', [TenantController::class, 'switch']);

            // Recursos del tenant bajo contexto `tenant`.
            Route::middleware('tenant')->group(function (): void {
                Route::get('{tenant}', [TenantController::class, 'show']);

                Route::put('{tenant}', [TenantController::class, 'update']);

                // FASE 4 — usuarios y roles.
                Route::get('{tenant}/users', [MemberController::class, 'index']);
                Route::patch('{tenant}/users/{user}', [MemberController::class, 'update']);
                Route::delete('{tenant}/users/{user}', [MemberController::class, 'destroy']);

                Route::get('{tenant}/users/invitations', [MemberInvitationController::class, 'index']);
                Route::post('{tenant}/users/invitations', [MemberInvitationController::class, 'store']);
                Route::post('{tenant}/users/invitations/{invitation}/revoke', [MemberInvitationController::class, 'revoke']);
                Route::post('{tenant}/users/invitations/{invitation}/resend', [MemberInvitationController::class, 'resend']);

                // FASE 5 — perfil de negocio.
                Route::get('{tenant}/business-profile', [BusinessProfileController::class, 'show']);
                Route::put('{tenant}/business-profile', [BusinessProfileController::class, 'update']);

                // FASE 6 — conexión de WhatsApp.
                Route::get('{tenant}/whatsapp', [WhatsAppController::class, 'show']);
                Route::post('{tenant}/whatsapp/connect', [WhatsAppController::class, 'connect']);
                Route::post('{tenant}/whatsapp/disconnect', [WhatsAppController::class, 'disconnect']);

                // FASE 7 — CRM de contactos.
                Route::get('{tenant}/contacts', [ContactController::class, 'index']);
                Route::post('{tenant}/contacts', [ContactController::class, 'store']);
                Route::get('{tenant}/contacts/{contact}', [ContactController::class, 'show']);
                Route::patch('{tenant}/contacts/{contact}', [ContactController::class, 'update']);
                Route::delete('{tenant}/contacts/{contact}', [ContactController::class, 'destroy']);

                // FASE 8 — conversaciones (inbox).
                Route::get('{tenant}/conversations', [ConversationController::class, 'index']);
                Route::post('{tenant}/conversations', [ConversationController::class, 'store']);
                Route::get('{tenant}/conversations/{conversation}', [ConversationController::class, 'show']);
                Route::patch('{tenant}/conversations/{conversation}', [ConversationController::class, 'update']);
                Route::post('{tenant}/conversations/{conversation}/assign', [ConversationController::class, 'assign']);
                Route::post('{tenant}/conversations/{conversation}/transfer', [ConversationController::class, 'transfer']);
                Route::post('{tenant}/conversations/{conversation}/claim', [ConversationController::class, 'claim']);
                Route::post('{tenant}/conversations/{conversation}/close', [ConversationController::class, 'close']);
                Route::post('{tenant}/conversations/{conversation}/reopen', [ConversationController::class, 'reopen']);
                Route::post('{tenant}/conversations/{conversation}/pause-bot', [ConversationController::class, 'pauseBot']);
                Route::post('{tenant}/conversations/{conversation}/resume-bot', [ConversationController::class, 'resumeBot']);

                // FASE 10 — historial y envío de mensajes (inbox chat).
                Route::get('{tenant}/conversations/{conversation}/messages', [MessagesController::class, 'index']);
                Route::post('{tenant}/conversations/{conversation}/messages', [MessagesController::class, 'store']);

                // FASE 11 — chatbots, flujos y triggers.
                Route::get('{tenant}/chatbots', [ChatbotController::class, 'index']);
                Route::post('{tenant}/chatbots', [ChatbotController::class, 'store']);
                Route::get('{tenant}/chatbots/{chatbot}', [ChatbotController::class, 'show']);
                Route::patch('{tenant}/chatbots/{chatbot}', [ChatbotController::class, 'update']);
                Route::delete('{tenant}/chatbots/{chatbot}', [ChatbotController::class, 'destroy']);

                Route::get('{tenant}/chatbots/{chatbot}/flows', [FlowController::class, 'index']);
                Route::post('{tenant}/chatbots/{chatbot}/flows', [FlowController::class, 'store']);
                Route::get('{tenant}/flows/{flow}', [FlowController::class, 'show']);
                Route::patch('{tenant}/flows/{flow}', [FlowController::class, 'update']);
                Route::put('{tenant}/flows/{flow}/draft', [FlowController::class, 'replaceDraft']);
                Route::get('{tenant}/flows/{flow}/validate', [FlowController::class, 'validate']);
                Route::get('{tenant}/flows/{flow}/variables', [FlowController::class, 'variables']);
                Route::post('{tenant}/flows/{flow}/publish', [FlowController::class, 'publish']);
                Route::post('{tenant}/flows/{flow}/deactivate', [FlowController::class, 'deactivate']);
                Route::delete('{tenant}/flows/{flow}', [FlowController::class, 'destroy']);

                Route::get('{tenant}/flows/{flow}/triggers', [TriggerController::class, 'index']);
                Route::post('{tenant}/flows/{flow}/triggers', [TriggerController::class, 'store']);
                Route::patch('{tenant}/flows/{flow}/triggers/{trigger}', [TriggerController::class, 'update']);
                Route::delete('{tenant}/flows/{flow}/triggers/{trigger}', [TriggerController::class, 'destroy']);

                // Ejecuciones de flujos (por tenant; filtros por flow/chatbot).
                Route::get('{tenant}/flow-executions', [FlowExecutionController::class, 'index']);
                Route::get('{tenant}/flow-executions/{execution}', [FlowExecutionController::class, 'show']);
                Route::post('{tenant}/flow-executions/{execution}/pause', [FlowExecutionController::class, 'pause']);
                Route::post('{tenant}/flow-executions/{execution}/resume', [FlowExecutionController::class, 'resume']);
                Route::post('{tenant}/flow-executions/{execution}/cancel', [FlowExecutionController::class, 'cancel']);

                // FASE 17 U2.1 — Knowledge Bases y documentos.
                Route::get('{tenant}/knowledge-bases', [KnowledgeBaseController::class, 'index']);
                Route::post('{tenant}/knowledge-bases', [KnowledgeBaseController::class, 'store']);
                Route::get('{tenant}/knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'show']);
                Route::patch('{tenant}/knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'update']);
                Route::delete('{tenant}/knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy']);

                Route::get('{tenant}/knowledge-bases/{knowledgeBase}/documents', [DocumentController::class, 'index']);
                Route::post('{tenant}/knowledge-bases/{knowledgeBase}/documents', [DocumentController::class, 'store']);
                Route::get('{tenant}/knowledge-bases/{knowledgeBase}/documents/{document}', [DocumentController::class, 'show']);
                Route::delete('{tenant}/knowledge-bases/{knowledgeBase}/documents/{document}', [DocumentController::class, 'destroy']);

                // FASE 18 U3 — FAQs del tenant.
                Route::get('{tenant}/faqs', [FaqController::class, 'index']);
                Route::post('{tenant}/faqs', [FaqController::class, 'store']);
                Route::get('{tenant}/faqs/{faq}', [FaqController::class, 'show']);
                Route::patch('{tenant}/faqs/{faq}', [FaqController::class, 'update']);
                Route::delete('{tenant}/faqs/{faq}', [FaqController::class, 'destroy']);

                // FASE 19 U2 — Leads del tenant.
                Route::get('{tenant}/leads', [LeadController::class, 'index']);
                Route::post('{tenant}/leads', [LeadController::class, 'store']);
                Route::get('{tenant}/leads/{lead}', [LeadController::class, 'show']);
                Route::patch('{tenant}/leads/{lead}', [LeadController::class, 'update']);
                Route::delete('{tenant}/leads/{lead}', [LeadController::class, 'destroy']);

                // FASE 20 U2 — Tags del tenant.
                Route::get('{tenant}/tags', [TagController::class, 'index']);
                Route::post('{tenant}/tags', [TagController::class, 'store']);
                Route::get('{tenant}/tags/{tag}', [TagController::class, 'show']);
                Route::patch('{tenant}/tags/{tag}', [TagController::class, 'update']);
                Route::delete('{tenant}/tags/{tag}', [TagController::class, 'destroy']);

                // FASE 20 U3 — Tag assignment a contactos.
                Route::post('{tenant}/contacts/{contact}/tags', [TagController::class, 'assignTags']);
                Route::delete('{tenant}/contacts/{contact}/tags/{tag}', [TagController::class, 'removeTag']);

                // FASE 21 U3 — Analytics del tenant.
                Route::get('{tenant}/analytics/overview', [AnalyticsController::class, 'overview']);

                // FASE 22 U3 — Notificaciones del usuario en el tenant.
                Route::get('{tenant}/notifications', [NotificationController::class, 'index']);
                Route::get('{tenant}/notifications/unread-count', [NotificationController::class, 'unreadCount']);
                Route::patch('{tenant}/notifications/{notification}/read', [NotificationController::class, 'markRead']);
                Route::post('{tenant}/notifications/read-all', [NotificationController::class, 'markAllRead']);

                // FASE 22 U4 — Preferencias de notificación.
                Route::get('{tenant}/notification-preferences', [NotificationPreferenceController::class, 'show']);
                Route::patch('{tenant}/notification-preferences', [NotificationPreferenceController::class, 'update']);

                // FASE 23 U3 — Billing: planes, suscripciones y uso.
                Route::get('{tenant}/plans', [PlanController::class, 'index']);
                Route::get('{tenant}/plans/{plan}', [PlanController::class, 'show']);

                Route::get('{tenant}/subscriptions', [SubscriptionController::class, 'index']);
                Route::post('{tenant}/subscriptions', [SubscriptionController::class, 'store']);
                Route::patch('{tenant}/subscriptions', [SubscriptionController::class, 'update']);
                Route::delete('{tenant}/subscriptions', [SubscriptionController::class, 'destroy']);

                Route::get('{tenant}/usage', [UsageController::class, 'index']);
                Route::get('{tenant}/usage/history', [UsageController::class, 'history']);

                // FASE 24 U2 — Checkout + Portal.
                Route::post('{tenant}/billing/checkout', [CheckoutController::class, 'store']);
                Route::post('{tenant}/billing/portal', [CheckoutController::class, 'portal']);
            });
        });
    });
});

<?php

declare(strict_types=1);

use App\Domain\AI\Exceptions\AIException;
use App\Domain\Billing\Exceptions\BillingProviderException;
use App\Domain\Billing\Exceptions\SubscriptionNotActiveException;
use App\Domain\Billing\Exceptions\SubscriptionNotFoundException;
use App\Domain\Billing\Exceptions\TenantQuotaExceededException;
use App\Domain\WhatsApp\Exceptions\WhatsAppException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestCorrelationId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TenantMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
            RequestCorrelationId::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->web(prepend: [
            RequestCorrelationId::class,
        ]);

        $middleware->alias([
            'tenant' => TenantMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'El recurso solicitado no existe.',
                    'code' => 'NOT_FOUND',
                    'errors' => new stdClass,
                ], 404);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'RATE_LIMITED',
                    'errors' => new stdClass,
                ], 429);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $code = match ($e->getStatusCode()) {
                    403 => 'FORBIDDEN',
                    419 => 'SESSION_EXPIRED',
                    default => 'HTTP_ERROR',
                };
                $message = match ($e->getStatusCode()) {
                    403 => 'No tienes permiso para realizar esta acción.',
                    419 => 'La sesión ha expirado.',
                    default => 'Error de solicitud.',
                };

                return response()->json([
                    'message' => $message,
                    'code' => $code,
                    'errors' => new stdClass,
                ], $e->getStatusCode());
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 'UNAUTHENTICATED',
                    'errors' => new stdClass,
                ], 401);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'RATE_LIMITED',
                    'errors' => new stdClass,
                ], 429);
            }
        });

        $exceptions->render(function (TenantQuotaExceededException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'TENANT_QUOTA_EXCEEDED',
                    'errors' => [
                        'category' => $e->category,
                        'limit' => $e->limit,
                        'used' => $e->used,
                    ],
                ], $e->getCode() ?: 429);
            }
        });

        $exceptions->render(function (SubscriptionNotActiveException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => SubscriptionNotActiveException::ERROR_CODE,
                    'errors' => new stdClass,
                ], SubscriptionNotActiveException::HTTP_STATUS);
            }
        });

        $exceptions->render(function (SubscriptionNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No hay una suscripción activa para este tenant.',
                    'code' => 'SUBSCRIPTION_NOT_FOUND',
                    'errors' => new stdClass,
                ], 409);
            }
        });

        $exceptions->render(function (WhatsAppException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode()->value,
                ], $e->status());
            }
        });

        $exceptions->render(function (AIException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode()->value,
                ], $e->status());
            }
        });

        $exceptions->render(function (BillingProviderException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'BILLING_PROVIDER_ERROR',
                    'errors' => new stdClass,
                ], 502);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                report($e);

                return response()->json([
                    'message' => 'Ocurrió un error interno del servidor.',
                    'code' => 'SERVER_ERROR',
                    'errors' => new stdClass,
                ], 500);
            }
        });
    })->create();

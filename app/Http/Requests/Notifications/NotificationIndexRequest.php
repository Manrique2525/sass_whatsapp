<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Listado de notificaciones del usuario autenticado (FASE 22 U3).
 */
final class NotificationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'read_status' => ['nullable', 'string', 'in:all,unread,read'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

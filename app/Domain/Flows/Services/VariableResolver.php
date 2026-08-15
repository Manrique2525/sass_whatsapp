<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;

/**
 * Resuelve variables `{{...}}` en textos y payloads del motor (FASE 11,
 * `docs/chatbot-engine.md` §5).
 *
 * Fuentes:
 * - `{{contact.name}}`, `{{contact.phone}}`, `{{contact.email}}` y
 *   `{{contact.<campo de metadata>}}`.
 * - `{{business.name}}`, `{{business.description}}`, `{{business.category}}`,
 *   `{{business.address}}`, `{{business.website}}`, `{{business.email}}`,
 *   `{{business.phone}}`.
 * - `{{conversation.id}}`.
 * - `{{custom.<campo>}}` (variables capturadas por nodos `question`, o bien
 *   `conversation.context.custom`).
 *
 * Reglas: los namespaces conocidos con campo inexistente se sustituyen por
 * cadena vacía; los namespaces desconocidos se conservan tal cual (defensivo,
 * nunca se exponen datos por error). Se eliminan caracteres de control del
 * resultado.
 */
final class VariableResolver
{
    public const PATTERN = '/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/i';

    /**
     * Sustituye TODAS las variables del texto.
     *
     * @param  array<string, mixed>  $custom  namespace `custom.*` (variables de ejecución)
     */
    public function resolve(
        string $text,
        Contact $contact,
        BusinessProfile $business,
        Conversation $conversation,
        array $custom,
    ): string {
        $result = (string) preg_replace_callback(self::PATTERN, function (array $matches) use ($contact, $business, $conversation, $custom): string {
            return $this->resolveKey(strtolower($matches[1]), $contact, $business, $conversation, $custom);
        }, $text);

        return $this->sanitize($result);
    }

    /**
     * @param  array<string, mixed>  $custom
     */
    private function resolveKey(
        string $key,
        Contact $contact,
        BusinessProfile $business,
        Conversation $conversation,
        array $custom,
    ): string {
        $parts = explode('.', $key);
        $namespace = $parts[0];
        $rest = implode('.', array_slice($parts, 1));

        return match ($namespace) {
            'contact' => $this->resolveContact($rest, $contact),
            'business' => $this->resolveBusiness($rest, $business),
            'conversation' => $rest === 'id' ? (string) $conversation->id : '',
            'custom' => array_key_exists($rest, $custom) ? (string) $custom[$rest] : '',
            default => '{{'.$key.'}}',
        };
    }

    private function resolveContact(string $key, Contact $contact): string
    {
        return match ($key) {
            'name' => (string) $contact->name,
            'phone' => (string) $contact->phone,
            'email' => (string) ($contact->email ?? ''),
            default => is_array($contact->metadata) && array_key_exists($key, $contact->metadata)
                ? (string) $contact->metadata[$key]
                : '',
        };
    }

    private function resolveBusiness(string $key, BusinessProfile $business): string
    {
        // FASE 13: whitelist única (`BusinessProfile::PUBLIC_FIELDS`). Un campo
        // que no esté en la whitelist se resuelve como vacío: nunca se exponen
        // datos no públicos (tokens, credenciales, etc.).
        if (! in_array($key, BusinessProfile::PUBLIC_FIELDS, true)) {
            return '';
        }

        $value = $business->getAttribute($key);

        return $value === null ? '' : (string) $value;
    }

    /**
     * Elimina caracteres de control (excepto \n\t\r) para no romper el canal.
     */
    private function sanitize(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    }
}

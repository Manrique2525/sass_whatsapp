<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Business\Models\BusinessProfile;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * Resuelve variables `{{...}}` en textos y payloads del motor (FASE 11,
 * `docs/chatbot-engine.md` §5; DSL extendido en FASE 13, UNIDAD 2).
 *
 * Fuentes (namespaces permitidos):
 * - `{{contact.name}}`, `{{contact.phone}}`, `{{contact.email}}` y
 *   `{{contact.<campo de metadata>}}`.
 * - `{{business.<campo>}}`: SOLO los campos de `BusinessProfile::PUBLIC_FIELDS`
 *   (whitelist única que también indexa `VariableCatalogService`; nunca expone
 *   secretos).
 * - `{{conversation.id}}` (solo lectura).
 * - `{{custom.<campo>}}`: variables capturadas por nodos `question` (tipadas
 *   según `question.config.type`).
 *
 * DSL (deliberadamente pequeño):
 * - `{{variable|default:'valor'}}`: usa `valor` cuando la variable no existe,
 *   es `null` o está vacía (`''` o `[]`). Comillas escapables con `\'`.
 * - Las claves se normalizan en minúsculas; los caracteres de control del
 *   resultado se eliminan; los tokens de namespace desconocido (o `node.*`) se
 *   conservan verbatim. NUNCA se usa eval() ni acceso dinámico a métodos.
 */
final class VariableResolver
{
    /** @var list<string> */
    private const ALLOWED_NAMESPACES = ['contact', 'business', 'conversation', 'custom'];

    /**
     * Token de interpolación: clave + filtro `|default:'...'` opcional.
     * El filtro SOLO acepta `default` con comillas simples y escapes `\'`/`\\`.
     * Se admiten espacios alrededor de la clave y de `|`.
     */
    private const TOKEN_PATTERN = '/\{\{\s*([a-z][a-z0-9_.]*)\s*(?:\|\s*default\s*:\s*\'((?:[^\'\\\\]|\\\\.)*)\')?\s*\}\}/i';

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
        $result = (string) preg_replace_callback(self::TOKEN_PATTERN, function (array $matches) use ($contact, $business, $conversation, $custom): string {
            $key = VariableGuard::normalizeKey($matches[1]);
            $default = isset($matches[2]) ? $this->unescapeDefault($matches[2]) : null;

            return $this->resolveKey($key, $default, $matches[0], $contact, $business, $conversation, $custom);
        }, $text);

        return $this->sanitize($result);
    }

    /**
     * Extrae las referencias/base de un texto SIN resolverlas: sin duplicados,
     * orden estable, solo namespaces permitidos y estructura de clave válida
     * (sin path traversal, sin `node.*`). El filtro `|default` se ignora.
     *
     * @return list<string>
     */
    public function extractReferences(string $text): array
    {
        $references = [];

        preg_match_all(self::TOKEN_PATTERN, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = VariableGuard::normalizeKey($match[1]);

            if ($this->isExtractableReference($key)) {
                $references[$key] = true;
            }
        }

        return array_keys($references);
    }

    /**
     * @param  array<string, mixed>  $custom
     */
    private function resolveKey(
        string $key,
        ?string $default,
        string $raw,
        Contact $contact,
        BusinessProfile $business,
        Conversation $conversation,
        array $custom,
    ): string {
        $parts = explode('.', $key);
        $namespace = $parts[0];
        $rest = implode('.', array_slice($parts, 1));

        // Namespaces permitidos (mismo conjunto que ALLOWED_NAMESPACES). La
        // comprobación textual evita que el analizador estático poda las ramas
        // del match a un literal y reporte falsos `match.alwaysTrue`.
        if (! str_contains('|contact|business|conversation|custom|', '|'.$namespace.'|')) {
            return $raw;
        }

        $value = match ($namespace) {
            'contact' => $this->resolveContactValue($rest, $contact),
            'business' => $this->resolveBusinessValue($rest, $business),
            'conversation' => $rest === 'id' ? (string) $conversation->id : null,
            'custom' => VariableGuard::isValidKey($rest) && array_key_exists($rest, $custom)
                ? $custom[$rest]
                : null,
            default => null,
        };

        if ($value === null || $value === '' || $value === []) {
            return $default ?? '';
        }

        return $this->toString($value);
    }

    private function resolveContactValue(string $key, Contact $contact): mixed
    {
        return match ($key) {
            'name' => $contact->name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            default => is_array($contact->metadata) && array_key_exists($key, $contact->metadata)
                ? $contact->metadata[$key]
                : null,
        };
    }

    private function resolveBusinessValue(string $key, BusinessProfile $business): mixed
    {
        // FASE 13: whitelist única (`BusinessProfile::PUBLIC_FIELDS`). Un campo
        // que no esté en la whitelist se resuelve como vacío: nunca se exponen
        // datos no públicos (tokens, credenciales, etc.).
        if (! in_array($key, BusinessProfile::PUBLIC_FIELDS, true)) {
            return null;
        }

        return $business->getAttribute($key);
    }

    /**
     * Representación textual estable por tipo (FASE 13, UNIDAD 2):
     * integer → decimal; decimal → representación decimal; boolean →
     * 'true'/'false'; date → Y-m-d; datetime → ISO 8601; array/object →
     * JSON determinístico; null → ''.
     */
    private function toString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * El filtro es válido SOLO si es una referencia de namespace permitido con
     * exactamente un segmento válido (namespace + clave): evita `node.*`, path
     * traversal y referencias que el resolver nunca podría resolver.
     */
    private function isExtractableReference(string $key): bool
    {
        $parts = explode('.', $key);

        if (count($parts) !== 2) {
            return false;
        }

        if (! in_array($parts[0], self::ALLOWED_NAMESPACES, true)) {
            return false;
        }

        return VariableGuard::isValidKey($parts[1]);
    }

    private function unescapeDefault(string $raw): string
    {
        return (string) preg_replace_callback('/\\\\([\\\\\'])/', static fn (array $m): string => $m[1], $raw);
    }

    /**
     * Elimina caracteres de control (excepto \n\t\r) para no romper el canal.
     */
    private function sanitize(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    }
}

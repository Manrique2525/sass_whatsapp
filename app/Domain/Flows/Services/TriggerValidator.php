<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Flows\Enums\FlowTriggerType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Validación de triggers (FASE 14, UNIDAD 1, ADR-038).
 *
 * El backend es la única autoridad: toda config de trigger se valida aquí,
 * nunca en el frontend. Reglas por tipo:
 *
 * - `keyword` / `new_message` / `start`: sin `config`; `keyword` exige una
 *   palabra clave no vacía.
 * - `tag`: `config.tags` (lista de etiquetas). Solo define el contrato; la
 *   ejecución por etiqueta llega en FASE 20. Jamás ejecuta aquí.
 * - `schedule`: `config.cron` (expresión cron determinista de 5 campos) +
 *   `config.conversation_id` (UUID). La pertenencia al tenant de la
 *   conversación la verifica el servicio (`FlowService`).
 * - `webhook`: `config.conversation_by` (`conversation_id` | `contact_id` |
 *   `phone`). El servicio añade `config.token_hash` (sha256) al crear; el
 *   token se genera con CSPRNG, se guarda hasheado y se devuelve una única
 *   vez. El cliente jamás envía secretos.
 *
 * Los mensajes de error son genéricos y no revelan datos de otros tenants.
 */
final class TriggerValidator
{
    public const MAX_CONFIG_SIZE = 4096;

    public const MAX_KEYWORD_LENGTH = 255;

    public const MAX_CRON_LENGTH = 255;

    public const MAX_TAG_COUNT = 10;

    public const MAX_TAG_LENGTH = 100;

    /** @var list<string> */
    public const WEBHOOK_CONVERSATION_BY = ['conversation_id', 'contact_id', 'phone'];

    /**
     * Valida un trigger.
     *
     * Con `clientProvided = true` la config viene del cliente: los secretos
     * (`token_hash`, `token`) están prohibidos. Con `false` se valida la
     * config final (el servicio ya compuso el `token_hash`).
     *
     * @param  array<string, mixed>|null  $config
     * @return list<string> errores (vacío = válido)
     */
    public function validate(FlowTriggerType $type, ?string $keyword, ?array $config, bool $clientProvided = false): array
    {
        $errors = [];

        if ($config !== null && strlen((string) json_encode($config)) > self::MAX_CONFIG_SIZE) {
            $errors[] = sprintf('La config del trigger excede el límite de %d caracteres.', self::MAX_CONFIG_SIZE);
        }

        switch ($type) {
            case FlowTriggerType::Keyword:
                if (! is_string($keyword) || trim($keyword) === '') {
                    $errors[] = 'El trigger keyword requiere una palabra clave no vacía.';
                } elseif (strlen($keyword) > self::MAX_KEYWORD_LENGTH) {
                    $errors[] = sprintf('La palabra clave excede el límite de %d caracteres.', self::MAX_KEYWORD_LENGTH);
                }

                if ($config !== null && $config !== []) {
                    $errors[] = 'El trigger keyword no admite config.';
                }
                break;

            case FlowTriggerType::NewMessage:
            case FlowTriggerType::Start:
                if ($config !== null && $config !== []) {
                    $errors[] = sprintf('El trigger %s no admite config.', $type->value);
                }
                break;

            case FlowTriggerType::Tag:
                $this->validateTagConfig($config, $errors);
                break;

            case FlowTriggerType::Schedule:
                $this->validateScheduleConfig($config, $errors);
                break;

            case FlowTriggerType::Webhook:
                $this->validateWebhookConfig($config, $errors, $clientProvided);
                break;
        }

        return $errors;
    }

    /**
     * Expresión cron determinista de 5 campos (minuto, hora, día de mes, mes,
     * día de semana). Soporta `*`, `?`, enteros, rangos `a-b`, listas `a,b` y
     * pasos tipo `asterisco/slash-n` o `a-b/n`. Nunca evalúa código. 0 y 7 en
     * día de semana se aceptan (7 es alias de domingo).
     */
    public static function isValidCron(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression));

        if ($fields === false || count($fields) !== 5) {
            return false;
        }

        $ranges = [
            [0, 59],
            [0, 23],
            [1, 31],
            [1, 12],
            [0, 7],
        ];

        foreach ($fields as $index => $field) {
            [$min, $max] = $ranges[$index];

            foreach (explode(',', $field) as $part) {
                $part = trim($part);

                if ($part === '') {
                    return false;
                }

                if (str_contains($part, '/')) {
                    [$base, $stepRaw] = explode('/', $part, 2);
                    $base = trim($base);
                    $stepRaw = trim($stepRaw);

                    if (! ctype_digit($stepRaw) || (int) $stepRaw < 1 || (int) $stepRaw > $max) {
                        return false;
                    }
                } else {
                    $base = $part;
                }

                if ($base === '*' || $base === '?') {
                    continue;
                }

                if (str_contains($base, '-')) {
                    [$startRaw, $endRaw] = explode('-', $base, 2);

                    if (! ctype_digit(trim($startRaw)) || ! ctype_digit(trim($endRaw))) {
                        return false;
                    }

                    $start = (int) $startRaw;
                    $end = (int) $endRaw;

                    if ($start < $min || $end > $max || $start > $end) {
                        return false;
                    }

                    continue;
                }

                if (! ctype_digit($base)) {
                    return false;
                }

                $value = (int) $base;

                if ($value < $min || $value > $max) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Evalúa si una expresión cron de 5 campos coincide con un instante dado.
     *
     * Determinista, sin eval/exec. Soporta `*`, `?`, enteros, rangos `a-b`,
     * listas `a,b` y pasos tipo asterisco/slash-n o `a-b/n`. Semántica DOM/DOW:
     * si ambos campos están restringidos, dispara si cualquiera de los dos
     * matchea (cron Vixie). 0 y 7 ambos significan domingo en DOW.
     */
    public static function matchesCron(string $expression, Carbon $time): bool
    {
        $fields = preg_split('/\s+/', trim($expression));

        if ($fields === false || count($fields) !== 5) {
            return false;
        }

        $ranges = [
            [0, 59],   // minuto
            [0, 23],   // hora
            [1, 31],   // día de mes
            [1, 12],   // mes
            [0, 7],    // día de semana (0 y 7 = domingo)
        ];

        $values = [
            (int) $time->minute,
            (int) $time->hour,
            (int) $time->day,
            (int) $time->month,
            (int) $time->dayOfWeek, // 0=Sunday ... 6=Saturday
        ];

        $matches = [];

        foreach ($fields as $index => $field) {
            [$min, $max] = $ranges[$index];
            $value = $values[$index];

            // Para DOW: ambos 0 y 7 significan domingo. Si el campo dice "7"
            // y el valor de Carbon es 0 (Sunday), o viceversa, debe matchear.
            // Solución: probar el valor raw y, si es DOW, también probar con
            // el equivalente (0↔7).
            $matched = self::cronFieldMatches($field, $value, $min, $max);

            if (! $matched && $index === 4) {
                $equiv = $value === 0 ? 7 : ($value === 7 ? 0 : -1);

                if ($equiv !== -1) {
                    $matched = self::cronFieldMatches($field, $equiv, $min, $max);
                }
            }

            $matches[] = $matched;
        }

        // Semántica DOM/DOW: si ambos campos están restringidos (no * ni ?),
        // dispara si CUALQUIERA de los dos matchea (cron Vixie clásico).
        $domRestricted = ! in_array(strtolower(trim($fields[2])), ['*', '?'], true);
        $dowRestricted = ! in_array(strtolower(trim($fields[4])), ['*', '?'], true);

        if ($domRestricted && $dowRestricted) {
            $dayMatch = $matches[2] || $matches[4];
        } else {
            $dayMatch = $matches[2] && $matches[4];
        }

        return $matches[0] && $matches[1] && $dayMatch && $matches[3];
    }

    /**
     * Evalúa si un valor dado coincide con un campo cron individual.
     *
     * Soporta: `*`, `?`, entero, rango `a-b`, lista `a,b,c` y
     * pasos tipo asterisco/slash-n o `a-b/n`.
     */
    private static function cronFieldMatches(string $field, int $value, int $min, int $max): bool
    {
        $field = strtolower(trim($field));

        if ($field === '*' || $field === '?') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            if ($part === '') {
                return false;
            }

            // Paso: */n o a-b/n o a/n
            if (str_contains($part, '/')) {
                [$base, $stepRaw] = explode('/', $part, 2);
                $step = (int) trim($stepRaw);

                if ($step < 1) {
                    return false;
                }

                $base = trim($base);

                if ($base === '*' || $base === '?') {
                    $start = $min;
                    $end = $max;
                } elseif (str_contains($base, '-')) {
                    [$rangeStart, $rangeEnd] = explode('-', $base, 2);
                    $start = (int) trim($rangeStart);
                    $end = (int) trim($rangeEnd);
                } else {
                    $start = (int) $base;
                    $end = $max;
                }

                if ($start < $min || $end > $max || $start > $end) {
                    return false;
                }

                if ($value >= $start && $value <= $end && ($value - $start) % $step === 0) {
                    return true;
                }

                continue;
            }

            // Rango: a-b
            if (str_contains($part, '-')) {
                [$rangeStart, $rangeEnd] = explode('-', $part, 2);
                $start = (int) trim($rangeStart);
                $end = (int) trim($rangeEnd);

                if ($start < $min || $end > $max || $start > $end) {
                    return false;
                }

                if ($value >= $start && $value <= $end) {
                    return true;
                }

                continue;
            }

            // Valor exacto
            if (ctype_digit($part)) {
                $v = (int) $part;

                if ($v < $min || $v > $max) {
                    return false;
                }

                if ($value === $v) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Token de webhook con CSPRNG. Se devuelve en texto plano una única vez
     * (respuesta de creación); solo su hash sha256 se persiste.
     */
    public static function generateWebhookToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashWebhookToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @param  list<string>  $errors
     */
    private function validateTagConfig(?array $config, array &$errors): void
    {
        if ($config === null || ! is_array($config['tags'] ?? null)) {
            $errors[] = 'El trigger tag requiere config.tags (lista de etiquetas).';

            return;
        }

        $tags = array_values($config['tags']);

        if (count($tags) < 1 || count($tags) > self::MAX_TAG_COUNT) {
            $errors[] = sprintf('config.tags debe tener entre 1 y %d etiquetas.', self::MAX_TAG_COUNT);
        }

        foreach ($tags as $tag) {
            if (! is_string($tag) || trim($tag) === '' || strlen($tag) > self::MAX_TAG_LENGTH) {
                $errors[] = sprintf('Cada etiqueta debe ser texto no vacío de a lo sumo %d caracteres.', self::MAX_TAG_LENGTH);
            }
        }

        $normalized = array_map(
            static fn ($tag): string => is_string($tag) ? trim($tag) : '',
            $tags,
        );

        if (count(array_unique($normalized)) !== count($tags)) {
            $errors[] = 'config.tags no puede repetir etiquetas.';
        }
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @param  list<string>  $errors
     */
    private function validateScheduleConfig(?array $config, array &$errors): void
    {
        $cron = is_array($config) ? ($config['cron'] ?? null) : null;

        if (! is_string($cron) || trim($cron) === '') {
            $errors[] = 'El trigger schedule requiere config.cron (expresión cron).';
        } else {
            if (strlen($cron) > self::MAX_CRON_LENGTH) {
                $errors[] = sprintf('config.cron excede el límite de %d caracteres.', self::MAX_CRON_LENGTH);
            }

            if (! self::isValidCron($cron)) {
                $errors[] = 'config.cron no es una expresión cron válida.';
            }
        }

        $conversationId = is_array($config) ? ($config['conversation_id'] ?? null) : null;

        if (! is_string($conversationId) || ! Str::isUuid($conversationId)) {
            $errors[] = 'El trigger schedule requiere config.conversation_id (UUID de una conversación del tenant).';
        }
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @param  list<string>  $errors
     */
    private function validateWebhookConfig(?array $config, array &$errors, bool $clientProvided): void
    {
        if ($config === null || $config === []) {
            $errors[] = 'El trigger webhook requiere config con conversation_by.';

            return;
        }

        $allowed = ['conversation_by', 'token_hash'];
        $extra = array_diff(array_keys($config), $allowed);

        if ($extra !== []) {
            $errors[] = sprintf('El trigger webhook no admite los campos: %s.', implode(', ', $extra));
        }

        if ($clientProvided && array_key_exists('token_hash', $config)) {
            $errors[] = 'El cliente no puede enviar token_hash: el servidor lo genera y guarda hasheado.';
        }

        $conversationBy = $config['conversation_by'] ?? null;

        if (! is_string($conversationBy) || ! in_array($conversationBy, self::WEBHOOK_CONVERSATION_BY, true)) {
            $errors[] = 'config.conversation_by debe ser conversation_id, contact_id o phone.';
        }

        if (! $clientProvided) {
            $tokenHash = $config['token_hash'] ?? null;

            if (! is_string($tokenHash) || preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
                $errors[] = 'El trigger webhook requiere un token_hash válido (sha256).';
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Flows\Enums;

use App\Domain\Flows\ValueObjects\VariableCoercion;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Tipos de variable del motor de flujos (FASE 13, UNIDAD 1).
 *
 * Se declaran en `question.config.type` (con `default` opcional en
 * `question.config.default`); el motor aplica la coerción al capturar la
 * respuesta del contacto. `coerce()` es determinista y NUNCA lanza
 * excepciones: devuelve `VariableCoercion` con `ok=false` cuando el valor no
 * puede convertirse al tipo declarado.
 *
 * Tipos:
 * - `string`: acepta cualquier valor; arrays/objetos se serializan a JSON.
 * - `integer`: solo enteros (strings numéricas enteras y floats enteros).
 * - `decimal`: cualquier valor numérico.
 * - `boolean`: mapeo controlado (`true`/`false`, `"1"`/`"0"`,
 *   `"true"`/`"false"`, `"sí"`/`"si"`/`"no"`, `"yes"`/`"no"`).
 * - `date`: fecha ISO `Y-m-d`.
 * - `datetime`: fecha-hora ISO 8601.
 * - `array`: arrays o strings JSON válidas (objeto → falla).
 * - `object`: objetos, arrays asociativos o strings JSON de objeto.
 * - `null`: solo `null` o cadena vacía.
 */
enum VariableType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Array = 'array';
    case Object = 'object';
    case Null = 'null';

    public function coerce(mixed $value): VariableCoercion
    {
        return match ($this) {
            self::String => new VariableCoercion(true, $this->coerceToString($value)),
            self::Integer => $this->coerceInteger($value),
            self::Decimal => $this->coerceDecimal($value),
            self::Boolean => $this->coerceBoolean($value),
            self::Date => $this->coerceDate($value),
            self::DateTime => $this->coerceDateTime($value),
            self::Array => $this->coerceArray($value),
            self::Object => $this->coerceObject($value),
            self::Null => $value === null || $value === ''
                ? new VariableCoercion(true, null)
                : new VariableCoercion(false, $value),
        };
    }

    private function coerceToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function coerceInteger(mixed $value): VariableCoercion
    {
        if (is_int($value)) {
            return new VariableCoercion(true, $value);
        }

        if (is_float($value) && floor($value) === $value) {
            return new VariableCoercion(true, (int) $value);
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return new VariableCoercion(true, (int) $value);
        }

        return new VariableCoercion(false, $value);
    }

    private function coerceDecimal(mixed $value): VariableCoercion
    {
        if (is_int($value) || is_float($value)) {
            return new VariableCoercion(true, (float) $value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return new VariableCoercion(true, (float) $value);
        }

        return new VariableCoercion(false, $value);
    }

    private function coerceBoolean(mixed $value): VariableCoercion
    {
        if (is_bool($value)) {
            return new VariableCoercion(true, $value);
        }

        if (is_int($value) || is_float($value)) {
            return new VariableCoercion(true, $value === 1 || $value === 1.0);
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1', 'sí', 'si', 'yes' => new VariableCoercion(true, true),
                'false', '0', 'no', 'not' => new VariableCoercion(true, false),
                default => new VariableCoercion(false, $value),
            };
        }

        return new VariableCoercion(false, $value);
    }

    private function coerceDate(mixed $value): VariableCoercion
    {
        $date = $this->parseDate($value);

        return $date === null
            ? new VariableCoercion(false, $value)
            : new VariableCoercion(true, $date->format('Y-m-d'));
    }

    private function coerceDateTime(mixed $value): VariableCoercion
    {
        $date = $this->parseDate($value);

        return $date === null
            ? new VariableCoercion(false, $value)
            : new VariableCoercion(true, $date->toIso8601String());
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function coerceArray(mixed $value): VariableCoercion
    {
        if (is_array($value)) {
            return new VariableCoercion(true, $value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return new VariableCoercion(true, $decoded);
            }
        }

        return new VariableCoercion(false, $value);
    }

    private function coerceObject(mixed $value): VariableCoercion
    {
        if (is_object($value)) {
            return new VariableCoercion(true, $value);
        }

        if (is_array($value)) {
            return new VariableCoercion(true, (object) $value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value);

            if (is_object($decoded)) {
                return new VariableCoercion(true, $decoded);
            }
        }

        return new VariableCoercion(false, $value);
    }
}

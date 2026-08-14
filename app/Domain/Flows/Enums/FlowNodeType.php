<?php

declare(strict_types=1);

namespace App\Domain\Flows\Enums;

/**
 * Tipos de nodo del motor de flujos (FASE 11, `docs/chatbot-engine.md` §3).
 *
 * Cada tipo se implementa como un NodeExecutor dedicado; el `FlowValidator`
 * valida la `config` de cada nodo ANTES de publicar el flujo.
 *
 * El nodo `ai` NO se implementa en FASE 11: queda bloqueado en `FlowValidator`
 * y reservado para FASE 16 (IA generativa). Nunca debe existir un ejecutor
 * vacío o falso para `ai`.
 */
enum FlowNodeType: string
{
    case Message = 'message';
    case Buttons = 'buttons';
    case Question = 'question';
    case Condition = 'condition';
    case Delay = 'delay';
    case Tag = 'tag';
    case Webhook = 'webhook';
    case AI = 'ai';
    case Human = 'human';
    case End = 'end';

    /**
     * Nodos que quedan en `waiting` (esperando el siguiente mensaje del
     * cliente o una tarea asíncrona) tras su ejecución.
     */
    public function isWaitingType(): bool
    {
        return in_array($this, [self::Question, self::Buttons, self::AI, self::Human], true);
    }

    /**
     * Nodo `ai` reservado para FASE 16. `FlowValidator` lo rechaza con un
     * mensaje explícito hasta que la fase exista.
     */
    public function requiresAI(): bool
    {
        return $this === self::AI;
    }

    public function label(): string
    {
        return match ($this) {
            self::Message => 'Mensaje',
            self::Buttons => 'Botones',
            self::Question => 'Pregunta',
            self::Condition => 'Condición',
            self::Delay => 'Espera',
            self::Tag => 'Etiquetar',
            self::Webhook => 'Webhook',
            self::AI => 'IA',
            self::Human => 'Transferir a humano',
            self::End => 'Fin',
        };
    }
}

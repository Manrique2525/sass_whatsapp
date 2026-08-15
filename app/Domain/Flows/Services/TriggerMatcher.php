<?php

declare(strict_types=1);

namespace App\Domain\Flows\Services;

use App\Domain\Flows\Enums\FlowTriggerType;
use App\Domain\Flows\Models\Trigger;

/**
 * Selecciona el flujo a arrancar a partir de un mensaje entrante (FASE 11,
 * `docs/chatbot-engine.md` §6).
 *
 * Precedencia: triggers específicos (`keyword`) antes que genéricos
 * (`new_message`/`start`). Entre los genéricos, `new_message` se evalúa antes
 * que `start`. `tag`/`schedule`/`webhook` (FASE 14) jamás matchean un mensaje
 * entrante: sus puntos de entrada son el etiquetado (FASE 20), el scheduler
 * (U2) y el webhook público (U3).
 *
 * - `keyword`: solo dispara si el mensaje es el PRIMERO de la conversación y
 *   contiene la palabra clave (case-insensitive).
 * - `new_message`: dispara con cualquier mensaje entrante.
 * - `start`: solo dispara en el primer mensaje de la conversación.
 */
final class TriggerMatcher
{
    /**
     * @param  list<Trigger>  $triggers  triggers de flujos publicados y activos
     */
    public function match(array $triggers, string $messageBody, bool $isFirstMessage): ?Trigger
    {
        $triggers = array_values(array_filter(
            $triggers,
            static fn (Trigger $trigger): bool => $trigger->active,
        ));

        usort($triggers, function (Trigger $a, Trigger $b): int {
            $order = $this->typeOrder($a->type) <=> $this->typeOrder($b->type);

            return $order !== 0 ? $order : ($b->priority <=> $a->priority);
        });

        foreach ($triggers as $trigger) {
            switch ($trigger->type) {
                case FlowTriggerType::Keyword:
                    if ($isFirstMessage && $this->matchesKeyword($trigger, $messageBody)) {
                        return $trigger;
                    }
                    break;
                case FlowTriggerType::NewMessage:
                    return $trigger;
                case FlowTriggerType::Start:
                    if ($isFirstMessage) {
                        return $trigger;
                    }
                    break;
                default:
                    break;
            }
        }

        return null;
    }

    private function matchesKeyword(Trigger $trigger, string $messageBody): bool
    {
        $keyword = strtolower(trim((string) $trigger->keyword));

        return $keyword !== '' && str_contains(strtolower($messageBody), $keyword);
    }

    private function typeOrder(FlowTriggerType $type): int
    {
        return match ($type) {
            FlowTriggerType::Keyword => 1,
            FlowTriggerType::Tag => 2,
            FlowTriggerType::Schedule => 3,
            FlowTriggerType::Webhook => 4,
            FlowTriggerType::NewMessage => 5,
            FlowTriggerType::Start => 6,
        };
    }
}

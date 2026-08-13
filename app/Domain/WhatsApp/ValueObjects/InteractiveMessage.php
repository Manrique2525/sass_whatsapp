<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\ValueObjects;

/**
 * Mensaje interactivo de WhatsApp (FASE 6).
 *
 * Transporta el objeto `interactive` de Meta (type + body/header/footer +
 * action). El `action` se construye con el esquema oficial de Meta
 * (buttons/reply o button/sections/rows, según el type). Los builders ricos
 * (listas/quick replies específicos del chatbot) se añaden en fases de flujos.
 */
final readonly class InteractiveMessage
{
    /**
     * @param  array<string, mixed>  $action  objeto `action` del esquema de Meta
     */
    public function __construct(
        public string $type,
        public string $bodyText,
        public ?string $headerText = null,
        public ?string $footerText = null,
        public array $action = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $interactive = [
            'type' => $this->type,
            'body' => ['text' => $this->bodyText],
        ];

        if ($this->headerText !== null) {
            $interactive['header'] = ['type' => 'text', 'text' => $this->headerText];
        }

        if ($this->footerText !== null) {
            $interactive['footer'] = ['text' => $this->footerText];
        }

        if ($this->action !== []) {
            $interactive['action'] = $this->action;
        }

        return $interactive;
    }
}

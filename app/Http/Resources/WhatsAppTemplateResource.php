<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\WhatsApp\Models\WhatsAppTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recurso p��blico de un template del cat��logo.
 *
 * Expone el schema normalizado de componentes (HEADER/BODY/FOOTER/BUTTONS),
 * nunca estructura ejecutable ni detalles internos de infraestructura.
 */
final class WhatsAppTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsAppTemplate $template */
        $template = $this->resource;

        return [
            'id' => $template->id,
            'name' => $template->name,
            'language' => $template->language,
            'category' => $template->category,
            'status' => $template->status->value,
            'can_send' => $template->canSend(),
            'components' => $template->components ?? [],
            'last_synced_at' => $template->last_synced_at?->toIso8601String(),
        ];
    }
}

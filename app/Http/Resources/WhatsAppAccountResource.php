<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\WhatsApp\Models\WhatsAppAccount;
use App\Domain\WhatsApp\Models\WhatsAppPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recurso público de la cuenta de WhatsApp.
 *
 * NUNCA expone `access_token` (además está en `$hidden` del modelo): los
 * secretos no se devuelven por API.
 */
final class WhatsAppAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsAppAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'whatsapp_business_account_id' => $account->whatsapp_business_account_id,
            'display_name' => $account->display_name,
            'status' => $account->status->value,
            'phone_numbers' => $account->relationLoaded('phoneNumbers')
                ? $account->phoneNumbers->map(static fn (WhatsAppPhoneNumber $phone): array => [
                    'id' => $phone->id,
                    'phone_id' => $phone->phone_id,
                    'display_phone_number' => $phone->display_phone_number,
                    'verified_name' => $phone->verified_name,
                    'quality_rating' => $phone->quality_rating,
                    'status' => $phone->status->value,
                    'is_default' => $phone->is_default,
                ])->values()->all()
                : [],
        ];
    }
}

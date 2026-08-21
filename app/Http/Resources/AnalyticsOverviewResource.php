<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Analytics\ValueObjects\AnalyticsOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa AnalyticsOverview a JSON (FASE 21 U3).
 *
 * NO incluye tenant_id, conversation_id, contact PII,
 * message body, AI prompts/responses, audit payloads, API keys.
 */
/** @mixin AnalyticsOverview */
final class AnalyticsOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AnalyticsOverview $overview */
        $overview = $this->resource;

        return $overview->toArray();
    }
}

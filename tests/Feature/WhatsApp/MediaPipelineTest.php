<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Application\Messages\Services\MessageService;
use App\Domain\Messages\Enums\MessageDirection;
use App\Domain\Messages\Enums\MessageMediaProcessingStatus;
use App\Domain\Messages\Enums\MessageStatus;
use App\Domain\Messages\Enums\MessageType;
use App\Domain\Messages\Models\Message;
use App\Domain\Messages\Models\MessageMedia;
use App\Domain\Tenants\Models\Tenant;
use App\Domain\Users\Models\User;
use App\Domain\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Domain\WhatsApp\ValueObjects\MediaMetadata;
use App\Infrastructure\Tenancy\TenantContext;
use App\Jobs\ProcessWhatsAppMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMediaTemplateProvider;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

function inbound_media_payload(string $id, string $mediaId = 'media-1', string $mime = 'image/png', int $size = 1234): array
{
    return [
        'id' => $id,
        'from' => '15550000001',
        'timestamp' => '1725000000',
        'type' => 'image',
        'image' => [
            'id' => $mediaId,
            'mime_type' => $mime,
            'caption' => 'Foto',
            'filename' => 'foto.jpg',
            'size' => $size,
        ],
    ];
}

/**
 * Prepara un media `downloaded` con contenido ya almacenado para probar el
 * endpoint de descarga.
 */
function media_download_report(Tenant $tenant, string $bytes = 'fake-file-bytes'): MessageMedia
{
    $contact = make_contact($tenant);

    $conversation = make_conversation($tenant, $contact, ['status' => 'open']);

    return TenantContext::withId($tenant->id, function () use ($tenant, $conversation, $bytes): MessageMedia {
        $message = Message::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound->value,
            'type' => MessageType::Image->value,
            'status' => MessageStatus::Delivered->value,
        ]);

        $media = new MessageMedia([
            'message_id' => $message->id,
            'provider_media_id' => 'media-1',
            'processing_status' => MessageMediaProcessingStatus::Downloaded,
            'storage_disk' => 'local',
            'storage_path' => 'tenant/'.$tenant->id.'/whatsapp/media/'.$message->id,
        ]);
        $media->forceFill(['tenant_id' => $tenant->id]);
        $media->save();

        Storage::disk('local')->put('tenant/'.$tenant->id.'/whatsapp/media/'.$message->id, $bytes);

        return $media;
    });
}

test('MEDIA-1: el webhook crea el asset pending y encola la descarga', function (): void {
    Queue::fake([ProcessWhatsAppMedia::class]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    ensure_test_usage_entitlement($tenant);

    app(MessageService::class)->handleInboundMessage($tenant, inbound_media_payload('wamid-media-1'));

    $message = Message::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();
    $media = MessageMedia::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($message->type)->toBe(MessageType::Image)
        ->and($media->message_id)->toBe($message->id)
        ->and($media->provider_media_id)->toBe('media-1')
        ->and($media->processing_status)->toBe(MessageMediaProcessingStatus::Pending);

    Queue::assertPushed(ProcessWhatsAppMedia::class, fn (ProcessWhatsAppMedia $job): bool => $job->messageMediaId === $media->id);
});

test('MEDIA-2: descarga segura guarda el archivo, valida mime y marca downloaded', function (): void {
    $fake = new FakeMediaTemplateProvider;
    $fake->setDownload(new MediaMetadata('media-1', 'image/png', null, 100, null, 'foto.png'), FakeMediaTemplateProvider::VALID_PNG, 'image/png');
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    ensure_test_usage_entitlement($tenant);

    app(MessageService::class)->handleInboundMessage($tenant, inbound_media_payload('wamid-media-2'));

    $media = MessageMedia::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($fake->downloadCalls())->toBe(1)
        ->and($media->processing_status)->toBe(MessageMediaProcessingStatus::Downloaded)
        ->and($media->mime)->toBe('image/png')
        ->and($media->size)->toBe(strlen(FakeMediaTemplateProvider::VALID_PNG))
        ->and($media->sha256)->toBe(hash('sha256', FakeMediaTemplateProvider::VALID_PNG))
        ->and($media->original_filename)->toBe('foto.jpg')
        ->and($media->downloaded_at)->not->toBeNull();

    $disk = Storage::disk('local');
    $path = $media->storage_path;

    expect($path)->toBe('tenant/'.$tenant->id.'/whatsapp/media/'.$media->id)
        ->and($disk->exists($path))->toBeTrue();
});

test('MEDIA-3: tipo no permitido marca failed invalid_mime', function (): void {
    $fake = new FakeMediaTemplateProvider;
    $fake->setDownload(new MediaMetadata('media-1', 'image/png', null, 100, null, null), 'contenido-no-imagen', 'text/plain');
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    ensure_test_usage_entitlement($tenant);

    app(MessageService::class)->handleInboundMessage($tenant, inbound_media_payload('wamid-media-3'));

    $media = MessageMedia::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($media->processing_status)->toBe(MessageMediaProcessingStatus::Failed)
        ->and($media->failure_reason)->toBe('invalid_mime');
});

test('MEDIA-4: tamaño declarado mayor al tope marca failed oversize', function (): void {
    $fake = new FakeMediaTemplateProvider;
    $fake->setDownload(new MediaMetadata('media-1', 'image/png', null, 999999999, null, null));
    app()->instance(WhatsAppProviderInterface::class, $fake);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);
    ensure_test_usage_entitlement($tenant);

    app(MessageService::class)->handleInboundMessage($tenant, inbound_media_payload('wamid-media-4'));

    $media = MessageMedia::query()->withoutTenantScope()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($media->processing_status)->toBe(MessageMediaProcessingStatus::Failed)
        ->and($media->failure_reason)->toBe('oversize');
});

test('MEDIA-5: el endpoint descarga el archivo del media del propio tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenant, 'owner');

    $media = media_download_report($tenant, 'payload-bytes');

    $this->actingAs($user)
        ->get("/api/v1/tenants/{$tenant->id}/whatsapp/media/{$media->id}/download")
        ->assertOk()
        ->assertDownload('media-'.$media->id);
});

test('MEDIA-6: CRITICO — download de media de OTRO tenant responde 404', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->create();
    make_tenant_member($user, $tenantB, 'owner');

    $mediaOwnerA = media_download_report($tenantA, 'secreto-de-A');

    $this->actingAs($user)
        ->get("/api/v1/tenants/{$tenantB->id}/whatsapp/media/{$mediaOwnerA->id}/download")
        ->assertNotFound();

    $this->assertDatabaseHas('message_media', [
        'id' => $mediaOwnerA->id,
        'tenant_id' => $tenantA->id,
    ]);
});

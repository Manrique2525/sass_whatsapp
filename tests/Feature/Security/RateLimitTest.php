<?php

declare(strict_types=1);

use App\Domain\Tenants\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| F26-U1: WhatsApp Webhook Rate Limit Tests
|--------------------------------------------------------------------------
|
| Limit: 120/min per IP.
| Throttle applies BEFORE signature verification (protects CPU).
|
*/

test('F26-U1-WA-RL-01: requests under limit succeed', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    for ($i = 0; $i < 5; $i++) {
        $body = whatsapp_webhook_payload("msg-rl-01-{$i}", 'phone-1');
        post_whatsapp_webhook($body)->assertOk();
    }
});

test('F26-U1-WA-RL-02: limit boundary — request at 121st is rejected', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    for ($i = 0; $i < 121; $i++) {
        $body = whatsapp_webhook_payload("msg-rl-02-{$i}", 'phone-1');
        $response = post_whatsapp_webhook($body);

        if ($i >= 120) {
            $response->assertStatus(429)
                ->assertJson([
                    'code' => 'RATE_LIMITED',
                ]);

            return;
        }
    }

    $this->fail('Rate limit should have been triggered by request 121.');
});

test('F26-U1-WA-RL-03: over limit returns 429 with safe error shape', function (): void {
    for ($i = 0; $i < 122; $i++) {
        $body = whatsapp_webhook_payload("msg-rl-03-{$i}", 'phone-1');

        $response = test()->call(
            'POST',
            WEBHOOK_URL,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
            ],
            $body,
        );

        if ($i >= 120) {
            $response->assertStatus(429)
                ->assertJsonStructure(['message', 'code'])
                ->assertJsonPath('code', 'RATE_LIMITED');

            return;
        }
    }

    $this->fail('Rate limit should have been triggered.');
});

test('F26-U1-WA-RL-04: invalid signature still cannot bypass limiter protections', function (): void {
    for ($i = 0; $i < 121; $i++) {
        $body = '{"object":"whatsapp_business_account","entry":[]}';

        $response = test()->call(
            'POST',
            WEBHOOK_URL,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=totally-invalid-sig-'.($i),
            ],
            $body,
        );

        if ($i >= 120) {
            $response->assertStatus(429);
            $response->assertJsonPath('code', 'RATE_LIMITED');

            return;
        }
    }

    $this->fail('Rate limit should have been triggered regardless of signature.');
});

test('F26-U1-WA-RL-05: verify endpoint also rate limited', function (): void {
    for ($i = 0; $i < 121; $i++) {
        $response = $this->get(WEBHOOK_URL.'?hub.mode=subscribe&hub.verify_token=mi-verify-token&hub.challenge='.$i);

        if ($i >= 120) {
            $response->assertStatus(429);

            return;
        }
    }

    $this->fail('Rate limit should have been triggered on verify endpoint.');
});

test('F26-U1-WA-RL-06: rate limit response does not leak internal config', function (): void {
    for ($i = 0; $i < 121; $i++) {
        $body = whatsapp_webhook_payload("msg-rl-06-{$i}", 'phone-1');

        $response = test()->call(
            'POST',
            WEBHOOK_URL,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => whatsapp_signature($body),
            ],
            $body,
        );

        if ($i >= 120) {
            $response->assertStatus(429)
                ->assertJsonPath('code', 'RATE_LIMITED');

            $content = $response->json();
            expect($content)->not->toHaveKey('retry_after');
            expect($content)->not->toHaveKey('limit');
            expect($content)->not->toHaveKey('remaining');

            return;
        }
    }

    $this->fail('Rate limit should have been triggered.');
});

/*
|--------------------------------------------------------------------------
| F26-U1: Invitation Rate Limit Tests
|--------------------------------------------------------------------------
|
| Limit: 30/min per IP.
| Applies to both API (GET /api/v1/invitations/{token}) and
| web (GET /invitations/{token}).
|
| Rate limiter middleware runs BEFORE controller logic, so invalid
| tokens are still rate-limited. We test with both valid and invalid
| tokens to verify throttling works regardless.
|
*/

test('F26-U1-INV-RL-01: under limit — any request to invitation endpoint succeeds', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->getJson('/api/v1/invitations/some-token-'.str_repeat('a', 60).$i)
            ->assertStatus(404);
    }
});

test('F26-U1-INV-RL-02: limit boundary — request at 31st is rejected', function (): void {
    for ($i = 0; $i < 31; $i++) {
        $response = $this->getJson('/api/v1/invitations/token-rl-02-'.str_repeat('b', 60).$i);

        if ($i >= 30) {
            $response->assertStatus(429)
                ->assertJsonPath('code', 'RATE_LIMITED');

            return;
        }
    }

    $this->fail('Rate limit should have been triggered by request 31.');
});

test('F26-U1-INV-RL-03: over limit returns 429', function (): void {
    for ($i = 0; $i < 35; $i++) {
        $response = $this->getJson('/api/v1/invitations/token-rl-03-'.str_repeat('c', 60).$i);

        if ($i >= 30) {
            $response->assertStatus(429)
                ->assertJsonPath('code', 'RATE_LIMITED');
        }
    }
});

test('F26-U1-INV-RL-04: invalid tokens cannot brute-force unlimited', function (): void {
    for ($i = 0; $i < 35; $i++) {
        $response = $this->getJson('/api/v1/invitations/invalid-token-'.str_repeat('a', 60).$i);

        if ($i >= 30) {
            $response->assertStatus(429)
                ->assertJsonPath('code', 'RATE_LIMITED');

            return;
        }
    }

    $this->fail('Rate limit should have been triggered to prevent brute force.');
});

test('F26-U1-INV-RL-05: web invitation route also rate limited', function (): void {
    for ($i = 0; $i < 31; $i++) {
        $response = $this->get('/invitations/token-rl-05-'.str_repeat('d', 60).$i);

        if ($i >= 30) {
            $response->assertStatus(429);

            return;
        }
    }

    $this->fail('Rate limit should have been triggered on web invitation route.');
});

test('F26-U1-INV-RL-06: different limiter buckets — WhatsApp and invitation are independent', function (): void {
    config(['whatsapp.app_secret' => whatsapp_secret()]);

    $tenant = Tenant::factory()->create();
    make_whatsapp_setup($tenant);

    // Exhaust WhatsApp limit
    for ($i = 0; $i < 121; $i++) {
        $body = whatsapp_webhook_payload("msg-ind-{$i}", 'phone-1');
        post_whatsapp_webhook($body);
    }

    // WhatsApp should be limited
    $body = whatsapp_webhook_payload('msg-ind-blocked', 'phone-1');
    post_whatsapp_webhook($body)->assertStatus(429);

    // Invitation should NOT be affected — different limiter bucket
    $this->getJson('/api/v1/invitations/token-independent-'.str_repeat('e', 60))
        ->assertStatus(404);
});

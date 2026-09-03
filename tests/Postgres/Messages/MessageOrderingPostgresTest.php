<?php

declare(strict_types=1);

use App\Domain\Contacts\Models\Contact;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Messages\Models\Message;
use App\Infrastructure\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Postgres\PgvectorTestCase;

/*
|--------------------------------------------------------------------------
| PostgreSQL Message Ordering Test (canonical PG-only)
|--------------------------------------------------------------------------
|
| MESSAGE-ORDER-PG-01 — Verifica el contrato de orden determinista
| `ORDER BY created_at, id` contra PostgreSQL real: cuando `created_at` hace
| tie, `id` (UUIDv7) desempata con un orden total estable, y el resultado es
| reproducible re-ejecutando la misma query.
|
| Requiere PostgreSQL real. Extiende PgvectorTestCase (se salta limpiamente
| cuando database.default !== 'pgsql') y NO es recolectado por la suite sqlite
| por defecto (phpunit.xml solo incluye Unit/Feature).
|
| Ejecutar:
|   HANDOFF_U2_PG_TEST=1 vendor/bin/pest --configuration=phpunit.pgsql.xml \
|     --testsuite=PostgresConcurrency --do-not-cache-result
*/
class MessageOrderingPostgresTest extends PgvectorTestCase
{
    private string $tenantId;

    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required for MESSAGE-ORDER-PG tests');
        }
    }

    /** @test MESSAGE-ORDER-PG-01 */
    public function it_orders_messages_with_identical_created_at_by_id_deterministically(): void
    {
        $this->artisan('migrate:fresh');

        $this->tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Message Ordering',
            'slug' => 'msg-order-'.Str::random(8),
            'status' => 'active',
            'timezone' => 'UTC',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::setId($this->tenantId);

        $createdAt = Carbon::yesterday()->startOfDay();
        $this->createTiedConversation(10, $createdAt);

        $this->assertDatabaseCount('messages', 10);

        // Sanidad: los 10 created_at son un ÚNICO valor (tie real).
        $distinct = [];
        foreach (DB::table('messages')
            ->where('conversation_id', $this->conversationId)
            ->get('created_at') as $row) {
            $distinct[] = $row->created_at;
        }
        expect(array_unique($distinct))->toHaveCount(1);

        // Orden total determinista: con created_at tie, el id manda.
        $asc = $this->orderedIds('ASC');
        $ascByIdOnly = $this->orderedIds('ASC', byIdOnly: true);

        expect($asc)->toEqual($ascByIdOnly);

        // ASC y DESC son exactamente inversos -> hay un orden total estable.
        $desc = $this->orderedIds('DESC');

        expect($this->reverse($desc))->toEqual($asc);

        // Reproducible: re-ejecutar devuelve exactamente el mismo DESC.
        expect($this->orderedIds('DESC'))->toEqual($desc);
    }

    private function createTiedConversation(int $count, Carbon $createdAt): Conversation
    {
        $contact = Contact::query()->create([
            'name' => 'Alice PG',
            'phone' => '+52993100000'.$count,
        ]);

        $conversation = Conversation::query()->create([
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $this->conversationId = $conversation->id;

        for ($i = 0; $i < $count; $i++) {
            $message = new Message([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => 'text',
                'status' => 'delivered',
                'body' => 'message '.$i,
            ]);
            $message->created_at = $createdAt;
            $message->save();
        }

        return $conversation;
    }

    private function orderedIds(string $order, bool $byIdOnly = false): array
    {
        $query = DB::table('messages')->where('conversation_id', $this->conversationId);

        if ($byIdOnly) {
            $query = $query->orderByRaw('id '.$order);
        } else {
            $query = $query->orderByRaw('created_at '.$order.', id '.$order);
        }

        $ids = [];

        foreach ($query->get('id') as $row) {
            $ids[] = $row->id;
        }

        return $ids;
    }

    private function reverse(array $values): array
    {
        $result = [];

        for ($i = count($values) - 1; $i >= 0; $i--) {
            $result[] = $values[$i];
        }

        return $result;
    }
}

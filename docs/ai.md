# IA

## 1. Principio

Todo acceso a IA pasa por una interfaz. El dominio nunca depende de OpenAI directamente.

```php
interface AIProviderInterface
{
    public function generateResponse(AIRequest $request): AIResponse;
}
```

**Implementado (FASE 16 U1)**: `AIProviderInterface` en `app/Domain/AI/Contracts/`.
`OpenAIProvider` en `app/Infrastructure/AI/` (Laravel HTTP Client, base configurable).
Swappable (Anthropic, Azure OpenAI, proveedor local) registrando otra implementación en el
contenedor.

### Value Objects

- `AIRequest` (`app/Domain/AI/ValueObjects/AIRequest.php`): prompt, systemPrompt (nullable),
  model (override), temperature, maxTokens.
- `AIResponse` (`app/Domain/AI/ValueObjects/AIResponse.php`): content, provider, model,
  inputTokens, outputTokens, totalTokens.

### Excepciones de dominio

- `AIException` (abstracta) con `AIErrorCode` enum.
- `AIAuthFailedException` (401, no retryable)
- `AIRateLimitException` (429, retryable)
- `AIInvalidRequestException` (400, no retryable)
- `AIProviderException` (5xx, retryable configurable)

### Métodos pendientes (futuras fases)

- `classifyIntent(string $text, array $context = []): IntentResult`
- `summarizeConversation(array $messages): string`
- `extractLeadData(string $text): array`
- `createEmbedding(string $text): array`

Se implementarán cuando se necesiten (YAGNI).

## 2. Modelos y costos

- Chat: `gpt-4o-mini` por defecto (costo/calidad). Configurable por tenant si el plan lo permite.
- Embeddings: `text-embedding-3-small` (1536 dims, coincide con `knowledge_chunks.embedding`).
- Todos los parámetros (modelo, temperature, max_tokens, timeout) en config/env por tenant.

## 3. Límites y control de costos

- **`UsageGuard`**: antes de cada llamada IA se valida cuota de `ai_tokens` del plan.
  Excedida → `TENANT_QUOTA_EXCEEDED`, el flujo usa fallback (mensaje estático) sin llamar al API.
- **Tokens**: se contabilizan (prompt+completion) en `usage_records` desde la respuesta.
- **Rate limiting**: límite por tenant/hora; cola de trabajo de IA con backoff.
- **Timeout corto** (p. ej. 15s) con reintento único; luego fallback.

## 4. Fallback (OpenAI caído)

- 2 reintentos con backoff exponencial.
- Si falla: `generateResponse` devuelve respuesta estática del tenant (FAQ/fallback configurado)
  y loguea el fallo. La conversación sigue viva; nunca se pierde el mensaje del cliente.
- `classifyIntent` falla → se asume intent `fallback` (usa keyword matching como respaldo).

## 5. RAG — Base de conocimiento

Flujo de pregunta:

```
Pregunta del cliente
  → embed(pregunta)
  → SELECT ... ORDER BY embedding <=> $vec LIMIT k   (filtro tenant_id SIEMPRE)
  → context = chunks relevantes
  → generateResponse(system + context + pregunta)
  → respuesta al cliente
```

- `knowledge_chunks`: `content`, `embedding vector(1536)`, índice HNSW.
- **Aislamiento**: el buscador recibe `tenant_id` como parámetro obligatorio y filtra en SQL.
  Tests explícitos de que Tenant A jamás obtiene chunks de Tenant B.
- Chunking: por párrafos/párrafos+frases con solape, `token_count` guardado, máx por documento.
- Umbral de similitud mínimo (p. ej. 0.4) → si no hay contexto relevante, el bot responde
  "no lo sé" y sugiere transferir a humano.

## 6. Datos sensibles

- Nunca enviar secretos/tokens en prompts.
- Prompts con instrucciones de no inventar datos (grounding en contexto).
- Logs de IA sin PII innecesaria; `usage_records` solo con conteo de tokens.
- API key de OpenAI nunca en response, logs, auditoría, exceptions ni frontend.
- Provider stateless re: tenant (sin TenantContext, Contact, Conversation queries).

## 7. Config

```php
// config/ai.php
return [
    'default' => env('AI_PROVIDER', 'openai'),
    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY', ''),
            'model' => env('AI_MODEL', 'gpt-4o-mini'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'timeout' => (int) env('AI_TIMEOUT', 15),
            'max_retries' => (int) env('AI_MAX_RETRIES', 1),
            'max_tokens' => (int) env('AI_MAX_TOKENS', 500),
        ],
    ],
];
```

## 8. Tests (FASE 16 U1)

- **Implementado**: `OpenAIProviderTest` (AI-P01..P15) — VO inmutabilidad, resolución desde
  contenedor, Http::fake con respuesta exitosa, system prompt incluido/omitido, manejo de
  errores (401, 429, 400, 500, timeout, respuesta malformada), telemetría de tokens.
- Pendiente: Mock del provider (interfaz) para tests de motor sin red (U2).
- Pendiente: Límite de tokens → se corta la llamada antes del API.
- Pendiente: Fallback timeout/rechazo → respuesta estática + log.
- Pendiente: Aislamiento RAG: Tenant A no ve chunks de Tenant B.
- Pendiente: Costos: `usage_records` se crean por llamada.

# IA

## 1. Principio

Todo acceso a IA pasa por una interfaz. El dominio nunca depende de OpenAI directamente.

```php
interface AIProviderInterface
{
    public function classifyIntent(string $text, array $context = []): IntentResult;
    public function generateResponse(string $prompt, array $context = []): string;
    public function summarizeConversation(array $messages): string;
    public function extractLeadData(string $text): array;
    public function createEmbedding(string $text): array;   // float[] para pgvector
}
```

Implementación concreta: `OpenAIProvider` (Laravel HTTP Client, base `https://api.openai.com/v1`).
Swappable (Anthropic, Azure OpenAI, proveedor local) registrando otra implementación en el
contenedor.

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

## 7. Tests (FASE 16)

- Mock del provider (interfaz) para tests de motor sin red.
- `OpenAIProvider` probado con `Http::fake`.
- Límite de tokens: se corta la llamada antes del API.
- Fallback: timeout/rechazo → respuesta estática + log.
- Aislamiento RAG: Tenant A no ve chunks de Tenant B.
- Costos: `usage_records` se crean por llamada.

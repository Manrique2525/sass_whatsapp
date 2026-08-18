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
- Pendiente: Límite de tokens → se corta la llamada antes del API.
- Pendiente: Fallback timeout/rechazo → respuesta estática + log.
- Pendiente: Aislamiento RAG: Tenant A no ve chunks de Tenant B.
- Pendiente: Costos: `usage_records` se crean por llamada.

## 9. AI Node Runtime (FASE 16 U2)

### Ejecutor: AiNodeExecutor

- `app/Application/Flows/Services/Executors/AiNodeExecutor.php`
- Ejecuta síncronamente: genera contenido con IA y lo guarda en `custom.{output_variable}`.
- **NO envía mensajes** directamente al contacto. Un nodo `message` posterior interpola la variable.
- `bot_paused` verificado primero (defense-in-depth).
- Output sanitizado: `preg_replace` control chars + `VariableGuard::truncateValue`.
- Fallback: si provider falla o devuelve vacío → `config.fallback_message` del nodo o global.
- Idempotencia: si `output_variable` ya existe + log `ai_completed` registrado → reutiliza sin provider call.

### Prompt Builder: AiPromptBuilder

- `app/Application/Flows/Services/AiPromptBuilder.php`
- Separa conceptualmente SYSTEM / CONTEXT / USER.
- **SYSTEM**: instrucciones de plataforma (nunca revelar secrets, grounding en contexto).
- **CONTEXT**: whitelist de campos de contacto, negocio y custom vars (máx 5 campos escalares).
- **USER**: prompt del nodo resuelto con `VariableResolver`.
- `MAX_PROMPT_LENGTH = 8000` trunca el mensaje completo.

### Seguridad del nodo AI

- API key nunca en logs, audit, response ni frontend.
- Prompt completo y response completos nunca registrados (solo token counts).
- Output tratado como texto plano (sin eval, sin dynamic execution).
- Inyección bloqueada: system prompt separado de datos del usuario.
- VariableGuard en `output_variable`.
- Aislamiento cross-tenant: output de Tenant A jamás en Tenant B.

### Config

```php
// config/ai.php (añadido en U2)
'fallback_message' => env('AI_FALLBACK_MESSAGE'),
```

### Tests U2

- **Unit (AiNodeExecutorTest)**: 15 tests / 33 assertions — provider invocation, output
  persistence, prompt resolution, fallback on invalid/empty/timeout/rate-limit/auth errors,
  no message sending, idempotency, bot_paused, sanitization, truncation, security.
- **Feature (AiFlowTest)**: 10 tests / 24 assertions — publish, end-to-end execution,
  AI→condition, AI→message interpolation, fallback→continue, bot_paused, idempotency,
  completion, handoff, validation.
- **Security (AiSecurityTest)**: 10 tests / 15 assertions — cross-tenant isolation,
  API key not in logs/audit, prompt/response not logged, output as plain text,
  injection via contact/custom/config.
- **Multi-tenant (AiTenantIsolationTest)**: 6 tests / 14 assertions — correct tenant context,
  data isolation, output isolation, template isolation, context cleanup.
- **Total AI backend**: 41 tests / 86 assertions.

## 10. Flow Builder AI UX (FASE 16 U3)

### Panel de configuración: AiNodeConfig.vue

- `resources/js/features/flows/components/panels/config/AiNodeConfig.vue`
- Sigue patrón `modelValue`/`update:modelValue` de los otros NodeConfig.
- 4 campos: prompt (requerido, textarea, VariablePicker), system_prompt (opcional, textarea),
  output_variable (requerido, input text, validación snake_case), fallback_message (opcional, textarea).
- VariablePicker disponible para prompt y system_prompt (no para fallback — el runtime no resuelve variables en fallback).
- Preview de output variable: `{{custom.respuesta_ia}}` (solo visual, valor enviado es key plana).

### Validación frontend

- `flowValidation.ts` — AI requiere `prompt` no vacío + `output_variable` válido.
- Longitudes validadas contra `MAX_TEXT_LENGTH` (4096).
- `system_prompt` y `fallback_message` validados si presentes.
- `variableReferenceWarnings` escanea AI prompt.

### Desbloqueos del editor

- **NodePalette**: eliminado `if (type === 'ai') { return; }`, badge "Reservado", `:disabled`.
- **AINode.vue**: delega a FlowNodeBase sin overlay.
- **FlowNodeBase.vue**: eliminados opacity, badge suppression, handle suppression para AI.
- **useFlowEditor.ts**: eliminado `|| type === 'ai'` en `addNode()`.
- **flowUtils.ts**: `isImplementedNodeType('ai')` retorna `true`.
- **flowAdapter.ts**: `canNodeBeStart('ai')` sigue retornando `false` (AI no puede ser start).

### Handle y wraps

- AI tiene target handle (izquierda) + source handle (derecha) como nodos síncronos no-terminales.
- AI NO puede ser start node (mantenido en `canNodeBeStart`).

### Read-only

- AI node editable en draft por flows.manage.
- Read-only en published y para agent con flows.view.
- Mismo patrón que otros nodos (via `NodePropertiesPanel` → `readOnly` context).

### Seguridad frontend

- No se exponen API keys, provider credentials ni model config.
- No se llama al provider desde frontend (sin AI playground/preview).
- Contract UI = contract backend: solo `prompt`, `system_prompt`, `output_variable`, `fallback_message`.

### Tests U3

- **AI-V01..V20**: 20 describe blocks, 49 tests — palette, canvas, start node, config panel,
  validation, VariablePicker, roundtrip, handles, visual, read-only, save, FLOW_CONFLICT,
  security (no model/provider/api_key).
- **Suite frontend total**: 244 tests.

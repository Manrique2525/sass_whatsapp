# Testing

## 1. Estrategia

Pirámide de tests con prioridad en lo crítico:

1. **Unit** — motor de flujos, condiciones, variables, validadores, providers (con `Http::fake`).
2. **Feature** — APIs REST, webhooks, aislamiento tenant, autorización, límites de uso.
3. **Integration** — jobs de cola, webhook → worker → DB → broadcast.
4. **E2E (Playwright)** — flujos de usuario completos (FASE 30).
5. **Security** — 403/401 en cada recurso, firma de webhook, rate limits, tenant isolation.

## 2. Herramientas

| Capa | Herramienta | Nota |
|---|---|---|
| Backend | Pest + Testbench | `tests/Unit`, `tests/Feature` |
| Frontend | Vitest | componentes + composables |
| E2E | Playwright | `tests/e2e` (carpeta separada) |
| Cobertura | Xdebug/pcov | objetivo >= 80% en código crítico |
| Calidad | Pint, PHPStan, ESLint, vue-tsc | gates de CI |

## 3. Convenciones

- Un test por comportamiento. Naming: `Pest\it('...')` descriptivo.
- Fixtures de fábricas: `Database\Factories` (tenant, usuario, contacto, conversación, flujo).
- El **tenant** se crea por test: helper `actingAsTenant($user)` fija `TenantContext` + auth.
- Tests de cola con `Queue::fake()` salvo los de integración (usar `database` sync).
- No se usan `Mockery` permanente para proveedores reales: `Http::fake` para Meta/OpenAI;
  la interfaz se puede falsificar solo en tests de dominio (doble de test, no mock permanente).

## 4. Tests obligatorios por módulo

### Aislamiento tenant (crítico)
- `it('rejects access to another tenant conversation')` → 403/404.
- Ídem para contacts, messages, flows, chunks KB, leads, analytics.
- `it('job does not leak tenant context between executions')`.

### WhatsApp (crítico — FASE 6, 42 tests WHATSAPP-1..40 + 37b/39b)
- Verificación GET: token correcto → challenge en texto plano; incorrecto → 403.
- Firma `X-Hub-Signature-256` ausente/incorrecta → 401 `WHATSAPP_SIGNATURE_INVALID`.
- Mensaje/status entrante: ingesta + dedupe + resolución de tenant por `phone_number_id` +
  dispatch del job correcto (`WHATSAPP-6/7/12`).
- Evento duplicado → `duplicate = true`, no se reprocesa (`WHATSAPP-8`).
- `phone_number_id` desconocido / payload malformado → **200** + `webhook_events.failed`
  (nunca 500; sin reintentos en bucle de Meta) (`WHATSAPP-5/9/10`).
- Aislamiento CRITICO: webhook de un número de B no toca datos de A (`WHATSAPP-11`); Tenant A no
  ve/desconecta la cuenta de B (`WHATSAPP-20`).
- Conexión/envío: token cifrado en reposo (`WHATSAPP-15`), 401/404/409 en Meta, 403 sin permiso,
  404 no-miembro, el token nunca se expone por API (`WHATSAPP-29`), error permanente vs.
  transitorio (`WHATSAPP-26/28`), registro en `message_send_attempts` (`WHATSAPP-25`).
- Provider unit: firma HMAC, payload oficial de sendText, mapeo de errores retryable/no-retryable,
  timeout, `subscribed_apps` (`WHATSAPP-31..40`).
- `Http::fake` con patrones por URL: el matcher de Laravel compara contra la URL **con query
  string** (p. ej. `?fields=...` en `getPhoneNumberInfo`), así que los patrones absorben el query
  (`graph.facebook.com/*/phone-1*`); los `Http::fake` se registran en UNA sola llamada (los
  callbacks se acumulan, no se reemplazan).

### Contactos (FASE 7, 19 tests CONTACT-1..19 + 13 vitest en `contactUtils.test.ts`)
- CRUD por rol: agent no crea/edita/borra (403 `PERMISSION_DENIED`), owner/admin sí (`CONTACT-2/7/14`).
- Validación backend: teléfono inválido (<7 o >15 dígitos, caracteres raros) → 422 (`CONTACT-4`);
  nombre/email/metadata/avatar validados (`CONTACT-16`).
- **Normalización E.164**: `'+54 9 1155 5554-444'` → `+5491155554444`; email parcial
  (`?email=carla`) filtra (`CONTACT-3/9`); dedupe activo → 409 `CONTACT_DUPLICATE` (`CONTACT-6`);
  soft delete libera el teléfono para re-crear (`CONTACT-11`); `provider_contact_id` único
  (`CONTACT-18`).
- **Aislamiento CRITICO**: Tenant A jamás lee/modifica/elimina contactos de B (404 y B intacto,
  `CONTACT-12`); `tenant_id` del body ignorado (`CONTACT-13`).
- `findOrCreateForPhone`: crea bajo el tenant indicado y devuelve el existente (idempotente);
  libera `TenantContext` en `finally` (`CONTACT-19`).
- Auditoría `contact.created/updated/deleted` con `changed` (`CONTACT-15`).
- Paginación con `meta` explícito (`CONTACT-10`); 404 en contacto inexistente/ajeno (`CONTACT-8`).
- Frontend (Vitest): `normalizePhone`, `hasValidPhoneDigits`, `buildContactQuery`,
  `extractErrorMessage`, `parseMetadata`.

### Conversaciones (FASE 8, 24 tests CONV-1..24 + 7 vitest en `conversationUtils.test.ts`)
- CRUD por rol: agent no crea/edita/cierra/asigna (403 `PERMISSION_DENIED`), owner/admin sí
  (`CONV-21`); agent solo lectura (`CONV-22`); no-miembro/suspendido → 404/409.
- Crear: exige `contact_id` del MISMO tenant → 404 si ajeno (`CONV-18` CRITICO); 201 con
  contacto válido; `context`/`bot_paused`/`status` iniciales aceptados; `tenant_id` del body
  ignorado (`CONV-20`).
- Máquina de estados: `open→pending→resolved→archived`, reabrir desde cualquier ≠ `open`,
  transición inválida → 409 `CONVERSATION_INVALID_STATE`, mismo estado → no-op
  (`CONV-6/7/14`); `context` merge por claves en PATCH (`CONV-8`).
- Assign/transfer: asignación a miembro ACTIVO del tenant → 200 y `agent_id` actualizado;
  agente de otro tenant → 422 `AGENT_NOT_IN_TENANT` (`CONV-9/10`); transfer cierra la
  asignación/participación previas (`unassigned_at`/`left_at`) y crea historial con reason
  `transfer` (`CONV-11/13`); cada transición registra `conversation_assignments` y audita
  (`CONV-15`).
- **Aislamiento CRITICO**: Tenant A jamás lee/modifica/asigna conversaciones de B (404 y B
  intacto, `CONV-19`); crear sobre contacto de B → 404 (`CONV-18`); `tenant_id` del body
  ignorado (`CONV-20`).
- `findOrCreateActiveForContact`: reutiliza la activa del contacto o crea; con contacto
  soft-deleted no la resucita; libera `TenantContext` en `finally` (`CONV-24`).
- Listado: filtros search (sobre el contacto)/status/agent_id + paginación `meta`
  (`CONV-2/5`); 404 en conversación inexistente/ajena (`CONV-3`).
- Frontend (Vitest): `buildConversationQuery`, `formatLastInteraction`, `canClose/canReopen`,
  `CONVERSATION_STATUS_META`, `extractErrorMessage`.

### Mensajes (FASE 9, 28 tests MSG-1..9 / STAT-1..8 / OUT-1..7 / OUTBOX-1..4)
- Inbound (`MSG-1..9`): el webhook de Meta persiste contact+conversation+message e2e (`MSG-1`);
  dedupe por `provider_message_id` (mismo evento dos veces → mismo mensaje, `MSG-2`, y a nivel
  de servicio `MSG-3`); **aislamiento CRITICO** `MSG-6` (webhook de un número de B nunca toca
  datos de A); tipo no soportado → evento `failed` (`MSG-4`); payload sin id/from → no-op con log
  (`MSG-5`); media image persiste `media_mime`/`media_size` + metadata (`MSG-7`); conversación
  `resolved` se reabre (`MSG-8`); audita `message.received` (`MSG-9`).
- Status (`STAT-1..8`): `delivered`/`read` actualizan estado + columna temporal e2e (`STAT-1/2`);
  clave compuesta `id|status|timestamp` permite delivered+read del mismo mensaje (`STAT-4`);
  repetición idéntica → idempotente (`STAT-3`); `failed` → conversación `pending` (`STAT-5`);
  sin mensaje → no-op con log (`STAT-6`); status desconocido → no-op (`STAT-7`); aislamiento A/B
  (`STAT-8`).
- Outbound (`OUT-1..7`): `createOutbound` encola `SendWhatsAppMessage` con mensaje `pending`
  (`OUT-1`); éxito → `sent` + `provider_message_id` + attempt + audita `message.sent` (`OUT-2`);
  error permanente de Meta → `failed` (`OUT-3`); error retryable → rethrow y el mensaje queda
  `sending` (`OUT-4`); CAS: job duplicado no re-envía (`OUT-5`); sin cuenta conectada →
  `failed` `whatsapp_not_connected` (`OUT-6`); tipo no-text → `failed` (`OUT-7`).
- Outbox (`OUTBOX-1..4`): reprocesa eventos `received` viejos (`OUTBOX-1`); ignora recientes y
  `processed` (`OUTBOX-2/3`); límite 100 y exit codes (`OUTBOX-4`).
- Helpers de test compartidos en `tests/Support/helpers.php` (autoload-dev `files`): `make_contact`,
  `make_conversation`, webhook (firma/secreto/payload); `created_at`/`updated_at` de
  `webhook_events` no son `$fillable` → los tests de outbox insertan con `DB::table(...)->insert()`.

### Chatbot engine (crítico)
- Secuencia lineal, ramas condition, question→variable, delay, human, end.
- Loop detection / límite de pasos.
- Reanudación tras `waiting`.
- Validación de flujo: sin start, nodo huérfano, sin end, config inválida.

### Auth
- registro, login ok/ko, logout, forgot/reset, email verify, tokens.

### Billing / límites
- cuota superada → `TENANT_QUOTA_EXCEEDED` antes de enviar/IA/crear contacto.
- usage_records incrementan.

### Seguridad
- 401 sin token, 403 sin permiso, 404 en datos ajenos, throttle en login/envío.

## 5. Comandos

```bash
php artisan test                      # todo backend
php artisan test --testsuite=Feature  # API/feature
./vendor/bin/pint --test              # lint
./vendor/bin/phpstan analyze          # estático
npm run test                          # Vitest
npm run typecheck                     # vue-tsc
npx playwright test                   # E2E
```

En CI: secuencia lint → phpstan → test → build → typecheck → (E2E opcional en PRs grandes).

## 6. Cobertura

- `php artisan test --coverage` en CI.
- Umbrales: código crítico (webhooks, engine, tenant, billing) >= 90%; global >= 80%.
- Se registran excepciones justificadas en `docs/decisions.md`.

## 7. Estado de pruebas por fase

Cada fase declara su estado en `docs/roadmap.md` (PASS/FAIL) usando el formato de reporte
definido por el usuario (ver final de `roadmap.md`).

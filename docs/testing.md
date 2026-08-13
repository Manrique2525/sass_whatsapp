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

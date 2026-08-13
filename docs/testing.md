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

### WhatsApp (crítico)
- Verificación GET (token correcto/incorrecto).
- Firma `X-Hub-Signature-256` válida/inválida → 200/401.
- Mensaje entrante crea contact+conversation+message una sola vez (duplicado idempotente).
- Status delivered/read/failed.
- Payload malformado → 200 (no reenvío infinito).
- Rate limit de Meta → backoff.

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

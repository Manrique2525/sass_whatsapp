# Seguridad

Alineado a OWASP Top 10. Cada fase incluye controles de seguridad + tests.

## 1. Principios

- **Nunca confiar en datos del frontend.** Toda validación de negocio y límites en backend.
- **Defensa en profundidad** (validación + policies + scopes + auditoría).
- **Menor privilegio**: roles y permisos explícitos (spatie/laravel-permission).
- **Ocultar existencia**: accesos a datos ajenos devuelven 404 (no 401/403 cuando revelaría).
- **Secrets fuera del código**: todo vía `.env` (ver `deployment.md`).

## 2. Controles por vector

### Autenticación y sesiones
- Sanctum en modo stateful (cookies + CSRF) para el SPA interno y tokens Bearer para clientes
  externos (ADR-011). Passwords con `bcrypt` (cast Eloquent `hashed`, nunca en texto plano
  ni en logs).
- Login rate-limit: `auth-login` 10/min por email/IP.
- Registro rate-limit: `auth-register` 5/min por IP. Con verificación de email.
- Password reset rate-limit: `auth-password` 3/min por email/IP; tokens de un solo uso con
  expiración (60 min); el reset **revoca todos los tokens Sanctum** del usuario.
- **No filtración de emails**: `forgot-password` responde siempre igual (exista o no el email),
  tanto en web como en API.
- Rotación/revocación de tokens en logout (API revoca el token actual; web invalida sesión +
  `regenerateToken`). Regeneración de sesión tras login.
- Passwords mínimos: `Password::min(8)` (policy global en `AppServiceProvider`).
- Error de login genérico (mismo mensaje para email inexistente o contraseña incorrecta).

### Autorización
- `Policies` por entidad (ConversationPolicy, ContactPolicy, FlowPolicy...).
- `Middleware tenant` resuelve el tenant activo (ver `multi-tenancy.md`).
- Roles por tenant con spatie en modo `teams` (`team_id = tenant_id`): `owner`, `admin`,
  `agent`; `super_admin` global de plataforma. Permisos: `manage_contacts`,
  `manage_chatbots`, `manage_agents`, `view_analytics`, `manage_billing`, etc. Ver ADR-012.

### Inyección SQL
- Eloquent/Query Builder con bindings. Sin concatenación de SQL.
- `phpstan` + revisión en code review.

### XSS / Output encoding
- Vue escapa por defecto. Respuestas JSON (nunca HTML server-rendered con datos de usuario).
- Validación estricta de tipos en `FormRequest`.

### CSRF
- API con Bearer token (sin cookies de sesión → no aplica CSRF clásico).
- Si se usan rutas web (Inertia), `VerifyCsrfToken` aplica.

### Webhooks de WhatsApp (crítico)
- **Verificación GET**: `hub.verify_token` comparado (hash_equals) contra `WHATSAPP_VERIFY_TOKEN`.
- **Firma POST**: `X-Hub-Signature-256 = HMAC-SHA256(APP_SECRET, raw_body)` comparada con
  `hash_equals`, calculada sobre el **cuerpo crudo exacto** (`$request->getContent()`). NUNCA
  re-serializar el JSON para verificar (rompería la firma). Rechazo con 401 si falla.
- **Idempotencia**: `webhook_events` (plataforma) con UNIQUE `provider_event_id`; insert con
  `ON CONFLICT DO NOTHING`. Duplicados (secuenciales o concurrentes) no se reprocesan.
- Payload validado contra esquema antes de tocar DB.

### SSRF (salida)
- El nodo `webhook` de los flujos permite POST externos con URLs configuradas por el tenant.
  Validación anti-SSRF: solo esquemas http/https, bloqueo de IPs privadas/loopback/metadata
  cloud, resolución de DNS y verificación de IP del host antes del request, allowlist por tenant.

### Aislamiento de infraestructura compartida
- Redis y S3 son compartidos entre tenants: claves de cache/locks/rate-limit con prefijo
  `tenant:{id}:`, objetos S3 bajo `tenant/{tenant_id}/...`. Un tenant no puede leer claves/objetos
  de otro. Ver `multi-tenancy.md` §6.

### Headers de seguridad
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`,
  CSP para el SPA. `APP_DEBUG=false` en producción.

### Rate limiting
- `throttle` global en API (p. ej. 300/min) y específicos: envío de mensajes, IA, login.
- Las claves de throttle incluyen `tenant_id`/`user_id` (Redis compartido → nunca claves globales).

### Límites de uso (backend)
- `UsageGuard` valida cuota del plan ANTES de: enviar mensaje, ejecutar IA, crear contacto,
  publicar flow, procesar documento KB. Respuesta `TENANT_QUOTA_EXCEEDED`.

### Secreto de tokens de WhatsApp
- El `access_token` de cada WABA se guarda **cifrado** en `whatsapp_accounts.access_token`
  (atributo `encrypted`, cifrado con la `APP_KEY`). Nunca se devuelve en respuestas API.
  Cada tenant usa su propio token para envío; el `App Secret` y el `verify token` de los
  webhooks son globales de la app y viven solo en `.env`. Rotación de tokens de números.

### Seguridad de archivos
- Uploads a S3 con ACLs privadas, URLs firmadas, validación MIME real + tamaño.
- Detección de tipo por contenido (no confiar en extension).

### Logs y auditoría
- `AuditLog` para acciones sensibles (ver `database.md`). Logs sin datos personales
  innecesarios, con `tenant_id`/`user_id`/correlation id.

## 3. Comprobaciones automatizadas

- PHPStan nivel alto.
- Tests de seguridad por fase (acceso no autorizado a cada recurso).
- GitHub Actions: lint, tests, `composer audit`, `npm audit`.
- Revisión de dependencias (Dependabot).
- Sentry captura excepciones; sin excepciones en entornos no dev.

## 4. Encriptación

- Datos en repositorio: atributos `encrypted` (tokens WhatsApp, secrets).
- En tránsito: HTTPS obligatorio (nginx/TLS).
- En reposo: cifrado a nivel de disco en infraestructura.

## 5. Checklist antes de cada release

- [ ] Tests de aislamiento tenant verdes.
- [ ] Tests de autorización (403/401) verdes.
- [ ] `composer audit` sin vulnerabilidades conocidas.
- [ ] Webhook signature test verde.
- [ ] Sin secretos en el repo (gitleaks en CI).
- [ ] Rate limits aplicados a rutas sensibles.

# Manual operativo completo

**Producto:** SaaS multi-tenant de chatbots para WhatsApp
**Audiencia:** Platform Admin, Owner, Admin, Agent y soporte operativo
**Estado:** borrador operativo basado en la implementación actual
**Alcance:** FASE41, documentación solamente

> Este documento describe el comportamiento implementado, no el comportamiento futuro. Cuando
> existe una diferencia entre diseño y código, se conserva la limitación real y se marca como
> `MANUAL DISCOVERY ISSUE`.

## 1. Acceso y seguridad

### 1.1 Entradas públicas

- `/`: landing pública.
- `/privacy` y `/terms`: documentos legales.
- `/login`, `/register`, `/forgot-password`, `/reset-password`: autenticación web.
- `/invitations/{token}`: consulta pública de invitación; el token es la credencial.
- `/health` y `/ready`: probes sin sesión.
- `GET/POST /api/webhooks/whatsapp`: webhook público de Meta, protegido por verify token,
  firma `X-Hub-Signature-256` y rate limit.
- `POST /api/webhooks/flows/{trigger}`: webhook de flujo con Bearer token e idempotency key.
- `POST /api/webhooks/stripe`: webhook público autenticado por `Stripe-Signature`.

### 1.2 Flujo de usuario

1. Registrarse o iniciar sesión.
2. Verificar el correo electrónico. Las áreas tenant requieren usuario autenticado y verificado.
3. Seleccionar o cambiar de tenant si la cuenta pertenece a varios.
4. Completar `/onboarding` cuando corresponda.
5. Operar solo dentro del tenant activo.

El aislamiento es obligatorio: un recurso de otro tenant se oculta o rechaza; nunca se debe
usar un identificador de otro tenant para intentar acceder a datos.

### 1.3 Roles

| Rol | Uso operativo | Restricciones principales |
|---|---|---|
| Owner | Control total del tenant, configuración, miembros, billing y automatizaciones | No administra la plataforma global |
| Admin | Operación y configuración tenant | No asigna roles ni administra billing |
| Agent | Inbox, contactos y atención | No administra configuración; flujos en solo lectura |
| Platform Admin | Clientes, planes, suscripciones y seguridad de plataforma | No obtiene permisos tenant automáticamente |

`super_admin` es un rol global. El acceso Platform exige correo verificado y MFA. Las rutas
`/platform/*` aplican `platform.admin` y `platform.mfa`.

### 1.4 Recuperación y MFA

- Contraseña olvidada: `/forgot-password`; el enlace recibido abre `/reset-password`.
- Correo no verificado: `/verify-email`; se puede reenviar la verificación con limitación.
- Platform Admin: entrar en `/platform/security`, enrolar MFA, confirmar el código y conservar
  los códigos de recuperación fuera del repositorio.
- Si se pierde el segundo factor, usar un código de recuperación o el procedimiento interno de
  recuperación de Platform Admin. No desactivar MFA como solución operativa normal.

## 2. Menú tenant

El menú de aplicación expone Dashboard, Conversaciones, Contactos, Flujos y las páginas de
Settings. El acceso visual no sustituye la autorización backend.

### 2.1 Dashboard

`/dashboard` muestra el resumen operativo del tenant. Usarlo como punto de entrada para detectar
conversaciones pendientes, actividad y estado general. Para cifras auditables usar Analytics.

### 2.2 Perfil de negocio

Ruta UI: `/settings/business-profile`. API: `GET/PUT /api/v1/tenants/{tenant}/business-profile`.

Procedimiento:

1. Abrir el perfil del negocio.
2. Completar identidad y datos descriptivos del negocio.
3. Guardar y verificar el mensaje de confirmación.
4. Usar los valores del perfil en variables `{{business.name}}` y otros campos admitidos.

Validar siempre con el backend; los datos del formulario no son una fuente de autoridad.

### 2.3 Usuarios e invitaciones

Ruta UI: `/settings/users`. API: `/users`, `/users/invitations` bajo el tenant.

Owner/Admin autorizados pueden:

- consultar miembros;
- invitar por correo y rol;
- reenviar o revocar invitaciones;
- actualizar o eliminar miembros según las reglas de autorización.

El receptor abre el enlace, revisa el tenant y acepta. Una invitación revocada, expirada o de
otro contexto no debe reutilizarse. El rol efectivo se valida en backend.

### 2.4 WhatsApp

Ruta UI: `/settings/whatsapp`. API: `/whatsapp`, `/whatsapp/connect` y `/whatsapp/disconnect`.

Procedimiento de conexión:

1. Confirmar que se dispone de una cuenta de WhatsApp Business de Meta y sus credenciales.
2. Iniciar la conexión desde el panel.
3. Confirmar el estado del número y la configuración del webhook en Meta.
4. Enviar un mensaje de prueba y revisar que aparezca en Conversaciones.

Operaciones adicionales:

- consultar/sincronizar templates aprobados;
- comprobar salud del número con `POST /whatsapp/phone-health`;
- descargar media recibida desde la ruta tenant-scoped;
- consultar la cola de webhooks y reintentar eventos fallidos.

Solo se admite Meta WhatsApp Cloud API. No conectar proveedores no oficiales.

**MANUAL DISCOVERY ISSUE:** conectar WhatsApp requiere configuración externa de Meta y no se
considera resuelto solo porque la página web cargue. En Free Private Beta es condicional.

### 2.5 Contactos y tags

Ruta UI: `/settings/contacts`. API: CRUD `/contacts`, CRUD `/tags` y asignación de tags por
`/contacts/{contact}/tags`.

Procedimiento:

1. Buscar por teléfono u otros filtros disponibles.
2. Crear o editar el contacto con datos válidos.
3. Asignar tags existentes o crear tags desde la operación autorizada.
4. Verificar la relación en el detalle del contacto.

Los nombres de tag se recortan con `trim`; no se admiten nombres vacíos. Crear/asignar/remover
es idempotente. Los tags pertenecen al contacto, no a la conversación.

### 2.6 Conversaciones e inbox

Ruta UI: `/settings/conversations` y página `/conversations`. API: `/conversations` y
`/conversations/{conversation}/messages`.

Estados: `open`, `pending`, `resolved`, `archived`.

Procedimiento de atención:

1. Abrir la conversación y revisar historial y contacto.
2. Reclamar (`claim`) o asignar a un agente cuando corresponda.
3. Responder con texto o template aprobado.
4. Cerrar/resolver o reabrir según la operación.
5. Si el bot debe detenerse, usar `pause-bot`; para devolver automatización, `resume-bot`.

El handoff a humano fija `bot_paused`, conserva los mensajes entrantes para el agente y termina
la ejecución del flujo como `handed_off`. `resume-bot` no revive la ejecución anterior ni
reprocesa mensajes recibidos durante el handoff. Una pausa manual no crea una solicitud de
handoff reclamable.

### 2.7 Chatbots y flujos

Ruta UI: `/settings/flows` y `/settings/flows/{chatbot}/{flow}`. API: `/chatbots`, `/flows`,
`/triggers` y `/flow-executions`.

Estados de flujo: `draft -> published -> inactive`.

Tipos de nodo implementados:

| Nodo | Operación |
|---|---|
| `message` | envía texto y variables |
| `buttons` | muestra opciones y espera selección |
| `question` | pregunta, captura respuesta en una variable y puede aplicar default |
| `condition` | evalúa reglas y elige rama |
| `delay` | espera mediante job programado |
| `tag` | aplica tags al contacto |
| `webhook` | llama una URL HTTP(S) validada contra SSRF |
| `human` | pausa el bot y entrega a humano |
| `end` | finaliza la ejecución |
| `ai` | interfaz/configuración visual disponible, pero publicación bloqueada por validator actual |

Publicación:

1. Crear chatbot y flujo.
2. Editar el draft.
3. Validar el grafo.
4. Corregir nodos huérfanos, conexiones, terminales y configuraciones.
5. Publicar solo cuando la validación backend sea válida.

El flujo requiere un único nodo de inicio (`is_start`), todos los nodos alcanzables, terminal
`end` o `human`, conexiones válidas y ausencia de loops infinitos sin espera. Un flujo publicado
no se edita ni elimina. No puede haber dos triggers genéricos publicados equivalentes.

Triggers implementados: `keyword`, `new_message`, `start`, `schedule` y `webhook`. El trigger
`tag` valida su configuración CRUD, pero no dispara ejecuciones automáticas todavía.

Las condiciones admiten `all`/AND, `any`/OR, negación y operadores de existencia, vacío,
igualdad, contiene, prefijo, sufijo y comparaciones numéricas/fechas. Variables útiles incluyen
`contact`, `business`, `conversation` y `custom`.

### 2.8 FAQ

Ruta UI: `/settings/faq`. API: CRUD `/faqs`.

Procedimiento:

1. Crear pregunta y respuesta.
2. Activar la entrada.
3. Probar con la pregunta normalizada exacta.
4. Ajustar prioridad si existen coincidencias equivalentes.

El matcher es determinista: normaliza, busca coincidencia exacta entre FAQs activas y desempata
por prioridad y antigüedad. No es búsqueda semántica ni respuesta generativa.

### 2.9 Knowledge Base

Ruta UI: `/settings/knowledge`. API: `/knowledge-bases` y `/documents`.

Formatos admitidos: PDF, DOCX y TXT. Tamaño máximo de archivo: 10 MB. La validación comprueba
MIME y magic bytes. El texto extraído tiene límite de 500K caracteres; el chunking usa máximo
1500 caracteres, overlap de 200, mínimo de 50 y hasta 500 chunks por documento.

Procedimiento:

1. Crear una base de conocimiento.
2. Subir un documento permitido.
3. Esperar el procesamiento en cola.
4. Revisar estado y eliminar documentos inválidos o desactualizados.

En Free Private Beta Knowledge está ON con el límite de 10 MB. La ruta de storage y los reintentos
son configuración de infraestructura, no una opción del usuario final.

### 2.10 Leads

Ruta UI: `/settings/leads`. API: CRUD `/leads`.

Estados: `new`, `contacted`, `qualified`, `won`, `lost`. Buscar, abrir, actualizar estado/datos
y eliminar solo cuando la política operativa lo permita. Registrar cambios en el CRM y no confiar
en valores enviados desde el frontend.

### 2.11 Analytics y notificaciones

Analytics UI: `/settings/analytics`; API: `GET /analytics/overview`. Filtrar por período válido
y contrastar cifras con el período seleccionado.

Notificaciones API: listar, contar no leídas, marcar una o todas como leídas y actualizar
preferencias. Tipos actuales: `handoff_requested`, `conversation_assigned`,
`conversation_claimed` y `system`.

### 2.12 Billing y uso

Ruta UI: `/settings/billing`. API: planes, suscripciones, usage, checkout y portal.

El usuario puede consultar planes, suscripción y uso; checkout/portal dependen de configuración
de Stripe. En Free Private Beta Stripe permanece OFF: no presentar checkout como disponible.
Las categorías de uso son messages, ai_tokens, contacts, flow_executions, users y
knowledge_documents.

## 3. Platform Admin

Entrada: `/platform` o `/platform/dashboard`.

### 3.1 Seguridad

Antes de operar, confirmar correo verificado y MFA completado. La configuración se administra en
`/platform/security`; hay enrolamiento, confirmación, challenge, regeneración de códigos y
desactivación protegida.

### 3.2 Clientes y operaciones asistidas

`/platform/customers` lista tenants y ahora incluye `Create customer`. El formulario solicita
nombre del negocio, nombre del Owner y email del Owner; crea un tenant activo, membresía Owner y
suscripción Free. Para un email ya existente se exige confirmación explícita y no se crea un
usuario duplicado. El Owner nuevo recibe verificación y enlace de definición de contraseña por
correo; nunca se muestra una contraseña al Platform Admin.

`/platform/customers/{tenant}` muestra el detalle y permite:

- suspender/reactivar el tenant con confirmación y motivo;
- reenviar verificación si el Owner no está verificado;
- enviar reset de contraseña si el Owner ya está verificado;
- crear la suscripción Free si un tenant legado no tiene ninguna.

Estas mutaciones requieren Platform Admin, email verificado y MFA; son operaciones globales,
no permisos tenant.

### 3.3 Planes

`/platform/plans` permite listar, crear, consultar y editar planes. Validar nombre, límites,
precio, moneda, periodicidad y estado antes de guardar. Una modificación de plan debe revisarse
con la suscripción afectada.

### 3.4 Suscripciones

`/platform/subscriptions` permite listar y consultar. Desde el cliente se puede abrir preview y
cambiar el plan de suscripciones existentes. Confirmar preview antes de ejecutar una mutación.
La creación de clientes crea directamente la suscripción Free; el botón de reparación solo
aparece en detalles sin suscripción. Estados: `active`, `pending`,
`past_due` y `cancelled`.

### 3.5 Provisionamiento local

El comando local existente es:

```bash
php artisan platform-admin:create
```

Solicita email, nombre y contraseña de forma segura, valida unicidad, asigna el rol global y no
imprime secretos. El runbook está en `docs/runbooks/platform-admin-provisioning.md`.

**Advertencia:** el comando local no constituye por sí mismo una política productiva de creación
de administradores. En producción debe existir un procedimiento aprobado, auditado y con secretos
fuera de Git.

## 4. Operación de WhatsApp y flujos

### 4.1 Mensaje entrante

Meta entrega el webhook; se verifica firma, se persiste el evento de forma idempotente y se
resuelve el tenant/número. El motor usa lock Redis por conversación y no duplica ejecución ante
mensajes simultáneos o webhooks duplicados.

### 4.2 Mensaje saliente

Los envíos pasan por el pipeline de mensajes y provider oficial. Durante handoff, un outbound
automático se marca fallido con `BOT_PAUSED_HANDOFF` antes de llamar a Meta; los mensajes de origen
humano o handoff siguen permitidos.

### 4.3 Schedule y webhook de flujo

- Schedule: command cada minuto, cron de cinco campos, jobs únicos y varias barreras contra
  duplicación.
- Webhook de flujo: `POST /api/webhooks/flows/{trigger}`, Bearer token, payload máximo 64 KB,
  `Idempotency-Key` y respuesta `202 accepted` cuando se encola.
- Nunca incluir `tenant_id` del cliente como fuente de autoridad; el tenant se resuelve desde el
  trigger.

## 5. Errores y diagnóstico

Las respuestas API siguen `{message, code, errors}` cuando aplica.

| Código/resultado | Acción operativa |
|---|---|
| `401` | revisar sesión, token, firma o credencial; no repetir indiscriminadamente |
| `403` | confirmar rol y permiso requerido |
| `404` | comprobar tenant activo y que el recurso pertenezca al tenant |
| `409 FLOW_INVALID` | corregir grafo/configuración antes de publicar |
| `409 FLOW_PUBLISHED` | crear un nuevo draft/flujo; no editar el publicado |
| `409 FLOW_ALREADY_PUBLISHED` | revisar triggers genéricos publicados |
| `409 FLOW_CONFLICT` | recargar o resolver el conflicto de edición explícitamente |
| `409 WEBHOOK_DUPLICATE` | verificar idempotency key; no reenviar con la misma clave |
| `409 CONVERSATION_INVALID_STATE` | revisar estado antes de handoff/resume |
| `BOT_PAUSED_HANDOFF` | el bot está pausado por handoff; intervenir desde inbox |
| upload rechazado | revisar extensión, MIME, magic bytes y límite de 10 MB |

### 5.1 Checklist de soporte

1. Registrar tenant, usuario, URL, hora, recurso y código de error.
2. No pedir ni copiar tokens, contraseñas, API keys o recovery codes.
3. Confirmar el tenant activo y el rol.
4. Revisar estado de cola, logs sanitizados, auditoría y health/readiness.
5. En WhatsApp, revisar firma, evento recibido, número, template y estado Meta.
6. En flujos, revisar ejecución, logs por paso, estado de conversación y `bot_paused`.
7. Reintentar solo operaciones idempotentes y con la clave correcta.

## 6. QA manual

### 6.1 Smoke tenant

- Login y verificación de correo.
- Cambio de tenant y comprobación de aislamiento A/B.
- Crear contacto, tag, FAQ, lead y documento válido.
- Crear flujo message → question → condition → end; validar, publicar y ejecutar.
- Abrir conversación, reclamar, enviar, pausar y reanudar bot.
- Consultar analytics, notificaciones, billing y usage.

### 6.2 Smoke Platform

- Acceso no verificado bloqueado.
- Acceso sin MFA bloqueado.
- Platform Admin puede consultar dashboard, clientes, planes y suscripciones.
- Usuario tenant no puede abrir `/platform`.
- Preview de cambio de plan no muta hasta confirmación.

### 6.3 Casos negativos

- Usuario Agent intentando administrar usuarios, billing o flujos.
- Recurso de tenant B usando sesión de tenant A.
- Flujo sin inicio, con nodo huérfano, sin terminal o con loop no permitido.
- Documento >10 MB, MIME incorrecto o contenido no compatible.
- Webhook con token inválido, payload >64 KB o idempotency key duplicada.
- Handoff en conversación `resolved` o `archived`.
- Template no aprobado o variables inválidas.

### 6.4 Evidencia

Usar capturas solo como evidencia del entorno probado, sin incluir secretos ni PII:

```text
![Login](screenshots/01-login.png)
![Dashboard tenant](screenshots/02-dashboard.png)
![Inbox](screenshots/03-inbox.png)
![Editor de flujo](screenshots/04-flow-editor.png)
![Knowledge](screenshots/05-knowledge.png)
![Platform Admin](screenshots/06-platform.png)
```

Las rutas son placeholders hasta ejecutar QA visual con datos sintéticos.

## 7. Local, staging y producción

| Tema | Local | Staging/producción |
|---|---|---|
| Cola | puede usar sync para tests | Redis/worker operativo |
| Storage | MinIO/config local | S3 compatible con prefijo tenant |
| WhatsApp | credenciales de prueba o desconectado | Meta Cloud API configurada |
| Reverb | host local | dominio, TLS y procesos persistentes |
| Stripe | normalmente OFF en beta | solo con claves/webhooks aprobados |
| Platform Admin | comando Artisan local | procedimiento productivo aprobado |
| Datos | seeders/datos sintéticos | nunca usar credenciales de prueba |

Antes de producción comprobar `.env`, queue workers, scheduler, Redis, storage, Reverb, Sentry,
webhooks, TLS, backups, rate limits y aislamiento tenant. Nunca commitear `.env` ni secretos.

## 8. Trazabilidad técnica

| Área | Implementación principal |
|---|---|
| Rutas web | `routes/web.php` |
| API | `routes/api.php` |
| Autorización | `app/Application/Users/Services/AuthorizationService.php` |
| Permisos | `app/Domain/Users/Enums/TenantPermission.php` |
| Flujos | `app/Application/Flows`, `docs/chatbot-engine.md` |
| WhatsApp | `app/Infrastructure/WhatsApp`, controllers y jobs WhatsApp |
| Knowledge | `config/knowledge.php`, `KnowledgeBaseController`, `DocumentController` |
| Billing | `app/Application/Billing`, controllers de Billing/Checkout |
| Platform | `app/Http/Controllers/Platform`, `app/Application/Platform` |
| Frontend | `resources/js/Pages`, `resources/js/Layouts` |
| Tests | `tests/Feature`, `tests/Unit`, `tests/e2e` |

## 9. Discrepancias y límites conocidos

- `tag` trigger: contrato y CRUD validados, sin disparador automático.
- Nodo `ai`: configuración UX presente, pero el validator bloquea publicación; AI permanece OFF
  para Free Private Beta.
- Stripe: endpoints existen, pero la beta aprobada mantiene checkout desactivado.
- WhatsApp: la integración depende de configuración Meta externa y no debe asumirse conectada.
- Las capturas de pantalla todavía son placeholders.
- FASE40 continúa abierta por proveedor, región, dominio, DNS, ownership y decisión de WhatsApp
  para el día uno.

## 10. Glosario

- **Tenant:** negocio aislado dentro de la plataforma.
- **Tenant activo:** contexto seleccionado por el usuario para la operación actual.
- **Flow:** definición persistida y ejecutable del chatbot.
- **Trigger:** condición que inicia un flujo.
- **Execution:** instancia de ejecución asociada a una conversación.
- **Handoff:** entrega del control del bot a un agente humano.
- **Knowledge Base:** conjunto tenant-scoped de documentos procesados.
- **Free Private Beta:** modalidad aprobada de validación privada con límites y servicios reducidos.

# Runtime Topology Runbook

## Contract

Production is a container-per-process topology. The public edge terminates TLS in
the infrastructure reverse proxy and forwards HTTP/WebSocket traffic to the Nginx
container. PostgreSQL, Redis, S3-compatible storage and SMTP are external managed
dependencies in production; they are not bundled into the production Compose file.

```text
Internet / TLS reverse proxy
            |
            v
          Nginx (web)
          |         \
          v          v
      PHP-FPM app   Reverb WebSocket
          |
    +-----+------+---------+-----------+
    v            v         v           v
 PostgreSQL     Redis     S3          SMTP
 (external)   (external) (private)  (provider)

Redis queues:
  default   -> worker-default   -> WhatsApp, flows, triggers, notifications
  knowledge -> worker-knowledge -> document processing and embeddings
  analytics -> worker-analytics -> daily aggregation

Scheduler -> Laravel schedule:work -> scheduled commands
```

Production services are `web`, `app`, `worker-default`, `worker-knowledge`,
`worker-analytics`, `scheduler` and `reverb`. The production Compose template exposes
only the web listener; app/FPM, workers, scheduler and Reverb have no host ports.

## Disposable Validation

Use only the local rehearsal file. It provides isolated PostgreSQL, authenticated
Redis, MinIO and Mailpit containers, named volumes and one host port:

```bash
docker compose -f docker-compose.runtime-rehearsal.yml up -d
curl http://127.0.0.1:18080/health
curl http://127.0.0.1:18080/ready
docker compose -f docker-compose.runtime-rehearsal.yml ps
```

The rehearsal uses no bind mounts for application code and no host ports for internal
dependencies. It is not a production environment and its credentials are disposable.
The database was migrated only inside this disposable stack for job/service drills;
no production migration was executed.

## Probes

- `/health` is liveness: it checks only that Laravel can respond and remains `200` when DB or Redis is unavailable.
- `/ready` checks DB, Redis and the queue backend and returns `503` when a critical dependency is unavailable.
- Scheduler heartbeat is informational in `/ready`; the default production stale threshold is 120 seconds.
- Docker healthchecks cover dependency connectivity, FPM readiness, worker process, scheduler process, Reverb process and Nginx HTTP routing.

The probe routes bypass session, cookie, CSRF and Inertia middleware so a Redis outage
cannot turn liveness into an unrelated session error.

## Queue Matrix

| Work | Queue | Timeout | Retries/backoff | Criticality |
|---|---|---:|---|---|
| WhatsApp inbound, outbound, status, webhook/flow/tag triggers | `default` | 60-120s | job-specific, generally 3 with bounded backoff | customer-facing |
| Notifications and password/invitation/reset mail | `default` | framework/job-specific | framework/job-specific | customer-facing |
| Knowledge document processing | `knowledge` | 120s | 3, `[30,60]` | isolated long work |
| Knowledge embedding materialization | `knowledge` | 180s | 3, `[30,60,120]` | isolated long work |
| Daily analytics aggregation | `analytics` | 300s | 3, `[30,60,120]` | batch |

`ProcessKnowledgeDocument` explicitly routes its follow-up embedding job to
`knowledge`. No fourth production queue is part of this contract.

## Restart And Shutdown

Production uses `on-failure:5` in the template. This gives crash recovery without an
unbounded restart loop that masks a bad configuration. An orchestrator must alert when
the retry budget is exhausted. A deployment stops accepting traffic, drains workers,
restarts the independent process containers, verifies `/health` and `/ready`, then
restores traffic. Laravel workers receive `queue:restart` for a graceful code reload;
the queue backend retains an unfinished job for retry according to `retry_after`.

The rehearsal verified worker recovery by recreating a crashed default worker, and
queue isolation by stopping knowledge/analytics workers: default work continued,
specialized jobs remained in their Redis queue, and the backlog drained after restart.
The canonical E2E stack mirrors the queue contract with one worker consuming
`default,knowledge,analytics`; its final clean run completed 39/39 browser tests.
The `e2e:assert-queue-clean` guard checks all three queues plus `failed_jobs`.

## Reverb

Nginx proxies `/app/` with HTTP/1.1 `Upgrade` and `Connection` headers. The Nginx
resolver uses Docker DNS (`127.0.0.11`) so a Reverb container replacement does not
pin the old container IP. Origins are configured as explicit hostnames; the config
normalizes URL-shaped environment values to the host format required by Reverb.
Wildcard origins are rejected by the production validator.

The disposable rehearsal established a WebSocket `101 Switching Protocols` through
Nginx for an allowed origin and emitted Reverb's origin error for an unauthorized
origin. No real browser/provider connection was used.

## Storage, Mail And Redis

- The production default is S3-compatible private storage. Tenant object keys are namespaced under `tenant/{tenant_id}/`.
- Public/local storage is not an allowed production fallback. Upload/read failures return controlled application errors.
- The rehearsal wrote/read a private MinIO object, denied anonymous access, failed safely while MinIO was stopped, and recovered after restart.
- Production SMTP is required by the validator. Mailpit is allowed only in the rehearsal; verification/reset mail was sent to disposable Mailpit.
- Redis is authenticated and separates cache DB 1 from default/queue DB 0, with explicit prefixes. Reverb horizontal scaling remains disabled until a later topology decision.

## Capacity And Pooling

PHP-FPM process counts and worker counts remain environment/orchestrator tunables; U3
does not claim capacity without load testing. Scale app, default workers, knowledge
workers, analytics workers and Reverb independently using queue latency, backlog, job
duration, error rate and active WebSocket metrics. No PgBouncer is introduced; an
external pooler can be added later if connection pressure justifies it.

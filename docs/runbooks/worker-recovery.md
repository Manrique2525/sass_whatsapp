# Worker Recovery Runbook

## Normal Checks

Check each process independently:

```bash
docker compose -f docker-compose.production.yml ps
php artisan queue:monitor default,knowledge,analytics
php artisan queue:failed-summary
```

The operator must distinguish queue backlog from a dead worker. A queue can be
temporarily paused without stopping unrelated queues.

## Recovery

1. Confirm the queue, error rate, failed jobs and dependency readiness.
2. Inspect the worker logs without printing payloads or secrets.
3. Use `queue:restart` for a graceful code reload.
4. Recreate only the affected worker service if its process is dead.
5. Confirm its Docker healthcheck is healthy and the queue backlog decreases.
6. Escalate after the `on-failure:5` restart budget is exhausted; do not increase it blindly.

The three worker services are intentionally independent. Stopping `worker-knowledge`
does not stop `default` or `analytics`; knowledge jobs remain queued until that
worker returns. The same contract applies to `worker-analytics`.

## Graceful Shutdown

Workers should finish the current job or let the queue driver make it visible again
after the visibility/retry interval. Do not delete queue keys as a shutdown method.
After a restart, inspect pending, reserved and delayed counts and then inspect
`failed_jobs` through `queue:failed-summary`.

## Failed Jobs

`queue:failed-summary` reports aggregate queue, allowlisted job class, count and timestamp only.
It reads no payload fields other than Laravel's validated `displayName` and never prints the
remaining job payload. Use `queue:retry <uuid>` only after classifying the
failure and confirming the job is safe to replay; use `queue:forget <uuid>` only with
an incident record. `queue:flush` is disposable/rehearsal-only unless an incident
owner explicitly authorizes it.

## Scheduler Recovery

The scheduler is a separate `schedule:work` process. It writes a Redis heartbeat
every minute. A stale heartbeat is visible in `/ready` as `scheduler: stale`, but it
does not make application dependency readiness fail. Restart the scheduler and verify
the heartbeat returns to `ok`.

All scheduled commands use `withoutOverlapping()`. This prevents duplicate execution
on one shared cache. `onOneServer()` is not currently used, so a future horizontally
scaled scheduler deployment must either retain one scheduler replica or add a
shared-lock/`onOneServer()` decision before scaling it.

## Evidence From U3 Rehearsal

- All three queue workers consumed deterministic real job classes from their canonical queues.
- Specialized queue backlog remained at one while its worker was stopped and drained to zero after restart.
- A crashed default worker was recreated and returned healthy.
- A controlled malformed job produced no provider call; failed-job summary was exercised and disposable failed records were flushed.
- Final disposable queue state: pending 0, reserved 0, delayed 0, failed jobs 0.

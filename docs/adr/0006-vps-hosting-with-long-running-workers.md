# Host on a VPS with long-running queue workers

ZRA expects booking reports within 5 minutes and check-ins and check-outs "immediately", and the PRD targets delivery within 1 minute. On cPanel, a cron-started queue worker adds up to 60 seconds before any job begins, and shared hosts commonly kill long-running processes. Karafuu runs on a VPS managed through Laravel Forge or Ploi, with Supervisor keeping queue workers alive and the scheduler running as a daemon. The database queue and cache drivers are kept. Redis is added only when load requires it.

## Considered Options

- **cPanel with mitigations.** Kept as a documented fallback in case the client rejects a VPS: delivery is attempted right after commit inside the web request, and a cron-started worker runs for about 55 seconds each minute. The PRD's latency, availability and recovery targets would then be relaxed. `docs/architecture.md` gives the latency calculation.

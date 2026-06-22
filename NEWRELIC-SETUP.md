# New Relic Integration — Setup Guide

This guide explains how New Relic is wired into this Dockerized Magento 2.4.9 store
and how to set it up on a fresh machine. Integration has **two independent layers**:

1. **APM (New Relic PHP agent)** — installed in the PHP image. Captures transaction
   traces, slow DB queries, errors, throughput and Apdex for every web request.
   This is the core observability layer.
2. **Magento `NewRelicReporting` module** — Adobe's bundled module (already in
   `vendor/`). On a cron schedule it pushes business/system metrics (orders, admin
   activity, catalog & module counts, cache flushes, deploy markers) to New Relic as
   custom events. It complements APM; it does not replace it.

---

## What's in the repo

| File | Purpose |
|------|---------|
| [docker/php/Dockerfile](docker/php/Dockerfile) | Installs the New Relic PHP agent (auto-resolves the latest version; copies `newrelic.so` so it is not a dangling symlink) and wires the entrypoint. |
| [docker/php/newrelic-entrypoint.sh](docker/php/newrelic-entrypoint.sh) | Injects the license key / app name from environment variables into `newrelic.ini` at container start, then hands off to the base `php:apache` entrypoint. Keeps the secret out of the image. |
| [docker-compose.yml](docker-compose.yml) | The `app` service reads `NEW_RELIC_*` settings from a git-ignored `.env` file. |
| [.env.example](.env.example) | Template. Copy to `.env` and fill in your key. |
| `.env` | **Git-ignored.** Holds your real license key. Never committed. |

> **Why an entrypoint instead of relying on env vars directly?** The PHP agent reads
> `newrelic.ini`, and its env-var override is unreliable across versions. The
> entrypoint deterministically writes the values into the ini on every start.

---

## Part A — Create a free New Relic account & get a license key

New Relic's free tier is free forever (100 GB/month ingest, no credit card).

1. Sign up at https://newrelic.com/signup and pick your **data center region**
   (US or EU — remember which; EU needs an extra line in `.env`).
2. Go to **user menu → Administration → API keys**
   (`https://one.newrelic.com/admin-portal/api-keys/home`).
3. You need an **`INGEST - LICENSE`** key. The most reliable way to read its full
   value: click **Create a key** → type **Ingest - License** → name it
   `magento-docker` → **Create**. The full key is shown in plaintext on creation.

A valid license key is **exactly 40 characters** and usually ends in `NRAL`.
It must **not** start with `NRAK-` (that prefix is a *User* key, used later for the
Magento module — not for the agent).

---

## Part B — Configure & build (APM)

1. Copy the template and paste your key:
   ```bash
   cp .env.example .env
   # edit .env -> NEW_RELIC_LICENSE_KEY=<your 40-char key>
   # EU only: also set NEW_RELIC_HOST=collector.eu.nr-data.net
   ```

2. Build and start:
   ```bash
   docker compose build app
   docker compose up -d app
   ```

3. Verify the agent loaded and the license is accepted (should **not** say
   `INVALID FORMAT`):
   ```bash
   docker compose exec app php -m | grep newrelic
   docker compose exec app php -i | grep -E 'newrelic.appname|newrelic.license'
   ```

4. Generate a little traffic (browse `http://localhost:8080/`), then confirm the
   daemon connected:
   ```bash
   docker compose exec app tail -n 20 /var/log/newrelic/newrelic-daemon.log
   ```
   Look for: `app '<APPNAME>' connected with run id ...` and a `Reporting to:` line.

The `Reporting to:` redirect string base64-decodes to
`ACCOUNT_ID|APM|APPLICATION|APP_ID` — that gives you the **Account ID** and
**App ID** needed for Part C without hunting in the UI.

---

## Part C — Enable the Magento NewRelicReporting module

Run `bin/magento` as **www-data** (project convention). Replace the placeholders
with your values (User API key = the `NRAK-…` key; Insights insert key = your
license key, which the Event API accepts):

```bash
docker compose exec -u www-data app bash -lc '
  bin/magento config:set newrelicreporting/general/enable 1
  bin/magento config:set newrelicreporting/general/app_name "Magento 2.4.9 (Local Docker)"
  bin/magento config:set newrelicreporting/general/account_id "YOUR_ACCOUNT_ID"
  bin/magento config:set newrelicreporting/general/app_id "YOUR_APP_ID"
  bin/magento config:set newrelicreporting/general/api "NRAK-YOUR_USER_KEY"
  bin/magento config:set newrelicreporting/general/insights_insert_key "YOUR_LICENSE_KEY"
  bin/magento config:set newrelicreporting/cron/enable_cron 1
  bin/magento cache:flush
'
```

Equivalent admin path:
**Stores → Configuration → General → New Relic Reporting**
(`/admin/admin/system_config/edit/section/newrelicreporting/`).
The API Key / Insights API Key fields display blank because they are stored
encrypted — that is expected; leave blank to keep the stored value.

The module only sends data when Magento cron runs:
```bash
docker compose exec -u www-data app bin/magento cron:run --group=default
```
A successful run shows `magento_newrelicreporting_cron` with `status = success` in
the `cron_schedule` table. For continuous reporting, run cron on a schedule.

---

## Where the data appears in New Relic

- **APM data** (automatic, per request): **one.newrelic.com → APM & Services →**
  your app (e.g. *Magento 2.4.9 (Local Docker)*). Summary, Transactions, Databases,
  Errors Inbox. Data appears ~2–5 min after traffic.
- **Magento business events** (from the module + cron): **Query your data (NRQL)**:
  ```sql
  SELECT * FROM Transaction WHERE appName = 'Magento 2.4.9 (Local Docker)' SINCE 1 hour ago
  SELECT * FROM Orders SINCE 1 day ago
  ```
  (`Orders` events are created when a new order is placed, via the
  `OrderPlaceAfter` observer.)

---

## Troubleshooting

| Symptom | Cause / Fix |
|---|---|
| `Unable to load dynamic library 'newrelic.so' ... No such file or directory` (file exists) | Dangling symlink — the installer symlinked into a `/tmp` dir that was deleted. Fixed by `NR_INSTALL_USE_CP_NOT_LN=1` in the Dockerfile (copy, don't symlink). |
| `newrelic.license => ***INVALID FORMAT***` | The key is not a valid 40-char New Relic license key. A `NRAK-…` user key or a 64-char string will fail. Use the `INGEST - LICENSE` key. |
| `appname` stays `PHP Application` | The agent didn't pick up the env var. The entrypoint script writes it into `newrelic.ini` — confirm the container was rebuilt and restarted. |
| No data in APM | New Relic only shows data after real traffic. Browse the storefront/admin. First dev-mode load is slow — be patient. |
| EU account, no data | Set `NEW_RELIC_HOST=collector.eu.nr-data.net` in `.env` and restart. |

---

## Security note

The `.env` file holds a secret (your license key) and is git-ignored. Never commit
it. If a key is ever exposed, rotate it in New Relic → **API keys** and update
`.env`.

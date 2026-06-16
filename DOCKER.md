# Magento 2.4.9 — Docker Setup, Configuration & Debugging Guide

This document describes how the Dockerized Magento 2.4.9 environment for this project is
installed, configured, run, and troubleshooted. It reflects the **actual** setup in this repo
(`docker-compose.yml`, `docker/`), running on **Windows + Docker Desktop**, alongside an existing
XAMPP stack.

---

## 1. Overview

| Component   | Image / Source                          | Container name       | Host port → Container | Notes |
|-------------|------------------------------------------|----------------------|-----------------------|-------|
| Web/PHP app | `php:8.3-apache` (built via `docker/php/Dockerfile`) | `magento_app`        | `8080 → 80`           | Serves the storefront/admin |
| Database    | `mariadb:11.4`                           | `magento_db`         | `3308 → 3306`         | DB name `magento249`, host name `db` |
| Search      | `opensearchproject/opensearch:3.3.2`     | `magento_opensearch` | `9201 → 9200`         | Single-node, security plugin disabled |

- **Deploy mode:** `developer` (`MAGE_MODE=developer` in `app/etc/env.php`)
- **Storefront:** http://magento249.com:8080/
- **Admin:** http://magento249.com:8080/backendpanel
- The project runs **next to XAMPP** (XAMPP Apache owns host port 80), which is why Docker uses port **8080**.

### Service URLs (from where)
| From            | Database            | OpenSearch              |
|-----------------|---------------------|-------------------------|
| Inside `magento_app` container | `db:3306`          | `opensearch:9200`        |
| From Windows host              | `127.0.0.1:3308`   | `http://localhost:9201`  |

> ⚠️ **Key concept:** containers talk to each other by **service name** on the Docker network
> (`db`, `opensearch`). `localhost` inside a container means *that container itself*, not the host.
> The published ports (`3308`, `9201`) are only for access **from Windows**.

---

## 2. Prerequisites

- **Docker Desktop** for Windows (WSL2 backend recommended).
- The project checked out at `c:\xampp_lite_8_3\www\magento249`.
- A database dump available in the repo: `magento249.sql` (or `magento249.utf8.sql`).
- (Optional) The existing XAMPP stack may keep running — it uses port 80; Docker uses 8080.

---

## 3. Files that make up the Docker setup

```
magento249/
├─ docker-compose.yml            # service definitions (app, db, opensearch)
├─ docker/
│  ├─ php/
│  │  ├─ Dockerfile              # PHP 8.3 + Apache image with Magento extensions
│  │  └─ php.ini                 # custom PHP config (mounted as zz-magento.ini)
│  └─ apache/
│     └─ 000-default.conf        # Apache vhost
├─ app/etc/env.php               # ACTIVE env (Docker: db host = "db")
├─ app/etc/env.php.xampp         # XAMPP variant (db host = "localhost") — swap in for XAMPP
└─ .dockerignore
```

### docker-compose.yml (reference)
```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
    container_name: magento_app
    ports:
      - "8080:80"
    volumes:
      - ./:/var/www/html
    depends_on: [db, opensearch]
    networks: [magento]

  db:
    image: mariadb:11.4
    container_name: magento_db
    environment:
      MARIADB_ALLOW_EMPTY_ROOT_PASSWORD: "1"
      MARIADB_DATABASE: magento249
    command: --max_allowed_packet=128M --log_bin_trust_function_creators=1
    ports:
      - "3308:3306"
    volumes:
      - dbdata:/var/lib/mysql
    networks: [magento]

  opensearch:
    image: opensearchproject/opensearch:3.3.2
    container_name: magento_opensearch
    environment:
      - discovery.type=single-node
      - DISABLE_SECURITY_PLUGIN=true
      - "OPENSEARCH_JAVA_OPTS=-Xms512m -Xmx512m"
    ulimits:
      memlock: { soft: -1, hard: -1 }
      nofile:  { soft: 65536, hard: 65536 }
    ports:
      - "9201:9200"
    volumes:
      - osdata:/usr/share/opensearch/data
    networks: [magento]

volumes:
  dbdata:
  osdata:

networks:
  magento:
    driver: bridge
```

---

## 4. Installation / First-time bring-up

### 4.1 Build & start the stack
```bash
# from the project root (c:\xampp_lite_8_3\www\magento249)
docker compose build
docker compose up -d
docker compose ps          # all three should be "Up"
```

### 4.2 Import the database
```bash
# copy + import the dump into the db container
docker exec -i magento_db sh -c 'exec mariadb -uroot magento249' < magento249.sql
```
> If you hit packet-size errors, the `db` service is already started with `--max_allowed_packet=128M`.

### 4.3 Make sure env.php points at Docker services
`app/etc/env.php` must use the **Docker** hostnames:
```php
'db' => [
    'connection' => [
        'default' => [
            'host'   => 'db',          // NOT localhost
            'dbname' => 'magento249',
            ...
        ],
    ],
],
'MAGE_MODE' => 'developer',
```
(Keep `app/etc/env.php.xampp` with `host => localhost` for when you switch back to XAMPP.)

### 4.4 Wire up OpenSearch (one-time, stored in DB)
From the **app container's** point of view OpenSearch is at `opensearch:9200`:
```bash
docker exec -u www-data magento_app php bin/magento config:set catalog/search/engine opensearch
docker exec -u www-data magento_app php bin/magento config:set catalog/search/opensearch_server_hostname opensearch
docker exec -u www-data magento_app php bin/magento config:set catalog/search/opensearch_server_port 9200
```

### 4.5 Fix writable-directory ownership (critical — see §7.1)
```bash
docker exec magento_app sh -c '
  chown -R www-data:www-data generated var pub/static pub/media &&
  find generated var pub/static pub/media -type d -exec chmod 2775 {} + &&
  find generated var pub/static pub/media -type f -exec chmod 0664 {} +
'
```

### 4.6 Set base URLs + reachable domain
```bash
docker exec -u www-data magento_app php bin/magento config:set web/unsecure/base_url http://magento249.com:8080/
docker exec -u www-data magento_app php bin/magento config:set web/secure/base_url   http://magento249.com:8080/
```
Add to the Windows hosts file (`C:\Windows\System32\drivers\etc\hosts`, needs Administrator):
```
127.0.0.1 magento249.com
```

### 4.7 Upgrade, reindex, flush
```bash
docker exec -u www-data magento_app php bin/magento setup:upgrade
docker exec -u www-data magento_app php bin/magento indexer:reindex
docker exec -u www-data magento_app php bin/magento cache:flush
```

### 4.8 Verify
```bash
ping magento249.com                                   # → 127.0.0.1
curl -s -o /dev/null -w '%{http_code}\n' http://magento249.com:8080/             # → 200
curl -s -o /dev/null -w '%{http_code}\n' http://magento249.com:8080/backendpanel # → 200
```
> The **first** request after a cache flush is slow (dev mode regenerates code on demand over the
> Windows bind mount). Subsequent loads are fast.

---

## 5. Daily operation

| Action | Command |
|--------|---------|
| Start  | `docker compose up -d` |
| Stop   | `docker compose stop` |
| Stop + remove containers | `docker compose down` (volumes `dbdata`/`osdata` persist) |
| Status | `docker compose ps` |
| App logs | `docker compose logs -f app` |
| Shell into app | `docker exec -it -u www-data magento_app bash` |
| Rebuild image after Dockerfile change | `docker compose build app && docker compose up -d` |

---

## 6. ⭐ Golden rules (these prevent 90% of the problems)

1. **Always run `bin/magento` as `www-data`, never root:**
   ```bash
   docker exec -u www-data magento_app php bin/magento <command>
   ```
   Running as root creates root-owned files in `generated/` that the Apache (`www-data`) process
   cannot overwrite → on-demand class generation fails → **500 errors**.

2. **In developer mode, the everyday command is just:**
   ```bash
   docker exec -u www-data magento_app php bin/magento cache:flush
   ```

3. **Do NOT run these in developer mode:** `setup:di:compile`, `setup:static-content:deploy`.
   They are **production-only**, unnecessary in dev (classes/static assets generate on demand), and
   extremely slow on the Windows bind mount. Only run them after `deploy:mode:set production`.

4. Run `setup:upgrade` **only** when modules or DB schema change.

---

## 7. Debugging guide

### 7.1 500 error: `Class "...\Interceptor" / "...\Proxy" does not exist`
**Cause:** generated-code permission problem — `generated/` files are owned by root (from a CLI run
as root) but Apache runs as `www-data` and can't (re)generate them.
**Logs:** `var/log/exception.log` shows `Permission denied` / `Can't create directory .../generated/code/...`.

**Fix:**
```bash
# 1. fix ownership/permissions
docker exec magento_app sh -c '
  chown -R www-data:www-data generated var pub/static pub/media &&
  find generated var pub/static pub/media -type d -exec chmod 2775 {} + &&
  find generated var pub/static pub/media -type f -exec chmod 0664 {} +
'
# 2. clear half-written generated code and let dev mode regenerate
docker exec -u www-data magento_app sh -c 'rm -rf generated/code/* generated/metadata/*'
docker exec -u www-data magento_app php bin/magento cache:flush
```
**Prevention:** follow Golden Rule #1 (always run CLI as `www-data`).

### 7.2 `Could not validate a connection to the OpenSearch. No alive nodes found`
**Cause:** Magento is pointed at the wrong OpenSearch host — usually `localhost` (which, inside the
container, is the app container itself), instead of the service name `opensearch`.

**Check & fix:**
```bash
# is it reachable from inside the app container?
docker exec magento_app sh -c "curl -s -o /dev/null -w '%{http_code}\n' http://opensearch:9200"  # → 200
# correct the config
docker exec -u www-data magento_app php bin/magento config:set catalog/search/opensearch_server_hostname opensearch
docker exec -u www-data magento_app php bin/magento config:set catalog/search/opensearch_server_port 9200
docker exec -u www-data magento_app php bin/magento cache:flush
```

### 7.3 `SQLSTATE[HY000] [2002] ... getaddrinfo for db failed`
**Cause:** DB host in `app/etc/env.php` doesn't match a running service. In Docker it must be `db`.
**Fix:** ensure `env.php` has `'host' => 'db'` (not `localhost`). You may have swapped in the XAMPP
variant by mistake — restore the Docker `env.php`.

### 7.4 Site won't load at `magento249.com:8080`
- **DNS:** `ping magento249.com` must return `127.0.0.1`. If not, add `127.0.0.1 magento249.com` to
  `C:\Windows\System32\drivers\etc\hosts` (Administrator).
- **base_url mismatch:** must include the port:
  ```bash
  docker exec -u www-data magento_app php bin/magento config:show web/unsecure/base_url
  # expect: http://magento249.com:8080/
  ```
- **Redirect loop to https:** ensure `web/secure/base_url` is `http://...` (no TLS in this dev setup).

### 7.5 Port conflict on `up`
- **Port 80** is held by XAMPP Apache (`c:\xampp_lite_8_3\apps\apache\bin\httpd.exe`) — that's why
  Docker uses 8080. Don't map the app to `80:80` unless you stop XAMPP first.
- Find what holds a port (PowerShell):
  ```powershell
  netstat -ano | findstr ":8080"
  Get-Process -Id <PID>
  ```

### 7.6 Container won't start / OpenSearch exits
```bash
docker compose logs opensearch        # look for memory / ulimit / bootstrap errors
docker compose logs db
docker compose logs app
```
OpenSearch is capped at 512MB heap (`OPENSEARCH_JAVA_OPTS`); raise it in `docker-compose.yml` if needed.

### 7.7 Useful diagnostics
```bash
docker compose ps
docker exec -u www-data magento_app php bin/magento deploy:mode:show
docker exec -u www-data magento_app php bin/magento indexer:status
docker exec magento_app sh -c "curl -s http://opensearch:9200/_cat/indices?v"   # expect magento2_product_* indices
docker exec -u www-data magento_app php bin/magento config:show web/unsecure/base_url
docker exec -u www-data magento_app php bin/magento info:adminuri                # admin path (/backendpanel)
```

---

## 8. Switching between XAMPP and Docker

This project can run under either stack. The difference is the DB host in `env.php`:

| Stack  | env.php `host` | DB | Search | URL |
|--------|----------------|----|--------|-----|
| Docker | `db`           | `magento_db` container | `opensearch:9200` | http://magento249.com:8080/ |
| XAMPP  | `localhost`    | local MySQL | local search | (XAMPP vhost, port 80) |

To switch to Docker: copy the Docker `env.php` in; to switch to XAMPP: copy `env.php.xampp` over
`env.php`. Each stack has its **own separate database**, so config set in one does not affect the other.

---

## 9. Performance note (Windows bind mount)

The slow part of this setup is the bind mount (`./:/var/www/html`) — every file write crosses the
Windows↔container boundary. To speed things up significantly:

1. **Move the project into the WSL2 filesystem** (e.g. under `~/` inside the WSL distro) instead of
   `C:\…`. Native ext4 I/O is typically 5–10× faster for Magento.
2. **Put `generated/` and `var/` in named Docker volumes** so heavy class/cache writes stay inside
   the container's fast filesystem instead of the bind mount.

---

## 10. Quick reference card

```bash
# start / stop
docker compose up -d
docker compose stop

# everyday (dev mode)
docker exec -u www-data magento_app php bin/magento cache:flush

# after module/schema change
docker exec -u www-data magento_app php bin/magento setup:upgrade
docker exec -u www-data magento_app php bin/magento cache:flush

# reindex
docker exec -u www-data magento_app php bin/magento indexer:reindex

# shell
docker exec -it -u www-data magento_app bash
```

| What | Value |
|------|-------|
| Storefront | http://magento249.com:8080/ |
| Admin      | http://magento249.com:8080/backendpanel |
| DB (from host) | `127.0.0.1:3308`, db `magento249` |
| OpenSearch (from host) | http://localhost:9201 |
| Deploy mode | developer |

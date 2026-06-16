# 🚀 Install this project on a new machine

A simple, follow-along guide to get this Magento 2.4.9 (Docker) project running on a fresh
computer. Just do the steps in order. 🙂

> 📖 Need deeper detail or troubleshooting? See **[DOCKER.md](DOCKER.md)**.

---

## ⚠️ First, the important bit: cloning is not enough

A few files this project needs are **kept out of git on purpose** (secrets, big files, generated
code). You have to bring these over **from your current machine**:

- [ ] `app/etc/env.php` — your Docker settings (contains secret keys + DB host)
- [ ] `magento249.sql` — the database (your products, config, admin users, etc.)
- [ ] `auth.json` — your Magento Marketplace keys (needed to download dependencies)

> 💡 Tip: copy these 3 files onto a USB stick / cloud drive before you start.

> 🟡 Note: a custom module (`Custom_PaymentGateway`) is **not committed yet**, so a fresh clone
> won't include it. If you need it, ask first and it can be added to git.

---

## 1️⃣ Install the tools (one time)

On the new machine install:
- **Docker Desktop** → https://www.docker.com/products/docker-desktop/ (turn on the WSL2 option)
- **Git** → https://git-scm.com/download/win

Start Docker Desktop and wait until it says **"Engine running"**.

---

## 2️⃣ Get the code

```bash
git clone https://github.com/vikku805/magento249.git
cd magento249
```

---

## 3️⃣ Copy in the 3 files from the checklist

Place them at exactly these locations inside the project folder:

| File | Goes here |
|------|-----------|
| `env.php`   | `app/etc/env.php` |
| `auth.json` | `auth.json` (project root) |
| `magento249.sql` | project root (just for importing in step 6) |

---

## 4️⃣ Start the containers

```bash
docker compose up -d
docker compose ps
```
You should see **3** containers `Up`: `magento_app`, `magento_db`, `magento_opensearch`.

---

## 5️⃣ Install dependencies

```bash
docker exec -u www-data magento_app composer install
```
☕ This downloads Magento's code into `vendor/` and takes a few minutes.

---

## 6️⃣ Load the database

```bash
docker exec -i magento_db sh -c 'exec mariadb -uroot magento249' < magento249.sql
```

---

## 7️⃣ Fix file permissions

(This prevents the "500 error / Class does not exist" problem.)

```bash
docker exec magento_app sh -c '
  chown -R www-data:www-data generated var pub/static pub/media &&
  find generated var pub/static pub/media -type d -exec chmod 2775 {} + &&
  find generated var pub/static pub/media -type f -exec chmod 0664 {} +
'
```

---

## 8️⃣ Make the web address work

Open the hosts file **as Administrator**:
`C:\Windows\System32\drivers\etc\hosts`

Add this line at the bottom and save:
```
127.0.0.1 magento249.com
```

---

## 9️⃣ Finish the setup

```bash
docker exec -u www-data magento_app php bin/magento setup:upgrade
docker exec -u www-data magento_app php bin/magento indexer:reindex
docker exec -u www-data magento_app php bin/magento cache:flush
```

---

## 🔟 Open your site! 🎉

| Page | URL |
|------|-----|
| 🛍️ Storefront | http://magento249.com:8080/ |
| 🔐 Admin | http://magento249.com:8080/backendpanel |

> ⏳ The **first** page load is slow (Magento is warming up). Refresh once — after that it's fast.

---

## ✅ Quick health check

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://magento249.com:8080/
```
`200` = working. 🎉

---

## 🛟 If something goes wrong

| Problem | Quick fix |
|---------|-----------|
| **500 error / "Class ... does not exist"** | Re-run step 7️⃣ (permissions), then `cache:flush` |
| **"No alive nodes found" (search)** | OpenSearch host must be `opensearch` — see DOCKER.md §7.2 |
| **"getaddrinfo for db failed"** | Your `app/etc/env.php` must have `'host' => 'db'` |
| **Site won't open** | Check the hosts file line (step 8️⃣) and that `:8080` is in the URL |
| **Port already in use** | Something else uses 8080 — see DOCKER.md §7.5 |

Full debugging guide: **[DOCKER.md](DOCKER.md) → section 7**.

---

## 📌 Two golden rules (save yourself headaches)

1. **Always run Magento commands as `www-data`:**
   ```bash
   docker exec -u www-data magento_app php bin/magento <command>
   ```
   (Running as root breaks file permissions → 500 errors.)

2. **For everyday work, you usually only need:**
   ```bash
   docker exec -u www-data magento_app php bin/magento cache:flush
   ```
   Don't run `di:compile` or `static-content:deploy` in this dev setup — they're slow and not needed.

---

Happy building! 🚀

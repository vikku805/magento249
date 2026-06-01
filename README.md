# Magento 2.4.9 Local Setup (Windows + XAMPP Lite + OpenSearch)

## Overview

This repository contains a local Magento Open Source 2.4.9 installation configured on:

* Windows 11
* XAMPP Lite 8.3
* PHP 8.3
* MariaDB
* OpenSearch 3.3.2

---

# What's New in Magento 2.4.9

Magento Open Source 2.4.9 includes:

* PHP 8.3 / 8.4 / 8.5 support
* OpenSearch compatibility improvements
* Security enhancements
* Performance optimizations
* Dependency upgrades (Symfony 7.4, Laminas updates)
* GraphQL improvements
* Platform stability fixes
* Updated Composer dependencies

Official Release Notes:
https://experienceleague.adobe.com/en/docs/commerce-operations/release/notes

---

# Prerequisites

## PHP Extensions

Verify:

```bash
php -m
```

Required extensions:

```text
bcmath
curl
dom
exif
fileinfo
gd
intl
mbstring
openssl
pdo_mysql
SimpleXML
soap
sodium
xsl
zip
```

---

# Install XAMPP Lite

Download:

https://sourceforge.net/projects/xampplite/

Install to:

```text
C:\xampp_lite_8_3
```

---

# Configure PHP

File:

```text
C:\xampp_lite_8_3\apps\php\php.ini
```

Verify:

```ini
extension=gd
extension=intl
extension=soap
extension=sockets
extension=xsl
extension=zip
extension=exif
```

Add PHP to Windows PATH:

```text
C:\xampp_lite_8_3\apps\php
```

Verify:

```bash
php -v
composer -V
```

---

# Install OpenSearch

Download:

https://opensearch.org/downloads.html

Install Java:

https://adoptium.net/

Edit:

```text
C:\opensearch-3.3.2\config\opensearch.yml
```

Add:

```yaml
plugins.security.disabled: true
discovery.type: single-node
```

Start:

```powershell
cd C:\opensearch-3.3.2\bin
.\opensearch.bat
```

Verify:

```text
http://localhost:9200
```

---

# Create Database

```sql
CREATE DATABASE magento249;
```

---

# Download Magento 2.4.9

```bash
composer create-project --repository-url=https://repo.magento.com/ magento/project-community-edition magento249
```

Verify:

```bash
php bin/magento --version
```

Expected:

```text
Magento CLI 2.4.9
```

---

# Magento Installation

PowerShell:

```powershell
php -d memory_limit=-1 bin/magento setup:install `
--base-url=http://magento249.com/ `
--db-host=localhost `
--db-name=magento249 `
--db-user=root `
--db-password="" `
--admin-firstname=Admin `
--admin-lastname=User `
--admin-email=admin@example.com `
--admin-user=admin `
--admin-password=Admin@12345 `
--language=en_US `
--currency=USD `
--timezone=Asia/Kolkata `
--use-rewrites=1 `
--search-engine=opensearch `
--opensearch-host=localhost `
--opensearch-port=9200
```

---

# Apache Configuration

## File

```text
C:\xampp_lite_8_3\apps\apache\conf\httpd.conf
```

Enable:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
Include conf/extra/httpd-vhosts.conf
```

---

# Virtual Host Configuration

File:

```text
C:\xampp_lite_8_3\apps\apache\conf\extra\httpd-vhosts.conf
```

```apache
<VirtualHost *:80>
    ServerName magento249.com
    ServerAlias www.magento249.com

    DocumentRoot "C:/xampp_lite_8_3/www/magento249/pub"

    <Directory "C:/xampp_lite_8_3/www/magento249/pub">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

---

# Hosts File

File:

```text
C:\Windows\System32\drivers\etc\hosts
```

Add:

```text
127.0.0.1 magento249.com
127.0.0.1 www.magento249.com
```

---

# Post Installation Commands

```bash
php bin/magento deploy:mode:set developer

php bin/magento cache:flush

php bin/magento indexer:reindex

php bin/magento setup:di:compile
```

---

# Verify Installation

Frontend:

```text
http://magento249.com
```

Admin:

```text
http://magento249.com/admin
```

OpenSearch:

```text
http://localhost:9200
```

---

# Troubleshooting

## RewriteBase Error

Error:

```text
RewriteBase takes one argument
```

Fix:

Restore Magento's original `.htaccess` and verify Apache rewrite module is enabled.

---

## OpenSearch Not Starting

Add:

```yaml
plugins.security.disabled: true
discovery.type: single-node
```

to `opensearch.yml`.

---

## Admin 2FA Email Issue (Local Development)

Disable:

```bash
php bin/magento module:disable Magento_TwoFactorAuth
php bin/magento cache:flush
```

---

# Screenshots

## Storefront

(Add screenshot here)

## Admin Dashboard

(Add screenshot here)

## OpenSearch Running

(Add screenshot here)

---

# Environment

| Component  | Version    |
| ---------- | ---------- |
| Magento    | 2.4.9      |
| PHP        | 8.3        |
| MariaDB    | 11.x       |
| OpenSearch | 3.3.2      |
| XAMPP Lite | 8.3        |
| OS         | Windows 11 |

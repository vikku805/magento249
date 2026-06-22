#!/bin/sh
# Inject runtime New Relic settings into newrelic.ini before the agent starts.
# Keeps the license key out of the image: values come from container env vars
# (see docker-compose.yml / .env). Runs on every container start.
set -e

NR_INI="/usr/local/etc/php/conf.d/newrelic.ini"

if [ -f "$NR_INI" ]; then
    if [ -n "$NEW_RELIC_LICENSE_KEY" ]; then
        sed -i "s|^[;[:space:]]*newrelic.license[[:space:]]*=.*|newrelic.license = \"${NEW_RELIC_LICENSE_KEY}\"|" "$NR_INI"
    fi
    if [ -n "$NEW_RELIC_APPNAME" ]; then
        sed -i "s|^[;[:space:]]*newrelic.appname[[:space:]]*=.*|newrelic.appname = \"${NEW_RELIC_APPNAME}\"|" "$NR_INI"
    fi
    if [ -n "$NEW_RELIC_ENABLED" ]; then
        sed -i "s|^[;[:space:]]*newrelic.enabled[[:space:]]*=.*|newrelic.enabled = ${NEW_RELIC_ENABLED}|" "$NR_INI"
    fi
    # EU data center only: point the agent + daemon at the EU collector.
    if [ -n "$NEW_RELIC_HOST" ]; then
        sed -i "s|^[;[:space:]]*newrelic.daemon.collector_host[[:space:]]*=.*|newrelic.daemon.collector_host = \"${NEW_RELIC_HOST}\"|" "$NR_INI"
    fi
fi

# Hand off to the base php:apache entrypoint (which runs apache2-foreground).
exec docker-php-entrypoint "$@"

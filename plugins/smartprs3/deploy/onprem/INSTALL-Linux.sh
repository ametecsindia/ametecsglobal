#!/usr/bin/env bash
# SmartPRS3 - on-premises installer (Linux). Run as root from this folder:  sudo bash INSTALL-Linux.sh
set -e
cd "$(dirname "$0")"
APP="$(pwd)"
trap 'echo; echo "[X] FAILED at: $BASH_COMMAND - read the error above, fix it, run this again (safe to repeat)."' ERR
echo "=== SmartPRS3 - on-premises installer (Linux) - see VERSION.txt ==="
command -v php >/dev/null || { echo "PHP not found. Install PHP 8.3+ and retry."; exit 1; }

echo "STEP 0: checking the server and the extracted files"
php _install.php verify

FRESH=""
[ -f .env ] || { cp .env.example .env; FRESH=fresh; }
mkdir -p storage/updates storage/keys public/uploads

echo "STEP 1: database and its accounts";    php _install.php db $FRESH

echo "STEP 2: application key";              php _install.php key
echo "STEP 3: database tables";              php tools/db/migrate.php --no-seed
echo "STEP 4: database rights";              php tools/db/grant-application-account.php --apply
echo "STEP 5: can the application connect"; php tools/db/verify-connection.php

WEB=www-data; id "$WEB" >/dev/null 2>&1 || WEB=nginx
echo "STEP 6: permissions ($WEB) and background jobs"
chown -R "$WEB":"$WEB" "$APP"; chmod 640 .env
CRON="*/5 * * * * php $APP/tools/cron.php >> $APP/storage/cron.log 2>&1
*/5 * * * * php $APP/tools/db/schedule.php timers >> $APP/storage/cron.log 2>&1
15 6 * * * php $APP/tools/db/schedule.php absence >> $APP/storage/cron.log 2>&1
20 6 * * * php $APP/tools/db/schedule.php unauthorized-absence >> $APP/storage/cron.log 2>&1
30 1 * * * php $APP/tools/db/schedule.php archive >> $APP/storage/cron.log 2>&1
45 23 * * * php $APP/tools/db/schedule.php anchor >> $APP/storage/cron.log 2>&1"
( crontab -u "$WEB" -l 2>/dev/null | grep -v "$APP/tools/" ; echo "$CRON" ) | crontab -u "$WEB" -

echo
echo "Done. Web root = $APP/public (nginx: deploy/nginx-smartprs3-routes.conf has the routes)."
echo "Restart php-fpm and the web server, open the site: /activate (License Code or .lic),"
echo "then the sign-in page asks you to create the administrator."

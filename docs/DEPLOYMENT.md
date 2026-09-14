# Deployment

## Layout

- Code: `/var/www/yfs-ai`
- Env: `/var/www/yfs-ai/.env` (do not commit)
- Web root: `/var/www/yfs-ai/public`
- nginx: `/etc/nginx/sites-available/yfs-ai`

## Code updates

```bash
cd /var/www/yfs-ai
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
```

Do **not** run `php artisan db:seed` or any Mousse seeder.

Create or rotate the admin user:

```bash
php artisan owl-admin:make-admin --email=admin@youngfashionshow.com --name="YFS Admin"
```

## Permissions

```bash
# after log files created by php-fpm as www-data, keep group-writable
chmod 775 storage storage/framework storage/logs bootstrap/cache
chmod 664 storage/logs/*.log
```

`public/storage` must point to `/var/www/yfs-ai/storage/app/public` (not any other project).

## Cron (user `deploy`)

Existing jobs for `jfs` and `yfs-ai-sorter` must stay. YFS AI adds exactly two lines:

```cron
* * * * * cd /var/www/yfs-ai && /usr/bin/php artisan schedule:run >> /var/www/yfs-ai/storage/logs/scheduler.log 2>&1
* * * * * flock -n /tmp/yfs-ai-queue-work.lock -c 'cd /var/www/yfs-ai && /usr/bin/php artisan queue:work --sleep=1 --tries=3 --timeout=180 --max-time=3600' >> /var/www/yfs-ai/storage/logs/queue-work.log 2>&1
```

Merge only. Never replace the whole crontab with `crontab -` from a partial file.

Backup of the previous crontab: `storage/app/private/crontab-deploy.before.txt`

Scheduled commands (from `routes/console.php`):

- `instagram:tokens:refresh` daily 03:15
- `facebook:tokens:check` daily 03:30
- `bot:send-follow-ups` every 5 minutes
- `bot:retry-missed-replies` every 2 minutes
- `bot:reenable-after-manual-inactivity` hourly
- `instagram:reconcile-conversations` every 5 minutes
- `ai:purge-analysis-telemetry --days=90` daily 04:10

Supervisor is not used for YFS AI.

## nginx / HTTPS

After editing only `/etc/nginx/sites-available/yfs-ai`:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Certificate renewal is the system Certbot timer. Do not alter other site certificates.

## Health checks

```bash
curl -sI http://ai.youngfashionshow.com          # 301 → HTTPS
curl -sI https://ai.youngfashionshow.com         # 302 → /login
curl -sI https://ai.youngfashionshow.com/login   # 200
php artisan about
php artisan migrate:status
```

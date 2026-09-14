# Server

## Host

- Server: `5.78.224.87`
- Project path: `/var/www/yfs-ai`
- Domain: `ai.youngfashionshow.com`
- Owner: `deploy:www-data`

## Installed stack on this host

- Ubuntu
- nginx
- PHP 8.3 / php8.3-fpm (`unix:/run/php/php8.3-fpm.sock`)
- MySQL 8
- Composer 2.10
- Node 20 / npm
- Supervisor (used by other projects only)
- Certbot / Let's Encrypt

## This site

- nginx config: `/etc/nginx/sites-available/yfs-ai`
- enabled symlink: `/etc/nginx/sites-enabled/yfs-ai`
- document root: `/var/www/yfs-ai/public`
- access log: `/var/log/nginx/yfs-ai_access.log`
- error log: `/var/log/nginx/yfs-ai_error.log`
- HTTPS: Let's Encrypt certificate for `ai.youngfashionshow.com`
- HTTP → HTTPS redirect is active

## Other production projects on the same server

These are independent and must not be modified by YFS AI deploys:

- `/var/www/jfs` — `app.youngfashionshow.com`
- `/var/www/fashion-planner` — `planner.youngfashionshow.com`
- `/var/www/yfs-ai-sorter` — `ai-sorting.youngfashionshow.com`
- `/var/www/packages`

## PHP / Laravel

- Laravel 13.19
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://ai.youngfashionshow.com`

Credentials are only in `/var/www/yfs-ai/.env` (`640`, `deploy:www-data`). They are not stored in this documentation.

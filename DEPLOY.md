# WealthDash — Deployment Guide

## Quick Start (XAMPP / Local)

```bash
# 1. Clone to htdocs
cd C:/xampp/htdocs
git clone <repo> wealthdash

# 2. Copy env
cp .env.example .env
# Edit .env: DB_HOST, DB_USER, DB_PASS, APP_SECRET, APP_URL

# 3. Import DB
mysql -u root wealthdash < database/01_schema_complete.sql
mysql -u root wealthdash < database/02_seed.sql

# 4. Run migrations (in order)
# database/migrations/*.sql — import all in filename order

# 5. Set permissions (Linux/Mac)
chmod -R 755 .
chmod -R 777 logs/ storage/

# 6. Visit: http://localhost/wealthdash
```

---

## Docker (Recommended for Production)

```bash
# 1. Copy env
cp .env.example .env
# Edit APP_SECRET, DB_PASS

# 2. Start
docker compose up -d

# 3. Check
docker compose ps
curl http://localhost:8080/api/router.php?action=health_ping

# 4. With phpMyAdmin (dev only)
docker compose --profile dev up -d

# Access:
# App:         http://localhost:8080
# phpMyAdmin:  http://localhost:8081
```

### Docker Commands

```bash
docker compose logs -f app          # App logs
docker compose exec app bash        # Shell into app
docker compose exec db mysql -u wealthdash -p wealthdash  # MySQL shell
docker compose down                 # Stop
docker compose down -v              # Stop + delete volumes (DESTRUCTIVE)
docker compose build --no-cache     # Rebuild image
```

---

## Production (Apache + Ubuntu)

### Prerequisites
```bash
apt install php8.2 php8.2-{mysql,mbstring,zip,gd,intl,xml,bcmath,opcache} \
            mysql-server apache2 composer
```

### Apache VirtualHost
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/wealthdash
    DirectoryIndex index.php

    <Directory /var/www/wealthdash>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  /var/log/apache2/wealthdash_error.log
    CustomLog /var/log/apache2/wealthdash_access.log combined
</VirtualHost>
```

### Nginx (alternative)
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/wealthdash;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\. { deny all; }
    location ~* \.(sql|log|env|md)$ { deny all; }
}
```

### Security Checklist
- [ ] `APP_ENV=production` in `.env`
- [ ] `APP_SECRET` is a strong random string (32+ chars)
- [ ] `.env` not web-accessible (`.htaccess` already blocks it)
- [ ] `debug/` folder removed or IP-restricted
- [ ] HTTPS enabled (Let's Encrypt)
- [ ] MySQL user has only SELECT/INSERT/UPDATE/DELETE (not DROP/CREATE)
- [ ] `logs/` not web-accessible
- [ ] OPcache enabled in `php.ini`
- [ ] Session cookie `secure=true` (auto when `APP_ENV != local`)

---

## Cron Jobs

```bash
# Edit crontab
crontab -e

# NAV daily update (9:30 PM IST)
30 21 * * 1-5 php /var/www/wealthdash/cron/update_nav_daily.php >> /var/www/wealthdash/logs/cron.log 2>&1

# FD maturity alerts (9 AM daily)
0 9 * * * php /var/www/wealthdash/cron/fd_maturity_alert.php >> /var/www/wealthdash/logs/cron.log 2>&1

# Monthly summary (1st of month, 8 AM)
0 8 1 * * php /var/www/wealthdash/cron/monthly_summary.php >> /var/www/wealthdash/logs/cron.log 2>&1

# Return calculations (daily, 10 PM)
0 22 * * * php /var/www/wealthdash/cron/calculate_returns.php >> /var/www/wealthdash/logs/cron.log 2>&1
```

---

## Environment Variables (.env)

```env
APP_NAME=WealthDash
APP_ENV=local
APP_URL=http://localhost/wealthdash
APP_SECRET=your_32_char_random_secret_here
APP_TIMEZONE=Asia/Kolkata

DB_HOST=localhost
DB_PORT=3306
DB_NAME=wealthdash
DB_USER=root
DB_PASS=

SESSION_LIFETIME=86400
SESSION_COOKIE_NAME=wd_session
LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_MINUTES=15
```

---

## Migration Order

Run all migrations in this order after initial schema import:

```bash
# Run all at once (Linux/Mac)
for f in database/migrations/t*.sql; do
    echo "Running $f..."
    mysql -u root wealthdash < "$f"
done
```

---

## Health Check

```bash
curl http://localhost/api/router.php?action=health_ping
# {"success":true,"data":{"db":true,"php":"8.2.x","memory_mb":4.2,...}}
```

Visit `/debug/debug.php` (local only) for full system check.

# PMS — Server Setup Guide

Production-grade, multi-tenant Property Management System API.

**Stack:** PHP 8.2+ · Apache 2.4 · MySQL 8.0 · PayHero (M-Pesa)

---

## 1. Prerequisites

```bash
sudo apt update
sudo apt install -y apache2 mysql-server \
    php php-cli php-mysql php-curl php-mbstring php-xml php-gd \
    composer git unzip
sudo a2enmod rewrite headers ssl
sudo systemctl restart apache2
```

Required PHP extensions: `pdo_mysql`, `curl`, `mbstring`, `json`, `gd`
(GD is used by `endroid/qr-code`).

---

## 2. Deploy the code

```bash
sudo mkdir -p /var/www/html/api
sudo chown -R "$USER":www-data /var/www/html/api
# copy the contents of this api/ directory into /var/www/html/api
cd /var/www/html/api
composer install --no-dev --optimize-autoloader
```

Make the logs directory writable by the web server:

```bash
sudo mkdir -p /var/www/html/api/logs
sudo chown -R www-data:www-data /var/www/html/api/logs
sudo chmod 750 /var/www/html/api/logs
```

---

## 3. MySQL users (principle of least privilege)

Create **two** users:

```sql
-- API runtime user: data only, NO DDL.
CREATE USER 'pms_user'@'localhost' IDENTIFIED BY 'STRONG_RUNTIME_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON `pms_global`.* TO 'pms_user'@'localhost';
-- Grant the same on the client DB name pattern. MySQL supports wildcards in
-- GRANT using the underscore escape; grant per-DB after each provision, or:
GRANT SELECT, INSERT, UPDATE, DELETE ON `pms\_client\_%`.* TO 'pms_user'@'localhost';

-- Provisioning user: used ONLY by createClient() to build new client DBs.
CREATE USER 'pms_provisioner'@'localhost' IDENTIFIED BY 'STRONG_PROVISIONER_PASSWORD';
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES,
      SELECT, INSERT, UPDATE, DELETE ON *.* TO 'pms_provisioner'@'localhost';

FLUSH PRIVILEGES;
```

> The runtime user deliberately lacks `CREATE`, `ALTER`, `DROP`, and `GRANT`.
> Only `pms_provisioner` can create databases.

---

## 4. Create the global database

```bash
mysql -u root -p < /var/www/html/api/setup/install.sql
```

This creates `pms_global`, all tables, seed settings, and a default super admin:

- **Email:** `admin@pms.co.ke`
- **Password:** `ChangeMe123!`  ← change immediately after first login.

To set your own super-admin password, generate a hash and update the row:

```bash
php -r "echo password_hash('YourNewPassword', PASSWORD_BCRYPT, ['cost'=>12]);"
```
```sql
UPDATE pms_global.super_admins SET password='<hash>' WHERE email='admin@pms.co.ke';
```

---

## 5. Environment configuration

```bash
cp /var/www/html/api/.env.example /var/www/html/api/.env
sudo chown www-data:www-data /var/www/html/api/.env
sudo chmod 640 /var/www/html/api/.env
```

Fill in real values for `DB_*`, `DB_ROOT_*` (provisioner), `PAYHERO_*`,
`SMTP_*`, `CORS_ALLOWED_ORIGIN`, `PUBLIC_BASE_URL`, and
`PAYHERO_ALLOWED_IPS` (PayHero's callback source IPs).

---

## 6. Apache virtual host

`/etc/apache2/sites-available/pms.conf`:

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem

    <Directory /var/www/html/api>
        AllowOverride All
        Require all granted
    </Directory>

    # Do not log the Authorization header.
    LogFormat "%h %l %u %t \"%r\" %>s %b" pms_safe
    CustomLog ${APACHE_LOG_DIR}/pms_access.log pms_safe
    ErrorLog  ${APACHE_LOG_DIR}/pms_error.log
</VirtualHost>

<VirtualHost *:80>
    ServerName yourdomain.com
    Redirect permanent / https://yourdomain.com/
</VirtualHost>
```

```bash
sudo a2ensite pms.conf
sudo systemctl reload apache2
```

Set `display_errors = Off` in production `php.ini`.

---

## 7. Crontab

```bash
sudo tee /etc/cron.d/pms >/dev/null <<'CRON'
*/15 * * * * www-data php /var/www/html/api/cron/purge_tokens.php >/dev/null 2>&1
0 8 * * *    www-data php /var/www/html/api/cron/send_reminders.php >/dev/null 2>&1
0 0 * * *    www-data php /var/www/html/api/cron/reconcile_payments.php >/dev/null 2>&1
CRON
```

---

## 8. First login (smoke test)

```bash
curl -s -X POST https://yourdomain.com/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@pms.co.ke","password":"ChangeMe123!"}'
```

A successful response returns a 64-char `token`. Use it as
`Authorization: Bearer <token>` on subsequent calls.

Create your first client (provisions their DB automatically):

```bash
curl -s -X POST https://yourdomain.com/api/superadmin/clients \
  -H "Authorization: Bearer <token>" \
  -H 'Content-Type: application/json' \
  -d '{"name":"John Mwangi","email":"john@example.com","phone":"0712345678","role":"landlord","service_fee_pct":5.0}'
```

---

## 9. Security checklist

- [ ] `.env` is `chmod 640`, owned by `www-data`, never committed.
- [ ] `pms_user` has only `SELECT, INSERT, UPDATE, DELETE`.
- [ ] HTTPS enforced (HTTP → HTTPS redirect in `.htaccess` + vhost).
- [ ] `CORS_ALLOWED_ORIGIN` set to your real domain (not `*`).
- [ ] `PAYHERO_ALLOWED_IPS` configured with PayHero's callback IPs.
- [ ] `display_errors = Off`, `APP_DEBUG=false` in production.
- [ ] Default super-admin password changed.
- [ ] `logs/` writable by `www-data`, not web-accessible (blocked in `.htaccess`).

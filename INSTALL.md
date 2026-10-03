# ACES System — Installation Guide

This document explains how to install the ACES Activity Tracking System. It covers two scenarios:

- **Local development** (Windows + XAMPP) — for developers, groupmates, and testing
- **Production deployment** (Linux server) — for the ACES Unit's IT staff

Read the Quick Start below if you just want to run the system on your own machine.

---

## Quick Start — Local Development (Windows + XAMPP)

If you just want to run ACES locally for development or testing, follow these 5 steps. **You can skip every section below marked "Production only."**

### 1. Install XAMPP

Download from https://www.apachefriends.org and install. Then open the XAMPP Control Panel and click **Start** next to both **Apache** and **MySQL**. Both should show green.

### 2. Clone the project into htdocs

XAMPP only serves files inside `C:\xampp\htdocs`. Open PowerShell:

```powershell
cd C:\xampp\htdocs
git clone https://github.com/Bicomong-ElijahRei/ACES.git cair-system
cd cair-system
```

### 3. Create the database and import the dump

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS aces_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root aces_db -e "source C:/xampp/htdocs/cair-system/database/aces_db.sql"
```

> **Note:** PowerShell does not support the `<` redirection operator — that's why we use MySQL's `source` directive. The path must use **forward slashes** (`C:/...`), not backslashes.

### 4. Create the environment file

```powershell
copy .env.example .env
```

Open `.env` in VS Code and set these values for local development:

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=aces_db
DB_USER=root
DB_PASS=

APP_URL=http://localhost/cair-system
APP_ENV=development
APP_DEBUG=true
```

Leave the `MAIL_*` values blank if you don't need email features (registration verification, password reset). Everything else will work.

### 5. Open in browser

```
http://localhost/cair-system/login.php
```

Log in with a default account:

| Email | Password | Role |
|-------|----------|------|
| `staff@aces.edu` | `password` | admin |
| `adminstaff@aces.edu` | `password` | admin |

That's it. You do **not** need sections 4, 6, 7, or 8 below for local development — those are for production servers.

---

## Requirements

### Server
- Apache 2.4+ (or XAMPP for local development)
- PHP 8.0 or higher
- MySQL 8.0+ / MariaDB 10.4+
- 2 GB RAM minimum
- 5 GB storage

### PHP Extensions
- pdo, pdo_mysql
- openssl
- fileinfo
- mbstring
- json

---

## Setup Steps (Production)

The sections below are for deploying on a real web server. If you're doing local development, see the Quick Start above.

### 1. Clone the Project

```bash
git clone https://github.com/Bicomong-ElijahRei/ACES.git cair-system
cd cair-system
```

---

### 2. Create the Database and Import the Dump

A full schema + data dump is included in the repo at `database/aces_db.sql`.

**Option A — Command line (recommended):**

On Linux / macOS (bash):

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS aces_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p aces_db < database/aces_db.sql
```

On Windows (PowerShell):

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS aces_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root aces_db -e "source C:/xampp/htdocs/cair-system/database/aces_db.sql"
```

**Option B — phpMyAdmin:**

1. Open `http://localhost/phpmyadmin`
2. Click **New** → name it `aces_db` → Collation `utf8mb4_unicode_ci` → **Create**
3. Click `aces_db` → **Import** tab → **Choose File** → `database/aces_db.sql` → **Go**

**Test data warning:** The included dump contains demo/test data (sessions prefixed with `TEST:`, sample students, sample staff). It also contains the following default staff accounts, all with the password `password`:

| Email | Role |
|-------|------|
| `staff@aces.edu` | admin |
| `adminstaff@aces.edu` | admin |
| `lead.staff@kld.edu.ph` | lead |
| `viewer.staff@kld.edu.ph` | viewer |

Change all passwords immediately after first login. Do not deploy to production without doing this.

**Strict-mode warning:** On MySQL 8.0+ / MariaDB 10.6+ the default `sql_mode` includes `STRICT_TRANS_TABLES`, which rejects the placeholder date `'0000-00-00'` present in one test row. If the import fails with:

```
ERROR 1525 (HY000): Incorrect DATE value: '0000-00-00'
```

Either:

1. Run this before the import in the same session:

```sql
SET SESSION sql_mode = '';
```

2. Or edit `database/aces_db.sql` and replace the `0000-00-00` value with a valid date (e.g. `2026-06-05`), then re-import.

XAMPP on Windows uses a lenient `sql_mode` by default, so this error does not appear locally — only on production Linux servers.

---

### 3. Configure Environment

```bash
cp .env.example .env
nano .env
```

Fill in these values in `.env`:

| Key | Purpose |
|-----|---------|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | Gmail SMTP (enable 2FA, then generate an App Password at https://myaccount.google.com/apppasswords) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender identity |
| `CSRF_SECRET`, `CRON_SECRET` | Random 64-character strings — generate with `openssl rand -hex 32` |
| `SESSION_IDLE_TIMEOUT` | Idle timeout in seconds (default `3600`) |
| `APP_URL` | The base URL of the deployment (e.g. `https://aces.kld.edu.ph`) |
| `APP_ENV` | `development` locally, `production` on the live server |
| `APP_DEBUG` | `true` locally, `false` on the live server |

---

### 4. Set File Permissions (Production only — Linux)

```bash
chown -R www-data:www-data /var/www/html/cair-system
chmod -R 755 /var/www/html/cair-system
chmod -R 775 /var/www/html/cair-system/uploads
chmod 600 /var/www/html/cair-system/.env
```

---

### 5. Verify Database Migrations (optional — safety check)

The included `database/aces_db.sql` already contains every table and column the system needs, so this step is normally unnecessary. Run it only if you imported an older dump, or you want to be certain the schema is complete.

```sql
-- Password reset support
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME NULL;

-- Email verification expiry
ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_expires DATETIME NULL;

-- Remember-me tokens
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector VARCHAR(24) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_selector (selector),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Login attempt tracking (brute-force protection)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(100) NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

### 6. Configure Apache (Production only — Linux)

Create a virtual host file at `/etc/apache2/sites-available/aces.conf`:

```apache
<VirtualHost *:80>
    ServerName aces.kld.edu.ph
    DocumentRoot /var/www/html/cair-system

    <Directory /var/www/html/cair-system>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/aces_error.log
    CustomLog ${APACHE_LOG_DIR}/aces_access.log combined
</VirtualHost>
```

Enable it:

```bash
sudo a2ensite aces.conf
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

---

### 7. Enable HTTPS (Production only — Linux)

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d aces.kld.edu.ph
```

Then uncomment the HTTPS redirect block in the root `.htaccess` file.

---

### 8. Set Up Cron Jobs (Production only — Linux)

Open the crontab:

```bash
crontab -e
```

Add these two lines:

```
0 * * * * /usr/bin/php /var/www/html/cair-system/cron/auto_assign.php
0 */6 * * * /usr/bin/php /var/www/html/cair-system/cron/send_reminders.php
```

Both scripts authenticate with the `CRON_SECRET` value from `.env`. If your cron implementation passes the secret as a query parameter, use:

```
0 * * * * /usr/bin/curl -s "https://aces.kld.edu.ph/cron/auto_assign.php?secret=YOUR_CRON_SECRET" > /dev/null
0 */6 * * * /usr/bin/curl -s "https://aces.kld.edu.ph/cron/send_reminders.php?secret=YOUR_CRON_SECRET" > /dev/null
```

---

### 9. Create the Initial Super Admin (optional — a default admin exists)

The imported dump already contains an admin account at `staff@aces.edu` with password `password`. If you prefer to create a fresh one instead:

Generate a bcrypt hash of the password:

```bash
php -r "echo password_hash('YourStrongPassword', PASSWORD_BCRYPT, ['cost' => 12]);"
```

Then insert the admin user in phpMyAdmin:

```sql
INSERT INTO users (email, password_hash, full_name, role, staff_role, is_verified, is_active)
VALUES (
    'admin@kld.edu.ph',
    'PASTE_THE_HASH_HERE',
    'ACES Admin',
    'staff',
    'admin',
    1,
    1
);
```

Log in at your deployment URL + `/login.php` (e.g. `http://localhost/cair-system/login.php` locally, or the domain assigned by IT in production) to verify.

---

## Post-Installation Checklist

### Functional

- [ ] Home page loads
- [ ] Login works with an admin account
- [ ] Student registration creates a new account
- [ ] Verification email arrives
- [ ] Password reset flow works
- [ ] Staff can create sessions and subtopics
- [ ] Students can select subtopics
- [ ] File uploads work and are validated
- [ ] CSV export opens in Excel without encoding issues
- [ ] PDF export produces clean tables
- [ ] Cron jobs run (check `logs/cron.log`) — production only

### Security

- [ ] All default passwords changed (especially `staff@aces.edu`, `adminstaff@aces.edu`)
- [ ] `.env` is not web-accessible (`https://yoursite/.env` → 403)
- [ ] `config/database.php` is not web-accessible (`https://yoursite/config/database.php` → 403)
- [ ] PHP execution blocked in `uploads/` (`https://yoursite/uploads/test.php` → 403)
- [ ] `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- [ ] `APP_URL` matches the actual deployed domain
- [ ] HTTPS enforced and cert valid — production only
- [ ] Gmail App Password rotated from any value used during development
- [ ] `CSRF_SECRET` and `CRON_SECRET` regenerated (do not reuse defaults)

### Data

- [ ] Test data (`TEST:` sessions, sample students) removed or hidden
- [ ] Database backup strategy in place

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Database connection failed | Check `.env` credentials; ensure MySQL is running |
| Import fails: `Incorrect DATE value '0000-00-00'` | Run `SET SESSION sql_mode='';` before import, or edit the invalid date in the dump |
| PowerShell error: `< operator is reserved for future use` | Use `mysql -e "source path/to/file.sql"` instead of `<` redirection |
| Uploads permission denied | `chmod -R 775 uploads/` |
| Emails not sending | Verify Gmail App Password; 2FA must be enabled |
| CSRF token failed | Ensure sessions work; check `session.save_path` is writable |
| 500 error | Check `logs/error.log`; temporarily set `APP_DEBUG=true` in `.env` |
| Cannot modify header info | No whitespace before `<?php` in any file |
| Remember-me cookie not persisting | Ensure HTTPS is enabled (cookie is `Secure` when `APP_ENV=production`) |

---

## Support

Contact: ACES Unit Head, Kolehiyo ng Lungsod ng Dasmariñas
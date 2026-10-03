# ACES System — Installation Guide

This document explains how to install the ACES Activity Tracking System. It covers three scenarios:

- **Local development** (Windows + XAMPP) — for developers, groupmates, and testing
- **Shared hosting** (cPanel) — for the ACES Unit's web host, if they have one
- **Production VPS** (Linux server) — for a dedicated server managed by IT staff

Read the Quick Start below if you just want to run the system on your own machine.

---

## Quick Start — Local Development (Windows + XAMPP)

If you just want to run ACES locally for development or testing, follow these 5 steps. **You can skip every section below marked "Production only" or "Shared hosting."**

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

That's it. You do **not** need the shared hosting or production sections below for local development.

---

## Shared Hosting Deployment (cPanel)

If the ACES Unit has shared hosting (a cPanel-based host like Hostinger, Bluehost, Namecheap, or a local Philippine provider), follow this section. You do **not** need SSH access or terminal skills — everything is done through cPanel's web interface.

### Prerequisites

Confirm with the hosting provider:

- PHP 8.0 or higher (set via cPanel's **Select PHP Version** tool)
- MySQL or MariaDB database
- At least 500 MB disk space
- cPanel access (username and password)

### Step 1 — Log in to cPanel

Your host will provide a cPanel URL, usually:

```
https://yourdomain.com:2083
```

Or:

```
https://yourdomain.com/cpanel
```

Log in with the cPanel username and password from your hosting provider.

### Step 2 — Create the database and user

1. In cPanel, scroll to the **Databases** section and click **MySQL Databases**.
2. Under **Create New Database**, enter a name (e.g. `aces_db`) and click **Create Database**.
3. Scroll down to **MySQL Users**. Enter a username and a strong password. Click **Create User**. Save these credentials.
4. Scroll to **Add User to Database**. Select your new user and database, click **Add**.
5. On the next screen, check **ALL PRIVILEGES** and click **Make Changes**.

> **Important:** cPanel prefixes database and user names with your account name. If your cPanel username is `kld`, the actual database name will be `kld_aces_db` and the user will be `kld_acesuser`. **Use the full prefixed names** — not the short ones — when configuring `.env`.

### Step 3 — Upload the project files

**Option A — File Manager (easiest):**

1. In cPanel, open **File Manager**.
2. Navigate to `public_html/` (your main domain root). If deploying to a subdomain, navigate to that subdomain's folder instead.
3. Click **Upload** and upload a ZIP archive of the ACES project.

   > **Don't have a ZIP?** On your local machine, right-click the `cair-system` folder → **Send to** → **Compressed (zipped) folder**. Exclude `.env` and `.git` from the archive.

4. Once uploaded, go back to File Manager, right-click the ZIP file, and click **Extract**.
5. After extraction, if the files are inside a subfolder (e.g. `public_html/cair-system/`), either move them up to `public_html/` directly, or plan to access the app at `https://yourdomain.com/cair-system/`.
6. Delete the ZIP file after extraction.

**Option B — FTP (for larger uploads or incremental updates):**

1. Install an FTP client like FileZilla.
2. Connect using the FTP credentials from cPanel (same username/password, host = your domain or the shared IP shown in cPanel's right sidebar).
3. Navigate to `public_html/`.
4. Upload the project files.

> **PHP file execution note:** If you upload to `public_html/` directly, your app will be at the root of the domain. If you upload to `public_html/cair-system/`, the URL will be `https://yourdomain.com/cair-system/`. Both work — just make sure `APP_URL` in `.env` matches.

### Step 4 — Import the database

1. In cPanel, go to **Databases** → **phpMyAdmin**.
2. In the left sidebar, click your newly created database (e.g. `kld_aces_db`).
3. Click the **Import** tab at the top.
4. Click **Choose File** and select `database/aces_db.sql` from your local machine.
5. Scroll down and click **Go**.

> **File size limit:** Most cPanel hosts limit phpMyAdmin imports to 128 MB. The ACES dump is ~120 KB, so it will import fine. If you ever have a larger dump, zip it first (`.sql.gz`) — phpMyAdmin accepts compressed files.

> **Strict-mode warning:** If the import fails with `ERROR 1525 (HY000): Incorrect DATE value: '0000-00-00'`, run this in phpMyAdmin's SQL tab **before** importing:

```sql
SET SESSION sql_mode = '';
```

> Or edit `database/aces_db.sql` and replace the `0000-00-00` value with a valid date (e.g. `2026-06-05`), then re-import.

### Step 5 — Set the PHP version and enable extensions

1. In cPanel, scroll to the **Software** section and click **Select PHP Version**.
2. Choose **PHP 8.0**, **8.1**, **8.2**, or **8.3** from the dropdown (whichever is available and at least 8.0).
3. Click the **Extensions** tab.
4. Enable these extensions if they're not already checked:

   - `pdo`
   - `pdo_mysql`
   - `mbstring`
   - `openssl`
   - `fileinfo`
   - `json`
   - `ctype`

5. Changes save automatically — no Save button needed.

> **MultiPHP Manager:** If your host uses **MultiPHP Manager** instead of **Select PHP Version**, set the domain's PHP version there, then use **MultiPHP INI Editor** to adjust limits if needed.

### Step 6 — Create the `.env` file

1. In cPanel File Manager, navigate to your project folder.
2. Click **Settings** (top-right corner of File Manager).
3. Check **Show Hidden Files (dotfiles)** and click **Save**.
4. You should now see `.env.example`. Right-click it → **Copy** → paste it as `.env` (or right-click `.env.example` → **Edit**, then save as `.env`).
5. Right-click `.env` → **Edit**. Update these values:

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=kld_aces_db
DB_USER=kld_acesuser
DB_PASS=your_strong_password_here

APP_URL=https://yourdomain.com
APP_ENV=production
APP_DEBUG=false

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your_16_char_app_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME=ACES Unit - KLD

SESSION_IDLE_TIMEOUT=3600
CSRF_SECRET=generate_a_random_64_char_string
CRON_SECRET=generate_a_random_64_char_string
```

> **Security note:** Never commit `.env` to GitHub. It stays on the server only.

> **Setting `APP_ENV=production` and `APP_DEBUG=false` is critical** — it disables detailed error messages that would leak file paths and database structure to visitors.

### Step 7 — Set file permissions

1. In File Manager, right-click the `uploads/` folder → **Change Permissions**.
2. Set permissions to `755` (or `775` if `755` doesn't work).
3. Check **Recurse into subdirectories** and **Apply to directory contents**.
4. Do the same for `logs/` if it exists.
5. For `.env`, set permissions to `600` (owner read/write only).

> **Note:** On shared hosting, you usually can't run `chown` or `chmod` commands. File Manager's permission tool is the equivalent.

### Step 8 — Enable HTTPS (AutoSSL)

Most cPanel hosts include **AutoSSL** (free Let's Encrypt certificates). To enable it:

1. In cPanel, go to **Security** → **SSL/TLS Status**.
2. If your domain doesn't have a certificate, click **Run AutoSSL**.
3. Wait a few minutes for issuance.

Once HTTPS is active, uncomment the redirect block in the root `.htaccess` file:

1. File Manager → `.htaccess` → **Edit**.
2. Find the block with `RewriteCond %{HTTPS} off` and remove the `#` from the start of those lines.
3. Save.

Alternatively, use cPanel's **Domains** → **Redirects** tool to force HTTPS.

### Step 9 — Set up cron jobs

1. In cPanel, scroll to **Advanced** → **Cron Jobs**.
2. Under **Add New Cron Job**, select **Once Per Hour** from the Common Settings dropdown.
3. In the Command field, enter:

```
/usr/local/bin/php /home/kld/public_html/cron/auto_assign.php
```

   > Replace `kld` with your actual cPanel username, and adjust the path to match where you uploaded the project.

4. Click **Add New Cron Job**.
5. Repeat for the second job, using **Once Per 6 Hours** as the frequency:

```
/usr/local/bin/php /home/kld/public_html/cron/send_reminders.php
```

> **Cron path note:** The PHP binary path on shared hosting is often `/usr/local/bin/php` or `/usr/bin/php`. If the cron job doesn't run, check cPanel's **Cron Jobs** page for the exact PHP path — some hosts display it.

> **CRON_SECRET alternative:** If your host runs PHP via CGI/FastCGI instead of CLI, use the curl-based approach instead:

```
/usr/bin/curl -s "https://yourdomain.com/cron/auto_assign.php?secret=YOUR_CRON_SECRET" > /dev/null
```

### Step 10 — Test the deployment

Open your browser and visit:

```
https://yourdomain.com/login.php
```

Or, if you uploaded to a subfolder:

```
https://yourdomain.com/cair-system/login.php
```

Log in with a default account:

| Email | Password | Role |
|-------|----------|------|
| `staff@aces.edu` | `password` | admin |
| `adminstaff@aces.edu` | `password` | admin |

**Immediately after first login, change all default passwords.** See the Security Checklist below.

### Shared hosting troubleshooting

| Issue | Fix |
|-------|-----|
| Blank white page | Check PHP version (must be 8.0+). Enable `display_errors` temporarily via **Select PHP Version** → **Options** tab. |
| Database connection failed | Verify the full prefixed database name and username in `.env` (e.g. `kld_aces_db`, not `aces_db`). |
| `ERR_TOO_MANY_REDIRECTS` | Comment out the HTTPS redirect block in `.htaccess` — your host may already handle HTTPS. |
| 500 Internal Server Error | Check `error_log` in File Manager. Common cause: wrong file permissions on `.htaccess` (should be `644`). |
| Uploads fail | Set `uploads/` folder permissions to `755` or `775` via File Manager. |
| Emails not sending | Verify Gmail App Password is correct, 2FA is enabled on the Gmail account, and `MAIL_ENCRYPTION=tls`. |

---

## Production VPS Deployment (Linux server)

The sections below are for deploying on a Linux VPS with SSH access (DigitalOcean, Linode, AWS, or a school-managed server).

### 1. Clone the Project

```bash
git clone https://github.com/Bicomong-ElijahRei/ACES.git cair-system
cd cair-system
```

### 2. Create the Database and Import the Dump

A full schema + data dump is included in the repo at `database/aces_db.sql`.

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS aces_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p aces_db < database/aces_db.sql
```

> **Strict-mode warning:** On MySQL 8.0+ / MariaDB 10.6+ the default `sql_mode` includes `STRICT_TRANS_TABLES`, which rejects the placeholder date `'0000-00-00'` present in one test row. If the import fails with `ERROR 1525 (HY000)`, run `SET SESSION sql_mode = '';` before the import, or edit the invalid date in the dump.

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

### 4. Set File Permissions

```bash
chown -R www-data:www-data /var/www/html/cair-system
chmod -R 755 /var/www/html/cair-system
chmod -R 775 /var/www/html/cair-system/uploads
chmod 600 /var/www/html/cair-system/.env
```

### 5. Verify Database Migrations (optional — safety check)

The included `database/aces_db.sql` already contains every table and column the system needs, so this step is normally unnecessary.

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

### 6. Configure Apache

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

### 7. Enable HTTPS

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d aces.kld.edu.ph
```

Then uncomment the HTTPS redirect block in the root `.htaccess` file.

### 8. Set Up Cron Jobs

```bash
crontab -e
```

Add these two lines:

```
0 * * * * /usr/bin/php /var/www/html/cair-system/cron/auto_assign.php
0 */6 * * * /usr/bin/php /var/www/html/cair-system/cron/send_reminders.php
```

### 9. Create the Initial Super Admin (optional — a default admin exists)

The imported dump already contains an admin account at `staff@aces.edu` with password `password`. If you prefer to create a fresh one:

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
- [ ] Cron jobs run (check `logs/cron.log`)

### Security

- [ ] All default passwords changed (especially `staff@aces.edu`, `adminstaff@aces.edu`)
- [ ] `.env` is not web-accessible (`https://yoursite/.env` → 403)
- [ ] `config/database.php` is not web-accessible (`https://yoursite/config/database.php` → 403)
- [ ] PHP execution blocked in `uploads/` (`https://yoursite/uploads/test.php` → 403)
- [ ] `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- [ ] `APP_URL` matches the actual deployed domain
- [ ] HTTPS enforced and cert valid
- [ ] Gmail App Password rotated from any value used during development
- [ ] `CSRF_SECRET` and `CRON_SECRET` regenerated (do not reuse defaults)

### Data

- [ ] Test data (`TEST:` sessions, sample students) removed or hidden
- [ ] Database backup strategy in place

---

## Deployment Handover

When handing this system over to the ACES Unit's IT staff, provide:

- This repository (GitHub URL or zipped copy without `.env` and `.git`)
- `database/aces_db.sql` — the schema and seed data
- `.env.example` — template for their own credentials
- The list of default accounts they must change (see above)
- A copy of this `INSTALL.md`
- Contact info for the research team during the warranty period

**The IT staff is responsible for:**
- Provisioning the server and domain
- Configuring HTTPS
- Setting up automated backups
- Changing all default passwords before go-live

**The research team is responsible for:**
- Delivering this documentation
- Training ACES staff on system use
- Providing bug fixes during the agreed warranty period

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Database connection failed | Check `.env` credentials; ensure MySQL is running |
| Import fails: `Incorrect DATE value '0000-00-00'` | Run `SET SESSION sql_mode='';` before import, or edit the invalid date in the dump |
| PowerShell error: `< operator is reserved for future use` | Use `mysql -e "source path/to/file.sql"` instead of `<` redirection |
| Blank white page (shared hosting) | Check PHP version (8.0+); enable `display_errors` temporarily via Select PHP Version |
| Database name wrong (shared hosting) | cPanel prefixes names — use the full name (e.g. `kld_aces_db`) |
| Uploads permission denied | `chmod -R 775 uploads/` (or set 755/775 via cPanel File Manager) |
| Emails not sending | Verify Gmail App Password; 2FA must be enabled |
| CSRF token failed | Ensure sessions work; check `session.save_path` is writable |
| 500 error | Check `logs/error.log`; temporarily set `APP_DEBUG=true` in `.env` |
| Cannot modify header info | No whitespace before `<?php` in any file |
| Remember-me cookie not persisting | Ensure HTTPS is enabled (cookie is `Secure` when `APP_ENV=production`) |

---

## Support

Contact: ACES Unit Head, Kolehiyo ng Lungsod ng Dasmariñas
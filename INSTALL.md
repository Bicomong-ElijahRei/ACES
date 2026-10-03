# ACES System — Installation Guide

This document explains how to install the ACES Activity Tracking System on a web server. It is meant for the IT staff or developer deploying the system for the ACES Unit.

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

## Setup Steps

### 1. Clone the Project

```bash
git clone https://github.com/Bicomong-ElijahRei/ACES.git cair-system
cd cair-system
```

### 2. Create the Database

Open phpMyAdmin or MySQL command line and run:

```sql
CREATE DATABASE aces_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Import the schema (contact the development team for the SQL dump).

### 3. Configure Environment

```bash
cp .env.example .env
nano .env
```

Fill in these values in `.env`:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — Database credentials
- `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` — Gmail SMTP (enable 2FA on the Gmail account, then generate an App Password)
- `CSRF_SECRET`, `CRON_SECRET` — random 64-character strings
- `APP_URL` — the production URL (e.g., `https://aces.kld.edu.ph`)

### 4. Set File Permissions (Linux only)

```bash
chown -R www-data:www-data /var/www/html/cair-system
chmod -R 755 /var/www/html/cair-system
chmod -R 775 /var/www/html/cair-system/uploads
chmod 600 /var/www/html/cair-system/.env
```

### 5. Run Database Migrations

These SQL statements add the columns and tables that ACES needs. Run them once after the initial database import:

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

Open the crontab:

```bash
crontab -e
```

Add these two lines:

```
0 * * * * /usr/bin/php /var/www/html/cair-system/cron/auto_assign.php
0 */6 * * * /usr/bin/php /var/www/html/cair-system/cron/send_reminders.php
```

### 9. Create the Initial Super Admin

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

Log in at `https://aces.kld.edu.ph/login.php` to verify.

---

## Post-Installation Checklist

- [ ] Home page loads
- [ ] Login works with the Super Admin account
- [ ] Student registration creates a new account
- [ ] Verification email arrives
- [ ] Password reset flow works
- [ ] Staff can create sessions
- [ ] Students can select subtopics
- [ ] File uploads work and are validated
- [ ] Cron jobs run (check `logs/cron.log`)

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Database connection failed | Check `.env` credentials; ensure MySQL is running |
| Uploads permission denied | `chmod -R 775 uploads/` |
| Emails not sending | Verify Gmail App Password; 2FA must be enabled |
| CSRF token failed | Ensure sessions work; check `session.save_path` |
| 500 error | Check `logs/error.log`; set `APP_DEBUG=true` temporarily |
| Cannot modify header info | No whitespace before `<?php` in any file |

---

## Support

Contact: ACES Unit Head, Kolehiyo ng Lungsod ng Dasmariñas
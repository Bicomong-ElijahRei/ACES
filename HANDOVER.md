# ACES System — Handover Document

**Project:** Development of Alumni Career, Enhancement and Services (ACES) Integration of Student Activity and Process Management System

**Institution:** Kolehiyo ng Lungsod ng Dasmariñas (KLD)

**Client:** ACES Unit — Unit Head, Ms. Judea Arthuree Aquino

**Prepared by:** Elijah Rei Z. Bicomong, John Kenneth D. Ecay, Jessica G. Encarguez, Michelle M. Guirao

**Date:** October 2026

---

## 1. Project Summary

The ACES System is a web-based platform for managing student activity sessions, module delivery, attendance tracking, and compliance reporting for the ACES Unit at KLD. It serves approximately 2,300 fourth-year students (BSIS and BS Psychology) and the ACES staff.

The system replaces a manual bingo-card process with a digital workflow covering session preregistration, subtopic selection, module completion, attendance validation, and automated report generation.

**What was delivered:**

- Complete working system (PHP 8+, MySQL, Bootstrap 5)
- Full source code on GitHub: `https://github.com/Bicomong-ElijahRei/ACES`
- Database schema + data dump: `database/aces_db.sql`
- Installation guide: `INSTALL.md`
- Environment template: `.env.example`
- This handover document

**What was not delivered:**

- Production server provisioning
- Domain registration and DNS configuration
- HTTPS certificate setup
- Data migration from legacy Excel/manual records
- Mobile native application
- Integration with the school registrar system

---

## 2. Access & Credentials

### Repository

| Item | Location |
|------|----------|
| GitHub Repository | `https://github.com/Bicomong-ElijahRei/ACES` |
| Repository Owner | Elijah Rei Z. Bicomong |
| Access Method | GitHub account (collaborator invite) |

### Default Accounts (Included in Database Dump)

| Email | Password | Role | Staff Role |
|-------|----------|------|------------|
| `staff@aces.edu` | `password` | staff | admin |
| `adminstaff@aces.edu` | `password` | staff | admin |
| `lead.staff@kld.edu.ph` | `password` | staff | lead |
| `viewer.staff@kld.edu.ph` | `password` | staff | viewer |

**Critical:** Change all passwords immediately after first login.

### Environment Variables (Not Included — Must Be Created)

The `.env` file is **not** committed to the repository. The IT staff must create it from `.env.example` and fill in their own credentials.

| Variable | Purpose |
|----------|---------|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Gmail SMTP |
| `CSRF_SECRET`, `CRON_SECRET` | Security tokens |
| `APP_URL`, `APP_ENV`, `APP_DEBUG` | Application configuration |

### Credentials to Rotate Before Production

| Credential | Action |
|------------|--------|
| Gmail App Password | Generate a new one for the production Gmail account |
| `CSRF_SECRET` | Generate a new 64-character random string |
| `CRON_SECRET` | Generate a new 64-character random string |
| All default passwords | Change via Staff → Account Settings |

---

## 3. Architecture Overview

### Technology Stack

| Layer | Technology |
|-------|------------|
| Frontend | HTML5, CSS3, JavaScript, Bootstrap 5, Tailwind CSS |
| Backend | PHP 8.0+ |
| Database | MySQL 8.0+ / MariaDB 10.4+ |
| Local Development | XAMPP (Apache + MySQL + PHP) |
| Email | PHPMailer via Gmail SMTP |
| PDF Generation | FPDF |

### Key Directories

| Path | Purpose |
|------|---------|
| `config/` | Environment loader, database connection, email config |
| `includes/` | Shared functions: auth, CSRF, session, upload, email |
| `staff/` | Staff-facing pages (dashboard, sessions, attendance, reports) |
| `student/` | Student-facing pages (dashboard, sessions, modules, progress) |
| `public/` | Public pages (registration, verification, password reset) |
| `api/` | AJAX endpoints for live data |
| `cron/` | Scheduled tasks (auto-assignment, reminders) |
| `database/` | SQL schema and data dump |
| `migrations/` | Individual migration scripts (historical) |
| `uploads/` | User-uploaded files (gitignored) |
| `lib/` | Third-party libraries (PHPMailer, FPDF) |

### Database Tables (18)

| Table | Purpose |
|-------|---------|
| `users` | All accounts (students + staff) |
| `students` | Student profile data |
| `sessions` | ACES activity sessions |
| `subtopics` | Session subtopics |
| `subtopic_section_capacity` | Per-section capacity limits |
| `registrations` | Student subtopic registrations |
| `attendance` | Attendance records |
| `compliance` | Completion tracking |
| `modules` | Learning modules and assessments |
| `student_module_progress` | Student module completion |
| `distribution_rules` | Auto-assignment rules |
| `holidays` | Holiday calendar |
| `school_events` | School event calendar |
| `login_logs` | Login history |
| `login_attempts` | Brute-force tracking |
| `remember_tokens` | Remember-me cookies |
| `staff_action_log` | Staff management audit trail |
| `reminders_sent` | Reminder delivery tracking |

### Security Features

| Feature | Status |
|---------|--------|
| CSRF protection on all POST endpoints | ✅ |
| Bcrypt password hashing (cost 12) | ✅ |
| Session regeneration on login | ✅ |
| Idle session timeout | ✅ |
| Remember-me with token rotation | ✅ |
| File upload validation (extension + MIME + size) | ✅ |
| Rate limiting on staff creation | ✅ |
| 24-hour expiry on verification/reset tokens | ✅ |
| `.htaccess` blocks sensitive files | ✅ |
| PHP execution blocked in uploads | ✅ |
| Output escaping with `htmlspecialchars` | ✅ |
| Audit logging | ✅ |

---

## 4. Runbook

### 4.1 Deploying a Code Change

```bash
# On the local machine
cd C:\xampp\htdocs\cair-system
git add .
git commit -m "Description of change"
git push origin main
```

```bash
# On the production server
cd /var/www/html/cair-system
git pull origin main
# If .env or database changed, re-import or update as needed
```

For shared hosting (cPanel): upload changed files via File Manager or FTP.

### 4.2 Applying a Database Migration

If a new migration file is added to `migrations/`:

1. Open phpMyAdmin
2. Select the `aces_db` database
3. Click the **SQL** tab
4. Paste the contents of the migration file
5. Click **Go**

Alternatively, if the migration is idempotent:

```bash
mysql -u root -p aces_db < migrations/003_new_migration.sql
```

### 4.3 Resetting the Database (Development Only)

```bash
# Drop and recreate
mysql -u root -p -e "DROP DATABASE aces_db;"
mysql -u root -p -e "CREATE DATABASE aces_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p aces_db < database/aces_db.sql
```

### 4.4 Running Cron Jobs Manually

```bash
php /path/to/cair-system/cron/auto_assign.php
php /path/to/cair-system/cron/send_reminders.php
```

Or via curl (if CRON_SECRET is set):

```bash
curl -s "https://yourdomain.com/cron/auto_assign.php?secret=YOUR_CRON_SECRET"
```

### 4.5 Checking Logs

| Log | Location | Purpose |
|-----|----------|---------|
| PHP errors | `logs/error.log` | Application errors |
| Cron log | `logs/cron.log` | Scheduled task output |
| Apache errors | `/var/log/apache2/error.log` | Web server errors |
| Apache access | `/var/log/apache2/access.log` | Request history |

### 4.6 Troubleshooting Common Issues

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| Blank white page | PHP error suppressed | Set `APP_DEBUG=true` in `.env` temporarily |
| Database connection failed | Wrong `.env` credentials | Verify `DB_*` values |
| Emails not sending | Gmail App Password invalid | Regenerate at https://myaccount.google.com/apppasswords |
| Uploads fail | Directory permissions | `chmod -R 775 uploads/` |
| CSRF token failed | Session not persisting | Check `session.save_path` is writable |
| 500 error | `.htaccess` issue | Check permissions (should be `644`) |
| Remember-me not working | HTTPS not enabled | Cookie is `Secure` in production |

---

## 5. Known Limitations & Future Work

### Deliberate Limitations

| Limitation | Reason |
|------------|--------|
| No RFID / QR / biometric attendance | Hardware not available in scope |
| No LMS integration | Standalone system by design |
| No automated clearance triggers | Clearance processing remains manual |
| No mobile native app | Responsive web only |
| No registrar sync | Data entry is manual |

### Known Technical Debt

| Item | Location | Impact |
|------|----------|--------|
| Session 52 has invalid date `0000-00-00` | `database/aces_db.sql` | Import fails on strict-mode MySQL; documented fix in `INSTALL.md` |
| Test data present in dump | `database/aces_db.sql` | Cosmetic clutter; harmless |
| Default passwords in dump | `users` table | Must be changed before production |

### Recommended Future Enhancements

1. **Data migration tool** — import legacy student data from Excel
2. **Mobile app** — React Native or Flutter wrapper for student access
3. **Hardware attendance** — QR code or RFID integration
4. **Analytics dashboard** — deeper reporting on student participation
5. **Automated backups** — scheduled `mysqldump` to cloud storage
6. **LMS integration** — sync with Moodle or Google Classroom
7. **Two-factor authentication** — for staff accounts

---

## 6. User Roles & Manuals

### Student

| Action | Steps |
|--------|-------|
| Register | Go to `/register.php` → fill form → verify email |
| Log in | Enter student ID or email + password |
| Choose subtopic | Go to Sessions → select session → choose subtopic |
| Complete module | Open module → read/watch → mark complete |
| View progress | Go to Progress → see completion percentage |

### Staff (Viewer)

| Action | Steps |
|--------|-------|
| Log in | Enter email + password |
| View dashboard | See session analytics and at-risk students |
| View student progress | Go to Student Progress → select student |

### Staff (Lead)

| Action | Steps |
|--------|-------|
| All Viewer actions | — |
| Create sessions | Dashboard → Sessions → New Session |
| Create subtopics | Sessions → select session → Add Subtopic |
| Manage modules | Modules → Create Module or Assessment |
| Take attendance | Attendance → select session → mark present/absent |
| Export reports | Reports → choose report type → export CSV/PDF |

### Staff (Admin)

| Action | Steps |
|--------|-------|
| All Lead actions | — |
| Manage staff accounts | Manage Staff → create/edit/deactivate |
| View audit log | Manage Staff → Activity Log |

---

## 7. Support & Warranty

### Support Window

The research team will provide bug fixes for **[X] months** after handover, starting **[date]** and ending **[date]**.

- **Contact:** [Insert email]
- **Response time:** Within [X] business days
- **Scope:** Bugs in functionality described in this document. New features are out of scope.

### Escalation

For issues that block ACES Unit operations:

1. Contact the research team lead: [Name, email]
2. If unavailable, contact the BSIS Department: [Insert contact]
3. For infrastructure issues (server down, domain expired), contact the school's IT department directly

### Out of Scope

- Training of new staff (beyond initial training session)
- Custom feature development
- Third-party integration (LMS, registrar, payments)
- Server maintenance
- Content creation (modules, assessments)

---

## 8. Handover Acceptance

By accepting this handover, the ACES Unit confirms receipt of:

- [ ] Source code repository access
- [ ] Database schema and seed data
- [ ] Installation guide (`INSTALL.md`)
- [ ] User manual (`USER_MANUAL.md` — if provided)
- [ ] This handover document
- [ ] Initial training session completed

**Handed over by:**

Name: ____________________________
Signature: ____________________________
Date: ____________________________

**Accepted by:**

Name: ____________________________
Position: ____________________________
Signature: ____________________________
Date: ____________________________

---

## 9. Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | October 2026 | Elijah Rei Z. Bicomong | Initial handover document |

---

**End of handover document.**
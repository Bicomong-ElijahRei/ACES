# ACES Activity Tracking and Preregistration System

A centralized, web-based platform for the Alumni Career, Enhancement, and Services (ACES) Unit at Kolehiyo ng Lungsod ng Dasmariñas.

## 📌 What It Does
Replaces manual "bingo cards" and fragmented Google Forms with:
- **Preregistration** — Students pick subtopics within deadlines.
- **Automated Session Distribution** — Even-split capacity management.
- **Digital Attendance Verification** — Staff-anchored to physical signatures.
- **Module-Based Compliance** — Auto-marks attendance when all modules are complete.
- **Real-Time Progress Dashboards** — Per-student and per-session views.
- **Role-Based Access Control** — Student / Staff / Super Admin.
- **Audit Logging** — Every administrative action is tracked.
- **Export Reports** — CSV + PDF for clearance processing.
- **Institutional Policies** — Built-in Terms and Data Privacy page.

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, JavaScript, Bootstrap 5, Tailwind CSS (v3.4.17) |
| Backend | PHP 8.0+ |
| Database | MySQL 8.0 / MariaDB 10.4+ |
| Server | Apache 2.4+ |
| Libraries | PHPMailer (email), FPDF (PDF generation) |

## 🚀 Quick Start

See [INSTALL.md](INSTALL.md) for full setup instructions.

```bash
# 1. Clone the repository
git clone https://github.com/Bicomong-ElijahRei/ACES.git
cd cair-system

# 2. Copy environment template
cp .env.example .env

# 3. Edit .env with your database + SMTP credentials
nano .env

# 4. Create the database and import the dump
mysql -u root -e "CREATE DATABASE IF NOT EXISTS aces_db CHARACTER SET utf8mb4"
mysql -u root aces_db -e "source database/aces_db.sql"

# 5. Compile Tailwind CSS (Crucial: run this if you change any HTML classes)
.\tailwindcss.exe -i .\assets\css\tailwind.src.css -o .\assets\css\tailwind.css --minify

# 6. Serve with Apache (XAMPP or LAMP)
# Access at http://localhost/cair-system
```

## ⚠️ Important Note for Maintainers
- **Default Passwords:** All default test accounts use the password `password`. Change these immediately upon deployment.
- **Tailwind CSS:** The system uses a **locally compiled** Tailwind CSS file. Do not delete `assets/css/tailwind.css` or the `tailwindcss.exe` compiler. 
- **Policies:** The Terms and Data Privacy page (`public/policies.php`) contains placeholder text. KLD IT must replace this with the official institutional policies before public launch.
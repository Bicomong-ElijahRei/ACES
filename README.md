# ACES Activity Tracking and Preregistration System

A centralized, web-based platform for the Alumni Career, Enhancement, and Services (ACES) Unit at Kolehiyo ng Lungsod ng Dasmariñas.

## 🎯 What It Does

Replaces manual "bingo cards" and fragmented Google Forms with:

- **Preregistration** — Students pick subtopics within deadlines
- **Automated Session Distribution** — Even-split capacity management
- **Digital Attendance Verification** — Staff-anchored to physical signatures
- **Module-Based Compliance** — Auto-marks attendance when all modules complete
- **Real-Time Progress Dashboards** — Per-student and per-session views
- **Role-Based Access Control** — Student / Staff / Super Admin
- **Audit Logging** — Every administrative action tracked
- **Export Reports** — CSV + PDF for clearance processing

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Frontend | HTML5, CSS3, JavaScript, Bootstrap 5, Tailwind CSS |
| Backend | PHP 8.0+ |
| Database | MySQL 8.0 / MariaDB 10.4+ |
| Server | Apache 2.4+ |
| Libraries | PHPMailer (email), FPDF (PDF generation) |

## 🚀 Quick Start

See [INSTALL.md](INSTALL.md) for full setup instructions.

```bash
# 1. Clone the repository
git clone https://github.com/Bicomong-ElijahRei/ACES.git
cd ACES

# 2. Copy environment template
cp .env.example .env

# 3. Edit .env with your database + SMTP credentials
nano .env

# 4. Create the database (see INSTALL.md)
mysql -u root -e "CREATE DATABASE aces_db CHARACTER SET utf8mb4"

# 5. Import the schema (once available)
# mysql -u root aces_db < schema.sql

# 6. Serve with Apache (XAMPP or LAMP)
# Access at http://localhost/cair-system
# ACES System — User Manual

**System:** Alumni Career, Enhancement and Services (ACES) Integration of Student Activity and Process Management System

**Institution:** Kolehiyo ng Lungsod ng Dasmariñas (KLD)

**Audience:** Students, ACES staff, and administrators

**Version:** 1.2 — October 2026

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Getting Started](#2-getting-started)
3. [Student Manual](#3-student-manual)
4. [Staff Manual](#4-staff-manual)
5. [Common Tasks & FAQ](#5-common-tasks--faq)
6. [Troubleshooting](#6-troubleshooting)

---

## 1. Introduction

The ACES System is the official platform for managing ACES activity sessions at KLD. It handles:

- Preregistration for ACES sessions
- Subtopic selection
- Learning module delivery
- Attendance tracking
- Progress and compliance reporting

This manual explains how to use every feature, organized by user role. Students should read Section 3. Staff should read Section 4.

**Logging in:** Your access URL will be provided by the ACES Unit (e.g. `https://aces.kld.edu.ph` for production, or `http://localhost/cair-system` for local testing).

> **Mobile Ready:** The entire system is fully responsive. You can access all features comfortably using the browser on your smartphone. If the navigation menu is hidden, tap the **Hamburger icon (☰)** in the top-left corner to open the sidebar.

---

## 2. Getting Started

### 2.1 First-Time Student Registration

1. Open the login page and click **Register** (or go directly to `/register.php`).
2. Fill in the registration form:

   | Field | Notes |
   |-------|-------|
   | **Student ID** | e.g. `2023-2-009999` — as shown on your school ID |
   | **Full Name** | First name, middle name, last name |
   | **Email** | Use an active email — you'll need it for verification. Must end in `@kld.edu.ph`. |
   | **Program** | BS Information Systems or BS Psychology |
   | **Section** | Your block section (e.g. `412`) |
   | **Mobile / Telephone** | Optional but recommended |
   | **Username** | Optional. If left blank, you must use your Student ID to log in. |
   | **Password** | Minimum 6 characters — mix of letters and numbers |

3. **Terms & Data Privacy:** At the bottom of the form, check the box to agree to the Terms and Conditions and Data Privacy Policy. You can click the links to read them in a new tab.
4. Click **Create Account**.
5. Check your email inbox for a verification message from ACES.
6. Click the verification link inside the email (valid for 24 hours).
7. You'll be redirected to the login page. Log in with your email/Student ID and password.

**Didn't receive the email?**
- Check your spam folder.
- Make sure you typed your email correctly.
- Wait 5 minutes and try again.
- If still nothing, contact the ACES Unit.

### 2.2 Logging In

1. Go to the login page.
2. Enter your **Student Number** or **Email** (students) / **Email** (staff).
3. Enter your **password**. *(Tip: You can click the **eye icon** inside the password box to show/hide your password.)*
4. Optional: Check **Remember me** to stay logged in for up to 30 days.
5. Click **Log In**.

You'll be redirected to your dashboard based on your role:
- Students → `student/dashboard.php`
- Staff → `staff/dashboard.php`

> **Deactivated Accounts:** If your account has been temporarily disabled by the ACES Super Admin, you will see a specific error message stating your account is deactivated. You must contact the ACES Unit to have your access restored.

### 2.3 Forgot Password

1. On the login page, click **Forgot Password?**
2. Enter your registered email address.
3. Click **Send Reset Link**.
4. Check your email — the reset link is valid for **24 hours**.
5. Click the link, enter a new password twice, and submit.
6. Log in with your new password.

### 2.4 Changing Your Password

**Students:** Dashboard → **Account** → **Change Password**

**Staff:** Sidebar → **Account Settings** → **Change Password**

### 2.5 Logging Out

Click your name or the **Logout** button in the top-right corner or sidebar.

> **Note:** If **Remember me** is enabled and you log out, the saved session is cleared from both your browser and the server securely.

---

## 3. Student Manual

### 3.1 Student Dashboard

The dashboard is your home page after login. It shows:

- **Upcoming sessions** — sessions you're registered for
- **Registered subtopics** — what you've signed up for
- **Module progress** — how many modules you've completed
- **Attendance status** — present / absent / pending

### 3.2 Viewing Available Sessions

1. From the sidebar, click **Subtopics** (or **Sessions**).
2. You'll see a list of ACES sessions organized by phase:

   | Phase | Meaning |
   |-------|---------|
   | **Preparation** | Pre-employment readiness sessions |
   | **Pre-Employment** | Skills workshops and seminars |
   | **Career Fair** | Career fair activities |

3. Each session card shows:
   - Session title and date
   - Proctor name and location
   - Number of subtopics available
   - Your registration status

4. Click a session to see its subtopics.

### 3.3 Choosing a Subtopic

**Scenario:** You're in a session that has multiple subtopics (e.g. Session A has "Resume Writing", "Interview Skills", and "Career Research"). You pick **one** unless the session allows multiple.

1. Open a session.
2. Browse the list of subtopics. Each shows:
   - Title and description
   - Deadline
   - **Available slots specific to your section** (e.g. "3 of 10 slots left for BSIS-401") 
   - Proctor and location

3. Click **Register** next to your chosen subtopic.
4. Confirm in the dialog.
5. You'll see a success message and the subtopic appears under "My Registrations".

**If you try to register for a second subtopic in a single-choice session:**

A warning appears: *"You are already registered for [Sub A]. Do you want to replace it with [Sub B]?"*

- Click **OK** to swap
- Click **Cancel** to keep your original choice

> **Note:** Once you swap, your previous attendance record and any module progress for that session are deleted. Only the new subtopic matters.

**If a subtopic is full:**

The **Register** button will be disabled. The system checks the capacity allocated specifically for *your section*. If your section's slots are full, you cannot register even if other sections have space.

**If a subtopic is restricted:**

Some subtopics are restricted to specific **Programs** and **Sections**. If a subtopic has both restrictions, you must match **both** your course AND your section to see and register for it.

### 3.4 Completing Modules

Modules are learning materials attached to subtopics. They can be:

- **Text** — read and continue
- **PDF** — paginated, book-style reader
- **Link** — opens an external site
- **Quiz** — multiple choice, true/false, or short answer
- **Assessment** — a graded task

**To complete a text or PDF module:**

1. Go to **Modules** in the sidebar.
2. Click the module you want to open.
3. Read the content. For PDFs, use **Next** / **Previous** to page through.
4. On the last page, the **Mark as Complete** button appears.
5. Click it to confirm.

**To complete a quiz:**

1. Open the quiz module.
2. Answer each question.
3. Click **Submit**.
4. Your score is shown and saved automatically.

> **Quiz Retake Protection:** If you pass a quiz and decide to retake it to get a higher score, the system will **not** downgrade your grade if you score lower on the second attempt. Your highest passing score is permanently kept.

**To complete a link module:**

1. Click **Open Link** to visit the external site.
2. Return to the module and click **Mark as Complete**.

> **Auto-attendance:** For subtopics marked as **Module-based**, completing all required modules will automatically mark you as **Present**. 
> *Note: This only happens if your attendance status is still 'Pending'. If a staff member has already manually marked you 'Present' or 'Absent', the system respects that manual entry.*

### 3.5 Viewing Your Progress

Go to **Progress** in the sidebar. You'll see:

- **Overall completion percentage** — a ring chart
- **Sessions attended** vs. required
- **Modules completed** vs. total
- **Per-subtopic breakdown**

Click any subtopic to see module-level detail.

### 3.6 Updating Your Account

Go to **Account Settings** in the sidebar.

You can update:

- Full name
- Contact number (Mobile & Telephone)
- Email address (requires re-verification)
- Password

**Note:** Your Student ID, Program, and Section are **locked** and cannot be changed. Contact the ACES Unit if these require correction.

---

## 4. Staff Manual

The ACES System has three staff roles with different access levels:

| Role | Can Do |
|------|--------|
| **Viewer** | View dashboards, view student progress, view reports |
| **Lead** | Everything Viewer can do, plus: create sessions, subtopics, modules, take attendance, export reports |
| **Admin** | Everything Lead can do, plus: manage staff accounts, view audit log |

Your role is shown next to your name in the sidebar.

### 4.1 Staff Dashboard

After logging in, you land on the dashboard. It shows:

- **Session analytics** — pie chart of registrations by phase
- **At-risk students** — students who've missed sessions or fallen behind
- **Upcoming sessions** — next 5 scheduled events
- **Quick actions** — shortcuts to common tasks

### 4.2 Creating a Session *(Lead / Admin)*

1. Sidebar → **Sessions** → **Create Session**
2. Fill in the Session Details:
   - **Title** (e.g. "Resume Writing Workshop")
   - **Phase** (Preparation / Pre-Employment / Career Fair)
   - **Description** (Optional)
   - **Allow Multiple** (Check if students can register for more than one subtopic)
3. Scroll down to the **Subtopics** section and click **Add Subtopic** to configure the specific activities.
4. Click **Create Session**.

### 4.3 Creating Subtopics *(Lead / Admin)*

When creating or editing a session, you can add subtopics:

1. Click **Add Subtopic**.
2. Fill in:
   - **Title** (e.g. "Basic Resume Template")
   - **Capacity** (Max students globally)
   - **Deadline** (Registration cutoff)
   - **Date / Start Time / End Time**
   - **Proctor / Location** (May differ from session)
   - **Attendance Type** (Physical, Online, or Module-based)
   - **Visibility Rules** (Restrict to specific Courses or Sections)
   - **Required Rules** (Auto-register specific Courses or Sections)
3. Click **Create Session** to save all subtopics at once.

> **Even Split Tool:** The system provides an "Even Split" button that automatically distributes the total number of eligible students evenly across all subtopics, ensuring fairness. This takes into account the visibility and required rules you set.

### 4.4 Creating Modules and Assessments *(Lead / Admin)*

1. Sidebar → **Modules** → **Create New**
2. Choose the **Module** tab or **Assessment** tab.
3. Choose the **subtopic** to attach it to.
4. Fill in the title and description.
5. Choose the content type:
   - **Module:** Upload File, Provide Link, or Write Content.
   - **Assessment:** Provide Link (e.g. Google Form) or Create Quiz.
6. **If creating a quiz:** Click **+ Add Question** to build your quiz. You can set the question type (Multiple Choice, True/False, Short Answer), the options, the correct answer, and the points.
7. Click **Create Module** or **Create Assessment**.

### 4.5 Taking Attendance *(Lead / Admin)*

1. Sidebar → **Attendance**
2. Select the session and subtopic.
3. The list of registered students appears.
4. For each student, choose:
   - **Present**
   - **Absent**
   - **Pending**
5. Click **Save Attendance**.

**Bulk actions:** Select multiple students via checkboxes, use the dropdown to select **Present** or **Absent**, and click **Apply Status**.

**Auto-generated records:** If a student completed all modules, they may already be marked present automatically. The system displays an "Auto" badge next to these records so you know the system verified them. You can override these if needed.

### 4.6 Viewing Student Progress

1. Sidebar → **Student Progress**
2. Search by student ID or name.
3. Click a student to see:
   - Sessions attended
   - Subtopics registered
   - Modules completed
   - Overall progress percentage
4. Use the slide-in drawer to view a single session's detail.

### 4.7 Reports and Exports *(Lead / Admin)*

Sidebar → **Reports**. Available report types:

| Report | Format |
|--------|--------|
| **Attendance Summary** | CSV / PDF |
| **Student Progress** | PDF |
| **Custom Report** | PDF (choose columns) |
| **Detailed Report** | PDF |
| **Registrants List** | CSV |
| **Students List** | CSV |
| **Progress Report** | Print-ready HTML |

**To export:**

1. Choose the report type.
2. Set filters (date range, session, section, etc.).
3. Click **Generate**.
4. The file downloads or opens in a new tab.

> **CSV in Excel:** All CSV exports include a UTF-8 BOM marker so Excel opens them without garbled characters.

### 4.8 Managing Registrants *(Lead / Admin)*

1. Sidebar → **Registrants**
2. Filter by session, subtopic, course, or section.
3. Actions available:
   - **Unregister** a student (with confirmation)
   - **Notify** selected students via email
   - **Purge excess registrations** (if capacity was exceeded)

### 4.9 Calendar View

Sidebar → **Calendar** shows all sessions, subtopics, and school events on a monthly view. Color coding:

- **Blue** — Sessions
- **Green** — Subtopics
- **Yellow** — School events
- **Red** — Holidays

Click an event to see details or edit (if Lead/Admin).

### 4.10 Managing Staff Accounts *(Admin only)*

1. Sidebar → **Manage Staff**
2. To create a new staff account:
   - Click **Add New Staff**
   - Enter email, full name, role (admin/lead/viewer)
   - Click **Create**
   - The new staff member receives a verification email

**Rate limit:** Maximum 10 new staff accounts per hour per admin.

3. To edit an existing account:
   - Click **Edit** (pencil icon) next to the name
   - Change role, deactivate, or reactivate

4. All staff actions are logged in the **Activity Log** (visible on the same page).

### 4.11 Staff Account Settings

Sidebar → **Account Settings**

You can update:
- Full Name
- Email Address
- Password (requires current password to change)

---

## 5. Common Tasks & FAQ

### For Students

**Q: I registered but didn't get a verification email.**
A: Check spam, wait 5 minutes, or contact the ACES Unit.

**Q: I forgot which subtopic I chose.**
A: Go to Dashboard — your current registrations are listed at the top.

**Q: Can I change my subtopic after registering?**
A: Yes, if the session allows it. Register for a new subtopic and confirm the swap when prompted.

**Q: Why can't I see a session that other students see?**
A: It may be restricted to a specific section or program. Contact the ACES Unit if you believe you should have access.

**Q: My module shows as "not complete" but I finished it.**
A: Refresh the page. If it still shows incomplete, submit a ticket to the ACES Unit.

**Q: How do I know if I was marked present?**
A: Go to Progress — each session shows Present, Absent, or Pending.

**Q: I failed a quiz and want to try again. Will it reset my score?**
A: Yes, but only if you haven't passed it yet. If you already have a passing score, retaking it will not lower your grade.

### For Staff

**Q: How do I reset a student's password?**
A: Ask the student to use Forgot Password. If they can't access their email, use the admin panel to trigger a manual reset.

**Q: Can I edit attendance after saving?**
A: Yes, return to Attendance, select the same session/subtopic, and change the statuses.

**Q: Why does a session show 0 registrants?**
A: The registration deadline may have passed, or the subtopics may be restricted to sections/programs that no students match.

**Q: How do I send a reminder to students?**
A: Go to Registrants → select students → click **Notify**.

**Q: What's the difference between deactivate and delete for staff?**
A: Deactivate (soft) keeps the account but blocks login. There is no hard delete — this preserves the audit log.

**Q: How often do cron jobs run?**
A: Auto-assignment runs hourly, reminders every 6 hours. Both are configured by the IT staff.

---

## 6. Troubleshooting

| Problem | Fix |
|---------|-----|
| "Invalid CSRF token" | Refresh the page and try again. If persistent, log out and log back in. |
| Session logs me out unexpectedly | Your idle timeout (default 1 hour) was exceeded. Log back in. |
| "Your account has been deactivated" | Your access has been revoked by the Super Admin. Contact the ACES Unit. |
| Remember-me doesn't work | Your browser may be blocking third-party cookies. Check browser settings. |
| Upload fails with "file type not allowed" | Check the allowed extensions list in Section 4.4. |
| Upload fails with "file too large" | Files must be under 10 MB. Compress or split. |
| Email features not working | Contact the ACES Unit — the SMTP credentials may need updating. |
| CSV opens with garbled characters in Excel | Ensure you're using the file as downloaded — do not re-save before opening. |
| Page shows "System temporarily unavailable" | The database is unreachable. Contact the IT staff. |
| Mobile menu doesn't open | Ensure you are clicking the hamburger icon (☰) in the top-left corner. Try refreshing the page. |

---

## 7. Getting Help

| Issue Type | Contact |
|------------|---------|
| Forgotten password / account issues | ACES Unit: [insert contact] |
| Session, subtopic, or module questions | Your session proctor |
| System errors or bugs | Research team: [insert contact] |
| Server / website down | School IT department: [insert contact] |

---

## 8. Revision History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | October 2026 | ACES Research Team | Initial user manual |
| 1.1 | October 2026 | ACES Research Team | Updated for new UI (Tailwind), Data Privacy checklist, mobile menu, and password toggles |
| 1.2 | October 2026 | ACES Research Team | Added Quiz Retake Protection, per-section capacity display, AND logic for visibility, deactivated account troubleshooting, and auto-attendance clarification |

---

**End of user manual.**
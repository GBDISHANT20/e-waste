# SWM Portal

Web application for **Municipal Solid Waste Management** – a district-level system to register
districts, District Collectors, urban wards + MCs, villages + Sarpanches, garbage-collection vehicles,
drivers & staff, and citizens (house owners).

**Stack:** HTML + CSS (no framework, mobile-first, fully responsive) · PHP 8.1+ · MySQL / MariaDB.
No Node, React or SQLite. A little plain JavaScript is used only to filter the ward/village drop-downs
on the sign-up form; every page works without it.

## Install

1. **Database** – create an empty MySQL/MariaDB database and a user for it, e.g. in phpMyAdmin or:
   ```sql
   CREATE DATABASE swm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'swm'@'localhost' IDENTIFIED BY 'choose-a-password';
   GRANT ALL ON swm.* TO 'swm'@'localhost';
   ```
2. **Settings** – copy `src/config.local.sample.php` to `src/config.local.php` and fill in the database
   name, user and password. (Environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` also work.)
3. **Tables + first admin** (run in a terminal; with XAMPP use its *Shell*):
   ```bash
   ADMIN_PASSWORD='choose-a-strong-one' php database/install.php
   ```
   This creates the tables from `database/schema.sql` and a **State Admin** (mobile `9999999999`, or set
   `ADMIN_PHONE`). If you don't set `ADMIN_PASSWORD`, a random one is printed once.
4. **Run it**
   - Quick local test: `php -S localhost:8000 -t public` then open <http://localhost:8000>.
   - Apache / XAMPP / shared hosting: make the **`public/`** folder the web root (or open
     `http://localhost/e-waste/public/`). `src/`, `database/` and `tests/` are blocked by `.htaccess`
     and should never be served.

### WAMP (Windows)

Needs PHP **8.1 or newer** (WAMP icon → PHP → Version) and the default `pdo_mysql` extension.

1. Copy this folder to `C:\wamp64\www\e-waste`.
2. Start WAMP (icon turns green), open <http://localhost/phpmyadmin> (user `root`, empty password) and
   create a database named `swm` with collation `utf8mb4_unicode_ci`.
3. Copy `src\config.local.sample.php` to `src\config.local.php` and set:
   `'db_user' => 'root'`, `'db_pass' => ''` (WAMP's default; use a real user/password on a live server).
4. Open **Command Prompt** and run the installer with WAMP's own PHP (adjust the version folder):
   ```bat
   cd C:\wamp64\www\e-waste
   set ADMIN_PASSWORD=choose-a-strong-one
   C:\wamp64\bin\php\php8.3.0\php.exe database\install.php
   ```
5. Open <http://localhost/e-waste/public/> and log in with mobile `9999999999` and that password.

Use HTTPS in production (the session cookie is automatically marked `Secure` on HTTPS).

## What it does

**Registrations:** districts · District Collectors · urban wards + MC · villages + Sarpanch · vehicles · drivers & staff · citizens (house owners).

**Daily work and compliance (from the SWM 2026 concept):**

| Module | What it gives you |
|---|---|
| **Compliance calendar** | Red / orange / green list of what needs attention: overdue actions, quarterly review due, expiring or expired facility documents, wards with no data for 7 days, facilities not inspected for 6 months, old complaints, annual report due 30 June. Also shown on the dashboard. |
| **Waste data** | Daily entries per vehicle/ward/village (generated, collected, wet/dry/mixed, processed, landfilled, weighbridge slip, photo, GPS). The *data validation engine* saves but flags numbers that don't add up (collected > generated, processed > collected, facility capacity exceeded). |
| **Facilities** | MRF, compost, biomethanation, RDF, landfill, legacy site, dump points … with capacity, GPS, operator, status and uploaded documents (land, authorization, consent, EC, agreement…) with expiry dates. |
| **Inspections** | Checklist, photo, GPS, observations. Ticking *violation* creates a numbered **action** with a deadline. |
| **Quarterly reviews** | Meeting record (date, chair, participants, the 13 review parameters, decisions) with minutes upload. |
| **Action tracker** | Unique numbers like `SWM/BWN/2026/00001`. Pending → Submitted (officer uploads after-photo, report, GPS) → Verified → Closed by the Collector, who can also return it with a remark. |
| **Complaints** | Public page (no login) to report a problem with photo + location and get a complaint number; track it by number + mobile. Collector assigns to MC/Sarpanch → action taken → closed. |
| **Waste pickers** | Database of informal waste pickers (area, SHG, material, linked MRF, training, PPE, registration status). No bank details are stored. |
| **Documents** | District repository (orders, action plan, reports) and per-facility documents; files are stored outside the web root and served only after a permission check. |
| **Map** | Self-contained map of geo-tagged facilities, dump points and open complaints (no external map service needed). |
| **Annual report** | One click: district, city, village summaries, infrastructure, waste quantities by month, compliance, violations, complaints, pickers. Print / save as PDF, download all waste data as CSV, mark as submitted. |
| **Audit log** | Who did what and when. |

## Who can do what

| Role | Registered by | Can do |
|---|---|---|
| State Admin | installer | register districts and District Collectors; overview of all districts; audit log |
| District Collector (DC/DM) | State Admin | everything in their district: register wards/MCs, villages/Sarpanches, vehicles, staff; create actions and reviews; assign and close complaints; documents; annual report |
| MC (Municipal Councillor) | Collector (allotted to a ward) | the same field work for **their own ward**: vehicles, staff, waste data, facilities, inspections, waste pickers; submit actions given to them; handle complaints |
| Sarpanch | Collector (allotted to a village) | the same, for **their own village** |
| Driver / Staff | Collector / MC / Sarpanch | enter daily waste data for their own vehicle; see own profile |
| Citizen (house owner) | self sign-up | see profile and their MC/Sarpanch; report issues and follow them |

- Accounts created by an official get a **one-time temporary password** shown on screen and must change it at first login.
- Registering a new Collector for a district deactivates the previous one; all district data stays.
- Users only see data of their own district (and own ward/village for MC / Sarpanch).
- A vehicle serves either a city ward **or** a village. Registration numbers are normalised (`HR16AB1234`).
- Uploads: JPG, PNG or PDF up to 5 MB, checked by file content. If your PHP `upload_max_filesize` is smaller (PHP default is 2 MB) raise it in `php.ini`.

## Security measures

Prepared statements everywhere (PDO) · `password_hash` · CSRF token on every form · output escaping ·
session id regenerated at login · login lock-out after 5 failures (15 min) · security headers + CSP ·
audit log of registrations, uploads and status changes (`audit_log` table) · uploaded files kept outside the web root and served only after a permission check · CSV export neutralises spreadsheet formulas.

## Tests

```bash
# needs the MySQL user above to also have access to a scratch database (it is WIPED):
CREATE DATABASE swm_test CHARACTER SET utf8mb4; GRANT ALL ON swm_test.* TO 'swm'@'localhost';
php tests/run.php          # ~155 end-to-end checks over real HTTP (php built-in server)
# other credentials: TEST_DB_NAME=... TEST_DB_USER=... TEST_DB_PASS=... php tests/run.php
```

## Layout

- `public/` – web root: one PHP page per screen, `assets/style.css`, `assets/app.js`
- `src/` – bootstrap, DB helpers, auth, role scoping (`scope.php`), layout/form helpers
- `database/schema.sql`, `database/install.php`
- `tests/run.php`
- `storage/uploads/` – uploaded files (not web-accessible; back this folder up with the database)

## Not built yet

Vehicle live GPS tracking, e-sign / DSC, SMS/e-mail notifications (alerts are shown inside the app), AI assistant,
state/CPCB API integration, mobile app. The public complaint form is protected by a per-number daily limit only;
add a CAPTCHA before exposing it to the open internet.

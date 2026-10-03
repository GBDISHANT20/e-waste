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

## Who registers whom

| Role | Registered by | Can do |
|---|---|---|
| State Admin | installer | register districts and District Collectors |
| District Collector (DC/DM) | State Admin | register wards + MC, villages + Sarpanch, vehicles, drivers/staff; see all citizens of the district |
| MC (Municipal Councillor) | Collector (allotted to a ward) | register vehicles/staff and see citizens of **their own ward** |
| Sarpanch | Collector (allotted to a village) | the same, for **their own village** |
| Driver / Staff | Collector / MC / Sarpanch | see own profile and assigned vehicle |
| Citizen (house owner) | self sign-up (district → ward/village → house) | see own profile and their MC / Sarpanch |

- Accounts created by an official get a **one-time temporary password** shown on screen and must change it at first login.
- Registering a new Collector for a district deactivates the previous one; all district data stays.
- Users only see data of their own district (and own ward/village for MC / Sarpanch).
- A vehicle serves either a city ward **or** a village. Registration numbers are normalised (`HR16AB1234`).

## Security measures

Prepared statements everywhere (PDO) · `password_hash` · CSRF token on every form · output escaping ·
session id regenerated at login · login lock-out after 5 failures (15 min) · security headers + CSP ·
audit log of registrations / status changes (`audit_log` table).

## Tests

```bash
# needs the MySQL user above to also have access to a scratch database (it is WIPED):
CREATE DATABASE swm_test CHARACTER SET utf8mb4; GRANT ALL ON swm_test.* TO 'swm'@'localhost';
php tests/run.php          # ~50 end-to-end checks over real HTTP (php built-in server)
# other credentials: TEST_DB_NAME=... TEST_DB_USER=... TEST_DB_PASS=... php tests/run.php
```

## Layout

- `public/` – web root: one PHP page per screen, `assets/style.css`, `assets/app.js`
- `src/` – bootstrap, DB helpers, auth, role scoping (`scope.php`), layout/form helpers
- `database/schema.sql`, `database/install.php`
- `tests/run.php`

## Not built yet (from the concept PDF)

Daily waste data entry, inspections, quarterly review, action tracker, GIS map, compliance calendar,
annual report, citizen complaints, mobile app.

**Shortcut on WAMP:** after copying the folder to `...\wamp\www\e-waste` and starting WAMP, just double-click
`install-wamp.bat` – it creates the database, the config file and the admin account.

**No command line at all:** in phpMyAdmin create the database `swm`, open it, choose *Import* and import
`database/phpmyadmin-import.sql`. This creates all tables plus the State Admin (mobile `9999999999`,
temporary password `ChangeMe@123`, changed at first login). Then open `http://localhost/e-waste/public/`.

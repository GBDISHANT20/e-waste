# SWM Portal

Web application for **Municipal Solid Waste Management**: a district-level system to register
districts, collectors, wards / villages, garbage-collection vehicles, drivers & staff and citizens
(house owners). Built from the "SWM 2026 Compliance & Monitoring" concept; this is **Phase 1 – registration**.

## Run

Requires Node.js 22.5+ (uses the built-in `node:sqlite`).

```bash
npm install
ADMIN_PASSWORD='choose-a-strong-one' npm start   # http://localhost:3000
npm test
```

On first start a **State Admin** is created (phone `9999999999`, or `ADMIN_PHONE`). If `ADMIN_PASSWORD`
is not set, a random password is printed once in the console. Data lives in `data/swm.db`.

## Who registers whom

| Role | Registered by | Can do |
|---|---|---|
| State Admin | seeded | register districts and District Collectors |
| District Collector (DC/DM) | State Admin | register wards + MC, villages + Sarpanch, vehicles, drivers/staff; view all citizens in the district |
| MC (Municipal Councillor) | Collector (allotted to a ward) | register vehicles/staff and view citizens of **own ward** |
| Sarpanch | Collector (allotted to a village) | same, for **own village** |
| Driver / Staff | Collector / MC / Sarpanch | view own profile and assigned vehicle |
| Citizen (house owner) | self sign-up (district → ward/village → house) | view own profile, their MC / Sarpanch |

- Accounts created by an official get a **one-time temporary password** and must change it at first login.
- Registering a new Collector for a district deactivates the previous one; all district data stays.
- Every user only sees data for their own district (and their own ward/village for MC/Sarpanch).
- Vehicles are assigned to a city ward **or** a village; registration numbers are normalised (`HR16AB1234`).

## Layout

- `server/` – Express API (`app.js`), SQLite schema (`db.js`), auth (`auth.js`), first-admin seed
- `public/` – single-page frontend (plain JS, no build step)
- `test/api.test.js` – end-to-end API test of the registration flow and role scoping

## Not yet built (from the concept PDF)

Waste data entry, inspections, quarterly review, action tracker, GIS map, compliance calendar,
annual reports, complaints, mobile app. The schema and role model are set up to extend into these.

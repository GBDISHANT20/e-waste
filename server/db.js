const { DatabaseSync } = require('node:sqlite');
const path = require('node:path');
const fs = require('node:fs');

function openDb(file) {
  const target = file || process.env.DB_FILE || path.join(__dirname, '..', 'data', 'swm.db');
  if (target !== ':memory:') fs.mkdirSync(path.dirname(target), { recursive: true });
  const db = new DatabaseSync(target);
  db.exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
  db.exec(SCHEMA);
  return db;
}

const SCHEMA = `
CREATE TABLE IF NOT EXISTS districts (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  state TEXT NOT NULL,
  code TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Every login account. Citizens, drivers/staff, MCs, sarpanches, collectors, state admin.
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  phone TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK (role IN
    ('STATE_ADMIN','DISTRICT_COLLECTOR','MC','SARPANCH','STAFF','CITIZEN')),
  district_id INTEGER REFERENCES districts(id),
  must_change_password INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at INTEGER NOT NULL
);

-- District Collector (DC/DM) profile. One active collector per district.
CREATE TABLE IF NOT EXISTS collectors (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL UNIQUE REFERENCES users(id),
  district_id INTEGER NOT NULL REFERENCES districts(id),
  employee_id TEXT NOT NULL,
  email TEXT,
  active INTEGER NOT NULL DEFAULT 1
);

-- Urban: a ward inside a city/ULB, allotted to one MC (municipal councillor).
CREATE TABLE IF NOT EXISTS wards (
  id INTEGER PRIMARY KEY,
  district_id INTEGER NOT NULL REFERENCES districts(id),
  city TEXT NOT NULL,
  ward_no TEXT NOT NULL,
  ward_name TEXT,
  mc_user_id INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (district_id, city, ward_no)
);

-- Rural: a village (Gram Panchayat) with its sarpanch.
CREATE TABLE IF NOT EXISTS villages (
  id INTEGER PRIMARY KEY,
  district_id INTEGER NOT NULL REFERENCES districts(id),
  block TEXT,
  name TEXT NOT NULL,
  sarpanch_user_id INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (district_id, name)
);

-- Garbage collection vehicles. Assigned to a city ward OR a village.
CREATE TABLE IF NOT EXISTS vehicles (
  id INTEGER PRIMARY KEY,
  district_id INTEGER NOT NULL REFERENCES districts(id),
  city TEXT,
  reg_number TEXT NOT NULL UNIQUE,
  type TEXT NOT NULL,
  capacity_kg INTEGER,
  status TEXT NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE','MAINTENANCE','RETIRED')),
  ward_id INTEGER REFERENCES wards(id),
  village_id INTEGER REFERENCES villages(id),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (NOT (ward_id IS NOT NULL AND village_id IS NOT NULL))
);

-- Drivers and helpers who run the vehicles.
CREATE TABLE IF NOT EXISTS staff (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL UNIQUE REFERENCES users(id),
  district_id INTEGER NOT NULL REFERENCES districts(id),
  staff_role TEXT NOT NULL CHECK (staff_role IN ('DRIVER','HELPER','SUPERVISOR')),
  licence_no TEXT,
  vehicle_id INTEGER REFERENCES vehicles(id),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Citizens / house owners whose garbage is collected.
CREATE TABLE IF NOT EXISTS households (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL UNIQUE REFERENCES users(id),
  district_id INTEGER NOT NULL REFERENCES districts(id),
  area_type TEXT NOT NULL CHECK (area_type IN ('URBAN','RURAL')),
  ward_id INTEGER REFERENCES wards(id),
  village_id INTEGER REFERENCES villages(id),
  house_no TEXT NOT NULL,
  address TEXT,
  members INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK ((area_type = 'URBAN' AND ward_id IS NOT NULL AND village_id IS NULL)
      OR (area_type = 'RURAL' AND village_id IS NOT NULL AND ward_id IS NULL))
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY,
  at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INTEGER,
  action TEXT NOT NULL,
  entity TEXT NOT NULL,
  entity_id INTEGER,
  detail TEXT
);
`;

module.exports = { openDb };

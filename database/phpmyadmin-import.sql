-- SWM Portal – MySQL / MariaDB schema (InnoDB, utf8mb4)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS districts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  state VARCHAR(120) NOT NULL,
  code VARCHAR(10) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_district_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every login account: state admin, collector, MC, sarpanch, staff, citizen.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone CHAR(10) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('STATE_ADMIN','DISTRICT_COLLECTOR','MC','SARPANCH','STAFF','CITIZEN') NOT NULL,
  district_id INT UNSIGNED NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_user_phone UNIQUE (phone),
  CONSTRAINT fk_user_district FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- District Collector (DC/DM) profile. Only one active collector per district (enforced by the app).
CREATE TABLE IF NOT EXISTS collectors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  district_id INT UNSIGNED NOT NULL,
  employee_id VARCHAR(40) NOT NULL,
  email VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT uq_collector_user UNIQUE (user_id),
  CONSTRAINT fk_collector_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_collector_district FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Urban: a ward of a city/ULB, allotted to one MC.
CREATE TABLE IF NOT EXISTS wards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  city VARCHAR(120) NOT NULL,
  ward_no VARCHAR(20) NOT NULL,
  ward_name VARCHAR(120) NULL,
  mc_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_ward UNIQUE (district_id, city, ward_no),
  CONSTRAINT fk_ward_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_ward_mc FOREIGN KEY (mc_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rural: a village (Gram Panchayat) with its sarpanch.
CREATE TABLE IF NOT EXISTS villages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  block VARCHAR(120) NULL,
  name VARCHAR(120) NOT NULL,
  sarpanch_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_village UNIQUE (district_id, name),
  CONSTRAINT fk_village_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_village_sarpanch FOREIGN KEY (sarpanch_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Garbage collection vehicles, assigned to a city ward OR a village (never both).
CREATE TABLE IF NOT EXISTS vehicles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  city VARCHAR(120) NULL,
  reg_number VARCHAR(20) NOT NULL,
  type ENUM('TRACTOR_TROLLEY','E_RICKSHAW','MINI_TRUCK','TIPPER','COMPACTOR','HANDCART') NOT NULL,
  capacity_kg INT UNSIGNED NULL,
  status ENUM('ACTIVE','MAINTENANCE','RETIRED') NOT NULL DEFAULT 'ACTIVE',
  ward_id INT UNSIGNED NULL,
  village_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_vehicle_reg UNIQUE (reg_number),
  CONSTRAINT fk_vehicle_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_vehicle_ward FOREIGN KEY (ward_id) REFERENCES wards(id),
  CONSTRAINT fk_vehicle_village FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT ck_vehicle_one_area CHECK (ward_id IS NULL OR village_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drivers, helpers and supervisors.
CREATE TABLE IF NOT EXISTS staff (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  district_id INT UNSIGNED NOT NULL,
  staff_role ENUM('DRIVER','HELPER','SUPERVISOR') NOT NULL,
  licence_no VARCHAR(30) NULL,
  vehicle_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_staff_user UNIQUE (user_id),
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_staff_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_staff_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Citizens / house owners whose garbage is collected.
CREATE TABLE IF NOT EXISTS households (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  district_id INT UNSIGNED NOT NULL,
  area_type ENUM('URBAN','RURAL') NOT NULL,
  ward_id INT UNSIGNED NULL,
  village_id INT UNSIGNED NULL,
  house_no VARCHAR(40) NOT NULL,
  address VARCHAR(250) NULL,
  members SMALLINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_household_user UNIQUE (user_id),
  CONSTRAINT fk_hh_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_hh_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_hh_ward FOREIGN KEY (ward_id) REFERENCES wards(id),
  CONSTRAINT fk_hh_village FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT ck_hh_area CHECK (
    (area_type = 'URBAN' AND ward_id IS NOT NULL AND village_id IS NULL) OR
    (area_type = 'RURAL' AND village_id IS NOT NULL AND ward_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NULL,
  detail TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brute-force protection for the login form.
CREATE TABLE IF NOT EXISTS login_failures (
  phone VARCHAR(20) NOT NULL PRIMARY KEY,
  fails INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- Phase 2: facilities, documents, waste data, inspections, reviews,
--          action tracker, complaints, waste pickers, reports
-- =====================================================================

-- Running numbers for IDs such as SWM/BWN/2026/00001 (one row per district, kind and year).
CREATE TABLE IF NOT EXISTS counters (
  district_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  yr SMALLINT UNSIGNED NOT NULL,
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (district_id, kind, yr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MRF, compost plant, landfill, dump point ... (each can be shown on the map).
CREATE TABLE IF NOT EXISTS facilities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  type ENUM('MRF','COMPOST_PLANT','VERMICOMPOST','BIOMETHANATION','RDF','WASTE_TO_ENERGY','RECYCLING',
            'TRANSFER_STATION','LANDFILL','LEGACY_SITE','OPEN_DUMP','OPEN_BURNING','OTHER') NOT NULL,
  name VARCHAR(160) NOT NULL,
  ward_id INT UNSIGNED NULL,
  village_id INT UNSIGNED NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  land_area_sqm INT UNSIGNED NULL,
  capacity_tpd DECIMAL(8,2) NULL,
  technology VARCHAR(120) NULL,
  operator VARCHAR(120) NULL,
  status ENUM('OPERATIONAL','NON_OPERATIONAL','UNDER_CONSTRUCTION') NOT NULL DEFAULT 'OPERATIONAL',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fac_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_fac_ward FOREIGN KEY (ward_id) REFERENCES wards(id),
  CONSTRAINT fk_fac_village FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_fac_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT ck_fac_one_area CHECK (ward_id IS NULL OR village_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uploaded files (stored outside the web root, served only through download.php after a permission check).
CREATE TABLE IF NOT EXISTS documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  owner_type ENUM('FACILITY','ACTION','INSPECTION','MEETING','COMPLAINT','WASTE','DISTRICT') NOT NULL,
  owner_id INT UNSIGNED NULL,
  category VARCHAR(40) NOT NULL,
  title VARCHAR(160) NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(40) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  expires_on DATE NULL,
  uploaded_by INT UNSIGNED NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_doc_stored UNIQUE (stored_name),
  CONSTRAINT fk_doc_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES users(id),
  INDEX ix_doc_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily waste data (one row per vehicle trip / daily record). Quantities in kg.
CREATE TABLE IF NOT EXISTS waste_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  ward_id INT UNSIGNED NULL,
  village_id INT UNSIGNED NULL,
  vehicle_id INT UNSIGNED NULL,
  facility_id INT UNSIGNED NULL,
  generated_kg DECIMAL(10,2) NULL,
  collected_kg DECIMAL(10,2) NOT NULL,
  wet_kg DECIMAL(10,2) NULL,
  dry_kg DECIMAL(10,2) NULL,
  mixed_kg DECIMAL(10,2) NULL,
  processed_kg DECIMAL(10,2) NULL,
  landfilled_kg DECIMAL(10,2) NULL,
  slip_no VARCHAR(40) NULL,
  notes VARCHAR(250) NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  photo_doc_id INT UNSIGNED NULL,
  flags VARCHAR(500) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_we_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_we_ward FOREIGN KEY (ward_id) REFERENCES wards(id),
  CONSTRAINT fk_we_village FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_we_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
  CONSTRAINT fk_we_facility FOREIGN KEY (facility_id) REFERENCES facilities(id),
  CONSTRAINT fk_we_photo FOREIGN KEY (photo_doc_id) REFERENCES documents(id),
  CONSTRAINT fk_we_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX ix_we_date (district_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quarterly SWM review meetings of the District Collector.
CREATE TABLE IF NOT EXISTS meetings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  fy_start SMALLINT UNSIGNED NOT NULL,
  quarter_no TINYINT UNSIGNED NOT NULL,
  meeting_date DATE NOT NULL,
  venue VARCHAR(160) NULL,
  chairperson VARCHAR(160) NOT NULL,
  participants VARCHAR(500) NULL,
  parameters TEXT NULL,
  decisions TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mt_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_mt_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  inspected_on DATE NOT NULL,
  inspector_user_id INT UNSIGNED NOT NULL,
  ward_id INT UNSIGNED NULL,
  village_id INT UNSIGNED NULL,
  facility_id INT UNSIGNED NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  checklist TEXT NULL,
  observations TEXT NULL,
  violation TINYINT(1) NOT NULL DEFAULT 0,
  direction VARCHAR(500) NULL,
  deadline DATE NULL,
  action_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_in_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_in_user FOREIGN KEY (inspector_user_id) REFERENCES users(id),
  CONSTRAINT fk_in_ward FOREIGN KEY (ward_id) REFERENCES wards(id),
  CONSTRAINT fk_in_village FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_in_facility FOREIGN KEY (facility_id) REFERENCES facilities(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Action Taken Tracker: PENDING -> SUBMITTED -> VERIFIED -> CLOSED
CREATE TABLE IF NOT EXISTS actions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  action_no VARCHAR(40) NOT NULL,
  meeting_id INT UNSIGNED NULL,
  inspection_id INT UNSIGNED NULL,
  issue VARCHAR(250) NOT NULL,
  location_text VARCHAR(250) NULL,
  responsible_user_id INT UNSIGNED NOT NULL,
  supporting_user_id INT UNSIGNED NULL,
  deadline DATE NOT NULL,
  status ENUM('PENDING','SUBMITTED','VERIFIED','CLOSED') NOT NULL DEFAULT 'PENDING',
  report_text TEXT NULL,
  before_doc_id INT UNSIGNED NULL,
  after_doc_id INT UNSIGNED NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  remark VARCHAR(500) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  verified_by INT UNSIGNED NULL,
  verified_at DATETIME NULL,
  closed_at DATETIME NULL,
  CONSTRAINT uq_action_no UNIQUE (action_no),
  CONSTRAINT fk_ac_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_ac_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id),
  CONSTRAINT fk_ac_inspection FOREIGN KEY (inspection_id) REFERENCES inspections(id),
  CONSTRAINT fk_ac_resp FOREIGN KEY (responsible_user_id) REFERENCES users(id),
  CONSTRAINT fk_ac_supp FOREIGN KEY (supporting_user_id) REFERENCES users(id),
  CONSTRAINT fk_ac_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_ac_before FOREIGN KEY (before_doc_id) REFERENCES documents(id),
  CONSTRAINT fk_ac_after FOREIGN KEY (after_doc_id) REFERENCES documents(id),
  INDEX ix_ac_status (district_id, status, deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Citizen complaints: RECEIVED -> ASSIGNED -> ACTION_TAKEN -> CLOSED
CREATE TABLE IF NOT EXISTS complaints (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_no VARCHAR(30) NOT NULL,
  district_id INT UNSIGNED NOT NULL,
  ward_id INT UNSIGNED NULL,
  village_id INT UNSIGNED NULL,
  category ENUM('GARBAGE_NOT_COLLECTED','OPEN_DUMPING','OPEN_BURNING','PLASTIC_DUMPING','MIXED_WASTE',
                'OVERFLOWING_BIN','MRF_ISSUE','DRAIN_WASTE','OTHER') NOT NULL,
  description VARCHAR(1000) NOT NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  photo_doc_id INT UNSIGNED NULL,
  citizen_name VARCHAR(120) NOT NULL,
  citizen_phone CHAR(10) NOT NULL,
  user_id INT UNSIGNED NULL,
  status ENUM('RECEIVED','ASSIGNED','ACTION_TAKEN','CLOSED') NOT NULL DEFAULT 'RECEIVED',
  assigned_to_user_id INT UNSIGNED NULL,
  action_note VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  closed_at DATETIME NULL,
  CONSTRAINT uq_complaint_no UNIQUE (complaint_no),
  CONSTRAINT fk_cp_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_cp_ward FOREIGN KEY (ward_id) REFERENCES wards(id),
  CONSTRAINT fk_cp_village FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_cp_photo FOREIGN KEY (photo_doc_id) REFERENCES documents(id),
  CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_cp_assignee FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
  INDEX ix_cp_phone (citizen_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Informal waste pickers / collectors (no bank details are stored).
CREATE TABLE IF NOT EXISTS waste_pickers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  district_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone CHAR(10) NULL,
  ward_id INT UNSIGNED NULL,
  village_id INT UNSIGNED NULL,
  age TINYINT UNSIGNED NULL,
  gender ENUM('MALE','FEMALE','OTHER') NULL,
  work_category VARCHAR(80) NULL,
  collection_area VARCHAR(160) NULL,
  organization VARCHAR(120) NULL,
  materials VARCHAR(160) NULL,
  approx_kg_per_day DECIMAL(8,2) NULL,
  facility_id INT UNSIGNED NULL,
  trained TINYINT(1) NOT NULL DEFAULT 0,
  has_ppe TINYINT(1) NOT NULL DEFAULT 0,
  registration_status ENUM('REGISTERED','PENDING','NOT_REGISTERED') NOT NULL DEFAULT 'PENDING',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_wp_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_wp_ward FOREIGN KEY (ward_id) REFERENCES wards(id),
  CONSTRAINT fk_wp_village FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_wp_facility FOREIGN KEY (facility_id) REFERENCES facilities(id),
  CONSTRAINT fk_wp_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When the annual report of a financial year was marked as submitted.
CREATE TABLE IF NOT EXISTS annual_reports (
  district_id INT UNSIGNED NOT NULL,
  fy_start SMALLINT UNSIGNED NOT NULL,
  submitted_on DATE NOT NULL,
  submitted_by INT UNSIGNED NOT NULL,
  PRIMARY KEY (district_id, fy_start),
  CONSTRAINT fk_ar_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_ar_user FOREIGN KEY (submitted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- First State Admin: mobile 9999999999, temporary password ChangeMe@123
-- (you are forced to choose a new password at first login).
INSERT INTO users (name, phone, password_hash, role, must_change_password)
SELECT 'State Admin', '9999999999', '$2y$10$yPitMfafb8beDPSIh6tfHuzh2ep8GtRNIH4fF.gD1ys89wxNvEmKq', 'STATE_ADMIN', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'STATE_ADMIN');

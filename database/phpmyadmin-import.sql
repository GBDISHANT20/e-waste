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

-- First State Admin: mobile 9999999999, temporary password ChangeMe@123
-- (you are forced to choose a new password at first login).
INSERT INTO users (name, phone, password_hash, role, must_change_password)
SELECT 'State Admin', '9999999999', '$2y$10$zmkvFYoQtdFwsUicwPdKBOe.FD0t7BrmtizIbZynCfCzmA.yily/O', 'STATE_ADMIN', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'STATE_ADMIN');

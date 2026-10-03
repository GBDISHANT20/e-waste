const express = require('express');
const path = require('node:path');
const {
  hashPassword, verifyPassword, tempPassword, createSession,
  attachUser, requireAuth, requireRole,
} = require('./auth');

class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}
const bad = (msg) => new HttpError(400, msg);

const PHONE_RE = /^[6-9]\d{9}$/;
const REG_RE = /^[A-Z]{2}\d{1,2}[A-Z]{0,3}\d{4}$/;
const VEHICLE_TYPES = ['TRACTOR_TROLLEY', 'E_RICKSHAW', 'MINI_TRUCK', 'TIPPER', 'COMPACTOR', 'HANDCART'];
const STAFF_ROLES = ['DRIVER', 'HELPER', 'SUPERVISOR'];

function str(v, field, { max = 120, required = true } = {}) {
  const s = typeof v === 'string' ? v.trim() : '';
  if (!s) { if (required) throw bad(`${field} is required`); return null; }
  if (s.length > max) throw bad(`${field} is too long`);
  return s;
}
function phone(v, field = 'Phone') {
  const s = str(v, field, { max: 10 });
  if (!PHONE_RE.test(s)) throw bad(`${field} must be a valid 10-digit mobile number`);
  return s;
}
function intOrNull(v, field) {
  if (v === undefined || v === null || v === '') return null;
  const n = Number(v);
  if (!Number.isInteger(n) || n < 0) throw bad(`${field} must be a whole number`);
  return n;
}

function createApp(db) {
  const app = express();
  app.use(express.json({ limit: '100kb' }));
  app.use(attachUser(db));

  const tx = (fn) => {
    db.exec('BEGIN');
    try { const r = fn(); db.exec('COMMIT'); return r; }
    catch (e) { db.exec('ROLLBACK'); throw e; }
  };
  const audit = (req, action, entity, entityId, detail) =>
    db.prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, detail) VALUES (?,?,?,?,?)')
      .run(req.user ? req.user.id : null, action, entity, entityId, detail ? JSON.stringify(detail) : null);

  function createUser({ name, phone: ph, role, district_id, password }) {
    const temp = password ? null : tempPassword();
    const r = db.prepare(
      `INSERT INTO users (name, phone, password_hash, role, district_id, must_change_password)
       VALUES (?,?,?,?,?,?)`
    ).run(name, ph, hashPassword(password || temp), role, district_id, password ? 0 : 1);
    return { id: Number(r.lastInsertRowid), temp_password: temp };
  }

  // The district a request operates on. State admin must say which one.
  function districtFor(req, supplied) {
    if (req.user.role === 'STATE_ADMIN') {
      const id = intOrNull(supplied, 'district_id');
      if (!id) throw bad('district_id is required');
      if (!db.prepare('SELECT 1 FROM districts WHERE id = ?').get(id)) throw bad('Unknown district');
      return id;
    }
    return req.user.district_id;
  }

  // Wards / villages this user is responsible for (null = whole district).
  function myWardIds(u) {
    return db.prepare('SELECT id FROM wards WHERE mc_user_id = ?').all(u.id).map((r) => r.id);
  }
  function myVillageIds(u) {
    return db.prepare('SELECT id FROM villages WHERE sarpanch_user_id = ?').all(u.id).map((r) => r.id);
  }
  function areaScope(u, wardCol = 'ward_id', villageCol = 'village_id') {
    if (u.role === 'MC') {
      const ids = myWardIds(u);
      return { sql: `${wardCol} IN (${ids.map(() => '?').join(',') || 'NULL'})`, args: ids };
    }
    if (u.role === 'SARPANCH') {
      const ids = myVillageIds(u);
      return { sql: `${villageCol} IN (${ids.map(() => '?').join(',') || 'NULL'})`, args: ids };
    }
    return null;
  }
  function listDistrictScope(req, col = 'district_id') {
    if (req.user.role === 'STATE_ADMIN') {
      const id = intOrNull(req.query.district_id, 'district_id');
      return id ? { sql: `${col} = ?`, args: [id] } : { sql: '1=1', args: [] };
    }
    return { sql: `${col} = ?`, args: [req.user.district_id] };
  }

  // ---------- auth ----------
  const failures = new Map(); // phone -> { n, until }
  app.post('/api/auth/login', (req, res) => {
    const ph = str(req.body.phone, 'Phone', { max: 10 });
    const f = failures.get(ph);
    if (f && f.until > Date.now()) throw new HttpError(429, 'Too many failed attempts. Try again in 15 minutes.');
    const u = db.prepare('SELECT * FROM users WHERE phone = ? AND active = 1').get(ph);
    if (!u || !verifyPassword(String(req.body.password || ''), u.password_hash)) {
      const expired = f && f.until && f.until <= Date.now();
      const n = (f && !expired ? f.n : 0) + 1;
      failures.set(ph, { n, until: n >= 5 ? Date.now() + 15 * 60 * 1000 : 0 });
      throw new HttpError(401, 'Invalid phone or password');
    }
    failures.delete(ph);
    res.json({ token: createSession(db, u.id), user: publicUser(u) });
  });
  const publicUser = (u) => ({
    id: u.id, name: u.name, phone: u.phone, role: u.role,
    district_id: u.district_id, must_change_password: !!u.must_change_password,
  });
  app.post('/api/auth/logout', requireAuth, (req, res) => {
    db.prepare('DELETE FROM sessions WHERE token = ?').run(req.token);
    res.json({ ok: true });
  });
  app.get('/api/auth/me', requireAuth, (req, res) => {
    const d = req.user.district_id
      ? db.prepare('SELECT id, name, state FROM districts WHERE id = ?').get(req.user.district_id) : null;
    res.json({ user: publicUser(req.user), district: d });
  });
  app.post('/api/auth/change-password', requireAuth, (req, res) => {
    const { old_password, new_password } = req.body;
    if (typeof new_password !== 'string' || new_password.length < 8) throw bad('New password must be at least 8 characters');
    const u = db.prepare('SELECT * FROM users WHERE id = ?').get(req.user.id);
    if (!verifyPassword(String(old_password || ''), u.password_hash)) throw new HttpError(401, 'Current password is wrong');
    db.prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
      .run(hashPassword(new_password), u.id);
    db.prepare('DELETE FROM sessions WHERE user_id = ? AND token != ?').run(u.id, req.token);
    audit(req, 'change_password', 'user', u.id);
    res.json({ ok: true });
  });

  // ---------- public lookups (for citizen self-registration) ----------
  app.get('/api/public/districts', (_req, res) => {
    res.json(db.prepare('SELECT id, name, state FROM districts ORDER BY state, name').all());
  });
  app.get('/api/public/areas', (req, res) => {
    const id = intOrNull(req.query.district_id, 'district_id');
    if (!id) throw bad('district_id is required');
    res.json({
      wards: db.prepare('SELECT id, city, ward_no, ward_name FROM wards WHERE district_id = ? ORDER BY city, ward_no').all(id),
      villages: db.prepare('SELECT id, block, name FROM villages WHERE district_id = ? ORDER BY name').all(id),
    });
  });

  // ---------- districts & collectors (State Admin) ----------
  app.get('/api/districts', requireAuth, (req, res) => {
    const rows = req.user.role === 'STATE_ADMIN'
      ? db.prepare(`SELECT d.*, u.name AS collector_name, u.phone AS collector_phone
                      FROM districts d
                      LEFT JOIN collectors c ON c.district_id = d.id AND c.active = 1
                      LEFT JOIN users u ON u.id = c.user_id
                     ORDER BY d.state, d.name`).all()
      : db.prepare('SELECT * FROM districts WHERE id = ?').all(req.user.district_id);
    res.json(rows);
  });
  app.post('/api/districts', requireRole('STATE_ADMIN'), (req, res) => {
    const name = str(req.body.name, 'District name');
    const state = str(req.body.state, 'State');
    const code = str(req.body.code, 'District code', { max: 10 }).toUpperCase();
    const r = db.prepare('INSERT INTO districts (name, state, code) VALUES (?,?,?)').run(name, state, code);
    audit(req, 'create', 'district', Number(r.lastInsertRowid), { name, state, code });
    res.status(201).json({ id: Number(r.lastInsertRowid), name, state, code });
  });

  app.get('/api/collectors', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR'), (req, res) => {
    const s = listDistrictScope(req, 'c.district_id');
    res.json(db.prepare(
      `SELECT c.id, c.employee_id, c.email, c.active, c.district_id, d.name AS district,
              u.name, u.phone
         FROM collectors c JOIN users u ON u.id = c.user_id JOIN districts d ON d.id = c.district_id
        WHERE ${s.sql} ORDER BY d.name, c.active DESC, c.id DESC`).all(...s.args));
  });
  // Registering a new collector deactivates the previous one (transfer); history is kept.
  app.post('/api/collectors', requireRole('STATE_ADMIN'), (req, res) => {
    const district_id = districtFor(req, req.body.district_id);
    const name = str(req.body.name, 'Name');
    const ph = phone(req.body.phone);
    const employee_id = str(req.body.employee_id, 'Employee ID', { max: 40 });
    const email = str(req.body.email, 'Email', { required: false, max: 120 });
    const out = tx(() => {
      const old = db.prepare('SELECT id, user_id FROM collectors WHERE district_id = ? AND active = 1').all(district_id);
      for (const o of old) {
        db.prepare('UPDATE collectors SET active = 0 WHERE id = ?').run(o.id);
        db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(o.user_id);
      }
      const u = createUser({ name, phone: ph, role: 'DISTRICT_COLLECTOR', district_id });
      const r = db.prepare('INSERT INTO collectors (user_id, district_id, employee_id, email) VALUES (?,?,?,?)')
        .run(u.id, district_id, employee_id, email);
      return { id: Number(r.lastInsertRowid), temp_password: u.temp_password, replaced: old.length };
    });
    audit(req, 'create', 'collector', out.id, { district_id, phone: ph });
    res.status(201).json(out);
  });

  // ---------- urban wards + MC ----------
  app.get('/api/wards', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC'), (req, res) => {
    const s = listDistrictScope(req, 'w.district_id');
    const a = areaScope(req.user, 'w.id');
    res.json(db.prepare(
      `SELECT w.id, w.district_id, w.city, w.ward_no, w.ward_name,
              u.name AS mc_name, u.phone AS mc_phone,
              (SELECT COUNT(*) FROM households h WHERE h.ward_id = w.id) AS households,
              (SELECT COUNT(*) FROM vehicles v WHERE v.ward_id = w.id AND v.status = 'ACTIVE') AS vehicles
         FROM wards w LEFT JOIN users u ON u.id = w.mc_user_id
        WHERE ${s.sql}${a ? ' AND ' + a.sql : ''} ORDER BY w.city, w.ward_no`
    ).all(...s.args, ...(a ? a.args : [])));
  });
  app.post('/api/wards', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR'), (req, res) => {
    const district_id = districtFor(req, req.body.district_id);
    const city = str(req.body.city, 'City');
    const ward_no = str(req.body.ward_no, 'Ward number', { max: 20 });
    const ward_name = str(req.body.ward_name, 'Ward name', { required: false });
    const mcName = str(req.body.mc_name, 'MC name');
    const mcPhone = phone(req.body.mc_phone, 'MC phone');
    const out = tx(() => {
      const mc = createUser({ name: mcName, phone: mcPhone, role: 'MC', district_id });
      const r = db.prepare('INSERT INTO wards (district_id, city, ward_no, ward_name, mc_user_id) VALUES (?,?,?,?,?)')
        .run(district_id, city, ward_no, ward_name, mc.id);
      return { id: Number(r.lastInsertRowid), mc_temp_password: mc.temp_password };
    });
    audit(req, 'create', 'ward', out.id, { district_id, city, ward_no });
    res.status(201).json(out);
  });

  // ---------- rural villages + sarpanch ----------
  app.get('/api/villages', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'SARPANCH'), (req, res) => {
    const s = listDistrictScope(req, 'v.district_id');
    const a = areaScope(req.user, 'NULL', 'v.id');
    res.json(db.prepare(
      `SELECT v.id, v.district_id, v.block, v.name,
              u.name AS sarpanch_name, u.phone AS sarpanch_phone,
              (SELECT COUNT(*) FROM households h WHERE h.village_id = v.id) AS households,
              (SELECT COUNT(*) FROM vehicles x WHERE x.village_id = v.id AND x.status = 'ACTIVE') AS vehicles
         FROM villages v LEFT JOIN users u ON u.id = v.sarpanch_user_id
        WHERE ${s.sql}${a ? ' AND ' + a.sql : ''} ORDER BY v.name`
    ).all(...s.args, ...(a ? a.args : [])));
  });
  app.post('/api/villages', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR'), (req, res) => {
    const district_id = districtFor(req, req.body.district_id);
    const name = str(req.body.name, 'Village name');
    const block = str(req.body.block, 'Block', { required: false });
    const spName = str(req.body.sarpanch_name, 'Sarpanch name');
    const spPhone = phone(req.body.sarpanch_phone, 'Sarpanch phone');
    const out = tx(() => {
      const sp = createUser({ name: spName, phone: spPhone, role: 'SARPANCH', district_id });
      const r = db.prepare('INSERT INTO villages (district_id, block, name, sarpanch_user_id) VALUES (?,?,?,?)')
        .run(district_id, block, name, sp.id);
      return { id: Number(r.lastInsertRowid), sarpanch_temp_password: sp.temp_password };
    });
    audit(req, 'create', 'village', out.id, { district_id, name });
    res.status(201).json(out);
  });

  // ---------- vehicles ----------
  const canManageArea = (u, ward_id, village_id) => {
    if (u.role === 'MC') return ward_id != null && myWardIds(u).includes(ward_id);
    if (u.role === 'SARPANCH') return village_id != null && myVillageIds(u).includes(village_id);
    return true;
  };
  function resolveArea(district_id, body) {
    const ward_id = intOrNull(body.ward_id, 'ward_id');
    const village_id = intOrNull(body.village_id, 'village_id');
    if (ward_id && village_id) throw bad('Choose either a ward or a village, not both');
    let city = null;
    if (ward_id) {
      const w = db.prepare('SELECT city FROM wards WHERE id = ? AND district_id = ?').get(ward_id, district_id);
      if (!w) throw bad('Ward not found in this district');
      city = w.city;
    }
    if (village_id && !db.prepare('SELECT 1 FROM villages WHERE id = ? AND district_id = ?').get(village_id, district_id))
      throw bad('Village not found in this district');
    return { ward_id, village_id, city };
  }

  app.get('/api/vehicles', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH'), (req, res) => {
    const s = listDistrictScope(req, 'v.district_id');
    const a = areaScope(req.user, 'v.ward_id', 'v.village_id');
    res.json(db.prepare(
      `SELECT v.*, w.ward_no, w.ward_name, vi.name AS village_name,
              (SELECT group_concat(u.name || ' (' || s.staff_role || ')', ', ')
                 FROM staff s JOIN users u ON u.id = s.user_id WHERE s.vehicle_id = v.id) AS crew
         FROM vehicles v
         LEFT JOIN wards w ON w.id = v.ward_id
         LEFT JOIN villages vi ON vi.id = v.village_id
        WHERE ${s.sql}${a ? ' AND ' + a.sql : ''} ORDER BY v.city, v.reg_number`
    ).all(...s.args, ...(a ? a.args : [])));
  });
  app.post('/api/vehicles', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH'), (req, res) => {
    const district_id = districtFor(req, req.body.district_id);
    const reg = str(req.body.reg_number, 'Registration number', { max: 20 }).toUpperCase().replace(/[\s-]/g, '');
    if (!REG_RE.test(reg)) throw bad('Registration number looks invalid (example: HR16AB1234)');
    const type = str(req.body.type, 'Vehicle type');
    if (!VEHICLE_TYPES.includes(type)) throw bad('Unknown vehicle type');
    const capacity_kg = intOrNull(req.body.capacity_kg, 'Capacity');
    const area = resolveArea(district_id, req.body);
    if (!canManageArea(req.user, area.ward_id, area.village_id))
      throw new HttpError(403, 'You can only register vehicles for your own ward/village');
    const r = db.prepare(
      `INSERT INTO vehicles (district_id, city, reg_number, type, capacity_kg, ward_id, village_id)
       VALUES (?,?,?,?,?,?,?)`
    ).run(district_id, area.city, reg, type, capacity_kg, area.ward_id, area.village_id);
    audit(req, 'create', 'vehicle', Number(r.lastInsertRowid), { reg });
    res.status(201).json({ id: Number(r.lastInsertRowid), reg_number: reg });
  });
  app.patch('/api/vehicles/:id', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH'), (req, res) => {
    const v = db.prepare('SELECT * FROM vehicles WHERE id = ?').get(Number(req.params.id));
    if (!v || (req.user.role !== 'STATE_ADMIN' && v.district_id !== req.user.district_id)) throw new HttpError(404, 'Vehicle not found');
    if (!canManageArea(req.user, v.ward_id, v.village_id)) throw new HttpError(403, 'Not your vehicle');
    const status = req.body.status;
    if (!['ACTIVE', 'MAINTENANCE', 'RETIRED'].includes(status)) throw bad('Invalid status');
    db.prepare('UPDATE vehicles SET status = ? WHERE id = ?').run(status, v.id);
    audit(req, 'update_status', 'vehicle', v.id, { from: v.status, to: status });
    res.json({ ok: true });
  });

  // ---------- drivers & staff ----------
  app.get('/api/staff', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH'), (req, res) => {
    const s = listDistrictScope(req, 's.district_id');
    const a = areaScope(req.user, 'v.ward_id', 'v.village_id');
    res.json(db.prepare(
      `SELECT s.id, s.staff_role, s.licence_no, s.vehicle_id, s.district_id,
              u.name, u.phone, v.reg_number
         FROM staff s JOIN users u ON u.id = s.user_id
         LEFT JOIN vehicles v ON v.id = s.vehicle_id
        WHERE ${s.sql}${a ? ' AND ' + a.sql : ''} ORDER BY u.name`
    ).all(...s.args, ...(a ? a.args : [])));
  });
  app.post('/api/staff', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH'), (req, res) => {
    const district_id = districtFor(req, req.body.district_id);
    const name = str(req.body.name, 'Name');
    const ph = phone(req.body.phone);
    const staff_role = str(req.body.staff_role, 'Role');
    if (!STAFF_ROLES.includes(staff_role)) throw bad('Role must be DRIVER, HELPER or SUPERVISOR');
    const licence_no = str(req.body.licence_no, 'Licence number', { required: staff_role === 'DRIVER', max: 30 });
    const vehicle_id = intOrNull(req.body.vehicle_id, 'vehicle_id');
    if (vehicle_id) {
      const v = db.prepare('SELECT * FROM vehicles WHERE id = ? AND district_id = ?').get(vehicle_id, district_id);
      if (!v) throw bad('Vehicle not found in this district');
      if (!canManageArea(req.user, v.ward_id, v.village_id)) throw new HttpError(403, 'Not your vehicle');
    } else if (req.user.role === 'MC' || req.user.role === 'SARPANCH') {
      throw bad('Choose a vehicle from your area');
    }
    const out = tx(() => {
      const u = createUser({ name, phone: ph, role: 'STAFF', district_id });
      const r = db.prepare('INSERT INTO staff (user_id, district_id, staff_role, licence_no, vehicle_id) VALUES (?,?,?,?,?)')
        .run(u.id, district_id, staff_role, licence_no, vehicle_id);
      return { id: Number(r.lastInsertRowid), temp_password: u.temp_password };
    });
    audit(req, 'create', 'staff', out.id, { staff_role });
    res.status(201).json(out);
  });

  // ---------- citizens / households ----------
  app.post('/api/auth/register-citizen', (req, res) => {
    const name = str(req.body.name, 'Name');
    const ph = phone(req.body.phone);
    const password = req.body.password;
    if (typeof password !== 'string' || password.length < 8) throw bad('Password must be at least 8 characters');
    const district_id = intOrNull(req.body.district_id, 'district_id');
    if (!district_id || !db.prepare('SELECT 1 FROM districts WHERE id = ?').get(district_id)) throw bad('Choose a district');
    const area_type = req.body.area_type;
    if (!['URBAN', 'RURAL'].includes(area_type)) throw bad('Choose urban or rural');
    const ward_id = area_type === 'URBAN' ? intOrNull(req.body.ward_id, 'ward_id') : null;
    const village_id = area_type === 'RURAL' ? intOrNull(req.body.village_id, 'village_id') : null;
    if (area_type === 'URBAN' && !db.prepare('SELECT 1 FROM wards WHERE id = ? AND district_id = ?').get(ward_id, district_id)) throw bad('Choose your ward');
    if (area_type === 'RURAL' && !db.prepare('SELECT 1 FROM villages WHERE id = ? AND district_id = ?').get(village_id, district_id)) throw bad('Choose your village');
    const house_no = str(req.body.house_no, 'House number', { max: 40 });
    const address = str(req.body.address, 'Address', { required: false, max: 250 });
    const members = intOrNull(req.body.members, 'Family members');
    const out = tx(() => {
      const u = createUser({ name, phone: ph, role: 'CITIZEN', district_id, password });
      const r = db.prepare(
        `INSERT INTO households (user_id, district_id, area_type, ward_id, village_id, house_no, address, members)
         VALUES (?,?,?,?,?,?,?,?)`
      ).run(u.id, district_id, area_type, ward_id, village_id, house_no, address, members);
      return { id: Number(r.lastInsertRowid), user_id: u.id };
    });
    res.status(201).json({ id: out.id, token: createSession(db, out.user_id) });
  });

  app.get('/api/households', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH'), (req, res) => {
    const s = listDistrictScope(req, 'h.district_id');
    const a = areaScope(req.user, 'h.ward_id', 'h.village_id');
    res.json(db.prepare(
      `SELECT h.id, h.area_type, h.house_no, h.address, h.members, h.created_at, h.district_id,
              u.name, u.phone, w.city, w.ward_no, vi.name AS village_name
         FROM households h JOIN users u ON u.id = h.user_id
         LEFT JOIN wards w ON w.id = h.ward_id
         LEFT JOIN villages vi ON vi.id = h.village_id
        WHERE ${s.sql}${a ? ' AND ' + a.sql : ''} ORDER BY h.id DESC LIMIT 500`
    ).all(...s.args, ...(a ? a.args : [])));
  });

  // What a staff member / citizen sees about themselves.
  app.get('/api/me/profile', requireRole('STAFF', 'CITIZEN'), (req, res) => {
    if (req.user.role === 'STAFF') {
      return res.json(db.prepare(
        `SELECT s.staff_role, s.licence_no, v.reg_number, v.type, v.city, w.ward_no, vi.name AS village_name
           FROM staff s LEFT JOIN vehicles v ON v.id = s.vehicle_id
           LEFT JOIN wards w ON w.id = v.ward_id LEFT JOIN villages vi ON vi.id = v.village_id
          WHERE s.user_id = ?`).get(req.user.id) || {});
    }
    res.json(db.prepare(
      `SELECT h.house_no, h.address, h.members, h.area_type, w.city, w.ward_no, vi.name AS village_name,
              mc.name AS mc_name, mc.phone AS mc_phone, sp.name AS sarpanch_name, sp.phone AS sarpanch_phone
         FROM households h
         LEFT JOIN wards w ON w.id = h.ward_id LEFT JOIN users mc ON mc.id = w.mc_user_id
         LEFT JOIN villages vi ON vi.id = h.village_id LEFT JOIN users sp ON sp.id = vi.sarpanch_user_id
        WHERE h.user_id = ?`).get(req.user.id) || {});
  });

  // ---------- dashboard ----------
  app.get('/api/stats', requireRole('STATE_ADMIN', 'DISTRICT_COLLECTOR', 'MC', 'SARPANCH'), (req, res) => {
    const one = (sql, ...args) => db.prepare(sql).get(...args).n;
    const dflt = listDistrictScope(req, 'district_id');
    const a = areaScope(req.user);
    const aw = a ? ' AND ' + a.sql : '';
    const out = {
      households: one(`SELECT COUNT(*) n FROM households WHERE ${dflt.sql}${aw}`, ...dflt.args, ...(a ? a.args : [])),
      vehicles: one(`SELECT COUNT(*) n FROM vehicles WHERE ${dflt.sql}${aw}`, ...dflt.args, ...(a ? a.args : [])),
      vehicles_active: one(`SELECT COUNT(*) n FROM vehicles WHERE status='ACTIVE' AND ${dflt.sql}${aw}`, ...dflt.args, ...(a ? a.args : [])),
    };
    if (req.user.role === 'STATE_ADMIN' || req.user.role === 'DISTRICT_COLLECTOR') {
      out.wards = one(`SELECT COUNT(*) n FROM wards WHERE ${dflt.sql}`, ...dflt.args);
      out.villages = one(`SELECT COUNT(*) n FROM villages WHERE ${dflt.sql}`, ...dflt.args);
      out.staff = one(`SELECT COUNT(*) n FROM staff WHERE ${dflt.sql}`, ...dflt.args);
    }
    if (req.user.role === 'STATE_ADMIN') out.districts = one('SELECT COUNT(*) n FROM districts');
    res.json(out);
  });

  // ---------- static + errors ----------
  app.use(express.static(path.join(__dirname, '..', 'public')));
  app.use('/api', (_req, res) => res.status(404).json({ error: 'Not found' }));
  app.use((err, _req, res, _next) => {
    if (err instanceof HttpError) return res.status(err.status).json({ error: err.message });
    if (err && /UNIQUE constraint failed/.test(err.message)) {
      const f = err.message.split(': ')[1] || '';
      const msg = f.includes('users.phone') ? 'This phone number is already registered'
        : f.includes('reg_number') ? 'This vehicle is already registered'
        : f.includes('wards') ? 'This ward already exists'
        : f.includes('villages') ? 'This village already exists'
        : f.includes('districts') ? 'This district (or code) already exists'
        : 'Duplicate entry';
      return res.status(409).json({ error: msg });
    }
    if (err instanceof SyntaxError) return res.status(400).json({ error: 'Invalid JSON' });
    console.error(err);
    res.status(500).json({ error: 'Something went wrong' });
  });

  return app;
}

module.exports = { createApp };

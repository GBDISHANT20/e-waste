const test = require('node:test');
const assert = require('node:assert/strict');
const { openDb } = require('../server/db');
const { createApp } = require('../server/app');
const { seedAdmin } = require('../server/seed');

let server, base;
test.before(async () => {
  process.env.ADMIN_PASSWORD = 'AdminPass123';
  const db = openDb(':memory:');
  seedAdmin(db);
  server = createApp(db).listen(0);
  base = `http://127.0.0.1:${server.address().port}/api`;
});
test.after(() => server.close());

async function call(method, path, body, token) {
  const r = await fetch(base + path, {
    method,
    headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: r.status, body: await r.json() };
}
const login = async (phone, password) => (await call('POST', '/auth/login', { phone, password })).body.token;

test('end-to-end registration flow with role scoping', async () => {
  const admin = await login('9999999999', 'AdminPass123');
  assert.ok(admin);

  // anonymous / wrong-role access is blocked
  assert.equal((await call('GET', '/districts')).status, 401);

  const d1 = (await call('POST', '/districts', { name: 'Bhiwani', state: 'Haryana', code: 'BWN' }, admin)).body;
  const d2 = (await call('POST', '/districts', { name: 'Hisar', state: 'Haryana', code: 'HSR' }, admin)).body;
  assert.equal((await call('POST', '/districts', { name: 'X', state: 'Haryana', code: 'BWN' }, admin)).status, 409);

  const c1 = (await call('POST', '/collectors', { district_id: d1.id, name: 'DC One', phone: '9810000001', employee_id: 'E1' }, admin)).body;
  assert.ok(c1.temp_password);
  const dc = await login('9810000001', c1.temp_password);

  // collector registers ward+MC, village+sarpanch
  const w = (await call('POST', '/wards', { city: 'Bhiwani', ward_no: '5', mc_name: 'MC Five', mc_phone: '9810000005' }, dc)).body;
  const w2 = (await call('POST', '/wards', { city: 'Bhiwani', ward_no: '6', mc_name: 'MC Six', mc_phone: '9810000006' }, dc)).body;
  const v = (await call('POST', '/villages', { name: 'Dhani Mahu', block: 'Bhiwani', sarpanch_name: 'Sarpanch D', sarpanch_phone: '9810000007' }, dc)).body;
  assert.ok(w.mc_temp_password && v.sarpanch_temp_password);

  // vehicles
  assert.equal((await call('POST', '/vehicles', { reg_number: 'bad', type: 'TIPPER', ward_id: w.id }, dc)).status, 400);
  assert.equal((await call('POST', '/vehicles', { reg_number: 'HR16AB1234', type: 'TIPPER', ward_id: w.id, village_id: v.id }, dc)).status, 400);
  const veh = (await call('POST', '/vehicles', { reg_number: 'hr 16 ab 1234', type: 'TIPPER', capacity_kg: 1500, ward_id: w.id }, dc)).body;
  assert.equal(veh.reg_number, 'HR16AB1234');
  assert.equal((await call('POST', '/vehicles', { reg_number: 'HR16AB1234', type: 'TIPPER', ward_id: w.id }, dc)).status, 409);
  const veh2 = (await call('POST', '/vehicles', { reg_number: 'HR16CD5678', type: 'HANDCART', ward_id: w2.id }, dc)).body;

  // staff
  assert.equal((await call('POST', '/staff', { name: 'Ram', phone: '9810000010', staff_role: 'DRIVER', vehicle_id: veh.id }, dc)).status, 400); // licence required
  const st = (await call('POST', '/staff', { name: 'Ram', phone: '9810000010', staff_role: 'DRIVER', licence_no: 'HR0620200001', vehicle_id: veh.id }, dc)).body;
  assert.ok(st.temp_password);

  // citizen self-registration
  const cz = await call('POST', '/auth/register-citizen', { name: 'Sita', phone: '9810000020', password: 'longenough1', district_id: d1.id, area_type: 'URBAN', ward_id: w.id, house_no: '12/A' });
  assert.equal(cz.status, 201);
  assert.equal((await call('POST', '/auth/register-citizen', { name: 'Bad', phone: '9810000021', password: 'longenough1', district_id: d1.id, area_type: 'URBAN', ward_id: 9999, house_no: '1' })).status, 400);
  const rural = await call('POST', '/auth/register-citizen', { name: 'Gopal', phone: '9810000022', password: 'longenough1', district_id: d1.id, area_type: 'RURAL', village_id: v.id, house_no: '3' });
  assert.equal(rural.status, 201);

  // MC sees only their ward
  const mc = await login('9810000005', w.mc_temp_password);
  assert.equal((await call('GET', '/wards', null, mc)).body.length, 1);
  assert.equal((await call('GET', '/households', null, mc)).body.length, 1);
  assert.equal((await call('GET', '/vehicles', null, mc)).body.length, 1);
  // ...and cannot register a vehicle in another ward or touch others'
  assert.equal((await call('POST', '/vehicles', { reg_number: 'HR16EF9999', type: 'TIPPER', ward_id: w2.id }, mc)).status, 403);
  assert.equal((await call('PATCH', '/vehicles/' + veh2.id, { status: 'RETIRED' }, mc)).status, 403);
  assert.equal((await call('PATCH', '/vehicles/' + veh.id, { status: 'MAINTENANCE' }, mc)).status, 200);
  assert.equal((await call('POST', '/wards', { city: 'X', ward_no: '1', mc_name: 'a', mc_phone: '9810000099' }, mc)).status, 403);

  // sarpanch sees only their village's data
  const sp = await login('9810000007', v.sarpanch_temp_password);
  assert.equal((await call('GET', '/households', null, sp)).body.length, 1);
  assert.equal((await call('GET', '/vehicles', null, sp)).body.length, 0);

  // citizen & staff can't see lists; can see own profile
  const citizen = await login('9810000020', 'longenough1');
  assert.equal((await call('GET', '/households', null, citizen)).status, 403);
  assert.equal((await call('GET', '/me/profile', null, citizen)).body.mc_name, 'MC Five');
  const driver = await login('9810000010', st.temp_password);
  assert.equal((await call('GET', '/me/profile', null, driver)).body.reg_number, 'HR16AB1234');

  // district isolation: Hisar collector sees nothing of Bhiwani
  const c2 = (await call('POST', '/collectors', { district_id: d2.id, name: 'DC Two', phone: '9820000001', employee_id: 'E2' }, admin)).body;
  const dc2 = await login('9820000001', c2.temp_password);
  assert.equal((await call('GET', '/wards', null, dc2)).body.length, 0);
  assert.equal((await call('GET', '/households', null, dc2)).body.length, 0);
  assert.equal((await call('PATCH', '/vehicles/' + veh.id, { status: 'RETIRED' }, dc2)).status, 404);

  // collector transfer: old account deactivated, data kept
  const c1b = (await call('POST', '/collectors', { district_id: d1.id, name: 'DC New', phone: '9810000002', employee_id: 'E3' }, admin)).body;
  assert.equal(c1b.replaced, 1);
  assert.equal((await call('POST', '/auth/login', { phone: '9810000001', password: c1.temp_password })).status, 401);
  const dcNew = await login('9810000002', c1b.temp_password);
  assert.equal((await call('GET', '/wards', null, dcNew)).body.length, 2);

  // duplicate phone, forced password change flow
  assert.equal((await call('POST', '/auth/register-citizen', { name: 'Dup', phone: '9810000020', password: 'longenough1', district_id: d1.id, area_type: 'URBAN', ward_id: w.id, house_no: '9' })).status, 409);
  assert.equal((await call('POST', '/auth/change-password', { old_password: w.mc_temp_password, new_password: 'short' }, mc)).status, 400);
  assert.equal((await call('POST', '/auth/change-password', { old_password: w.mc_temp_password, new_password: 'NewPassword9' }, mc)).status, 200);
  assert.ok(await login('9810000005', 'NewPassword9'));

  // stats
  const stats = (await call('GET', '/stats', null, dcNew)).body;
  assert.deepEqual([stats.wards, stats.villages, stats.vehicles, stats.staff, stats.households], [2, 1, 2, 1, 2]);
});

test('login lockout after repeated failures', async () => {
  for (let i = 0; i < 5; i++) await call('POST', '/auth/login', { phone: '9000000000', password: 'x' });
  assert.equal((await call('POST', '/auth/login', { phone: '9000000000', password: 'x' })).status, 429);
});

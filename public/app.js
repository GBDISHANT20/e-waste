(() => {
'use strict';

const ROLE_LABEL = {
  STATE_ADMIN: 'State Admin', DISTRICT_COLLECTOR: 'District Collector', MC: 'Municipal Councillor (MC)',
  SARPANCH: 'Sarpanch', STAFF: 'Driver / Staff', CITIZEN: 'Citizen',
};
const VEHICLE_TYPES = {
  TRACTOR_TROLLEY: 'Tractor trolley', E_RICKSHAW: 'E-rickshaw', MINI_TRUCK: 'Mini truck',
  TIPPER: 'Tipper', COMPACTOR: 'Compactor', HANDCART: 'Handcart',
};
const STAFF_ROLES = { DRIVER: 'Driver', HELPER: 'Helper', SUPERVISOR: 'Supervisor' };

const state = { token: null, user: null, district: null, page: null, flash: null };
try { state.token = sessionStorage.getItem('swm_token'); } catch { /* storage blocked */ }

// ---------- tiny DOM helper (text only, never innerHTML) ----------
function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (v === true) el.setAttribute(k, '');
    else if (v !== false && v != null) el.setAttribute(k, v);
  }
  for (const kid of kids.flat()) {
    if (kid == null || kid === false) continue;
    el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
  }
  return el;
}

async function api(path, opts = {}) {
  const res = await fetch('/api' + path, {
    method: opts.method || 'GET',
    headers: {
      ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
      ...(state.token ? { Authorization: 'Bearer ' + state.token } : {}),
    },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && state.token && !opts.quiet401) { logoutLocal(); throw new Error('Session expired. Please log in again.'); }
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function setToken(t) {
  state.token = t;
  try { t ? sessionStorage.setItem('swm_token', t) : sessionStorage.removeItem('swm_token'); } catch { /* ignore */ }
}
function logoutLocal() { setToken(null); state.user = null; render(); }

const root = document.getElementById('app');
const mount = (...nodes) => { root.replaceChildren(...nodes.filter(Boolean)); };

// ---------- auth screens ----------
function authScreen(initialTab = 'login', msg) {
  let tab = initialTab;
  const box = h('div', { class: 'auth' });
  const draw = () => {
    box.replaceChildren(...[
      h('h1', {}, 'SWM Portal'),
      h('p', { class: 'muted' }, 'Solid Waste Management – district garbage collection management'),
      msg ? h('div', { class: 'notice warn' }, msg) : null,
      h('div', { class: 'tabs' },
        h('button', { class: tab === 'login' ? 'active' : '', onclick: () => { tab = 'login'; draw(); } }, 'Login'),
        h('button', { class: tab === 'register' ? 'active' : '', onclick: () => { tab = 'register'; draw(); } }, 'Citizen sign-up')),
      h('div', { class: 'card' }, tab === 'login' ? loginForm() : null),
    ].filter(Boolean));
    if (tab === 'register') citizenForm(box.querySelector('.card'));
  };
  draw();
  mount(box);
}

function loginForm() {
  const err = h('div', { class: 'notice err', hidden: true });
  const f = h('form', { onsubmit: async (e) => {
    e.preventDefault(); err.hidden = true;
    try {
      const r = await api('/auth/login', { method: 'POST', body: { phone: f.phone.value, password: f.password.value }, quiet401: true });
      setToken(r.token); await boot();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  } },
    err,
    field('Mobile number', h('input', { name: 'phone', inputmode: 'numeric', maxlength: 10, required: true, autocomplete: 'username' })),
    field('Password', h('input', { name: 'password', type: 'password', required: true, autocomplete: 'current-password' })),
    h('button', { type: 'submit' }, 'Login'),
    h('p', { class: 'muted' }, 'Officials receive their first password from the person who registered them.'));
  return f;
}

function field(label, input) { return h('div', {}, h('label', {}, label), input); }

async function citizenForm(card) {
  const err = h('div', { class: 'notice err', hidden: true });
  const districts = await api('/public/districts');
  const area = h('select', { name: 'area_type' }, h('option', { value: 'URBAN' }, 'Urban (city ward)'), h('option', { value: 'RURAL' }, 'Rural (village)'));
  const dist = h('select', { name: 'district_id', required: true }, h('option', { value: '' }, 'Select district'),
    districts.map((d) => h('option', { value: d.id }, `${d.name}, ${d.state}`)));
  const place = h('select', { name: 'place', required: true });
  let areas = { wards: [], villages: [] };
  const fillPlace = () => {
    const urban = area.value === 'URBAN';
    const list = urban ? areas.wards : areas.villages;
    place.replaceChildren(h('option', { value: '' }, urban ? 'Select ward' : 'Select village'),
      ...list.map((x) => h('option', { value: x.id }, urban ? `${x.city} – Ward ${x.ward_no}${x.ward_name ? ' (' + x.ward_name + ')' : ''}` : x.name)));
  };
  dist.addEventListener('change', async () => {
    areas = dist.value ? await api('/public/areas?district_id=' + dist.value) : { wards: [], villages: [] };
    fillPlace();
  });
  area.addEventListener('change', fillPlace);
  fillPlace();
  const f = h('form', { onsubmit: async (e) => {
    e.preventDefault(); err.hidden = true;
    const body = {
      name: f.name.value, phone: f.phone.value, password: f.password.value, district_id: dist.value,
      area_type: area.value, house_no: f.house_no.value, address: f.address.value, members: f.members.value,
      [area.value === 'URBAN' ? 'ward_id' : 'village_id']: place.value,
    };
    try { const r = await api('/auth/register-citizen', { method: 'POST', body, quiet401: true }); setToken(r.token); await boot(); }
    catch (ex) { err.textContent = ex.message; err.hidden = false; }
  } },
    err,
    field('Full name (house owner)', h('input', { name: 'name', required: true })),
    field('Mobile number', h('input', { name: 'phone', inputmode: 'numeric', maxlength: 10, required: true })),
    field('Password (min 8 characters)', h('input', { name: 'password', type: 'password', minlength: 8, required: true, autocomplete: 'new-password' })),
    field('District', dist), field('Area type', area), field('Ward / Village', place),
    field('House number', h('input', { name: 'house_no', required: true })),
    field('Address / landmark', h('input', { name: 'address' })),
    field('Family members', h('input', { name: 'members', type: 'number', min: 1 })),
    h('button', { type: 'submit' }, 'Register my house'));
  card.append(f);
}

function changePasswordScreen(forced) {
  const msg = h('div', { class: 'notice err', hidden: true });
  const f = h('form', { onsubmit: async (e) => {
    e.preventDefault(); msg.hidden = true;
    try {
      await api('/auth/change-password', { method: 'POST', body: { old_password: f.old.value, new_password: f.neu.value } });
      state.user.must_change_password = false; render();
    } catch (ex) { msg.textContent = ex.message; msg.hidden = false; }
  } },
    msg,
    field(forced ? 'Temporary password' : 'Current password', h('input', { name: 'old', type: 'password', required: true })),
    field('New password (min 8 characters)', h('input', { name: 'neu', type: 'password', minlength: 8, required: true, autocomplete: 'new-password' })),
    h('button', { type: 'submit' }, 'Change password'));
  return h('div', { class: 'auth' }, h('h2', {}, forced ? 'Set a new password' : 'Change password'),
    forced ? h('p', { class: 'muted' }, 'For security, please replace your temporary password before continuing.') : null,
    h('div', { class: 'card' }, f));
}

// ---------- generic building blocks ----------
function table(cols, rows, empty = 'Nothing registered yet.') {
  if (!rows.length) return h('div', { class: 'empty' }, empty);
  return h('div', { class: 'table-wrap' }, h('table', {},
    h('thead', {}, h('tr', {}, cols.map((c) => h('th', {}, c.label)))),
    h('tbody', {}, rows.map((r) => h('tr', {}, cols.map((c) => h('td', {}, c.render ? c.render(r) : (r[c.key] ?? '–'))))))));
}

// Renders a registration form. fields: [{name,label,type,options:[[value,label]],required,...}]
function registerForm({ title, fields, submit, onDone }) {
  const note = h('div', { hidden: true });
  const showNote = (cls, ...kids) => { note.className = cls; note.replaceChildren(...kids.filter(Boolean)); note.hidden = false; };
  // A result shown just before a reload (e.g. a one-time temporary password) must survive it.
  if (state.flash && state.flash.page === state.page) showNote(state.flash.cls, ...state.flash.kids());
  const f = h('form', { class: 'grid', onsubmit: async (e) => {
    e.preventDefault(); note.hidden = true; state.flash = null;
    const body = {};
    for (const fd of fields) { const v = f.elements[fd.name].value; if (v !== '') body[fd.name] = v; }
    try {
      const r = await api(submit, { method: 'POST', body });
      f.reset();
      const pw = r.temp_password || r.mc_temp_password || r.sarpanch_temp_password;
      const kids = () => [
        pw ? h('span', {}, 'Registered. Temporary password (shown only once – share it securely): ', h('code', {}, pw))
           : 'Registered successfully.',
        r.replaced ? h('div', {}, 'The previous collector for this district was deactivated; its history is kept.') : null];
      state.flash = { page: state.page, cls: 'notice', kids };
      showNote('notice', ...kids());
      onDone();
    } catch (ex) { showNote('notice err', ex.message); }
  } },
    fields.map((fd) => {
      let input;
      if (fd.options) {
        input = h('select', { name: fd.name, required: !!fd.required },
          h('option', { value: '' }, fd.required ? 'Select…' : '— none —'),
          fd.options.map(([v, l]) => h('option', { value: v }, l)));
      } else {
        input = h('input', { name: fd.name, type: fd.type || 'text', required: !!fd.required, maxlength: fd.maxlength,
          inputmode: fd.inputmode, min: fd.min, placeholder: fd.placeholder });
      }
      return field(fd.label + (fd.required ? ' *' : ''), input);
    }),
    h('div', {}, h('button', { type: 'submit' }, 'Register')));
  return h('div', { class: 'card' }, h('h3', {}, title), note, f);
}

function section(titleText, ...kids) { return h('div', {}, h('h2', {}, titleText), ...kids); }
const opt = (rows, val, lab) => rows.map((r) => [r[val], typeof lab === 'function' ? lab(r) : r[lab]]);
const statusBadge = (s) => h('span', { class: 'badge' + (s === 'ACTIVE' ? '' : s === 'MAINTENANCE' ? ' warn' : ' err') }, s.toLowerCase());
const wardLabel = (w) => `${w.city} – Ward ${w.ward_no}${w.ward_name ? ' (' + w.ward_name + ')' : ''}`;

// ---------- pages ----------
const PAGES = {
  async dashboard() {
    const s = await api('/stats');
    const labels = { districts: 'Districts', wards: 'Urban wards', villages: 'Villages', households: 'Registered houses',
      vehicles: 'Vehicles', vehicles_active: 'Vehicles active', staff: 'Drivers & staff' };
    return section('Overview',
      h('div', { class: 'stats' }, Object.entries(s).map(([k, v]) => h('div', { class: 'stat' }, h('b', {}, v), h('span', {}, labels[k] || k)))));
  },

  async districts(rerender) {
    const rows = await api('/districts');
    return section('Districts',
      registerForm({ title: 'Register a district', submit: '/districts', onDone: rerender, fields: [
        { name: 'name', label: 'District name', required: true },
        { name: 'state', label: 'State', required: true },
        { name: 'code', label: 'District code', required: true, maxlength: 10, placeholder: 'e.g. BWN' }] }),
      h('div', { class: 'card' }, table([
        { label: 'District', key: 'name' }, { label: 'State', key: 'state' }, { label: 'Code', key: 'code' },
        { label: 'Collector', render: (r) => r.collector_name ? `${r.collector_name} (${r.collector_phone})` : h('span', { class: 'badge warn' }, 'not assigned') }], rows)));
  },

  async collectors(rerender) {
    const [rows, districts] = await Promise.all([api('/collectors'), api('/districts')]);
    return section('District Collectors',
      registerForm({ title: 'Register a District Collector (DC/DM)', submit: '/collectors', onDone: rerender, fields: [
        { name: 'district_id', label: 'District', required: true, options: opt(districts, 'id', (d) => `${d.name}, ${d.state}`) },
        { name: 'name', label: 'Full name', required: true },
        { name: 'phone', label: 'Official mobile', required: true, maxlength: 10, inputmode: 'numeric' },
        { name: 'employee_id', label: 'Employee ID', required: true },
        { name: 'email', label: 'Official email', type: 'email' }] }),
      h('p', { class: 'muted' }, 'Registering a new collector for a district deactivates the previous one. Old data stays with the district.'),
      h('div', { class: 'card' }, table([
        { label: 'District', key: 'district' }, { label: 'Name', key: 'name' }, { label: 'Mobile', key: 'phone' },
        { label: 'Employee ID', key: 'employee_id' }, { label: 'Email', key: 'email' },
        { label: 'Status', render: (r) => h('span', { class: 'badge' + (r.active ? '' : ' err') }, r.active ? 'active' : 'transferred') }], rows)));
  },

  async wards(rerender) {
    const rows = await api('/wards');
    const canAdd = state.user.role === 'DISTRICT_COLLECTOR';
    return section('Urban wards & MCs',
      canAdd ? registerForm({ title: 'Register a ward and allot its MC', submit: '/wards', onDone: rerender, fields: [
        { name: 'city', label: 'City / ULB', required: true },
        { name: 'ward_no', label: 'Ward number', required: true },
        { name: 'ward_name', label: 'Ward name' },
        { name: 'mc_name', label: 'MC name', required: true },
        { name: 'mc_phone', label: 'MC mobile', required: true, maxlength: 10, inputmode: 'numeric' }] }) : null,
      h('div', { class: 'card' }, table([
        { label: 'City', key: 'city' }, { label: 'Ward', render: (r) => r.ward_no + (r.ward_name ? ` – ${r.ward_name}` : '') },
        { label: 'MC', key: 'mc_name' }, { label: 'MC mobile', key: 'mc_phone' },
        { label: 'Houses', key: 'households' }, { label: 'Vehicles', key: 'vehicles' }], rows)));
  },

  async villages(rerender) {
    const rows = await api('/villages');
    const canAdd = state.user.role === 'DISTRICT_COLLECTOR';
    return section('Villages & Sarpanches',
      canAdd ? registerForm({ title: 'Register a village and its sarpanch', submit: '/villages', onDone: rerender, fields: [
        { name: 'name', label: 'Village name', required: true },
        { name: 'block', label: 'Block' },
        { name: 'sarpanch_name', label: 'Sarpanch name', required: true },
        { name: 'sarpanch_phone', label: 'Sarpanch mobile', required: true, maxlength: 10, inputmode: 'numeric' }] }) : null,
      h('div', { class: 'card' }, table([
        { label: 'Village', key: 'name' }, { label: 'Block', key: 'block' },
        { label: 'Sarpanch', key: 'sarpanch_name' }, { label: 'Mobile', key: 'sarpanch_phone' },
        { label: 'Houses', key: 'households' }, { label: 'Vehicles', key: 'vehicles' }], rows)));
  },

  async vehicles(rerender) {
    const role = state.user.role;
    const [rows, wards, villages] = await Promise.all([
      api('/vehicles'),
      role === 'SARPANCH' ? [] : api('/wards'),
      role === 'MC' ? [] : api('/villages')]);
    const fields = [
      { name: 'reg_number', label: 'Registration number', required: true, placeholder: 'HR16AB1234', maxlength: 20 },
      { name: 'type', label: 'Vehicle type', required: true, options: Object.entries(VEHICLE_TYPES) },
      { name: 'capacity_kg', label: 'Capacity (kg)', type: 'number', min: 0 }];
    if (wards.length) fields.push({ name: 'ward_id', label: 'Urban ward (city)', options: opt(wards, 'id', wardLabel) });
    if (villages.length) fields.push({ name: 'village_id', label: 'Village (rural)', options: opt(villages, 'id', 'name') });
    const setStatus = async (v, status) => { try { await api('/vehicles/' + v.id, { method: 'PATCH', body: { status } }); rerender(); } catch (e) { alert(e.message); } };
    return section('Garbage collection vehicles',
      registerForm({ title: 'Register a vehicle (choose a ward OR a village)', submit: '/vehicles', onDone: rerender, fields }),
      h('div', { class: 'card' }, table([
        { label: 'Reg. no.', key: 'reg_number' }, { label: 'Type', render: (r) => VEHICLE_TYPES[r.type] || r.type },
        { label: 'Capacity', render: (r) => r.capacity_kg ? r.capacity_kg + ' kg' : '–' },
        { label: 'Area', render: (r) => r.ward_id ? `${r.city} – Ward ${r.ward_no}` : r.village_name || '–' },
        { label: 'Crew', render: (r) => r.crew || '–' },
        { label: 'Status', render: (r) => statusBadge(r.status) },
        { label: '', render: (r) => r.status === 'RETIRED' ? '' : h('select', { onchange: (e) => e.target.value && setStatus(r, e.target.value) },
            h('option', { value: '' }, 'Change…'), ['ACTIVE', 'MAINTENANCE', 'RETIRED'].filter((s) => s !== r.status).map((s) => h('option', { value: s }, s.toLowerCase()))) }], rows)));
  },

  async staff(rerender) {
    const [rows, vehicles] = await Promise.all([api('/staff'), api('/vehicles')]);
    const usable = vehicles.filter((v) => v.status !== 'RETIRED');
    return section('Drivers & staff',
      registerForm({ title: 'Register a driver / helper', submit: '/staff', onDone: rerender, fields: [
        { name: 'name', label: 'Full name', required: true },
        { name: 'phone', label: 'Mobile', required: true, maxlength: 10, inputmode: 'numeric' },
        { name: 'staff_role', label: 'Role', required: true, options: Object.entries(STAFF_ROLES) },
        { name: 'licence_no', label: 'Driving licence no. (drivers)' },
        { name: 'vehicle_id', label: 'Assign vehicle', required: state.user.role !== 'DISTRICT_COLLECTOR',
          options: opt(usable, 'id', (v) => `${v.reg_number} – ${v.city || v.village_name || 'unassigned'}`) }] }),
      h('div', { class: 'card' }, table([
        { label: 'Name', key: 'name' }, { label: 'Mobile', key: 'phone' },
        { label: 'Role', render: (r) => STAFF_ROLES[r.staff_role] }, { label: 'Licence', key: 'licence_no' },
        { label: 'Vehicle', key: 'reg_number' }], rows)));
  },

  async citizens() {
    const rows = await api('/households');
    return section('Registered houses (citizens)',
      h('div', { class: 'card' }, table([
        { label: 'House owner', key: 'name' }, { label: 'Mobile', key: 'phone' }, { label: 'House no.', key: 'house_no' },
        { label: 'Area', render: (r) => r.area_type === 'URBAN' ? `${r.city} – Ward ${r.ward_no}` : r.village_name },
        { label: 'Address', render: (r) => r.address || '–' }, { label: 'Members', render: (r) => r.members ?? '–' },
        { label: 'Registered', render: (r) => r.created_at.slice(0, 10) }], rows, 'No citizens have registered yet.')));
  },

  async profile() {
    const p = await api('/me/profile');
    const u = state.user;
    const rows = u.role === 'STAFF'
      ? [['Role', STAFF_ROLES[p.staff_role]], ['Licence', p.licence_no], ['Vehicle', p.reg_number],
         ['Vehicle type', VEHICLE_TYPES[p.type]], ['Area', p.ward_no ? `${p.city} – Ward ${p.ward_no}` : p.village_name]]
      : [['House no.', p.house_no], ['Address', p.address], ['Family members', p.members],
         ['Area', p.area_type === 'URBAN' ? `${p.city} – Ward ${p.ward_no}` : p.village_name],
         [p.area_type === 'URBAN' ? 'Your MC' : 'Your Sarpanch',
           p.area_type === 'URBAN' ? `${p.mc_name || '–'} (${p.mc_phone || '–'})` : `${p.sarpanch_name || '–'} (${p.sarpanch_phone || '–'})`]];
    return section('My profile', h('div', { class: 'card' }, table([{ label: 'Detail', key: 0 }, { label: 'Value', render: (r) => r[1] || '–' }], rows)));
  },

  async password() { return changePasswordScreen(false); },
};

const NAV = {
  STATE_ADMIN: [['dashboard', 'Overview'], ['districts', 'Districts'], ['collectors', 'District Collectors']],
  DISTRICT_COLLECTOR: [['dashboard', 'Overview'], ['wards', 'Wards & MCs'], ['villages', 'Villages & Sarpanches'],
    ['vehicles', 'Vehicles'], ['staff', 'Drivers & staff'], ['citizens', 'Citizens']],
  MC: [['dashboard', 'Overview'], ['wards', 'My wards'], ['vehicles', 'Vehicles'], ['staff', 'Drivers & staff'], ['citizens', 'Citizens']],
  SARPANCH: [['dashboard', 'Overview'], ['villages', 'My village'], ['vehicles', 'Vehicles'], ['staff', 'Drivers & staff'], ['citizens', 'Citizens']],
  STAFF: [['profile', 'My profile']],
  CITIZEN: [['profile', 'My profile']],
};

// ---------- shell ----------
async function render() {
  if (!state.user) return authScreen();
  if (state.user.must_change_password) {
    return mount(topbar(), changePasswordScreen(true));
  }
  const nav = [...NAV[state.user.role], ['password', 'Change password']];
  if (!state.page || !nav.some(([k]) => k === state.page)) state.page = nav[0][0];
  const content = h('main', {}, h('div', { class: 'empty' }, 'Loading…'));
  const side = h('nav', { class: 'side' }, nav.map(([k, l]) =>
    h('button', { class: k === state.page ? 'active' : '', onclick: () => { state.page = k; state.flash = null; render(); } }, l)));
  mount(topbar(), h('div', { class: 'layout' }, side, content));
  const rerender = () => load();
  const load = async () => {
    try {
      const node = await PAGES[state.page](rerender);
      const keep = state.page; if (keep !== state.page) return;
      content.replaceChildren(node);
    } catch (e) { content.replaceChildren(h('div', { class: 'notice err' }, e.message)); }
  };
  load();
}

function topbar() {
  const u = state.user;
  return h('header', { class: 'topbar' },
    h('span', { class: 'brand' }, 'SWM Portal'),
    h('span', { class: 'who' }, `${u.name} · ${ROLE_LABEL[u.role]}${state.district ? ' · ' + state.district.name : ''}`),
    h('button', { class: 'small', onclick: async () => { try { await api('/auth/logout', { method: 'POST' }); } catch { /* ignore */ } logoutLocal(); } }, 'Logout'));
}

async function boot() {
  if (state.token) {
    try { const me = await api('/auth/me'); state.user = me.user; state.district = me.district; }
    catch { setToken(null); state.user = null; }
  }
  state.page = null;
  render();
}
boot();
})();

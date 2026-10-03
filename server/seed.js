const { hashPassword } = require('./auth');

// Creates the first State Admin if none exists. Password comes from ADMIN_PASSWORD,
// otherwise a random one is printed once.
function seedAdmin(db) {
  const exists = db.prepare("SELECT 1 FROM users WHERE role = 'STATE_ADMIN'").get();
  if (exists) return null;
  const crypto = require('node:crypto');
  const password = process.env.ADMIN_PASSWORD || crypto.randomBytes(9).toString('base64url');
  const phone = process.env.ADMIN_PHONE || '9999999999';
  db.prepare(
    `INSERT INTO users (name, phone, password_hash, role, must_change_password)
     VALUES ('State Admin', ?, ?, 'STATE_ADMIN', ?)`
  ).run(phone, hashPassword(password), process.env.ADMIN_PASSWORD ? 0 : 1);
  console.log(`Created State Admin  phone: ${phone}  password: ${password}  (change it after first login)`);
  return { phone, password };
}

if (require.main === module) {
  const { openDb } = require('./db');
  seedAdmin(openDb());
}

module.exports = { seedAdmin };

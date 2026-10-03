const crypto = require('node:crypto');

const SESSION_HOURS = 12;

function hashPassword(pw) {
  const salt = crypto.randomBytes(16).toString('hex');
  const hash = crypto.scryptSync(pw, salt, 64).toString('hex');
  return `${salt}:${hash}`;
}

function verifyPassword(pw, stored) {
  const [salt, hash] = stored.split(':');
  const test = crypto.scryptSync(pw, salt, 64);
  const expected = Buffer.from(hash, 'hex');
  return expected.length === test.length && crypto.timingSafeEqual(expected, test);
}

function tempPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  return Array.from(crypto.randomBytes(10), (b) => chars[b % chars.length]).join('');
}

function createSession(db, userId) {
  const token = crypto.randomBytes(32).toString('hex');
  const exp = Date.now() + SESSION_HOURS * 3600 * 1000;
  db.prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?,?,?)').run(token, userId, exp);
  return token;
}

function attachUser(db) {
  return (req, _res, next) => {
    const h = req.headers.authorization || '';
    const token = h.startsWith('Bearer ') ? h.slice(7) : null;
    if (token) {
      const row = db.prepare(
        `SELECT u.id, u.name, u.phone, u.role, u.district_id, u.must_change_password
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token = ? AND s.expires_at > ? AND u.active = 1`
      ).get(token, Date.now());
      if (row) { req.user = row; req.token = token; }
    }
    next();
  };
}

const requireAuth = (req, res, next) =>
  req.user ? next() : res.status(401).json({ error: 'Login required' });

const requireRole = (...roles) => (req, res, next) => {
  if (!req.user) return res.status(401).json({ error: 'Login required' });
  if (!roles.includes(req.user.role)) return res.status(403).json({ error: 'Not allowed for your role' });
  next();
};

module.exports = { hashPassword, verifyPassword, tempPassword, createSession, attachUser, requireAuth, requireRole };

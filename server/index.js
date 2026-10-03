const { openDb } = require('./db');
const { createApp } = require('./app');
const { seedAdmin } = require('./seed');

const db = openDb();
seedAdmin(db);
const port = Number(process.env.PORT) || 3000;
createApp(db).listen(port, () => console.log(`SWM portal running on http://localhost:${port}`));

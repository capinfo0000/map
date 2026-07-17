'use strict';

const path = require('path');
const fs = require('fs');
const Database = require('better-sqlite3');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, 'data');
fs.mkdirSync(DATA_DIR, { recursive: true });

const db = new Database(path.join(DATA_DIR, 'parking.db'));
db.pragma('journal_mode = WAL');

db.exec(`
  CREATE TABLE IF NOT EXISTS lots (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT NOT NULL,
    lat               REAL NOT NULL,
    lng               REAL NOT NULL,
    address           TEXT,
    hourly_rate       INTEGER,
    max_rate          INTEGER,
    fee_note          TEXT,
    capacity          INTEGER,
    photo             TEXT,
    nickname          TEXT,
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL,
    confirm_count     INTEGER NOT NULL DEFAULT 0,
    last_confirmed_at TEXT,
    report_count      INTEGER NOT NULL DEFAULT 0
  );

  CREATE TABLE IF NOT EXISTS reports (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    lot_id       INTEGER NOT NULL,
    client_token TEXT NOT NULL,
    kind         TEXT NOT NULL CHECK (kind IN ('confirm','report')),
    comment      TEXT,
    created_at   TEXT NOT NULL,
    FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE,
    UNIQUE (lot_id, client_token, kind)
  );

  CREATE INDEX IF NOT EXISTS idx_lots_latlng ON lots(lat, lng);
  CREATE INDEX IF NOT EXISTS idx_reports_lot ON reports(lot_id);
`);

// ---- 駐車場（lots） -------------------------------------------------------

const insertLotStmt = db.prepare(`
  INSERT INTO lots (name, lat, lng, address, hourly_rate, max_rate, fee_note,
                    capacity, photo, nickname, created_at, updated_at)
  VALUES (@name, @lat, @lng, @address, @hourly_rate, @max_rate, @fee_note,
          @capacity, @photo, @nickname, @now, @now)
`);

function createLot(data) {
  const now = new Date().toISOString();
  const info = insertLotStmt.run({ ...data, now });
  return getLot(info.lastInsertRowid);
}

const getLotStmt = db.prepare('SELECT * FROM lots WHERE id = ?');
function getLot(id) {
  return getLotStmt.get(id);
}

const listLotsStmt = db.prepare('SELECT * FROM lots');
const listLotsBboxStmt = db.prepare(`
  SELECT * FROM lots
  WHERE lat BETWEEN @minLat AND @maxLat
    AND lng BETWEEN @minLng AND @maxLng
`);
function listLots(bbox) {
  if (bbox) return listLotsBboxStmt.all(bbox);
  return listLotsStmt.all();
}

const updateLotStmt = db.prepare(`
  UPDATE lots SET
    name = @name, lat = @lat, lng = @lng, address = @address,
    hourly_rate = @hourly_rate, max_rate = @max_rate, fee_note = @fee_note,
    capacity = @capacity, nickname = @nickname,
    photo = COALESCE(@photo, photo),
    updated_at = @now
  WHERE id = @id
`);

function updateLot(id, data) {
  const now = new Date().toISOString();
  updateLotStmt.run({ ...data, id, now });
  return getLot(id);
}

// ---- 信頼性（confirm / report） -------------------------------------------

const insertReportStmt = db.prepare(`
  INSERT INTO reports (lot_id, client_token, kind, comment, created_at)
  VALUES (@lot_id, @client_token, @kind, @comment, @now)
`);
const bumpConfirmStmt = db.prepare(`
  UPDATE lots SET confirm_count = confirm_count + 1, last_confirmed_at = @now WHERE id = @id
`);
const bumpReportStmt = db.prepare(`
  UPDATE lots SET report_count = report_count + 1 WHERE id = @id
`);

/**
 * confirm / report を記録。1トークン1票（UNIQUE 制約）。
 * @returns {{ok: true, lot: object} | {ok: false, reason: 'duplicate'|'notfound'}}
 */
function addReport(lotId, { client_token, kind, comment }) {
  const lot = getLot(lotId);
  if (!lot) return { ok: false, reason: 'notfound' };
  const now = new Date().toISOString();
  const tx = db.transaction(() => {
    insertReportStmt.run({ lot_id: lotId, client_token, kind, comment: comment || null, now });
    if (kind === 'confirm') bumpConfirmStmt.run({ id: lotId, now });
    else bumpReportStmt.run({ id: lotId });
  });
  try {
    tx();
  } catch (err) {
    if (String(err.message).includes('UNIQUE')) return { ok: false, reason: 'duplicate' };
    throw err;
  }
  return { ok: true, lot: getLot(lotId) };
}

module.exports = {
  db,
  createLot,
  getLot,
  listLots,
  updateLot,
  addReport,
};

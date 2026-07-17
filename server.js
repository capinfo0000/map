'use strict';

const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const express = require('express');
const multer = require('multer');

const dbApi = require('./db');
const { estimate, toPositiveInt } = require('./estimate');

const app = express();
const PORT = process.env.PORT || 3000;

const UPLOAD_DIR = process.env.UPLOAD_DIR || path.join(__dirname, 'uploads');
fs.mkdirSync(UPLOAD_DIR, { recursive: true });

// ---- 写真アップロード（multer） -------------------------------------------
const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, UPLOAD_DIR),
  filename: (req, file, cb) => {
    const ext = (path.extname(file.originalname) || '.jpg').toLowerCase();
    const safeExt = ['.jpg', '.jpeg', '.png', '.webp'].includes(ext) ? ext : '.jpg';
    cb(null, `${Date.now()}-${crypto.randomBytes(6).toString('hex')}${safeExt}`);
  },
});
const upload = multer({
  storage,
  limits: { fileSize: 6 * 1024 * 1024 }, // 6MB（クライアントで縮小済み想定）
  fileFilter: (req, file, cb) => {
    if (/^image\/(jpe?g|png|webp)$/.test(file.mimetype)) cb(null, true);
    else cb(new Error('画像ファイル（JPEG/PNG/WebP）のみアップロードできます'));
  },
});

app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));
app.use('/uploads', express.static(UPLOAD_DIR, { maxAge: '7d' }));
// Leaflet をローカル配信（CDN 依存を避け、社内網/CSP下でも動くように）
app.use('/vendor/leaflet', express.static(path.join(__dirname, 'node_modules/leaflet/dist'), { maxAge: '30d' }));

// ---- ヘルパ ---------------------------------------------------------------

function parseBbox(raw) {
  if (!raw) return null;
  const p = String(raw).split(',').map(Number);
  if (p.length !== 4 || p.some((n) => !Number.isFinite(n))) return null;
  const [minLng, minLat, maxLng, maxLat] = p;
  return { minLng, minLat, maxLng, maxLat };
}

// lot にサーバ計算値（概算額）を付与して返す形に整える
function decorate(lot, hours) {
  return { ...lot, estimate: estimate(lot, hours) };
}

// ---- API: 一覧 ------------------------------------------------------------

app.get('/api/lots', (req, res) => {
  const bbox = parseBbox(req.query.bbox);
  const hours = Number(req.query.hours) > 0 ? Number(req.query.hours) : 1;
  const sort = req.query.sort || 'updated';

  let lots = dbApi.listLots(bbox).map((l) => decorate(l, hours));

  const nullsLast = (v) => (v == null ? Number.POSITIVE_INFINITY : v);
  const cmp = {
    hourly: (a, b) => nullsLast(a.hourly_rate) - nullsLast(b.hourly_rate),
    max: (a, b) => nullsLast(a.max_rate) - nullsLast(b.max_rate),
    estimate: (a, b) => nullsLast(a.estimate) - nullsLast(b.estimate),
    updated: (a, b) => (a.updated_at < b.updated_at ? 1 : -1),
  }[sort] || null;

  if (cmp) lots.sort(cmp);
  res.json({ lots, hours, sort });
});

app.get('/api/lots/:id', (req, res) => {
  const hours = Number(req.query.hours) > 0 ? Number(req.query.hours) : 1;
  const lot = dbApi.getLot(Number(req.params.id));
  if (!lot) return res.status(404).json({ error: 'not found' });
  res.json({ lot: decorate(lot, hours) });
});

// ---- API: 登録・編集 ------------------------------------------------------

function readLotBody(req) {
  const b = req.body || {};
  const lat = Number(b.lat);
  const lng = Number(b.lng);
  const name = (b.name || '').trim();
  if (!name) return { error: '駐車場名を入力してください' };
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return { error: '位置（緯度・経度）が不正です' };
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return { error: '位置の範囲が不正です' };
  return {
    data: {
      name: name.slice(0, 120),
      lat,
      lng,
      address: (b.address || '').trim().slice(0, 200) || null,
      hourly_rate: toPositiveInt(b.hourly_rate),
      max_rate: toPositiveInt(b.max_rate),
      fee_note: (b.fee_note || '').trim().slice(0, 500) || null,
      capacity: toPositiveInt(b.capacity),
      nickname: (b.nickname || '').trim().slice(0, 40) || null,
    },
  };
}

app.post('/api/lots', upload.single('photo'), (req, res) => {
  const parsed = readLotBody(req);
  if (parsed.error) {
    if (req.file) fs.unlink(path.join(UPLOAD_DIR, req.file.filename), () => {});
    return res.status(400).json({ error: parsed.error });
  }
  const data = { ...parsed.data, photo: req.file ? req.file.filename : null };
  const lot = dbApi.createLot(data);
  res.status(201).json({ lot: decorate(lot, 1) });
});

app.put('/api/lots/:id', upload.single('photo'), (req, res) => {
  const id = Number(req.params.id);
  const existing = dbApi.getLot(id);
  if (!existing) {
    if (req.file) fs.unlink(path.join(UPLOAD_DIR, req.file.filename), () => {});
    return res.status(404).json({ error: 'not found' });
  }
  const parsed = readLotBody(req);
  if (parsed.error) {
    if (req.file) fs.unlink(path.join(UPLOAD_DIR, req.file.filename), () => {});
    return res.status(400).json({ error: parsed.error });
  }
  const data = { ...parsed.data, photo: req.file ? req.file.filename : null };
  const lot = dbApi.updateLot(id, data);
  // 写真を差し替えたら古い写真を削除
  if (req.file && existing.photo && existing.photo !== lot.photo) {
    fs.unlink(path.join(UPLOAD_DIR, existing.photo), () => {});
  }
  res.json({ lot: decorate(lot, 1) });
});

// ---- API: 信頼性（confirm / report） --------------------------------------

function handleVote(kind) {
  return (req, res) => {
    const id = Number(req.params.id);
    const token = (req.body && req.body.client_token || '').trim();
    if (!token) return res.status(400).json({ error: 'client_token が必要です' });
    const comment = req.body && req.body.comment;
    const result = dbApi.addReport(id, { client_token: token, kind, comment });
    if (!result.ok && result.reason === 'notfound') return res.status(404).json({ error: 'not found' });
    if (!result.ok && result.reason === 'duplicate') {
      return res.status(409).json({ error: kind === 'confirm' ? 'すでに確認済みです' : 'すでに報告済みです' });
    }
    res.json({ lot: decorate(result.lot, 1) });
  };
}

app.post('/api/lots/:id/confirm', handleVote('confirm'));
app.post('/api/lots/:id/report', handleVote('report'));

// ---- エラーハンドラ（multer 等） ------------------------------------------
app.use((err, req, res, next) => {
  if (err instanceof multer.MulterError || err.message) {
    return res.status(400).json({ error: err.message || 'アップロードに失敗しました' });
  }
  next(err);
});

if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`🅿️  駐車場マップ: http://localhost:${PORT}`);
  });
}

module.exports = app;

'use strict';

/* ============================================================
 * みんなの駐車場マップ — フロントエンド
 * ============================================================ */

// ---- 状態 ----
const state = {
  sort: 'estimate',
  hours: 1,
  lots: [],
  markers: new Map(), // id -> marker
  userPos: null, // {lat, lng}
  addMode: false,
  pending: null, // {lat, lng} 登録中の位置
  editingId: null,
  me: null, // 自分の貢献ランク {points, rank, nextRank, badges, stats, nickname}
  loggedIn: false, // ログイン状態（識別はサーバーのセッション）
  username: null,
};

// ---- 地図初期化 ----
const map = L.map('map', { zoomControl: true }).setView([35.681236, 139.767125], 15); // 東京駅付近を初期表示
// 「Leaflet」表記は任意なので消す（© OpenStreetMap はライセンス上必須のため残す）
map.attributionControl.setPrefix(false);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
}).addTo(map);

let userMarker = null;
let accuracyCircle = null;

// ---- ユーティリティ ----
const $ = (sel) => document.querySelector(sel);
const yen = (n) => (n == null ? '—' : '¥' + Number(n).toLocaleString('ja-JP'));

function toast(msg) {
  const el = $('#toast');
  el.textContent = msg;
  el.classList.remove('hidden');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.add('hidden'), 2600);
}

function daysSince(iso) {
  if (!iso) return null;
  return Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
}
function relTime(iso) {
  const d = daysSince(iso);
  if (d == null) return '不明';
  if (d <= 0) return '今日';
  if (d === 1) return '昨日';
  if (d < 30) return `${d}日前`;
  if (d < 365) return `${Math.floor(d / 30)}ヶ月前`;
  return `${Math.floor(d / 365)}年以上前`;
}

// 情報の鮮度（更新日）からバッジを決める。信頼度は trust 側で扱う。
function freshness(lot) {
  const ref = lot.last_confirmed_at || lot.updated_at;
  const d = daysSince(ref);
  if (d == null) return { cls: 'badge-old', label: '更新日不明' };
  if (d <= 30) return { cls: 'badge-fresh', label: `${relTime(ref)}に更新` };
  if (d <= 90) return { cls: 'badge-mid', label: `${relTime(ref)}に更新` };
  return { cls: 'badge-old', label: `${relTime(ref)}に更新` }; // 3か月超は trust 側で「要更新」表示
}

// 信頼度バッジ（サーバの trust をもとに）。level: unconfirmed|has-info|confirmed|certified|flagged|stale
function trustBadge(lot) {
  const t = lot.trust;
  if (!t) return '';
  const cls = { flagged: 'badge-warn', stale: 'badge-mid', certified: 'badge-cert', confirmed: 'badge-confirmed', 'has-info': 'badge-info', unconfirmed: 'badge-old' }[t.level] || 'badge-old';
  return `<span class="badge ${cls}">${escapeHtml(t.label)}</span>`;
}

// 合意形成 / 再確認をうながすヒント
function trustHint(lot) {
  const t = lot.trust;
  if (!t) return '';
  // 3か月以上更新なし → 再確認を促す
  if (t.level === 'stale') {
    return `<p class="popup-note">🕒 3か月以上更新がありません。現地で確認して「✅正しい」を押すと最新になります</p>`;
  }
  if (t.next == null) return '';
  const goal = t.level === 'confirmed' ? '認定' : 'みんなが確認';
  return `<p class="popup-note">🤝 あと ${t.next} 人の「✅正しい」で「${goal}」になります</p>`;
}

// ピン色: 初期データ(サンプル・未編集)=グレー / ユーザーが登録・上書きした情報=赤
function pinClass(lot) {
  return lot.source === 'osm' ? 'pin-gray' : 'pin-red';
}

function distanceKm(a, b) {
  const R = 6371;
  const dLat = ((b.lat - a.lat) * Math.PI) / 180;
  const dLng = ((b.lng - a.lng) * Math.PI) / 180;
  const s =
    Math.sin(dLat / 2) ** 2 +
    Math.cos((a.lat * Math.PI) / 180) * Math.cos((b.lat * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
  return R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
}
function distLabel(lot) {
  if (!state.userPos) return null;
  const km = distanceKm(state.userPos, lot);
  return km < 1 ? `${Math.round(km * 1000)}m` : `${km.toFixed(1)}km`;
}

function makePinIcon(lot) {
  const cls = pinClass(lot);
  return L.divIcon({
    className: '',
    html: `<div class="pin ${cls}"><span>${lot.estimate != null ? '¥' + shortYen(lot.estimate) : 'P'}</span></div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 30],
    popupAnchor: [0, -28],
  });
}
function shortYen(n) {
  if (n >= 1000) return (n / 1000).toFixed(n % 1000 === 0 ? 0 : 1) + 'k';
  return String(n);
}

// ---- データ取得 & 描画 ----
async function loadLots() {
  // 「近い順」はサーバに無いのでサーバ側は updated で取得し、クライアントで距離ソートする
  const serverSort = state.sort === 'distance' ? 'updated' : state.sort;
  const params = new URLSearchParams({ sort: serverSort, hours: String(state.hours) });

  // ズームインしている時は表示範囲(bbox)で絞り込み、周辺だけを取得
  if (map.getZoom() >= 13) {
    const b = map.getBounds();
    params.set('bbox', [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(','));
  }

  const res = await fetch('/api/lots?' + params.toString());
  const json = await res.json();
  state.lots = json.lots;

  // 近い順（現在地がある時のみ有効。無ければ概算順にフォールバック）
  if (state.sort === 'distance') {
    if (state.userPos) {
      state.lots.sort((a, b) => distanceKm(state.userPos, a) - distanceKm(state.userPos, b));
    } else {
      const nl = (v) => (v == null ? Infinity : v);
      state.lots.sort((a, b) => nl(a.estimate) - nl(b.estimate));
    }
  }

  renderMarkers();
  renderList();
  refreshOsmPins(); // 地図上の「P」(OSMの駐車場)をグレーピンで表示
}

// 地図移動で周辺を再取得（デバウンス）
let _moveTimer = null;
function scheduleReload() {
  clearTimeout(_moveTimer);
  _moveTimer = setTimeout(loadLots, 250);
}

// ---- 地図上の「P」(OpenStreetMap の駐車場) をグレーピンで表示 ----
const osmLayer = L.layerGroup().addTo(map);
let osmFetching = false;
let osmPending = false;          // 取得中に画面が変わったら、あとで取り直す
let osmCoverage = null;          // { bounds: 取得済みの広めの範囲, els }

const OSM_MIN_ZOOM = 13; // これ以上ズームしたら地図上の駐車場(P)を表示

async function refreshOsmPins() {
  // ズームが浅すぎると数が多すぎるので、ある程度拡大したときだけ表示
  if (map.getZoom() < OSM_MIN_ZOOM) { osmLayer.clearLayers(); osmCoverage = null; return; }
  const view = map.getBounds();

  // 直近に取得した「広めの範囲」に収まっていれば、取得せず即描画（体感を高速に）
  if (osmCoverage && osmCoverage.bounds.contains(view)) {
    renderOsmPins(osmCoverage.els);
    return;
  }
  // すでに取得中なら、あとで最新ビューを取り直す（取りこぼし防止）
  if (osmFetching) { osmPending = true; return; }

  osmFetching = true;
  showOsmLoading(true);
  try {
    const padded = view.pad(0.3); // 画面より少し広めに取得 → 小さな移動は再取得不要
    const els = await fetchOverpassParking(padded);
    osmCoverage = { bounds: padded, els };
    renderOsmPins(els);
  } catch (e) { /* 失敗は無視（手動で再操作すれば再取得） */ }
  finally {
    osmFetching = false;
    showOsmLoading(false);
    if (osmPending) { osmPending = false; refreshOsmPins(); }
  }
}

// 読み込み中インジケータ（右下に小さく表示）
let _osmLoadingEl = null;
function showOsmLoading(on) {
  if (!_osmLoadingEl) {
    _osmLoadingEl = document.createElement('div');
    _osmLoadingEl.className = 'osm-loading hidden';
    _osmLoadingEl.textContent = '🅿️ 周辺の駐車場を読み込み中…';
    document.body.appendChild(_osmLoadingEl);
  }
  _osmLoadingEl.classList.toggle('hidden', !on);
}

async function fetchOverpassParking(b) {
  // サーバー側キャッシュ経由で取得（2回目以降は誰でも高速・Overpass負荷も軽減）
  const bbox = [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(',');
  const res = await fetch('/api/parking-nearby?bbox=' + encodeURIComponent(bbox));
  if (!res.ok) return [];
  const d = await res.json();
  return (d.parkings || []).filter((x) => x.lat != null && x.lng != null);
}

function renderOsmPins(els) {
  osmLayer.clearLayers();
  els.forEach((o) => {
    // 既にDBにある駐車場（約30m以内）とは重複表示しない
    const dup = state.lots.some((l) => distanceKm({ lat: o.lat, lng: o.lng }, l) < 0.03);
    if (dup) return;
    const icon = L.divIcon({
      className: '',
      html: '<div class="pin pin-gray pin-osm"><span>P</span></div>',
      iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -28],
    });
    const m = L.marker([o.lat, o.lng], { icon });
    m.bindPopup(osmPopupHtml(o));
    osmLayer.addLayer(m);
  });
}

function osmPopupHtml(o) {
  const nm = o.name ? escapeHtml(o.name) : '駐車場';
  return `<div class="popup">
      <p class="popup-name">${nm}</p>
      <p class="popup-note">🅿️ 地図上の駐車場です。まだ料金情報がありません。</p>
      <div class="popup-actions">
        <button class="act-osmreg" data-lat="${o.lat}" data-lng="${o.lng}" data-name="${nm}">＋ 料金・写真を登録</button>
      </div>
    </div>`;
}

function renderMarkers() {
  // 既存マーカーをクリア
  state.markers.forEach((m) => map.removeLayer(m));
  state.markers.clear();
  state.lots.forEach((lot) => {
    const marker = L.marker([lot.lat, lot.lng], { icon: makePinIcon(lot) }).addTo(map);
    marker.on('click', () => marker.setPopupContent(popupHtml(lot)));
    marker.bindPopup(popupHtml(lot), { minWidth: 220, maxWidth: 260 });
    state.markers.set(lot.id, marker);
  });
}

function popupHtml(lot) {
  const fresh = freshness(lot);
  const dist = distLabel(lot);
  const photo = lot.photo
    ? `<img class="popup-photo" src="/uploads/${lot.photo}" alt="料金看板" data-photo="${lot.photo}" title="タップで拡大" />
       <div class="popup-photo-cap">🔍 タップで拡大 — 実際の料金はこの看板でご確認ください</div>`
    : '';
  const noPhoto = !lot.photo ? '<span class="badge badge-photo">📷 写真なし</span>' : '';
  const sample = lot.source === 'osm' ? '<span class="badge badge-sample">🔰 サンプル・未確認</span>' : '';
  return `
    <div class="popup" data-id="${lot.id}">
      ${photo}
      <p class="popup-name">${escapeHtml(lot.name)}</p>
      <p class="popup-price">概算(${fmtHours(state.hours)}): <strong>${yen(lot.estimate)}</strong> <span class="est-note">※検索用の目安</span></p>
      <p class="popup-price" style="color:var(--muted)">${escapeHtml(ratesText(lot))}</p>
      ${lot.fee_note ? `<p class="popup-note">📝 ${escapeHtml(lot.fee_note)}</p>` : ''}
      ${lot.address ? `<p class="popup-note">📍 ${escapeHtml(lot.address)}</p>` : ''}
      ${trustHint(lot)}
      <div class="popup-meta">
        ${trustBadge(lot)}
        <span class="badge ${fresh.cls}">${fresh.label}</span>
        ${sample}
        ${noPhoto}
        ${dist ? `<span>🚶 ${dist}</span>` : ''}
        ${lot.confirm_count ? `<span>✅ ${lot.confirm_count}</span>` : ''}
        ${lot.nickname ? `<span>by ${escapeHtml(lot.nickname)}</span>` : ''}
      </div>
      <div class="popup-actions">
        <button class="act-confirm" data-act="confirm" data-id="${lot.id}">✅ 情報は正しい</button>
        <button class="act-report" data-act="report" data-id="${lot.id}">⚠️ 違う/古い</button>
        <button class="act-report" data-act="inappropriate" data-id="${lot.id}">🚩 不適切</button>
        <button class="act-edit" data-act="edit" data-id="${lot.id}">✏️ 編集</button>
      </div>
    </div>`;
}

function fmtHours(h) {
  if (h < 1) return `${h * 60}分`;
  if (h === 24) return '1日';
  return `${h}時間`;
}

function unitLabel(m) {
  const map = { 10: '10分', 15: '15分', 20: '20分', 30: '30分', 60: '60分', 720: '12時間', 1440: '24時間' };
  return map[Number(m)] || `${m}分`;
}

// 料金行を「10分¥100・24時間¥800(最大)・20:00〜08:00 ¥500(最大)」の形に整形。無ければ旧hourly/maxで代替
function ratesText(lot) {
  if (lot.rates && lot.rates.length) {
    return lot.rates.map((r) => {
      if (r.from && r.to) return `${r.from}〜${r.to} ${yen(r.yen)}(最大)`;
      return `${unitLabel(r.minutes)}${yen(r.yen)}${r.is_max ? '(最大)' : ''}`;
    }).join(' ・ ');
  }
  const parts = [];
  if (lot.hourly_rate != null) parts.push(`時間${yen(lot.hourly_rate)}`);
  if (lot.max_rate != null) parts.push(`最大${yen(lot.max_rate)}`);
  return parts.join(' / ') || '料金情報なし';
}

function renderList() {
  const ul = $('#lot-list');
  if (!ul) return; // 一覧サイドバーは廃止（地図中心UI）
  ul.innerHTML = '';
  $('#list-count').textContent = state.lots.length
    ? `${state.lots.length}件の駐車場（${sortLabel()}）`
    : 'まだ登録がありません。「＋駐車場を登録」から追加してみましょう。';

  state.lots.forEach((lot) => {
    const fresh = freshness(lot);
    const dist = distLabel(lot);
    const li = document.createElement('li');
    li.className = 'lot-card';
    li.innerHTML = `
      ${lot.photo
        ? `<img class="lot-thumb" src="/uploads/${lot.photo}" alt="" />`
        : '<div class="lot-thumb">🅿️</div>'}
      <div class="lot-body">
        <p class="lot-name">${escapeHtml(lot.name)}</p>
        <div class="lot-price">概算(${fmtHours(state.hours)}) <strong>${yen(lot.estimate)}</strong>
          <span style="color:var(--muted)"> ｜ ${escapeHtml(ratesText(lot))}</span>
        </div>
        <div class="lot-meta">
          ${trustBadge(lot)}
          <span class="badge ${fresh.cls}">${fresh.label}</span>
          ${lot.source === 'osm' ? '<span class="badge badge-sample">🔰 サンプル</span>' : ''}
          ${!lot.photo ? '<span class="badge badge-photo">📷 写真なし</span>' : ''}
          ${dist ? `<span>🚶 ${dist}</span>` : ''}
        </div>
      </div>`;
    li.addEventListener('click', () => {
      map.setView([lot.lat, lot.lng], Math.max(map.getZoom(), 16));
      const m = state.markers.get(lot.id);
      if (m) m.openPopup();
    });
    ul.appendChild(li);
  });
}

function sortLabel() {
  if (state.sort === 'distance' && !state.userPos) return '概算が安い順';
  return { distance: '近い順', estimate: '概算が安い順', hourly: '時間料金順', max: '最大料金順', updated: '新しい順' }[state.sort];
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ---- confirm / report ----
// reason: 'inappropriate' のとき不適切通報（少数で自動非表示）
async function vote(id, kind, reason) {
  if (!ensureLoggedIn()) return; // ログイン必須
  try {
    const res = await fetch(`/api/lots/${id}/${kind}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(reason ? { reason } : {}),
    });
    const json = await res.json();
    if (res.status === 401) { openAuth('login'); return; }
    if (!res.ok) return toast(json.error || 'エラーが発生しました');
    if (kind === 'report' && json.lot && json.lot.hidden) {
      toast('報告を受け付けました。この情報は非表示になりました');
      map.closePopup();
    } else {
      toast(kind === 'confirm' ? 'ありがとうございます！確認を記録しました' : '報告を受け付けました');
    }
    if (json.me) applyMe(json.me);
    await loadLots();
  } catch (e) {
    toast('通信に失敗しました');
  }
}

// ---- 貢献ランク（reputation） ----
async function fetchMe() {
  try {
    const res = await fetch('/api/auth/me');
    if (!res.ok) return;
    const d = await res.json();
    state.loggedIn = !!d.loggedIn;
    state.username = d.username || null;
    renderAuthUI();
    if (d.loggedIn && d.reputation) applyMe({ nickname: d.username, ...d.reputation }, { silent: true });
  } catch (e) { /* noop */ }
}

// me を反映。ランクが上がっていたら祝いのトーストを出す。
function applyMe(me, opts = {}) {
  const prev = state.me;
  state.me = me;
  renderRankChip();
  if (!opts.silent && prev && me.rank && prev.rank && me.rank.key !== prev.rank.key) {
    const order = ['bronze', 'silver', 'gold', 'platinum'];
    if (order.indexOf(me.rank.key) > order.indexOf(prev.rank.key)) {
      toast(`ランクアップ！${me.rank.label} になりました🎉`);
    }
  }
}

function renderRankChip() {
  const chip = $('#rank-chip');
  if (!chip || !state.me) return;
  chip.innerHTML = `${state.me.rank.label}<span class="rank-pts">${state.me.points}pt</span>`;
}

// ---- ログイン状態のUI ----
function renderAuthUI() {
  const inn = state.loggedIn;
  $('#btn-login').classList.toggle('hidden', inn);
  $('#btn-logout').classList.toggle('hidden', !inn);
  $('#rank-chip').classList.toggle('hidden', !inn);
  if (inn) $('#btn-logout').textContent = `ログアウト（${state.username}）`;
}

// 未ログインならログイン画面を出して false を返す
function ensureLoggedIn() {
  if (state.loggedIn) return true;
  openAuth('login');
  return false;
}

let authMode = 'login';
function openAuth(mode) {
  authMode = mode || 'login';
  const isReg = authMode === 'register';
  $('#auth-title').textContent = isReg ? '新規登録' : 'ログイン';
  $('#auth-submit').textContent = isReg ? '登録してはじめる' : 'ログイン';
  $('#pw-hint').textContent = isReg ? '（6文字以上）' : '';
  $('#auth-switch').innerHTML = isReg
    ? 'アカウントをお持ちの方は <a href="#" id="to-login">ログイン</a>'
    : 'はじめての方は <a href="#" id="to-register">新規登録</a>';
  $('#auth-error').classList.add('hidden');
  $('#auth-form').reset();
  $('#auth').classList.remove('hidden');
  const sw = authMode === 'register' ? '#to-login' : '#to-register';
  const el = $(sw);
  if (el) el.addEventListener('click', (e) => { e.preventDefault(); openAuth(isReg ? 'login' : 'register'); });
}
function closeAuth() { $('#auth').classList.add('hidden'); }

function openProfile() {
  const me = state.me;
  if (!me) return;
  $('#profile-rank-label').textContent = me.rank.label;
  $('#profile-points').textContent = `${me.points} pt`;

  // 進捗バー（現ランク下限→次ランク下限）
  const bar = $('#progress-bar');
  const nextEl = $('#profile-next');
  if (me.nextRank) {
    // 次ランクのしきい値に対する到達率
    const pct = Math.max(0, Math.min(100, (me.points / me.nextRank.min) * 100));
    bar.style.width = pct + '%';
    nextEl.textContent = `次のランク「${me.nextRank.label}」まであと ${me.nextRank.remaining}pt`;
  } else {
    bar.style.width = '100%';
    nextEl.textContent = '最高ランクに到達しています！🎉';
  }

  const s = me.stats;
  $('#profile-stats').innerHTML = `
    ${statBox(s.posts, '登録した駐車場')}
    ${statBox(s.photoPosts, '写真つき投稿')}
    ${statBox(s.votes, '確認・報告')}
    ${statBox(s.confirmsReceived, '自分の情報が確認された')}
    ${statBox(s.refreshes ?? 0, '古い情報を再確認')}`;

  $('#badge-grid').innerHTML = me.badges.map((b) =>
    `<div class="badge-item ${b.earned ? 'earned' : 'locked'}">${b.earned ? '' : '🔒 '}${escapeHtml(b.label)}</div>`
  ).join('');

  $('#profile').classList.remove('hidden');
}
function statBox(num, label) {
  return `<div class="stat-box"><div class="stat-num">${num}</div><div class="stat-label">${label}</div></div>`;
}

// ---- 現在地 ----
// options.initial=true のときは「近い順」を既定にして周辺を初期表示する
function locate(options = {}) {
  return new Promise((resolve) => {
    if (!navigator.geolocation) {
      if (!options.initial) toast('この端末では現在地を取得できません');
      return resolve(false);
    }
    if (!options.initial) toast('現在地を取得中…');
    let done = false;
    let best = null;       // 最も精度の良い測位結果を採用
    let recentered = false; // この呼び出しで一度だけ地図を移動＆ソート切替
    let watchId = null;
    const apply = () => {
      applyUserPos(best, options, !recentered);
      recentered = true;
    };
    const finish = () => {
      if (done) return;
      done = true;
      if (watchId != null) navigator.geolocation.clearWatch(watchId);
      if (best) { apply(); resolve(true); }
      else {
        if (!options.initial) toast('現在地を取得できませんでした（位置情報の許可を確認してください）');
        resolve(false);
      }
    };
    // watchPosition で数回受信し、accuracy が最も良いものを使う（初回のブレを軽減）
    watchId = navigator.geolocation.watchPosition(
      (pos) => {
        if (!best || pos.coords.accuracy < best.coords.accuracy) best = pos;
        apply();                                  // 逐次反映（だんだん正確になる）
        if (best.coords.accuracy <= 30) finish(); // 十分な精度で確定
      },
      () => { if (!best) finish(); },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
    setTimeout(finish, 8000); // 最長8秒でその時点のベストに確定
  });
}

function applyUserPos(pos, options, recenter) {
  const { latitude: lat, longitude: lng, accuracy } = pos.coords;
  state.userPos = { lat, lng };
  if (recenter) map.setView([lat, lng], 16);
  if (userMarker) map.removeLayer(userMarker);
  if (accuracyCircle) map.removeLayer(accuracyCircle);
  // 精度の円（この範囲のどこかにいる、の目安）
  accuracyCircle = L.circle([lat, lng], {
    radius: Math.min(accuracy || 50, 500), color: '#1573ff', weight: 1,
    fillColor: '#1573ff', fillOpacity: 0.12,
  }).addTo(map);
  userMarker = L.circleMarker([lat, lng], {
    radius: 8, color: '#fff', weight: 2, fillColor: '#1573ff', fillOpacity: 1,
  }).addTo(map).bindPopup(`現在地（誤差 約${Math.round(accuracy || 0)}m）`);
  if (options.initial && recenter) {
    state.sort = 'distance';
    setActiveSortButton('distance');
  }
  loadLots();
}

function setActiveSortButton(sort) {
  const seg = $('#sort-seg');
  if (!seg) return; // 並び替えUIは廃止
  seg.querySelectorAll('button').forEach((x) =>
    x.classList.toggle('active', x.dataset.sort === sort));
}

// ---- 登録モード ----
function startAddMode() {
  state.addMode = true;
  state.editingId = null;
  $('#add-hint').classList.remove('hidden');
  map.getContainer().style.cursor = 'crosshair';
  // 現在地があれば、そこを初期候補にできるようヒントを出すだけ（タップで確定）
}
function stopAddMode() {
  state.addMode = false;
  $('#add-hint').classList.add('hidden');
  map.getContainer().style.cursor = '';
}

map.on('click', (e) => {
  if (!state.addMode) return;
  state.pending = { lat: e.latlng.lat, lng: e.latlng.lng };
  stopAddMode();
  openForm(null, state.pending);
});

// ---- 可変料金行（rates） ----
const RATE_UNITS = [
  { v: 10, label: '10分' }, { v: 15, label: '15分' }, { v: 20, label: '20分' },
  { v: 30, label: '30分' }, { v: 60, label: '60分' }, { v: 720, label: '12時間' },
  { v: 1440, label: '24時間' },
];

// 従量/最大の行
function addRateRow(minutes = 60, yen = '', isMax = false) {
  const row = document.createElement('div');
  row.className = 'rate-row';
  const opts = RATE_UNITS.map((u) => `<option value="${u.v}" ${u.v === minutes ? 'selected' : ''}>${u.label}</option>`).join('');
  row.innerHTML = `
    <select class="rate-unit">${opts}</select>
    <input class="rate-yen" type="number" min="0" inputmode="numeric" placeholder="金額" value="${yen}" />
    <span class="yen-suffix">円</span>
    <label class="rate-max"><input type="checkbox" class="rate-ismax" ${isMax ? 'checked' : ''} />最大</label>
    <button type="button" class="rate-del" title="削除">×</button>`;
  row.querySelector('.rate-del').addEventListener('click', () => row.remove());
  $('#rate-rows').appendChild(row);
}

// 時間帯の最大料金（夜間など）の行
function addRateWindowRow(from = '20:00', to = '08:00', yen = '') {
  const row = document.createElement('div');
  row.className = 'rate-row rate-row--window';
  row.innerHTML = `
    <input class="rate-from" type="time" value="${from}" />
    <span class="yen-suffix">〜</span>
    <input class="rate-to" type="time" value="${to}" />
    <input class="rate-yen" type="number" min="0" inputmode="numeric" placeholder="金額" value="${yen}" />
    <span class="yen-suffix">円 最大</span>
    <button type="button" class="rate-del" title="削除">×</button>`;
  row.querySelector('.rate-del').addEventListener('click', () => row.remove());
  $('#rate-rows').appendChild(row);
}

function resetRateRows(rates) {
  const box = $('#rate-rows');
  box.innerHTML = '';
  if (rates && rates.length) {
    rates.forEach((r) => {
      if (r.from && r.to) addRateWindowRow(r.from, r.to, r.yen ?? '');
      else addRateRow(Number(r.minutes) || 60, r.yen ?? '', !!r.is_max);
    });
  } else {
    // 既定で「60分」「24時間(最大)」の空行を用意
    addRateRow(60, '', false);
    addRateRow(1440, '', true);
  }
}

function gatherRates() {
  const rates = [];
  $('#rate-rows').querySelectorAll('.rate-row').forEach((row) => {
    const yenRaw = row.querySelector('.rate-yen').value.trim();
    if (yenRaw === '') return; // 金額未入力の行は無視
    const yen = Number(yenRaw);
    if (!Number.isFinite(yen) || yen < 0) return;
    if (row.classList.contains('rate-row--window')) {
      const from = row.querySelector('.rate-from').value;
      const to = row.querySelector('.rate-to').value;
      if (!from || !to) return;
      rates.push({ from, to, yen: Math.round(yen), is_max: true });
    } else {
      const minutes = Number(row.querySelector('.rate-unit').value);
      if (!minutes) return;
      rates.push({ minutes, yen: Math.round(yen), is_max: row.querySelector('.rate-ismax').checked });
    }
  });
  return rates;
}

// ---- フォーム ----
function openForm(lot, pos, prefillName) {
  const form = $('#lot-form');
  form.reset();
  $('#form-error').classList.add('hidden');
  $('#photo-preview').classList.add('hidden');
  $('#photo-preview').querySelector('img').src = '';

  if (lot) {
    state.editingId = lot.id;
    $('#modal-title').textContent = '駐車場を編集';
    $('#btn-submit').textContent = '更新する';
    form.id.value = lot.id;
    form.lat.value = lot.lat;
    form.lng.value = lot.lng;
    form.name.value = lot.name || '';
    form.fee_note.value = lot.fee_note || '';
    form.capacity.value = lot.capacity ?? '';
    form.address.value = lot.address || '';
    resetRateRows(lot.rates);
    $('#pos-label').textContent = `${lot.lat.toFixed(5)}, ${lot.lng.toFixed(5)}`;
  } else {
    state.editingId = null;
    $('#modal-title').textContent = '駐車場を登録';
    $('#btn-submit').textContent = '登録する';
    form.lat.value = pos.lat;
    form.lng.value = pos.lng;
    if (prefillName) form.name.value = prefillName; // OSMのP等から名称を引き継ぐ
    resetRateRows(null);
    $('#pos-label').textContent = `${pos.lat.toFixed(5)}, ${pos.lng.toFixed(5)}`;
    // 位置から住所・名称を自動取得して入力補助（無料のOSM。失敗時は無言でスキップ）
    autofillFromLocation(pos.lat, pos.lng);
  }
  $('#modal').classList.remove('hidden');
}

// OpenStreetMap(Nominatim) の逆ジオコーディングで住所・名称をフォームに自動入力。
// 無料・APIキー不要。取得結果はあくまで候補で、本人が確認・修正して登録する。
async function autofillFromLocation(lat, lng) {
  const form = $('#lot-form');
  const addrInput = form.address;
  const nameInput = form.name;
  const prevPlaceholder = addrInput.placeholder;
  addrInput.placeholder = '住所を取得中…';
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 6000); // ネットワーク停滞時のフォールバック
  try {
    const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`
      + `&zoom=18&addressdetails=1&namedetails=1&accept-language=ja`;
    const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl.signal });
    if (!res.ok) throw new Error('geocode failed');
    const d = await res.json();
    // 住所（自動取得。ユーザーが未入力のときだけ埋める）
    const addr = buildJpAddress(d);
    if (addr && !addrInput.value.trim()) addrInput.value = addr;
    // 駐車場名（近くの施設名/POI名が取れて、未入力のときだけ候補として入れる）
    const nm = (d.namedetails && (d.namedetails['name:ja'] || d.namedetails.name)) || d.name || '';
    if (nm && !nameInput.value.trim()) nameInput.value = nm.slice(0, 120);
  } catch (e) {
    /* 取得できなくても手入力でOK。無言でスキップ */
  } finally {
    clearTimeout(timer);
    addrInput.placeholder = prevPlaceholder;
  }
}

// Nominatim の address 構造から日本語の住所文字列を組み立てる
function buildJpAddress(d) {
  const a = d.address || {};
  // 日本の住所は「都道府県→市区町村→町名→番地」の順で並べる
  const parts = [
    a.province || a.state,
    a.city || a.town || a.village || a.county,
    a.suburb || a.city_district || a.neighbourhood,
    a.quarter,
    a.road,
    a.house_number,
  ].filter(Boolean);
  const joined = parts.join('');
  if (joined) return joined;
  // 構造化が取れない場合は display_name 先頭部分を利用
  return d.display_name ? d.display_name.split(',').slice(0, 3).reverse().join('') : '';
}
function closeForm() {
  $('#modal').classList.add('hidden');
  state.editingId = null;
}

// 写真プレビュー
$('#photo-input').addEventListener('change', async (e) => {
  const file = e.target.files[0];
  if (!file) return;
  const url = URL.createObjectURL(file);
  const box = $('#photo-preview');
  box.querySelector('img').src = url;
  box.classList.remove('hidden');
});

// クライアント側で画像を長辺1280pxにリサイズ＆JPEG圧縮（帯域・容量削減）
async function resizeImage(file) {
  if (!file) return null;
  try {
    const img = await loadImage(file);
    const maxSide = 1280;
    let { width, height } = img;
    if (Math.max(width, height) > maxSide) {
      const scale = maxSide / Math.max(width, height);
      width = Math.round(width * scale);
      height = Math.round(height * scale);
    }
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(img, 0, 0, width, height);
    const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.82));
    return blob ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file;
  } catch {
    return file; // 失敗時は元ファイルをそのまま
  }
}
function loadImage(file) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = URL.createObjectURL(file);
  });
}

$('#lot-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const errEl = $('#form-error');
  errEl.classList.add('hidden');

  if (!form.name.value.trim()) {
    errEl.textContent = '駐車場名を入力してください';
    return errEl.classList.remove('hidden');
  }

  // 写真は必須（新規は写真ファイル、編集は既存写真があればOK）
  const editingLot = state.editingId ? state.lots.find((l) => l.id === state.editingId) : null;
  const hasExistingPhoto = editingLot && editingLot.photo;
  if (!form.photo.files[0] && !hasExistingPhoto) {
    errEl.textContent = '料金看板の写真を添付してください（写真は必須です）';
    return errEl.classList.remove('hidden');
  }

  const submitBtn = $('#btn-submit');
  submitBtn.disabled = true;
  submitBtn.textContent = '送信中…';

  const fd = new FormData();
  ['name', 'lat', 'lng', 'address', 'fee_note', 'capacity'].forEach((k) => {
    fd.append(k, form[k].value.trim());
  });
  fd.append('website', form.website ? form.website.value : ''); // ハニーポット
  fd.append('rates', JSON.stringify(gatherRates()));
  const file = form.photo.files[0];
  if (file) {
    const resized = await resizeImage(file);
    fd.append('photo', resized, 'photo.jpg');
  }

  try {
    const editing = state.editingId;
    // 編集も POST（PHP は PUT+multipart で $_FILES が空になるため）
    const url = editing ? `/api/lots/${editing}` : '/api/lots';
    const res = await fetch(url, { method: 'POST', body: fd });
    const json = await res.json();
    if (res.status === 401) { closeForm(); openAuth('login'); return; }
    if (!res.ok) {
      errEl.textContent = json.error || '保存に失敗しました';
      errEl.classList.remove('hidden');
      return;
    }
    closeForm();
    toast(editing ? '更新しました！' : '登録しました！ありがとうございます');
    if (json.me) applyMe(json.me);
    await loadLots();
    const saved = json.lot;
    map.setView([saved.lat, saved.lng], Math.max(map.getZoom(), 16));
    const m = state.markers.get(saved.id);
    if (m) m.openPopup();
  } catch (err) {
    errEl.textContent = '通信に失敗しました';
    errEl.classList.remove('hidden');
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = state.editingId ? '更新する' : '登録する';
  }
});

// ---- イベント委任: ポップアップ内ボタン & 写真拡大 ----
document.addEventListener('click', (e) => {
  const actBtn = e.target.closest('[data-act]');
  if (actBtn) {
    const id = Number(actBtn.dataset.id);
    const act = actBtn.dataset.act;
    if (act === 'confirm') vote(id, 'confirm');
    else if (act === 'report') vote(id, 'report');
    else if (act === 'inappropriate') {
      if (confirm('この写真・投稿を「不適切」として通報します。よろしいですか？')) vote(id, 'report', 'inappropriate');
    } else if (act === 'edit') {
      if (!ensureLoggedIn()) return;
      const lot = state.lots.find((l) => l.id === id);
      if (lot) { map.closePopup(); openForm(lot); }
    }
    return;
  }
  // OSMの「P」ピンから料金・写真を登録
  const osmBtn = e.target.closest('.act-osmreg');
  if (osmBtn) {
    const lat = Number(osmBtn.dataset.lat);
    const lng = Number(osmBtn.dataset.lng);
    map.closePopup();
    stopAddMode();
    openForm(null, { lat, lng }, osmBtn.dataset.name || '');
    return;
  }
  const photo = e.target.closest('.popup-photo');
  if (photo) {
    const lb = $('#lightbox');
    lb.querySelector('img').src = photo.src;
    lb.classList.remove('hidden');
  }
});
$('#lightbox').addEventListener('click', () => $('#lightbox').classList.add('hidden'));

// ---- コントロール ----
$('#btn-add-rate').addEventListener('click', () => addRateRow(60, '', false));
$('#btn-add-rate-window').addEventListener('click', () => addRateWindowRow('20:00', '08:00', ''));
$('#rank-chip').addEventListener('click', openProfile);
$('#profile-close').addEventListener('click', () => $('#profile').classList.add('hidden'));
$('#profile').addEventListener('click', (e) => { if (e.target.id === 'profile') $('#profile').classList.add('hidden'); });
$('#btn-locate').addEventListener('click', () => locate());
$('#btn-refresh').addEventListener('click', forceRefresh);
$('#btn-add').addEventListener('click', () => { if (ensureLoggedIn()) startAddMode(); });

// ---- ログイン/登録 ----
$('#btn-login').addEventListener('click', () => openAuth('login'));
$('#auth-close').addEventListener('click', closeAuth);
$('#auth').addEventListener('click', (e) => { if (e.target.id === 'auth') closeAuth(); });
$('#btn-logout').addEventListener('click', async () => {
  await fetch('/api/auth/logout', { method: 'POST' });
  state.loggedIn = false; state.username = null; state.me = null;
  renderAuthUI();
  toast('ログアウトしました');
});
$('#auth-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const err = $('#auth-error');
  err.classList.add('hidden');
  const payload = { username: form.username.value.trim(), password: form.password.value, website: form.website.value };
  const path = authMode === 'register' ? '/api/auth/register' : '/api/auth/login';
  try {
    const res = await fetch(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const json = await res.json();
    if (!res.ok) { err.textContent = json.error || '失敗しました'; err.classList.remove('hidden'); return; }
    state.loggedIn = true;
    state.username = json.username;
    renderAuthUI();
    if (json.reputation) applyMe({ nickname: json.username, ...json.reputation }, { silent: true });
    closeAuth();
    toast(authMode === 'register' ? 'ようこそ！登録が完了しました' : 'ログインしました');
  } catch (e2) {
    err.textContent = '通信に失敗しました'; err.classList.remove('hidden');
  }
});

// この地図を更新: 表示範囲のDB＋OSMのPを取り直す
function forceRefresh() {
  osmCoverage = null; // OSMキャッシュを無効化して確実に再取得
  toast('この地図を更新しています…');
  loadLots();
}
$('#btn-cancel-add').addEventListener('click', stopAddMode);
$('#modal-close').addEventListener('click', closeForm);
$('#btn-form-cancel').addEventListener('click', closeForm);
$('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeForm(); });

// 地図を動かしたら、その範囲の駐車場を再取得（周辺表示の追従）
map.on('moveend', scheduleReload);

// ---- 起動 ----
fetchMe(); // 自分の貢献ランクを取得してチップに反映
// まず現在地の取得を試み、その付近の駐車場を「近い順」で初期表示する。
// 位置が取れなければ既定表示（東京駅周辺）のまま概算順で表示。
(async () => {
  const ok = await locate({ initial: true });
  if (!ok) loadLots();
})();

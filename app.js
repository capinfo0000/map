'use strict';

/* ============================================================
 * みんなの駐車場マップ — フロントエンド
 * ============================================================ */

// ---- 匿名トークン（1人1票の識別に使用。localStorage 保存） ----
function getClientToken() {
  let t = localStorage.getItem('pm_token');
  if (!t) {
    t = 'c-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem('pm_token', t);
  }
  return t;
}
const CLIENT_TOKEN = getClientToken();

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
};

// ---- 地図初期化 ----
const map = L.map('map', { zoomControl: true }).setView([35.681236, 139.767125], 15); // 東京駅付近を初期表示
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  maxZoom: 19,
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
}).addTo(map);

let userMarker = null;

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

// ピン色: 信頼度（要確認=赤/要更新=灰/認定=金）を優先し、それ以外は料金帯で色分け
function pinClass(lot) {
  const level = lot.trust && lot.trust.level;
  if (level === 'flagged') return 'pin-red';
  if (level === 'stale') return 'pin-gray';
  if (level === 'certified') return 'pin-gold';
  return priceColor(lot);
}

// 料金帯からピン色（安い=緑/中=橙/高=赤/不明=灰）
function priceColor(lot) {
  const v = lot.estimate;
  if (v == null) return 'pin-gray';
  if (v <= 300) return 'pin-green';
  if (v <= 800) return 'pin-amber';
  return 'pin-red';
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
}

// 地図移動で周辺を再取得（デバウンス）
let _moveTimer = null;
function scheduleReload() {
  clearTimeout(_moveTimer);
  _moveTimer = setTimeout(loadLots, 400);
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
    ? `<img class="popup-photo" src="/uploads/${lot.photo}" alt="料金看板" data-photo="${lot.photo}" />`
    : '';
  const noPhoto = !lot.photo ? '<span class="badge badge-photo">📷 写真なし</span>' : '';
  const sample = lot.source === 'osm' ? '<span class="badge badge-sample">🔰 サンプル・未確認</span>' : '';
  return `
    <div class="popup" data-id="${lot.id}">
      ${photo}
      <p class="popup-name">${escapeHtml(lot.name)}</p>
      <p class="popup-price">概算(${fmtHours(state.hours)}): <strong>${yen(lot.estimate)}</strong></p>
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
async function vote(id, kind) {
  try {
    const res = await fetch(`/api/lots/${id}/${kind}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ client_token: CLIENT_TOKEN }),
    });
    const json = await res.json();
    if (!res.ok) return toast(json.error || 'エラーが発生しました');
    if (kind === 'report' && json.lot && json.lot.hidden) {
      toast('報告が多いため、この情報は自動的に非表示になりました');
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
    const res = await fetch('/api/users/me?token=' + encodeURIComponent(CLIENT_TOKEN));
    if (!res.ok) return;
    applyMe(await res.json(), { silent: true });
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
  // 登録フォームのニックネームを保存済みの表示名で補完
  if (me.nickname && $('#lot-form') && !$('#lot-form').nickname.value) {
    $('#lot-form').nickname.value = me.nickname;
  }
}

function renderRankChip() {
  const chip = $('#rank-chip');
  if (!chip || !state.me) return;
  chip.innerHTML = `${state.me.rank.label}<span class="rank-pts">${state.me.points}pt</span>`;
}

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
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const { latitude: lat, longitude: lng } = pos.coords;
        state.userPos = { lat, lng };
        map.setView([lat, lng], 16);
        if (userMarker) map.removeLayer(userMarker);
        userMarker = L.circleMarker([lat, lng], {
          radius: 8, color: '#fff', weight: 2, fillColor: '#1573ff', fillOpacity: 1,
        }).addTo(map).bindPopup('現在地');
        if (options.initial) {
          // 初回は「近い順」に切り替えて周辺を初期表示
          state.sort = 'distance';
          setActiveSortButton('distance');
        }
        loadLots(); // moveend でも走るが、距離ソート反映のため明示的に
        resolve(true);
      },
      () => {
        if (!options.initial) toast('現在地を取得できませんでした（位置情報の許可を確認してください）');
        resolve(false);
      },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  });
}

function setActiveSortButton(sort) {
  $('#sort-seg').querySelectorAll('button').forEach((x) =>
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
function openForm(lot, pos) {
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
    form.nickname.value = lot.nickname || '';
    form.address.value = lot.address || '';
    resetRateRows(lot.rates);
    $('#pos-label').textContent = `${lot.lat.toFixed(5)}, ${lot.lng.toFixed(5)}`;
  } else {
    state.editingId = null;
    $('#modal-title').textContent = '駐車場を登録';
    $('#btn-submit').textContent = '登録する';
    form.lat.value = pos.lat;
    form.lng.value = pos.lng;
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
  ['name', 'lat', 'lng', 'address', 'fee_note', 'capacity', 'nickname'].forEach((k) => {
    fd.append(k, form[k].value.trim());
  });
  fd.append('rates', JSON.stringify(gatherRates()));
  fd.append('client_token', CLIENT_TOKEN);
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
    else if (act === 'edit') {
      const lot = state.lots.find((l) => l.id === id);
      if (lot) { map.closePopup(); openForm(lot); }
    }
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
$('#sort-seg').addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  state.sort = b.dataset.sort;
  $('#sort-seg').querySelectorAll('button').forEach((x) => x.classList.toggle('active', x === b));
  loadLots();
});
$('#hours-seg').addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  state.hours = Number(b.dataset.hours);
  $('#hours-seg').querySelectorAll('button').forEach((x) => x.classList.toggle('active', x === b));
  loadLots();
});

$('#btn-add-rate').addEventListener('click', () => addRateRow(60, '', false));
$('#btn-add-rate-window').addEventListener('click', () => addRateWindowRow('20:00', '08:00', ''));
$('#rank-chip').addEventListener('click', openProfile);
$('#profile-close').addEventListener('click', () => $('#profile').classList.add('hidden'));
$('#profile').addEventListener('click', (e) => { if (e.target.id === 'profile') $('#profile').classList.add('hidden'); });
$('#btn-locate').addEventListener('click', () => locate());
$('#btn-add').addEventListener('click', startAddMode);
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

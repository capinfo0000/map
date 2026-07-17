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

// 情報の鮮度・信頼性からバッジ種別を決める
function freshness(lot) {
  if (lot.report_count >= 3) return { cls: 'badge-warn', label: `⚠️ 要確認(報告${lot.report_count})`, pin: 'pin-red' };
  const ref = lot.last_confirmed_at || lot.updated_at;
  const d = daysSince(ref);
  if (d == null) return { cls: 'badge-old', label: '更新日不明', pin: 'pin-gray' };
  if (d <= 30) return { cls: 'badge-fresh', label: `${relTime(ref)}に更新`, pin: null };
  if (d <= 90) return { cls: 'badge-mid', label: `${relTime(ref)}に更新`, pin: null };
  return { cls: 'badge-old', label: `⚠️ ${relTime(ref)}（古い可能性）`, pin: null };
}

// 料金帯からピン色（安い=緑/中=橙/高=赤/不明=灰）。要確認は freshness が優先。
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
  const fresh = freshness(lot);
  const cls = fresh.pin || priceColor(lot);
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
  const params = new URLSearchParams({ sort: state.sort, hours: String(state.hours) });
  const res = await fetch('/api/lots?' + params.toString());
  const json = await res.json();
  state.lots = json.lots;
  renderMarkers();
  renderList();
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
  return `
    <div class="popup" data-id="${lot.id}">
      ${photo}
      <p class="popup-name">${escapeHtml(lot.name)}</p>
      <p class="popup-price">概算(${fmtHours(state.hours)}): <strong>${yen(lot.estimate)}</strong></p>
      <p class="popup-price">時間 ${yen(lot.hourly_rate)} / 最大 ${yen(lot.max_rate)}</p>
      ${lot.fee_note ? `<p class="popup-note">📝 ${escapeHtml(lot.fee_note)}</p>` : ''}
      ${lot.address ? `<p class="popup-note">📍 ${escapeHtml(lot.address)}</p>` : ''}
      <div class="popup-meta">
        <span class="badge ${fresh.cls}">${fresh.label}</span>
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
          <span style="color:var(--muted)"> ｜ 時間${yen(lot.hourly_rate)} / 最大${yen(lot.max_rate)}</span>
        </div>
        <div class="lot-meta">
          <span class="badge ${fresh.cls}">${fresh.label}</span>
          ${!lot.photo ? '<span class="badge badge-photo">📷 写真なし</span>' : ''}
          ${dist ? `<span>🚶 ${dist}</span>` : ''}
          ${lot.confirm_count ? `<span>✅ ${lot.confirm_count}</span>` : ''}
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
  return { estimate: '概算が安い順', hourly: '時間料金順', max: '最大料金順', updated: '新しい順' }[state.sort];
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
    toast(kind === 'confirm' ? 'ありがとうございます！確認を記録しました' : '報告を受け付けました');
    await loadLots();
  } catch (e) {
    toast('通信に失敗しました');
  }
}

// ---- 現在地 ----
function locate() {
  if (!navigator.geolocation) return toast('この端末では現在地を取得できません');
  toast('現在地を取得中…');
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const { latitude: lat, longitude: lng } = pos.coords;
      state.userPos = { lat, lng };
      map.setView([lat, lng], 16);
      if (userMarker) map.removeLayer(userMarker);
      userMarker = L.circleMarker([lat, lng], {
        radius: 8, color: '#fff', weight: 2, fillColor: '#1573ff', fillOpacity: 1,
      }).addTo(map).bindPopup('現在地');
      renderList(); // 距離を反映
    },
    () => toast('現在地を取得できませんでした（位置情報の許可を確認してください）'),
    { enableHighAccuracy: true, timeout: 8000 }
  );
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
    form.hourly_rate.value = lot.hourly_rate ?? '';
    form.max_rate.value = lot.max_rate ?? '';
    form.fee_note.value = lot.fee_note || '';
    form.capacity.value = lot.capacity ?? '';
    form.nickname.value = lot.nickname || '';
    form.address.value = lot.address || '';
    $('#pos-label').textContent = `${lot.lat.toFixed(5)}, ${lot.lng.toFixed(5)}`;
  } else {
    state.editingId = null;
    $('#modal-title').textContent = '駐車場を登録';
    $('#btn-submit').textContent = '登録する';
    form.lat.value = pos.lat;
    form.lng.value = pos.lng;
    $('#pos-label').textContent = `${pos.lat.toFixed(5)}, ${pos.lng.toFixed(5)}`;
  }
  $('#modal').classList.remove('hidden');
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

  const submitBtn = $('#btn-submit');
  submitBtn.disabled = true;
  submitBtn.textContent = '送信中…';

  const fd = new FormData();
  ['name', 'lat', 'lng', 'address', 'hourly_rate', 'max_rate', 'fee_note', 'capacity', 'nickname'].forEach((k) => {
    fd.append(k, form[k].value.trim());
  });
  const file = form.photo.files[0];
  if (file) {
    const resized = await resizeImage(file);
    fd.append('photo', resized, 'photo.jpg');
  }

  try {
    const editing = state.editingId;
    const url = editing ? `/api/lots/${editing}` : '/api/lots';
    const res = await fetch(url, { method: editing ? 'PUT' : 'POST', body: fd });
    const json = await res.json();
    if (!res.ok) {
      errEl.textContent = json.error || '保存に失敗しました';
      errEl.classList.remove('hidden');
      return;
    }
    closeForm();
    toast(editing ? '更新しました！' : '登録しました！ありがとうございます');
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

$('#btn-locate').addEventListener('click', locate);
$('#btn-add').addEventListener('click', startAddMode);
$('#btn-cancel-add').addEventListener('click', stopAddMode);
$('#modal-close').addEventListener('click', closeForm);
$('#btn-form-cancel').addEventListener('click', closeForm);
$('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeForm(); });

// ---- 起動 ----
loadLots();
// 初回に現在地を試みる（許可されなければ東京駅のまま）
if (navigator.geolocation) locate();

'use strict';

const $ = (s) => document.querySelector(s);

function toast(msg) {
  const el = $('#toast');
  el.textContent = msg;
  el.classList.remove('hidden');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => el.classList.add('hidden'), 2600);
}
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
const yen = (n) => (n == null ? '—' : '¥' + Number(n).toLocaleString('ja-JP'));

async function api(path, opts) {
  const res = await fetch('/api' + path, opts);
  let json = {};
  try { json = await res.json(); } catch (e) {}
  return { ok: res.ok, status: res.status, json };
}

function show(box) {
  $('#login-box').classList.toggle('hidden', box !== 'login');
  $('#list-box').classList.toggle('hidden', box !== 'list');
}

async function init() {
  const { json } = await api('/admin/session');
  if (json.admin) { show('list'); loadList(); }
  else show('login');
}

$('#login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  $('#login-error').classList.add('hidden');
  const { ok, json } = await api('/admin/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ password: $('#pw').value }),
  });
  if (!ok) {
    $('#login-error').textContent = json.error || 'ログインに失敗しました';
    $('#login-error').classList.remove('hidden');
    return;
  }
  show('list');
  loadList();
});

$('#btn-logout').addEventListener('click', async () => {
  await api('/admin/logout', { method: 'POST' });
  $('#pw').value = '';
  show('login');
});
$('#btn-reload').addEventListener('click', loadList);

async function loadList() {
  const { ok, json } = await api('/admin/lots');
  if (!ok) { toast('取得に失敗しました'); return; }
  const lots = json.lots || [];
  $('#admin-count').textContent = `${lots.length} 件（新しい順）`;
  const list = $('#admin-list');
  list.innerHTML = '';
  lots.forEach((lot) => {
    const row = document.createElement('div');
    row.className = 'admin-row' + (lot.hidden ? ' is-hidden' : '');
    const thumb = lot.photo
      ? `<img class="admin-thumb" src="/uploads/${esc(lot.photo)}" alt="" />`
      : '<div class="admin-thumb">🅿️</div>';
    row.innerHTML = `
      ${thumb}
      <div class="admin-info">
        <p class="admin-name">${esc(lot.name)}</p>
        <div class="admin-meta">
          <span>${lot.source === 'osm' ? '🔰サンプル' : '👤ユーザー'}</span>
          <span>時間${yen(lot.hourly_rate)}/最大${yen(lot.max_rate)}</span>
          <span>✅${lot.confirm_count || 0}</span>
          <span>⚠️${lot.report_count || 0}</span>
          ${lot.hidden ? '<span style="color:var(--red)">非表示中</span>' : ''}
          ${lot.nickname ? `<span>by ${esc(lot.nickname)}</span>` : ''}
        </div>
      </div>
      <div class="admin-actions">
        ${lot.hidden
          ? `<button class="act-unhide" data-act="unhide" data-id="${lot.id}">復活</button>`
          : `<button class="act-hide" data-act="hide" data-id="${lot.id}">非表示</button>`}
        <button class="act-del" data-act="delete" data-id="${lot.id}">削除</button>
      </div>`;
    list.appendChild(row);
  });
}

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-act]');
  if (!btn) return;
  const id = Number(btn.dataset.id);
  const act = btn.dataset.act;
  if (act === 'delete') {
    if (!confirm('この駐車場を完全に削除します。よろしいですか？')) return;
    const { ok } = await api(`/lots/${id}/delete`, { method: 'POST' });
    toast(ok ? '削除しました' : '削除に失敗しました');
    loadList();
  } else if (act === 'hide' || act === 'unhide') {
    const { ok } = await api(`/lots/${id}/${act}`, { method: 'POST' });
    toast(ok ? (act === 'hide' ? '非表示にしました' : '復活しました') : '失敗しました');
    loadList();
  }
});

init();

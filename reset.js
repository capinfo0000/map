// パスワード再設定ページ。メールのリンク（?token=...）から開く。
(function () {
  'use strict';
  var params = new URLSearchParams(location.search);
  var token = params.get('token') || '';

  var form = document.getElementById('reset-form');
  var pw = document.getElementById('reset-pw');
  var pw2 = document.getElementById('reset-pw2');
  var errEl = document.getElementById('reset-error');
  var msgEl = document.getElementById('reset-msg');
  var submit = document.getElementById('reset-submit');

  function showError(text) {
    errEl.textContent = text;
    errEl.classList.remove('hidden');
    msgEl.classList.add('hidden');
  }
  function showMsg(html) {
    msgEl.innerHTML = html;
    msgEl.classList.remove('hidden');
    errEl.classList.add('hidden');
  }

  if (!token) {
    showError('リンクが正しくありません。パスワード再発行をやり直してください。');
    submit.disabled = true;
    return;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    errEl.classList.add('hidden');
    var p = pw.value || '';
    var p2 = pw2.value || '';
    if (p.length < 6) { showError('パスワードは6文字以上にしてください'); return; }
    if (p !== p2) { showError('確認用のパスワードが一致しません'); return; }

    submit.disabled = true;
    fetch('/api/auth/reset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: token, password: p })
    }).then(function (res) {
      return res.json().then(function (data) { return { ok: res.ok, data: data }; });
    }).then(function (r) {
      if (r.ok && r.data && r.data.ok) {
        form.classList.add('hidden');
        showMsg('✅ パスワードを変更しました。<a href="/">地図にもどってログイン</a>してください。');
      } else {
        submit.disabled = false;
        showError((r.data && r.data.error) || 'エラーが発生しました。もう一度お試しください。');
      }
    }).catch(function () {
      submit.disabled = false;
      showError('通信エラーが発生しました。もう一度お試しください。');
    });
  });
})();

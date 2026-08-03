<?php
/**
 * フロントコントローラ。/api/* のリクエストをここで振り分ける。
 * .htaccess の rewrite（^api(/.*)?$ -> api/index.php）が前提。
 * ローカル検証では router.php 経由でここに到達する。
 */

// 内部エラー詳細を画面に出さない（ログにのみ記録）
ini_set('display_errors', '0');

require __DIR__ . '/../lib/estimate.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/trust.php';
require __DIR__ . '/../lib/reputation.php';
require __DIR__ . '/../lib/overpass.php';
require __DIR__ . '/../lib/mail.php';
require __DIR__ . '/../lib/db.php';

// 予期しない例外は一般的な 500 で返す（内部情報を漏らさない）
set_exception_handler(function ($e) {
    error_log('[parking-map] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'サーバーエラーが発生しました'], JSON_UNESCAPED_UNICODE);
});

$configPath = __DIR__ . '/../lib/config.php';
if (!file_exists($configPath)) {
    json_error('サーバー設定（lib/config.php）が見つかりません。config.sample.php を参考に作成してください。', 500);
}
$config = require $configPath;

$UPLOAD_DIR = __DIR__ . '/../uploads';

try {
    $db = new DB($config);
} catch (Throwable $e) {
    error_log('[parking-map] DB connect failed: ' . $e->getMessage());
    json_error('サーバーエラーが発生しました（データベース接続）', 500);
}

// ---- ルート解決 ----
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// "/api" 以降を取り出す
$route = preg_replace('#^.*?/api#', '', $path);
$route = '/' . ltrim($route, '/');
$route = rtrim($route, '/');
if ($route === '') {
    $route = '/';
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---- スクリプト対策・レート制限のヘルパ ----

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** 自サイト以外からの書き込みを拒否（素のスクリプト対策）。Origin か Referer のホスト一致を要求。 */
function require_same_origin(): void
{
    // HTTP_HOST はポートを含むことがあるので除いてホスト名だけで比較
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
    $ok = false;
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $reqHost = parse_url($_SERVER[$h], PHP_URL_HOST);
            if ($reqHost !== null && strcasecmp($reqHost, $host) === 0) {
                $ok = true;
            }
        }
    }
    // Origin/Referer が両方無い（＝ブラウザ以外の可能性が高い）場合も拒否
    if (!$ok) {
        json_error('不正なリクエストです（このサイトから操作してください）', 403);
    }
}

/** ハニーポット: 人間が触らない隠しフィールドに値があれば bot とみなす。 */
function reject_if_bot(array $body): void
{
    if (!empty($body['website']) || !empty($body['hp'])) {
        // bot と判断。成功に見せかけて何もしない
        json_out(['ok' => true]);
    }
}

/** IP 単位のレート制限。超過で 429。 */
function rate_guard(DB $db, string $action, int $perMin, int $perHour): void
{
    $ip = client_ip();
    if (!$db->rateAllow("$action:m:$ip", $perMin, 60)
        || !$db->rateAllow("$action:h:$ip", $perHour, 3600)) {
        json_error('操作が多すぎます。少し時間をおいて再度お試しください。', 429);
    }
}

/** 管理者セッションを要求。未ログインなら 403。 */
function require_admin(): void
{
    start_session();
    if (empty($_SESSION['admin'])) {
        json_error('管理者のみ操作できます', 403);
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // HTTPS のときだけ Secure を付ける（ローカル http 検証を壊さない）
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime'  => 0,
        'path'      => '/',
        'httponly'  => true,       // JS から Cookie を読めなくする
        'samesite'  => 'Lax',      // 他サイトからの送信を制限（CSRF 緩和）
        'secure'    => $secure,    // HTTPS のみ送信
    ]);
    session_start();
}

/** ログイン中アカウントの token を返す（未ログインなら null）。 */
function current_token(): ?string
{
    start_session();
    return $_SESSION['token'] ?? null;
}

/** ログイン必須。未ログインなら 401。@return string アカウント token */
function require_login(): string
{
    $t = current_token();
    if ($t === null) {
        json_error('ログインが必要です', 401);
    }
    return $t;
}

// 駐車場を JSON 用に整形（概算料金を付与）
function decorate_lot(array $lot, float $hours): array
{
    $lot['kind'] = ($lot['kind'] ?? 'parking') === 'shop' ? 'shop' : 'parking';
    $lot['estimate'] = estimateFee($lot, $hours);
    $lot['rates'] = normalizeRates($lot['rates'] ?? null); // 料金行を配列で返す
    $lot['trust'] = trustLevel($lot); // 信頼度ランク（みんなの確認で上がる）
    // 数値カラムを数値型に
    foreach (['id', 'hourly_rate', 'max_rate', 'capacity', 'confirm_count', 'report_count', 'hidden'] as $k) {
        if (isset($lot[$k]) && $lot[$k] !== null) {
            $lot[$k] = (int)$lot[$k];
        }
    }
    $lot['lat'] = (float)$lot['lat'];
    $lot['lng'] = (float)$lot['lng'];
    // 内部トークンは外に出さない
    unset($lot['created_by_token'], $lot['reviewer_token'], $lot['approver_token'], $lot['points_revoked']);
    return $lot;
}

// ---- ルーティング ----

// GET /api/lots
if ($route === '/lots' && $method === 'GET') {
    $hours = (float)($_GET['hours'] ?? 1);
    if ($hours <= 0) {
        $hours = 1;
    }
    $sort  = $_GET['sort'] ?? 'updated';
    $bbox  = parse_bbox($_GET['bbox'] ?? null);

    $lots = array_map(fn($l) => decorate_lot($l, $hours), $db->listLots($bbox));

    $nullsLast = fn($v) => $v === null ? PHP_INT_MAX : $v;
    $cmp = [
        'hourly'   => fn($a, $b) => $nullsLast($a['hourly_rate']) <=> $nullsLast($b['hourly_rate']),
        'max'      => fn($a, $b) => $nullsLast($a['max_rate']) <=> $nullsLast($b['max_rate']),
        'estimate' => fn($a, $b) => $nullsLast($a['estimate']) <=> $nullsLast($b['estimate']),
        'updated'  => fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']),
    ][$sort] ?? null;
    if ($cmp) {
        usort($lots, $cmp);
    }
    json_out(['lots' => $lots, 'hours' => $hours, 'sort' => $sort]);
}

// GET /api/lots/{id}
if (preg_match('#^/lots/(\d+)$#', $route, $m) && $method === 'GET') {
    $hours = (float)($_GET['hours'] ?? 1);
    $lot = $db->getLot((int)$m[1]);
    if (!$lot) {
        json_error('not found', 404);
    }
    json_out(['lot' => decorate_lot($lot, $hours > 0 ? $hours : 1)]);
}

// POST /api/lots  （新規登録）
if ($route === '/lots' && $method === 'POST') {
    require_same_origin();
    reject_if_bot($_POST);
    $token = require_login(); // 投稿はログイン必須。識別はセッションから（偽造不可）
    rate_guard($db, 'post', 5, 30); // 登録: 5件/分, 30件/時（IP単位）
    $parsed = read_lot_body($_POST);
    $photo  = handle_photo_upload($UPLOAD_DIR, $config['max_upload_bytes'] ?? 6291456);
    if (isset($parsed['error'])) {
        json_error($parsed['error'], 400);
    }
    if (isset($photo['error'])) {
        json_error($photo['error'], 400);
    }
    $data = $parsed['data'];
    // 写真は駐車場では必須（料金の根拠）。店では任意（営業時間がメイン情報）。
    if ($data['kind'] !== 'shop' && empty($photo['filename'])) {
        json_error('料金看板の写真を添付してください（写真は必須です）', 400);
    }
    $account = $db->getAccountByToken($token);
    $data['photo']  = $photo['filename'];
    $data['source'] = 'user';
    $data['nickname'] = $account['username'] ?? null; // 投稿者名はアカウント名
    // 可変料金行（rates）: 指定があれば保存し、比較用 hourly/max を導出して上書き（駐車場のみ）
    if ($data['kind'] !== 'shop') {
        $rates = process_rates($_POST['rates'] ?? null);
        $data['rates'] = $rates['json'];
        if ($rates['json'] !== null) {
            $data['hourly_rate'] = $rates['derived']['hourly_rate'];
            $data['max_rate']    = $rates['derived']['max_rate'];
        }
    }
    $data['created_by_token'] = $token;
    $data['status'] = 'pending_approval'; // 新規は緩く: 誰か1人の承認で公開（ポイントは公開時に付与）
    $lot = $db->createLot($data);
    $stats = $db->getUserStats($token);
    $me = ['nickname' => $stats['nickname']] + reputation($stats);
    json_out(['pending' => true, 'lot' => decorate_lot($lot, 1), 'me' => $me], 201);
}

// POST /api/lots/{id}  （編集。PHP は PUT+multipart で $_FILES が空になるため POST に統一）
if (preg_match('#^/lots/(\d+)$#', $route, $m) && $method === 'POST') {
    require_same_origin();
    reject_if_bot($_POST);
    require_login(); // 編集もログイン必須
    rate_guard($db, 'edit', 10, 60); // 編集: 10件/分, 60件/時
    $id = (int)$m[1];
    $existing = $db->getLot($id);
    if (!$existing) {
        json_error('not found', 404);
    }
    $parsed = read_lot_body($_POST);
    $photo  = handle_photo_upload($UPLOAD_DIR, $config['max_upload_bytes'] ?? 6291456);
    if (isset($parsed['error'])) {
        json_error($parsed['error'], 400);
    }
    if (isset($photo['error'])) {
        json_error($photo['error'], 400);
    }
    $data = $parsed['data'];
    $token = current_token();
    $isShop = ($existing['kind'] ?? 'parking') === 'shop';

    // ---- 編集は厳しく: 公開済みの情報への編集は「提案」として保留し、10承認で確定 ----
    // 承認までは今の情報（旧写真）が表示され続け、承認画面では旧・新の2枚を見比べられる。
    if (($existing['status'] ?? 'published') === 'published') {
        $payload = [
            'name'     => $data['name'],
            'lat'      => $data['lat'],
            'lng'      => $data['lng'],
            'address'  => $data['address'],
            'fee_note' => $data['fee_note'],
        ];
        if ($isShop) {
            $payload['hours'] = $data['hours'];
            $payload['category'] = $data['category'];
        } else {
            $payload['capacity'] = $data['capacity'];
            $rates = process_rates($_POST['rates'] ?? null);
            if ($rates['json'] !== null) {
                $payload['rates']       = $rates['json'];
                $payload['hourly_rate'] = $rates['derived']['hourly_rate'];
                $payload['max_rate']    = $rates['derived']['max_rate'];
            }
        }
        if (!empty($photo['filename'])) {
            $payload['photo'] = $photo['filename']; // 承認確定時に差し替え（それまで新旧2枚が並ぶ）
        }
        $proposal = $db->createProposal($id, $token, $payload);
        json_out([
            'proposed'  => true,
            'proposal'  => ['id' => (int)$proposal['id'], 'approves_needed' => $proposal['approves_needed']],
            'threshold' => DB::APPROVE_THRESHOLD,
        ], 202);
    }

    // ---- 公開前(承認待ち等)の情報は提案にせず、そのまま更新（実質発生しない保険）----
    $data['photo'] = $photo['filename'];
    $data['source'] = 'user';
    if (!$isShop) {
        $rates = process_rates($_POST['rates'] ?? null);
        $data['rates'] = $rates['json'];
        if ($rates['json'] !== null) {
            $data['hourly_rate'] = $rates['derived']['hourly_rate'];
            $data['max_rate']    = $rates['derived']['max_rate'];
        }
    }
    $lot = $db->updateLot($id, $data);
    if ($photo['filename'] && $existing['photo'] && $existing['photo'] !== $lot['photo']) {
        @unlink($UPLOAD_DIR . '/' . $existing['photo']);
    }
    json_out(['lot' => decorate_lot($lot, 1)]);
}

// POST /api/lots/{id}/confirm | report
if (preg_match('#^/lots/(\d+)/(confirm|report)$#', $route, $m) && $method === 'POST') {
    require_same_origin();
    $token = require_login(); // 確認/報告もログイン必須。1アカウント1票（識別はセッション）
    rate_guard($db, 'vote', 20, 200); // 確認/報告: 20件/分, 200件/時（IP単位）
    $id = (int)$m[1];
    $kind = $m[2];
    $body = read_json_body();
    // 報告理由: inappropriate（不適切）/ closed（なくなった・閉鎖）を記録し、閾値で自動非表示
    $reason = $body['reason'] ?? '';
    $comment = ($kind === 'report' && in_array($reason, ['inappropriate', 'closed'], true))
        ? $reason : ($body['comment'] ?? null);
    // 確認時、その駐車場が「要更新（3か月以上）」だったかを記録（鮮度キーパー用）
    $target = $db->getLot($id);
    $wasStale = $target ? !empty(trustLevel($target)['stale']) : false;
    $result = $db->addReport($id, $token, $kind, $comment, $wasStale);
    if (!$result['ok'] && ($result['reason'] ?? '') === 'notfound') {
        json_error('not found', 404);
    }
    if (!$result['ok'] && ($result['reason'] ?? '') === 'duplicate') {
        json_error($kind === 'confirm' ? 'すでに確認済みです' : 'すでに報告済みです', 409);
    }
    // 確認/報告後の、投票者本人の最新の貢献度も返す（チップ即時更新用）
    $stats = $db->getUserStats($token);
    json_out([
        'lot' => decorate_lot($result['lot'], 1),
        'me'  => ['nickname' => $stats['nickname']] + reputation($stats),
    ]);
}

// GET /api/queue?type=new|edit  （審査待ち一覧。ログイン必須・自分が関与したものは除外）
if ($route === '/queue' && $method === 'GET') {
    $token = require_login();
    $type = ($_GET['type'] ?? 'new') === 'edit' ? 'edit' : 'new';
    if ($type === 'edit') {
        // 編集の承認待ち: 現状(lot)と提案(payload)の両方を返す（新旧2枚を見比べるため）
        $items = array_map(function ($row) {
            return [
                'lot'      => decorate_lot($row['lot'], 1),
                'proposal' => $row['proposal'],
            ];
        }, $db->listEditQueue($token, 50));
    } else {
        $items = array_map(fn($l) => decorate_lot($l, 1), $db->listQueue('new', $token, 50));
    }
    json_out(['type' => $type, 'items' => $items, 'counts' => $db->queueCounts($token)]);
}

// GET /api/queue/counts  （審査待ち件数。ボタンのバッジ用）
if ($route === '/queue/counts' && $method === 'GET') {
    $token = current_token();
    if ($token === null) {
        json_out(['counts' => ['new' => 0, 'edit' => 0]]);
    }
    json_out(['counts' => $db->queueCounts($token)]);
}

// POST /api/lots/{id}/publish  {ok}  （新規の承認: ok=trueで公開 / falseで却下。1人でOK＝緩く）
if (preg_match('#^/lots/(\d+)/publish$#', $route, $m) && $method === 'POST') {
    require_same_origin();
    $token = require_login();
    rate_guard($db, 'moderate', 20, 200);
    $body = read_json_body();
    $ok = !empty($body['ok']);
    $res = $db->approvePublish((int)$m[1], $token, $ok, PT_POST, PT_PHOTO, PT_APPROVE);
    if (!$res['ok'] && ($res['reason'] ?? '') === 'self') {
        json_error('自分の投稿は承認できません', 403);
    }
    if (!$res['ok']) {
        json_error('この投稿は承認できません（すでに処理済みの可能性）', 404);
    }
    $stats = $db->getUserStats($token);
    json_out([
        'approved' => true, 'result' => $res['result'],
        'me' => ['nickname' => $stats['nickname']] + reputation($stats),
        'counts' => $db->queueCounts($token),
    ]);
}

// GET /api/lots/{id}/proposals  （店への承認待ち編集の一覧）
if (preg_match('#^/lots/(\d+)/proposals$#', $route, $m) && $method === 'GET') {
    $id = (int)$m[1];
    $list = array_map(function ($p) {
        return [
            'id'              => (int)$p['id'],
            'payload'         => $p['payload'],
            'approve_count'   => (int)$p['approve_count'],
            'approves_needed' => (int)$p['approves_needed'],
            'created_at'      => $p['created_at'],
        ];
    }, $db->listPendingProposals($id));
    json_out(['proposals' => $list, 'threshold' => DB::APPROVE_THRESHOLD]);
}

// POST /api/proposals/{id}/approve  （編集提案を承認。10承認で確定）
if (preg_match('#^/proposals/(\d+)/approve$#', $route, $m) && $method === 'POST') {
    require_same_origin();
    $token = require_login(); // 承認もログイン必須。1アカウント1票
    rate_guard($db, 'vote', 20, 200);
    $id = (int)$m[1];
    $res = $db->approveProposal($id, $token, PT_APPROVE_EDIT, PT_EDIT_APPLIED);
    if (!$res['ok'] && ($res['reason'] ?? '') === 'self') {
        json_error('自分が提案した編集は承認できません', 403);
    }
    if (!$res['ok'] && ($res['reason'] ?? '') === 'notfound') {
        json_error('この編集提案は見つからないか、すでに確定/取り下げされています', 404);
    }
    if (!$res['ok'] && ($res['reason'] ?? '') === 'duplicate') {
        json_error('すでに承認済みです', 409);
    }
    // 確定して写真が差し替わった場合、旧写真ファイルを削除
    if ($res['applied'] && !empty($res['oldPhoto'])) {
        @unlink($UPLOAD_DIR . '/' . $res['oldPhoto']);
    }
    $stats = $db->getUserStats($token);
    $out = [
        'approved'        => true,
        'applied'         => $res['applied'],
        'approve_count'   => (int)$res['proposal']['approve_count'],
        'approves_needed' => (int)$res['proposal']['approves_needed'],
        'me'              => ['nickname' => $stats['nickname']] + reputation($stats),
        'counts'          => $db->queueCounts($token),
    ];
    if ($res['applied'] && !empty($res['lot'])) {
        $out['lot'] = decorate_lot($res['lot'], 1);
    }
    json_out($out);
}

// GET /api/places-nearby?bbox=minLng,minLat,maxLng,maxLat  （地図上の店：OSM店POI・サーバーキャッシュ）
if ($route === '/places-nearby' && $method === 'GET') {
    $bbox = parse_bbox($_GET['bbox'] ?? null);
    if (!$bbox) {
        json_out(['places' => []]);
    }
    $list = places_nearby($bbox, __DIR__ . '/../cache');
    json_out(['places' => $list]);
}

// GET /api/parking-nearby?bbox=minLng,minLat,maxLng,maxLat  （地図上のP：OSM駐車場・サーバーキャッシュ）
if ($route === '/parking-nearby' && $method === 'GET') {
    $bbox = parse_bbox($_GET['bbox'] ?? null);
    if (!$bbox) {
        json_out(['parkings' => []]);
    }
    $list = parking_nearby($bbox, __DIR__ . '/../cache');
    json_out(['parkings' => $list]);
}

// ---- 認証（アカウント） ----

// POST /api/auth/register  {username, email, password}
if ($route === '/auth/register' && $method === 'POST') {
    require_same_origin();
    $body = read_json_body();
    reject_if_bot($body);
    rate_guard($db, 'register', 3, 10); // アカウント作成: 3/分, 10/時
    $username = trim($body['username'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = (string)($body['password'] ?? '');
    if (mb_strlen($username) < 2 || mb_strlen($username) > 20) {
        json_error('ニックネームは2〜20文字で入力してください', 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('メールアドレスの形式が正しくありません', 400);
    }
    if (strlen($password) < 6) {
        json_error('パスワードは6文字以上にしてください', 400);
    }
    if (($config['mail_from'] ?? '') === '') {
        json_error('サーバーのメール設定が未完了のため登録できません（管理者にご連絡ください）', 500);
    }
    $token = bin2hex(random_bytes(16));
    $verifyToken = bin2hex(random_bytes(16));
    $res = $db->createAccount($username, $email, password_hash($password, PASSWORD_DEFAULT), $token, $verifyToken);
    if (!$res['ok'] && ($res['reason'] ?? '') === 'duplicate') {
        json_error('そのニックネームまたはメールアドレスは既に使われています', 409);
    }
    $link = site_url($config) . '/api/auth/verify?token=' . $verifyToken;
    send_mail($config, $email, 'メールアドレスの確認 | みんなの駐車場マップ',
        "みんなの駐車場マップにご登録ありがとうございます。\n\n"
        . "以下のリンクを開いてメールアドレスの確認を完了してください（24時間有効）。\n\n"
        . $link . "\n\n"
        . "心当たりがない場合はこのメールを破棄してください。\n");
    json_out(['registered' => true, 'needVerify' => true]);
}

// GET /api/auth/verify?token=...  （メール内リンク。HTMLで結果表示）
if ($route === '/auth/verify' && $method === 'GET') {
    $ok = $db->verifyAccount(trim($_GET['token'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    $msg = $ok ? '✅ メール認証が完了しました。ログインしてご利用ください。'
               : '⚠️ リンクが無効か、既に認証済みです。';
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>メール認証</title><style>body{font-family:sans-serif;max-width:560px;margin:60px auto;padding:0 20px;line-height:1.8;color:#1c2430}a{color:#1573ff}</style></head>'
        . '<body><h2>🅿️ みんなの駐車場マップ</h2><p>' . $msg . '</p><p><a href="/">地図をひらく →</a></p></body></html>';
    exit;
}

// POST /api/auth/resend  {email}  （確認メール再送）
if ($route === '/auth/resend' && $method === 'POST') {
    require_same_origin();
    rate_guard($db, 'resend', 3, 10);
    $body = read_json_body();
    $email = trim($body['email'] ?? '');
    $account = $email !== '' ? $db->getAccountByEmail($email) : null;
    if ($account && (int)$account['verified'] === 0) {
        $verifyToken = bin2hex(random_bytes(16));
        $db->refreshVerifyToken((int)$account['id'], $verifyToken);
        $link = site_url($config) . '/api/auth/verify?token=' . $verifyToken;
        send_mail($config, $email, 'メールアドレスの確認（再送） | みんなの駐車場マップ',
            "以下のリンクでメール認証を完了してください（24時間有効）。\n\n" . $link . "\n");
    }
    json_out(['ok' => true]); // 存在有無を明かさない
}

// POST /api/auth/login  {email, password}
if ($route === '/auth/login' && $method === 'POST') {
    require_same_origin();
    rate_guard($db, 'login', 5, 30); // 総当たり対策
    $body = read_json_body();
    $email = trim($body['email'] ?? '');
    $password = (string)($body['password'] ?? '');
    $account = $email !== '' ? $db->getAccountByEmail($email) : null;
    if (!$account || !password_verify($password, $account['password_hash'])) {
        json_error('メールアドレスまたはパスワードが違います', 401);
    }
    if ((int)$account['verified'] === 0) {
        json_error('メール認証が完了していません。確認メールのリンクから認証してください', 403);
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['token'] = $account['token'];
    $_SESSION['username'] = $account['username'];
    $stats = $db->getUserStats($account['token']);
    json_out(['loggedIn' => true, 'username' => $account['username'], 'reputation' => reputation($stats)]);
}

// POST /api/auth/forgot  {email}  （パスワード再発行メール）
if ($route === '/auth/forgot' && $method === 'POST') {
    require_same_origin();
    rate_guard($db, 'forgot', 3, 10);
    $body = read_json_body();
    $email = trim($body['email'] ?? '');
    $account = $email !== '' ? $db->getAccountByEmail($email) : null;
    if ($account) {
        $resetToken = bin2hex(random_bytes(16));
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 3600); // 1時間有効
        $db->setResetToken((int)$account['id'], $resetToken, $expires);
        $link = site_url($config) . '/reset.html?token=' . $resetToken;
        send_mail($config, $email, 'パスワード再設定 | みんなの駐車場マップ',
            "パスワード再設定のリクエストを受け付けました。\n\n"
            . "以下のリンクから新しいパスワードを設定してください（1時間有効）。\n\n"
            . $link . "\n\n"
            . "心当たりがない場合はこのメールを破棄してください。\n");
    }
    json_out(['ok' => true]); // 存在有無を明かさない
}

// POST /api/auth/reset  {token, password}
if ($route === '/auth/reset' && $method === 'POST') {
    require_same_origin();
    rate_guard($db, 'reset', 5, 30);
    $body = read_json_body();
    $rtoken = trim($body['token'] ?? '');
    $password = (string)($body['password'] ?? '');
    if (strlen($password) < 6) {
        json_error('パスワードは6文字以上にしてください', 400);
    }
    $account = $db->getAccountByResetToken($rtoken);
    if (!$account) {
        json_error('リンクが無効か、有効期限が切れています。もう一度お試しください', 400);
    }
    $db->updatePassword((int)$account['id'], password_hash($password, PASSWORD_DEFAULT));
    json_out(['ok' => true]);
}

// POST /api/auth/logout
if ($route === '/auth/logout' && $method === 'POST') {
    start_session();
    unset($_SESSION['token'], $_SESSION['username']);
    json_out(['ok' => true]);
}

// GET /api/auth/me  （ログイン状態＋貢献度）
if ($route === '/auth/me' && $method === 'GET') {
    $token = current_token();
    if ($token === null) {
        json_out(['loggedIn' => false]);
    }
    $stats = $db->getUserStats($token);
    json_out(['loggedIn' => true, 'username' => $stats['nickname'], 'reputation' => reputation($stats)]);
}

// ---- 管理（admin） ----

// POST /api/admin/login  {password}
if ($route === '/admin/login' && $method === 'POST') {
    require_same_origin();
    rate_guard($db, 'adminlogin', 5, 20); // 総当たり対策
    $body = read_json_body();
    $pw = (string)($body['password'] ?? '');
    $expected = (string)($config['admin_password'] ?? '');
    if ($expected === '') {
        json_error('管理機能は無効です（lib/config.php の admin_password を設定してください）', 403);
    }
    if (!hash_equals($expected, $pw)) {
        json_error('パスワードが違います', 401);
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['admin'] = true;
    json_out(['ok' => true]);
}

// POST /api/admin/logout
if ($route === '/admin/logout' && $method === 'POST') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = [];
    json_out(['ok' => true]);
}

// GET /api/admin/session  （ログイン状態確認）
if ($route === '/admin/session' && $method === 'GET') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    json_out(['admin' => !empty($_SESSION['admin'])]);
}

// GET /api/admin/lots  （全件・非表示含む）
if ($route === '/admin/lots' && $method === 'GET') {
    require_admin();
    $lots = array_map(fn($l) => decorate_lot($l, 1), $db->listAllLots());
    json_out(['lots' => $lots]);
}

// POST /api/lots/{id}/delete  （完全削除・管理者のみ）
if (preg_match('#^/lots/(\d+)/delete$#', $route, $m) && $method === 'POST') {
    require_admin();
    $photo = $db->deleteLot((int)$m[1]);
    if ($photo) {
        @unlink($UPLOAD_DIR . '/' . $photo);
    }
    json_out(['ok' => true]);
}

// POST /api/lots/{id}/hide | unhide  （非表示/復活・管理者のみ）
if (preg_match('#^/lots/(\d+)/(hide|unhide)$#', $route, $m) && $method === 'POST') {
    require_admin();
    $lot = $db->setHidden((int)$m[1], $m[2] === 'hide');
    if (!$lot) {
        json_error('not found', 404);
    }
    json_out(['lot' => decorate_lot($lot, 1)]);
}

json_error('not found: ' . $route, 404);

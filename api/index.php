<?php
/**
 * フロントコントローラ。/api/* のリクエストをここで振り分ける。
 * .htaccess の rewrite（^api(/.*)?$ -> api/index.php）が前提。
 * ローカル検証では router.php 経由でここに到達する。
 */

require __DIR__ . '/../lib/estimate.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/trust.php';
require __DIR__ . '/../lib/reputation.php';
require __DIR__ . '/../lib/overpass.php';
require __DIR__ . '/../lib/db.php';

$configPath = __DIR__ . '/../lib/config.php';
if (!file_exists($configPath)) {
    json_error('サーバー設定（lib/config.php）が見つかりません。config.sample.php を参考に作成してください。', 500);
}
$config = require $configPath;

$UPLOAD_DIR = __DIR__ . '/../uploads';

try {
    $db = new DB($config);
} catch (Throwable $e) {
    json_error('データベースに接続できません: ' . $e->getMessage(), 500);
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

// 駐車場を JSON 用に整形（概算料金を付与）
function decorate_lot(array $lot, float $hours): array
{
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
    unset($lot['created_by_token']);
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
    $parsed = read_lot_body($_POST);
    $photo  = handle_photo_upload($UPLOAD_DIR, $config['max_upload_bytes'] ?? 6291456);
    if (isset($parsed['error'])) {
        json_error($parsed['error'], 400);
    }
    if (isset($photo['error'])) {
        json_error($photo['error'], 400);
    }
    // 写真は必須（情報の信頼性を担保するため、写真なしの登録は受け付けない）
    if (empty($photo['filename'])) {
        json_error('料金看板の写真を添付してください（写真は必須です）', 400);
    }
    $data = $parsed['data'];
    $data['photo']  = $photo['filename'];
    $data['source'] = 'user';
    // 可変料金行（rates）: 指定があれば保存し、比較用 hourly/max を導出して上書き
    $rates = process_rates($_POST['rates'] ?? null);
    $data['rates'] = $rates['json'];
    if ($rates['json'] !== null) {
        $data['hourly_rate'] = $rates['derived']['hourly_rate'];
        $data['max_rate']    = $rates['derived']['max_rate'];
    }
    $token = trim($_POST['client_token'] ?? '');
    $data['created_by_token'] = $token ?: null;
    if ($token !== '') {
        $db->upsertUser($token, $data['nickname']);
    }
    $lot = $db->createLot($data);
    $me = null;
    if ($token !== '') {
        $stats = $db->getUserStats($token);
        $me = ['nickname' => $stats['nickname']] + reputation($stats);
    }
    json_out(['lot' => decorate_lot($lot, 1), 'me' => $me], 201);
}

// POST /api/lots/{id}  （編集。PHP は PUT+multipart で $_FILES が空になるため POST に統一）
if (preg_match('#^/lots/(\d+)$#', $route, $m) && $method === 'POST') {
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
    // 編集でも写真は必須。新しい写真が無く、既存写真も無ければ拒否
    if (empty($photo['filename']) && empty($existing['photo'])) {
        json_error('料金看板の写真を添付してください（写真は必須です）', 400);
    }
    $data = $parsed['data'];
    $data['photo'] = $photo['filename']; // null なら維持（db 側で COALESCE）
    $data['source'] = 'user'; // 上書き編集された情報はユーザー情報扱い（地図で赤ピン）
    $rates = process_rates($_POST['rates'] ?? null);
    $data['rates'] = $rates['json'];
    if ($rates['json'] !== null) {
        $data['hourly_rate'] = $rates['derived']['hourly_rate'];
        $data['max_rate']    = $rates['derived']['max_rate'];
    }
    $lot = $db->updateLot($id, $data);
    // 写真差し替え時は旧写真を削除
    if ($photo['filename'] && $existing['photo'] && $existing['photo'] !== $lot['photo']) {
        @unlink($UPLOAD_DIR . '/' . $existing['photo']);
    }
    json_out(['lot' => decorate_lot($lot, 1)]);
}

// POST /api/lots/{id}/confirm | report
if (preg_match('#^/lots/(\d+)/(confirm|report)$#', $route, $m) && $method === 'POST') {
    $id = (int)$m[1];
    $kind = $m[2];
    $body = read_json_body();
    $token = trim($body['client_token'] ?? '');
    if ($token === '') {
        json_error('client_token が必要です', 400);
    }
    $db->upsertUser($token); // 確認/報告した人も貢献として記録
    // 確認時、その駐車場が「要更新（3か月以上）」だったかを記録（鮮度キーパー用）
    $target = $db->getLot($id);
    $wasStale = $target ? !empty(trustLevel($target)['stale']) : false;
    $result = $db->addReport($id, $token, $kind, $body['comment'] ?? null, $wasStale);
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

// GET /api/parking-nearby?bbox=minLng,minLat,maxLng,maxLat  （地図上のP：OSM駐車場・サーバーキャッシュ）
if ($route === '/parking-nearby' && $method === 'GET') {
    $bbox = parse_bbox($_GET['bbox'] ?? null);
    if (!$bbox) {
        json_out(['parkings' => []]);
    }
    $list = parking_nearby($bbox, __DIR__ . '/../cache');
    json_out(['parkings' => $list]);
}

// GET /api/users/me?token=...  （自分の貢献ランク・バッジ）
if ($route === '/users/me' && $method === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if ($token === '') {
        json_error('token が必要です', 400);
    }
    $stats = $db->getUserStats($token);
    json_out(['nickname' => $stats['nickname']] + reputation($stats));
}

json_error('not found: ' . $route, 404);

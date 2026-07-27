<?php
/**
 * 初期データ投入スクリプト（CLI）。
 *
 *   php tools/seed.php
 *
 * seed/seed.json（OpenStreetMap 由来の実在駐車場の位置・名称 + サンプル料金）を DB に登録し、
 * 各駐車場に「料金サンプル」プレースホルダ画像（SVG）を uploads/ へ生成して添付します。
 * すべて source='osm'（＝サンプル・未確認）として登録され、アプリ上でバッジ表示されます。
 * 実在の位置に対する料金はサンプル値です（精度は問いません。ユーザーの確認/編集で育てる想定）。
 *
 * 冪等: 同名・同座標の駐車場が既にあればスキップします（再実行しても重複しません）。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("このスクリプトはコマンドライン（php tools/seed.php）で実行してください。\n");
}

require __DIR__ . '/../lib/db.php';

$configPath = __DIR__ . '/../lib/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "lib/config.php がありません。config.sample.php を参考に作成してください。\n");
    exit(1);
}
$config = require $configPath;
$UPLOAD_DIR = __DIR__ . '/../uploads';
if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0775, true);
}

$seedFile = __DIR__ . '/../seed/seed.json';
$seeds = json_decode(file_get_contents($seedFile), true);
if (!is_array($seeds)) {
    fwrite(STDERR, "seed/seed.json を読み込めません。\n");
    exit(1);
}

$db = new DB($config);
$pdo = $db->pdo();

$existsStmt = $pdo->prepare(
    'SELECT id FROM lots WHERE name = ? AND ABS(lat - ?) < 0.00001 AND ABS(lng - ?) < 0.00001 LIMIT 1'
);

$added = 0;
$skipped = 0;
foreach ($seeds as $s) {
    $existsStmt->execute([$s['name'], $s['lat'], $s['lng']]);
    if ($existsStmt->fetch()) {
        $skipped++;
        continue;
    }

    // プレースホルダの料金看板画像（SVG）を生成
    $photo = make_placeholder_svg($UPLOAD_DIR, $s);

    $db->createLot([
        'name'        => $s['name'],
        'lat'         => (float)$s['lat'],
        'lng'         => (float)$s['lng'],
        'address'     => $s['address'] ?? null,
        'hourly_rate' => $s['hourly_rate'] ?? null,
        'max_rate'    => $s['max_rate'] ?? null,
        'fee_note'    => $s['fee_note'] ?? null,
        'capacity'    => $s['capacity'] ?? null,
        'photo'       => $photo,
        'nickname'    => $s['nickname'] ?? '初期データ',
        'source'      => 'osm',
    ]);
    $added++;
}

echo "初期データ投入完了: 追加 {$added} 件 / スキップ {$skipped} 件（既存）\n";
echo "現在の登録総数: " . $db->countLots() . " 件\n";

/** サンプル料金看板の SVG を uploads/ に生成し、ファイル名を返す。 */
function make_placeholder_svg(string $dir, array $s): string
{
    $name = mb_substr($s['name'], 0, 16);
    $hourly = isset($s['hourly_rate']) ? '¥' . number_format($s['hourly_rate']) . ' / 60分' : '—';
    $max    = isset($s['max_rate']) ? '¥' . number_format($s['max_rate']) . ' / 最大' : '—';
    $esc = fn($t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="400" viewBox="0 0 640 400">
  <rect width="640" height="400" fill="#0f5ad6"/>
  <rect x="24" y="24" width="592" height="352" rx="16" fill="#ffffff"/>
  <text x="320" y="86" text-anchor="middle" font-family="sans-serif" font-size="30" font-weight="bold" fill="#1c2430">{$esc($name)}</text>
  <text x="320" y="122" text-anchor="middle" font-family="sans-serif" font-size="18" fill="#e0453e">🔰 サンプル画像（未確認）</text>
  <line x1="80" y1="150" x2="560" y2="150" stroke="#e2e7ec" stroke-width="2"/>
  <text x="320" y="214" text-anchor="middle" font-family="sans-serif" font-size="40" font-weight="bold" fill="#0f5ad6">{$esc($hourly)}</text>
  <text x="320" y="278" text-anchor="middle" font-family="sans-serif" font-size="34" font-weight="bold" fill="#1faa59">{$esc($max)}</text>
  <text x="320" y="340" text-anchor="middle" font-family="sans-serif" font-size="16" fill="#6b7684">実際の料金は現地の看板をご確認ください</text>
</svg>
SVG;

    $filename = 'seed-' . substr(md5($s['name'] . $s['lat'] . $s['lng']), 0, 12) . '.svg';
    file_put_contents($dir . '/' . $filename, $svg);
    return $filename;
}

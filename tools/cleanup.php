<?php
/**
 * 画像・不要データの掃除スクリプト（CLI・cron 向け）。
 *
 *   php tools/cleanup.php
 *
 * 1) 非表示になってから一定日数（既定30日）過ぎた駐車場を完全削除し、写真も消す
 *    （報告多数で自動非表示になったスパム等を、猶予期間つきで自動削除）
 * 2) どの駐車場からも参照されていない孤立画像ファイルを uploads/ から削除
 *    （写真差し替えや削除で残った古い画像を掃除してストレージを節約）
 * 3) 古い OSM キャッシュ（cache/parking_*.json・cache/places_*.json）を削除
 *    （キャッシュTTLは30日。30日以上使われていないものは消してディスクを節約）
 *
 * コアサーバーの cron に「毎日1回」登録しておけば、管理者の手作業なしで
 * ストレージが自動で片付きます。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("このスクリプトはコマンドライン（php tools/cleanup.php）で実行してください。\n");
}

require __DIR__ . '/../lib/db.php';

$configPath = __DIR__ . '/../lib/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "lib/config.php がありません。\n");
    exit(1);
}
$config = require $configPath;
$UPLOAD_DIR = __DIR__ . '/../uploads';
$CACHE_DIR  = __DIR__ . '/../cache';

// 非表示から何日で完全削除するか
$PURGE_DAYS = (int)($config['purge_hidden_days'] ?? 30);
// 孤立ファイルは作成直後の巻き込み削除を防ぐため、この分数より古いものだけ対象
$ORPHAN_MIN_AGE_MIN = 60;
// OSMキャッシュはこの時間より古いものを削除（TTL30日に合わせて30日）
$CACHE_MAX_AGE_HOURS = 24 * 30;

$db = new DB($config);

// 1) 長期間 非表示の駐車場を完全削除（写真も）
$purged = $db->purgeHiddenOlderThan($PURGE_DAYS);
$purgedFiles = 0;
foreach ($purged['photos'] as $p) {
    if ($p && @unlink($UPLOAD_DIR . '/' . $p)) {
        $purgedFiles++;
    }
}

// 2) 孤立画像ファイルの削除
$referenced = array_flip($db->referencedPhotos());
$keep = ['.htaccess' => 1, '.gitkeep' => 1];
$deletedOrphans = 0;
if (is_dir($UPLOAD_DIR)) {
    foreach (scandir($UPLOAD_DIR) as $file) {
        if ($file === '.' || $file === '..' || isset($keep[$file])) {
            continue;
        }
        $path = $UPLOAD_DIR . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        // 参照されていて、かつ十分古いファイルのみ削除対象
        if (isset($referenced[$file])) {
            continue;
        }
        if (time() - filemtime($path) < $ORPHAN_MIN_AGE_MIN * 60) {
            continue; // アップロード直後の巻き込みを防ぐ
        }
        if (@unlink($path)) {
            $deletedOrphans++;
        }
    }
}

// 3) 古い OSM キャッシュの削除（駐車場 parking_* と 店 places_* の両方）
$deletedCache = 0;
if (is_dir($CACHE_DIR)) {
    $cutoff = time() - $CACHE_MAX_AGE_HOURS * 3600;
    $cacheFiles = array_merge(
        glob($CACHE_DIR . '/parking_*.json') ?: [],
        glob($CACHE_DIR . '/places_*.json') ?: []
    );
    foreach ($cacheFiles as $path) {
        if (is_file($path) && filemtime($path) < $cutoff) {
            if (@unlink($path)) {
                $deletedCache++;
            }
        }
    }
}

// 4) 古いレート制限記録の削除（1日より前）
$prunedRate = $db->pruneRateHits(86400);

echo "掃除完了:\n";
echo "  非表示から{$PURGE_DAYS}日超で削除した駐車場: {$purged['lots']} 件（画像 {$purgedFiles} 件）\n";
echo "  孤立画像の削除: {$deletedOrphans} 件\n";
echo "  古いキャッシュの削除: {$deletedCache} 件\n";
echo "  古いレート記録の削除: {$prunedRate} 件\n";

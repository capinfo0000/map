<?php
/**
 * PHP ビルトインサーバー（php -S）用のルーター。ローカル検証専用。
 *   php -S localhost:8000 router.php
 * 本番（Apache）では .htaccess の rewrite が同じ役割を果たすため、このファイルは使いません。
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// /api/* はフロントコントローラへ
if (preg_match('#^/api(/.*)?$#', $path)) {
    require __DIR__ . '/api/index.php';
    return true;
}

// 実在ファイルはそのまま配信（ビルトインサーバーに委譲）
$full = __DIR__ . $path;
if ($path !== '/' && file_exists($full) && !is_dir($full)) {
    return false;
}

// それ以外は index.html
require __DIR__ . '/index.html';
return true;

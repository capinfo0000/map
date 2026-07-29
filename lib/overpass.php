<?php
/**
 * OpenStreetMap(Overpass) から周辺の駐車場を取得し、サーバー側にキャッシュする。
 * 2回目以降は誰がアクセスしてもキャッシュから高速に返せる（Overpass 負荷も軽減）。
 */

const OVERPASS_CACHE_TTL = 21600; // キャッシュ有効期間（秒）＝6時間
const OVERPASS_MAX_SPAN  = 0.4;   // これより広い範囲はOverpass保護のためリクエストしない（約44km）
const OVERPASS_LIMIT     = 500;   // 取得件数の上限（多いエリアで片側が欠けないよう十分大きく）

/**
 * 指定 bbox の駐車場一覧を返す（キャッシュ優先）。
 * @param array $bbox ['minLat','maxLat','minLng','maxLng']
 * @return array<int,array{lat:float,lng:float,name:string}>
 */
function parking_nearby(array $bbox, string $cacheDir): array
{
    // キャッシュ共有のため 0.01°(≒1.1km) グリッドに丸める。
    // ただし内側に丸めると端が欠けるので、必ず外側へ広げる(floor/ceil)＝画面全体を覆う
    $minLat = floor($bbox['minLat'] * 100) / 100;
    $maxLat = ceil($bbox['maxLat'] * 100) / 100;
    $minLng = floor($bbox['minLng'] * 100) / 100;
    $maxLng = ceil($bbox['maxLng'] * 100) / 100;

    // 広すぎる範囲はスキップ（Overpass 保護）
    if (($maxLat - $minLat) > OVERPASS_MAX_SPAN || ($maxLng - $minLng) > OVERPASS_MAX_SPAN) {
        return [];
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $key  = md5("$minLat,$minLng,$maxLat,$maxLng");
    $file = $cacheDir . '/parking_' . $key . '.json';

    // 有効なキャッシュがあれば即返す
    if (is_file($file) && (time() - filemtime($file) < OVERPASS_CACHE_TTL)) {
        $c = json_decode(file_get_contents($file), true);
        if (is_array($c)) {
            return $c;
        }
    }

    // Overpass から取得
    $data = overpass_fetch($minLat, $minLng, $maxLat, $maxLng);
    if ($data !== null) {
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    }
    // 取得失敗時は、期限切れでも古いキャッシュがあれば返す
    if (is_file($file)) {
        $c = json_decode(file_get_contents($file), true);
        if (is_array($c)) {
            return $c;
        }
    }
    return [];
}

/** Overpass API を叩いて駐車場を取得。失敗時は null。 */
function overpass_fetch(float $minLat, float $minLng, float $maxLat, float $maxLng): ?array
{
    $bbox = "$minLat,$minLng,$maxLat,$maxLng";
    $q = '[out:json][timeout:20];('
        . 'node["amenity"="parking"](' . $bbox . ');'
        . 'way["amenity"="parking"](' . $bbox . ');'
        . ');out center ' . OVERPASS_LIMIT . ';';

    $endpoints = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];
    // 開発サンドボックス等でプロキシ経由の場合に対応（本番では未設定＝直結）
    $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
    $caBundle = '/root/.ccr/ca-bundle.crt';

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'data=' . urlencode($q),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_USERAGENT      => 'minna-parking-map/1.0 (+contact via site)',
        ];
        if ($proxy) {
            $opts[CURLOPT_PROXY] = $proxy;
            if (is_file($caBundle)) {
                $opts[CURLOPT_CAINFO] = $caBundle;
            }
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code !== 200) {
            continue; // 次のエンドポイントへ
        }
        $d = json_decode($res, true);
        if (!is_array($d) || !isset($d['elements'])) {
            continue;
        }
        $out = [];
        foreach ($d['elements'] as $e) {
            $lat = $e['lat'] ?? ($e['center']['lat'] ?? null);
            $lng = $e['lon'] ?? ($e['center']['lon'] ?? null);
            if ($lat === null || $lng === null) {
                continue;
            }
            $name = $e['tags']['name:ja'] ?? ($e['tags']['name'] ?? '');
            $out[] = ['lat' => (float)$lat, 'lng' => (float)$lng, 'name' => (string)$name];
        }
        return $out;
    }
    return null;
}

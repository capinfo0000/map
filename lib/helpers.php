<?php
/** JSON 出力・入力パース・バリデーションのヘルパ。 */

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_out(['error' => $message], $status);
}

/** JSON リクエストボディを配列で取得（confirm/report 用）。 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** "minLng,minLat,maxLng,maxLat" をパース。不正なら null。 */
function parse_bbox(?string $raw): ?array
{
    if (!$raw) {
        return null;
    }
    $p = array_map('floatval', explode(',', $raw));
    if (count($p) !== 4) {
        return null;
    }
    [$minLng, $minLat, $maxLng, $maxLat] = $p;
    return [
        'minLng' => $minLng, 'minLat' => $minLat,
        'maxLng' => $maxLng, 'maxLat' => $maxLat,
    ];
}

/**
 * POST された駐車場フィールドを検証・正規化。
 * @return array{data?:array, error?:string}
 */
function read_lot_body(array $b): array
{
    $kind = ($b['kind'] ?? 'parking') === 'shop' ? 'shop' : 'parking';
    $name = trim($b['name'] ?? '');
    $lat  = isset($b['lat']) ? (float)$b['lat'] : null;
    $lng  = isset($b['lng']) ? (float)$b['lng'] : null;

    if ($name === '') {
        return ['error' => $kind === 'shop' ? '店名を入力してください' : '駐車場名を入力してください'];
    }
    if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng)) {
        return ['error' => '位置（緯度・経度）が不正です'];
    }
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return ['error' => '位置の範囲が不正です'];
    }

    $trimOrNull = function ($v, int $len) {
        $v = trim((string)($v ?? ''));
        if ($v === '') {
            return null;
        }
        return mb_substr($v, 0, $len);
    };

    return ['data' => [
        'kind'        => $kind,
        'name'        => mb_substr($name, 0, 120),
        'lat'         => $lat,
        'lng'         => $lng,
        'address'     => $trimOrNull($b['address'] ?? null, 200),
        'hourly_rate' => toPositiveInt($b['hourly_rate'] ?? null),
        'max_rate'    => toPositiveInt($b['max_rate'] ?? null),
        'fee_note'    => $trimOrNull($b['fee_note'] ?? null, 500),
        'capacity'    => toPositiveInt($b['capacity'] ?? null),
        'hours'       => $trimOrNull($b['hours'] ?? null, 200),   // 営業時間（店用）
        'category'    => $trimOrNull($b['category'] ?? null, 40), // 業種（店用）
        'nickname'    => $trimOrNull($b['nickname'] ?? null, 40),
    ]];
}

/**
 * POST された rates（料金行の JSON 文字列）を検証・正規化し、
 * 保存用 JSON と、比較用に導出した hourly_rate/max_rate を返す。
 * rates が未指定なら json=null（＝旧来の hourly/max をそのまま使う）。
 * @return array{json:?string, derived:array{hourly_rate:?int,max_rate:?int}}
 */
function process_rates($raw): array
{
    if ($raw === null || $raw === '' ) {
        return ['json' => null, 'derived' => ['hourly_rate' => null, 'max_rate' => null]];
    }
    $rates = normalizeRates($raw); // 配列/JSON どちらでも可
    if (!$rates) {
        return ['json' => null, 'derived' => ['hourly_rate' => null, 'max_rate' => null]];
    }
    return [
        'json'    => json_encode($rates, JSON_UNESCAPED_UNICODE),
        'derived' => deriveComparable($rates),
    ];
}

/**
 * アップロードされた写真を保存し、保存ファイル名を返す。無ければ null。
 * @return array{filename?:?string, error?:string}
 */
function handle_photo_upload(string $uploadDir, int $maxBytes): array
{
    if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['filename' => null];
    }
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'アップロードに失敗しました'];
    }
    if ($f['size'] > $maxBytes) {
        return ['error' => '画像サイズが大きすぎます'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    $extByMime = [
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/webp' => '.webp',
    ];
    if (!isset($extByMime[$mime])) {
        return ['error' => '画像ファイル（JPEG/PNG/WebP）のみアップロードできます'];
    }
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    // 保存前に軽量化（長辺 1280px・JPEG 品質78 に再エンコード）。GD が無ければ原本を保存。
    if (function_exists('imagecreatefromstring')) {
        $filename = date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.jpg';
        if (compress_image($f['tmp_name'], $uploadDir . '/' . $filename, 1280, 78)) {
            return ['filename' => $filename];
        }
    }
    // フォールバック: 原本をそのまま保存
    $filename = date('YmdHis') . '-' . bin2hex(random_bytes(5)) . $extByMime[$mime];
    if (!move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $filename)) {
        return ['error' => '画像の保存に失敗しました'];
    }
    return ['filename' => $filename];
}

/** 画像を長辺 maxSide 以内に縮小し JPEG で保存。成功で true。 */
function compress_image(string $src, string $dest, int $maxSide, int $quality): bool
{
    $raw = @file_get_contents($src);
    if ($raw === false) {
        return false;
    }
    $img = @imagecreatefromstring($raw);
    if ($img === false) {
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);

    // EXIF の回転情報を反映（JPEG のみ・関数があれば）
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        if (!empty($exif['Orientation'])) {
            $deg = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation']] ?? 0;
            if ($deg !== 0) {
                $img = imagerotate($img, $deg, 0);
                $w = imagesx($img);
                $h = imagesy($img);
            }
        }
    }

    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    // 透過 PNG 等は白背景に
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $ok = imagejpeg($dst, $dest, $quality);
    imagedestroy($img);
    imagedestroy($dst);
    return $ok;
}

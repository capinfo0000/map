<?php
/**
 * 駐車料金の概算ロジック。
 *
 * 料金はユーザーが自由に行を足せる（例: 10分100円 / 30分250円 / 60分400円 / 24時間800円）。
 * 各行は { minutes: ブロック分数, yen: 金額, is_max: 最大料金(上限)か } の形。
 * 指定時間に対する概算額を計算し、一覧の「概算順」ソートや比較に使う。
 * 実際の課金は看板写真（一次情報）で必ず確認する前提の "目安"。
 *
 * 旧データ（rates 無し）は hourly_rate / max_rate にフォールバックする。
 */

function toPositiveInt($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        return null;
    }
    $n = (int)round((float)$v);
    return $n < 0 ? null : $n;
}

/** "H:MM"/"HH:MM" を検証して返す。不正なら null。 */
function normalizeTime($v): ?string
{
    if (!is_string($v) || $v === '') {
        return null;
    }
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($v), $m)) {
        return null;
    }
    return sprintf('%02d:%s', (int)$m[1], $m[2]);
}

/**
 * rates 配列を正規化。2種類の行を許可する:
 *  - 従量/最大行: {minutes>0, yen>=0, is_max:bool}
 *  - 時間帯行(夜間など): {from:"HH:MM", to:"HH:MM", yen>=0, is_max:true}
 * @param mixed $rates 配列 or JSON文字列
 * @return array<int,array>
 */
function normalizeRates($rates): array
{
    if (is_string($rates)) {
        $rates = json_decode($rates, true);
    }
    if (!is_array($rates)) {
        return [];
    }
    $out = [];
    foreach ($rates as $r) {
        if (!is_array($r)) {
            continue;
        }
        $yen = toPositiveInt($r['yen'] ?? null);
        if ($yen === null) {
            continue;
        }
        $from = normalizeTime($r['from'] ?? null);
        $to   = normalizeTime($r['to'] ?? null);
        if ($from !== null && $to !== null) {
            // 時間帯行（夜間などの最大料金）
            $out[] = ['from' => $from, 'to' => $to, 'yen' => $yen, 'is_max' => true];
        } else {
            // 従量/最大行
            $minutes = toPositiveInt($r['minutes'] ?? null);
            if (!$minutes || $minutes <= 0) {
                continue;
            }
            $out[] = ['minutes' => $minutes, 'yen' => $yen, 'is_max' => !empty($r['is_max'])];
        }
        if (count($out) >= 12) {
            break; // 行数の上限
        }
    }
    return $out;
}

/**
 * 正規化済み rates から、指定時間（時間単位）の概算料金（円）。算出不能なら null。
 */
function estimateFromRates(array $rates, float $hours): ?int
{
    if ($hours <= 0 || !$rates) {
        return null;
    }
    $total = $hours * 60.0;

    $blockCosts = [];
    $capCosts = [];
    foreach ($rates as $r) {
        // 時間帯行（夜間など）は入庫時刻が不明なため概算には含めない（表示のみ）
        if (isset($r['from'])) {
            continue;
        }
        $cost = (int)ceil($total / $r['minutes']) * $r['yen'];
        if ($r['is_max']) {
            $capCosts[] = $cost;
        } else {
            $blockCosts[] = $cost;
        }
    }
    // 通常の従量課金は最も安い行を採用（複数体系がある場合の目安）。最大料金があれば上限として比較。
    $candidates = [];
    if ($blockCosts) {
        $candidates[] = min($blockCosts);
    }
    if ($capCosts) {
        $candidates[] = min($capCosts);
    }
    return $candidates ? min($candidates) : null;
}

/**
 * 指定時間駐車した場合の概算料金（円）。rates があればそれを、無ければ hourly/max を使う。
 * @param array $lot rates / hourly_rate / max_rate を持つ配列
 */
function estimateFee(array $lot, float $hours): ?int
{
    $rates = normalizeRates($lot['rates'] ?? null);
    if ($rates) {
        return estimateFromRates($rates, $hours);
    }
    // 旧データ: hourly_rate/max_rate から概算
    if ($hours <= 0) {
        return null;
    }
    $hourly = toPositiveInt($lot['hourly_rate'] ?? null);
    $max    = toPositiveInt($lot['max_rate'] ?? null);
    $byHour = $hourly !== null ? $hourly * (int)ceil($hours) : null;
    $byMax  = $max !== null ? $max * (int)ceil($hours / 24) : null;
    if ($byHour !== null && $byMax !== null) {
        return min($byHour, $byMax);
    }
    return $byHour ?? $byMax;
}

/**
 * rates から比較用の hourly_rate（1時間概算）と max_rate（最大料金の最小値）を導出。
 * 「時間料金順」「最大料金順」ソート・表示のために保存時に計算する。
 * @return array{hourly_rate:?int, max_rate:?int}
 */
function deriveComparable(array $rates): array
{
    if (!$rates) {
        return ['hourly_rate' => null, 'max_rate' => null];
    }
    $hourly = estimateFromRates($rates, 1.0);
    $caps = [];
    foreach ($rates as $r) {
        // 終日の最大料金のみ（時間帯行は全日最大ではないので除外）
        if ($r['is_max'] && !isset($r['from'])) {
            $caps[] = $r['yen'];
        }
    }
    return ['hourly_rate' => $hourly, 'max_rate' => $caps ? min($caps) : null];
}

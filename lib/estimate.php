<?php
/**
 * 駐車料金の概算ロジック（JS 版 estimate.js の移植）。
 * 概算 = min(時間料金 × ceil(時間), 最大料金 × ceil(時間/24))。片方欠損は他方、両欠損は null。
 */

function toPositiveInt($v): ?int
{
    if ($v === null || $v === '' ) {
        return null;
    }
    if (!is_numeric($v)) {
        return null;
    }
    $n = (int)round((float)$v);
    return $n < 0 ? null : $n;
}

/**
 * @param array $lot hourly_rate / max_rate を持つ配列
 * @param float $hours 駐車時間（時間）
 * @return int|null 概算料金（円）
 */
function estimateFee(array $lot, float $hours): ?int
{
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
    if ($byHour !== null) {
        return $byHour;
    }
    if ($byMax !== null) {
        return $byMax;
    }
    return null;
}

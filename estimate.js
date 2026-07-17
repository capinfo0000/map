'use strict';

/**
 * 駐車料金の概算ロジック。
 *
 * 料金体系は駐車場ごとに多様（20分課金・夜間最大・曜日別…）だが、MVP では
 * 比較可能性を優先し「時間料金(hourly_rate) / 最大料金(max_rate)」の2軸に正規化して持つ。
 * ここでは指定した駐車時間に対する概算額を返し、一覧の「概算順」ソートに使う。
 * 実際の課金は看板写真(一次情報)と fee_note を必ず確認する前提の "目安" である。
 */

/**
 * 指定時間（時間単位）駐車した場合の概算料金（円）を返す。
 * - hourly_rate と max_rate の両方があれば安い方を採用（多くのコインパーキングの挙動に近い）。
 * - どちらか一方しか無ければそれを使う。
 * - どちらも無ければ null（＝概算不能）。
 *
 * @param {{hourly_rate?: number|null, max_rate?: number|null}} lot
 * @param {number} hours 駐車時間（時間、小数可。例: 0.5 = 30分）
 * @returns {number|null} 概算料金（円）。算出不能なら null。
 */
function estimate(lot, hours) {
  const h = Number(hours);
  if (!Number.isFinite(h) || h <= 0) return null;

  const hourly = toPositiveInt(lot && lot.hourly_rate);
  const max = toPositiveInt(lot && lot.max_rate);

  // 時間料金は 1時間単位で切り上げ（コインパーキングの慣習に寄せた概算）。
  const byHour = hourly != null ? hourly * Math.ceil(h) : null;

  // 最大料金は「1日(24時間)あたりの上限」とみなす。24時間を超える分は日数按分。
  const byMax = max != null ? max * Math.ceil(h / 24) : null;

  if (byHour != null && byMax != null) return Math.min(byHour, byMax);
  if (byHour != null) return byHour;
  if (byMax != null) return byMax;
  return null;
}

function toPositiveInt(v) {
  if (v === null || v === undefined || v === '') return null;
  const n = Number(v);
  if (!Number.isFinite(n) || n < 0) return null;
  return Math.round(n);
}

module.exports = { estimate, toPositiveInt };

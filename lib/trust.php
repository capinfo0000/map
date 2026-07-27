<?php
/**
 * 駐車場の「信頼度ランク」ロジック。
 *
 * ブロックチェーンのような重い仕組みではなく、その本質＝「中央管理者なしで、多数の合意で
 * 信頼を形成する」を軽量に実現する。複数人が「✅正しい」を押すほど信頼度が上がる。
 */

const TRUST_CONFIRM_OK = 3;    // これ以上の確認で「みんなが確認」
const TRUST_CERTIFIED  = 10;   // これ以上の確認で「認定」
const TRUST_REPORT_WARN = 3;   // これ以上の報告で「要確認」
const TRUST_STALE_DAYS = 90;   // 約3か月。確認も更新もこの日数を超えたら「要更新」

/** ISO8601 文字列から経過日数。取得不能なら null。 */
function daysSinceIso(?string $iso): ?int
{
    if (!$iso) {
        return null;
    }
    $t = strtotime($iso);
    if ($t === false) {
        return null;
    }
    return (int)floor((time() - $t) / 86400);
}

/**
 * @param array $lot confirm_count / report_count / photo を持つ配列
 * @return array{level:string, label:string, color:string, next:?int}
 *   level : flagged|unconfirmed|has-info|confirmed|certified
 *   color : red|gray|blue|green|gold（ピン色/バッジ色のヒント）
 *   next  : 次の段階（認定）まであと何人の確認が必要か。無ければ null
 */
function trustLevel(array $lot): array
{
    $confirm = (int)($lot['confirm_count'] ?? 0);
    $report  = (int)($lot['report_count'] ?? 0);
    $hasPhoto = !empty($lot['photo']);

    // 鮮度: 最終確認日（なければ更新日）からの経過日数
    $ref = !empty($lot['last_confirmed_at']) ? $lot['last_confirmed_at'] : ($lot['updated_at'] ?? null);
    $age = daysSinceIso($ref);
    $isStale = $age !== null && $age > TRUST_STALE_DAYS;

    if ($report >= TRUST_REPORT_WARN) {
        return trust('flagged', "⚠️ 要確認（報告{$report}件）", 'red', null, $age);
    }
    // 3か月以上、確認も更新もなければ「要更新」（過去に認定されていても鮮度を優先）
    if ($isStale) {
        $months = (int)floor($age / 30);
        return trust('stale', "🕒 要更新（約{$months}か月未更新）", 'amber', null, $age);
    }
    if ($confirm >= TRUST_CERTIFIED) {
        return trust('certified', "🏅 認定駐車場（確認{$confirm}人）", 'gold', null, $age);
    }
    if ($confirm >= TRUST_CONFIRM_OK) {
        return trust('confirmed', "✅ みんなが確認（{$confirm}人）", 'green', TRUST_CERTIFIED - $confirm, $age);
    }
    if ($hasPhoto || $confirm >= 1) {
        return trust('has-info', $confirm >= 1 ? "確認{$confirm}人" : '情報あり', 'blue', TRUST_CONFIRM_OK - $confirm, $age);
    }
    return trust('unconfirmed', '未確認', 'gray', TRUST_CONFIRM_OK, $age);
}

function trust(string $level, string $label, string $color, ?int $next, ?int $ageDays): array
{
    return [
        'level'   => $level,
        'label'   => $label,
        'color'   => $color,
        'next'    => $next,
        'ageDays' => $ageDays,
        'stale'   => $level === 'stale',
    ];
}

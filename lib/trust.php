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

    if ($report >= TRUST_REPORT_WARN) {
        return ['level' => 'flagged', 'label' => "⚠️ 要確認（報告{$report}件）", 'color' => 'red', 'next' => null];
    }
    if ($confirm >= TRUST_CERTIFIED) {
        return ['level' => 'certified', 'label' => "🏅 認定駐車場（確認{$confirm}人）", 'color' => 'gold', 'next' => null];
    }
    if ($confirm >= TRUST_CONFIRM_OK) {
        return [
            'level' => 'confirmed',
            'label' => "✅ みんなが確認（{$confirm}人）",
            'color' => 'green',
            'next'  => TRUST_CERTIFIED - $confirm, // 認定まであと何人か
        ];
    }
    if ($hasPhoto || $confirm >= 1) {
        return [
            'level' => 'has-info',
            'label' => $confirm >= 1 ? "確認{$confirm}人" : '情報あり',
            'color' => 'blue',
            'next'  => TRUST_CONFIRM_OK - $confirm, // 「みんなが確認」まであと何人か
        ];
    }
    return [
        'level' => 'unconfirmed',
        'label' => '未確認',
        'color' => 'gray',
        'next'  => TRUST_CONFIRM_OK, // 「みんなが確認」まであと3人
    ];
}

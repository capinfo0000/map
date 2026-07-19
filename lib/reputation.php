<?php
/**
 * ユーザーの「貢献ランク＆バッジ」ロジック。
 *
 * 匿名トークン単位の貢献指標からポイント・ランク・獲得バッジを算出する。
 * ※匿名トークンは端末単位・リセット可能なので厳密な不正防止ではない（将来ログインで強化）。
 */

// ポイント配分
const PT_POST            = 5; // 駐車場を1件登録
const PT_PHOTO           = 3; // 写真付き投稿（登録ポイントに加算）
const PT_VOTE            = 1; // 確認/報告を1回
const PT_CONFIRM_RECEIVED = 2; // 自分の情報が1回確認された

// ランクのしきい値（ポイント）
const RANKS = [
    ['key' => 'bronze',   'label' => '🥉 ブロンズ',   'min' => 0],
    ['key' => 'silver',   'label' => '🥈 シルバー',   'min' => 30],
    ['key' => 'gold',     'label' => '🥇 ゴールド',   'min' => 80],
    ['key' => 'platinum', 'label' => '💎 プラチナ',   'min' => 200],
];

/**
 * @param array{posts:int,photoPosts:int,votes:int,confirmsReceived:int} $stats
 * @return array points/rank/nextRank/badges を含む配列
 */
function reputation(array $stats): array
{
    $posts   = (int)($stats['posts'] ?? 0);
    $photos  = (int)($stats['photoPosts'] ?? 0);
    $votes   = (int)($stats['votes'] ?? 0);
    $recv    = (int)($stats['confirmsReceived'] ?? 0);

    $points = $posts * PT_POST
        + $photos * PT_PHOTO
        + $votes * PT_VOTE
        + $recv * PT_CONFIRM_RECEIVED;

    // 現在ランクと次ランク
    $current = RANKS[0];
    $next = null;
    foreach (RANKS as $i => $r) {
        if ($points >= $r['min']) {
            $current = $r;
            $next = RANKS[$i + 1] ?? null;
        }
    }

    $badges = [
        badge('first_post',  '🌱 初投稿',           $posts >= 1),
        badge('five_posts',  '🏘️ 5件登録',          $posts >= 5),
        badge('photographer','📸 写真マスター',      $photos >= 10),
        badge('verifier',    '🔍 検証者',           $votes >= 20),
        badge('trusted',     '🤝 信頼される投稿者',  $recv >= 50),
    ];

    return [
        'points' => $points,
        'rank'   => ['key' => $current['key'], 'label' => $current['label']],
        'nextRank' => $next ? [
            'key'      => $next['key'],
            'label'    => $next['label'],
            'min'      => $next['min'],
            'remaining' => max(0, $next['min'] - $points),
        ] : null,
        'badges' => $badges,
        'stats'  => [
            'posts' => $posts, 'photoPosts' => $photos,
            'votes' => $votes, 'confirmsReceived' => $recv,
        ],
    ];
}

function badge(string $key, string $label, bool $earned): array
{
    return ['key' => $key, 'label' => $label, 'earned' => $earned];
}

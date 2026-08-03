<?php
/**
 * ユーザーの「貢献ランク＆バッジ」ロジック。
 *
 * 匿名トークン単位の貢献指標からポイント・ランク・獲得バッジを算出する。
 * ※匿名トークンは端末単位・リセット可能なので厳密な不正防止ではない（将来ログインで強化）。
 */

// ポイント配分
// ※投稿・写真・レビュー・承認のポイントは「公開が確定した時点」でポイント台帳(point_events)に
//   記録される。ここでの投稿ポイントは台帳合計(ledgerPoints)として合算される。
const PT_POST            = 5; // 投稿（公開確定時に投稿者へ）＝一番多い
const PT_PHOTO           = 3; // 写真つき投稿の加算（投稿者へ）
const PT_REVIEW          = 1; // （旧）レビュー
const PT_APPROVE         = 2; // 新規投稿の承認（承認者へ）
const PT_APPROVE_EDIT    = 1; // 編集提案の承認1回（承認者へ）
const PT_EDIT_APPLIED    = 2; // 編集が確定した（提案者へ）
const PT_VOTE            = 1; // 確認/報告を1回
const PT_CONFIRM_RECEIVED = 2; // 自分の情報が1回確認された
const PT_REFRESH_BONUS   = 2; // 「要更新」の情報を再確認して鮮度を保った（確認ポイントに加算）

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
    $posts    = (int)($stats['posts'] ?? 0);
    $photos   = (int)($stats['photoPosts'] ?? 0);
    $votes    = (int)($stats['votes'] ?? 0);
    $recv     = (int)($stats['confirmsReceived'] ?? 0);
    $refreshes = (int)($stats['refreshes'] ?? 0);
    $reviews  = (int)($stats['reviews'] ?? 0);
    $approvals = (int)($stats['approvals'] ?? 0);
    // 投稿・写真・レビュー・承認・剥奪/分配の増減はポイント台帳の合計で持つ（付与も剥奪も履歴に残る）
    $ledger   = (int)($stats['ledgerPoints'] ?? 0);

    // 台帳外の指標（確認/報告・自分の情報が確認された・鮮度維持）は従来どおり集計で加算
    $points = $ledger
        + $votes * PT_VOTE
        + $recv * PT_CONFIRM_RECEIVED
        + $refreshes * PT_REFRESH_BONUS;
    if ($points < 0) {
        $points = 0; // 剥奪でマイナスになっても表示上は0止まり
    }

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
        badge('reviewer',    '🕵️ 目利き（レビュー）', $reviews >= 10),
        badge('approver',    '⚖️ 承認者',           $approvals >= 10),
        badge('freshkeeper', '🕒 鮮度キーパー',      $refreshes >= 3),
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
            'refreshes' => $refreshes, 'reviews' => $reviews, 'approvals' => $approvals,
        ],
    ];
}

function badge(string $key, string $label, bool $earned): array
{
    return ['key' => $key, 'label' => $label, 'earned' => $earned];
}

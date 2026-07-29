<?php
/**
 * メール送信ヘルパ（PHP の mail() を使用。コアサーバー標準）。
 * 送信元アドレスとサイトURLは config（mail_from / site_url）で指定。
 * ※共有サーバーの mail() は迷惑メール振り分けされやすいので、送信元は
 *   自ドメインのアドレスにするのが推奨（例: no-reply@あなたのドメイン）。
 */

function send_mail(array $config, string $to, string $subject, string $body): bool
{
    $from = $config['mail_from'] ?? '';
    if ($from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[parking-map] mail skipped (from未設定 or 宛先不正): ' . $to);
        return false;
    }
    // 件名・本文を UTF-8 で
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
    $headers = [];
    $headers[] = 'From: ' . $from;
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), '-f' . preg_replace('/[^\x21-\x7e]/', '', $from));
    if (!$ok) {
        error_log('[parking-map] mail() failed to ' . $to);
    }
    return $ok;
}

/** サイトURL（末尾スラッシュ無し）を返す。 */
function site_url(array $config): string
{
    $u = rtrim((string)($config['site_url'] ?? ''), '/');
    if ($u === '') {
        // 設定が無ければ現在のホストから推測
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $u = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $u;
}

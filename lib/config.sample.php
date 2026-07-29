<?php
/**
 * DB 接続設定のサンプル。
 * これを lib/config.php にコピーして、コアサーバー等の環境に合わせて書き換えてください。
 * lib/config.php は Git 管理外（.gitignore 済み）です。
 *
 * ■ 本番（コアサーバー / MySQL）の例:
 *   管理パネルで作成した MySQL データベース名・ユーザー・パスワード・ホストを設定します。
 */

return [
    // 'mysql' または 'sqlite'
    'driver'  => 'mysql',

    // ---- MySQL 用（driver=mysql のとき） ----
    'host'    => 'localhost',      // コアサーバーの MySQL ホスト名（管理パネルで確認）
    'dbname'  => 'your_db_name',   // 作成したデータベース名
    'user'    => 'your_db_user',   // データベースユーザー
    'pass'    => 'your_db_pass',   // パスワード
    'charset' => 'utf8mb4',

    // ---- SQLite 用（driver=sqlite のとき。ローカル検証向け） ----
    'path'    => __DIR__ . '/../data/parking.db',

    // アップロード上限（バイト）
    'max_upload_bytes' => 6 * 1024 * 1024,

    // 管理画面(admin.html)のログインパスワード（空だと管理機能は無効）
    // 十分に長い推測されにくい文字列を設定してください
    'admin_password' => '',

    // ---- メール認証・パスワード再発行 ----
    // サイトの公開URL（末尾スラッシュ不要）。確認/再発行メールのリンク生成に使用
    'site_url'  => 'https://map.example.com',
    // 送信元メールアドレス（自ドメインのアドレス推奨。空だとメール送信は無効＝認証不可）
    'mail_from' => '',
];

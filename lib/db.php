<?php
/**
 * PDO 接続とデータアクセス層。
 *
 * driver は 'mysql'（本番・コアサーバー）と 'sqlite'（ローカル検証）の両対応。
 * SQL はできるだけ両ドライバで共通化し、差分（AUTO_INCREMENT 等）のみ出し分ける。
 * 日時は ISO8601 UTC 文字列（gmdate('c')）で保存し、JS の new Date() でそのまま解釈できるようにする。
 */

class DB
{
    /** @var PDO */
    private $pdo;
    /** @var string */
    private $driver;

    // 自動非表示のしきい値: 報告がこの数以上たまり、かつ確認数を上回ったら非表示にする
    // （確認された良い情報を守りつつ、荒らし投稿を管理者なしで隠す）
    const HIDE_MIN_REPORTS = 5;
    // 「不適切」通報がこの数に達したら即非表示（ログイン必須で通報が信頼できる前提）
    const HIDE_INAPPROPRIATE = 10;

    public function __construct(array $config)
    {
        $this->driver = $config['driver'] ?? 'sqlite';

        if ($this->driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config['host'] ?? 'localhost',
                $config['dbname'] ?? '',
                $config['charset'] ?? 'utf8mb4'
            );
            $this->pdo = new PDO($dsn, $config['user'] ?? '', $config['pass'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } else {
            $path = $config['path'] ?? (__DIR__ . '/../data/parking.db');
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        $this->migrate();
    }

    /** テーブルを冪等に作成（共有サーバーで phpMyAdmin 操作不要にする）。 */
    private function migrate(): void
    {
        if ($this->driver === 'mysql') {
            $auto = 'INT AUTO_INCREMENT PRIMARY KEY';
            $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        } else {
            $auto = 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $engine = '';
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS lots (
                id                $auto,
                kind              VARCHAR(10) NOT NULL DEFAULT 'parking',
                name              VARCHAR(120) NOT NULL,
                lat               DOUBLE NOT NULL,
                lng               DOUBLE NOT NULL,
                address           VARCHAR(200),
                hourly_rate       INT,
                max_rate          INT,
                fee_note          VARCHAR(500),
                capacity          INT,
                hours             VARCHAR(200),
                category          VARCHAR(40),
                photo             VARCHAR(255),
                nickname          VARCHAR(40),
                source            VARCHAR(20),
                rates             TEXT,
                created_by_token  VARCHAR(80),
                created_at        VARCHAR(30) NOT NULL,
                updated_at        VARCHAR(30) NOT NULL,
                confirm_count     INT NOT NULL DEFAULT 0,
                last_confirmed_at VARCHAR(30),
                report_count      INT NOT NULL DEFAULT 0,
                hidden            INT NOT NULL DEFAULT 0,
                hidden_at         VARCHAR(30)
            )$engine
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS reports (
                id           $auto,
                lot_id       INT NOT NULL,
                client_token VARCHAR(80) NOT NULL,
                kind         VARCHAR(10) NOT NULL,
                comment      VARCHAR(500),
                was_stale    INT NOT NULL DEFAULT 0,
                created_at   VARCHAR(30) NOT NULL,
                UNIQUE (lot_id, client_token, kind)
            )$engine
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                token      VARCHAR(80) PRIMARY KEY,
                nickname   VARCHAR(40),
                created_at VARCHAR(30) NOT NULL
            )$engine
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS accounts (
                id            $auto,
                username      VARCHAR(40) NOT NULL,
                email         VARCHAR(190),
                password_hash VARCHAR(255) NOT NULL,
                token         VARCHAR(80) NOT NULL,
                verified      INT NOT NULL DEFAULT 0,
                verify_token  VARCHAR(80),
                reset_token   VARCHAR(80),
                reset_expires VARCHAR(30),
                created_at    VARCHAR(30) NOT NULL,
                UNIQUE (username),
                UNIQUE (token),
                UNIQUE (email)
            )$engine
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_hits (
                id         $auto,
                bucket     VARCHAR(80) NOT NULL,
                created_at VARCHAR(30) NOT NULL
            )$engine
        ");

        // 編集提案（承認制）: 既存の店(lot)への編集は即時反映せず提案として貯め、
        // 一定数の承認が集まったら本体へ反映する。payload は変更後フィールドの JSON。
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS proposals (
                id             $auto,
                lot_id         INT NOT NULL,
                proposer_token VARCHAR(80),
                payload        TEXT NOT NULL,
                approve_count  INT NOT NULL DEFAULT 0,
                status         VARCHAR(12) NOT NULL DEFAULT 'pending',
                created_at     VARCHAR(30) NOT NULL,
                updated_at     VARCHAR(30) NOT NULL
            )$engine
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS proposal_votes (
                id           $auto,
                proposal_id  INT NOT NULL,
                client_token VARCHAR(80) NOT NULL,
                created_at   VARCHAR(30) NOT NULL,
                UNIQUE (proposal_id, client_token)
            )$engine
        ");
        try {
            $sql = 'CREATE INDEX idx_rate_bucket ON rate_hits (bucket, created_at)';
            if ($this->driver === 'sqlite') {
                $sql = str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $sql);
            }
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            // 既存なら無視
        }

        // 既存 DB 向け: 列が無ければ追加（重複エラーは無視）
        foreach ([
            'ALTER TABLE lots ADD COLUMN created_by_token VARCHAR(80)',
            'ALTER TABLE reports ADD COLUMN was_stale INT NOT NULL DEFAULT 0',
            'ALTER TABLE lots ADD COLUMN hidden INT NOT NULL DEFAULT 0',
            'ALTER TABLE lots ADD COLUMN hidden_at VARCHAR(30)',
            'ALTER TABLE lots ADD COLUMN rates TEXT',
            'ALTER TABLE accounts ADD COLUMN email VARCHAR(190)',
            'ALTER TABLE accounts ADD COLUMN verified INT NOT NULL DEFAULT 0',
            'ALTER TABLE accounts ADD COLUMN verify_token VARCHAR(80)',
            'ALTER TABLE accounts ADD COLUMN reset_token VARCHAR(80)',
            'ALTER TABLE accounts ADD COLUMN reset_expires VARCHAR(30)',
            "ALTER TABLE lots ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'parking'",
            'ALTER TABLE lots ADD COLUMN hours VARCHAR(200)',
            'ALTER TABLE lots ADD COLUMN category VARCHAR(40)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                // 既に存在する場合は無視
            }
        }
        // メール未設定の既存アカウントは認証済み扱いにして温存
        try {
            $this->pdo->exec("UPDATE accounts SET verified = 1 WHERE (email IS NULL OR email = '') AND verified = 0");
        } catch (PDOException $e) {
            // accounts が無い等は無視
        }

        // インデックス（存在しても致命的でないよう try）
        foreach ([
            'CREATE INDEX idx_lots_latlng ON lots (lat, lng)',
            'CREATE INDEX idx_reports_lot ON reports (lot_id)',
            'CREATE INDEX idx_proposals_lot ON proposals (lot_id, status)',
        ] as $sql) {
            try {
                if ($this->driver === 'sqlite') {
                    $sql = str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $sql);
                }
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                // MySQL は IF NOT EXISTS 非対応なので重複は無視
            }
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    // ---- lots ----

    public function createLot(array $d): array
    {
        $now = self::nowIso();
        $stmt = $this->pdo->prepare("
            INSERT INTO lots (kind, name, lat, lng, address, hourly_rate, max_rate, fee_note,
                              capacity, hours, category, photo, nickname, source, rates, created_by_token, created_at, updated_at)
            VALUES (:kind, :name, :lat, :lng, :address, :hourly_rate, :max_rate, :fee_note,
                    :capacity, :hours, :category, :photo, :nickname, :source, :rates, :created_by_token, :created_at, :updated_at)
        ");
        $stmt->execute([
            ':kind' => $d['kind'] ?? 'parking',
            ':name' => $d['name'], ':lat' => $d['lat'], ':lng' => $d['lng'],
            ':address' => $d['address'], ':hourly_rate' => $d['hourly_rate'],
            ':max_rate' => $d['max_rate'], ':fee_note' => $d['fee_note'],
            ':capacity' => $d['capacity'],
            ':hours' => $d['hours'] ?? null, ':category' => $d['category'] ?? null,
            ':photo' => $d['photo'],
            ':nickname' => $d['nickname'], ':source' => $d['source'] ?? 'user',
            ':rates' => $d['rates'] ?? null,
            ':created_by_token' => $d['created_by_token'] ?? null,
            ':created_at' => $now, ':updated_at' => $now,
        ]);
        return $this->getLot((int)$this->pdo->lastInsertId());
    }

    public function getLot(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lots WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array{minLat:float,maxLat:float,minLng:float,maxLng:float}|null $bbox */
    public function listLots(?array $bbox): array
    {
        if ($bbox) {
            $stmt = $this->pdo->prepare("
                SELECT * FROM lots
                WHERE hidden = 0
                  AND lat BETWEEN :minLat AND :maxLat
                  AND lng BETWEEN :minLng AND :maxLng
            ");
            $stmt->execute([
                ':minLat' => $bbox['minLat'], ':maxLat' => $bbox['maxLat'],
                ':minLng' => $bbox['minLng'], ':maxLng' => $bbox['maxLng'],
            ]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query('SELECT * FROM lots WHERE hidden = 0')->fetchAll();
    }

    public function updateLot(int $id, array $d): array
    {
        $now = self::nowIso();
        // 写真は指定があれば差し替え、無ければ従来値を維持
        $stmt = $this->pdo->prepare("
            UPDATE lots SET
                name = :name, lat = :lat, lng = :lng, address = :address,
                hourly_rate = :hourly_rate, max_rate = :max_rate, fee_note = :fee_note,
                capacity = :capacity, hours = :hours, category = :category,
                nickname = :nickname, rates = :rates,
                source = :source,
                photo = COALESCE(:photo, photo),
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $d['name'], ':lat' => $d['lat'], ':lng' => $d['lng'],
            ':address' => $d['address'], ':hourly_rate' => $d['hourly_rate'],
            ':max_rate' => $d['max_rate'], ':fee_note' => $d['fee_note'],
            ':capacity' => $d['capacity'],
            ':hours' => $d['hours'] ?? null, ':category' => $d['category'] ?? null,
            ':nickname' => $d['nickname'],
            ':rates' => $d['rates'] ?? null,
            ':source' => $d['source'] ?? 'user', // 上書き編集された情報は user 扱い（赤ピン）
            ':photo' => $d['photo'], ':updated_at' => $now, ':id' => $id,
        ]);
        return $this->getLot($id);
    }

    // ---- 編集提案（承認制） ----

    // 提案がこの承認数に達したら本体へ反映
    const APPROVE_THRESHOLD = 10;

    /**
     * 編集提案を作成。payload は反映したいフィールドの連想配列。
     * @return array 作成した提案（approvesNeeded 付き）
     */
    public function createProposal(int $lotId, ?string $token, array $payload): array
    {
        $now = self::nowIso();
        $st = $this->pdo->prepare(
            'INSERT INTO proposals (lot_id, proposer_token, payload, approve_count, status, created_at, updated_at)
             VALUES (?, ?, ?, 0, \'pending\', ?, ?)'
        );
        $st->execute([$lotId, $token, json_encode($payload, JSON_UNESCAPED_UNICODE), $now, $now]);
        return $this->getProposal((int)$this->pdo->lastInsertId());
    }

    public function getProposal(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM proposals WHERE id = ?');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            return null;
        }
        $p['payload'] = json_decode($p['payload'] ?? '{}', true) ?: [];
        $p['approves_needed'] = max(0, self::APPROVE_THRESHOLD - (int)$p['approve_count']);
        return $p;
    }

    /** ある lot の保留中の提案一覧（新しい順）。 */
    public function listPendingProposals(int $lotId): array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM proposals WHERE lot_id = ? AND status = 'pending' ORDER BY id DESC"
        );
        $st->execute([$lotId]);
        $out = [];
        foreach ($st->fetchAll() as $p) {
            $p['payload'] = json_decode($p['payload'] ?? '{}', true) ?: [];
            $p['approves_needed'] = max(0, self::APPROVE_THRESHOLD - (int)$p['approve_count']);
            $out[] = $p;
        }
        return $out;
    }

    /**
     * 提案を承認（1アカウント1票）。しきい値に達したら本体へ反映して applied に。
     * @return array{ok:bool, reason?:string, applied?:bool, proposal?:array, lot?:array}
     */
    public function approveProposal(int $proposalId, string $token): array
    {
        $now = self::nowIso();
        $p = $this->getProposal($proposalId);
        if (!$p || $p['status'] !== 'pending') {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        try {
            $this->pdo->beginTransaction();
            // 1アカウント1票（UNIQUE 違反で重複を検知）
            $this->pdo->prepare(
                'INSERT INTO proposal_votes (proposal_id, client_token, created_at) VALUES (?, ?, ?)'
            )->execute([$proposalId, $token, $now]);
            $this->pdo->prepare(
                'UPDATE proposals SET approve_count = approve_count + 1, updated_at = ? WHERE id = ?'
            )->execute([$now, $proposalId]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->isUniqueViolation($e)) {
                return ['ok' => false, 'reason' => 'duplicate'];
            }
            throw $e;
        }

        $p = $this->getProposal($proposalId);
        $applied = false;
        if ((int)$p['approve_count'] >= self::APPROVE_THRESHOLD) {
            $this->applyProposal($p);
            $applied = true;
            $p = $this->getProposal($proposalId);
        }
        return ['ok' => true, 'applied' => $applied, 'proposal' => $p, 'lot' => $this->getLot((int)$p['lot_id'])];
    }

    /** 提案 payload を lot へ反映し、提案を applied に。 */
    private function applyProposal(array $p): void
    {
        $lot = $this->getLot((int)$p['lot_id']);
        if (!$lot) {
            return;
        }
        $pl = $p['payload'];
        // 反映を許可するフィールドのみマージ（安全のためホワイトリスト）
        $fields = ['name', 'lat', 'lng', 'address', 'hourly_rate', 'max_rate',
                   'fee_note', 'capacity', 'hours', 'category', 'rates', 'photo'];
        $merged = $lot;
        foreach ($fields as $f) {
            if (array_key_exists($f, $pl)) {
                $merged[$f] = $pl[$f];
            }
        }
        $merged['nickname'] = $lot['nickname'];
        $merged['source'] = 'user'; // 承認で確定した編集は user（赤ピン）
        $merged['photo'] = array_key_exists('photo', $pl) ? $pl['photo'] : null; // null は COALESCE で現状維持
        $this->updateLot((int)$p['lot_id'], $merged);
        $this->pdo->prepare("UPDATE proposals SET status = 'applied', updated_at = ? WHERE id = ?")
            ->execute([self::nowIso(), (int)$p['id']]);
    }

    // ---- reports (confirm / report) ----

    /**
     * confirm / report を記録。1トークン1票（UNIQUE 制約）。
     * @return array{ok:bool, reason?:string, lot?:array}
     */
    public function addReport(int $lotId, string $token, string $kind, ?string $comment, bool $wasStale = false): array
    {
        $lot = $this->getLot($lotId);
        if (!$lot) {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        $now = self::nowIso();
        try {
            $this->pdo->beginTransaction();
            $ins = $this->pdo->prepare("
                INSERT INTO reports (lot_id, client_token, kind, comment, was_stale, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            // 「要更新」だった駐車場を確認で復活させた場合に鮮度キーパーの実績として記録
            $ins->execute([$lotId, $token, $kind, $comment, ($kind === 'confirm' && $wasStale) ? 1 : 0, $now]);

            if ($kind === 'confirm') {
                $this->pdo->prepare(
                    'UPDATE lots SET confirm_count = confirm_count + 1, last_confirmed_at = ? WHERE id = ?'
                )->execute([$now, $lotId]);
            } else {
                $this->pdo->prepare(
                    'UPDATE lots SET report_count = report_count + 1 WHERE id = ?'
                )->execute([$lotId]);
                // 自動非表示: 報告が既定数以上たまり、かつ確認数を上回ったら非表示（管理者不要・巻き添え防止）
                $this->pdo->prepare(
                    "UPDATE lots SET hidden = 1, hidden_at = ?
                     WHERE id = ? AND hidden = 0
                       AND report_count >= ? AND report_count > confirm_count"
                )->execute([$now, $lotId, self::HIDE_MIN_REPORTS]);
                // 不適切通報は少数でも即非表示（ログイン必須で通報が信頼できるため）
                if ($comment === 'inappropriate') {
                    $ic = $this->pdo->prepare(
                        "SELECT COUNT(*) AS c FROM reports WHERE lot_id = ? AND kind = 'report' AND comment = 'inappropriate'"
                    );
                    $ic->execute([$lotId]);
                    if ((int)$ic->fetch()['c'] >= self::HIDE_INAPPROPRIATE) {
                        $this->pdo->prepare('UPDATE lots SET hidden = 1, hidden_at = ? WHERE id = ? AND hidden = 0')
                            ->execute([$now, $lotId]);
                    }
                }
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // UNIQUE 制約違反 = 重複投票
            if ($this->isUniqueViolation($e)) {
                return ['ok' => false, 'reason' => 'duplicate'];
            }
            throw $e;
        }
        return ['ok' => true, 'lot' => $this->getLot($lotId)];
    }

    public function countLots(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) AS c FROM lots')->fetch()['c'];
    }

    // ---- レート制限 ----

    /**
     * $bucket について直近 $windowSec 秒間の回数が $limit 未満なら記録して true。
     * 上限に達していれば false（＝拒否）。
     */
    public function rateAllow(string $bucket, int $limit, int $windowSec): bool
    {
        $now = time();
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', $now - $windowSec);
        $this->pdo->prepare('DELETE FROM rate_hits WHERE bucket = ? AND created_at < ?')
            ->execute([$bucket, $cutoff]);
        $c = $this->pdo->prepare('SELECT COUNT(*) AS c FROM rate_hits WHERE bucket = ?');
        $c->execute([$bucket]);
        if ((int)$c->fetch()['c'] >= $limit) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO rate_hits (bucket, created_at) VALUES (?, ?)')
            ->execute([$bucket, gmdate('Y-m-d\TH:i:s\Z', $now)]);
        return true;
    }

    /** 古いレート記録を削除（cleanup 用）。@return int 削除件数 */
    public function pruneRateHits(int $olderThanSec = 86400): int
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $olderThanSec);
        $st = $this->pdo->prepare('DELETE FROM rate_hits WHERE created_at < ?');
        $st->execute([$cutoff]);
        return $st->rowCount();
    }

    // ---- アカウント（ログイン） ----

    /**
     * アカウント作成（メール認証前）。verified=0, verify_token 付き。
     * @return array{ok:bool, reason?:string, account?:array}
     */
    public function createAccount(string $username, string $email, string $passwordHash, string $token, string $verifyToken): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            $this->pdo->prepare(
                'INSERT INTO accounts (username, email, password_hash, token, verified, verify_token, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)'
            )->execute([$username, $email, $passwordHash, $token, $verifyToken, $now]);
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return ['ok' => false, 'reason' => 'duplicate'];
            }
            throw $e;
        }
        return ['ok' => true, 'account' => $this->getAccountByToken($token)];
    }

    public function getAccountByUsername(string $username): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM accounts WHERE username = ?');
        $st->execute([$username]);
        return $st->fetch() ?: null;
    }

    public function getAccountByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM accounts WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    public function getAccountByToken(string $token): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM accounts WHERE token = ?');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    /** verify_token でメール認証を完了。@return bool 成功可否 */
    public function verifyAccount(string $verifyToken): bool
    {
        if ($verifyToken === '') {
            return false;
        }
        $st = $this->pdo->prepare('SELECT id FROM accounts WHERE verify_token = ?');
        $st->execute([$verifyToken]);
        if (!$st->fetch()) {
            return false;
        }
        $this->pdo->prepare('UPDATE accounts SET verified = 1, verify_token = NULL WHERE verify_token = ?')
            ->execute([$verifyToken]);
        return true;
    }

    /** 未認証アカウントの verify_token を再発行して返す（再送用）。 */
    public function refreshVerifyToken(int $id, string $verifyToken): void
    {
        $this->pdo->prepare('UPDATE accounts SET verify_token = ? WHERE id = ? AND verified = 0')
            ->execute([$verifyToken, $id]);
    }

    public function setResetToken(int $id, string $resetToken, string $expiresIso): void
    {
        $this->pdo->prepare('UPDATE accounts SET reset_token = ?, reset_expires = ? WHERE id = ?')
            ->execute([$resetToken, $expiresIso, $id]);
    }

    /** 有効なリセットトークンのアカウントを返す（期限切れは無効）。 */
    public function getAccountByResetToken(string $resetToken): ?array
    {
        if ($resetToken === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM accounts WHERE reset_token = ?');
        $st->execute([$resetToken]);
        $a = $st->fetch();
        if (!$a) {
            return null;
        }
        if (empty($a['reset_expires']) || $a['reset_expires'] < gmdate('Y-m-d\TH:i:s\Z')) {
            return null; // 期限切れ
        }
        return $a;
    }

    /** パスワードを更新し、リセットトークンを消す。 */
    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE accounts SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([$passwordHash, $id]);
    }

    // ---- 管理（admin） ----

    /** 全駐車場（非表示含む）を新しい順に返す。 */
    public function listAllLots(int $limit = 500): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lots ORDER BY updated_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** 駐車場を完全削除（報告も削除）。@return ?string 削除した写真ファイル名 */
    public function deleteLot(int $id): ?string
    {
        $lot = $this->getLot($id);
        if (!$lot) {
            return null;
        }
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM reports WHERE lot_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM lots WHERE id = ?')->execute([$id]);
        $this->pdo->commit();
        return $lot['photo'] ?: null;
    }

    /** 非表示/復活を切り替える。 */
    public function setHidden(int $id, bool $hidden): ?array
    {
        if (!$this->getLot($id)) {
            return null;
        }
        $this->pdo->prepare('UPDATE lots SET hidden = ?, hidden_at = ? WHERE id = ?')
            ->execute([$hidden ? 1 : 0, $hidden ? gmdate('Y-m-d\TH:i:s\Z') : null, $id]);
        return $this->getLot($id);
    }

    // ---- メンテナンス（画像・不要データの掃除） ----

    /** DB で参照されている写真ファイル名の一覧（孤立ファイル判定用）。 */
    public function referencedPhotos(): array
    {
        $rows = $this->pdo->query("SELECT photo FROM lots WHERE photo IS NOT NULL AND photo <> ''")->fetchAll();
        return array_map(fn($r) => $r['photo'], $rows);
    }

    /**
     * 非表示になってから $days 日以上たった駐車場を完全削除する。
     * （報告多数で非表示のまま放置されたスパム等を、猶予期間を置いて自動削除する）
     * @return array{lots:int, photos:string[]} 削除した駐車場数と、その写真ファイル名一覧
     */
    public function purgeHiddenOlderThan(int $days): array
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
        $sel = $this->pdo->prepare(
            "SELECT id, photo FROM lots WHERE hidden = 1 AND hidden_at IS NOT NULL AND hidden_at < ?"
        );
        $sel->execute([$cutoff]);
        $rows = $sel->fetchAll();
        if (!$rows) {
            return ['lots' => 0, 'photos' => []];
        }
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $this->pdo->beginTransaction();
        $this->pdo->prepare("DELETE FROM reports WHERE lot_id IN ($in)")->execute($ids);
        $this->pdo->prepare("DELETE FROM lots WHERE id IN ($in)")->execute($ids);
        $this->pdo->commit();

        $photos = array_values(array_filter(array_map(fn($r) => $r['photo'] ?? '', $rows)));
        return ['lots' => count($rows), 'photos' => $photos];
    }

    // ---- users（貢献度・匿名トークン単位） ----

    /** 匿名ユーザーを登録/更新。nickname は空でなければ更新。 */
    public function upsertUser(string $token, ?string $nickname = null): void
    {
        if ($token === '') {
            return;
        }
        $exists = $this->pdo->prepare('SELECT token, nickname FROM users WHERE token = ?');
        $exists->execute([$token]);
        $row = $exists->fetch();
        if ($row) {
            if ($nickname !== null && $nickname !== '' && $nickname !== $row['nickname']) {
                $this->pdo->prepare('UPDATE users SET nickname = ? WHERE token = ?')
                    ->execute([$nickname, $token]);
            }
        } else {
            $this->pdo->prepare('INSERT INTO users (token, nickname, created_at) VALUES (?, ?, ?)')
                ->execute([$token, $nickname ?: null, self::nowIso()]);
        }
    }

    /**
     * トークンの貢献指標を集計。
     * @return array{nickname:?string, posts:int, photoPosts:int, votes:int, confirmsReceived:int}
     */
    public function getUserStats(string $token): array
    {
        $one = function (string $sql, array $args) {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);
            return (int)($st->fetch()['c'] ?? 0);
        };

        // ニックネームはアカウント(username)から。無ければ旧users.nicknameにフォールバック
        $a = $this->pdo->prepare('SELECT username FROM accounts WHERE token = ?');
        $a->execute([$token]);
        $arow = $a->fetch();
        $nickname = $arow['username'] ?? null;
        if ($nickname === null) {
            $u = $this->pdo->prepare('SELECT nickname FROM users WHERE token = ?');
            $u->execute([$token]);
            $nickname = ($u->fetch()['nickname'] ?? null);
        }

        return [
            'nickname'         => $nickname,
            'posts'            => $one('SELECT COUNT(*) AS c FROM lots WHERE created_by_token = ?', [$token]),
            'photoPosts'       => $one("SELECT COUNT(*) AS c FROM lots WHERE created_by_token = ? AND photo IS NOT NULL AND photo <> ''", [$token]),
            'votes'            => $one('SELECT COUNT(*) AS c FROM reports WHERE client_token = ?', [$token]),
            'confirmsReceived' => $one('SELECT COALESCE(SUM(confirm_count),0) AS c FROM lots WHERE created_by_token = ?', [$token]),
            'refreshes'        => $one("SELECT COUNT(*) AS c FROM reports WHERE client_token = ? AND kind = 'confirm' AND was_stale = 1", [$token]),
        ];
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        $code = $e->getCode();
        // SQLite: 23000 / 'HY000' with 'UNIQUE', MySQL: 23000 (1062)
        $msg = strtolower($e->getMessage());
        return $code === '23000'
            || strpos($msg, 'unique') !== false
            || strpos($msg, 'duplicate') !== false;
    }
}

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
    // 「なくなった/閉鎖」報告がこの数に達したら非表示（確認済みでも閉店はあり得るので確認数は問わない）
    const HIDE_CLOSED = 5;

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
                hidden_at         VARCHAR(30),
                status            VARCHAR(16) NOT NULL DEFAULT 'published',
                reviewer_token    VARCHAR(80),
                approver_token    VARCHAR(80),
                reviewed_at       VARCHAR(30),
                approved_at       VARCHAR(30),
                points_revoked    INT NOT NULL DEFAULT 0
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

        // ポイント台帳（追記型・ブロックチェーン的）: 付与も剥奪も分配も履歴として積む。
        // points は正負どちらも取る（剥奪は打ち消しの負の行を積む）。
        // prev_hash/hash で各行を連結し、後からの改ざんを検知できるようにする。
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS point_events (
                id          $auto,
                user_token  VARCHAR(80) NOT NULL,
                lot_id      INT,
                role        VARCHAR(24) NOT NULL,
                points      INT NOT NULL,
                note        VARCHAR(200),
                prev_hash   VARCHAR(64),
                hash        VARCHAR(64),
                created_at  VARCHAR(30) NOT NULL
            )$engine
        ");

        // レビュー/承認の投票（1アカウント1回・自作自演防止の補助）
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS moderations (
                id           $auto,
                lot_id       INT NOT NULL,
                client_token VARCHAR(80) NOT NULL,
                kind         VARCHAR(10) NOT NULL,
                created_at   VARCHAR(30) NOT NULL
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
            "ALTER TABLE lots ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'published'",
            'ALTER TABLE lots ADD COLUMN reviewer_token VARCHAR(80)',
            'ALTER TABLE lots ADD COLUMN approver_token VARCHAR(80)',
            'ALTER TABLE lots ADD COLUMN reviewed_at VARCHAR(30)',
            'ALTER TABLE lots ADD COLUMN approved_at VARCHAR(30)',
            'ALTER TABLE lots ADD COLUMN points_revoked INT NOT NULL DEFAULT 0',
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
            'CREATE INDEX idx_lots_status ON lots (status)',
            'CREATE INDEX idx_reports_lot ON reports (lot_id)',
            'CREATE INDEX idx_proposals_lot ON proposals (lot_id, status)',
            'CREATE INDEX idx_pe_user ON point_events (user_token)',
            'CREATE INDEX idx_pe_lot ON point_events (lot_id)',
            'CREATE INDEX idx_mod_lot ON moderations (lot_id, kind)',
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
                              capacity, hours, category, photo, nickname, source, rates, created_by_token,
                              status, created_at, updated_at)
            VALUES (:kind, :name, :lat, :lng, :address, :hourly_rate, :max_rate, :fee_note,
                    :capacity, :hours, :category, :photo, :nickname, :source, :rates, :created_by_token,
                    :status, :created_at, :updated_at)
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
            ':status' => $d['status'] ?? 'published',
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
                WHERE hidden = 0 AND status = 'published'
                  AND lat BETWEEN :minLat AND :maxLat
                  AND lng BETWEEN :minLng AND :maxLng
            ");
            $stmt->execute([
                ':minLat' => $bbox['minLat'], ':maxLat' => $bbox['maxLat'],
                ':minLng' => $bbox['minLng'], ':maxLng' => $bbox['maxLng'],
            ]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query("SELECT * FROM lots WHERE hidden = 0 AND status = 'published'")->fetchAll();
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
     * 編集提案を承認（1アカウント1票）。承認者に +approvePts。しきい値で本体へ反映＆提案者に +proposerPts。
     * @return array{ok:bool, reason?:string, applied?:bool, oldPhoto?:?string, proposal?:array, lot?:array}
     */
    public function approveProposal(int $proposalId, string $token, int $approvePts = 0, int $proposerPts = 0): array
    {
        $now = self::nowIso();
        $p = $this->getProposal($proposalId);
        if (!$p || $p['status'] !== 'pending') {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        if (($p['proposer_token'] ?? '') === $token) {
            return ['ok' => false, 'reason' => 'self']; // 自分の編集提案は承認できない
        }
        $oldPhoto = null;
        try {
            $this->pdo->beginTransaction();
            // 1アカウント1票（UNIQUE 違反で重複を検知）
            $this->pdo->prepare(
                'INSERT INTO proposal_votes (proposal_id, client_token, created_at) VALUES (?, ?, ?)'
            )->execute([$proposalId, $token, $now]);
            $this->pdo->prepare(
                'UPDATE proposals SET approve_count = approve_count + 1, updated_at = ? WHERE id = ?'
            )->execute([$now, $proposalId]);
            $lotId = (int)$p['lot_id'];
            if ($approvePts > 0) {
                $this->appendPointEvent($token, $lotId, 'edit_approve', $approvePts, '編集を承認');
            }
            $fresh = $this->getProposal($proposalId);
            $applied = false;
            if ((int)$fresh['approve_count'] >= self::APPROVE_THRESHOLD) {
                $oldPhoto = $this->applyProposal($fresh, $proposerPts);
                $applied = true;
            }
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
        return ['ok' => true, 'applied' => $applied, 'oldPhoto' => $oldPhoto,
                'proposal' => $p, 'lot' => $this->getLot((int)$p['lot_id'])];
    }

    /** 提案 payload を lot へ反映し、提案を applied に。提案者へ +proposerPts。差し替え前の旧写真名を返す。 */
    private function applyProposal(array $p, int $proposerPts = 0): ?string
    {
        $lot = $this->getLot((int)$p['lot_id']);
        if (!$lot) {
            return null;
        }
        $pl = $p['payload'];
        $oldPhoto = (!empty($pl['photo']) && !empty($lot['photo']) && $pl['photo'] !== $lot['photo'])
            ? $lot['photo'] : null; // 新写真で差し替わる場合のみ旧写真を削除対象に
        $fields = ['name', 'lat', 'lng', 'address', 'hourly_rate', 'max_rate',
                   'fee_note', 'capacity', 'hours', 'category', 'rates', 'photo'];
        $merged = $lot;
        foreach ($fields as $f) {
            if (array_key_exists($f, $pl)) {
                $merged[$f] = $pl[$f];
            }
        }
        $merged['nickname'] = $lot['nickname'];
        $merged['source'] = 'user';
        $merged['photo'] = array_key_exists('photo', $pl) ? $pl['photo'] : null; // null は COALESCE で現状維持
        $this->updateLot((int)$p['lot_id'], $merged);
        $this->pdo->prepare("UPDATE proposals SET status = 'applied', updated_at = ? WHERE id = ?")
            ->execute([self::nowIso(), (int)$p['id']]);
        if ($proposerPts > 0 && !empty($p['proposer_token'])) {
            $this->appendPointEvent($p['proposer_token'], (int)$p['lot_id'], 'edit_applied', $proposerPts, '編集が承認され確定');
        }
        return $oldPhoto;
    }

    // ---- ポイント台帳（追記型・ハッシュ連結） ----

    private function lastLedgerHash(): ?string
    {
        $r = $this->pdo->query('SELECT hash FROM point_events ORDER BY id DESC LIMIT 1')->fetch();
        return $r['hash'] ?? null;
    }

    /** 台帳に1行追記（付与も剥奪も分配もすべてここを通す）。前行ハッシュと連結して改ざん検知可能に。 */
    public function appendPointEvent(string $token, ?int $lotId, string $role, int $points, ?string $note = null): void
    {
        $now = self::nowIso();
        $prev = $this->lastLedgerHash();
        $payload = ($prev ?? '') . '|' . $token . '|' . ($lotId ?? '') . '|' . $role . '|' . $points . '|' . $now;
        $hash = hash('sha256', $payload);
        $this->pdo->prepare(
            'INSERT INTO point_events (user_token, lot_id, role, points, note, prev_hash, hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$token, $lotId, $role, $points, $note, $prev, $hash, $now]);
    }

    /** そのユーザーが、その投稿で得た/失った純ポイント（剥奪額の算定に使う）。 */
    public function lotUserNet(int $lotId, string $token): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(points),0) AS s FROM point_events WHERE lot_id = ? AND user_token = ?');
        $st->execute([$lotId, $token]);
        return (int)$st->fetch()['s'];
    }

    /** 台帳を先頭から検証。改ざんがあれば false（管理用）。 */
    public function verifyLedger(): bool
    {
        $rows = $this->pdo->query('SELECT * FROM point_events ORDER BY id ASC')->fetchAll();
        $prev = null;
        foreach ($rows as $r) {
            $payload = ($prev ?? '') . '|' . $r['user_token'] . '|' . ($r['lot_id'] ?? '') . '|' . $r['role'] . '|' . $r['points'] . '|' . $r['created_at'];
            if (hash('sha256', $payload) !== $r['hash']) {
                return false;
            }
            $prev = $r['hash'];
        }
        return true;
    }

    // ---- 新規投稿の審査（レビュー → 承認 → 公開） ----

    /** レビュー待ちの投稿にレビューを付ける。ok=trueで承認待ちへ、falseで却下。 */
    public function submitReview(int $lotId, string $token, bool $ok, int $reviewPts): array
    {
        $lot = $this->getLot($lotId);
        if (!$lot || $lot['status'] !== 'pending_review') {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        if (($lot['created_by_token'] ?? '') === $token) {
            return ['ok' => false, 'reason' => 'self']; // 自分の投稿はレビュー不可
        }
        $now = self::nowIso();
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare('INSERT INTO moderations (lot_id, client_token, kind, created_at) VALUES (?,?,?,?)')
                ->execute([$lotId, $token, 'review', $now]);
            if ($ok) {
                $this->pdo->prepare("UPDATE lots SET status='pending_approval', reviewer_token=?, reviewed_at=? WHERE id=?")
                    ->execute([$token, $now, $lotId]);
                $this->appendPointEvent($token, $lotId, 'review', $reviewPts, 'レビュー');
            } else {
                $this->pdo->prepare("UPDATE lots SET status='rejected', reviewer_token=?, reviewed_at=? WHERE id=?")
                    ->execute([$token, $now, $lotId]);
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'lot' => $this->getLot($lotId)];
    }

    /** 新規投稿を承認して公開（1人でOK＝緩く）。ok=falseは却下。投稿者と承認者にポイント付与。 */
    public function approvePublish(int $lotId, string $token, bool $ok, int $posterPts, int $photoPts, int $approvePts): array
    {
        $lot = $this->getLot($lotId);
        if (!$lot || $lot['status'] !== 'pending_approval') {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        if (($lot['created_by_token'] ?? '') === $token) {
            return ['ok' => false, 'reason' => 'self']; // 自分の投稿は承認できない
        }
        $now = self::nowIso();
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare('INSERT INTO moderations (lot_id, client_token, kind, created_at) VALUES (?,?,?,?)')
                ->execute([$lotId, $token, 'approve', $now]);
            if ($ok) {
                $this->pdo->prepare("UPDATE lots SET status='published', approver_token=?, approved_at=?, updated_at=? WHERE id=?")
                    ->execute([$token, $now, $now, $lotId]);
                $poster = $lot['created_by_token'] ?? null;
                if ($poster) {
                    $this->appendPointEvent($poster, $lotId, 'post', $posterPts, '投稿が公開');
                    if (!empty($lot['photo'])) {
                        $this->appendPointEvent($poster, $lotId, 'photo', $photoPts, '写真つき投稿');
                    }
                }
                $this->appendPointEvent($token, $lotId, 'approve', $approvePts, '新規を承認');
                $result = 'published';
            } else {
                $this->pdo->prepare("UPDATE lots SET status='rejected' WHERE id=?")->execute([$lotId]);
                $result = 'rejected';
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'result' => $result, 'lot' => $this->getLot($lotId)];
    }

    /** 新規の公開待ち一覧（自分の投稿は除外）。 */
    public function listQueue(string $type, string $excludeToken, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $st = $this->pdo->prepare(
            "SELECT * FROM lots
             WHERE status='pending_approval' AND hidden=0
               AND (created_by_token IS NULL OR created_by_token <> ?)
             ORDER BY id ASC LIMIT $limit"
        );
        $st->execute([$excludeToken]);
        return $st->fetchAll();
    }

    /** 編集の承認待ち一覧（自分が提案したものは除外）。lotの現状とpayloadを返す。 */
    public function listEditQueue(string $excludeToken, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $st = $this->pdo->prepare(
            "SELECT * FROM proposals
             WHERE status='pending' AND (proposer_token IS NULL OR proposer_token <> ?)
             ORDER BY id ASC LIMIT $limit"
        );
        $st->execute([$excludeToken]);
        $out = [];
        foreach ($st->fetchAll() as $p) {
            $lot = $this->getLot((int)$p['lot_id']);
            if (!$lot || (int)$lot['hidden'] === 1) {
                continue;
            }
            $out[] = [
                'proposal' => [
                    'id' => (int)$p['id'],
                    'payload' => json_decode($p['payload'] ?? '{}', true) ?: [],
                    'approve_count' => (int)$p['approve_count'],
                    'approves_needed' => max(0, self::APPROVE_THRESHOLD - (int)$p['approve_count']),
                ],
                'lot' => $lot,
            ];
        }
        return $out;
    }

    /** 審査待ち件数（新規公開待ち / 編集承認待ち）。 */
    public function queueCounts(string $excludeToken): array
    {
        $r = $this->pdo->prepare("SELECT COUNT(*) AS c FROM lots WHERE status='pending_approval' AND hidden=0 AND (created_by_token IS NULL OR created_by_token <> ?)");
        $r->execute([$excludeToken]);
        $e = $this->pdo->prepare("SELECT COUNT(*) AS c FROM proposals WHERE status='pending' AND (proposer_token IS NULL OR proposer_token <> ?)");
        $e->execute([$excludeToken]);
        return ['new' => (int)$r->fetch()['c'], 'edit' => (int)$e->fetch()['c']];
    }

    /** 公開後に不適切指摘が集まったとき、関係者のポイントを剥奪し指摘者へ分配（1回のみ）。 */
    private function revokeInappropriate(int $lotId, array $lot): void
    {
        // その投稿でプラスのポイントを得た全員（投稿者・承認者・編集提案者・編集承認者）を対象に、
        // 得た分だけ剥奪する。
        $ts = $this->pdo->prepare(
            'SELECT user_token, COALESCE(SUM(points),0) AS net FROM point_events
             WHERE lot_id = ? GROUP BY user_token HAVING SUM(points) > 0'
        );
        $ts->execute([$lotId]);
        $total = 0;
        foreach ($ts->fetchAll() as $row) {
            $net = (int)$row['net'];
            if ($net > 0) {
                $this->appendPointEvent($row['user_token'], $lotId, 'revoke_inappropriate', -$net, '公開後に不適切と判断され剥奪');
                $total += $net;
            }
        }
        $rs = $this->pdo->prepare("SELECT DISTINCT client_token FROM reports WHERE lot_id=? AND kind='report' AND comment='inappropriate'");
        $rs->execute([$lotId]);
        $reporters = array_column($rs->fetchAll(), 'client_token');
        $n = count($reporters);
        if ($n > 0 && $total > 0) {
            $each = intdiv($total, $n);
            $rem  = $total - $each * $n; // 余りは先頭から1ずつ配って総量を保存
            foreach ($reporters as $i => $t) {
                $pts = $each + ($i < $rem ? 1 : 0);
                if ($pts > 0) {
                    $this->appendPointEvent($t, $lotId, 'report_reward', $pts, '不適切の指摘への分配');
                }
            }
        }
        $this->pdo->prepare('UPDATE lots SET points_revoked=1 WHERE id=?')->execute([$lotId]);
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
                // 「古い」は更新をうながす印なので自動非表示にはしない（消すのは「閉鎖」「不適切」だけ）。
                // 不適切通報は少数でも即非表示（ログイン必須で通報が信頼できるため）
                if ($comment === 'inappropriate') {
                    $ic = $this->pdo->prepare(
                        "SELECT COUNT(*) AS c FROM reports WHERE lot_id = ? AND kind = 'report' AND comment = 'inappropriate'"
                    );
                    $ic->execute([$lotId]);
                    if ((int)$ic->fetch()['c'] >= self::HIDE_INAPPROPRIATE) {
                        $this->pdo->prepare('UPDATE lots SET hidden = 1, hidden_at = ? WHERE id = ? AND hidden = 0')
                            ->execute([$now, $lotId]);
                        // 10人以上が不適切と指摘 → 投稿者・レビュー者・承認者のポイントを剥奪して指摘者へ分配（1回のみ）
                        if ((int)($lot['points_revoked'] ?? 0) === 0) {
                            $this->revokeInappropriate($lotId, $lot);
                        }
                    }
                }
                // 「なくなった/閉鎖」報告が既定数に達したら非表示（確認済みでも閉店はあり得るため確認数は問わない。ポイント剥奪はしない）
                if ($comment === 'closed') {
                    $cc = $this->pdo->prepare(
                        "SELECT COUNT(*) AS c FROM reports WHERE lot_id = ? AND kind = 'report' AND comment = 'closed'"
                    );
                    $cc->execute([$lotId]);
                    if ((int)$cc->fetch()['c'] >= self::HIDE_CLOSED) {
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

    /** 公開中の駐車場・店を名前/住所で検索。@return array<int,array> */
    public function searchLots(string $q, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        // ワイルドカード文字は普通の文字として扱う（ESCAPE句はMySQL/SQLiteで挙動が違うため使わない）
        $like = '%' . str_replace(['%', '_'], ['', ''], $q) . '%';
        // MySQL(エミュレーションOFF)では同名プレースホルダを複数使えないため別名にする
        $st = $this->pdo->prepare(
            "SELECT id, kind, name, address, lat, lng FROM lots
             WHERE hidden = 0 AND status = 'published'
               AND (name LIKE :q1 OR address LIKE :q2)
             ORDER BY id DESC LIMIT $limit"
        );
        $st->execute([':q1' => $like, ':q2' => $like]);
        return $st->fetchAll();
    }

    /**
     * その投稿の投票内訳（1アカウント1票）。件数と「最後に押された日時」を返す。
     * @return array 例: {confirm:29, confirm_at:'...', wrong:1, wrong_at:'...', closed:9, ..., inappropriate:4, ...}
     */
    public function reportBreakdown(int $lotId): array
    {
        $out = [
            'confirm' => 0, 'confirm_at' => null,
            'wrong' => 0, 'wrong_at' => null,
            'closed' => 0, 'closed_at' => null,
            'inappropriate' => 0, 'inappropriate_at' => null,
        ];
        $st = $this->pdo->prepare(
            'SELECT kind, comment, COUNT(*) AS c, MAX(created_at) AS last
             FROM reports WHERE lot_id = ? GROUP BY kind, comment'
        );
        $st->execute([$lotId]);
        foreach ($st->fetchAll() as $r) {
            $c = (int)$r['c'];
            $last = $r['last'];
            if ($r['kind'] === 'confirm') {
                $key = 'confirm';
            } elseif ($r['comment'] === 'inappropriate') {
                $key = 'inappropriate';
            } elseif ($r['comment'] === 'closed') {
                $key = 'closed';
            } else {
                $key = 'wrong'; // 違う/古い（理由なしの一般報告）
            }
            $out[$key] += $c;
            if ($last !== null && ($out[$key . '_at'] === null || $last > $out[$key . '_at'])) {
                $out[$key . '_at'] = $last;
            }
        }
        return $out;
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

        // ポイント台帳の合計（付与−剥奪）
        $lp = $this->pdo->prepare('SELECT COALESCE(SUM(points),0) AS c FROM point_events WHERE user_token = ?');
        $lp->execute([$token]);
        $ledgerPoints = (int)$lp->fetch()['c'];

        return [
            'nickname'         => $nickname,
            // 投稿系は「公開済み」だけをバッジ対象にする
            'posts'            => $one("SELECT COUNT(*) AS c FROM lots WHERE created_by_token = ? AND status = 'published'", [$token]),
            'photoPosts'       => $one("SELECT COUNT(*) AS c FROM lots WHERE created_by_token = ? AND status = 'published' AND photo IS NOT NULL AND photo <> ''", [$token]),
            'votes'            => $one('SELECT COUNT(*) AS c FROM reports WHERE client_token = ?', [$token]),
            'confirmsReceived' => $one("SELECT COALESCE(SUM(confirm_count),0) AS c FROM lots WHERE created_by_token = ? AND status = 'published'", [$token]),
            'refreshes'        => $one("SELECT COUNT(*) AS c FROM reports WHERE client_token = ? AND kind = 'confirm' AND was_stale = 1", [$token]),
            'reviews'          => $one("SELECT COUNT(*) AS c FROM moderations WHERE client_token = ? AND kind = 'review'", [$token]),
            'approvals'        => $one("SELECT COUNT(*) AS c FROM moderations WHERE client_token = ? AND kind = 'approve'", [$token]),
            'ledgerPoints'     => $ledgerPoints,
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

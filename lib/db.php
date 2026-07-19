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
                name              VARCHAR(120) NOT NULL,
                lat               DOUBLE NOT NULL,
                lng               DOUBLE NOT NULL,
                address           VARCHAR(200),
                hourly_rate       INT,
                max_rate          INT,
                fee_note          VARCHAR(500),
                capacity          INT,
                photo             VARCHAR(255),
                nickname          VARCHAR(40),
                source            VARCHAR(20),
                created_by_token  VARCHAR(80),
                created_at        VARCHAR(30) NOT NULL,
                updated_at        VARCHAR(30) NOT NULL,
                confirm_count     INT NOT NULL DEFAULT 0,
                last_confirmed_at VARCHAR(30),
                report_count      INT NOT NULL DEFAULT 0
            )$engine
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS reports (
                id           $auto,
                lot_id       INT NOT NULL,
                client_token VARCHAR(80) NOT NULL,
                kind         VARCHAR(10) NOT NULL,
                comment      VARCHAR(500),
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

        // 既存 DB 向け: created_by_token 列が無ければ追加（重複エラーは無視）
        try {
            $this->pdo->exec('ALTER TABLE lots ADD COLUMN created_by_token VARCHAR(80)');
        } catch (PDOException $e) {
            // 既に存在する場合は無視
        }

        // インデックス（存在しても致命的でないよう try）
        foreach ([
            'CREATE INDEX idx_lots_latlng ON lots (lat, lng)',
            'CREATE INDEX idx_reports_lot ON reports (lot_id)',
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
            INSERT INTO lots (name, lat, lng, address, hourly_rate, max_rate, fee_note,
                              capacity, photo, nickname, source, created_by_token, created_at, updated_at)
            VALUES (:name, :lat, :lng, :address, :hourly_rate, :max_rate, :fee_note,
                    :capacity, :photo, :nickname, :source, :created_by_token, :created_at, :updated_at)
        ");
        $stmt->execute([
            ':name' => $d['name'], ':lat' => $d['lat'], ':lng' => $d['lng'],
            ':address' => $d['address'], ':hourly_rate' => $d['hourly_rate'],
            ':max_rate' => $d['max_rate'], ':fee_note' => $d['fee_note'],
            ':capacity' => $d['capacity'], ':photo' => $d['photo'],
            ':nickname' => $d['nickname'], ':source' => $d['source'] ?? 'user',
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
                WHERE lat BETWEEN :minLat AND :maxLat
                  AND lng BETWEEN :minLng AND :maxLng
            ");
            $stmt->execute([
                ':minLat' => $bbox['minLat'], ':maxLat' => $bbox['maxLat'],
                ':minLng' => $bbox['minLng'], ':maxLng' => $bbox['maxLng'],
            ]);
            return $stmt->fetchAll();
        }
        return $this->pdo->query('SELECT * FROM lots')->fetchAll();
    }

    public function updateLot(int $id, array $d): array
    {
        $now = self::nowIso();
        // 写真は指定があれば差し替え、無ければ従来値を維持
        $stmt = $this->pdo->prepare("
            UPDATE lots SET
                name = :name, lat = :lat, lng = :lng, address = :address,
                hourly_rate = :hourly_rate, max_rate = :max_rate, fee_note = :fee_note,
                capacity = :capacity, nickname = :nickname,
                photo = COALESCE(:photo, photo),
                updated_at = :updated_at
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $d['name'], ':lat' => $d['lat'], ':lng' => $d['lng'],
            ':address' => $d['address'], ':hourly_rate' => $d['hourly_rate'],
            ':max_rate' => $d['max_rate'], ':fee_note' => $d['fee_note'],
            ':capacity' => $d['capacity'], ':nickname' => $d['nickname'],
            ':photo' => $d['photo'], ':updated_at' => $now, ':id' => $id,
        ]);
        return $this->getLot($id);
    }

    // ---- reports (confirm / report) ----

    /**
     * confirm / report を記録。1トークン1票（UNIQUE 制約）。
     * @return array{ok:bool, reason?:string, lot?:array}
     */
    public function addReport(int $lotId, string $token, string $kind, ?string $comment): array
    {
        $lot = $this->getLot($lotId);
        if (!$lot) {
            return ['ok' => false, 'reason' => 'notfound'];
        }
        $now = self::nowIso();
        try {
            $this->pdo->beginTransaction();
            $ins = $this->pdo->prepare("
                INSERT INTO reports (lot_id, client_token, kind, comment, created_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $ins->execute([$lotId, $token, $kind, $comment, $now]);

            if ($kind === 'confirm') {
                $this->pdo->prepare(
                    'UPDATE lots SET confirm_count = confirm_count + 1, last_confirmed_at = ? WHERE id = ?'
                )->execute([$now, $lotId]);
            } else {
                $this->pdo->prepare(
                    'UPDATE lots SET report_count = report_count + 1 WHERE id = ?'
                )->execute([$lotId]);
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

        $u = $this->pdo->prepare('SELECT nickname FROM users WHERE token = ?');
        $u->execute([$token]);
        $urow = $u->fetch();

        return [
            'nickname'         => $urow['nickname'] ?? null,
            'posts'            => $one('SELECT COUNT(*) AS c FROM lots WHERE created_by_token = ?', [$token]),
            'photoPosts'       => $one("SELECT COUNT(*) AS c FROM lots WHERE created_by_token = ? AND photo IS NOT NULL AND photo <> ''", [$token]),
            'votes'            => $one('SELECT COUNT(*) AS c FROM reports WHERE client_token = ?', [$token]),
            'confirmsReceived' => $one('SELECT COALESCE(SUM(confirm_count),0) AS c FROM lots WHERE created_by_token = ?', [$token]),
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

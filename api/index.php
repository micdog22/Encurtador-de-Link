<?php
declare(strict_types=1);
session_start();

/**
 * MicDog Shorty - Link Shortener API
 */

header_remove('X-Powered-By');

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect(string $to, int $status = 302): void {
    http_response_code($status);
    header('Location: ' . $to);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Redirecting to ' . $to;
    exit;
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);
    $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'shorty.sqlite';
    $needInit = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($needInit) init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("
        PRAGMA journal_mode = WAL;
        CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            url TEXT NOT NULL,
            title TEXT,
            clicks_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT
        );
        CREATE TABLE IF NOT EXISTS clicks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            link_id INTEGER NOT NULL,
            at TEXT NOT NULL DEFAULT (datetime('now')),
            ip TEXT,
            ua TEXT,
            ref TEXT,
            FOREIGN KEY(link_id) REFERENCES links(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_clicks_link ON clicks(link_id);
        CREATE INDEX IF NOT EXISTS idx_clicks_at ON clicks(at);
    ");
}

function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

function route_path(): string {
    $uri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $path = '/' . ltrim(substr($uri, strlen($base)), '/');
    $path = preg_replace('#/index\.php#', '', $path, 1);
    return $path === '' ? '/' : $path;
}

function query(string $k, ?string $default = null): ?string {
    return isset($_GET[$k]) && $_GET[$k] !== '' ? (string)$_GET[$k] : $default;
}

function parse_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);
    return is_array($data) ? $data : [];
}

function valid_url(string $url): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return in_array($scheme, ['http','https'], true);
}

function gen_code(int $len = 6): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = '';
    for ($i=0; $i<$len; $i++) $s .= $chars[random_int(0, strlen($chars)-1)];
    return $s;
}

// CSRF for non-GET
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function require_csrf(): void {
    if (method() === 'GET') return;
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$hdr || !hash_equals($_SESSION['csrf'], $hdr)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }
}

$path = route_path();
$method = method();

try {
    if ($path === '/' || $path === '') {
        json_response(['ok' => true, 'service' => 'MicDog Shorty API']);
    }

    if ($path === '/csrf' && $method === 'GET') {
        json_response(['token' => $_SESSION['csrf']]);
    }

    // Links collection
    if ($path === '/links') {
        $pdo = get_db();
        if ($method === 'GET') {
            $q = query('q');
            $sql = 'SELECT id, code, url, title, clicks_count, created_at, updated_at FROM links';
            $params = [];
            if ($q) {
                $sql .= ' WHERE code LIKE ? OR url LIKE ? OR COALESCE(title,"") LIKE ?';
                $like = '%' . $q . '%';
                $params = [$like, $like, $like];
            }
            $sql .= ' ORDER BY created_at DESC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_response(['items' => $stmt->fetchAll()]);
        }
        if ($method === 'POST') {
            require_csrf();
            $d = parse_json();
            $url = trim((string)($d['url'] ?? ''));
            $title = trim((string)($d['title'] ?? ''));
            $code = trim((string)($d['code'] ?? ''));

            if (!valid_url($url)) json_response(['errors' => ['url' => 'Invalid URL']], 422);
            if ($code !== '' && !preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $code)) {
                json_response(['errors' => ['code' => 'Alias must be 3-32 chars [a-zA-Z0-9_-]']], 422);
            }

            $pdo->beginTransaction();
            if ($code === '') {
                do {
                    $code = gen_code(6);
                    $exists = $pdo->prepare('SELECT 1 FROM links WHERE code = ?');
                    $exists->execute([$code]);
                } while ($exists->fetchColumn());
            } else {
                $exists = $pdo->prepare('SELECT 1 FROM links WHERE code = ?');
                $exists->execute([$code]);
                if ($exists->fetchColumn()) {
                    $pdo->rollBack();
                    json_response(['errors' => ['code' => 'Alias already in use']], 422);
                }
            }

            $stmt = $pdo->prepare('INSERT INTO links (code, url, title) VALUES (?, ?, ?)');
            $stmt->execute([$code, $url, $title !== '' ? $title : null]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();

            $row = $pdo->query('SELECT id, code, url, title, clicks_count, created_at, updated_at FROM links WHERE id = '.$id)->fetch();
            json_response(['item' => $row], 201);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    // Single link
    if (preg_match('#^/links/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        $pdo = get_db();

        if ($method === 'GET') {
            $row = $pdo->query('SELECT id, code, url, title, clicks_count, created_at, updated_at FROM links WHERE id = '.$id)->fetch();
            if (!$row) json_response(['error' => 'Not found'], 404);
            json_response(['item' => $row]);
        }

        if ($method === 'PUT') {
            require_csrf();
            $d = parse_json();
            $fields = [];
            $params = [':id' => $id];

            if (isset($d['url'])) {
                if (!valid_url((string)$d['url'])) json_response(['errors' => ['url' => 'Invalid URL']], 422);
                $fields[] = 'url = :url'; $params[':url'] = $d['url'];
            }
            if (isset($d['title'])) {
                $fields[] = 'title = :title'; $params[':title'] = trim((string)$d['title']) !== '' ? $d['title'] : null;
            }
            if (isset($d['code'])) {
                $code = (string)$d['code'];
                if (!preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $code)) {
                    json_response(['errors' => ['code' => 'Alias must be 3-32 chars [a-zA-Z0-9_-]']], 422);
                }
                $ck = $pdo->prepare('SELECT id FROM links WHERE code = ? AND id <> ?');
                $ck->execute([$code, $id]);
                if ($ck->fetch()) json_response(['errors' => ['code' => 'Alias already in use']], 422);
                $fields[] = 'code = :code'; $params[':code'] = $code;
            }

            if (!$fields) json_response(['error' => 'Nothing to update'], 400);
            $sql = 'UPDATE links SET '.implode(',', $fields).', updated_at = datetime("now") WHERE id = :id';
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $pdo->query('SELECT id, code, url, title, clicks_count, created_at, updated_at FROM links WHERE id = '.$id)->fetch();
            json_response(['item' => $row]);
        }

        if ($method === 'DELETE') {
            require_csrf();
            $st = $pdo->prepare('DELETE FROM links WHERE id = ?');
            $st->execute([$id]);
            json_response(['deleted' => $id]);
        }

        json_response(['error' => 'Method not allowed'], 405);
    }

    // Stats
    if ($path === '/stats' && $method === 'GET') {
        $pdo = get_db();
        $totLinks = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
        $totClicks = (int)$pdo->query('SELECT COUNT(*) FROM clicks')->fetchColumn();
        $top = $pdo->query('SELECT id, code, url, title, clicks_count FROM links ORDER BY clicks_count DESC, id ASC LIMIT 10')->fetchAll();

        // série diária últimos 30 dias
        $series = [];
        $stmt = $pdo->query("
            WITH days AS (
                SELECT date('now','-29 day') AS d
                UNION ALL
                SELECT date(d,'+1 day') FROM days WHERE d < date('now')
            )
            SELECT d AS day, COALESCE( (SELECT COUNT(*) FROM clicks WHERE substr(at,1,10)=d), 0 ) AS clicks
            FROM days;
        ");
        $series = $stmt->fetchAll();

        json_response(['totalLinks' => $totLinks, 'totalClicks' => $totClicks, 'top' => $top, 'series' => $series]);
    }

    if (preg_match('#^/stats/(\d+)$#', $path, $m) && $method === 'GET') {
        $id = (int)$m[1];
        $pdo = get_db();
        $row = $pdo->query('SELECT id, code, url, title, clicks_count FROM links WHERE id = '.$id)->fetch();
        if (!$row) json_response(['error' => 'Not found'], 404);

        $series = $pdo->query("
            SELECT substr(at,1,10) AS day, COUNT(*) AS clicks
            FROM clicks
            WHERE link_id = $id
            GROUP BY day
            ORDER BY day ASC
        ")->fetchAll();

        $recent = $pdo->prepare('SELECT at, ip, ua, ref FROM clicks WHERE link_id = ? ORDER BY at DESC LIMIT 50');
        $recent->execute([$id]);
        $recentRows = $recent->fetchAll();

        json_response(['link' => $row, 'series' => $series, 'recent' => $recentRows]);
    }

    // Exports
    if ($path === '/export/links' && $method === 'GET') {
        $pdo = get_db();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="links.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id','code','url','title','clicks_count','created_at','updated_at']);
        $stmt = $pdo->query('SELECT id, code, url, title, clicks_count, created_at, updated_at FROM links ORDER BY id ASC');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $r);
        fclose($out);
        exit;
    }

    if ($path === '/export/clicks' && $method === 'GET') {
        $pdo = get_db();
        $linkId = (int)(query('link_id') ?? 0);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clicks.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['at','link_id','ip','ua','ref']);
        if ($linkId > 0) {
            $st = $pdo->prepare('SELECT at, link_id, ip, ua, ref FROM clicks WHERE link_id = ? ORDER BY at ASC');
            $st->execute([$linkId]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $r);
        } else {
            $st = $pdo->query('SELECT at, link_id, ip, ua, ref FROM clicks ORDER BY at ASC');
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    // Redirect
    if (preg_match('#^/go/([a-zA-Z0-9_-]{3,64})$#', $path, $m) && $method === 'GET') {
        $code = $m[1];
        $pdo = get_db();
        $st = $pdo->prepare('SELECT id, url FROM links WHERE code = ?');
        $st->execute([$code]);
        $link = $st->fetch();
        if (!$link) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Link not found';
            exit;
        }

        // log click
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ref = $_SERVER['HTTP_REFERER'] ?? null;

        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO clicks (link_id, ip, ua, ref) VALUES (?, ?, ?, ?)');
        $ins->execute([$link['id'], $ip, $ua, $ref]);
        $upd = $pdo->prepare('UPDATE links SET clicks_count = clicks_count + 1 WHERE id = ?');
        $upd->execute([$link['id']]);
        $pdo->commit();

        redirect($link['url']);
    }

    json_response(['error' => 'Not found', 'path' => $path], 404);
} catch (Throwable $e) {
    json_response(['error' => 'Server error', 'message' => $e->getMessage()], 500);
}

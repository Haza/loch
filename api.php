<?php
/**
 * Loch - API JSON (PHP 8.1+, PDO SQLite)
 */
declare(strict_types=1);

require __DIR__ . '/inc.php';

/* ---------- Réponses ---------- */
function out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): never
{
    out(['error' => $msg], $code);
}
function input(): array
{
    static $in = null;
    if ($in === null) {
        $raw = file_get_contents('php://input') ?: '';
        $in = $raw !== '' ? (json_decode($raw, true) ?? []) : [];
        if (!is_array($in)) $in = [];
    }
    return $in;
}
function valid_date(?string $d): bool
{
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $j] = array_map('intval', explode('-', $d));
    return checkdate($m, $j, $y);
}
function range_params(): array
{
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    return [valid_date($from) ? $from : '0000-01-01', valid_date($to) ? $to : '9999-12-31'];
}
function projects(): array
{
    return q("SELECT p.*, COUNT(e.id) AS n, COALESCE(SUM(e.duration),0) AS d, MAX(e.date) AS last
              FROM projects p LEFT JOIN entries e ON e.project_id = p.id
              GROUP BY p.id ORDER BY p.archived, p.name COLLATE NOCASE");
}
function device_label(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browser = match (true) {
        str_contains($ua, 'Vivaldi') => 'Vivaldi',
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') => 'Opera',
        str_contains($ua, 'Firefox') => 'Firefox',
        str_contains($ua, 'Chrome') => 'Chrome',
        str_contains($ua, 'Safari') => 'Safari',
        default => 'Navigateur',
    };
    $os = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };
    return trim("$browser $os");
}

/* ---------- Session ---------- */
$sessDir = $config['session_dir'] ?? (__DIR__ . '/data/sessions');
if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
$lifetime = 86400 * (int)($config['session_days'] ?? 30);
if (is_dir($sessDir) && is_writable($sessDir)) {
    session_save_path($sessDir);
    ini_set('session.gc_maxlifetime', (string)$lifetime);
}
session_name('jdb_session');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function authed(): bool
{
    return !empty($_SESSION['auth']);
}
function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

/* ---------- Routage ---------- */
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($action === 'me') {
        out(['auth' => authed(), 'csrf' => authed() ? csrf() : null, 'configured' => !empty($config['password_hash'])]);
    }
    if ($action === 'login') {
        if ($method !== 'POST') fail('Méthode invalide', 405);
        if (empty($config['password_hash'])) fail('Aucun mot de passe configuré dans config.php (voir README).', 503);
        if (!password_verify((string)(input()['password'] ?? ''), $config['password_hash'])) {
            usleep(800000);
            fail('Mot de passe incorrect', 401);
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        unset($_SESSION['csrf']);
        out(['auth' => true, 'csrf' => csrf()]);
    }

    if (!authed()) fail('Non authentifié', 401);
    if ($method === 'POST' && !hash_equals(csrf(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        fail('Jeton CSRF invalide, recharge la page', 403);
    }
    session_write_close();

    switch ($action) {
        case 'logout':
            session_start();
            $_SESSION = [];
            session_destroy();
            out(['ok' => true]);

        case 'bootstrap':
            out([
                'projects' => projects(),
                'tags' => meta('tags', DEFAULT_TAGS),
                'reminders' => clean_reminders(meta('reminders', DEFAULT_REMINDERS)),
                'today' => date('Y-m-d'),
                'hours' => clean_hours(meta('hours', default_hours())),
            ]);

        /* ----- Entrées ----- */
        case 'entries':
            [$from, $to] = range_params();
            $w = ['e.date BETWEEN ? AND ?'];
            $p = [$from, $to];
            if (!empty($_GET['project'])) {
                if ($_GET['project'] === 'none') $w[] = 'e.project_id IS NULL';
                else { $w[] = 'e.project_id = ?'; $p[] = (int)$_GET['project']; }
            }
            if (!empty($_GET['tag'])) { $w[] = "(',' || e.tags || ',') LIKE ?"; $p[] = '%,' . $_GET['tag'] . ',%'; }
            if (!empty($_GET['search'])) {
                $s = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $_GET['search']) . '%';
                $w[] = "(e.title LIKE ? ESCAPE '\\' OR e.body LIKE ? ESCAPE '\\')";
                array_push($p, $s, $s);
            }
            $limit = max(1, min(5000, (int)($_GET['limit'] ?? 2000)));
            out(q("SELECT e.*, p.name AS pname, p.client AS pclient, p.color AS pcolor
                   FROM entries e LEFT JOIN projects p ON p.id = e.project_id
                   WHERE " . implode(' AND ', $w) . " ORDER BY e.date DESC, e.pos, e.id DESC LIMIT $limit", $p));

        case 'entry_save':
            $in = input();
            $title = trim((string)($in['title'] ?? ''));
            $date = (string)($in['date'] ?? '');
            if ($title === '') fail('Titre obligatoire');
            if (!valid_date($date)) fail('Date invalide');
            $pid = !empty($in['project_id']) ? (int)$in['project_id'] : null;
            if ($pid && !row('SELECT id FROM projects WHERE id = ?', [$pid])) fail('Projet inconnu');
            $dur = max(0, min(1440, (int)($in['duration'] ?? 0)));
            $tags = implode(',', array_unique(array_filter(array_map(fn($t) => trim(str_replace(',', '', (string)$t)), (array)($in['tags'] ?? [])))));
            $body = trim((string)($in['body'] ?? ''));
            if (!empty($in['id'])) {
                // Changement de jour : l'entrée repart en tête de sa nouvelle journée
                $st = db()->prepare('UPDATE entries SET pos = CASE WHEN date = ? THEN pos ELSE 0 END, date=?, project_id=?, duration=?, tags=?, title=?, body=?, updated_at=? WHERE id=?');
                $st->execute([$date, $date, $pid, $dur, $tags, $title, $body, now(), (int)$in['id']]);
                if (!$st->rowCount()) fail('Entrée introuvable', 404);
                out(['id' => (int)$in['id']]);
            }
            db()->prepare('INSERT INTO entries (date, project_id, duration, tags, title, body, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$date, $pid, $dur, $tags, $title, $body, now(), now()]);
            out(['id' => (int)db()->lastInsertId()]);

        case 'entry_delete':
            db()->prepare('DELETE FROM entries WHERE id = ?')->execute([(int)(input()['id'] ?? 0)]);
            out(['ok' => true]);

        case 'entries_reorder':
            $in = input();
            $date = (string)($in['date'] ?? '');
            if (!valid_date($date)) fail('Date invalide');
            $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
            $all = array_map('intval', array_column(q('SELECT id FROM entries WHERE date = ? ORDER BY pos, id DESC', [$date]), 'id'));
            if (!$ids || array_diff($ids, $all)) fail('Entrées inconnues pour ce jour, recharge la page');
            // La liste reçue peut être filtrée : les entrées masquées gardent leur place, les autres prennent l'ordre reçu
            $queue = $ids;
            $order = [];
            foreach ($all as $id) $order[] = in_array($id, $ids, true) ? array_shift($queue) : $id;
            $st = db()->prepare('UPDATE entries SET pos = ? WHERE id = ?');
            db()->beginTransaction();
            foreach ($order as $i => $id) $st->execute([$i + 1, $id]);
            db()->commit();
            out(['ok' => true]);

        case 'entry_duplicate':
            $e = row('SELECT * FROM entries WHERE id = ?', [(int)(input()['id'] ?? 0)]);
            if (!$e) fail('Entrée introuvable', 404);
            db()->prepare('INSERT INTO entries (date, project_id, duration, tags, title, body, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([date('Y-m-d'), $e['project_id'], $e['duration'], $e['tags'], $e['title'], $e['body'], now(), now()]);
            out(['id' => (int)db()->lastInsertId()]);

        /* ----- À faire : pense-bête, les tâches non faites restent visibles jusqu'à être cochées ----- */
        case 'todos':
            // Non faites, plus celles cochées aujourd'hui (pour pouvoir décocher ou les noter)
            $since = valid_date($_GET['today'] ?? '') ? $_GET['today'] : date('Y-m-d');
            out(q('SELECT id, title, due, done_at FROM todos WHERE done_at IS NULL OR done_at >= ? ORDER BY due, id', [$since]));

        case 'todo_save':
            $in = input();
            $title = trim((string)($in['title'] ?? ''));
            $due = (string)($in['due'] ?? '');
            if ($title === '') fail('Titre obligatoire');
            if (!valid_date($due)) fail('Échéance invalide');
            db()->prepare('INSERT INTO todos (title, due, created_at) VALUES (?,?,?)')->execute([mb_substr($title, 0, 500), $due, now()]);
            out(['id' => (int)db()->lastInsertId()]);

        case 'todo_update':
            $in = input();
            $id = (int)($in['id'] ?? 0);
            if (array_key_exists('done', $in)) {
                db()->prepare('UPDATE todos SET done_at = ? WHERE id = ?')->execute([$in['done'] ? now() : null, $id]);
            }
            if (isset($in['due'])) {
                if (!valid_date((string)$in['due'])) fail('Échéance invalide');
                db()->prepare('UPDATE todos SET due = ? WHERE id = ?')->execute([$in['due'], $id]);
            }
            out(['ok' => true]);

        case 'todo_delete':
            db()->prepare('DELETE FROM todos WHERE id = ?')->execute([(int)(input()['id'] ?? 0)]);
            out(['ok' => true]);

        /* ----- Projets et réglages ----- */
        case 'project_save':
            $in = input();
            $id = (int)($in['id'] ?? 0);
            $cur = $id ? row('SELECT * FROM projects WHERE id = ?', [$id]) : null;
            if ($id && !$cur) fail('Projet introuvable', 404);
            $name = trim((string)($in['name'] ?? $cur['name'] ?? ''));
            if ($name === '') fail('Nom obligatoire');
            $client = trim((string)($in['client'] ?? $cur['client'] ?? ''));
            $color = (string)($in['color'] ?? $cur['color'] ?? '#e0a95b');
            if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = '#e0a95b';
            $arch = isset($in['archived']) ? (int)(bool)$in['archived'] : (int)($cur['archived'] ?? 0);
            try {
                if ($id) {
                    db()->prepare('UPDATE projects SET name=?, client=?, color=?, archived=? WHERE id=?')->execute([$name, $client, $color, $arch, $id]);
                } else {
                    db()->prepare('INSERT INTO projects (name, client, color, archived, created_at) VALUES (?,?,?,?,?)')->execute([$name, $client, $color, $arch, now()]);
                    $id = (int)db()->lastInsertId();
                }
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) fail('Un projet porte déjà ce nom', 409);
                throw $e;
            }
            out(['id' => $id, 'projects' => projects()]);

        case 'project_delete':
            $id = (int)(input()['id'] ?? 0);
            db()->prepare('UPDATE entries SET project_id = NULL WHERE project_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            out(['projects' => projects()]);

        case 'settings_save':
            $in = input();
            if (isset($in['tags'])) {
                $tags = array_values(array_unique(array_filter(array_map(fn($t) => mb_strtolower(trim(str_replace(',', '', (string)$t))), (array)$in['tags']))));
                set_meta('tags', $tags);
            }
            if (isset($in['reminders'])) set_meta('reminders', clean_reminders($in['reminders']));
            if (isset($in['hours'])) set_meta('hours', clean_hours($in['hours']));
            out(['tags' => meta('tags', DEFAULT_TAGS), 'reminders' => clean_reminders(meta('reminders', DEFAULT_REMINDERS)), 'hours' => clean_hours(meta('hours', default_hours()))]);

        /* ----- Web Push ----- */
        case 'push_status':
            $missing = array_values(array_filter(['openssl', 'curl'], fn($e) => !extension_loaded($e)));
            $last = meta('cron_last_run');
            out([
                'available' => !$missing,
                'missing' => $missing,
                'publicKey' => $missing ? null : vapid_keys()['public'],
                'devices' => q('SELECT id, endpoint, label, created_at, last_ok, fail_count FROM push_subscriptions ORDER BY created_at'),
                'cron_ago' => $last ? time() - (int)$last : null,
                'log' => q('SELECT l.reminder_id, l.date, l.sent_at, l.result FROM reminder_log l ORDER BY l.sent_at DESC LIMIT 8'),
            ]);

        case 'push_subscribe':
            $in = input();
            $ep = (string)($in['endpoint'] ?? '');
            $p256 = (string)($in['keys']['p256dh'] ?? '');
            $auth = (string)($in['keys']['auth'] ?? '');
            if (!preg_match('#^https://#', $ep) || strlen($ep) > 1000) fail('Endpoint invalide');
            try {
                ec_public_from_raw(b64u_dec($p256));
                if (strlen(b64u_dec($auth)) !== 16) throw new InvalidArgumentException();
            } catch (Throwable $e) {
                fail('Clés d\'abonnement invalides');
            }
            db()->prepare('INSERT INTO push_subscriptions (endpoint, p256dh, auth, label, created_at) VALUES (?,?,?,?,?)
                           ON CONFLICT(endpoint) DO UPDATE SET p256dh = excluded.p256dh, auth = excluded.auth, fail_count = 0')
                ->execute([$ep, $p256, $auth, device_label(), now()]);
            out(['ok' => true]);

        case 'push_unsubscribe':
            $in = input();
            if (!empty($in['id'])) db()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([(int)$in['id']]);
            else db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([(string)($in['endpoint'] ?? '')]);
            out(['ok' => true]);

        case 'push_test':
            $st = today_status();
            $ep = (string)(input()['endpoint'] ?? '');
            $r = push_send(['title' => 'Loch', 'body' => "Ceci est un test.\n" . $st['line'], 'tag' => 'jdb-test', 'url' => './#journal'], $ep ?: null);
            if (!$r['devices']) fail('Aucun appareil abonné' . ($ep ? ' pour ce navigateur' : ''));
            out($r);

        /* ----- Stats ----- */
        case 'status':
            out(today_status());

        case 'stats':
            [$from, $to] = range_params();
            $P = [$from, $to];
            $k = row('SELECT COUNT(*) AS n, COALESCE(SUM(duration),0) AS d, COUNT(DISTINCT date) AS days FROM entries WHERE date BETWEEN ? AND ?', $P);
            $proj = q("SELECT e.project_id AS id, COALESCE(p.client,'') AS client, COALESCE(p.name,'Sans projet') AS l, COALESCE(p.color,'#7d88b3') AS c, COALESCE(SUM(e.duration),0) AS v, COUNT(*) AS n
                       FROM entries e LEFT JOIN projects p ON p.id = e.project_id
                       WHERE e.date BETWEEN ? AND ? GROUP BY e.project_id ORDER BY v DESC, n DESC", $P);
            $tags = [];
            foreach (q('SELECT tags, duration FROM entries WHERE date BETWEEN ? AND ?', $P) as $r) {
                $ts = array_filter(explode(',', (string)$r['tags']));
                foreach ($ts ?: ['(sans tag)'] as $t) $tags[$t] = ($tags[$t] ?? 0) + (int)$r['duration'];
            }
            arsort($tags);
            $dow = array_fill(0, 7, 0);
            foreach (q('SELECT date, SUM(duration) AS d FROM entries WHERE date BETWEEN ? AND ? GROUP BY date', $P) as $r) {
                $dow[(int)date('N', strtotime($r['date'])) - 1] += (int)$r['d'];
            }
            $heatStart = date('Y-m-d', strtotime('monday this week -77 days'));
            out([
                'kpis' => $k,
                'projects' => $proj,
                'tags' => array_map(fn($l, $v) => ['l' => (string)$l, 'v' => $v], array_keys($tags), $tags),
                'dow' => $dow,
                'heat_start' => $heatStart,
                'heat' => q('SELECT date, SUM(duration) AS d, COUNT(*) AS n FROM entries WHERE date >= ? GROUP BY date', [$heatStart]),
            ]);

        /* ----- Données ----- */
        case 'export_sqlite':
            $tmp = tempnam(sys_get_temp_dir(), 'jdb');
            @unlink($tmp);
            try {
                db()->exec('VACUUM INTO ' . db()->quote($tmp));
            } catch (Throwable $e) {
                db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
                copy($config['db_path'], $tmp);
            }
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="journal-de-bord-' . date('Y-m-d') . '.sqlite"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store');
            readfile($tmp);
            @unlink($tmp);
            exit;

        case 'export_csv':
            $rows = q("SELECT e.date, COALESCE(p.name,'') AS projet, COALESCE(p.client,'') AS client, e.duration, e.tags, e.title, e.body
                       FROM entries e LEFT JOIN projects p ON p.id = e.project_id ORDER BY e.date, e.pos DESC, e.id");
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="journal-de-bord-' . date('Y-m-d') . '.csv"');
            header('Cache-Control: no-store');
            $fh = fopen('php://output', 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['Date', 'Projet', 'Client', 'Durée (min)', 'Durée (h)', 'Tags', 'Titre', 'Détails'], ';', '"', '');
            foreach ($rows as $r) {
                fputcsv($fh, [$r['date'], $r['projet'], $r['client'], $r['duration'], str_replace('.', ',', (string)round($r['duration'] / 60, 2)), $r['tags'], $r['title'], $r['body']], ';', '"', '');
            }
            fclose($fh);
            exit;

        case 'import_sqlite':
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Fichier manquant ou trop gros');
            if (file_get_contents($f['tmp_name'], false, null, 0, 16) !== "SQLite format 3\0") fail('Ce n\'est pas une base SQLite');
            $test = new PDO('sqlite:' . $f['tmp_name'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = $test->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('entries', $tables, true) || !in_array('projects', $tables, true)) fail('Base non reconnue (tables entries/projects absentes)');
            $count = (int)$test->query('SELECT COUNT(*) FROM entries')->fetchColumn();
            $test = null;

            // On garde les clés VAPID, les abonnements et l'état du cron de ce serveur
            $keep = ['vapid' => meta('vapid'), 'cron_last_run' => meta('cron_last_run')];
            $subs = q('SELECT endpoint, p256dh, auth, label, created_at, last_ok FROM push_subscriptions');

            $bk = backup('avant-import');
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            db_close();
            foreach (['-wal', '-shm'] as $s) @unlink($config['db_path'] . $s);
            if (!copy($f['tmp_name'], $config['db_path'])) fail('Écriture de la base impossible', 500);

            foreach ($keep as $k => $v) if ($v !== null) set_meta($k, $v);
            $ins = db()->prepare('INSERT OR REPLACE INTO push_subscriptions (endpoint, p256dh, auth, label, created_at, last_ok) VALUES (?,?,?,?,?,?)');
            foreach ($subs as $s) $ins->execute(array_values($s));
            out(['ok' => true, 'entries' => $count, 'backup' => $bk]);

        case 'reset':
            $bk = backup('avant-reset');
            db()->exec('DELETE FROM entries; DELETE FROM projects; DELETE FROM reminder_log; DELETE FROM todos;');
            db()->exec("DELETE FROM sqlite_sequence WHERE name IN ('entries','projects','todos')");
            migrate(db());
            out(['ok' => true, 'backup' => $bk]);

        case 'sql':
            $sql = trim((string)(input()['sql'] ?? ''));
            if (!preg_match('/^(select|with)\b/i', $sql)) fail('Seules les requêtes SELECT / WITH sont autorisées');
            db()->exec('PRAGMA query_only = ON');
            try {
                $st = db()->query($sql);
                $rows = [];
                while (count($rows) < 500 && ($r = $st->fetch(PDO::FETCH_NUM)) !== false) $rows[] = $r;
                $cols = [];
                for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = $st->getColumnMeta($i)['name'] ?? "col$i";
            } finally {
                db()->exec('PRAGMA query_only = OFF');
            }
            out(['columns' => $cols, 'rows' => $rows]);

        case 'info':
            clearstatcache();
            out([
                'entries' => (int)row('SELECT COUNT(*) AS n FROM entries')['n'],
                'projects' => (int)row('SELECT COUNT(*) AS n FROM projects')['n'],
                'size' => @filesize($config['db_path']) ?: 0,
                'sqlite' => db()->query('SELECT sqlite_version()')->fetchColumn(),
                'php' => PHP_VERSION,
            ]);

        default:
            fail('Action inconnue', 404);
    }
} catch (Throwable $e) {
    error_log('[journal] ' . $e->getMessage());
    fail('Erreur serveur : ' . $e->getMessage(), 500);
}

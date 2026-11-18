<?php
/**
 * Journal de bord - socle commun (API + cron) : config, base SQLite, réglages, Web Push.
 */
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Europe/Paris');

const DEFAULT_TAGS = ['bug', 'évol', 'devops', 'réunion', 'support', 'doc', 'veille'];
const DEFAULT_REMINDERS = [
    'skipMinutes' => 90,
    'items' => [
        ['id' => 'r-midi', 'time' => '12:15', 'days' => [1, 2, 3, 4, 5], 'message' => 'Qu\'as-tu fait ce matin ?', 'enabled' => true],
        ['id' => 'r-soir', 'time' => '17:45', 'days' => [1, 2, 3, 4, 5], 'message' => 'Note ta journée avant de partir.', 'enabled' => true],
    ],
];

/* ---------- Base ---------- */
function db(): PDO
{
    global $config, $pdo;
    if (isset($pdo) && $pdo instanceof PDO) return $pdo;
    $path = $config['db_path'];
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException("Impossible de créer le dossier $dir");
    if (!is_writable($dir)) throw new RuntimeException("Le dossier $dir n'est pas accessible en écriture");
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}
function db_close(): void
{
    $GLOBALS['pdo'] = null;
}
function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            client TEXT DEFAULT '',
            color TEXT DEFAULT '#e0a95b',
            archived INTEGER DEFAULT 0,
            created_at TEXT
        );
        CREATE TABLE IF NOT EXISTS entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL,
            project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
            duration INTEGER DEFAULT 0,
            tags TEXT DEFAULT '',
            title TEXT NOT NULL,
            body TEXT DEFAULT '',
            pos INTEGER DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_entries_date ON entries(date);
        CREATE INDEX IF NOT EXISTS idx_entries_project ON entries(project_id);
        CREATE INDEX IF NOT EXISTS idx_entries_created ON entries(created_at);
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            label TEXT DEFAULT '',
            created_at TEXT,
            last_ok TEXT,
            fail_count INTEGER DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS reminder_log (
            reminder_id TEXT NOT NULL,
            date TEXT NOT NULL,
            sent_at TEXT,
            result TEXT,
            PRIMARY KEY (reminder_id, date)
        );
    ");
    // v4 : ordre manuel des entrées au sein d'une journée (0 = nouvelle, en tête)
    if (!in_array('pos', array_column($pdo->query('PRAGMA table_info(entries)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) {
        $pdo->exec('ALTER TABLE entries ADD COLUMN pos INTEGER DEFAULT 0');
    }
    $ins = $pdo->prepare('INSERT OR IGNORE INTO meta (key, value) VALUES (?, ?)');
    $ins->execute(['tags', json_encode(DEFAULT_TAGS, JSON_UNESCAPED_UNICODE)]);
    $ins->execute(['reminders', json_encode(DEFAULT_REMINDERS, JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare("INSERT INTO meta (key, value) VALUES ('schema', '4') ON CONFLICT(key) DO UPDATE SET value = '4'")->execute();
    if (!$pdo->query('SELECT 1 FROM projects LIMIT 1')->fetchColumn()) {
        $pdo->prepare('INSERT INTO projects (name, client, color, created_at) VALUES (?,?,?,?)')
            ->execute(['Interne', '', '#7c8db5', now()]);
    }
}
function now(): string
{
    return date('Y-m-d H:i:s');
}
function q(string $sql, array $p = []): array
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}
function row(string $sql, array $p = []): ?array
{
    return q($sql, $p)[0] ?? null;
}
function meta(string $key, mixed $default = null): mixed
{
    $st = db()->prepare('SELECT value FROM meta WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v === false) return $default;
    return json_decode((string)$v, true) ?? $default;
}
function set_meta(string $key, mixed $value): void
{
    db()->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
function backup(string $reason): ?string
{
    global $config;
    $dir = $config['backup_dir'] ?? (dirname($config['db_path']) . '/backups');
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!is_file($config['db_path'])) return null;
    $file = $dir . '/journal-' . date('Ymd-His') . "-$reason.sqlite";
    try {
        db()->exec('VACUUM INTO ' . db()->quote($file));
    } catch (Throwable $e) {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        if (!@copy($config['db_path'], $file)) return null;
    }
    return basename($file);
}

/* ---------- Réglages ---------- */
function clean_reminders(mixed $r): array
{
    $items = [];
    foreach ((array)($r['items'] ?? []) as $it) {
        $time = (string)($it['time'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) continue;
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($it['days'] ?? [])), fn($d) => $d >= 1 && $d <= 7)));
        sort($days);
        $items[] = [
            'id' => preg_replace('/[^a-z0-9-]/i', '', (string)($it['id'] ?? '')) ?: 'r-' . bin2hex(random_bytes(4)),
            'time' => $time,
            'days' => $days,
            'message' => mb_substr(trim((string)($it['message'] ?? '')), 0, 200),
            'enabled' => !empty($it['enabled']),
        ];
    }
    usort($items, fn($a, $b) => strcmp($a['time'], $b['time']));
    return ['skipMinutes' => max(0, min(1440, (int)($r['skipMinutes'] ?? 90))), 'items' => $items];
}
function fmt_dur(int $m): string
{
    if ($m <= 0) return '0h';
    $h = intdiv($m, 60);
    $r = $m % 60;
    return $h ? ($r ? sprintf('%dh%02d', $h, $r) : "{$h}h") : "{$r}min";
}
function today_status(): array
{
    $last = row('SELECT MAX(created_at) AS c FROM entries')['c'] ?? null;
    $t = row('SELECT COUNT(*) AS n, COALESCE(SUM(duration),0) AS d FROM entries WHERE date = ?', [date('Y-m-d')]);
    $n = (int)$t['n'];
    $d = (int)$t['d'];
    return [
        'last_entry_ago' => $last ? max(0, time() - strtotime($last)) : null,
        'today_count' => $n,
        'today_minutes' => $d,
        'line' => $n ? "Aujourd'hui : $n entrée" . ($n > 1 ? 's' : '') . ($d ? ', ' . fmt_dur($d) : '') . '.' : "Rien de saisi aujourd'hui.",
    ];
}

/* ==========================================================================
 * Web Push natif (RFC 8030 + VAPID RFC 8292 + chiffrement aes128gcm RFC 8291)
 * Uniquement ext-openssl et ext-curl, aucune dépendance Composer.
 * ========================================================================== */

function b64u_enc(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64u_dec(string $s): string
{
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($r === false) throw new InvalidArgumentException('base64url invalide');
    return $r;
}

/** Point public brut (65 octets, 0x04||X||Y) d'une clé EC P-256 OpenSSL. */
function ec_raw_public(OpenSSLAsymmetricKey $key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
/** Clé publique OpenSSL à partir d'un point brut P-256 (via un SubjectPublicKeyInfo DER). */
function ec_public_from_raw(string $raw): OpenSSLAsymmetricKey
{
    if (strlen($raw) !== 65 || $raw[0] !== "\x04") throw new InvalidArgumentException('Clé publique P-256 invalide');
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $k = openssl_pkey_get_public($pem);
    if (!$k) throw new InvalidArgumentException('Clé publique refusée par OpenSSL');
    return $k;
}
function ec_new_key(): OpenSSLAsymmetricKey
{
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) throw new RuntimeException('Génération de clé EC impossible : ' . openssl_error_string());
    return $k;
}
/** Signature DER ECDSA -> format brut R||S (64 octets) exigé par JWT ES256. */
function der_to_raw_sig(string $der): string
{
    $o = 2;
    if ((ord($der[1]) & 0x80) !== 0) $o += ord($der[1]) & 0x7f;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$o]) !== 0x02) throw new RuntimeException('Signature DER inattendue');
        $len = ord($der[$o + 1]);
        $int = ltrim(substr($der, $o + 2, $len), "\0");
        $parts[] = str_pad($int, 32, "\0", STR_PAD_LEFT);
        $o += 2 + $len;
    }
    return $parts[0] . $parts[1];
}

/**
 * Clés VAPID du serveur : générées une seule fois puis stockées en base.
 * Elles identifient ce serveur auprès des services de push (Google, Mozilla, Apple).
 */
function vapid_keys(): array
{
    $k = meta('vapid');
    if (is_array($k) && !empty($k['public']) && !empty($k['private_pem'])) return $k;
    $key = ec_new_key();
    openssl_pkey_export($key, $pem);
    $k = ['public' => b64u_enc(ec_raw_public($key)), 'private_pem' => $pem, 'created_at' => now()];
    set_meta('vapid', $k);
    return $k;
}
function vapid_header(string $endpoint): string
{
    global $config;
    $k = vapid_keys();
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $sub = $config['vapid_subject'] ?? '';
    if (!preg_match('/^(mailto:|https:)/', $sub)) $sub = 'mailto:admin@example.com';
    $data = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
        . '.' . b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($data, $sig, $k['private_pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signature VAPID impossible');
    return 'vapid t=' . $data . '.' . b64u_enc(der_to_raw_sig($sig)) . ', k=' . $k['public'];
}

/** Chiffre un message pour un abonnement (RFC 8291, aes128gcm, un seul enregistrement). */
function push_encrypt(string $payload, string $p256dhB64, string $authB64): string
{
    $uaPublic = b64u_dec($p256dhB64);
    $authSecret = b64u_dec($authB64);
    if (strlen($authSecret) !== 16) throw new InvalidArgumentException('Secret auth invalide');
    if (strlen($payload) > 3800) throw new InvalidArgumentException('Message trop long');

    $as = ec_new_key();
    $asPublic = ec_raw_public($as);
    $ecdh = openssl_pkey_derive(ec_public_from_raw($uaPublic), $as, 32);
    if ($ecdh === false) throw new RuntimeException('ECDH impossible : ' . openssl_error_string());

    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) throw new RuntimeException('Chiffrement impossible');
    return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
}

/**
 * Envoie une notification à tous les appareils abonnés (ou à un seul endpoint).
 * Supprime automatiquement les abonnements expirés (404 / 410).
 */
function push_send(array $payload, ?string $onlyEndpoint = null): array
{
    $subs = $onlyEndpoint
        ? q('SELECT * FROM push_subscriptions WHERE endpoint = ?', [$onlyEndpoint])
        : q('SELECT * FROM push_subscriptions');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = ['devices' => count($subs), 'ok' => 0, 'removed' => 0, 'errors' => []];
    if (!$subs) return $res;

    $mh = curl_multi_init();
    $handles = [];
    foreach ($subs as $s) {
        try {
            $body = push_encrypt($json, $s['p256dh'], $s['auth']);
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . vapid_header($s['endpoint']),
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'TTL: 3600',
                    'Urgency: high',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = [$ch, $s];
        } catch (Throwable $e) {
            $res['errors'][] = $e->getMessage();
        }
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $st === CURLM_OK);

    foreach ($handles as [$ch, $s]) {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        $resp = (string)curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            $res['ok']++;
            db()->prepare('UPDATE push_subscriptions SET last_ok = ?, fail_count = 0 WHERE id = ?')->execute([now(), $s['id']]);
        } elseif ($code === 404 || $code === 410) {
            $res['removed']++;
            db()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$s['id']]);
        } else {
            $res['errors'][] = ($s['label'] ?: 'appareil') . " : HTTP $code " . ($err ?: mb_substr(trim($resp), 0, 150));
            db()->prepare('UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = ?')->execute([$s['id']]);
            db()->exec('DELETE FROM push_subscriptions WHERE fail_count >= 20');
        }
    }
    curl_multi_close($mh);
    return $res;
}

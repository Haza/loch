<?php
/**
 * Loch - envoi des rappels par Web Push.
 * À lancer chaque minute :  * * * * * php /chemin/journal/cron.php
 *
 * Options : --test  envoie immédiatement une notification de test à tous les appareils
 *           -v      affiche ce qui se passe
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/inc.php';

const LATE_SECONDS = 600; // un rappel manqué (cron en retard, serveur redémarré) part encore pendant 10 min

$verbose = in_array('-v', $argv, true);
$log = function (string $m) use ($verbose) { if ($verbose) echo '[' . date('H:i:s') . "] $m\n"; };

try {
    set_meta('cron_last_run', time());

    if (in_array('--test', $argv, true)) {
        $st = today_status();
        $r = push_send(['title' => 'Loch', 'body' => "Test depuis le cron.\n" . $st['line'], 'tag' => 'jdb-test']);
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
        exit($r['errors'] ? 1 : 0);
    }

    $rem = clean_reminders(meta('reminders', DEFAULT_REMINDERS));
    $now = time();
    $today = date('Y-m-d');
    $dow = (int)date('N');
    $due = [];

    foreach ($rem['items'] as $it) {
        if (!$it['enabled'] || !in_array($dow, $it['days'], true)) continue;
        $t = strtotime("$today {$it['time']}:00");
        if ($now < $t || $now - $t >= LATE_SECONDS) continue;
        // Verrou : un seul envoi par rappel et par jour, même si deux crons se chevauchent
        $st = db()->prepare('INSERT OR IGNORE INTO reminder_log (reminder_id, date, sent_at) VALUES (?, ?, ?)');
        $st->execute([$it['id'], $today, now()]);
        if ($st->rowCount()) $due[] = $it;
    }

    if (!$due) {
        $log('Aucun rappel à envoyer');
        exit(0);
    }

    $status = today_status();
    $skip = $rem['skipMinutes'];
    if ($skip > 0 && $status['last_entry_ago'] !== null && $status['last_entry_ago'] < $skip * 60) {
        $msg = 'ignoré : entrée saisie il y a ' . round($status['last_entry_ago'] / 60) . ' min';
        foreach ($due as $it) db()->prepare('UPDATE reminder_log SET result = ? WHERE reminder_id = ? AND date = ?')->execute([$msg, $it['id'], $today]);
        $log($msg);
        exit(0);
    }

    $it = end($due);
    $res = push_send([
        'title' => 'Loch',
        'body' => trim(($it['message'] ?: 'Pense à remplir ton journal.') . "\n" . $status['line']),
        'tag' => 'jdb-reminder',
        'url' => './#journal',
    ]);
    $summary = "envoyé à {$res['ok']}/{$res['devices']} appareil(s)"
        . ($res['removed'] ? ", {$res['removed']} abonnement(s) expiré(s) supprimé(s)" : '')
        . ($res['errors'] ? ' ; erreurs : ' . implode(' | ', $res['errors']) : '');
    foreach ($due as $i) db()->prepare('UPDATE reminder_log SET result = ? WHERE reminder_id = ? AND date = ?')->execute([$summary, $i['id'], $today]);
    $log("{$it['time']} : $summary");

    db()->prepare('DELETE FROM reminder_log WHERE date < ?')->execute([date('Y-m-d', strtotime('-30 days'))]);
    exit($res['errors'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[journal cron] ' . $e->getMessage() . "\n");
    exit(1);
}

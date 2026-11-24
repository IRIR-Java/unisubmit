<?php
/*
 * Background marker (runs in the `worker` container): keeps the system self-sufficient.
 * Every few seconds it marks any submission still queued (for example uploaded while the AI was offline),
 * so nobody has to press a button. Results are released or flagged exactly as on upload.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

const IDLE_SECONDS = 10;   // pause between checks when there is nothing to do
const MAX_ATTEMPTS = 5;    // after this many failures a submission is flagged for the lecturer

function say($msg) { fwrite(STDOUT, date('H:i:s') . " $msg\n"); }

say('UniSubmit worker started; marker at ' . $ollama_host . ' (' . MODEL . ')');
while (true) {
    $row = $pdo->query("SELECT * FROM submissions WHERE status = 'Pending' ORDER BY attempts ASC, submitted_at ASC LIMIT 1")->fetch();
    if (!$row || !ollama_online($ollama_host)) { sleep(IDLE_SECONDS); continue; }

    say("marking #{$row['id']} {$row['original_name']}");
    $g = grade_stored($pdo, $row, $ollama_host);
    if ($g['ok']) {
        say("  -> {$g['status']} {$g['score']}/100" . ($g['flags'] ? ' [' . implode('; ', $g['flags']) . ']' : ''));
    } else {
        say("  -> not marked: {$g['reason']}");
        if (($row['attempts'] + 1) >= MAX_ATTEMPTS || empty($g['retry'])) {
            $pdo->prepare("UPDATE submissions SET status = 'Flagged', flags = ? WHERE id = ? AND status = 'Pending'")
                ->execute([json_encode(['Could not be marked automatically: mark by hand']), $row['id']]);
            say('  -> flagged for the lecturer');
        }
        sleep(IDLE_SECONDS);
    }
}

<?php
/*
 * UniSubmit shared code: database, schema, text extraction, AI marking, review flags, page chrome.
 * Used by the web pages (index.php, lecturer.php, view.php, download.php) and by worker.php.
 *
 * Marking model: the system marks every submission itself and releases the result to the student at once.
 * Results that look unusual are flagged and held for the lecturer, who supervises rather than marks.
 */
if (PHP_SAPI !== 'cli') session_start();

$db_host = getenv('DB_HOST') ?: 'db';
$db_name = getenv('DB_NAME') ?: 'unisubmit_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: 'rootpassword';
$ollama_host = getenv('OLLAMA_HOST') ?: 'http://172.30.208.1:11434';
// The Instruct build shares the GPU with other local tools (OLLAMA_MAX_LOADED_MODELS=1), so it stays loaded;
// it also follows the JSON schema better than the Thinking build behind the plain `qwen3:4b` tag.
define('MODEL', getenv('OLLAMA_MODEL') ?: 'qwen3:4b-instruct-2507-q4_K_M');
const COURSE = 'COSC 428';
// Generic enough for written reports and software projects alike; the lecturer can change it.
const DEFAULT_RUBRIC = "40% Depth and accuracy: understanding of the topic, analysis and evidence\n30% Application: practical examples, case studies or working implementation\n30% Clarity and structure: organisation, writing and presentation";
// ~8,000 characters is about 2,200 tokens: with the instructions and up to 800 output tokens that fits a
// 4,096-token context, so the shared model is never reloaded for a different context size.
const CONTENT_CHARS = 8000;
const BRIEF_CHARS = 1800;
const REG_RE = '[A-Z]{1,4}\d?(?:\/[A-Z]{2,4})?\/\d{3,6}\/\d{2}';
const ALLOWED_EXT = ['pdf', 'docx', 'txt', 'md', 'php', 'py', 'c', 'java', 'sql', 'html'];
// Course materials the lecturer shares; slides, spreadsheets and archives are download-only.
const MATERIAL_EXT = ['pdf', 'docx', 'pptx', 'xlsx', 'zip', 'txt', 'md', 'php', 'py', 'c', 'java', 'sql', 'html', 'csv'];
// Below this mark a result is held for the lecturer instead of being released.
const LOW_MARK = 40;
// Marked work first (by mark), then work waiting for the marker.
const ORDER_SQL = "ORDER BY (status = 'Pending') ASC, score DESC, submitted_at ASC";

$pdo = null;
for ($i = 0; $i < 10; $i++) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        break;
    } catch (PDOException $e) {
        if ($i === 9) die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        sleep(2);
    }
}
ensure_schema($pdo);

/* ---------- schema & settings ---------- */

// Brings an existing database (created from an older db_init.sql) up to date.
function ensure_schema($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (name VARCHAR(50) PRIMARY KEY, value TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        token VARCHAR(32) NOT NULL,
        is_brief TINYINT(1) NOT NULL DEFAULT 0,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $mcols = $pdo->query("SHOW COLUMNS FROM materials")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_brief', $mcols, true)) $pdo->exec("ALTER TABLE materials ADD COLUMN is_brief TINYINT(1) NOT NULL DEFAULT 0");
    $cols = $pdo->query("SHOW COLUMNS FROM submissions")->fetchAll(PDO::FETCH_COLUMN);
    $add = ['token' => 'VARCHAR(32) NULL', 'summary' => 'TEXT NULL', 'criteria' => 'TEXT NULL', 'lecturer_note' => 'TEXT NULL',
            'members' => 'TEXT NULL', 'flags' => 'TEXT NULL', 'attempts' => 'INT NOT NULL DEFAULT 0', 'graded_at' => 'TIMESTAMP NULL'];
    foreach ($add as $col => $def) {
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE submissions ADD COLUMN $col $def");
    }
    $pdo->exec("UPDATE submissions SET token = LEFT(MD5(CONCAT(id, RAND())), 20) WHERE token IS NULL");
    // Older builds called an unreviewed AI mark a "Draft"; it is now released as "Graded".
    $pdo->exec("UPDATE submissions SET status = 'Graded' WHERE status = 'Draft'");
}

function get_setting($pdo, $name, $default) {
    $st = $pdo->prepare("SELECT value FROM settings WHERE name = ?");
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

function set_setting($pdo, $name, $value) {
    $pdo->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([$name, $value]);
}

function find_by_token($pdo, $token) {
    $st = $pdo->prepare("SELECT * FROM submissions WHERE token = ?");
    $st->execute([(string)$token]);
    return $st->fetch() ?: null;
}

function find_material($pdo, $token) {
    $st = $pdo->prepare("SELECT * FROM materials WHERE token = ?");
    $st->execute([(string)$token]);
    return $st->fetch() ?: null;
}

function current_brief($pdo) {
    return $pdo->query("SELECT * FROM materials WHERE is_brief = 1 ORDER BY uploaded_at DESC LIMIT 1")->fetch() ?: null;
}

// Text of the assignment brief ('' if none), trimmed to fit the marker's context.
function brief_text($pdo) {
    $m = current_brief($pdo);
    if (!$m) return '';
    $ext = strtolower(pathinfo($m['filename'], PATHINFO_EXTENSION));
    $path = __DIR__ . '/uploads/' . basename($m['filename']);
    if (doc_kind($ext) === 'none' || !is_file($path)) return '';
    $t = preg_replace(["/[ \t]+/", "/\n\s*\n+/"], [' ', "\n"], extract_text($path, $ext));
    return mb_strlen($t) > BRIEF_CHARS ? mb_substr($t, 0, BRIEF_CHARS) . "\n[...brief continues...]" : $t;
}

// How the viewer can show a file: rendered PDF, rendered Word, escaped text, or download only.
function doc_kind($ext) {
    if ($ext === 'pdf') return 'pdf';
    if ($ext === 'docx') return 'docx';
    if (in_array($ext, ['txt', 'md', 'php', 'py', 'c', 'java', 'sql', 'html', 'csv'], true)) return 'text';
    return 'none';
}

function size_label($bytes) {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}

/* ---------- small helpers ---------- */

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash($type, $msg) { $_SESSION['flash'] = [$type, $msg]; }
function take_flash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function go($url) { header('Location: ' . $url); exit; }
function band($score) { return $score >= 70 ? 'high' : ($score >= 50 ? 'mid' : 'low'); }
function has_reg($reg) { return !in_array($reg, ['PENDING-REG', 'MANUAL-REVIEW', ''], true) && !str_starts_with((string)$reg, 'PENDING'); }
function grade_letter($score) { return $score >= 70 ? 'A' : ($score >= 60 ? 'B' : ($score >= 50 ? 'C' : ($score >= 40 ? 'D' : 'E'))); }

// Two-letter initials and a stable avatar colour (1-6) for a name.
function initials($name) {
    $w = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', (string)$name)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$w) return '?';
    return strtoupper(mb_substr($w[0], 0, 1) . (count($w) > 1 ? mb_substr(end($w), 0, 1) : ''));
}
function avatar_hue($name) { return (crc32((string)$name) % 6) + 1; }

function ago($ts) {
    $d = time() - strtotime((string)$ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    return date('j M', strtotime((string)$ts));
}

/**
 * What each status means, for the student and for the lecturer.
 *   Pending  - waiting for the marker (queued; the worker retries on its own)
 *   Graded   - marked and released to the student automatically
 *   Flagged  - marked, but held for the lecturer to check before the student sees it
 *   Approved - checked or adjusted by the lecturer; final
 */
function status_info($status, $for = 'student') {
    $s = [
        'Pending'  => ['Queued', 'Being marked', 'queued'],
        'Graded'   => ['Auto-released', 'Marked', 'graded'],
        'Flagged'  => ['Needs review', 'In review', 'flagged'],
        'Approved' => ['Approved', 'Final', 'approved'],
    ][$status] ?? [$status, $status, 'queued'];
    return ['label' => $for === 'lecturer' ? $s[0] : $s[1], 'class' => $s[2]];
}

/* ---------- text extraction & identity ---------- */

function extract_text($path, $ext) {
    if ($ext === 'pdf') {
        return trim((string)shell_exec('pdftotext ' . escapeshellarg($path) . ' - 2>/dev/null'));
    }
    if ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml !== false) {
                $xml = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);
                return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
        }
        return '';
    }
    return trim((string)@file_get_contents($path));
}

// Labelled identity lines on the cover page, e.g. "Student Name: Jane Doe" / "Reg No: SC/COM/0142/21".
function cover_identity($text) {
    $head = mb_substr($text, 0, 1500);
    $name = $reg = '';
    if (preg_match('/\b(?:student\s+)?(?:full\s+)?name\s*[:\-]\s*([A-Za-z][A-Za-z\'.\- ]{1,58})/i', $head, $m)) {
        $name = clean_name($m[1], '');
    }
    if (preg_match('/\b(?:reg(?:istration)?|admission|adm)\.?\s*(?:no\.?|number|#)?\s*[:\-]\s*([A-Za-z0-9][A-Za-z0-9\/\-.]{2,29})/i', $head, $m)) {
        $reg = clean_reg($m[1]);
    }
    return [$name, $reg === 'PENDING-REG' ? '' : $reg];
}

/**
 * Group submissions list several members, usually as a table that pdftotext turns into
 * "Name" / "REG" line pairs (or "Name REG" on one line). Returns null for single-student work.
 */
function group_identity($text) {
    $lines = array_values(array_filter(array_map('trim', explode("\n", mb_substr($text, 0, 4000))), 'strlen'));
    $members = [];
    foreach ($lines as $i => $l) {
        if (preg_match('/^(' . REG_RE . ')$/i', $l, $m)) {
            $name = '';
            for ($j = $i - 1; $j >= max(0, $i - 2); $j--) {
                if (preg_match('/^\d+\.?$/', $lines[$j])) continue;
                $name = clean_name($lines[$j], '');
                if ($name !== '') break;
            }
            $members[] = ['name' => $name, 'reg' => strtoupper($m[1])];
        } elseif (preg_match('/^(?:\d+\.?\s+)?([^:\d][^:]*?)\s+(' . REG_RE . ')$/i', $l, $m)) {
            $members[] = ['name' => clean_name($m[1], ''), 'reg' => strtoupper($m[2])];
        }
    }
    if (count($members) < 2) return null;
    $label = preg_match('/\bGroup\s+(\d{1,3}|[A-Z])\b/', $text, $g) ? "Group {$g[1]}" : 'Group submission';
    return ['label' => $label, 'members' => $members];
}

// Registration numbers listed for more than one group member (a common copy-paste mistake).
function duplicate_regs($members) {
    $by = [];
    foreach ($members ?: [] as $m) $by[$m['reg']][] = $m['name'] ?: '(no name)';
    return array_filter($by, fn($names) => count($names) > 1);
}

// Keep only a plausible person's name: cut at any commentary, drop labels/quotes, max 5 words.
function clean_name($raw, $fallback) {
    $s = preg_replace('/^(?:student\s+)?(?:full\s+)?name\s*[:\-]\s*/i', '', trim($raw, " \t'\""));
    $s = trim(preg_split('/[:(\n"]|\s[-–—]\s/u', $s)[0] ?? '', " \t'.,;");
    $words = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    $ok = $words && count($words) <= 5 && !preg_match('/\d/', $s) && !preg_match('/^unknown/i', $s)
        && !preg_match('/\b(?:I|the|is|but|use|might)\b/', $s);
    return $ok ? mb_substr(implode(' ', $words), 0, 60) : ($fallback !== '' ? $fallback : '');
}

function clean_reg($raw) {
    $s = strtoupper(trim(preg_split('/\s/', trim($raw))[0] ?? '', " .,;:'\""));
    return ($s !== '' && strlen($s) <= 30 && preg_match('/\d/', $s)) ? $s : 'PENDING-REG';
}

/* ---------- rubric ---------- */

// "40% Technical depth: problem analysis" -> ['weight'=>40, 'name'=>'Technical depth', 'detail'=>'problem analysis']
function parse_rubric($text) {
    $out = [];
    // Each criterion runs to the next "NN%" or the end of the line, so commas inside a description survive.
    if (preg_match_all('/(\d{1,3})\s*%\s*(.+?)\s*(?=,?\s*\d{1,3}\s*%|\r?\n|$)/', $text, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $parts = array_map(fn($p) => trim($p, " \t,;"), explode(':', $m[2], 2));
            $out[] = ['weight' => (int)$m[1], 'name' => $parts[0], 'detail' => $parts[1] ?? ''];
        }
    }
    return $out ?: [['weight' => 100, 'name' => 'Overall quality', 'detail' => trim($text)]];
}

/* ---------- AI marking ---------- */

// 90s keeps a whole request under a proxy's 100s timeout as well as PHP's 120s and Nginx's 180s.
function call_ollama($url, $payload, $timeout = 90) {
    $ch = curl_init($url . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    return [$code, $res, $errno];
}

function ollama_online($url) {
    $ch = curl_init($url . '/api/version');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 2]);
    curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    return $ok;
}

/**
 * Mark one submission. Always returns a row-shaped array; 'ok' says whether the AI produced a mark.
 * A successful mark is 'Graded' (released) unless a review flag applies, in which case it is 'Flagged'.
 */
function grade_submission($content, $rubric, $orig_name, $host, $brief = '') {
    $raw_len = mb_strlen(trim($content));
    // Tidy extracted text (zero-width marks, runs of blank lines/spaces) so more of the work fits the context.
    $content = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $content);
    $content = preg_replace(["/[ \t]+/", "/\n\s*\n+/"], [' ', "\n"], $content);
    // The brief shares the context with the submission, so the submission gets what is left.
    $limit = CONTENT_CHARS - mb_strlen($brief);
    $truncated = mb_strlen($content) > $limit;
    $content = mb_substr($content, 0, $limit) . ($truncated ? "\n[...submission continues; only the first part is shown...]" : '');

    [$cover_name, $cover_reg] = cover_identity($content);
    $group = group_identity($content);
    if ($group) {
        $n_m = count($group['members']);
        $cover_name = $group['label'] . " ($n_m members)";
        $cover_reg = $group['members'][0]['reg'] . ($n_m > 1 ? ' +' . ($n_m - 1) : '');
    }
    $r = [
        'student_name' => $cover_name ?: pathinfo($orig_name, PATHINFO_FILENAME),
        'reg_number' => $cover_reg ?: 'PENDING-REG',
        'members' => $group ? $group['members'] : null,
        'score' => 0, 'strength' => '', 'gap' => '', 'summary' => '', 'criteria' => null, 'flags' => [],
        'status' => 'Pending', 'ok' => false, 'reason' => '', 'retry' => true
    ];
    if (trim($content) === '') {
        $r['reason'] = 'No readable text in the file (a scanned PDF needs manual marking).';
        $r['status'] = 'Flagged';
        $r['flags'] = ['No readable text: mark by hand'];
        $r['retry'] = false;
        return $r;
    }

    $crit = parse_rubric($rubric);
    $list = '';
    foreach ($crit as $i => $c) {
        $list .= ($i + 1) . ". {$c['name']} ({$c['weight']}%)" . ($c['detail'] !== '' ? ": {$c['detail']}" : '') . "\n";
    }
    $n = count($crit);
    $who = $group ? 'a group of students ("you" means the group)' : 'the student as "you"';
    // A shared assignment brief tells the model what was asked, so its feedback names anything missing.
    $brief_part = $brief !== ''
        ? "ASSIGNMENT BRIEF (what the students were asked to do):\n$brief\n\n"
          . "Mark how well the submission does what this brief asks, and name anything required that it misses.\n\n"
        : '';
    $prompt = "You are a fair, experienced university lecturer marking coursework against a rubric.\n\n"
        . $brief_part
        . "RUBRIC CRITERIA (mark each from 0 to 100):\n$list\n"
        . "MARKING SCALE: 70-100 excellent (A), 60-69 good (B), 50-59 satisfactory (C), 40-49 pass (D), below 40 fail. "
        . "Competent work that answers the brief usually scores 55-75. Use scores below 40 only for work that is missing, off-topic or seriously wrong.\n"
        . "Judge the work for what it is. If a criterion assumes something this type of submission does not need (for example code or a prototype in a written report), mark the closest equivalent (examples, case studies, practical application) instead of treating it as missing.\n"
        . "Read the whole submission before marking; give credit for every section it contains.\n\n"
        . "Tasks:\n"
        . "1. Extract the student's full name and registration number from the cover page/header. student_name must be ONLY the person's name (e.g. \"Jane Doe\"), with no labels, quotes or commentary. If absent, use 'Unknown Student' and 'PENDING-REG'.\n"
        . "2. criteria: exactly $n entries, in the order listed above. For each, a score from 0 to 100 and one specific sentence of feedback that refers to this submission's actual content.\n"
        . "3. strength: the single biggest strength (max 15 words).\n"
        . "4. gap: the single most important improvement (max 15 words).\n"
        . "5. summary: 2 to 3 sentences of overall commentary addressed to $who.\n"
        . "Return ONLY JSON.\n\nSUBMISSION:\n" . $content;
    $schema = ["type" => "object", "properties" => [
        "student_name" => ["type" => "string", "maxLength" => 60],
        "reg_number" => ["type" => "string", "maxLength" => 30],
        "criteria" => ["type" => "array", "minItems" => $n, "maxItems" => $n, "items" => [
            "type" => "object",
            "properties" => [
                "score" => ["type" => "integer", "minimum" => 0, "maximum" => 100],
                "comment" => ["type" => "string", "maxLength" => 240]
            ],
            "required" => ["score", "comment"]
        ]],
        "strength" => ["type" => "string", "maxLength" => 140],
        "gap" => ["type" => "string", "maxLength" => 140],
        "summary" => ["type" => "string", "maxLength" => 500]
    ], "required" => ["student_name", "reg_number", "criteria", "strength", "gap", "summary"]];

    // Single call, no retry here: the background worker retries queued work on its own.
    [$code, $raw, $errno] = call_ollama($host, [
        "model" => MODEL, "prompt" => $prompt, "stream" => false, "think" => false,
        "format" => $schema, "keep_alive" => "30m",
        "options" => ["temperature" => 0.1, "num_predict" => 800, "num_ctx" => 4096]
    ]);
    if ($errno === CURLE_OPERATION_TIMEDOUT) { $r['reason'] = 'The marker took too long to answer.'; return $r; }
    if ($errno || $code === 0) { $r['reason'] = 'The marker is offline.'; return $r; }
    if ($code !== 200) { $r['reason'] = "The marker returned HTTP $code."; return $r; }

    $outer = json_decode((string)$raw, true);
    $ai = json_decode(trim(preg_replace('/<think>.*?<\/think>/s', '', $outer['response'] ?? '')), true);
    if (!is_array($ai) || !is_array($ai['criteria'] ?? null) || !$ai['criteria']) {
        $r['reason'] = 'The marker\'s answer could not be read.';
        return $r;
    }

    // Overall mark = rubric-weighted average of the criterion marks.
    $rows = []; $wsum = 0; $total = 0;
    foreach ($crit as $i => $c) {
        $a = $ai['criteria'][$i] ?? null;
        if (!is_array($a)) continue;
        $s = max(0, min(100, intval($a['score'] ?? 0)));
        $rows[] = ['name' => $c['name'], 'weight' => $c['weight'], 'score' => $s, 'comment' => trim((string)($a['comment'] ?? ''))];
        $wsum += $c['weight']; $total += $c['weight'] * $s;
    }
    if (!$rows) { $r['reason'] = 'The marker\'s answer could not be read.'; return $r; }

    // An explicit "Name:" / "Reg No:" label on the cover page beats the model's reading of it.
    // (For group work, $cover_name/$cover_reg already hold the group label and first member.)
    $r['student_name'] = $cover_name ?: clean_name((string)($ai['student_name'] ?? ''), pathinfo($orig_name, PATHINFO_FILENAME));
    $r['reg_number'] = $cover_reg ?: clean_reg((string)($ai['reg_number'] ?? ''));
    $r['score'] = $wsum ? (int)round($total / $wsum) : 0;
    $r['criteria'] = $rows;
    $r['strength'] = ltrim(trim((string)($ai['strength'] ?? '')), '+ ') ?: 'Submission parsed and evaluated.';
    $r['gap'] = ltrim(trim((string)($ai['gap'] ?? '')), '-− ') ?: 'Review rubric criteria for deeper coverage.';
    $r['summary'] = trim((string)($ai['summary'] ?? ''));
    $r['ok'] = true;

    // Review flags: anything a person should look at before the student sees the mark.
    if (!has_reg($r['reg_number'])) $r['flags'][] = 'No registration number found';
    foreach (duplicate_regs($r['members']) as $reg => $names) $r['flags'][] = "Reg. no. $reg listed twice (" . implode(', ', $names) . ')';
    if ($r['score'] < LOW_MARK) $r['flags'][] = "Low mark ({$r['score']}): confirm before release";
    if ($raw_len < 400) $r['flags'][] = 'Very short submission';
    $r['status'] = $r['flags'] ? 'Flagged' : 'Graded';
    return $r;
}

// Mark a stored submission (used by "Re-mark" and the background worker) and save the result.
function grade_stored($pdo, $row, $host) {
    $path = __DIR__ . '/uploads/' . basename($row['filename']);
    $pdo->prepare("UPDATE submissions SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
    if (!is_file($path)) return ['ok' => false, 'reason' => 'The stored file is missing.', 'retry' => false];
    $ext = strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION));
    $g = grade_submission(extract_text($path, $ext), get_setting($pdo, 'rubric', DEFAULT_RUBRIC), $row['original_name'], $host, brief_text($pdo));
    if ($g['ok'] || $g['status'] === 'Flagged') save_grade($pdo, $row['id'], $g);
    return $g;
}

function save_grade($pdo, $id, $g) {
    $pdo->prepare("UPDATE submissions SET student_name = ?, reg_number = ?, score = ?, strength = ?, gap = ?, summary = ?,
                   criteria = ?, members = ?, flags = ?, status = ?, graded_at = NOW() WHERE id = ?")
        ->execute([$g['student_name'], $g['reg_number'], $g['score'], $g['strength'], $g['gap'], $g['summary'],
            $g['criteria'] ? json_encode($g['criteria']) : null, $g['members'] ? json_encode($g['members']) : null,
            $g['flags'] ? json_encode($g['flags']) : null, $g['status'], $id]);
}

/* ---------- page chrome ---------- */

function page_head($title) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<title><?= h($title) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231a56db'/%3E%3Cpath d='M10 9v9a6 6 0 0 0 12 0V9' stroke='white' stroke-width='3.2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/style.css?v=13">
</head>
<body>
<?php }

function brand_mark() {
    return '<svg class="mark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8"/><path d="M10 9v9a6 6 0 0 0 12 0V9"/></svg>';
}

function top_bar($active, $online) { ?>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/"><?= brand_mark() ?><span class="brand-name">UniSubmit</span><span class="course-chip"><?= h(COURSE) ?></span></a>
        <nav class="switch" aria-label="View">
            <a href="/" class="<?= $active === 'student' ? 'on' : '' ?>" <?= $active === 'student' ? 'aria-current="page"' : '' ?>>Student</a>
            <a href="/lecturer.php" class="<?= $active === 'lecturer' ? 'on' : '' ?>" <?= $active === 'lecturer' ? 'aria-current="page"' : '' ?>>Lecturer</a>
        </nav>
        <span class="grader <?= $online ? 'on' : 'off' ?>" title="<?= h(MODEL) ?>">
            <span class="dot"></span><span class="grader-text"><?= $online ? 'Auto-marking on' : 'Marker offline · work is queued' ?></span>
        </span>
    </div>
</header>
<?php }

function flash_box($flash) {
    if ($flash) echo '<div class="toast ' . h($flash[0]) . '" role="status">' . h($flash[1]) . '</div>';
}

function avatar($name, $size = '') {
    return '<span class="avatar h' . avatar_hue($name) . ($size ? " $size" : '') . '" aria-hidden="true">' . h(initials($name)) . '</span>';
}

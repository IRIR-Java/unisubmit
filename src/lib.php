<?php
/*
 * UniSubmit shared code: database connection, tables, helpers and page layout.
 * Used by index.php (students), lecturer.php (lecturer/admin) and download.php.
 */
session_start();

$db_host = getenv('DB_HOST') ?: 'db';
$db_name = getenv('DB_NAME') ?: 'unisubmit_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: 'rootpassword';
const COURSE = 'COSC 428';
const SUBMIT_EXT = ['pdf', 'docx', 'doc', 'pptx', 'xlsx', 'txt', 'md', 'zip', 'php', 'py', 'c', 'java', 'sql', 'html'];
const MATERIAL_EXT = ['pdf', 'docx', 'doc', 'pptx', 'xlsx', 'txt', 'md', 'zip', 'csv'];

// Connect to MySQL (retry while the database container is still starting).
$pdo = null;
for ($i = 0; $i < 10; $i++) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        break;
    } catch (PDOException $e) {
        if ($i === 9) die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        sleep(2);
    }
}

// Create the tables if they do not exist yet (same as db_init.sql).
$pdo->exec("CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_name VARCHAR(150) NOT NULL,
    reg_number VARCHAR(100) NOT NULL,
    filename VARCHAR(255),
    original_name VARCHAR(255),
    token VARCHAR(32),
    status VARCHAR(50) DEFAULT 'Submitted',
    score INT NULL,
    lecturer_note TEXT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    token VARCHAR(32) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Rows from the earlier AI build carry other statuses; here a submission is only "Submitted" or "Marked".
$pdo->exec("UPDATE submissions SET status = 'Submitted' WHERE status NOT IN ('Submitted', 'Marked')");

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function flash($type, $msg) { $_SESSION['flash'] = [$type, $msg]; }
function take_flash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function go($url) { header('Location: ' . $url); exit; }
function size_label($bytes) { return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB'; }
function is_marked($s) { return $s['status'] === 'Marked' && $s['score'] !== null; }

function find_submission($pdo, $token) {
    $st = $pdo->prepare("SELECT * FROM submissions WHERE token = ?");
    $st->execute([(string)$token]);
    return $st->fetch() ?: null;
}
function find_material($pdo, $token) {
    $st = $pdo->prepare("SELECT * FROM materials WHERE token = ?");
    $st->execute([(string)$token]);
    return $st->fetch() ?: null;
}

// Save an uploaded file into uploads/ under a random name; returns [stored name, original name] or an error string.
function save_upload($file, $allowed, $prefix) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? 'Choose a file first.' : 'Upload failed. Please try again.';
    }
    $orig = basename($file['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return 'That file type is not allowed. Allowed: ' . implode(', ', $allowed) . '.';
    $stored = uniqid($prefix, true) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/uploads/' . $stored)) return 'The file could not be saved.';
    return [$stored, $orig];
}

function page_head($title) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231a56db'/%3E%3Cpath d='M10 9v9a6 6 0 0 0 12 0V9' stroke='white' stroke-width='3.2' fill='none' stroke-linecap='round'/%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/style.css?v=21">
</head>
<body>
<?php }

function top_bar($active) { ?>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/"><svg class="mark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8"/><path d="M10 9v9a6 6 0 0 0 12 0V9"/></svg><span class="brand-name">UniSubmit</span><span class="course-chip"><?= h(COURSE) ?></span></a>
        <nav class="switch" aria-label="View">
            <a href="/" class="<?= $active === 'student' ? 'on' : '' ?>">Student</a>
            <a href="/lecturer.php" class="<?= $active === 'lecturer' ? 'on' : '' ?>">Lecturer</a>
        </nav>
    </div>
</header>
<?php }

function flash_box($f) { if ($f) echo '<div class="toast ' . h($f[0]) . '" role="status">' . h($f[1]) . '</div>'; }

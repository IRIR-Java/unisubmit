<?php
/*
 * Sends a stored file as a download: a submission (?s=<token>) or a course file (?m=<token>).
 * Files are never served from /uploads directly (Nginx blocks it), so an uploaded script can never run.
 */
require __DIR__ . '/lib.php';

$item = isset($_GET['m']) ? find_material($pdo, $_GET['m']) : find_submission($pdo, $_GET['s'] ?? '');
$path = $item ? __DIR__ . '/uploads/' . basename($item['filename']) : '';
if (!$item || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('File not found.');
}
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $item['original_name']) ?: 'file';
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
readfile($path);

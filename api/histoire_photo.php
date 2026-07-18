<?php
/**
 * Serve histoire chapter photos — requires login
 * Usage: /api/histoire_photo.php?f=filename.jpg
 */
require_once __DIR__.'/../config.php';
requireLogin();

$filename = basename($_GET['f'] ?? '');
if (!$filename) {
    http_response_code(404);
    exit;
}

$path = __DIR__.'/../uploads/histoire/'.$filename;
if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($path);
if (!str_starts_with($mime, 'image/')) {
    http_response_code(403);
    exit;
}

header('Content-Type: '.$mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: '.filesize($path));
readfile($path);

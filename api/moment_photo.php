<?php
/**
 * Serve moment photos — requires login
 * Usage: /api/moment_photo.php?f=moment_123456_abcd1234.jpg
 */
require_once __DIR__.'/../config.php';
requireLogin();

$filename = basename($_GET['f'] ?? '');
if (!$filename || !preg_match('/^moment_\d+_[a-f0-9]+\.\w+$/', $filename)) {
    http_response_code(404);
    exit;
}

$path = __DIR__.'/../uploads/moments/'.$filename;
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

<?php
/**
 * Serve "Photo du jour" album photos — requires login
 * Usage: /api/album_photo.php?f=album_1_2_20260709_ab12cd34.jpg
 */
require_once __DIR__.'/../config.php';
requireLogin();

$filename = basename($_GET['f'] ?? '');
if (!$filename || !preg_match('/^album_\d+_\d+_\d+_[a-f0-9]+\.(jpe?g|png|gif|webp)$/', $filename)) {
    http_response_code(404);
    exit;
}

$path = __DIR__.'/../uploads/album/'.$filename;
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

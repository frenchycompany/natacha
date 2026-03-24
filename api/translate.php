<?php
/**
 * AJAX translation proxy — calls MyMemory API
 * GET ?q=text&from=fr&to=ru
 */
require_once __DIR__.'/../config.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q    = trim($_GET['q'] ?? '');
$from = $_GET['from'] ?? 'fr';
$to   = $_GET['to'] ?? 'ru';

if (!$q || strlen($q) > 5000) {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

if (!in_array($from, ['fr', 'ru']) || !in_array($to, ['fr', 'ru']) || $from === $to) {
    echo json_encode(['ok' => false, 'error' => 'Invalid language pair']);
    exit;
}

$translated = translateText($q, $from, $to);
echo json_encode(['ok' => true, 'text' => $translated], JSON_UNESCAPED_UNICODE);

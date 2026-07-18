<?php
/**
 * API — Transport des médias chiffrés de la messagerie E2EE.
 * Le serveur ne reçoit et ne renvoie que du CHIFFRÉ (blob AES-GCM déjà
 * chiffré côté navigateur). Il ne peut rien déchiffrer.
 *
 *  POST ?csrf=... (corps = octets chiffrés bruts)  → { ok, file }
 *  GET  ?f=<nom>                                    → renvoie le blob chiffré
 */
require_once __DIR__.'/../config.php';
requireLogin();

$user = currentUser();
$uid  = (int)$user['id'];

$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $s = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $s->execute([$uid]);
    $coupleId = $s->fetchColumn() ?: null;
}
if (!$coupleId) { http_response_code(403); exit; }
$coupleId = (int)$coupleId;

$dir = __DIR__.'/../uploads/messages/';

// ─── GET : servir un blob chiffré ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $f = basename($_GET['f'] ?? '');
    // Nom attendu : msg_<couple>_<user>_<time>_<hex>.enc
    if (!$f || !preg_match('/^msg_\d+_\d+_\d+_[a-f0-9]+\.enc$/', $f)) { http_response_code(404); exit; }
    // Doit appartenir au couple courant (le nom encode le couple_id)
    if (strpos($f, 'msg_'.$coupleId.'_') !== 0) { http_response_code(403); exit; }
    $path = $dir.$f;
    if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: application/octet-stream');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: '.filesize($path));
    readfile($path);
    exit;
}

// ─── POST : recevoir un blob chiffré ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // CSRF via query (le corps est binaire)
    $tok = $_GET['csrf'] ?? '';
    if (!$tok || !hash_equals($_SESSION['csrf_token'] ?? '', $tok)) {
        echo json_encode(['ok'=>false,'error'=>'csrf']); exit;
    }
    $data = file_get_contents('php://input');
    if ($data === false || strlen($data) === 0) {
        echo json_encode(['ok'=>false,'error'=>'empty']); exit;
    }
    // Limite dure : 30 Mo de chiffré (~ vidéo courte). post_max_size doit suivre.
    if (strlen($data) > 30 * 1024 * 1024) {
        echo json_encode(['ok'=>false,'error'=>'too_big']); exit;
    }
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    if (!is_dir($dir) || !is_writable($dir)) {
        echo json_encode(['ok'=>false,'error'=>'not_writable']); exit;
    }
    $fname = 'msg_'.$coupleId.'_'.$uid.'_'.time().'_'.bin2hex(random_bytes(6)).'.enc';
    if (file_put_contents($dir.$fname, $data) === false) {
        echo json_encode(['ok'=>false,'error'=>'write_failed']); exit;
    }
    echo json_encode(['ok'=>true,'file'=>$fname]);
    exit;
}

http_response_code(405);

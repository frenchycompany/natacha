<?php
/**
 * API Messagerie E2EE — le serveur ne stocke QUE du chiffré.
 *
 * Modèle crypto (tout se passe dans le navigateur, cf messages.php) :
 *  - Chaque utilisateur a une paire ECDH P-256 générée côté client.
 *  - La clé publique est stockée en clair (sert au partenaire à dériver le secret partagé).
 *  - La clé privée est chiffrée avec une clé dérivée d'une PHRASE SECRÈTE (PBKDF2),
 *    puis stockée ici sous forme chiffrée uniquement. Le serveur ne connaît jamais
 *    la phrase ni la clé privée en clair.
 *  - Les messages sont chiffrés AES-GCM avec le secret partagé (ECDH). Le serveur
 *    ne stocke que le ciphertext + l'IV.
 *
 * Ce fichier ne fait AUCUN déchiffrement : il transporte et range du chiffré.
 */
require_once __DIR__.'/../config.php';
requireLogin();
header('Content-Type: application/json');

$user = currentUser();
$uid  = (int)$user['id'];

// couple_id
$coupleId = $user['couple_id'] ?? null;
if (!$coupleId) {
    $s = db()->prepare("SELECT couple_id FROM users WHERE id=?");
    $s->execute([$uid]);
    $coupleId = $s->fetchColumn() ?: null;
}
if (!$coupleId) { echo json_encode(['ok'=>false,'error'=>'no_couple']); exit; }
$coupleId = (int)$coupleId;

// ─── Tables (auto-création) ───
if (empty($_SESSION['_tbl_messages'])) {
    try { db()->query("SELECT 1 FROM msg_keys LIMIT 1"); } catch (Exception $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS msg_keys (
            user_id INT UNSIGNED PRIMARY KEY,
            public_key TEXT NOT NULL,
            wrapped_private_key TEXT NOT NULL,
            salt VARCHAR(64) NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    try { db()->query("SELECT 1 FROM messages LIMIT 1"); } catch (Exception $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            couple_id INT UNSIGNED NOT NULL,
            sender_id INT UNSIGNED NOT NULL,
            msg_type ENUM('text','photo','video') DEFAULT 'text',
            ciphertext MEDIUMTEXT NOT NULL,
            iv VARCHAR(32) NOT NULL,
            media_file VARCHAR(255) DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME DEFAULT NULL,
            INDEX idx_couple (couple_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $_SESSION['_tbl_messages'] = 1;
}

// Purge des messages éphémères expirés (léger, à chaque appel)
try { db()->prepare("DELETE FROM messages WHERE expires_at IS NOT NULL AND expires_at < ?")
        ->execute([date('Y-m-d H:i:s')]); } catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Lecture JSON body si présent
$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) { $j = json_decode($raw, true); if (is_array($j)) $input = $j; }
    if (!$input) $input = $_POST;
}

// CSRF sur toutes les écritures
function needCsrf(array $input): bool {
    $tok = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    return $tok && hash_equals($_SESSION['csrf_token'] ?? '', $tok);
}

// ══════════════ ENDPOINTS ══════════════

// Enregistrer / mettre à jour mes clés (clé publique + clé privée chiffrée par la phrase)
if ($action === 'register_keys' && $method === 'POST') {
    if (!needCsrf($input)) { echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }
    $pub  = $input['public_key'] ?? '';
    $wrap = $input['wrapped_private_key'] ?? '';
    $salt = $input['salt'] ?? '';
    if (!$pub || !$wrap || !$salt || strlen($pub) > 4000 || strlen($wrap) > 8000 || strlen($salt) > 64) {
        echo json_encode(['ok'=>false,'error'=>'bad_input']); exit;
    }
    db()->prepare("INSERT INTO msg_keys (user_id, public_key, wrapped_private_key, salt)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE public_key=VALUES(public_key), wrapped_private_key=VALUES(wrapped_private_key), salt=VALUES(salt)")
        ->execute([$uid, $pub, $wrap, $salt]);
    echo json_encode(['ok'=>true]); exit;
}

// Récupérer MES clés (pour restaurer sur un nouvel appareil via la phrase)
if ($action === 'get_my_keys') {
    $s = db()->prepare("SELECT public_key, wrapped_private_key, salt FROM msg_keys WHERE user_id=?");
    $s->execute([$uid]);
    $row = $s->fetch();
    echo json_encode(['ok'=>true, 'keys'=>$row ?: null]); exit;
}

// Récupérer la clé PUBLIQUE du partenaire (pour dériver le secret partagé)
if ($action === 'get_partner_key') {
    $s = db()->prepare("SELECT u.id, u.display_name, k.public_key
        FROM users u LEFT JOIN msg_keys k ON k.user_id=u.id
        WHERE u.couple_id=? AND u.id!=? LIMIT 1");
    $s->execute([$coupleId, $uid]);
    $row = $s->fetch();
    echo json_encode(['ok'=>true, 'partner'=>$row ?: null]); exit;
}

// Envoyer un message chiffré
if ($action === 'send' && $method === 'POST') {
    if (!needCsrf($input)) { echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }
    $ct   = $input['ciphertext'] ?? '';
    $iv   = $input['iv'] ?? '';
    $type = $input['type'] ?? 'text';
    if (!in_array($type, ['text','photo','video'], true)) $type = 'text';
    $mediaFile = $input['media_file'] ?? null;
    $expiresIn = (int)($input['expires_in'] ?? 0); // secondes; 0 = permanent
    if (!$ct || !$iv || strlen($iv) > 32 || strlen($ct) > 12000000) {
        echo json_encode(['ok'=>false,'error'=>'bad_input']); exit;
    }
    $expiresAt = $expiresIn > 0 ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

    db()->prepare("INSERT INTO messages (couple_id, sender_id, msg_type, ciphertext, iv, media_file, expires_at)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([$coupleId, $uid, $type, $ct, $iv, $mediaFile, $expiresAt]);
    $newId = (int)db()->lastInsertId();

    // Notifier le partenaire (le contenu reste chiffré : la notif est générique)
    try {
        require_once __DIR__.'/../includes/notifications.php';
        $preview = $type === 'text'
            ? [$user['display_name'].' t\'a envoyé un message', $user['display_name'].' отправил(а) сообщение']
            : ($type === 'photo'
                ? [$user['display_name'].' t\'a envoyé une photo', $user['display_name'].' отправил(а) фото']
                : [$user['display_name'].' t\'a envoyé une vidéo', $user['display_name'].' отправил(а) видео']);
        notifyOtherUser($uid, 'message', $preview[0], $preview[1], BASE_URL.'/messages.php');
    } catch (Exception $e) {}

    echo json_encode(['ok'=>true, 'id'=>$newId, 'created_at'=>date('c')]); exit;
}

// Récupérer les messages postérieurs à un id (pour le rafraîchissement léger)
if ($action === 'fetch') {
    $since = (int)($_GET['since'] ?? 0);
    $s = db()->prepare("SELECT id, sender_id, msg_type, ciphertext, iv, media_file, expires_at, created_at, read_at
        FROM messages WHERE couple_id=? AND id>? ORDER BY id ASC LIMIT 200");
    $s->execute([$coupleId, $since]);
    $rows = $s->fetchAll();
    echo json_encode(['ok'=>true, 'messages'=>$rows]); exit;
}

// Marquer comme lus les messages reçus jusqu'à un id
if ($action === 'mark_read' && $method === 'POST') {
    if (!needCsrf($input)) { echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }
    $upTo = (int)($input['up_to_id'] ?? 0);
    if ($upTo > 0) {
        db()->prepare("UPDATE messages SET read_at=? WHERE couple_id=? AND sender_id!=? AND id<=? AND read_at IS NULL")
            ->execute([date('Y-m-d H:i:s'), $coupleId, $uid, $upTo]);
    }
    echo json_encode(['ok'=>true]); exit;
}

// Supprimer un de MES messages (des deux côtés — c'est du chiffré, on efface la ligne)
if ($action === 'delete' && $method === 'POST') {
    if (!needCsrf($input)) { echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }
    $mid = (int)($input['id'] ?? 0);
    if ($mid) {
        // media à nettoyer ?
        $m = db()->prepare("SELECT media_file FROM messages WHERE id=? AND couple_id=? AND sender_id=?");
        $m->execute([$mid, $coupleId, $uid]);
        $mf = $m->fetchColumn();
        db()->prepare("DELETE FROM messages WHERE id=? AND couple_id=? AND sender_id=?")
            ->execute([$mid, $coupleId, $uid]);
        if ($mf) { $p = __DIR__.'/../uploads/messages/'.basename($mf); if (is_file($p)) @unlink($p); }
    }
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false, 'error'=>'unknown_action']);

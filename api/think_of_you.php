<?php
/**
 * API — "Je pense à toi" : envoie une notification push instantanée au partenaire.
 * Rate-limité pour éviter le spam (max 1 / 2 min par utilisateur).
 */
require_once __DIR__.'/../config.php';
requireLogin();
header('Content-Type: application/json');

$user = currentUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfVerify()) {
    echo json_encode(['ok' => false]); exit;
}

// Anti-spam : 1 envoi / 2 min
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rlKey = 'think_'.$user['id'];
try {
    if (!checkRateLimit('think_of_you', $rlKey, 1, 120)) {
        echo json_encode(['ok' => false, 'error' => 'rate']);
        exit;
    }
    recordRateLimit('think_of_you', $rlKey);
} catch (Exception $e) {}

// Petit message doux, éventuellement personnalisé
$note = trim($_POST['note'] ?? '');
if (mb_strlen($note) > 120) $note = mb_substr($note, 0, 120);

$name = $user['display_name'] ?? '';
$fr = $note !== '' ? ($name.' pense à toi 💭 « '.$note.' »') : ($name.' pense à toi 💭');
$ru = $note !== '' ? ($name.' думает о тебе 💭 « '.$note.' »') : ($name.' думает о тебе 💭');

try {
    require_once __DIR__.'/../includes/notifications.php';
    notifyOtherUser($user['id'], 'pensee', $fr, $ru, BASE_URL.'/couple.php');
} catch (Exception $e) {}

// Petit bonus tendresse pour l'avatar du couple
try {
    $coupleId = $user['couple_id'] ?? null;
    if (!$coupleId) {
        $s = db()->prepare("SELECT couple_id FROM users WHERE id=?");
        $s->execute([$user['id']]);
        $coupleId = $s->fetchColumn() ?: null;
    }
    if ($coupleId) {
        require_once __DIR__.'/../includes/couple_helper.php';
        $ce = new CoupleEntity(db());
        $ce->recordActivity($coupleId, $user['id'], 'reaction',
            $name.' a pensé à l\'autre 💭', $name.' подумал(а) о партнёре 💭');
    }
} catch (Exception $e) {}

echo json_encode(['ok' => true]);

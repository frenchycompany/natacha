<?php
require_once __DIR__.'/config.php';
startSession();

if (!empty($_SESSION['user_id'])) {
    // If user has a couple, go to couple page; otherwise dashboard
    $coupleId = $_SESSION['user']['couple_id'] ?? null;
    if ($coupleId) {
        header('Location: '.BASE_URL.'/couple.php');
    } else {
        header('Location: '.BASE_URL.'/dashboard.php');
    }
} else {
    header('Location: '.BASE_URL.'/landing.php');
}
exit;

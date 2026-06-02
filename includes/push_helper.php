<?php
/**
 * Send push notification to a user
 * Called after notifyOtherUser() to also send a real push
 */

function sendPushToUser(int $userId, string $title, string $body, string $url = ''): void {
    $autoloadPath = __DIR__.'/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) return;
    require_once $autoloadPath;

    try {
        $subs = db()->prepare("SELECT * FROM push_subscriptions WHERE user_id=?");
        $subs->execute([$userId]);
        $rows = $subs->fetchAll();
        if (empty($rows)) return;

        $auth = [
            'VAPID' => [
                'subject'    => VAPID_SUBJECT,
                'publicKey'  => VAPID_PUBLIC,
                'privateKey' => VAPID_PRIVATE,
            ],
        ];
        $webPush = new WebPush($auth);

        $payload = json_encode([
            'title' => $title,
            'body'  => $body,
            'icon'  => '/natacha/assets/icons/icon-192x192.png',
            'badge' => '/natacha/assets/icons/icon-96x96.png',
            'url'   => $url ?: '/natacha/couple.php',
            'tag'   => 'natacha-' . time(),
        ]);

        foreach ($rows as $row) {
            $sub = Subscription::create([
                'endpoint' => $row['endpoint'],
                'keys'     => [
                    'p256dh' => $row['p256dh'],
                    'auth'   => $row['auth'],
                ],
            ]);
            $webPush->queueNotification($sub, $payload);
        }

        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                // Clean up expired subscriptions
                db()->prepare("DELETE FROM push_subscriptions WHERE endpoint=?")
                    ->execute([$report->getEndpoint()]);
            }
        }
    } catch (Exception $e) {
        error_log('Push notification error for user ' . $userId . ': ' . $e->getMessage());
    }
}

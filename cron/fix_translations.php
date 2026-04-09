<?php
/**
 * Fix translations: detect real language from user, re-translate if wrong
 * Run: php cron/fix_translations.php
 */
require_once __DIR__.'/../config.php';

echo "=== Fix translations ===\n";

$tables = [
    ['table' => 'livre_desirs', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
    ['table' => 'gratitude_entries', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
    ['table' => 'couple_dreams', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
    ['table' => 'couple_quick_actions', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
];

// Build user lang map
$users = db()->query("SELECT id, lang, display_name FROM users")->fetchAll();
$userLangs = [];
foreach ($users as $u) {
    $userLangs[$u['id']] = $u['lang'] ?? 'fr';
    echo "User {$u['display_name']} (#{$u['id']}): lang={$u['lang']}\n";
}

foreach ($tables as $t) {
    $tbl = $t['table'];
    $cc = $t['content_col'];
    $tc = $t['trans_col'];
    $lc = $t['lang_col'];

    try { db()->query("SELECT {$tc} FROM {$tbl} LIMIT 1"); } catch (Exception $e) {
        echo "[{$tbl}] Skipping (no {$tc} column)\n";
        continue;
    }

    // Get ALL entries — check if content_lang matches user's actual lang
    $rows = db()->query("SELECT id, user_id, {$cc}, {$tc}, {$lc} FROM {$tbl} ORDER BY id")->fetchAll();
    echo "\n[{$tbl}] Checking " . count($rows) . " entries...\n";

    $fixed = 0;
    foreach ($rows as $row) {
        $actualLang = $userLangs[$row['user_id']] ?? 'fr';
        $storedLang = $row[$lc] ?? 'fr';
        $content = $row[$cc];

        if (!$content || mb_strlen($content) < 2) continue;

        // If stored lang doesn't match user's actual lang, it was wrong
        if ($storedLang !== $actualLang) {
            echo "  [{$tbl}#{$row['id']}] WRONG: stored={$storedLang}, actual={$actualLang} — re-translating\n";
            $toLang = $actualLang === 'ru' ? 'fr' : 'ru';
            $translated = translateText($content, $actualLang, $toLang);
            if ($translated) {
                db()->prepare("UPDATE {$tbl} SET {$tc}=?, {$lc}=? WHERE id=?")
                    ->execute([$translated, $actualLang, $row['id']]);
                $fixed++;
                echo "    => OK ({$actualLang}→{$toLang})\n";
            } else {
                echo "    => API failed\n";
            }
            usleep(300000);
        }
        // If no translation exists at all
        elseif (empty($row[$tc])) {
            $toLang = $actualLang === 'ru' ? 'fr' : 'ru';
            $translated = translateText($content, $actualLang, $toLang);
            if ($translated) {
                db()->prepare("UPDATE {$tbl} SET {$tc}=?, {$lc}=? WHERE id=?")
                    ->execute([$translated, $actualLang, $row['id']]);
                $fixed++;
                echo "  [{$tbl}#{$row['id']}] Added translation ({$actualLang}→{$toLang})\n";
            }
            usleep(300000);
        }
    }
    echo "[{$tbl}] Fixed: {$fixed}\n";
}

echo "\n=== Done ===\n";

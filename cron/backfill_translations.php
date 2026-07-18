<?php
/**
 * Backfill translations for existing content that has content_translated = NULL
 * Run once: php cron/backfill_translations.php
 */
require_once __DIR__.'/../config.php';

echo "=== Backfill translations ===\n";

$tables = [
    ['table' => 'livre_desirs', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
    ['table' => 'gratitude_entries', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
    ['table' => 'couple_dreams', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
    ['table' => 'couple_quick_actions', 'content_col' => 'content', 'trans_col' => 'content_translated', 'lang_col' => 'content_lang'],
];

foreach ($tables as $t) {
    $tbl = $t['table'];
    $cc = $t['content_col'];
    $tc = $t['trans_col'];
    $lc = $t['lang_col'];

    // Check columns exist
    try { db()->query("SELECT {$tc} FROM {$tbl} LIMIT 1"); } catch (Exception $e) {
        echo "[{$tbl}] Column {$tc} missing, skipping\n";
        continue;
    }

    // Get untranslated entries
    $rows = db()->query("SELECT id, {$cc}, {$lc}, user_id FROM {$tbl} WHERE {$tc} IS NULL OR {$tc} = '' ORDER BY id")->fetchAll();
    echo "[{$tbl}] {$count} entries to translate: " . count($rows) . "\n";

    $done = 0;
    foreach ($rows as $row) {
        $content = $row[$cc];
        if (!$content || mb_strlen($content) < 2) continue;

        // Detect language from user or default to fr
        $fromLang = 'fr';
        if ($row[$lc] && $row[$lc] !== '') {
            $fromLang = $row[$lc];
        } else {
            // Try to get user's lang
            try {
                $uLang = db()->prepare("SELECT lang FROM users WHERE id=?");
                $uLang->execute([$row['user_id']]);
                $fromLang = $uLang->fetchColumn() ?: 'fr';
            } catch (Exception $e) {}
        }
        $toLang = $fromLang === 'ru' ? 'fr' : 'ru';

        $translated = translateText($content, $fromLang, $toLang);
        if ($translated) {
            db()->prepare("UPDATE {$tbl} SET {$tc}=?, {$lc}=? WHERE id=?")
                ->execute([$translated, $fromLang, $row['id']]);
            $done++;
            echo "  [{$tbl}#{$row['id']}] {$fromLang}→{$toLang}: OK\n";
        } else {
            echo "  [{$tbl}#{$row['id']}] {$fromLang}→{$toLang}: FAILED\n";
        }

        // Rate limit: MyMemory allows ~5 req/sec
        usleep(300000); // 300ms
    }
    echo "[{$tbl}] Done: {$done} translated\n\n";
}

echo "=== Complete ===\n";

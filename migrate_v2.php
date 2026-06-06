<?php
/**
 * Run all pending migrations at once.
 * Called once, then sets a flag in DB to not run again.
 */
require_once __DIR__.'/config.php';

$version = 0;
try {
    $v = db()->query("SELECT setting_value FROM site_settings WHERE setting_key='db_version'")->fetchColumn();
    $version = (int)$v;
} catch (Exception $e) {}

if ($version >= 2) {
    return; // Already migrated
}

// All migrations in one shot
$migrations = [
    "ALTER TABLE couple_quick_actions ADD COLUMN content_translated VARCHAR(200) DEFAULT NULL",
    "ALTER TABLE couple_quick_actions ADD COLUMN content_lang CHAR(2) DEFAULT 'fr'",
    "ALTER TABLE couple_quick_actions ADD COLUMN photo TEXT DEFAULT NULL",
    "ALTER TABLE gratitude_entries ADD COLUMN content_translated TEXT DEFAULT NULL",
    "ALTER TABLE gratitude_entries ADD COLUMN content_lang CHAR(2) DEFAULT 'fr'",
    "ALTER TABLE livre_desirs ADD COLUMN content_translated TEXT DEFAULT NULL",
    "ALTER TABLE livre_desirs ADD COLUMN content_lang CHAR(2) DEFAULT 'fr'",
    "ALTER TABLE couple_dreams ADD COLUMN content_translated TEXT DEFAULT NULL",
    "ALTER TABLE couple_dreams ADD COLUMN content_lang CHAR(2) DEFAULT 'fr'",
    "ALTER TABLE jeux_connaissance_reponses ADD COLUMN is_self_answer TINYINT(1) DEFAULT 0",
    "ALTER TABLE jeux_connaissance_reponses ADD COLUMN reponse_translated TEXT DEFAULT NULL",
    "ALTER TABLE jeux_connaissance_reponses ADD COLUMN reponse_lang CHAR(2) DEFAULT 'fr'",
    "ALTER TABLE mots_du_jour ADD COLUMN message_translated TEXT DEFAULT NULL",
    "ALTER TABLE mots_du_jour ADD COLUMN message_lang CHAR(2) DEFAULT 'fr'",
    "ALTER TABLE users ADD COLUMN accent_color VARCHAR(7) DEFAULT '#c9a96e'",
    "ALTER TABLE couple_levels ADD COLUMN name_ru VARCHAR(50) DEFAULT NULL",
];

$ok = 0;
$skip = 0;
foreach ($migrations as $sql) {
    try {
        db()->exec($sql);
        $ok++;
    } catch (Exception $e) {
        $skip++; // Column already exists
    }
}

// Mark as done
try {
    setSetting('db_version', '2');
} catch (Exception $e) {
    db()->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('db_version','2') ON DUPLICATE KEY UPDATE setting_value='2'");
}

if (php_sapi_name() === 'cli') {
    echo "Migrations: {$ok} applied, {$skip} skipped\n";
}

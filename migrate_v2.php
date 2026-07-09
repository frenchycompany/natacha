<?php
/**
 * Run all pending migrations.
 * Safe to run multiple times — each ALTER is wrapped in try/catch.
 */
require_once __DIR__.'/config.php';

echo "Running migrations...\n";

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
    "ALTER TABLE reactions MODIFY COLUMN item_type VARCHAR(50) NOT NULL",
    // Index pour les COUNT de réactions (item_type,item_id) — sans user_id en tête
    "ALTER TABLE reactions ADD INDEX idx_item (item_type, item_id)",
    // Clé unique pour la décote quotidienne atomique (anti double-décote)
    "ALTER TABLE gauge_decay_log ADD UNIQUE KEY uk_couple_day (couple_id, decayed_at)",
];

$ok = 0;
$skip = 0;
$errors = [];
foreach ($migrations as $sql) {
    try {
        db()->exec($sql);
        $ok++;
        echo "  OK: $sql\n";
    } catch (Exception $e) {
        $skip++;
        $msg = $e->getMessage();
        // Toutes ces variantes = "déjà en place", donc bénin (colonne/index/clé)
        if (stripos($msg, 'Duplicate column') !== false
            || stripos($msg, 'Duplicate key name') !== false
            || stripos($msg, 'already exists') !== false
            || stripos($msg, 'check that column/key exists') !== false) {
            echo "  SKIP: $sql (déjà en place)\n";
        } else {
            echo "  ERROR: $sql => $msg\n";
            $errors[] = $msg;
        }
    }
}

echo "\nDone: {$ok} applied, {$skip} skipped\n";
if (!empty($errors)) {
    echo "ERRORS:\n";
    foreach ($errors as $e) echo "  - $e\n";
}

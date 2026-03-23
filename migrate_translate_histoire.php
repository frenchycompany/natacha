<?php
/**
 * Script de migration : traduit les chapitres existants qui n'ont pas encore de traduction.
 * Usage : php migrate_translate_histoire.php  (ou via navigateur)
 */
require_once __DIR__.'/config.php';

echo "=== Migration traduction histoire ===\n";

// Récupérer les chapitres sans traduction
$rows = db()->query("
    SELECT h.id, h.titre, h.contenu, h.langue, u.lang AS user_lang
    FROM histoire_chapitres h
    JOIN users u ON u.id = h.user_id
    WHERE h.contenu_traduit IS NULL OR h.contenu_traduit = ''
")->fetchAll();

echo count($rows) . " chapitre(s) à traduire.\n";

foreach ($rows as $row) {
    $fromLang = $row['langue'] ?? ($row['user_lang'] ?? 'fr');
    $toLang   = $fromLang === 'ru' ? 'fr' : 'ru';

    echo "#{$row['id']} ({$fromLang}→{$toLang}) \"{$row['titre']}\" ... ";

    // Mettre à jour la langue si pas encore définie
    if (empty($row['langue'])) {
        db()->prepare("UPDATE histoire_chapitres SET langue=? WHERE id=?")->execute([$fromLang, $row['id']]);
    }

    $titre_traduit   = translateText($row['titre'], $fromLang, $toLang);
    $contenu_traduit = translateText($row['contenu'], $fromLang, $toLang);

    if ($contenu_traduit) {
        db()->prepare("UPDATE histoire_chapitres SET titre_traduit=?, contenu_traduit=? WHERE id=?")
           ->execute([$titre_traduit, $contenu_traduit, $row['id']]);
        echo "OK\n";
    } else {
        echo "ERREUR (API indisponible ?)\n";
    }

    // Petite pause pour ne pas spammer l'API
    usleep(500000);
}

echo "=== Terminé ===\n";

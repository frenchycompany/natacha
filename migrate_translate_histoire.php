<?php
/**
 * Script de migration : corrige la langue et traduit les chapitres existants.
 * Usage : php migrate_translate_histoire.php  (ou via navigateur)
 */
require_once __DIR__.'/config.php';

echo "=== Migration traduction histoire ===\n\n";

// ÉTAPE 1 : Corriger la langue de tous les chapitres selon la langue de l'auteur
echo "--- Correction des langues ---\n";
db()->exec("
    UPDATE histoire_chapitres h
    JOIN users u ON u.id = h.user_id
    SET h.langue = u.lang
");
echo "Langues corrigées selon le profil de chaque utilisateur.\n\n";

// ÉTAPE 2 : Traduire les chapitres sans traduction (ou retraduire tout)
$retraduire = in_array('--force', $argv ?? []) || isset($_GET['force']);
if ($retraduire) {
    echo "--- Mode FORCE : retraduction de tous les chapitres ---\n";
    $rows = db()->query("
        SELECT h.id, h.titre, h.contenu, u.lang AS user_lang
        FROM histoire_chapitres h
        JOIN users u ON u.id = h.user_id
    ")->fetchAll();
} else {
    echo "--- Traduction des chapitres manquants ---\n";
    $rows = db()->query("
        SELECT h.id, h.titre, h.contenu, u.lang AS user_lang
        FROM histoire_chapitres h
        JOIN users u ON u.id = h.user_id
        WHERE h.contenu_traduit IS NULL OR h.contenu_traduit = ''
    ")->fetchAll();
}

echo count($rows) . " chapitre(s) à traduire.\n\n";

foreach ($rows as $row) {
    $fromLang = $row['user_lang'] ?? 'fr';
    $toLang   = $fromLang === 'ru' ? 'fr' : 'ru';

    echo "#{$row['id']} ({$fromLang}→{$toLang}) \"" . mb_substr($row['titre'], 0, 40) . "\" ... ";

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

echo "\n=== Terminé ===\n";

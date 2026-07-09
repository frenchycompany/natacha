<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * NATACHA — Sauvegarde hebdomadaire chiffrée du coffre-fort
 * ────────────────────────────────────────────────────────────────────────────
 * Produit une archive .zip unique et portable contenant :
 *   - manifest.json : métadonnées de la table `coffre_fichiers` (tout ce qu'il
 *     faut pour restaurer — id, user_id, nom_original, nom_chiffre, type_mime,
 *     taille, categorie, description, tags, iv, file_key, created_at)
 *   - tous les blobs *.enc référencés par `nom_chiffre` (déjà chiffrés au repos)
 *
 * Les blobs sont copiés TELS QUELS : ils sont déjà chiffrés en AES-256-CBC et
 * chaque clé de fichier est encapsulée (wrapped) avec COFFRE_KEY. On ne
 * déchiffre RIEN — la sauvegarde ne contient donc que du chiffré, ce qui la
 * rend sûre même si elle quitte le serveur.
 *
 * Pour restaurer il faut : cette archive + COFFRE_KEY (config.php). Sans la clé,
 * l'archive est inexploitable.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * CRON (dimanche 03:00, journalisé) :
 *
 *   0 3 * * 0 php /var/www/frenchy-core/ionos/gestion/natacha/cron/backup_coffre.php >> /var/log/natacha_backup.log 2>&1
 *
 * ════════════════════════════════════════════════════════════════════════════
 */

// ── 1. CLI uniquement ────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// ── 2. Configuration (COFFRE_KEY, COFFRE_STORAGE, db(), DB_NAME) ──────────────
require_once __DIR__ . '/../config.php';

// Nombre de sauvegardes hebdomadaires conservées (~2 mois).
const BACKUP_RETENTION = 8;

$startTime = microtime(true);

try {
    // ── 3. Répertoire de sauvegarde ──────────────────────────────────────────
    // IMPORTANT : ce dossier ne doit PAS être accessible depuis le web.
    // Sur Apache on dépose un .htaccess "Deny from all" ci-dessous.
    // Sur nginx (qui ignore .htaccess), placez ce dossier HORS du webroot ou
    // protégez-le explicitement dans la conf serveur, ex. :
    //     location ~ ^/storage/backups/ { deny all; return 404; }
    $backupDir = __DIR__ . '/../storage/backups';
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
            throw new RuntimeException("Impossible de créer le dossier de sauvegarde : $backupDir");
        }
    }

    // .htaccess (Apache) — refuse tout accès web au dossier de sauvegarde.
    $htaccess = $backupDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        // "Require all denied" (Apache 2.4) + "Deny from all" (Apache 2.2) pour compat.
        $rules = "Require all denied\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
        file_put_contents($htaccess, $rules);
    }

    // ── 4. Métadonnées : dump de la table coffre_fichiers ────────────────────
    $rows = db()->query(
        "SELECT id, user_id, nom_original, nom_chiffre, type_mime, taille,
                categorie, description, tags, iv, file_key, created_at
         FROM coffre_fichiers
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $manifest = [
        'backup_version' => 1,
        'generated_at'   => date('c'),
        'database'       => DB_NAME,
        'table'          => 'coffre_fichiers',
        'note'           => 'Blobs already encrypted (AES-256-CBC). Per-file key wrapped with COFFRE_KEY. Requires COFFRE_KEY to decrypt.',
        'file_count'     => count($rows),
        'fichiers'       => $rows,
    ];
    $manifestJson = json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($manifestJson === false) {
        throw new RuntimeException('Échec de l\'encodage JSON du manifest : ' . json_last_error_msg());
    }

    // ── 5. Résoudre les blobs chiffrés référencés ────────────────────────────
    $blobs = [];        // [nom_chiffre => chemin absolu]
    $missing = [];      // blobs référencés mais absents du disque
    $totalBytes = 0;
    foreach ($rows as $row) {
        $nom = $row['nom_chiffre'];
        if ($nom === null || $nom === '' || isset($blobs[$nom])) {
            continue;
        }
        $path = COFFRE_STORAGE . '/' . $nom;
        if (is_file($path)) {
            $blobs[$nom] = $path;
            $totalBytes += (int) filesize($path);
        } else {
            $missing[] = $nom;
        }
    }

    $date       = date('Y-m-d');
    $archiveName = "coffre_backup_$date";

    // ── 6. Construction de l'archive ─────────────────────────────────────────
    $usedZip = false;
    $archivePath = '';

    if (class_exists('ZipArchive')) {
        $archivePath = $backupDir . '/' . $archiveName . '.zip';
        // Supprime une archive du même jour (ré-exécution le même dimanche).
        if (file_exists($archivePath)) {
            @unlink($archivePath);
        }

        $zip = new ZipArchive();
        $res = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($res !== true) {
            throw new RuntimeException("Impossible d'ouvrir l'archive zip ($res) : $archivePath");
        }

        $zip->addFromString('manifest.json', $manifestJson);
        foreach ($blobs as $nom => $path) {
            // Les blobs vont dans un sous-dossier "coffre/" pour refléter le stockage.
            if (!$zip->addFile($path, 'coffre/' . $nom)) {
                throw new RuntimeException("Impossible d'ajouter le blob à l'archive : $nom");
            }
        }
        if ($zip->close() !== true) {
            throw new RuntimeException("Échec de la finalisation de l'archive zip : $archivePath");
        }
        // Restreint les permissions de l'archive (contient les clés encapsulées).
        @chmod($archivePath, 0600);
        $usedZip = true;

    } else {
        // ── 8. Repli si l'extension ZipArchive est absente ───────────────────
        // On copie le manifest + les blobs dans un dossier daté.
        $archivePath = $backupDir . '/' . $archiveName;
        if (!is_dir($archivePath)) {
            if (!mkdir($archivePath, 0700, true) && !is_dir($archivePath)) {
                throw new RuntimeException("Impossible de créer le dossier de repli : $archivePath");
            }
        }
        if (file_put_contents($archivePath . '/manifest.json', $manifestJson) === false) {
            throw new RuntimeException("Impossible d'écrire le manifest dans : $archivePath");
        }
        $blobDir = $archivePath . '/coffre';
        if (!is_dir($blobDir) && !mkdir($blobDir, 0700, true) && !is_dir($blobDir)) {
            throw new RuntimeException("Impossible de créer le dossier des blobs : $blobDir");
        }
        foreach ($blobs as $nom => $path) {
            if (!copy($path, $blobDir . '/' . $nom)) {
                throw new RuntimeException("Impossible de copier le blob : $nom");
            }
        }
        @chmod($archivePath, 0700);
    }

    // ── 5 (bis). Rétention : conserver les 8 dernières sauvegardes ───────────
    // On liste zips ET dossiers de repli, on trie par nom (date ISO = tri
    // chronologique), on supprime les plus anciens au-delà de BACKUP_RETENTION.
    $existing = glob($backupDir . '/coffre_backup_*') ?: [];
    rsort($existing); // plus récent en premier
    $deleted = 0;
    foreach (array_slice($existing, BACKUP_RETENTION) as $old) {
        if (is_dir($old)) {
            rrmdir($old);
            $deleted++;
        } elseif (is_file($old)) {
            @unlink($old);
            $deleted++;
        }
    }

    // ── 6. Résumé ─────────────────────────────────────────────────────────────
    $finalSize = is_file($archivePath) ? filesize($archivePath) : dir_size($archivePath);
    $elapsed = round(microtime(true) - $startTime, 2);

    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  NATACHA — Sauvegarde coffre-fort — " . date('Y-m-d H:i:s') . "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  Format         : " . ($usedZip ? 'ZIP' : 'DOSSIER (repli, ZipArchive absent)') . "\n";
    echo "  Fichiers       : " . count($blobs) . " blob(s) chiffré(s)\n";
    echo "  Enregistrements: " . count($rows) . " ligne(s) coffre_fichiers\n";
    echo "  Données brutes : " . human_size($totalBytes) . "\n";
    echo "  Archive        : $archivePath\n";
    echo "  Taille archive : " . human_size((int) $finalSize) . "\n";
    echo "  Rétention      : " . count($existing) . " sauvegarde(s), $deleted supprimée(s) (max " . BACKUP_RETENTION . ")\n";
    if (!empty($missing)) {
        echo "  ⚠ Manquants    : " . count($missing) . " blob(s) référencé(s) introuvable(s) sur le disque\n";
        foreach ($missing as $m) {
            echo "      - $m\n";
        }
    }
    echo "  Durée          : {$elapsed}s\n";
    echo "═══════════════════════════════════════════════════════════════\n";

    exit(0);

} catch (Throwable $e) {
    // ── 7. Gestion d'erreur : message + sortie non-zéro ──────────────────────
    fwrite(STDERR, "[ERREUR backup_coffre] " . $e->getMessage() . "\n");
    echo "[ERREUR backup_coffre] " . $e->getMessage() . "\n";
    exit(1);
}

// ════════════════════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════════════════════

/** Taille lisible par un humain. */
function human_size(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' Go';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' Mo';
    if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' Ko';
    return $bytes . ' o';
}

/** Taille totale d'un dossier (récursif). */
function dir_size(string $dir): int
{
    if (!is_dir($dir)) return 0;
    $total = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        $total += $file->getSize();
    }
    return $total;
}

/** Suppression récursive d'un dossier. */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

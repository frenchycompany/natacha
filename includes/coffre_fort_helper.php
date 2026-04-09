<?php
/**
 * CoffreFort — Helper class for encrypted file vault
 * AES-256-CBC encryption, session management, file operations
 */
class CoffreFort
{
    public ?string $lastError = null;

    // ════════════════════════════════════════════════════════════
    // PIN Management
    // ════════════════════════════════════════════════════════════

    public function hasPin(int $userId): bool
    {
        $stmt = db()->prepare("SELECT coffre_pin FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $pin = $stmt->fetchColumn();
        return !empty($pin);
    }

    public function setPin(int $userId, string $pin): bool
    {
        if (strlen($pin) < 4 || strlen($pin) > 8) {
            $this->lastError = t('Le PIN doit contenir entre 4 et 8 chiffres.', 'PIN должен содержать от 4 до 8 цифр.');
            return false;
        }
        $hash = password_hash($pin, PASSWORD_BCRYPT);
        db()->prepare("UPDATE users SET coffre_pin = ? WHERE id = ?")->execute([$hash, $userId]);
        $this->log($userId, 'pin_set', null, 'PIN coffre-fort défini');
        return true;
    }

    public function verifyPin(int $userId, string $pin): array
    {
        $stmt = db()->prepare("SELECT coffre_pin FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($pin, $hash)) {
            $this->log($userId, 'verification_fail', null, 'PIN incorrect');
            return ['success' => false, 'error' => t('PIN incorrect.', 'Неверный PIN.')];
        }

        // Create session
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + COFFRE_SESSION_DURATION);

        db()->prepare("INSERT INTO coffre_sessions (user_id, token, verified, expires_at) VALUES (?, ?, 1, ?)")
            ->execute([$userId, $token, $expires]);

        $_SESSION['coffre_fort_token'] = $token;
        $this->log($userId, 'verification_ok', null, 'Coffre déverrouillé');

        return ['success' => true];
    }

    // ════════════════════════════════════════════════════════════
    // Session Management
    // ════════════════════════════════════════════════════════════

    public function verifierSession(): ?array
    {
        $token = $_SESSION['coffre_fort_token'] ?? '';
        if (!$token) return null;

        $stmt = db()->prepare("SELECT * FROM coffre_sessions WHERE token = ? AND verified = 1 AND expires_at > NOW()");
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) {
            unset($_SESSION['coffre_fort_token']);
            return null;
        }
        return $session;
    }

    public function tempsRestant(): int
    {
        $session = $this->verifierSession();
        if (!$session) return 0;
        return max(0, strtotime($session['expires_at']) - time());
    }

    public function prolongerSession(string $token): void
    {
        $expires = date('Y-m-d H:i:s', time() + COFFRE_SESSION_DURATION);
        db()->prepare("UPDATE coffre_sessions SET expires_at = ? WHERE token = ?")->execute([$expires, $token]);
    }

    public function invaliderSession(string $token): void
    {
        $userId = $_SESSION['user_id'] ?? 0;
        db()->prepare("DELETE FROM coffre_sessions WHERE token = ?")->execute([$token]);
        unset($_SESSION['coffre_fort_token']);
        $this->log($userId, 'session_expire', null, 'Session verrouillée manuellement');
    }

    // ════════════════════════════════════════════════════════════
    // File Operations
    // ════════════════════════════════════════════════════════════

    private const ALLOWED_EXTENSIONS = [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic', 'heif',
        // Videos
        'mp4', 'mov', 'avi', 'mkv', 'webm',
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
        'txt', 'rtf', 'csv',
        // Archives
        'zip', 'rar', '7z',
    ];

    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml',
        'image/heic', 'image/heif',
        'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
        'text/plain', 'text/csv', 'application/rtf',
        'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
    ];

    public function upload(array $file, string $categorie, int $userId, string $description = '', string $tags = ''): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => t('Erreur d\'upload: code ', 'Ошибка загрузки: код ') . $file['error']];
        }

        if ($file['size'] > COFFRE_MAX_FILE_SIZE) {
            return ['success' => false, 'error' => t('Fichier trop volumineux (max 200 Mo).', 'Файл слишком большой (макс. 200 Мб).')];
        }

        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS)) {
            return ['success' => false, 'error' => t('Type de fichier non autorisé', 'Тип файла не разрешён') . ' (' . htmlspecialchars($ext) . ').'];
        }

        // Validate MIME type via finfo (not trusting client)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);
        if (!in_array($realMime, self::ALLOWED_MIMES)) {
            return ['success' => false, 'error' => t('Type MIME non autorisé', 'MIME-тип не разрешён') . ' (' . htmlspecialchars($realMime) . ').'];
        }

        $allowedCategories = ['photo', 'video', 'document', 'contrat', 'identite', 'autre'];
        if (!in_array($categorie, $allowedCategories)) {
            $categorie = 'autre';
        }

        // Read file content
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return ['success' => false, 'error' => t('Impossible de lire le fichier.', 'Не удалось прочитать файл.')];
        }

        // Encrypt
        $iv = random_bytes(16);
        $fileKey = random_bytes(32);
        $encrypted = openssl_encrypt($content, 'aes-256-cbc', $fileKey, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            return ['success' => false, 'error' => t('Erreur de chiffrement.', 'Ошибка шифрования.')];
        }

        // Encrypt the file key with the master key
        $masterIv = substr(hash('sha256', COFFRE_KEY), 0, 16);
        $encryptedKey = openssl_encrypt($fileKey, 'aes-256-cbc', COFFRE_KEY, 0, $masterIv);

        // Save encrypted file
        $nomChiffre = bin2hex(random_bytes(16)) . '.enc';
        $path = COFFRE_STORAGE . '/' . $nomChiffre;

        if (!is_dir(COFFRE_STORAGE)) {
            mkdir(COFFRE_STORAGE, 0700, true);
        }

        if (file_put_contents($path, $encrypted) === false) {
            return ['success' => false, 'error' => t('Impossible d\'écrire le fichier chiffré.', 'Не удалось записать зашифрованный файл.')];
        }

        // Save to DB
        $stmt = db()->prepare("INSERT INTO coffre_fichiers (user_id, nom_original, nom_chiffre, type_mime, taille, categorie, description, tags, iv, file_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $file['name'],
            $nomChiffre,
            $file['type'] ?: 'application/octet-stream',
            $file['size'],
            $categorie,
            $description ?: null,
            $tags ?: null,
            base64_encode($iv),
            $encryptedKey,
        ]);

        $fichierId = db()->lastInsertId();
        $this->log($userId, 'upload', $fichierId, $file['name'] . ' (' . self::formatTaille($file['size']) . ')');

        return ['success' => true, 'id' => $fichierId];
    }

    public function lister(string $categorie = '', string $recherche = ''): array
    {
        $sql = "SELECT * FROM coffre_fichiers WHERE 1=1";
        $params = [];

        if ($categorie) {
            $sql .= " AND categorie = ?";
            $params[] = $categorie;
        }
        if ($recherche) {
            $sql .= " AND (nom_original LIKE ? OR description LIKE ? OR tags LIKE ?)";
            $like = '%' . $recherche . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " ORDER BY created_at DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getFichier(int $id): ?array
    {
        $stmt = db()->prepare("SELECT * FROM coffre_fichiers WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function supprimer(int $fichierId, int $userId): bool
    {
        $fichier = $this->getFichier($fichierId);
        if (!$fichier) return false;

        $path = COFFRE_STORAGE . '/' . $fichier['nom_chiffre'];
        if (file_exists($path)) {
            unlink($path);
        }

        db()->prepare("DELETE FROM coffre_fichiers WHERE id = ?")->execute([$fichierId]);
        $this->log($userId, 'suppression', $fichierId, $fichier['nom_original']);
        return true;
    }

    // ════════════════════════════════════════════════════════════
    // Decryption & Streaming
    // ════════════════════════════════════════════════════════════

    private function decrypt(int $fichierId): ?string
    {
        $fichier = $this->getFichier($fichierId);
        if (!$fichier) {
            $this->lastError = t('Fichier introuvable.', 'Файл не найден.');
            return null;
        }

        $path = COFFRE_STORAGE . '/' . $fichier['nom_chiffre'];
        if (!file_exists($path)) {
            $this->lastError = t('Fichier chiffré introuvable sur le disque.', 'Зашифрованный файл не найден на диске.');
            return null;
        }

        $encrypted = file_get_contents($path);
        $iv = base64_decode($fichier['iv']);

        // Decrypt the file key
        $masterIv = substr(hash('sha256', COFFRE_KEY), 0, 16);
        $fileKey = openssl_decrypt($fichier['file_key'], 'aes-256-cbc', COFFRE_KEY, 0, $masterIv);

        if ($fileKey === false) {
            $this->lastError = t('Impossible de déchiffrer la clé du fichier.', 'Невозможно расшифровать ключ файла.');
            return null;
        }

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $fileKey, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            $this->lastError = t('Impossible de déchiffrer le fichier.', 'Невозможно расшифровать файл.');
            return null;
        }

        return $decrypted;
    }

    public function streamImageBase64(int $fichierId, int $userId): ?string
    {
        $fichier = $this->getFichier($fichierId);
        if (!$fichier) return null;

        $data = $this->decrypt($fichierId);
        if ($data === null) return null;

        $this->log($userId, 'consultation', $fichierId, $fichier['nom_original']);
        return 'data:' . $fichier['type_mime'] . ';base64,' . base64_encode($data);
    }

    public function streamVideo(int $fichierId, int $userId): void
    {
        $fichier = $this->getFichier($fichierId);
        if (!$fichier) return;

        $data = $this->decrypt($fichierId);
        if ($data === null) return;

        $this->log($userId, 'consultation', $fichierId, $fichier['nom_original']);

        header('Content-Type: ' . $fichier['type_mime']);
        header('Content-Length: ' . strlen($data));
        header('Accept-Ranges: none');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $data;
    }

    public function streamDocument(int $fichierId, int $userId): void
    {
        $fichier = $this->getFichier($fichierId);
        if (!$fichier) return;

        $data = $this->decrypt($fichierId);
        if ($data === null) return;

        $this->log($userId, 'consultation', $fichierId, $fichier['nom_original']);

        header('Content-Type: ' . $fichier['type_mime']);
        header('Content-Length: ' . strlen($data));
        header('Content-Disposition: inline; filename="' . $fichier['nom_original'] . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $data;
    }

    // ════════════════════════════════════════════════════════════
    // Stats & Logs
    // ════════════════════════════════════════════════════════════

    public function getStats(): array
    {
        $total = db()->query("SELECT COUNT(*) FROM coffre_fichiers")->fetchColumn();
        $taille = db()->query("SELECT COALESCE(SUM(taille), 0) FROM coffre_fichiers")->fetchColumn();
        $parCat = [];
        $rows = db()->query("SELECT categorie, COUNT(*) as nb FROM coffre_fichiers GROUP BY categorie")->fetchAll();
        foreach ($rows as $r) {
            $parCat[$r['categorie']] = (int)$r['nb'];
        }
        return [
            'total_fichiers' => (int)$total,
            'taille_totale' => (int)$taille,
            'par_categorie' => $parCat,
        ];
    }

    public function getLogs(int $limit = 30): array
    {
        $stmt = db()->prepare("
            SELECT l.*, u.display_name as user_nom, f.nom_original as fichier_nom
            FROM coffre_logs l
            JOIN users u ON u.id = l.user_id
            LEFT JOIN coffre_fichiers f ON f.id = l.fichier_id
            ORDER BY l.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    private function log(int $userId, string $action, ?int $fichierId = null, ?string $details = null): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        db()->prepare("INSERT INTO coffre_logs (user_id, action, fichier_id, details, ip_address) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, $action, $fichierId, $details, $ip]);
    }

    // ════════════════════════════════════════════════════════════
    // Helpers
    // ════════════════════════════════════════════════════════════

    public static function formatTaille(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' Go';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' Mo';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' Ko';
        return $bytes . ' o';
    }

    public function cleanExpiredSessions(): void
    {
        db()->exec("DELETE FROM coffre_sessions WHERE expires_at < NOW()");
    }
}

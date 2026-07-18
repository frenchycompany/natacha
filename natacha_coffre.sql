-- ============================================
-- NATACHA — Coffre-Fort Numérique
-- mysql --default-character-set=utf8mb4 -u root -p natacha < natacha_coffre.sql
-- ============================================

USE natacha;

-- PIN de sécurité pour le coffre-fort (ajout colonne users)
ALTER TABLE users ADD COLUMN IF NOT EXISTS coffre_pin VARCHAR(255) DEFAULT NULL;

-- ── Sessions coffre-fort ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS coffre_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token       VARCHAR(64) NOT NULL UNIQUE,
    verified    TINYINT(1) DEFAULT 0,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Fichiers chiffrés ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS coffre_fichiers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    nom_original  VARCHAR(255) NOT NULL,
    nom_chiffre   VARCHAR(255) NOT NULL,
    type_mime     VARCHAR(100) NOT NULL,
    taille        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    categorie     ENUM('photo','video','document','contrat','identite','autre') DEFAULT 'autre',
    description   TEXT,
    tags          VARCHAR(500),
    iv            VARCHAR(48) NOT NULL,
    file_key      VARCHAR(128) NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_cat (categorie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Journal d'accès ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS coffre_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    action      VARCHAR(50) NOT NULL,
    fichier_id  INT UNSIGNED DEFAULT NULL,
    details     TEXT,
    ip_address  VARCHAR(45),
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

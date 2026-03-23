-- ============================================
-- NATACHA — Schéma base de données
-- Exécuter : mysql -u root -p < natacha.sql
-- ============================================

CREATE DATABASE IF NOT EXISTS natacha CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE natacha;

-- Utilisateur dédié (remplace MOT_DE_PASSE_ICI)
CREATE USER IF NOT EXISTS 'natacha_user'@'localhost' IDENTIFIED BY 'MOT_DE_PASSE_ICI';
GRANT SELECT, INSERT, UPDATE ON natacha.* TO 'natacha_user'@'localhost';
FLUSH PRIVILEGES;

-- Table des sessions (une ligne = une soumission)
CREATE TABLE IF NOT EXISTS submissions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submitted_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    lang          ENUM('fr','ru') DEFAULT 'fr',
    ip            VARCHAR(45),
    user_agent    TEXT,
    mail_sent     TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table des réponses (25 lignes par soumission)
CREATE TABLE IF NOT EXISTS answers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id   INT UNSIGNED NOT NULL,
    question_index  TINYINT UNSIGNED NOT NULL,  -- 0 à 24
    answer_index    TINYINT UNSIGNED NOT NULL,  -- 0 à 3 (A/B/C/D)
    FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vue pratique pour lire les réponses facilement
CREATE OR REPLACE VIEW v_submissions AS
SELECT
    s.id,
    s.submitted_at,
    s.lang,
    s.ip,
    s.mail_sent,
    COUNT(a.id) AS nb_answers
FROM submissions s
LEFT JOIN answers a ON a.submission_id = s.id
GROUP BY s.id
ORDER BY s.submitted_at DESC;

-- ============================================
-- ADMIN — table de compte admin
-- Mot de passe : à hasher avec PHP password_hash()
-- ============================================
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insérer un admin (remplace le hash par : php -r "echo password_hash('TON_MDP', PASSWORD_BCRYPT);")
-- INSERT INTO admin_users (username, password_hash) VALUES ('raphael', '$2y$12$HASH_ICI');

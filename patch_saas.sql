-- ============================================
-- NATACHA — Patch SaaS pour sessions existantes
-- Ajoute couple_id aux users + crée le couple
-- À exécuter une seule fois
-- ============================================

USE natacha;

-- 1. Ajouter les colonnes manquantes à users (si pas déjà fait)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL AFTER username,
    ADD COLUMN IF NOT EXISTS couple_id INT UNSIGNED DEFAULT NULL AFTER lang,
    ADD COLUMN IF NOT EXISTS role ENUM('creator','partner') DEFAULT 'creator' AFTER couple_id,
    ADD COLUMN IF NOT EXISTS onboarding_step TINYINT UNSIGNED DEFAULT 0 AFTER role,
    ADD COLUMN IF NOT EXISTS coffre_pin VARCHAR(255) DEFAULT NULL;

-- 2. Créer la table couples si elle n'existe pas
CREATE TABLE IF NOT EXISTS couples (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    birth_date      DATE NOT NULL,
    personality     VARCHAR(50) DEFAULT NULL,
    gauge_communication  TINYINT UNSIGNED DEFAULT 50,
    gauge_adventure      TINYINT UNSIGNED DEFAULT 50,
    gauge_tenderness     TINYINT UNSIGNED DEFAULT 50,
    gauge_surprise       TINYINT UNSIGNED DEFAULT 50,
    gauge_complicity     TINYINT UNSIGNED DEFAULT 50,
    level           TINYINT UNSIGNED DEFAULT 1,
    xp              INT UNSIGNED DEFAULT 0,
    mood            ENUM('radiant','happy','serene','tired','sad','sick') DEFAULT 'happy',
    invite_code     VARCHAR(32) UNIQUE NOT NULL,
    invite_accepted TINYINT(1) DEFAULT 1,
    plan            ENUM('free','premium') DEFAULT 'free',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Créer les tables de support SaaS
CREATE TABLE IF NOT EXISTS couple_activities (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    activity_type   VARCHAR(50) NOT NULL,
    xp_earned       SMALLINT UNSIGNED DEFAULT 10,
    gauge_impacts   JSON DEFAULT NULL,
    description_fr  VARCHAR(255) DEFAULT NULL,
    description_en  VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_couple_date (couple_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gauge_decay_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id       INT UNSIGNED NOT NULL,
    decayed_at      DATE NOT NULL,
    UNIQUE KEY uk_couple_date (couple_id, decayed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS couple_levels (
    level           TINYINT UNSIGNED PRIMARY KEY,
    name_fr         VARCHAR(50) NOT NULL,
    name_en         VARCHAR(50) NOT NULL,
    emoji           VARCHAR(10) NOT NULL,
    min_days        INT UNSIGNED NOT NULL,
    min_xp          INT UNSIGNED NOT NULL,
    min_avg_gauge   TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO couple_levels (level, name_fr, name_en, emoji, min_days, min_xp, min_avg_gauge) VALUES
(1, 'Étincelle',  'Spark',    '🌱', 0,    0,    0),
(2, 'Flamme',     'Flame',    '🔥', 90,   500,  40),
(3, 'Racines',    'Roots',    '🌿', 180,  1500, 50),
(4, 'Arbre',      'Tree',     '🌳', 365,  4000, 60),
(5, 'Forêt',      'Forest',   '🌲', 730,  10000,70),
(6, 'Légende',    'Legend',    '⭐', 1825, 25000,80)
ON DUPLICATE KEY UPDATE name_fr=VALUES(name_fr);

CREATE TABLE IF NOT EXISTS couple_personalities (
    code            VARCHAR(50) PRIMARY KEY,
    name_fr         VARCHAR(100) NOT NULL,
    name_en         VARCHAR(100) NOT NULL,
    description_fr  TEXT NOT NULL,
    description_en  TEXT NOT NULL,
    emoji           VARCHAR(10) NOT NULL,
    initial_gauges  JSON NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO couple_personalities (code, name_fr, name_en, description_fr, description_en, emoji, initial_gauges) VALUES
('adventurer', 'Aventurier Passionné', 'Passionate Adventurer',
 'Votre couple vit d''expériences et de découvertes.',
 'Your couple thrives on experiences and discoveries.',
 '🧭', '{"communication":45,"adventure":70,"tenderness":40,"surprise":60,"complicity":50}'),
('romantic', 'Romantique Rêveur', 'Dreamy Romantic',
 'Votre couple est fait de douceur et d''attentions.',
 'Your couple is built on sweetness and attention.',
 '🌹', '{"communication":55,"adventure":35,"tenderness":75,"surprise":50,"complicity":55}'),
('complice', 'Complice Fusionnel', 'Soulmate Connection',
 'Votre couple fonctionne comme un seul être.',
 'Your couple functions as one being.',
 '🔗', '{"communication":60,"adventure":40,"tenderness":55,"surprise":40,"complicity":75}'),
('creative', 'Créatif Électrique', 'Electric Creative',
 'Votre couple est imprévisible et stimulant.',
 'Your couple is unpredictable and stimulating.',
 '⚡', '{"communication":50,"adventure":55,"tenderness":40,"surprise":70,"complicity":50}'),
('sage', 'Sage Profond', 'Deep Sage',
 'Votre couple est ancré et réfléchi.',
 'Your couple is grounded and thoughtful.',
 '🧘', '{"communication":70,"adventure":30,"tenderness":55,"surprise":35,"complicity":65}')
ON DUPLICATE KEY UPDATE name_fr=VALUES(name_fr);

-- 4. Créer le couple s'il n'existe pas encore, et lier TOUS les users
INSERT INTO couples (id, name, birth_date, personality, invite_code, invite_accepted)
SELECT 1, 'Natacha', '2024-07-14', 'romantic', MD5(RAND()), 1
FROM dual WHERE NOT EXISTS (SELECT 1 FROM couples WHERE id = 1);

-- 5. Lier tous les users existants au couple 1
UPDATE users SET couple_id = 1 WHERE couple_id IS NULL;

-- 6. Tables des 3 journaux
CREATE TABLE IF NOT EXISTS gratitude_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    entry_date DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_date (user_id, entry_date),
    INDEX idx_couple_date (couple_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS couple_dreams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    category ENUM('voyage','experience','goal_week','goal_month','goal_year') NOT NULL,
    content TEXT NOT NULL,
    is_done TINYINT(1) DEFAULT 0,
    done_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_couple_cat (couple_id, category, is_done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS livre_desirs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    mood ENUM('doux','intense','fou','secret') DEFAULT 'doux',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_couple_date (couple_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

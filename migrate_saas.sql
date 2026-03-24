-- ============================================
-- NATACHA SaaS — Migration multi-tenant
-- Couple comme entité vivante
-- ============================================

USE natacha;

-- ── Couples (l'entité centrale) ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS couples (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL COMMENT 'Prénom du couple',
    birth_date      DATE NOT NULL COMMENT 'Date de naissance du couple',
    personality     VARCHAR(50) DEFAULT NULL COMMENT 'Type de personnalité issu du quiz',

    -- Jauges (0-100)
    gauge_communication  TINYINT UNSIGNED DEFAULT 50,
    gauge_adventure      TINYINT UNSIGNED DEFAULT 50,
    gauge_tenderness     TINYINT UNSIGNED DEFAULT 50,
    gauge_surprise       TINYINT UNSIGNED DEFAULT 50,
    gauge_complicity     TINYINT UNSIGNED DEFAULT 50,

    -- Évolution
    level           TINYINT UNSIGNED DEFAULT 1 COMMENT '1=étincelle 2=flamme 3=racines 4=arbre 5=forêt 6=légende',
    xp              INT UNSIGNED DEFAULT 0,
    mood            ENUM('radiant','happy','serene','tired','sad','sick') DEFAULT 'happy',

    -- Invitation
    invite_code     VARCHAR(32) UNIQUE NOT NULL,
    invite_accepted TINYINT(1) DEFAULT 0,

    -- Meta
    plan            ENUM('free','premium') DEFAULT 'free',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Étendre users pour le multi-tenant ──────────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL AFTER username,
    ADD COLUMN IF NOT EXISTS couple_id INT UNSIGNED DEFAULT NULL AFTER email,
    ADD COLUMN IF NOT EXISTS role ENUM('creator','partner') DEFAULT 'creator' AFTER couple_id,
    ADD COLUMN IF NOT EXISTS onboarding_step TINYINT UNSIGNED DEFAULT 0 AFTER role,
    ADD COLUMN IF NOT EXISTS coffre_pin VARCHAR(255) DEFAULT NULL;

-- Index pour rechercher par couple
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_couple (couple_id);

-- ── Quiz d'onboarding ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS onboarding_quiz (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    question_index  TINYINT UNSIGNED NOT NULL,
    answer_index    TINYINT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Activités du couple (nourrit les jauges) ────────────────────────────────
CREATE TABLE IF NOT EXISTS couple_activities (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    activity_type   VARCHAR(50) NOT NULL COMMENT 'histoire, defi, jeu, lieu, mot, photo, calendrier, musique',
    xp_earned       SMALLINT UNSIGNED DEFAULT 10,
    gauge_impacts   JSON DEFAULT NULL COMMENT '{"communication":5,"adventure":0,"tenderness":10,"surprise":0,"complicity":5}',
    description_fr  VARCHAR(255) DEFAULT NULL,
    description_en  VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (couple_id) REFERENCES couples(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_couple_date (couple_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Gauge decay log (pour la dégradation naturelle des jauges) ──────────────
CREATE TABLE IF NOT EXISTS gauge_decay_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id       INT UNSIGNED NOT NULL,
    decayed_at      DATE NOT NULL,
    UNIQUE KEY uk_couple_date (couple_id, decayed_at),
    FOREIGN KEY (couple_id) REFERENCES couples(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Niveaux d'évolution ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS couple_levels (
    level           TINYINT UNSIGNED PRIMARY KEY,
    name_fr         VARCHAR(50) NOT NULL,
    name_en         VARCHAR(50) NOT NULL,
    emoji           VARCHAR(10) NOT NULL,
    min_days        INT UNSIGNED NOT NULL COMMENT 'Jours minimum pour atteindre ce niveau',
    min_xp          INT UNSIGNED NOT NULL COMMENT 'XP minimum',
    min_avg_gauge   TINYINT UNSIGNED NOT NULL COMMENT 'Moyenne de jauges minimum'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO couple_levels (level, name_fr, name_en, emoji, min_days, min_xp, min_avg_gauge) VALUES
(1, 'Étincelle',  'Spark',    '🌱', 0,    0,    0),
(2, 'Flamme',     'Flame',    '🔥', 90,   500,  40),
(3, 'Racines',    'Roots',    '🌿', 180,  1500, 50),
(4, 'Arbre',      'Tree',     '🌳', 365,  4000, 60),
(5, 'Forêt',      'Forest',   '🌲', 730,  10000,70),
(6, 'Légende',    'Legend',    '⭐', 1825, 25000,80)
ON DUPLICATE KEY UPDATE name_fr=VALUES(name_fr);

-- ── Personnalités de couple (résultat du quiz) ─────────────────────────────
CREATE TABLE IF NOT EXISTS couple_personalities (
    code            VARCHAR(50) PRIMARY KEY,
    name_fr         VARCHAR(100) NOT NULL,
    name_en         VARCHAR(100) NOT NULL,
    description_fr  TEXT NOT NULL,
    description_en  TEXT NOT NULL,
    emoji           VARCHAR(10) NOT NULL,
    initial_gauges  JSON NOT NULL COMMENT 'Jauges initiales basées sur la personnalité'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO couple_personalities (code, name_fr, name_en, description_fr, description_en, emoji, initial_gauges) VALUES
('adventurer', 'Aventurier Passionné', 'Passionate Adventurer',
 'Votre couple vit d''expériences et de découvertes. Vous avez besoin de nouveauté pour vibrer ensemble.',
 'Your couple thrives on experiences and discoveries. You need novelty to feel alive together.',
 '🧭', '{"communication":45,"adventure":70,"tenderness":40,"surprise":60,"complicity":50}'),

('romantic', 'Romantique Rêveur', 'Dreamy Romantic',
 'Votre couple est fait de douceur et d''attentions. Les petits gestes comptent plus que les grandes aventures.',
 'Your couple is built on sweetness and attention. Small gestures matter more than grand adventures.',
 '🌹', '{"communication":55,"adventure":35,"tenderness":75,"surprise":50,"complicity":55}'),

('complice', 'Complice Fusionnel', 'Soulmate Connection',
 'Votre couple fonctionne comme un seul être. Vous vous comprenez sans parler, vous vibrez ensemble.',
 'Your couple functions as one being. You understand each other without words.',
 '🔗', '{"communication":60,"adventure":40,"tenderness":55,"surprise":40,"complicity":75}'),

('creative', 'Créatif Électrique', 'Electric Creative',
 'Votre couple est imprévisible et stimulant. Vous vous surprenez, vous vous challengez, vous créez ensemble.',
 'Your couple is unpredictable and stimulating. You surprise each other, challenge each other, create together.',
 '⚡', '{"communication":50,"adventure":55,"tenderness":40,"surprise":70,"complicity":50}'),

('sage', 'Sage Profond', 'Deep Sage',
 'Votre couple est ancré et réfléchi. Vous construisez sur des bases solides avec patience et sagesse.',
 'Your couple is grounded and thoughtful. You build on solid foundations with patience and wisdom.',
 '🧘', '{"communication":70,"adventure":30,"tenderness":55,"surprise":35,"complicity":65}')
ON DUPLICATE KEY UPDATE name_fr=VALUES(name_fr);

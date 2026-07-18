-- ============================================
-- NATACHA — Migration journaux intimes du couple
-- Gratitude + Rêves/Projets + Livre des Désirs
-- ============================================

USE natacha;

-- ── Journal de gratitude ──
CREATE TABLE IF NOT EXISTS gratitude_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    content     TEXT NOT NULL,
    entry_date  DATE NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_date (user_id, entry_date),
    INDEX idx_couple_date (couple_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Rêves & Projets ──
CREATE TABLE IF NOT EXISTS couple_dreams (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    category    ENUM('voyage','experience','goal_week','goal_month','goal_year') NOT NULL,
    content     TEXT NOT NULL,
    is_done     TINYINT(1) DEFAULT 0,
    done_at     DATETIME DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_couple_cat (couple_id, category, is_done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Livre des Désirs (jardin secret) ──
CREATE TABLE IF NOT EXISTS livre_desirs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    content     TEXT NOT NULL,
    mood        ENUM('doux','intense','fou','secret') DEFAULT 'doux',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_couple_date (couple_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- NATACHA — Migration jeu "Qui est notre couple ?"
-- ============================================

USE natacha;

CREATE TABLE IF NOT EXISTS couple_quiz_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    couple_id       INT UNSIGNED NOT NULL,
    status          ENUM('open','complete') DEFAULT 'open',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME DEFAULT NULL,
    -- Résultat fusionné
    result_personality VARCHAR(50) DEFAULT NULL,
    result_gauges      JSON DEFAULT NULL,
    FOREIGN KEY (couple_id) REFERENCES couples(id) ON DELETE CASCADE,
    INDEX idx_couple_status (couple_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS couple_quiz_answers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    question_index  TINYINT UNSIGNED NOT NULL,
    answer_index    TINYINT UNSIGNED NOT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES couple_quiz_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_session_user_q (session_id, user_id, question_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

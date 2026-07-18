-- Migration v2: Add guesses table for "Qui me connait le mieux" game
-- The reponses table stays as-is (is_correct/validated_by columns unused but harmless)

CREATE TABLE IF NOT EXISTS jeux_connaissance_guesses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL COMMENT 'The person guessing',
    guess TEXT NOT NULL,
    target_user_id INT UNSIGNED NOT NULL COMMENT 'Whose answer they are guessing',
    is_correct TINYINT(1) DEFAULT NULL COMMENT 'NULL=pending, set by the target user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES jeux_connaissance(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

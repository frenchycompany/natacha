-- Migration: Calendar / Important Dates
-- Run: mysql -u root -p natacha < migrate_calendrier.sql

CREATE TABLE IF NOT EXISTS calendrier_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    titre_fr VARCHAR(255) NOT NULL,
    titre_ru VARCHAR(255) NOT NULL DEFAULT '',
    description_fr TEXT,
    description_ru TEXT,
    date_event DATE NOT NULL,
    recurrent BOOLEAN NOT NULL DEFAULT FALSE,
    categorie ENUM('anniversaire','voyage','souvenir','rdv','autre') NOT NULL DEFAULT 'autre',
    couleur VARCHAR(7) NOT NULL DEFAULT '#c9a96e',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_date_event (date_event),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample events
INSERT INTO calendrier_events (user_id, titre_fr, titre_ru, description_fr, description_ru, date_event, recurrent, categorie, couleur) VALUES
(1, 'Notre rencontre', 'Наша встреча', 'Le jour o\u00f9 tout a commenc\u00e9.', 'День, когда всё началось.', '2024-07-14', TRUE, 'anniversaire', '#c9a96e'),
(1, 'Premier voyage ensemble', 'Первое путешествие вместе', 'Notre premi\u00e8re aventure \u00e0 deux.', 'Наше первое приключение вдвоём.', '2024-09-15', FALSE, 'souvenir', '#c96e9a');

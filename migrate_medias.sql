-- Migration: Music + Films tables
-- Run: mysql -u root -p natacha < migrate_medias.sql

CREATE TABLE IF NOT EXISTS musiques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    titre VARCHAR(255) NOT NULL,
    artiste VARCHAR(255),
    deezer_url VARCHAR(500),
    commentaire_fr TEXT,
    commentaire_ru TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS films (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    titre VARCHAR(255) NOT NULL,
    annee YEAR,
    statut ENUM('vu','a_voir') DEFAULT 'a_voir',
    note INT DEFAULT NULL,
    commentaire_fr TEXT,
    commentaire_ru TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample music data
INSERT INTO musiques (user_id, titre, artiste, deezer_url, commentaire_fr, commentaire_ru) VALUES
(1, 'La Vie en Rose', 'Édith Piaf', 'https://www.deezer.com/track/905432', 'Notre chanson classique, un hymne à l''amour éternel.', 'Наша классическая песня, гимн вечной любви.'),
(2, 'Je te promets', 'Johnny Hallyday', 'https://www.deezer.com/track/68440942', 'Une promesse en musique, pour toujours.', 'Обещание в музыке, навсегда.'),
(1, 'Любовь', 'Alekseev', 'https://www.deezer.com/track/504820622', 'Une belle chanson russe sur l''amour.', 'Красивая песня о любви.');

-- Sample films data
INSERT INTO films (user_id, titre, annee, statut, note, commentaire_fr, commentaire_ru) VALUES
(1, 'Amélie', 2001, 'vu', 5, 'Un chef-d''œuvre poétique et romantique.', 'Поэтический и романтический шедевр.'),
(2, 'Москва слезам не верит', 1980, 'a_voir', NULL, 'Un classique du cinéma soviétique à découvrir ensemble.', 'Классика советского кино, которую нужно посмотреть вместе.'),
(1, 'Intouchables', 2011, 'a_voir', NULL, 'On nous l''a recommandé, à voir bientôt !', 'Нам посоветовали, скоро посмотрим!');

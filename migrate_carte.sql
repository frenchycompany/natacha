CREATE TABLE IF NOT EXISTS lieux (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    nom_fr VARCHAR(255) NOT NULL,
    nom_ru VARCHAR(255),
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    description_fr TEXT,
    description_ru TEXT,
    date_visite DATE,
    categorie ENUM('ville','restaurant','nature','monument','plage','autre') DEFAULT 'autre',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO lieux (user_id, nom_fr, nom_ru, latitude, longitude, description_fr, description_ru, date_visite, categorie) VALUES
(1, 'Paris', 'Париж', 48.85660000, 2.35220000, 'La ville lumière, notre premier voyage ensemble.', 'Город света, наше первое совместное путешествие.', '2024-07-14', 'ville'),
(1, 'Tour Eiffel', 'Эйфелева башня', 48.85840000, 2.29450000, 'Vue magnifique depuis le sommet.', 'Великолепный вид с вершины.', '2024-07-15', 'monument'),
(1, 'Moscou', 'Москва', 55.75580000, 37.61730000, 'La capitale russe, pleine de surprises.', 'Российская столица, полная сюрпризов.', '2024-12-20', 'ville');

CREATE TABLE IF NOT EXISTS jeux_connaissance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_fr TEXT NOT NULL,
    question_ru TEXT,
    categorie ENUM('preferences','souvenirs','personnalite','reves') DEFAULT 'preferences',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jeux_connaissance_reponses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reponse TEXT NOT NULL,
    is_correct TINYINT(1) DEFAULT NULL,
    validated_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES jeux_connaissance(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data: 15 fun couple questions in FR + RU
INSERT INTO jeux_connaissance (question_fr, question_ru, categorie) VALUES
('Quelle est ma couleur préférée ?', 'Какой мой любимый цвет?', 'preferences'),
('Quel est mon plat réconfortant préféré ?', 'Какое моё любимое утешительное блюдо?', 'preferences'),
('Quel est mon endroit préféré dans le monde ?', 'Какое моё любимое место в мире?', 'preferences'),
('Quel est notre meilleur souvenir ensemble ?', 'Какое наше лучшее общее воспоминание?', 'souvenirs'),
('Où nous sommes-nous embrassés pour la première fois ?', 'Где мы впервые поцеловались?', 'souvenirs'),
('Quel est le premier truc que tu as remarqué chez moi ?', 'Что ты первым заметил(а) во мне?', 'souvenirs'),
('Quel est mon plus grand rêve pour nous deux ?', 'Какая моя самая большая мечта для нас двоих?', 'reves'),
('Où est-ce que je rêve de vivre un jour ?', 'Где я мечтаю когда-нибудь жить?', 'reves'),
('Si je pouvais changer de métier demain, je ferais quoi ?', 'Если бы я мог(ла) сменить профессию завтра, что бы я делал(а)?', 'reves'),
('Qu''est-ce qui me met de bonne humeur instantanément ?', 'Что мгновенно поднимает мне настроение?', 'personnalite'),
('Quel est mon défaut qui t''attendrit le plus ?', 'Какой мой недостаток тебя больше всего умиляет?', 'personnalite'),
('De quoi ai-je le plus peur ?', 'Чего я боюсь больше всего?', 'personnalite'),
('Quelle chanson me fait toujours danser ?', 'Какая песня всегда заставляет меня танцевать?', 'preferences'),
('Quel moment de notre histoire te fait encore sourire ?', 'Какой момент нашей истории до сих пор заставляет тебя улыбаться?', 'souvenirs'),
('Quel super-pouvoir je choisirais ?', 'Какую суперспособность я бы выбрал(а)?', 'reves');

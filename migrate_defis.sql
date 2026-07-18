-- Migration: Défis du Jour (Daily Couple Challenges)
-- Run: mysql -u root -p natacha < migrate_defis.sql

CREATE TABLE IF NOT EXISTS defis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contenu_fr TEXT NOT NULL,
    contenu_ru TEXT NOT NULL,
    categorie ENUM('romantique','aventure','cuisine','creativite','communication') DEFAULT 'romantique',
    difficulte TINYINT DEFAULT 1 COMMENT '1=facile, 2=moyen, 3=difficile',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS defis_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    defi_id INT NOT NULL,
    date_defi DATE NOT NULL COMMENT 'the day this challenge was assigned',
    complete_user1 BOOLEAN DEFAULT FALSE,
    complete_user2 BOOLEAN DEFAULT FALSE,
    commentaire_fr TEXT,
    commentaire_ru TEXT,
    completed_at TIMESTAMP NULL,
    UNIQUE KEY unique_date (date_defi),
    KEY idx_defi_id (defi_id),
    CONSTRAINT fk_defis_log_defi FOREIGN KEY (defi_id) REFERENCES defis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════
-- 35 bilingual challenges across all categories
-- ═══════════════════════════════════════════════════════════

INSERT INTO defis (contenu_fr, contenu_ru, categorie, difficulte) VALUES

-- Romantique (8)
('Écrire une lettre d''amour à l''autre', 'Написать любовное письмо другому', 'romantique', 1),
('Regarder le coucher de soleil ensemble', 'Смотреть закат вместе', 'romantique', 1),
('Se faire un compliment toutes les heures', 'Делать комплимент каждый час', 'romantique', 2),
('Danser ensemble sur votre chanson', 'Танцевать вместе под вашу песню', 'romantique', 1),
('Préparer un petit-déjeuner au lit pour l''autre', 'Приготовить завтрак в постель для другого', 'romantique', 2),
('Écrire 10 choses que vous aimez chez l''autre', 'Написать 10 вещей, которые вы любите в другом', 'romantique', 1),
('Se regarder dans les yeux pendant 4 minutes sans parler', 'Смотреть друг другу в глаза 4 минуты молча', 'romantique', 2),
('Revivre votre premier rendez-vous', 'Воссоздать ваше первое свидание', 'romantique', 3),

-- Aventure (7)
('Explorer un quartier inconnu de la ville', 'Исследовать незнакомый район города', 'aventure', 2),
('Faire une promenade sans destination précise', 'Пойти на прогулку без определённой цели', 'aventure', 1),
('Visiter un musée ou une exposition ensemble', 'Посетить музей или выставку вместе', 'aventure', 2),
('Prendre des photos l''un de l''autre dans des endroits insolites', 'Фотографировать друг друга в необычных местах', 'aventure', 2),
('Essayer un sport ou une activité que vous n''avez jamais fait', 'Попробовать спорт или занятие, которое вы никогда не делали', 'aventure', 3),
('Planifier ensemble un voyage de rêve', 'Спланировать вместе путешествие мечты', 'aventure', 1),
('Faire un pique-nique dans un parc', 'Устроить пикник в парке', 'aventure', 1),

-- Cuisine (7)
('Cuisiner ensemble un plat de l''autre pays', 'Приготовить вместе блюдо из другой страны', 'cuisine', 2),
('Préparer un dessert ensemble', 'Приготовить десерт вместе', 'cuisine', 2),
('Inventer une nouvelle recette ensemble', 'Придумать новый рецепт вместе', 'cuisine', 3),
('Faire un dîner aux chandelles à la maison', 'Устроить ужин при свечах дома', 'cuisine', 2),
('Préparer un cocktail ou une boisson spéciale ensemble', 'Приготовить коктейль или особый напиток вместе', 'cuisine', 1),
('Cuisiner le plat préféré de l''autre', 'Приготовить любимое блюдо другого', 'cuisine', 2),
('Faire des crêpes ou des blinis ensemble', 'Приготовить блины вместе', 'cuisine', 1),

-- Créativité (6)
('Dessiner le portrait de l''autre', 'Нарисовать портрет другого', 'creativite', 2),
('Écrire un poème pour l''autre', 'Написать стихотворение для другого', 'creativite', 2),
('Créer une playlist de chansons qui racontent votre histoire', 'Создать плейлист из песен, рассказывающих вашу историю', 'creativite', 1),
('Prendre un selfie créatif chaque heure', 'Делать креативное селфи каждый час', 'creativite', 2),
('Fabriquer un petit cadeau fait main pour l''autre', 'Сделать маленький подарок своими руками для другого', 'creativite', 3),
('Écrire ensemble une courte histoire de fiction', 'Написать вместе короткую выдуманную историю', 'creativite', 3),

-- Communication (7)
('Poser 20 questions que vous n''avez jamais posées à l''autre', 'Задать 20 вопросов, которые вы никогда не задавали другому', 'communication', 2),
('Partager un souvenir d''enfance chaque heure', 'Делиться воспоминанием из детства каждый час', 'communication', 1),
('Dire merci pour 5 choses précises que l''autre fait au quotidien', 'Сказать спасибо за 5 конкретных вещей, которые другой делает каждый день', 'communication', 1),
('Parler uniquement dans la langue de l''autre pendant une heure', 'Говорить только на языке другого в течение часа', 'communication', 3),
('Raconter votre moment préféré ensemble cette semaine', 'Рассказать ваш любимый совместный момент на этой неделе', 'communication', 1),
('Écrire chacun une lettre décrivant où vous vous voyez dans 5 ans ensemble', 'Написать каждому письмо о том, где вы видите себя вместе через 5 лет', 'communication', 2),
('Apprendre ensemble 10 mots dans la langue de l''autre', 'Выучить вместе 10 слов на языке другого', 'communication', 2);

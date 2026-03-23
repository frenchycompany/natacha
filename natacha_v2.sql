-- ============================================
-- NATACHA — Schéma complet v2
-- mysql -u root -p < natacha_v2.sql
-- ============================================

CREATE DATABASE IF NOT EXISTS natacha CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE natacha;

-- ── Users ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    display_name  VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    lang          ENUM('fr','ru') DEFAULT 'fr',
    avatar        ENUM('R','M') NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- raphael / MaRaNa25!
INSERT IGNORE INTO users (username, display_name, password_hash, lang, avatar)
VALUES ('raphael', 'Raphaël', '$2y$10$Hb0J/OKlSsPGFfcQEMOzRuHyKG.POVuRMj3s0BNPT.W0zf6UFS.s.', 'fr', 'R');

-- marina / MaRaNa25!
INSERT IGNORE INTO users (username, display_name, password_hash, lang, avatar)
VALUES ('marina', 'Marina', '$2y$10$Hb0J/OKlSsPGFfcQEMOzRuHyKG.POVuRMj3s0BNPT.W0zf6UFS.s.', 'ru', 'M');

-- ── Histoire ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS histoire_chapitres (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    titre      VARCHAR(255) NOT NULL,
    contenu    LONGTEXT NOT NULL,
    event_date DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Questionnaires ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS questionnaires (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by  INT UNSIGNED NOT NULL,
    titre       VARCHAR(255) NOT NULL,
    description TEXT,
    lang        ENUM('fr','ru','both') DEFAULT 'both',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS questionnaire_questions (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    questionnaire_id  INT UNSIGNED NOT NULL,
    position          TINYINT UNSIGNED NOT NULL,
    question_fr       TEXT NOT NULL,
    question_ru       TEXT,
    opt_a_fr          VARCHAR(255) NOT NULL,
    opt_b_fr          VARCHAR(255) NOT NULL,
    opt_c_fr          VARCHAR(255),
    opt_d_fr          VARCHAR(255),
    opt_a_ru          VARCHAR(255),
    opt_b_ru          VARCHAR(255),
    opt_c_ru          VARCHAR(255),
    opt_d_ru          VARCHAR(255),
    FOREIGN KEY (questionnaire_id) REFERENCES questionnaires(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS questionnaire_reponses (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    questionnaire_id INT UNSIGNED NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    question_id      INT UNSIGNED NOT NULL,
    answer_index     TINYINT UNSIGNED NOT NULL,
    submitted_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (questionnaire_id) REFERENCES questionnaires(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (question_id) REFERENCES questionnaire_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Jeux ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS jeux_cartes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('action','verite') NOT NULL,
    contenu_fr  TEXT NOT NULL,
    contenu_ru  TEXT,
    niveau      TINYINT DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quelques cartes de départ
INSERT IGNORE INTO jeux_cartes (type, contenu_fr, contenu_ru, niveau) VALUES
('verite','Quel est le moment où tu as le plus pensé à moi ?','Когда ты думал(а) обо мне больше всего?',1),
('verite','Qu''est-ce qui t''attire le plus chez moi ?','Что тебя привлекает во мне больше всего?',1),
('verite','Décris notre premier vrai moment ensemble.','Опиши наш первый настоящий момент вместе.',1),
('verite','Qu''est-ce que tu n''oses pas encore me dire ?','Что ты ещё не решаешься мне сказать?',2),
('verite','Si tu devais choisir un mot pour nous, lequel ce serait ?','Если бы ты выбрал(а) одно слово для нас — какое?',1),
('verite','Quel est ton souvenir préféré avec moi jusqu''ici ?','Какое твоё любимое воспоминание обо мне?',1),
('verite','Qu''est-ce qui te fait peur dans ce qui se passe entre nous ?','Что тебя пугает в том, что происходит между нами?',2),
('verite','Qu''est-ce que tu changerais si tu pouvais remonter le temps ?','Что бы ты изменил(а), если бы мог(ла) вернуться назад?',2),
('action','Envoie-moi un message comme si on se rencontrait pour la première fois.','Напиши мне сообщение, как будто мы встречаемся впервые.',1),
('action','Dis-moi quelque chose que tu ne m''as jamais dit.','Скажи мне что-то, чего никогда не говорил(а).',2),
('action','Décris comment tu imagines notre prochain moment ensemble.','Опиши, каким ты представляешь наш следующий момент вместе.',1),
('action','Envoie-moi une chanson qui te fait penser à nous.','Пришли мне песню, которая напоминает тебе о нас.',1),
('action','Décris-moi en 3 mots ce que je suis pour toi en ce moment.','Опиши меня тремя словами — кто я для тебя сейчас.',1),
('action','Dis-moi ce que tu ressens là, maintenant, en lisant ceci.','Скажи, что ты чувствуешь прямо сейчас, читая это.',2);

-- ── Sessions ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sessions_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    logged_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip         VARCHAR(45),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

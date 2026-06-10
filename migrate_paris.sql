-- Migration : feature « Les Paris » (suivi des paris du couple)
-- À exécuter sur la base `natacha` (HeidiSQL / phpMyAdmin).
-- Idempotent : ne fait rien si la table existe déjà.

CREATE TABLE IF NOT EXISTS paris (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  couple_id         INT UNSIGNED NOT NULL,
  created_by        INT UNSIGNED NOT NULL,
  enonce            VARCHAR(500) NOT NULL,
  enonce_translated VARCHAR(500) DEFAULT NULL,
  enjeu             VARCHAR(300) NOT NULL,
  enjeu_translated  VARCHAR(300) DEFAULT NULL,
  src_lang          CHAR(2) NOT NULL DEFAULT 'fr',
  statut            ENUM('ouvert','resolu') NOT NULL DEFAULT 'ouvert',
  winner_user_id    INT UNSIGNED DEFAULT NULL,
  created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
  resolved_at       DATETIME DEFAULT NULL,
  INDEX idx_couple_statut (couple_id, statut, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

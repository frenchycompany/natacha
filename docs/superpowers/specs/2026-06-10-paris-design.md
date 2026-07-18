# Spec — « Les Paris » (suivi des paris du couple)

Date : 2026-06-10
Projet : Natacha (PWA couple, PHP/MySQL, FR/RU)

## But

Permettre au couple d'enregistrer leurs paris, de désigner le gagnant (Raphaël ou
Marina, d'après les vrais comptes), et de garder un score. Chaque pari a un **énoncé**
(« je parie que… ») et un **enjeu** remporté par le gagnant (texte libre).

## Décisions validées

- **Cycle de vie** : énoncé + enjeu à la création → plus tard on marque « Gagné par <nom> ».
- **Gagnant** : un des 2 membres réels du couple (`users.display_name`), jamais codé en dur.
- **Enjeu** : texte libre (ce que le gagnant remporte).
- **Score** : compteur de paris gagnés par membre (`Raphaël 3 — 2 Marina`).
- **Traduction** : énoncé + enjeu traduits auto FR↔RU et cachés en base (comme `mots_du_jour`).
- **Accès** : carte sur le dashboard, section « Se Divertir ». Barre du bas **inchangée**.
- **Intégration** : notif à l'autre (création + résolution) ; XP/jauges du couple à la résolution.

## Données — table `paris`

```sql
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
```

Fourni dans `migrate_paris.sql`. **Raphaël l'exécute lui-même** (HeidiSQL/phpMyAdmin).
Filet de sécurité : `paris.php` exécute ce même `CREATE TABLE IF NOT EXISTS` au premier
accès (idempotent, non destructif), exactement comme `couple_quick_actions` / `mots_du_jour`
le font déjà dans le repo.

## Page `paris.php`

Même squelette que `couple.php` : `config.php` → `requireLogin()` → `securityHeaders()` →
récup `couple_id` (`$user['couple_id']` avec fallback requête). Esthétique éditoriale
or/noir habituelle. Bottom-nav incluse avant `</body>`.

Sections :
1. **Topbar** : retour Accueil + titre « Les Paris ».
2. **Score** : les 2 membres et leur nombre de paris gagnés (`statut='resolu'` groupé par
   `winner_user_id`).
3. **Lancer un pari** : champ énoncé + champ enjeu → POST `action=create`.
4. **En cours** (`statut='ouvert'`, plus récents d'abord) : énoncé (+ traduction en muted),
   enjeu, et 2 boutons `Gagné par <membreA>` / `Gagné par <membreB>` → POST `action=resolve`.
5. **Terminés** (`statut='resolu'`) : énoncé barré, badge 🏆 du gagnant, enjeu remporté.

### Actions POST (CSRF vérifié, réponse JSON ; rechargement géré côté JS)

- `create` : valide `enonce` (1..500) et `enjeu` (1..300) non vides ; détermine `src_lang`
  = langue de l'auteur ; `translateText()` vers l'autre langue → `*_translated` ;
  INSERT `statut='ouvert'`. Puis `notifyOtherUser(uid,'pari', "<nom> a lancé un pari",
  "<имя> предложил пари", BASE_URL.'/paris.php')`.
- `resolve` : `pari_id` + `winner_user_id` (doit appartenir aux 2 membres du couple ;
  le pari doit être `ouvert` et du bon `couple_id`). UPDATE `statut='resolu'`,
  `winner_user_id`, `resolved_at=NOW()`. Puis `notifyOtherUser(...)` (« X a gagné le pari »)
  et `(new CoupleEntity(db()))->recordActivity($coupleId, $uid, 'pari', $descFr, null)`
  (signature réelle : `recordActivity($coupleId,$userId,$type,$descFr=null,$descEn=null)`).

Toutes les requêtes en PDO préparé, scopées par `couple_id`. Échappement `h()` à l'affichage.

## Modifs d'intégration

- `includes/couple_helper.php` : ajout **additif** d'un type `pari` :
  `XP_REWARDS['pari'=>12]` et `GAUGE_IMPACTS['pari'=>['complicity'=>6,'surprise'=>5]]`.
  (N'altère aucun comportement existant.)
- `dashboard.php` : carte « Les Paris » dans la section « 🎮 Se Divertir », avec compteur
  de paris en cours (`SELECT COUNT(*) ... WHERE couple_id=? AND statut='ouvert'`, en try/catch).

## Fichiers

| Fichier | Type |
|---|---|
| `migrate_paris.sql` | nouveau (SQL fourni, exécuté par Raphaël) |
| `paris.php` | nouveau (page + logique create/resolve/score/traduction) |
| `includes/couple_helper.php` | modifié (type `pari`, additif) |
| `dashboard.php` | modifié (carte Se Divertir + compteur) |

## Hors périmètre (YAGNI)

- Pas de mises/positions par membre à la création (le couple sait qui a raison).
- Pas de réactions ❤️ sur les paris pour l'instant (ajoutable plus tard).
- Pas d'onglet dédié dans la barre du bas.

## Déploiement

1. Raphaël exécute `migrate_paris.sql` sur la base `natacha`.
2. `git pull` sur le VPS + `reset_cache.php` (cache PWA).

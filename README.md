# 💌 Natacha — Guide de déploiement

## Structure des fichiers

```
/var/www/frenchycompany.fr/natacha/
├── index.html        ← page publique (FR/RU)
├── send.php          ← reçoit les réponses → MySQL + mail
├── config.php        ← config DB + constantes (à sécuriser)
├── admin/
│   ├── login.php     ← connexion admin
│   └── index.php     ← tableau de bord des réponses
└── responses/        ← backup JSON (créé auto)
```

---

## 1. Base de données MySQL

```bash
# Se connecter en root
mysql -u root -p

# Exécuter le schéma
source /chemin/vers/natacha.sql;
```

**Créer le compte admin :**
```bash
# Générer le hash du mot de passe (remplace MonMotDePasse)
php -r "echo password_hash('MonMotDePasse', PASSWORD_BCRYPT);"
```

Puis dans MySQL :
```sql
USE natacha;
INSERT INTO admin_users (username, password_hash)
VALUES ('raphael', 'COLLER_LE_HASH_ICI');
```

---

## 2. config.php — à modifier

```php
define('DB_PASS', 'MOT_DE_PASSE_ICI');  // mot de passe MySQL natacha_user
define('MAIL_TO', 'raphael@frenchycompany.fr');
define('MAIL_FROM', 'natacha@frenchycompany.fr');
```

> ⚠️ Pour plus de sécurité, déplacer config.php HORS du webroot :
> `/var/www/config/natacha_config.php`
> et mettre à jour le `require_once` dans send.php et admin/*.php

---

## 3. Upload sur le VPS

```bash
# Depuis ta machine locale
scp -r ./natacha/ user@ton-vps:/var/www/frenchycompany.fr/

# Ou via rsync
rsync -avz ./natacha/ user@ton-vps:/var/www/frenchycompany.fr/natacha/
```

---

## 4. Permissions

```bash
cd /var/www/frenchycompany.fr/natacha
chmod 644 index.html send.php config.php
chmod 644 admin/login.php admin/index.php
chmod 755 responses/
# Empêcher l'accès direct à config.php depuis le web
# Dans ton .htaccess ou nginx :
```

**.htaccess (Apache) :**
```apache
<Files "config.php">
    Order allow,deny
    Deny from all
</Files>
```

**Nginx — dans le bloc server :**
```nginx
location ~ /natacha/config\.php$ {
    deny all;
}
```

---

## 5. Sécuriser l'admin avec .htaccess (optionnel mais recommandé)

```bash
# Restreindre /admin à ton IP
echo "Order Deny,Allow
Deny from all
Allow from TON_IP" > /var/www/frenchycompany.fr/natacha/admin/.htaccess
```

---

## 6. URLs finales

| Page | URL |
|------|-----|
| Questionnaire | `https://frenchycompany.fr/natacha/` |
| Admin login | `https://frenchycompany.fr/natacha/admin/login.php` |
| Admin dashboard | `https://frenchycompany.fr/natacha/admin/index.php` |

---

## 7. Vérifier que le mail fonctionne

Si `mail()` PHP ne fonctionne pas sur ton VPS :
- Installer **msmtp** ou **ssmtp** comme relay
- Ou utiliser **PHPMailer** avec SMTP (Gmail / OVH)
- Vérifier : `php -r "mail('raphael@frenchycompany.fr','test','test');" && echo ok`

---

## Requêtes SQL utiles

```sql
-- Voir toutes les soumissions
SELECT * FROM v_submissions;

-- Voir les réponses d'une soumission (ex: id=1)
SELECT a.question_index, a.answer_index
FROM answers a
WHERE a.submission_id = 1
ORDER BY a.question_index;

-- Compter par langue
SELECT lang, COUNT(*) FROM submissions GROUP BY lang;
```

# Journal de bord

Petit journal de bord dev : PHP 8.1+ et SQLite (PDO), rappels par Web Push, aucune dépendance Composer.

## Fichiers

| Fichier | Rôle |
|---|---|
| `index.html` | Interface (journal et récap de la veille, stats, projets, rappels, données) |
| `api.php` | API JSON : auth, CRUD, stats, exports, import, abonnements push |
| `inc.php` | Socle commun : base SQLite, réglages, Web Push natif (VAPID + chiffrement) |
| `cron.php` | Envoie les rappels, à lancer chaque minute en CLI |
| `config.php` | (à créer depuis `config.example.php`, non versionné) Mot de passe, chemin de la base, fuseau, journée type (`day_minutes`), contact VAPID |
| `sw.js` | Service worker : reçoit les push, gère le clic sur la notification |
| `icon.svg` | Favicon et icône des notifications |
| `.htaccess`, `data/.htaccess` | Bloquent l'accès à `config.php`, `inc.php`, `cron.php` et `data/` (Apache) |

Extensions PHP nécessaires : `pdo_sqlite`, `openssl`, `curl`, `mbstring` (présentes partout ou presque).

## Installation

1. Copier le dossier sur le serveur (ex : `/var/www/journal`).
2. Copier `config.example.php` en `config.php`, générer le hash du mot de passe et le coller dans `config.php` :
   ```bash
   php -r "echo password_hash('mon-mot-de-passe', PASSWORD_DEFAULT), PHP_EOL;"
   ```
3. Mettre ton adresse dans `vapid_subject` (`config.php`), ex : `mailto:moi@domaine.fr`.
4. Donner l'écriture au serveur web sur `data/` (ou sur le dossier choisi dans `db_path`) :
   ```bash
   chown -R www-data:www-data data && chmod 700 data
   ```
5. Ajouter le cron, **avec le même utilisateur que PHP web** (sinon droits sur la base) :
   ```bash
   sudo crontab -u www-data -e
   ```
   ```cron
   * * * * * php /var/www/journal/cron.php
   ```
6. Servir en **HTTPS** (obligatoire pour le Web Push).
7. Se connecter, onglet **Rappels** > « Recevoir les rappels ici », puis « Tester ».

Recommandé : mettre `db_path`, `backup_dir` et `session_dir` hors de la racine web.

### Nginx

Les `.htaccess` ne sont pas lus, ajouter :

```nginx
location ~ ^/journal/(config|inc|cron)\.php$ { deny all; }
location = /journal/README.md { deny all; }
location ^~ /journal/data/ { deny all; }
location = /journal/sw.js { add_header Cache-Control "no-store"; }
```

## Rappels (Web Push)

Fonctionnement : le cron regarde chaque minute si un rappel est dû, puis le serveur envoie
un message chiffré au service de push du navigateur (Google pour Chrome/Vivaldi/Edge,
Mozilla pour Firefox, Apple pour Safari). Le navigateur réveille le service worker, qui
affiche la notification. **Aucun onglet n'a besoin d'être ouvert**, il suffit que le
navigateur tourne (même réduit). Sur Android, ça marche aussi navigateur fermé.

- Rappels réglables dans l'onglet Rappels : heure, jours, message, actif ou non.
- Option « ne pas rappeler si une entrée a été saisie il y a moins de N minutes ».
- Un rappel manqué (cron en retard, serveur redémarré) part encore pendant 10 minutes.
- Un seul envoi par rappel et par jour, même si deux crons se chevauchent.
- Les abonnements expirés sont supprimés automatiquement.
- L'onglet Rappels affiche l'état du cron, les appareils abonnés et les derniers envois.

### VAPID, c'est quoi ?

Une paire de clés qui identifie ton serveur auprès des services de push : la clé publique
est donnée au navigateur lors de l'abonnement, la clé privée signe chaque envoi. Ainsi,
seul ton serveur peut envoyer des notifications à tes abonnements.

**Tu n'as rien à faire** : les clés sont générées automatiquement au premier usage et
stockées dans la base (table `meta`, clé `vapid`). Elles sont conservées lors d'un import
de base. Si tu les supprimes, il suffit de recliquer « Recevoir les rappels ici » sur
chaque appareil (fait aussi automatiquement au prochain chargement de la page).

### Tester depuis le serveur

```bash
sudo -u www-data php /var/www/journal/cron.php --test   # envoie un push à tous les appareils
sudo -u www-data php /var/www/journal/cron.php -v       # affiche ce que le cron décide
```

### Dépannage

- « Le cron n'a jamais tourné » : la crontab n'est pas en place ou tourne avec un autre utilisateur.
- Rien ne s'affiche mais l'envoi est OK : vérifier les notifications du site dans le navigateur
  et celles du système (mode Ne pas déranger, réglages de notifications de Windows/macOS).
- Vivaldi : l'économiseur de mémoire n'a plus d'effet, ce n'est plus l'onglet qui envoie.
- iPhone : le Web Push ne marche que si le site est ajouté à l'écran d'accueil (iOS 16.4+).

## Sécurité

- Mot de passe unique (hash dans `config.php`), session 30 jours, cookie `HttpOnly` + `SameSite=Strict`.
- Jeton CSRF sur toutes les écritures.
- `cron.php` refuse de s'exécuter hors CLI.
- Console SQL en lecture seule (`SELECT` / `WITH` + `PRAGMA query_only`).
- Sauvegarde automatique dans `backup_dir` avant chaque import ou réinitialisation.

## Sauvegarde cron (optionnel)

```cron
15 3 * * * sqlite3 /chemin/data/journal.sqlite ".backup '/chemin/backups/journal-$(date +\%F).sqlite'"
```

## Reprendre les données de la version locale

Dans la version mono-fichier : Données > Exporter `.sqlite`, puis ici : Données > Importer `.sqlite`.
Le schéma est compatible, les tables manquantes sont créées automatiquement.

<?php
/**
 * Configuration du Journal de bord.
 *
 * Générer le hash du mot de passe :
 *   php -r "echo password_hash('mon-mot-de-passe', PASSWORD_DEFAULT), PHP_EOL;"
 */
return [
    // Hash bcrypt/argon du mot de passe (vide = connexion impossible)
    'password_hash' => '',

    // Chemin de la base SQLite. Idéalement hors de la racine web.
    'db_path' => __DIR__ . '/data/journal.sqlite',

    // Dossier des sauvegardes automatiques (avant import / reset)
    'backup_dir' => __DIR__ . '/data/backups',

    // Dossier des sessions PHP (garde les sessions 30 jours, indépendamment du GC système)
    'session_dir' => __DIR__ . '/data/sessions',

    // Durée de session en jours
    'session_days' => 365,

    'timezone' => 'Europe/Paris',

    // Contact transmis aux services de push (Google, Mozilla, Apple) avec chaque envoi.
    // Une adresse mail ou une URL https, au choix.
    'vapid_subject' => 'mailto:moi@exemple.fr',

    // Signature utilisée dans le récap au format mail
    'signature' => 'Prénom',
];

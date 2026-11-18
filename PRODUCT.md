# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
Un seul utilisateur : un développeur qui note ce qu'il fait plusieurs fois par jour entre deux tâches, puis relit la veille pour l'appel du matin et suit son temps par projet.

## Product Purpose
Loch : journal de bord dev auto-hébergé : saisir vite une entrée (date, projet, durée, titre, tags, détails markdown), la retrouver, relire la veille et en tirer des stats, et être relancé par des rappels Web Push quand rien n'a été noté.

## Operating Context
Desktop, un onglet ouvert toute la journée à côté de l'IDE et du terminal. Rappels reçus par notification système. Le matin, la veille est relue à voix haute lors d'un court appel d'équipe.

## Capabilities and Constraints
- PHP 8.1+ / SQLite, aucune dépendance Composer ; interface mono-fichier `index.html` (CSS et JS inline).
- Vues : Journal (avec le récap de la veille), Stats, Projets (+ tags), Rappels, Données (export/import, SQL lecture seule).
- Chaque projet a une couleur choisie par l'utilisateur.
- Interface en français, tutoiement.

## Brand Commitments
- Thème clair uniquement (le thème sombre a été retiré : moins soigné, inutile).
- Garder la densité et la rapidité de saisie (formulaire en tête du Journal, Entrée / Ctrl+Entrée pour enregistrer).

## Product Principles
1. Noter doit coûter moins de dix secondes.
2. La relecture (récap de la veille, stats) doit être aussi lisible que la saisie est rapide.
3. Outil perso, pas un produit : aucune décoration qui ralentit.

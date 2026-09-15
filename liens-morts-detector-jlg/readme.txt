=== Liens morts detector - JLG ===
Contributors: jeromelegousse
Tags: broken links, seo, scan, maintenance
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Détecte les liens et images morts, les signale dans wp-admin et propose des outils de réparation rapide.

== Description ==

Liens Morts Detector scanne les contenus WordPress (articles, widgets, menus, commentaires, métadonnées) pour signaler les liens et images cassés. L’administration suit la charte wp-admin (`wrap`, `h1`, `nav-tab`, Settings API, `form-table`, `button-primary`, `notice-*`).

== Installation ==

1. Copier le dossier `liens-morts-detector-jlg` dans `wp-content/plugins/`.
2. Activer l’extension.
3. Ouvrir le menu **Liens Morts**.

== Changelog ==

= 1.0.1 =
* Déclaration WordPress 7.1 (Requires / Tested up to / PHP).
* Charte wp-admin : plus de restyle du chrome (`#wpbody-content`, Inter, violet).
* JS admin ignoré dans l’éditeur iframé.
* Colonnes primaires des listes pour les en-têtes de ligne.
* Enregistrement des réglages : plus de scan de liens immédiat (OOM `options.php`).

= 1.0 =
* Version initiale.

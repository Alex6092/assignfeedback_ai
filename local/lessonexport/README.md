# local_lessonexport — Leçons imprimables

Une version imprimable de chaque leçon (`mod_lesson`), avec la mise en page de Moodle, pour que les
élèves gardent une trace hors ligne : panne de Moodle, révisions, fin de la formation. L'élève ouvre
la version imprimable puis fait **Imprimer → Enregistrer au format PDF** dans son navigateur.

## Ce que voit l'élève

- Sous chaque leçon : un bouton **🖨 Version imprimable de la leçon**.
- Sous la page du cours (et dans le menu du cours) : **🖨 Leçons imprimables**, la liste des leçons
  accessibles et **Imprimer toutes les leçons** (une leçon par page imprimée).

## Fidélité du rendu

La page reprend exactement le balisage de `mod_lesson` (boîte `contents`, puis `no-overflow`) dans la
mise en page `print` du thème. Les classes des parcours (`.objectif`, `.astuce`, `.carte`…) du « Code
SCSS initial » s'appliquent donc comme dans la leçon. Les règles d'impression (`styles.css`) :

- A4, marges de 14 mm, sans l'interface de Moodle ;
- couleurs des encadrés conservées (`print-color-adjust: exact`) ;
- un encadré, un bloc de code, un tableau ou une image n'est jamais coupé entre deux pages ;
- le code long passe à la ligne ; l'adresse des liens externes est écrite après le lien ;
- une leçon par page imprimée quand on imprime tout le cours.

## Qui peut imprimer quoi

- Le plugin s'active **cours par cours** (page « Leçons imprimables », bouton de l'enseignant), ou
  pour tout le site par défaut (*Plugins locaux → Leçons imprimables*). L'enseignant voit toujours la
  page, pour vérifier avant d'activer.
- Une leçon ne s'imprime que si l'élève peut l'ouvrir : visibilité, restrictions d'accès, dates
  d'ouverture, pas de mot de passe. Les pages de question sont imprimées sans leurs réponses.
- **Mode examen** d'une activité surveillée (`local_classhours`) : toutes les pages passent par
  `require_login()`, l'élève est donc renvoyé vers l'examen comme pour le reste du cours.

Capacités : `local/lessonexport:view` (élèves, enseignants), `local/lessonexport:manage`
(enseignants éditeurs, gestionnaires). Aucune donnée personnelle n'est conservée.

## Export en lot (enseignant)

L'outil `moodle_export_lessons_pdf` du serveur MCP (`tools/moodle-mcp`) produit sur le poste un PDF
par leçon et un PDF du parcours complet, avec la feuille de style du thème et les règles de ce plugin,
imprimés par Edge ou Chrome sans fenêtre.

## Installation

Copier `local/lessonexport` dans `public/local/lessonexport` (Moodle 5.x), puis *Administration du
site → Notifications*. Aucune configuration obligatoire.

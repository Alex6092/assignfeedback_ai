// Gabarit d'un TD (travail dirigé, non évalué, 20 à 30 min) — à copier dans Mx-sujet/td.questions.mjs.
// Générer le Moodle XML :  node <skill>/scripts/build-questions.mjs td.questions.mjs td.xml
// Contrôler :              node <skill>/scripts/check-module.mjs <module> [--manifest td.deploy.json]
//                          node <skill>/scripts/verifier-tpe.mjs <module> --questions td.questions.mjs (CodeRunner)
// Règles : references/anatomie-module.md § Le TD. Exemple réel : templates/exemples-cpp/td-M2.questions.mjs.
//
// Structure : un CAS PRATIQUE (texte 1), puis des étapes « texte de guidage → 1 ou 2 questions », puis un bilan.
//   - texte de guidage (type description, 0 point) : la notion utile, en quelques lignes, avec un mini-exemple
//     sur un AUTRE sujet que la question (sinon la question se recopie), puis ce qu'on va faire ;
//   - questions de plus en plus exigeantes : reconnaître (QCM) → prévoir (numérique, réponse courte)
//     → compléter (ordre, appariement) → écrire (CodeRunner, 2 au plus, avec squelette `preload`) ;
//   - 1 ou 2 indices (`hints`) par question, sans donner la réponse ; un feedback par mauvaise réponse qui nomme
//     l'erreur probable ; un feedback général qui explique la bonne réponse (il est montré : c'est un apprentissage) ;
//   - aucune question corrigée par IA ; ni le sujet d'un TP évalué ni une question du sommatif.
// Remplace tout ce qui est entre [crochets]. Le préfixe (XX) et le module (M5) suivent la carte du parcours.

export const header = `TD M5 — [Titre du cas pratique] (XX-M5-TD01 à XX-M5-TD12)
Travail dirigé non évalué : textes de guidage intercalés avec des questions à indices.`;

const tags = (...c) => ['XX-M5-TD', ...c];

export const questions = [
  {
    type: 'description', id: 'XX-M5-TD01', name: 'M5-TD01 — Le cas',
    text: `<h3>[emoji] [Titre du cas pratique]</h3>
<p>[Qui : une entreprise, un service, un client crédible pour un technicien CIEL. Quel besoin, quel
incident. Ce qu'on va construire ensemble, en n étapes.]</p>
<div class="astuce">💡 <strong>Comment travailler.</strong> Lis chaque explication, réponds, puis clique sur
<strong>Vérifier</strong>. Si c'est faux, un <strong>indice</strong> s'affiche : réessaie. Ce TD n'est pas
noté. Garde la leçon ouverte dans un autre onglet.</div>`,
  },

  // Étape 1 — reconnaître, puis prévoir
  {
    type: 'description', id: 'XX-M5-TD02', name: 'M5-TD02 — Étape 1 : [notion]',
    text: `<h3>Étape 1 — [Ce qu'on fait] : [notion de la leçon]</h3>
<p>[La notion en 3 à 6 lignes, avec un mini-exemple sur un autre sujet.]</p>
<pre><code>[exemple court et commenté]</code></pre>`,
  },
  {
    type: 'multichoice', id: 'XX-M5-TD03', name: 'M5-TD03 — [Titre]', tags: tags('C08'),
    text: '<p>[Question qui fait reconnaître la bonne forme dans le cas pratique.]</p>',
    answers: [
      [100, '[bonne réponse]', '[Pourquoi c\'est juste.]'],
      [0, '[erreur fréquente 1]', '[L\'erreur nommée, et pourquoi elle ne marche pas.]'],
      [0, '[erreur fréquente 2]', '[…]'],
      [0, '[erreur fréquente 3]', '[…]'],
    ],
    hints: ['<p>[Renvoi à la notion, sans la réponse.]</p>', '<p>[Indice plus précis : une valeur à tester.]</p>'],
    general: '<p>[La règle à retenir, en une ou deux phrases.]</p>',
  },
  {
    type: 'numerical', id: 'XX-M5-TD04', name: 'M5-TD04 — [Titre]', tags: tags('C08'),
    text: '<p>[Un court extrait du programme du cas. « Quel nombre s\'affiche ? »]</p>',
    answers: [
      [100, '[valeur]', '[Le déroulé qui y mène.]', 0],
      [0, '[valeur fausse attendue]', '[L\'erreur probable qui y mène.]', 0],
      [0, '*', '[Méthode : un tableau tour par tour, une ligne par instruction…]', 0],
    ],
    hints: ['<p>[…]</p>', '<p>[…]</p>'],
    general: '<p>[Le déroulé complet.]</p>',
  },

  // Étape 2 — compléter
  {
    type: 'description', id: 'XX-M5-TD05', name: 'M5-TD05 — Étape 2 : [notion]',
    text: '<h3>Étape 2 — [Ce qu\'on fait]</h3>\n<p>[La notion, un mini-exemple, ce qu\'on va faire.]</p>',
  },
  {
    type: 'matching', id: 'XX-M5-TD06', name: 'M5-TD06 — [Titre]', tags: tags('C08'),
    text: '<p>[Associer chaque élément du cas à son rôle, à son résultat…]</p>',
    pairs: [['[élément 1]', '[réponse 1]'], ['[élément 2]', '[réponse 2]'], ['[élément 3]', '[réponse 3]'], ['', '[distracteur]']],
    hints: ['<p>[…]</p>'],
    general: '<p>[…]</p>',
  },
  {
    type: 'ordering', id: 'XX-M5-TD07', name: 'M5-TD07 — [Titre]', tags: tags('C08'),
    text: '<p>[Remets dans l\'ordre les lignes, les étapes…]</p>',
    items: ['[premier]', '[deuxième]', '[troisième]', '[quatrième]'],
    hints: ['<p>[…]</p>'],
    general: '<p>[…]</p>',
  },

  // Étape 3 — écrire
  {
    type: 'description', id: 'XX-M5-TD08', name: 'M5-TD08 — Étape 3 : [notion]',
    text: '<h3>Étape 3 — [Ce qu\'on fait]</h3>\n<p>[La notion, un mini-exemple, ce qu\'on va écrire.]</p>',
  },
  {
    type: 'shortanswer', id: 'XX-M5-TD09', name: 'M5-TD09 — [Titre]', tags: tags('C08'),
    text: '<p>[Une ligne manquante à écrire, un mot-clé, un nom de commande.]</p>',
    answers: [[100, '[réponse]', '[…]'], [0, '*', '[…]']],
    hints: ['<p>[…]</p>'],
    general: '<p>[…]</p>',
  },
  {
    type: 'coderunner', id: 'XX-M5-TD10', name: 'M5-TD10 — [Titre]', tags: tags('C08'),
    crtype: 'cpp_function', lines: 12,
    text: '<p>[Écris la fonction … qui … Rappel des règles du cas. N\'écris que la fonction.]</p>',
    preload: '// [rôle de la fonction]\nint f(int x) {\n    // à compléter\n\n}',
    solution: 'int f(int x) {\n    return 2 * x;\n}',
    tests: [
      { code: 'std::cout << f(2);', expected: '4', example: true },
      { code: 'std::cout << f(0);', expected: '0' },
    ],
    general: '<p>[Une solution commentée, et ce que vérifient les tests cachés.]</p>',
  },
  {
    type: 'multichoice', id: 'XX-M5-TD11', name: 'M5-TD11 — [Titre]', tags: tags('C08'),
    text: '<p>[Question de synthèse sur le cas.]</p>',
    answers: [[100, '[…]', '[…]'], [0, '[…]', '[…]'], [0, '[…]', '[…]']],
    hints: ['<p>[…]</p>'],
    general: '<p>[…]</p>',
  },

  {
    type: 'description', id: 'XX-M5-TD12', name: 'M5-TD12 — Bilan',
    text: `<h3>✅ Ce que tu as construit</h3>
<p>[Ce que le programme / le système du cas sait faire maintenant, notion par notion.]</p>
<div class="objectif">🎯 <strong>Les réflexes à garder :</strong> [3 à 5 réflexes].</div>
<p>À toi maintenant : le <strong>TP d'entraînement</strong> […], puis le <strong>QCM d'entraînement</strong>.</p>`,
  },
];

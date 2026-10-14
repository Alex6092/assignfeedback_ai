// Gabarit des TP évalués en classe et du TP bonus d'un module — à copier dans Mx-sujet/tp-evalues.mjs.
// Générer :   node <skill>/scripts/make-tpe.mjs <module> [--dans-module]   → tpe1/, tpb/, tp-evalues.deploy.json, fiche
// Contrôler : node <skill>/scripts/verifier-tpe.mjs <module>                → compile et exécute les corrigés
//             node <skill>/scripts/check-module.mjs <module> --manifest tp-evalues.deploy.json
// Règles : references/anatomie-module.md § Les TP. Exemple réel : templates/exemples-cpp/tp-evalues-M2.mjs.
//
// Rappels : sujet INÉDIT (ni leçon, ni TD, ni TP d'entraînement), notions du module seulement ; 3 ou 4 niveaux, le
// dernier est un défi ; la durée est INDICATIVE et ne va jamais dans la consigne (fiche enseignant seulement) ;
// barème sur 100 par niveau ; le bonus ne s'ouvre qu'à ceux qui ont rendu les TP évalués (make-tpe pose `requires`).

export const meta = {
  n: 5,                                   // module M5
  // section, sectionName, course, prefix : repris de module.deploy.json s'ils sont absents
  titre: '[Titre du module]',
  filetypes: '.cpp', maxfiles: 1,         // sans filetypes : réponse en texte en ligne
  // Un TP qui fait rendre un diagramme : l'élève dépose une image (jamais un dessin en texte), par exemple
  // filetypes: '.cpp,.h,.png,.jpg,.jpeg,.pdf', et la Correction IA doit la lire : vision: true.
};

export const tps = [
  {
    num: 1, titre: 'Le convertisseur de vitesse',
    code: 'C08.3.D2.T3', codeLibelle: '[libellé du code EFE N3]', duree: 50,
    competences: 'C08 — Coder',
    consigne: `<p><strong>Mission.</strong> [Le contexte, inédit.] Le travail est <strong>individuel</strong> et découpé en <strong>3 niveaux</strong> à faire dans l'ordre ; le niveau 3 est un défi. Si tu n'as pas terminé un niveau, rends ce qui fonctionne : tout ce qui est correct est compté. Tu déposes ton fichier <code>.cpp</code> avant la fin de la séance.</p>
<div class="cdc"><strong>📄 Cahier des charges.</strong> Le programme lit une vitesse en km/h (nombre entier) et l'affiche en m/s.</div>
<div class="phase"><strong>Niveau 1 — La conversion</strong>
<ul><li>une fonction <code>double enMetresParSeconde(int kmh)</code> renvoie la vitesse convertie (divise par 3.6) ;</li>
<li>le programme affiche « 90 km/h = 25 m/s » pour 90.</li></ul></div>
<div class="phase"><strong>Niveau 2 — Les saisies refusées</strong>
<ul><li>une vitesse négative affiche « Vitesse invalide ».</li></ul></div>
<div class="phase"><strong>Niveau 3 (défi) — La table</strong>
<ul><li>avec la saisie 0, le programme affiche la table de 10 à 130 km/h, de 10 en 10.</li></ul></div>
<div class="rendre"><strong>📦 À rendre :</strong> ton fichier <code>.cpp</code>, commenté.</div>`,
    solution: `#include <iostream>

double enMetresParSeconde(int kmh) {
    return kmh / 3.6;
}

int main() {
    int v = 0;
    std::cout << "Vitesse (km/h) : ";
    std::cin >> v;
    if (v < 0) {
        std::cout << "Vitesse invalide" << std::endl;
    } else if (v == 0) {
        for (int k = 10; k <= 130; k += 10) {
            std::cout << k << " km/h = " << enMetresParSeconde(k) << " m/s" << std::endl;
        }
    } else {
        std::cout << v << " km/h = " << enMetresParSeconde(v) << " m/s" << std::endl;
    }
    return 0;
}`,
    bareme: [
      { titre: 'Niveau 1 — La conversion', pts: 60, items: [['fonction enMetresParSeconde correcte (division réelle)', 35], ['affichage conforme', 25]] },
      { titre: 'Niveau 2 — Les saisies refusées', pts: 20, items: [['vitesse négative refusée', 20]] },
      { titre: 'Niveau 3 — La table (défi)', pts: 15, items: [['boucle de 10 à 130 par pas de 10', 15]] },
      { titre: 'Qualité', pts: 5, items: [['code indenté et commenté', 5]] },
    ],
    erreurs: ['division entière kmh / 36 * 10 qui perd les décimales', 'table de 10 à 120 (borne oubliée)'],
    vigilance: ['[Ce que l\'enseignant surveille pendant la séance.]'],
    tests: [
      { stdin: '90\n', attendu: ['90 km/h = 25 m/s'] },
      { stdin: '-5\n', attendu: ['Vitesse invalide'] },
      { stdin: '0\n', attendu: ['10 km/h', '130 km/h'] },
    ],
  },
  {
    bonus: true, num: 9, titre: '[Titre du bonus]',
    code: 'C08.4.D2.T3', codeLibelle: '[libellé du code EFE N3]', duree: 50,
    competences: 'C08 — Coder',
    // Exemple de TP sans code à compiler (analyse, conception) : sansCompilation
    sansCompilation: true,
    consigne: `<p><strong>TP bonus.</strong> Ce TP est réservé à celles et ceux qui ont <strong>déjà rendu</strong> les TP évalués de la séance : il ne les remplace pas. Il est <strong>noté lui aussi</strong>. Le travail est <strong>individuel</strong> et découpé en <strong>4 niveaux</strong> à faire dans l'ordre ; le niveau 4 est un défi.</p>
<div class="phase"><strong>Niveau 1 — [titre]</strong><ul><li>[…]</li></ul></div>
<div class="phase"><strong>Niveau 2 — [titre]</strong><ul><li>[…]</li></ul></div>
<div class="phase"><strong>Niveau 3 — [titre]</strong><ul><li>[…]</li></ul></div>
<div class="phase"><strong>Niveau 4 (défi) — [titre]</strong><ul><li>[…]</li></ul></div>`,
    solution: '[Corrigé type : la production attendue, complète.]',
    bareme: [
      { titre: 'Niveau 1', pts: 40, items: [['[critère]', 40]] },
      { titre: 'Niveau 2', pts: 25, items: [['[critère]', 25]] },
      { titre: 'Niveau 3', pts: 20, items: [['[critère]', 20]] },
      { titre: 'Niveau 4 (défi)', pts: 15, items: [['[critère]', 15]] },
    ],
    erreurs: ['[erreur typique]'],
  },
];

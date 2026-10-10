# Référentiel BTS CIEL option A et codes EFE

Source : référentiel officiel du BTS « Cybersécurité, Informatique et réseaux, Électronique »
(option A « Informatique et réseaux ») et référentiel **EFE** de l'établissement, lu par le pont le
9 octobre 2026. Les codes peuvent évoluer : avant de figer un parcours, relis-les avec
`moodle_get_efe_competences` (niveaux `n2` et `n3`) quand le serveur MCP est disponible.

## Sommaire

1. Compétences, blocs et épreuves
2. Choisir un code EFE
3. Tâches professionnelles (suffixes N3)
4. Critères N2 et tâches N3, compétence par compétence

## 1. Compétences, blocs et épreuves

| Code | Compétence (option A) | Bloc / épreuve |
|---|---|---|
| C01 | Communiquer en situation professionnelle (français/anglais) | U6 / E6 |
| C02 | Organiser une intervention | U5 / E5 |
| C03 | Gérer un projet | U6 / E6 |
| C04 | Analyser un système informatique | U4 / E4 |
| C05 | Concevoir un système informatique | U4 / E4 |
| C06 | Valider un système informatique | U5 / E5 |
| C07 | *(option B uniquement)* | — |
| C08 | Coder | U6 / E6 |
| C09 | Installer un réseau informatique | U5 / E5 |
| C10 | Exploiter un réseau informatique | U6 / E6 |
| C11 | Maintenir un réseau informatique | U5 / E5 |

- **E4 / U4** « Étude et conception de réseaux informatiques » : écrit de 6 h, coefficient 4 (C04, C05).
- **E5 / U5** « Exploitation et maintenance de réseaux informatiques » : CCF, coefficient 3
  (C02, C06, C09, C11).
- **E6 / U6** « Valorisation de la donnée et cybersécurité » : oral d'1 h sur un projet de 150 h,
  coefficient 7 (C01, C03, C08, C10).
- Dans EFE, les blocs sont B1 = U4, B2 = U5, B3 = U6.

Activités professionnelles du référentiel : R1 Accompagnement du client · R2 Installation et
qualification · R3 Exploitation et maintien en condition opérationnelle · R4 Gestion de projet et
d'équipe · R5 Maintenance des réseaux · D1 Cahier des charges · D2 Développement et validation
logicielle · D3 Gestion d'incidents · D4 Valorisation de la donnée · D5 Audit de l'installation ou
du système.

Les connaissances associées ont un niveau taxonomique de 1 à 4 : 1 information, 2 expression,
3 maîtrise d'outils, 4 maîtrise méthodologique. Une évaluation ne doit pas exiger plus que le niveau
visé par le référentiel.

## 2. Choisir un code EFE

- **Niveaux** : N1 = la compétence (`C04`), N2 = un critère d'évaluation (`C04.3`), N3 = un critère
  appliqué à une tâche professionnelle (`C04.3.D4.T4`) ou un item créé par un enseignant
  (`C04.4.1`). L'inspection impose **N2 au minimum, N3 de préférence. Jamais N1 seul.**
- **Une compétence par activité notée**, sur laquelle porte le barème. Le TP ou le test est conçu
  pour faire observer **ce critère-là** ; ne choisis pas le code après coup.
- **Ponctualité** (TP) : en plus, une compétence de respect des délais est rapportée par la note de
  ponctualité. Dans les parcours existants, on trouve `C03.3.R4.T5` (l'équipe gère retards et aléas),
  `C03.4.R4.T5` (travaux livrés conformes), `C03.5.R4.T5` et `C11.5` (travail réalisé dans le calme
  et les délais). Varie-les, toujours sur des codes N2/N3.
- **Varier les compétences** au fil du parcours : le tableau de couverture doit montrer un éventail
  large, et pas la même compétence partout. Pour chaque bloc d'examen visé, couvre ses compétences.
- **Libellé pour la Correction IA et les questions IA** : une ligne par compétence, au format
  `Cxx — Libellé de la compétence` (TP), ou `Cxx — Libellé : critère` (champ `<competencies>`
  d'une composition IA).

Exemples relevés dans les parcours :

| Activité | Code EFE | Pourquoi |
|---|---|---|
| TP Nmap « Cartographier ta cible » | `C04.3` | critique de la structure de la solution (la surface d'attaque) |
| TP « Énumérer ta cible » | `C10.2.D5.T2` | vérifier le fonctionnement d'un équipement, tâche d'audit |
| TP « Traces d'une attaque » | `C11.2.R5.T4` | interpréter des résultats d'analyse, diagnostic de maintenance |
| Test sommatif « Cadre, éthique & méthodologie » | `C02.4` | analyser la situation, respecter qualité et délais |
| Test sommatif « Deep learning & images » | `C08.1.D4.T5` | choix et justification d'environnements, valorisation de la donnée |

## 3. Tâches professionnelles (suffixes N3)

Un code N3 se forme en ajoutant le suffixe au code N2 : `C10.2` + `D5.T1` = `C10.2.D5.T1`. Seules
les combinaisons listées au § 4 existent dans EFE.

| Suffixe | Activité — tâche professionnelle |
|---|---|
| R1.T1 | Accompagnement du client — Analyse des besoins du client |
| R1.T2 | Accompagnement du client — Réception de l’installation avec le client |
| R1.T3 | Accompagnement du client — Formation du client |
| R1.T4 | Accompagnement du client — Explication des modalités de l’intervention |
| R2.T1 | Installation et qualification — Analyse de la demande du client |
| R2.T2 | Installation et qualification — Production des documents pour la mise en œuvre (plans d'exécution, protocoles, paramétrages etc.) |
| R2.T3 | Installation et qualification — Vérification du dossier et interprétation des plans d’exécution |
| R2.T4 | Installation et qualification — Préparation du chantier en fonction de l’intervention souhaitée |
| R2.T5 | Installation et qualification — Réalisation des opérations avec, en particulier, prise en compte des contraintes client et contrôle matériel et logiciel de l’installation |
| R2.T6 | Installation et qualification — Recettage de l’installation |
| R3.T1 | Exploitation et maintien en condition opérationnelle — Suivi de l’exploitation technique |
| R3.T2 | Exploitation et maintien en condition opérationnelle — Contact avec les supports techniques externes |
| R3.T3 | Exploitation et maintien en condition opérationnelle — Supervision de l’état du réseau dans son périmètre |
| R3.T4 | Exploitation et maintien en condition opérationnelle — Réalisation d’un diagnostic de premier niveau |
| R3.T5 | Exploitation et maintien en condition opérationnelle — Configuration matérielle et logicielle des équipements |
| R3.T6 | Exploitation et maintien en condition opérationnelle — Intégration de nouveaux équipements |
| R3.T7 | Exploitation et maintien en condition opérationnelle — Mise à jour des équipements |
| R4.T1 | Gestion de projet et d’équipe — Identification de toutes les étapes du projet jusqu’à la réception des travaux |
| R4.T2 | Gestion de projet et d’équipe — Identification des ressources humaines et matérielles |
| R4.T3 | Gestion de projet et d’équipe — Management des équipes opérationnelles internes |
| R4.T5 | Gestion de projet et d’équipe — Pilotage de l’exécution des travaux |
| R5.T4 | Maintenance des réseaux informatiques — Réalisation de diagnostics et d’interventions de maintenance curative |
| R5.T5 | Maintenance des réseaux informatiques — Réparation de câblage, changement de cartes ou d’équipements |
| D1.T1 | Élaboration et appropriation d’un cahier des charges — Collecte des informations |
| D1.T2 | Élaboration et appropriation d’un cahier des charges — Analyse des informations |
| D1.T3 | Élaboration et appropriation d’un cahier des charges — Interprétation d’un cahier des charges |
| D2.T1 | Développement et validation de solutions logicielles — Conception de l’architecture d’une solution logicielle |
| D2.T2 | Développement et validation de solutions logicielles — Modélisation d’une solution logicielle |
| D2.T3 | Développement et validation de solutions logicielles — Développement, utilisation ou adaptation de composants logiciels |
| D2.T4 | Développement et validation de solutions logicielles — Tests de mise en production |
| D2.T5 | Développement et validation de solutions logicielles — Recette et validation |
| D3.T1 | Gestion d’incidents — Ouverture et analyse des tickets par niveau de criticité |
| D3.T2 | Gestion d’incidents — Traitement des tickets |
| D3.T3 | Gestion d’incidents — Remédiation des incidents |
| D3.T4 | Gestion d’incidents — Élaboration des rapports d’incidents |
| D3.T5 | Gestion d’incidents — Transmission de l’information (escalade) |
| D4.T1 | Valorisation de la donnée — Collecte de la donnée |
| D4.T2 | Valorisation de la donnée — Stockage de la donnée |
| D4.T3 | Valorisation de la donnée — Orchestration de la donnée |
| D4.T4 | Valorisation de la donnée — Analyse de la donnée |
| D4.T5 | Valorisation de la donnée — Exploitation de la donnée |
| D5.T1 | Audit de l’installation ou du système — Évaluation des biens et moyens dans le périmètre de l’audit |
| D5.T2 | Audit de l’installation ou du système — Évaluation de la configuration |
| D5.T3 | Audit de l’installation ou du système — Évaluation du contrôle d’accès |
| D5.T5 | Audit de l’installation ou du système — Évaluation de la sécurité |


## 4. Critères N2 et tâches N3, compétence par compétence
### C01 — COMMUNIQUER EN SITUATION PROFESSIONNELLE

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C01.1** | Le rapport (typographie, orthographe, illustration, lisibilité) est soigné, personnel et argumenté avec des enchaînements cohérents | R1.T2, R1.T3, R1.T4, R3.T2, D3.T4, D3.T5 |
| **C01.2** | La présentation (typographie, orthographe, illustration, lisibilité) est soignée et soutient le discours avec des enchaînements cohérents | R1.T2, R1.T3, R1.T4, R3.T2, D3.T4, D3.T5 |
| **C01.3** | La présentation orale est de qualitée et claire | R1.T2, R1.T3, R1.T4, R3.T2, D3.T4, D3.T5 |
| **C01.4** | L'argumentation lors de l'échange est de qualité | R1.T2, R1.T3, R1.T4, R3.T2, D3.T4, D3.T5 |
| **C01.5** | Le style, le ton et la terminologie utilisés sont adaptés,L’attitude, les comportements et le langage sont conformes | R1.T2, R1.T3, R1.T4, R3.T2, D3.T4, D3.T5 |

Items N3 propres à des enseignants : `C01.2.1` Oral clair et précis.

### C02 — ORGANISER UNE INTERVENTION

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C02.1** | Les différents interlocuteurs et ressources sont identifiés | R4.T1, R4.T2 |
| **C02.2** | Le cahier des charges préliminaire est complété et les ressources permettant de répondre au cahier des charges sont décrites | R4.T1, R4.T2 |
| **C02.3** | Le planning prévisionnel est interprété | R4.T1, R4.T2 |
| **C02.4** | Analyse la situation, respecte la qualité, l’efficacité et les délais. | R4.T1, R4.T2 |

### C03 — GERER UN PROJET

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C03.1** | Les documents de suivis des tâches sont renseignés, le planning prévisionnel est mis à jour. | R4.T2, R4.T3, R4.T5 |
| **C03.2** | L’adéquation des ressources humaines et des ressources matérielles pour mener le projet est validée. | R4.T2, R4.T3, R4.T5 |
| **C03.3** | L’équipe projet communique correctement et gère les retards et les aléas | R4.T2, R4.T3, R4.T5 |
| **C03.4** | Les travaux sont réalisés et livrés avec la documentation en concordance avec les besoins du client | R4.T2, R4.T3, R4.T5 |
| **C03.5** | La résolution d'un problème nouveau imprévu est réussie, Le travail en équipe est conduit de manière solidaire | R4.T2, R4.T3, R4.T5 |

### C04 — ANALYSER UN SYSTEME INFORMATIQUE

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C04.1** | Les spécifications du cahier des charges sont extraites | R1.T1, D1.T2, D1.T3, D2.T2, D4.T4 |
| **C04.2** | L’organisation structurelle des sous-ensembles est conforme aux exigences fonctionnelles | R1.T1, D1.T2, D1.T3, D2.T2, D4.T4 |
| **C04.3** | La structure de la solution technique est critiquée | R1.T1, D1.T2, D1.T3, D2.T2, D4.T4 |
| **C04.4** | Les algorithmes sont critiqués | R1.T1, D1.T2, D1.T3, D2.T2, D4.T4 |
| **C04.5** | Le calme est conservé de façon constante | R1.T1, D1.T2, D1.T3, D2.T2, D4.T4 |

Items N3 propres à des enseignants : `C04.2.1` Analyser une image numérique · `C04.3.6` Analyser un circuit electronique · `C04.3.7` Analyser une chaîne d'information · `C04.4.1` Le code est complété

### C05 — CONCEVOIR UN SYSTEME INFORMATIQUE

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C05.1** | Les ressources permettant de réaliser le cahier des charges sont recensées et définies | D1.T1, D2.T1, D2.T2, D2.T4, D2.T5 |
| **C05.2** | Les solutions logicielles proposées sont conformes aux spécifications du cahier des charges | D1.T1, D2.T1, D2.T2, D2.T4, D2.T5 |
| **C05.3** | Les tests unitaires et d’intégrations sont définis | D1.T1, D2.T1, D2.T2, D2.T4, D2.T5 |
| **C05.4** | Le document de recette est rédigé conformément aux exigences du cahier des charges | D1.T1, D2.T1, D2.T2, D2.T4, D2.T5 |
| **C05.5** | Le souci constant de la qualité est recherché et aide son équipe | D1.T1, D2.T1, D2.T2, D2.T4, D2.T5 |

Items N3 propres à des enseignants : `C05.3.6` Concevoir un circuit électronique en régime continu · `C05.3.7` Concevoir un circuit elec en regime alternatif · `C05.3.8` Concevoir un circuit électrique en régime oscillant

### C06 — VALIDER UN SYSTEME INFORMATIQUE

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C06.1** | Les exigences à valider sont identifiées dans le périmètre défini | R2.T2, R2.T3, R2.T6 |
| **C06.2** | Les procédures de test sont établies | R2.T2, R2.T3, R2.T6 |
| **C06.3** | Les tests (unitaires, d’intégration et autres) sont appliqués | R2.T2, R2.T3, R2.T6 |
| **C06.4** | Les résultats de tests sont synthétisés pour évaluer la conformité globale | R2.T2, R2.T3, R2.T6 |
| **C06.5** | Le document de recette est validé par le client et la recette est réalisée avec le client | R2.T2, R2.T3, R2.T6 |
| **C06.6** | planifie son travail efficacement,garde son calme en toute situation, persévère jusqu’au résultat, et prend les bonnes décisions | R2.T2, R2.T3, R2.T6 |

Items N3 propres à des enseignants : `C06.1.1` Les requêtes SQL sont conformes au CDC · `C06.2.4` Mesurer des paramètres électriques sur circuit · `C06.4.4` Mesurer des parametres elec sur circuit

### C08 — CODER

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C08.1** | Les environnements sont choisis et justifiés et les données de l’entreprise sont identifiées | D2.T3, D2.T4, D4.T1, D4.T2, D4.T3, D4.T5 |
| **C08.2** | Le code est versionné, commenté et le logiciel est documenté | D2.T3, D2.T4, D4.T1, D4.T2, D4.T3, D4.T5 |
| **C08.3** | Les composants logiciels individuels sont développés conformément (sécurité et de protection des données ) | D2.T3, D2.T4, D4.T1, D4.T2, D4.T3, D4.T5 |
| **C08.4** | La solution (logicielle et matérielle) est intégrée et testée conformément | D2.T3, D2.T4, D4.T1, D4.T2, D4.T3, D4.T5 |
| **C08.5** | La résolution d'un problème nouveau imprévu est réussie | D2.T3, D2.T4, D4.T1, D4.T2, D4.T3, D4.T5 |

Items N3 propres à des enseignants : `C08.1.1` Notion de cyber sécurité · `C08.2.1` Utilisation de GitHub · `C08.3.19` Développer un module logiciel selon les spécifications · `C08.4.1` T6. Mise en œuvre d'un codage d'information

### C09 — INSTALLER UN RESEAU INFORMATIQUE

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C09.1** | Les équipements nécessaires à la réponse au CDC (fourni par le client) sont identifiés | R2.T1, R2.T2, R2.T4, R2.T5, R3.T6 |
| **C09.2** | Une procédure de configuration ou d’installation est déterminée | R2.T1, R2.T2, R2.T4, R2.T5, R3.T6 |
| **C09.3** | La ou les procédures choisies sont suivies | R2.T1, R2.T2, R2.T4, R2.T5, R3.T6 |
| **C09.4** | Les activités sont menées en respectant les règles de sécurité | R2.T1, R2.T2, R2.T4, R2.T5, R3.T6 |
| **C09.5** | Un compte-rendu du fonctionnement de l'installation est fourni (anomalies, difficultés et retours clients etc.) | R2.T1, R2.T2, R2.T4, R2.T5, R3.T6 |
| **C09.6** | La personne adapte sa communication au contexte et répond aux attentes en préparant un travail de qualité, efficace et livré dans les délais. | R2.T1, R2.T2, R2.T4, R2.T5, R3.T6 |

Items N3 propres à des enseignants : `C09.1.1` Conversions Fondamentales Hexa Binaire · `C09.2.1` Notion d'IP et Masque · `C09.4.1` Mise en œuvre de capteurs et actionneurs · `C09.5.1` Maitrise Notion de réseau · `C09.5.2` Décodage de trame · `C09.5.3` Maitrise Les Rôles des adresses IP · `C09.5.4` Classe IP et Cohérence de configuration

### C10 — EXPLOITER UN RESEAU INFORMATIQUE

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C10.1** | Les différents éléments matériels et/ou logiciels sont identifiés à partir d’un schéma fourni | R3.T1, R3.T4, R3.T7, D3.T1, D3.T2, D3.T3, D5.T1, D5.T2, D5.T3, D5.T5 |
| **C10.2** | Le fonctionnement d’un équipement matériel et/ou logiciel est vérifié en tenant compte du contexte | R3.T1, R3.T4, R3.T7, D3.T1, D3.T2, D3.T3, D5.T1, D5.T2, D5.T3, D5.T5 |
| **C10.3** | La mise à jour d’un matériel et/ou logiciel est proposée et justifiée | R3.T1, R3.T4, R3.T7, D3.T1, D3.T2, D3.T3, D5.T1, D5.T2, D5.T3, D5.T5 |
| **C10.4** | Les optimisations ou résolution d’incidents nécessaires sont effectuées | R3.T1, R3.T4, R3.T7, D3.T1, D3.T2, D3.T3, D5.T1, D5.T2, D5.T3, D5.T5 |
| **C10.5** | Le travail en équipe est conduit de manière solidaire | R3.T1, R3.T4, R3.T7, D3.T1, D3.T2, D3.T3, D5.T1, D5.T2, D5.T3, D5.T5 |

Items N3 propres à des enseignants : `C10.3.1` Intégration Back ou Front · `C10.4.1` Intégration d'un nouveauté logiciel

### C11 — MAINTENIR UN RESEAU INFORMATIQUE

| N2 | Critère | Tâches N3 (suffixes) |
|---|---|---|
| **C11.1** | Les outils logiciels et matériels permettant d’effectuer les tests et l’analyse du système d’information sont identifiés et mis en œuvre selon les spécifications | R3.T3, R3.T5, R5.T4, R5.T5 |
| **C11.2** | Les résultats de tests et d’analyse sont interprétés de manière pertinente et les causes de l’incident sont localisées | R3.T3, R3.T5, R5.T4, R5.T5 |
| **C11.3** | L’incident est résolu ou qualifié et escaladé, le service est opérationnel | R3.T3, R3.T5, R5.T4, R5.T5 |
| **C11.4** | Le client est correctement informé et conseillé quant aux mesures de prévention possibles | R3.T3, R3.T5, R5.T4, R5.T5 |
| **C11.5** | Le travail est réalisé dans le calme et les délais | R3.T3, R3.T5, R5.T4, R5.T5 |

Items N3 propres à des enseignants : `C11.4.1` Renseigne les futurs étudiants en porte ouverte

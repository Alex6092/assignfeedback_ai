# Vidéo + QCM (`mod_videoqcm`)

Activité du plugin de l'établissement : une vidéo YouTube avec des questions qui l'interrompent
(visionnage noté sur 10, à la maison), et un QCM optionnel joué en classe.

## Quand proposer une vidéo

Une vidéo coûte du temps (à choisir ou à produire) et ajoute une activité au module. Ne la propose
que si **toutes** ces conditions sont réunies :

1. **Le concept est dynamique** : un processus qui se déroule dans le temps, un flux, une évolution
   pas à pas (un échange réseau, un apprentissage qui converge, une transformation de données).
   Un schéma fixe dans la leçon l'explique mal.
2. **C'est une difficulté réelle** du module : la notion que les élèves ratent le plus, ou celle dont
   dépend la suite.
3. **La vidéo apporte autre chose que la leçon** : elle montre, elle ne relit pas le texte.

Si une seule condition manque, ne propose rien : un schéma, un exemple pas à pas ou un exercice
« À toi ! » dans la leçon fera mieux. Dans la carte du parcours, une vidéo proposée est toujours
accompagnée de sa raison en une ligne. Dans la plupart des parcours, cela concerne 2 à 5 modules,
pas tous.

## Deux façons d'avoir la vidéo

**1. Une vidéo existante (par défaut)** : une vidéo YouTube de qualité, en français de préférence,
courte (2 à 6 minutes, l'attention chute au-delà de 6), d'une source fiable (enseignant, université,
chaîne de vulgarisation reconnue). Propose 2 ou 3 candidates avec leur durée et ce qu'elles
montrent ; l'enseignant regarde et choisit. Tu ne peux pas regarder une vidéo : pour écrire les
questions, demande-lui de copier la **transcription** YouTube (« Afficher la transcription »), avec
les minutages. Le plugin sait aussi récupérer les sous-titres et générer des questions avec le LLM
local (bouton de génération dans l'activité) : c'est une alternative si l'enseignant préfère.

**2. Une animation produite (pilote, plus tard)** : le code d'une animation explicative (Remotion ou
Motion Canvas), rendu en MP4 sur le poste de l'enseignant puis mis en ligne par lui en « non
répertoriée ». C'est bien plus coûteux (50 à 150 k tokens par animation) : réservé à un concept
pour lequel aucune vidéo existante ne convient, et seulement quand l'enseignant le demande.

## Le fichier `video.json`

```json
{
  "youtubeurl": "https://www.youtube.com/watch?v=XXXXXXXXXXX",
  "titre": "Ce que montre la vidéo, en une ligne",
  "duree": "4:12",
  "source": "Chaîne ou auteur",
  "pertinence": "Pourquoi cette vidéo pour ce module (concept dynamique, difficulté visée)",
  "questions": [
    { "id": 1, "time": 45, "question": "…", "choices": ["…", "…", "…", "…"], "correct": 2 },
    { "id": 2, "time": "end", "question": "…", "choices": ["…", "…", "…", "…"], "correct": 0 }
  ],
  "livequestions": [
    { "id": 1, "question": "…", "choices": ["…", "…", "…", "…"], "correct": 1 }
  ]
}
```

Contraintes du plugin (vérifiées à l'enregistrement) :
- chaque question a **exactement 4 choix** et `correct` est l'index de la bonne réponse (0 à 3) ;
- `time` = la seconde où la vidéo s'arrête pour poser la question ; `"end"` = juste avant la fin
  (garantit que l'élève a tout regardé) ;
- `livequestions` n'a pas de `time` ; laisser `[]` s'il n'y a pas de QCM en classe.

## Les questions pendant la vidéo

- Une question **toutes les 45 à 90 secondes**, juste **après** le passage qui y répond, plus une
  question `"end"`. Pour 4 minutes : 4 ou 5 questions.
- Elles vérifient la compréhension de ce qui vient d'être montré (pas un détail de décor, pas une
  date) ; la bonne réponse n'est pas plus longue que les autres ; les distracteurs sont des erreurs
  plausibles.
- Barème du plugin : +1 par bonne réponse, aucune pénalité. C'est un outil d'attention et
  d'apprentissage.

## Le QCM en classe (`livequestions`)

- 5 à 8 questions **différentes** de celles de la vidéo, qui font transférer la notion à une
  situation nouvelle.
- Barème du plugin : +1 bonne réponse, −0,25 mauvaise, +0,25 pour « je ne sais pas ».
- Il reste **formatif** : pas d'EFE par défaut. L'évaluation qui compte est le test sommatif.

## Réglages

Rôle `video` dans `reglages-moodle.md` : note sur 10, achèvement « terminer le visionnage », pas
d'EFE, visionnage hors des heures de cours (réglage du site).

// Réglages Moodle de chaque rôle d'activité du skill moodle-ciel
// (skills/moodle-ciel/references/reglages-moodle.md). Le manifeste d'un module
// ne contient que les écarts voulus (« overrides »), appliqués en dernier.

/** Rôles que le pont sait déployer, et le module Moodle de chacun. */
export const ROLE_MODULES = {
  lecon: 'lesson',
  entrainement: 'quiz',
  td: 'quiz',
  'quiz-ia': 'quiz',
  sommatif: 'quiz',
  finale: 'quiz',
  tp: 'assign',
  page: 'page',
  label: 'label',
  url: 'url',
};

const REVIEW_FIELDS = ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback',
  'rightanswer', 'overallfeedback'];
const REVIEW_TIMES = ['during', 'immediately', 'open', 'closed'];

/**
 * Options de relecture d'un test au format du formulaire (attemptduring...).
 *
 * @param {Record<string, string[]>} shown champ → moments où il est montré
 */
export function review(shown) {
  const out = {};
  for (const field of REVIEW_FIELDS) {
    for (const time of REVIEW_TIMES) {
      out[`${field}${time}`] = (shown[field] ?? []).includes(time) ? 1 : 0;
    }
  }
  return out;
}

const ALL = ['immediately', 'open', 'closed'];
const AFTER = ['immediately', 'open'];

/** Achèvement « une tentative faite ». */
const QUIZ_COMPLETION = { completion: 2, completionminattempts: 1 };

/** QCM d'entraînement : fait à la maison, sans EFE ; la bonne réponse et le feedback général ne sont jamais montrés. */
function entrainement(activity) {
  return {
    attempts: 0, grademethod: 1, delay1: 86400, delay2: 172800,
    timelimit: Math.round((activity.timelimit_minutes ?? 10) * 60),
    questionsperpage: 1, navmethod: 'free', shuffleanswers: 1, preferredbehaviour: 'deferredfeedback',
    grade: 100, gradepass: 0, showuserpicture: 0, showblocks: 0,
    ...review({
      attempt: ['during', ...ALL], correctness: ALL, maxmarks: ALL, marks: ALL, specificfeedback: ALL,
      overallfeedback: ALL, generalfeedback: [], rightanswer: [],
    }),
    ...QUIZ_COMPLETION,
  };
}

/**
 * TD (travail dirigé) : textes de guidage et questions dans l'ordre, sur une seule page, en mode interactif
 * (bouton Vérifier, indices, nouvel essai) ; tout est montré. Non noté : note maximale 0, donc rien dans le carnet
 * de notes ; ni EFE ni surveillance.
 */
function td() {
  return {
    attempts: 0, grademethod: 1, delay1: 0, delay2: 0, timelimit: 0,
    questionsperpage: 0, navmethod: 'free', shuffleanswers: 1, preferredbehaviour: 'interactive',
    grade: 0, gradepass: 0, showuserpicture: 0, showblocks: 0,
    // « Pendant la tentative » : ce que montre le bouton Vérifier (l'appréciation globale n'existe qu'à la fin).
    ...review(Object.fromEntries(REVIEW_FIELDS.map((f) => [f, f === 'overallfeedback' ? ALL : ['during', ...ALL]]))),
    ...QUIZ_COMPLETION,
  };
}

/** Test sommatif surveillé : la correction attend sa publication par le bloc Avancement (« après fermeture »). */
function sommatif() {
  return {
    timeopen: 0, timeclose: 0, timelimit: 0, attempts: 1, grademethod: 1, delay1: 0, delay2: 0,
    questionsperpage: 1, navmethod: 'free', shuffleanswers: 1, preferredbehaviour: 'deferredfeedback',
    grade: 20, gradepass: 0, showuserpicture: 2, showblocks: 0,
    ...review({
      attempt: ['during', 'closed'], maxmarks: ['during', ...ALL], marks: ALL, overallfeedback: ALL,
      correctness: ['closed'], specificfeedback: ['closed'], generalfeedback: ['closed'], rightanswer: ['closed'],
    }),
    ...QUIZ_COMPLETION,
  };
}

/** Test court de questions IA : exercice d'apprentissage, tout est montré juste après. */
function quizIa(activity) {
  return {
    attempts: 1, grademethod: 1, timelimit: Math.round((activity.timelimit_minutes ?? 0) * 60),
    questionsperpage: 1, navmethod: 'free', shuffleanswers: 1, preferredbehaviour: 'deferredfeedback',
    grade: activity.points ?? 10, showuserpicture: 0, showblocks: 0,
    ...review(Object.fromEntries(REVIEW_FIELDS.map((f) => [f, f === 'attempt' ? ['during', ...ALL] : ALL]))),
    ...QUIZ_COMPLETION,
  };
}

/** Champs EFE du formulaire (local_efenotes). */
function efe(activity, manifest) {
  if (!activity.efe) {
    return {};
  }
  const punct = activity.efe.ponctualite ?? [];
  return {
    efenotes_enabled: 1,
    efenotes_competence: activity.efe.competences ?? [],
    efenotes_punct_enabled: punct.length ? 1 : 0,
    efenotes_punct_competence: punct,
    efenotes_report_after_due: activity.efe.after_due === false ? 0 : 1,
    ...(manifest.course?.teacher_id ? { efenotes_prof_moodleid: manifest.course.teacher_id } : {}),
  };
}

const WEBSEARCH = { none: 0, web: 1, material: 2 };

/** TP : devoir avec Correction IA, Tuteur IA et EFE ; 2 tentatives, réouverture à la main. */
function tp(activity, manifest) {
  const submission = activity.submission ?? { type: 'onlinetext' };
  const files = submission.type === 'file';
  const tutor = activity.tutor ?? { websearch: 'none' };
  return {
    alwaysshowdescription: 0, submissiondrafts: 1, requiresubmissionstatement: 0,
    assignsubmission_onlinetext_enabled: files ? 0 : 1,
    assignsubmission_file_enabled: files ? 1 : 0,
    ...(files ? {
      assignsubmission_file_maxfiles: submission.maxfiles ?? 1,
      assignsubmission_file_maxsizebytes: 0,
      assignsubmission_file_filetypes: submission.filetypes ?? '.pdf',
    } : {}),
    assignsubmission_comments_enabled: 1,
    maxattempts: 2, attemptreopenmethod: 'manual',
    grade: { scale: 'Barème officiel' },
    duedate: activity.duedate ?? 0, cutoffdate: 0,
    completion: 2, completionsubmit: 1,
    assignfeedback_comments_enabled: 1, assignfeedback_file_enabled: 1, assignfeedback_editpdf_enabled: 0,
    assignfeedback_offline_enabled: 0,
    assignfeedback_ai_enabled: 1, assignfeedback_ai_systemprompt: '', assignfeedback_ai_autograde: 1,
    // vision absente : réglage du site ; true ou false : imposé à ce devoir (case « remplacer » cochée).
    assignfeedback_ai_vision_enabled: activity.vision ? 1 : 0,
    assignfeedback_ai_vision_enabled_override: typeof activity.vision === 'boolean' ? 1 : 0,
    aichatenabled: 1, aichatincludeintro: 0, aichatincludebrief: 1,
    aichatwebsearch: WEBSEARCH[tutor.websearch] ?? 0,
    ...(tutor.sites ? { aichatwebsearchsites: tutor.sites.join('\n') } : {}),
    ...efe(activity, manifest),
  };
}

const LECON = {
  custom: 1, grade: 0, maxattempts: 1, review: 0, retake: 0, modattempts: 0, practice: 0,
  progressbar: 0, displayleft: 0, completion: 2, completionendreached: 1,
};

/**
 * Champs du formulaire Moodle d'une activité du manifeste : réglages du rôle,
 * EFE, puis écarts voulus (overrides).
 *
 * @param {object} activity activité du manifeste
 * @param {object} manifest manifeste complet (enseignant pour EFE...)
 */
export function roleSettings(activity, manifest) {
  let base;
  switch (activity.role) {
    case 'lecon': base = { ...LECON }; break;
    case 'entrainement': base = entrainement(activity); break;
    case 'td': base = td(); break;
    case 'sommatif': base = { ...sommatif(), ...efe(activity, manifest) }; break;
    case 'finale': base = { ...sommatif(), ...efe(activity, manifest) }; break;
    case 'quiz-ia': base = { ...quizIa(activity), ...efe(activity, manifest) }; break;
    case 'tp': base = tp(activity, manifest); break;
    case 'page': base = { completion: 0, printintro: 0, printlastmodified: 0 }; break;
    case 'label': base = {}; break;
    case 'url': base = { externalurl: activity.url ?? '', completion: 0 }; break;
    default: throw new Error(`rôle « ${activity.role} » non déployable par le pont`);
  }
  return { ...base, ...(activity.overrides ?? {}) };
}

/** Le test mélange-t-il ses questions ? (sommatif oui, finale non : le dossier vient en premier.) */
export function shuffles(role) {
  return role === 'sommatif' || role === 'quiz-ia';
}

YUI.add('moodle-availability_classhours-form', function (Y, NAME) {

/**
 * JavaScript du formulaire de la condition « Pendant les heures de cours ».
 *
 * La condition n'a pas de réglage : on affiche le résumé des créneaux du
 * cours et un lien vers la page Heures de cours. Une condition posée par
 * l'option EFE garde son marqueur « efe » à l'enregistrement du formulaire.
 *
 * Pas d'étape de build dans ce dépôt : les fichiers de yui/build sont des
 * copies de ce fichier enveloppées dans YUI.add().
 *
 * @module moodle-availability_classhours-form
 */
M.availability_classhours = M.availability_classhours || {};

/**
 * @class M.availability_classhours.form
 * @extends M.core_availability.plugin
 */
M.availability_classhours.form = Y.Object(M.core_availability.plugin);

/**
 * @method initInner
 * @param {String} summary résumé des créneaux (HTML déjà échappé côté serveur)
 * @param {String} url page Heures de cours du cours
 * @param {Boolean} hasslots le cours a-t-il des créneaux ?
 */
M.availability_classhours.form.initInner = function(summary, url, hasslots) {
    this.summary = summary;
    this.url = url;
    this.hasslots = hasslots;
};

M.availability_classhours.form.getNode = function(json) {
    var str = function(key) {
        return M.util.get_string(key, 'availability_classhours');
    };
    var html = '<span class="availability_classhours">' +
            '<span class="pe-2 fw-bold">' + str('form_label') + '</span>';
    if (this.hasslots) {
        if (this.summary) {
            html += '<span class="pe-2 text-muted">' + this.summary + '</span>';
        }
    } else {
        html += '<span class="pe-2 text-danger">' + str('noslots_form') + '</span>';
    }
    html += '<a href="' + Y.Escape.html(this.url) + '" target="_blank" rel="noopener">' + str('configure') + '</a>';
    if (json.efe) {
        html += '<br><small class="text-muted">' + str('efe_form') + '</small>';
    }
    html += '</span>';

    var node = Y.Node.create('<span class="d-flex flex-wrap align-items-center">' + html + '</span>');
    node.setData('efe', json.efe ? 1 : 0);
    return node;
};

M.availability_classhours.form.fillValue = function(value, node) {
    if (node.getData('efe')) {
        value.efe = 1;
    }
};

}, '@VERSION@', {"requires": ["base", "node", "event", "escape", "moodle-core_availability-form"]});

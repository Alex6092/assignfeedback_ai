/**
 * JavaScript du formulaire de la condition « Activité surveillée ».
 *
 * La condition n'a pas de réglage : on affiche une explication et le lien vers
 * la page Activités surveillées, où l'enseignant ouvre et ferme l'activité.
 *
 * Pas d'étape de build dans ce dépôt : les fichiers de yui/build sont des
 * copies de ce fichier enveloppées dans YUI.add().
 *
 * @module moodle-availability_supervised-form
 */
M.availability_supervised = M.availability_supervised || {};

/**
 * @class M.availability_supervised.form
 * @extends M.core_availability.plugin
 */
M.availability_supervised.form = Y.Object(M.core_availability.plugin);

/**
 * @method initInner
 * @param {String} url page Activités surveillées du cours
 */
M.availability_supervised.form.initInner = function(url) {
    this.url = url;
};

M.availability_supervised.form.getNode = function() {
    var str = function(key) {
        return M.util.get_string(key, 'availability_supervised');
    };
    var html = '<span class="availability_supervised">' +
            '<span class="pe-2 fw-bold">' + str('form_label') + '</span>' +
            '<span class="pe-2 text-muted">' + str('form_help') + '</span>' +
            '<a href="' + Y.Escape.html(this.url) + '" target="_blank" rel="noopener">' + str('manage_link') + '</a>' +
            '</span>';
    return Y.Node.create('<span class="d-flex flex-wrap align-items-center">' + html + '</span>');
};

M.availability_supervised.form.fillValue = function() {
    // Aucun paramètre : {"type":"supervised"}.
};

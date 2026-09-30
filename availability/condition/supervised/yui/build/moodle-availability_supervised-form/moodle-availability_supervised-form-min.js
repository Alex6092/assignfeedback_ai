YUI.add('moodle-availability_supervised-form', function (Y, NAME) {

/**
 * JavaScript du formulaire de la condition « Activité surveillée ».
 *
 * La condition n'a pas de réglage : on affiche une explication et le lien vers
 * la page Activités surveillées, où l'enseignant ouvre et ferme l'activité.
 * Un verrou ({"type":"supervised","lock":cmid} : activité liée, fermée pendant
 * une activité surveillée) s'affiche en lecture seule et garde son activité à
 * l'enregistrement.
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
 * @param {Object} names activités surveillées du cours (cmid => nom)
 */
M.availability_supervised.form.initInner = function(url, names) {
    this.url = url;
    this.names = names || {};
};

M.availability_supervised.form.getNode = function(json) {
    var str = function(key, a) {
        return M.util.get_string(key, 'availability_supervised', a);
    };
    var html;
    if (json.lock) {
        var name = this.names[json.lock];
        html = '<span class="availability_supervised">' +
            '<span class="pe-2">' + (name ? str('form_lock', Y.Escape.html(name)) : str('desc_lock_missing')) +
            '</span>' +
            '<a href="' + Y.Escape.html(this.url) + '" target="_blank" rel="noopener">' + str('manage_link') + '</a>' +
            '</span>';
    } else {
        html = '<span class="availability_supervised">' +
            '<span class="pe-2 fw-bold">' + str('form_label') + '</span>' +
            '<span class="pe-2 text-muted">' + str('form_help') + '</span>' +
            '<a href="' + Y.Escape.html(this.url) + '" target="_blank" rel="noopener">' + str('manage_link') + '</a>' +
            '</span>';
    }
    var node = Y.Node.create('<span class="d-flex flex-wrap align-items-center">' + html + '</span>');
    node.setData('lock', json.lock ? json.lock : 0);
    return node;
};

M.availability_supervised.form.fillValue = function(value, node) {
    var lock = node.getData('lock');
    if (lock) {
        value.lock = lock;
    }
};

}, '@VERSION@', {"requires": ["base", "node", "event", "escape", "moodle-core_availability-form"]});

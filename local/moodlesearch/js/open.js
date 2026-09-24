/**
 * MoodleSearch — page d'attente d'un clic : demande l'ouverture du site pour
 * la classe (ajax_open.php), puis y conduit, ou explique pourquoi il reste
 * fermé (liste noire, mode examen, demande en attente, poste non déclaré).
 *
 * JavaScript « nu », sans étape de compilation, comme le widget du Tuteur IA.
 *
 * @param {Object} p {ajaxurl, clickid, sesskey, url, errormessage}
 */
window.localMoodlesearchOpen = function(p) {
    'use strict';

    var box = document.getElementById('moodlesearch-open');
    if (!box) {
        return;
    }
    var show = function(d) {
        box.querySelector('.moodlesearch-open-wait').hidden = true;
        var msg = box.querySelector('.moodlesearch-open-message');
        msg.textContent = d.message;
        msg.className = 'alert alert-' + (d.level || 'warning') + ' moodlesearch-open-message';
        msg.hidden = false;
        if (d.showlink) {
            box.querySelector('.moodlesearch-open-link').hidden = false;
        }
        if (d.macurl) {
            var mac = box.querySelector('.moodlesearch-open-mac');
            mac.href = d.macurl;
            mac.hidden = false;
        }
    };

    var body = new URLSearchParams();
    body.set('clickid', p.clickid);
    body.set('sesskey', p.sesskey);
    fetch(p.ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
    }).then(function(response) {
        return response.json();
    }).then(function(d) {
        if (d.go) {
            window.location.replace(p.url);
        } else {
            show(d);
        }
    }).catch(function() {
        show({message: p.errormessage, level: 'warning', showlink: true});
    });
};

/**
 * Widget du tuteur IA (local_aichat).
 *
 * JavaScript « nu » (pas d'AMD, pas d'étape de compilation) : le plugin sert ce
 * fichier tel quel, ce qui évite une chaîne de build dans un dépôt qui n'en a
 * pas. Cycle d'une question :
 *
 *   send  → ajax.php (crée les messages + prend un ticket de file)
 *   poll  → ajax.php (position dans la file, puis « ready »)
 *   flux  → stream.php (SSE : les fragments s'affichent à mesure)
 *
 * Le texte en cours de génération est inséré avec textContent (jamais de HTML
 * brut venant du modèle) ; la version finale est du HTML assaini par le serveur.
 */
(function () {
    'use strict';

    var root = document.getElementById('local-aichat-root');
    if (!root || root.dataset.aichatReady) {
        return;
    }
    root.dataset.aichatReady = '1';

    var CFG;
    try {
        CFG = JSON.parse(root.getAttribute('data-config'));
    } catch (e) {
        return;
    }
    var S = CFG.strings || {};
    var STORAGE_KEY = 'local_aichat_open_' + CFG.cmid;
    var SIZE_KEY = 'local_aichat_size';   // taille choisie, commune aux activités
    var MIN_W = 320;
    var MIN_H = 320;

    // --- État ---------------------------------------------------------------
    var state = 'idle';        // idle | sending | queued | streaming
    var currentMessageId = 0;
    var pollTimer = null;
    var controller = null;     // AbortController du flux en cours
    var streamNode = null;     // noeud texte de la réponse en cours

    // --- Construction de l'interface ----------------------------------------
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    var toggle = el('button', 'local-aichat-toggle');
    toggle.type = 'button';
    toggle.setAttribute('aria-label', S.widget_open || 'Tuteur IA');
    toggle.title = S.widget_open || 'Tuteur IA';
    toggle.innerHTML = '<span class="local-aichat-toggle-icon" aria-hidden="true">&#128172;</span>';
    toggle.appendChild(el('span', 'local-aichat-toggle-label', S.widget_title || 'Tuteur IA'));

    var panel = el('section', 'local-aichat-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', S.widget_title || 'Tuteur IA');
    panel.hidden = true;

    var header = el('header', 'local-aichat-header');
    header.appendChild(el('h2', 'local-aichat-title', S.widget_title || 'Tuteur IA'));
    var btnNew = el('button', 'local-aichat-iconbtn', '↻');
    btnNew.type = 'button';
    btnNew.title = S.widget_new || '';
    btnNew.setAttribute('aria-label', S.widget_new || '');
    var btnClose = el('button', 'local-aichat-iconbtn', '✕');
    btnClose.type = 'button';
    btnClose.title = S.widget_close || '';
    btnClose.setAttribute('aria-label', S.widget_close || '');
    header.appendChild(btnNew);
    header.appendChild(btnClose);
    panel.appendChild(header);

    // Poignée de redimensionnement, dans le coin opposé à l'ancrage.
    var resizer = el('button', 'local-aichat-resize', '⤡');
    resizer.type = 'button';
    resizer.title = S.widget_resize || '';
    resizer.setAttribute('aria-label', S.widget_resize || '');
    panel.appendChild(resizer);

    if (CFG.notice) {
        panel.appendChild(el('p', 'local-aichat-notice', CFG.notice));
    }
    if (CFG.needkeys && CFG.keysurl) {
        // Recherche Web autorisée sur l'activité, mais aucune clé personnelle.
        var keysnote = el('p', 'local-aichat-notice', (S.widget_needkeys || '') + ' ');
        var keyslink = el('a', '', S.widget_needkeys_link || '');
        keyslink.href = CFG.keysurl;
        keysnote.appendChild(keyslink);
        panel.appendChild(keysnote);
    }

    var list = el('div', 'local-aichat-messages');
    list.setAttribute('aria-live', 'polite');
    list.setAttribute('aria-atomic', 'false');
    panel.appendChild(list);

    var status = el('div', 'local-aichat-status');
    status.hidden = true;
    panel.appendChild(status);

    // Fichiers joints en attente d'envoi (sous la zone de saisie).
    var ATTACH = CFG.attach || null;
    var pending = [];          // {file, url (aperçu d'image ou '')}
    var chips = el('div', 'local-aichat-chips');
    chips.hidden = true;
    panel.appendChild(chips);

    var form = el('form', 'local-aichat-form');
    var fileInput = null;
    var btnAttach = null;
    if (ATTACH) {
        fileInput = el('input', 'local-aichat-fileinput');
        fileInput.type = 'file';
        fileInput.multiple = true;
        fileInput.accept = ATTACH.accept || '';
        fileInput.hidden = true;
        btnAttach = el('button', 'local-aichat-attach', '📎');
        btnAttach.type = 'button';
        btnAttach.title = S.widget_attach || '';
        btnAttach.setAttribute('aria-label', S.widget_attach || '');
        form.appendChild(fileInput);
        form.appendChild(btnAttach);
    }
    var input = el('textarea', 'local-aichat-input');
    input.rows = 2;
    input.placeholder = S.widget_placeholder || '';
    input.setAttribute('aria-label', S.widget_placeholder || '');
    input.maxLength = CFG.maxchars || 2000;
    var btnSend = el('button', 'local-aichat-send', S.widget_send || '');
    btnSend.type = 'submit';
    var btnStop = el('button', 'local-aichat-stop', S.widget_stop || '');
    btnStop.type = 'button';
    btnStop.hidden = true;
    form.appendChild(input);
    form.appendChild(btnSend);
    form.appendChild(btnStop);
    panel.appendChild(form);

    var quotaLine = el('p', 'local-aichat-quota');
    quotaLine.hidden = true;
    panel.appendChild(quotaLine);

    document.body.appendChild(toggle);
    document.body.appendChild(panel);

    // --- Rendu des messages -------------------------------------------------
    function addBubble(role, cssextra) {
        var wrap = el('div', 'local-aichat-msg local-aichat-' + role
            + (cssextra ? ' ' + cssextra : ''));
        var body = el('div', 'local-aichat-body');
        wrap.appendChild(body);
        list.appendChild(wrap);
        scrollDown();
        return body;
    }

    // Pièces d'un message : miniatures cliquables pour les images, lien de
    // téléchargement pour le reste. items : {name, kind, size, url}.
    function renderAttachments(body, items) {
        if (!items || !items.length) {
            return;
        }
        var box = el('div', 'local-aichat-files');
        items.forEach(function (item) {
            // Sans adresse (fichier pas encore enregistré) : simple étiquette.
            var link = el(item.url ? 'a' : 'span', 'local-aichat-file');
            if (item.url) {
                link.href = item.url;
                link.target = '_blank';
                link.rel = 'noopener';
            }
            link.title = item.name + (item.size ? ' (' + item.size + ')' : '');
            if (item.kind === 'image' && item.url) {
                var img = el('img', 'local-aichat-thumb');
                img.src = item.url;
                img.alt = item.name;
                link.appendChild(img);
            } else {
                link.textContent = '📄 ' + item.name;
            }
            box.appendChild(link);
        });
        body.appendChild(box);
    }

    function renderUser(text, items) {
        var body = addBubble('user');
        if (text) {
            body.appendChild(el('div', 'local-aichat-usertext', text));
        }
        renderAttachments(body, items);
        scrollDown();
        return body;
    }

    function renderMessage(message) {
        if (message.role === 'user') {
            renderUser(message.content || '', message.attachments);
            return;
        }
        var extra = (message.status === 'failed') ? 'local-aichat-error' : '';
        var body = addBubble('assistant', extra);
        if (message.html) {
            body.innerHTML = message.html; // assaini côté serveur (clean_text)
        } else if (message.status === 'failed') {
            body.textContent = message.error || S.widget_networkerror || '';
        } else if (message.status === 'cancelled') {
            body.textContent = S.widget_interrupted || '';
        }
        if (message.status === 'cancelled' && message.html) {
            body.appendChild(el('p', 'local-aichat-interrupted', S.widget_interrupted || ''));
        }
    }

    function clearList() {
        list.innerHTML = '';
    }

    function showWelcome() {
        if (list.children.length === 0 && S.widget_welcome) {
            list.appendChild(el('p', 'local-aichat-welcome', S.widget_welcome));
        }
    }

    function scrollDown() {
        list.scrollTop = list.scrollHeight;
    }

    function setStatus(text, kind) {
        if (!text) {
            status.hidden = true;
            status.textContent = '';
            return;
        }
        status.className = 'local-aichat-status' + (kind ? ' local-aichat-status-' + kind : '');
        status.textContent = text;
        status.hidden = false;
    }

    // 850 → « 850 », 2340 → « 2,3k », 20000 → « 20k ».
    function kilo(n) {
        if (n < 1000) {
            return String(n);
        }
        var k = (n < 10000) ? Math.round(n / 100) / 10 : Math.round(n / 1000);
        return String(k).replace('.', CFG.decsep || '.') + 'k';
    }

    function setQuota(quota) {
        if (!quota || (!quota.messagesmax && !quota.tokensmax)) {
            quotaLine.hidden = true;
            return;
        }
        var parts = [];
        if (quota.messagesmax) {
            parts.push(quota.messagesused + '/' + quota.messagesmax);
        }
        if (quota.tokensmax) {
            parts.push(kilo(quota.tokensused) + '/' + kilo(quota.tokensmax));
        }
        // Fenêtre ouverte : l'élève sait quand ses compteurs repartent de zéro.
        if (quota.resetlabel) {
            parts.push((S.widget_quota_reset || '').replace('{$a}', function () {
                return quota.resetlabel;
            }));
        }
        quotaLine.textContent = (S.widget_quota || '') + ' ' + parts.join(' · ');
        quotaLine.hidden = false;
    }

    function setBusy(busy) {
        btnSend.hidden = busy;
        btnStop.hidden = !busy;
        input.disabled = busy;
        if (btnAttach) {
            btnAttach.disabled = busy;
        }
    }

    // --- Fichiers joints ----------------------------------------------------
    // Les contrôles d'ici évitent un envoi voué au refus ; le serveur refait
    // tous les contrôles.

    // Remplit {$a} / {$a->clé} d'une chaîne (résolue sans paramètre côté serveur).
    function fill(text, values) {
        return (text || '').replace(/\{\$a(?:->(\w+))?\}/g, function (all, key) {
            var value = key ? values[key] : values.a;
            return (value === undefined) ? all : String(value);
        });
    }

    function extensionOf(name) {
        var pos = name.lastIndexOf('.');
        return (pos > 0) ? name.slice(pos).toLowerCase() : '';
    }

    function isImage(file) {
        return /^image\/(png|jpeg|gif|webp)$/.test(file.type || '');
    }

    function refusal(file) {
        if (pending.length >= ATTACH.maxfiles) {
            return fill(S.attach_toomany, {a: ATTACH.maxfiles});
        }
        var ext = extensionOf(file.name);
        if ((ATTACH.extensions || []).indexOf(ext) < 0
                && (ATTACH.names || []).indexOf(file.name.toLowerCase()) < 0) {
            return fill((isImage(file) && !ATTACH.images) ? S.attach_noimages : S.attach_badtype,
                {a: file.name});
        }
        if (file.size > ATTACH.maxbytes) {
            return fill(S.attach_toobig, {name: file.name, max: ATTACH.maxlabel});
        }
        var total = pending.reduce(function (sum, item) {
            return sum + item.file.size;
        }, file.size);
        if (ATTACH.maxtotal && total > ATTACH.maxtotal) {
            return fill(S.attach_toobigtotal, {a: ATTACH.totallabel});
        }
        return '';
    }

    function addFiles(list) {
        if (!ATTACH || state !== 'idle') {
            return;
        }
        var refused = '';
        Array.prototype.forEach.call(list, function (file) {
            var reason = refusal(file);
            if (reason) {
                refused = refused || reason;
                return;
            }
            pending.push({file: file, url: (isImage(file) && window.URL) ? window.URL.createObjectURL(file) : ''});
        });
        renderChips();
        setStatus(refused, refused ? 'error' : '');
        input.focus();
    }

    function revoke(item) {
        if (item.url && window.URL) {
            window.URL.revokeObjectURL(item.url);
        }
    }

    function renderChips() {
        chips.innerHTML = '';
        pending.forEach(function (item, index) {
            var chip = el('span', 'local-aichat-chip');
            if (item.url) {
                var thumb = el('img', 'local-aichat-chipthumb');
                thumb.src = item.url;
                thumb.alt = '';
                chip.appendChild(thumb);
            }
            chip.appendChild(el('span', 'local-aichat-chipname', item.file.name));
            var remove = el('button', 'local-aichat-chipremove', '✕');
            remove.type = 'button';
            remove.title = fill(S.widget_attach_remove, {a: item.file.name});
            remove.setAttribute('aria-label', remove.title);
            remove.addEventListener('click', function () {
                revoke(pending.splice(index, 1)[0]);
                renderChips();
            });
            chip.appendChild(remove);
            chips.appendChild(chip);
        });
        chips.hidden = (pending.length === 0);
    }

    // Capture collée : le navigateur la nomme « image.png » ; on lui donne
    // l'heure, pour que l'élève et l'enseignant s'y retrouvent.
    function pastedName(file, index) {
        var d = new Date();
        var pad = function (n) {
            return (n < 10 ? '0' : '') + n;
        };
        var ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        return 'capture-' + pad(d.getHours()) + pad(d.getMinutes()) + pad(d.getSeconds())
            + (index ? '-' + index : '') + '.' + ext;
    }

    function hasFiles(event) {
        var types = event.dataTransfer && event.dataTransfer.types;
        return !!types && Array.prototype.indexOf.call(types, 'Files') >= 0;
    }

    // --- Appels réseau ------------------------------------------------------
    // Avec des fichiers : multipart (FormData) ; sinon formulaire encodé.
    function post(action, params, files) {
        var fields = {cmid: CFG.cmid, sesskey: CFG.sesskey, action: action};
        Object.keys(params || {}).forEach(function (key) {
            fields[key] = params[key];
        });
        var body;
        var headers = {};
        if (files && files.length) {
            body = new FormData();
            Object.keys(fields).forEach(function (key) {
                body.append(key, fields[key]);
            });
            files.forEach(function (file) {
                body.append('files[]', file, file.name);
            });
        } else {
            var encoded = new URLSearchParams();
            Object.keys(fields).forEach(function (key) {
                encoded.set(key, fields[key]);
            });
            body = encoded.toString();
            headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
        }
        return fetch(CFG.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: body
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function fail(message) {
        stopPolling();
        state = 'idle';
        setBusy(false);
        setStatus(message || S.widget_networkerror, 'error');
    }

    // --- File d'attente -----------------------------------------------------
    function stopPolling() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function schedulePoll() {
        stopPolling();
        pollTimer = window.setTimeout(pollOnce, CFG.pollinterval || 1500);
    }

    function pollOnce() {
        if (!currentMessageId) {
            return;
        }
        post('poll', {messageid: currentMessageId}).then(function (data) {
            if (!data.ok) {
                fail(data.message);
                return;
            }
            if (data.quota) {
                setQuota(data.quota);
            }
            if (data.status === 'queued') {
                var n = data.position || 0;
                setStatus(n > 0
                    ? (S.widget_queued_n || '').replace('{$a}', n)
                    : (S.widget_queued_next || ''), 'queue');
                schedulePoll();
                return;
            }
            if (data.status === 'ready') {
                openStream();
                return;
            }
            if (data.status === 'running') {
                // Un autre onglet génère déjà cette réponse : on attend qu'elle
                // soit enregistrée plutôt que de lancer un second flux.
                setStatus(S.widget_generating || '', 'queue');
                schedulePoll();
                return;
            }
            if (data.status === 'done') {
                finishFromServer(data.html);
                return;
            }
            if (data.status === 'cancelled') {
                stopPolling();
                state = 'idle';
                setBusy(false);
                setStatus('');
                return;
            }
            fail(data.error || S.widget_networkerror);
        }).catch(function () {
            fail(S.widget_networkerror);
        });
    }

    // --- Flux de génération -------------------------------------------------
    function openStream() {
        stopPolling();
        state = 'streaming';
        setStatus(S.widget_generating || '', 'queue');

        var body = addBubble('assistant', 'local-aichat-streaming');
        streamNode = body;

        controller = new AbortController();
        var payload = new URLSearchParams();
        payload.set('cmid', CFG.cmid);
        payload.set('sesskey', CFG.sesskey);
        payload.set('messageid', currentMessageId);

        fetch(CFG.streamurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: payload.toString(),
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok || !response.body) {
                throw new Error('HTTP ' + response.status);
            }
            return readStream(response.body.getReader());
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return; // arrêt demandé par l'élève : déjà traité
            }
            fail(S.widget_networkerror);
        });
    }

    function readStream(reader) {
        var decoder = new TextDecoder();
        var buffer = '';

        function pump() {
            return reader.read().then(function (result) {
                if (result.done) {
                    if (state === 'streaming') {
                        // Flux coupé sans « done » : on demande l'état au serveur.
                        schedulePoll();
                    }
                    return;
                }
                buffer += decoder.decode(result.value, {stream: true});
                var index;
                while ((index = buffer.indexOf('\n\n')) >= 0) {
                    handleEvent(buffer.slice(0, index));
                    buffer = buffer.slice(index + 2);
                }
                return pump();
            });
        }
        return pump();
    }

    function handleEvent(block) {
        var name = '';
        var raw = '';
        block.split('\n').forEach(function (line) {
            if (line.indexOf('event:') === 0) {
                name = line.slice(6).trim();
            } else if (line.indexOf('data:') === 0) {
                raw += line.slice(5).trim();
            }
        });
        if (!name || !raw) {
            return; // commentaire de remplissage, ligne vide
        }
        var data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            return;
        }

        if (name === 'delta' && streamNode) {
            streamNode.textContent += data.t;
            scrollDown();
            setStatus('');
            return;
        }
        if (name === 'search') {
            // Recherche Web ou lecture d'une page en cours : effacée par le
            // fragment de texte suivant. (Remplacement par fonction : une requête
            // contenant « $& » ne doit pas être interprétée comme un motif.)
            var label = (data.tool === 'read') ? S.widget_reading : S.widget_searching;
            setStatus((label || '').replace('{$a}', function () {
                return data.q || '';
            }), 'queue');
            return;
        }
        if (name === 'done') {
            finishFromServer(data.html);
            if (data.quota) {
                setQuota(data.quota);
            }
            return;
        }
        if (name === 'error') {
            if (streamNode && streamNode.textContent === '') {
                streamNode.parentNode.remove();
            }
            streamNode = null;
            fail(data.message);
        }
    }

    function finishFromServer(html) {
        stopPolling();
        if (streamNode) {
            if (html) {
                streamNode.innerHTML = html; // assaini côté serveur
            }
            streamNode.parentNode.classList.remove('local-aichat-streaming');
            streamNode = null;
        } else if (html) {
            renderMessage({role: 'assistant', html: html, status: 'done'});
        }
        state = 'idle';
        currentMessageId = 0;
        controller = null;
        setBusy(false);
        setStatus('');
        scrollDown();
        input.focus();
    }

    // --- Actions ------------------------------------------------------------
    function send() {
        var text = input.value.trim();
        if ((text === '' && pending.length === 0) || state !== 'idle') {
            return;
        }
        state = 'sending';
        setBusy(true);
        setStatus(S.widget_connecting || '', 'queue');

        var welcome = list.querySelector('.local-aichat-welcome');
        if (welcome) {
            welcome.remove();
        }
        // Bulle affichée tout de suite, avec les aperçus locaux des images.
        var sent = pending;
        var bubble = renderUser(text, sent.map(function (item) {
            return {name: item.file.name, kind: item.url ? 'image' : 'file', url: item.url, size: ''};
        }));
        input.value = '';
        pending = [];
        renderChips();

        post('send', {content: text}, sent.map(function (item) {
            return item.file;
        })).then(function (data) {
            if (!data.ok) {
                // Rien n'a été enregistré : l'élève retrouve son message et
                // ses fichiers, pour corriger et renvoyer.
                bubble.parentNode.remove();
                if (input.value === '') {
                    input.value = text;
                }
                pending = sent.concat(pending);
                renderChips();
                showWelcome();
                fail(data.message);
                if (data.error === 'quota' && data.quota) {
                    setQuota(data.quota);
                }
                return;
            }
            // Aperçus locaux remplacés par les fichiers enregistrés.
            if (sent.length && data.usermessage) {
                var box = bubble.querySelector('.local-aichat-files');
                if (box) {
                    box.remove();
                }
                renderAttachments(bubble, data.usermessage.attachments);
                sent.forEach(revoke);
            }
            currentMessageId = data.messageid;
            state = 'queued';
            if (data.quota) {
                setQuota(data.quota);
            }
            pollOnce();
        }).catch(function () {
            fail(S.widget_networkerror);
        });
    }

    function stop() {
        if (state === 'streaming' && controller) {
            controller.abort();       // le serveur détecte l'abandon et coupe le LLM
        } else if (currentMessageId) {
            post('cancel', {messageid: currentMessageId}).catch(function () {});
        }
        stopPolling();
        if (streamNode) {
            streamNode.parentNode.classList.remove('local-aichat-streaming');
            if (streamNode.textContent !== '') {
                streamNode.appendChild(el('p', 'local-aichat-interrupted',
                    S.widget_interrupted || ''));
            } else {
                streamNode.parentNode.remove();
            }
            streamNode = null;
        }
        state = 'idle';
        currentMessageId = 0;
        controller = null;
        setBusy(false);
        setStatus('');
    }

    function loadHistory(action) {
        post(action || 'history', {}).then(function (data) {
            if (!data.ok) {
                fail(data.message);
                return;
            }
            clearList();
            (data.messages || []).forEach(renderMessage);
            showWelcome();
            setQuota(data.quota);
            scrollDown();
            // Une réponse était en cours (rechargement de page, autre onglet).
            if (data.busymessageid) {
                currentMessageId = data.busymessageid;
                state = 'queued';
                setBusy(true);
                pollOnce();
            }
        }).catch(function () {
            fail(S.widget_networkerror);
        });
    }

    // --- Taille de la fenêtre ----------------------------------------------
    // Sous 600 px de large, la feuille de style occupe tout l'écran : il n'y a
    // rien à redimensionner, et une taille en dur y serait gênante.
    function canResize() {
        return window.innerWidth > 600;
    }

    function applySize(size) {
        if (!size || !canResize()) {
            return;
        }
        var maxw = Math.max(MIN_W, window.innerWidth - 40);
        var maxh = Math.max(MIN_H, window.innerHeight - 40);
        panel.style.width = Math.min(Math.max(size.w, MIN_W), maxw) + 'px';
        panel.style.height = Math.min(Math.max(size.h, MIN_H), maxh) + 'px';
    }

    function storedSize() {
        try {
            var size = JSON.parse(window.localStorage.getItem(SIZE_KEY));
            return (size && size.w > 0 && size.h > 0) ? size : null;
        } catch (e) {
            return null;   // stockage bloqué ou valeur abîmée
        }
    }

    function storeSize() {
        var rect = panel.getBoundingClientRect();
        try {
            window.localStorage.setItem(SIZE_KEY,
                JSON.stringify({w: Math.round(rect.width), h: Math.round(rect.height)}));
        } catch (e) {
            // simple confort
        }
    }

    function resetSize() {
        panel.style.width = '';
        panel.style.height = '';
        try {
            window.localStorage.removeItem(SIZE_KEY);
        } catch (e) {
            // idem
        }
    }

    var drag = null;

    resizer.addEventListener('pointerdown', function (event) {
        if (!canResize()) {
            return;
        }
        var rect = panel.getBoundingClientRect();
        drag = {x: event.clientX, y: event.clientY, w: rect.width, h: rect.height};
        panel.classList.add('local-aichat-resizing');
        if (resizer.setPointerCapture) {
            resizer.setPointerCapture(event.pointerId);
        }
        event.preventDefault();
    });

    resizer.addEventListener('pointermove', function (event) {
        if (drag) {
            // Coin haut-gauche : vers le haut et vers la gauche, ça agrandit.
            applySize({w: drag.w + (drag.x - event.clientX), h: drag.h + (drag.y - event.clientY)});
        }
    });

    function endDrag() {
        if (!drag) {
            return;
        }
        drag = null;
        panel.classList.remove('local-aichat-resizing');
        storeSize();
        scrollDown();
    }

    resizer.addEventListener('pointerup', endDrag);
    resizer.addEventListener('pointercancel', endDrag);
    resizer.addEventListener('dblclick', resetSize);

    // Au clavier : flèches pour ajuster, Origine pour la taille par défaut.
    resizer.addEventListener('keydown', function (event) {
        var rect = panel.getBoundingClientRect();
        var size = {w: rect.width, h: rect.height};
        if (event.key === 'Home') {
            resetSize();
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            size.w += (event.key === 'ArrowLeft') ? 40 : -40;
            applySize(size);
            storeSize();
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            size.h += (event.key === 'ArrowUp') ? 40 : -40;
            applySize(size);
            storeSize();
        } else {
            return;
        }
        event.preventDefault();
        scrollDown();
    });

    window.addEventListener('resize', function () {
        if (!canResize()) {
            panel.style.width = '';
            panel.style.height = '';
            return;
        }
        applySize(storedSize());
    });

    // --- Ouverture / fermeture ---------------------------------------------
    var loaded = false;

    function openPanel() {
        panel.hidden = false;
        applySize(storedSize());
        toggle.classList.add('local-aichat-hidden');
        try {
            window.localStorage.setItem(STORAGE_KEY, '1');
        } catch (e) {
            // Navigation privée ou stockage bloqué : simple confort, on ignore.
        }
        if (!loaded) {
            loaded = true;
            loadHistory('history');
        }
        input.focus();
    }

    function closePanel() {
        panel.hidden = true;
        toggle.classList.remove('local-aichat-hidden');
        try {
            window.localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            // idem
        }
    }

    toggle.addEventListener('click', openPanel);
    btnClose.addEventListener('click', closePanel);
    btnNew.addEventListener('click', function () {
        if (state !== 'idle') {
            stop();
        }
        loadHistory('newconv');
    });
    btnStop.addEventListener('click', stop);
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        send();
    });
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            send();
        }
    });

    if (ATTACH) {
        btnAttach.addEventListener('click', function () {
            fileInput.click();
        });
        fileInput.addEventListener('change', function () {
            addFiles(fileInput.files);
            fileInput.value = '';   // permet de rechoisir le même fichier
        });
        // Ctrl+V d'une capture d'écran. Un presse-papiers qui contient aussi
        // du texte (cellule de tableur, page Web) est collé comme texte.
        input.addEventListener('paste', function (event) {
            var items = (event.clipboardData && event.clipboardData.items) || [];
            var files = [];
            var hastext = false;
            Array.prototype.forEach.call(items, function (item) {
                if (item.kind === 'file') {
                    var file = item.getAsFile();
                    if (file) {
                        files.push(file);
                    }
                } else if (item.type === 'text/plain') {
                    hastext = true;
                }
            });
            if (!files.length || hastext) {
                return;
            }
            event.preventDefault();
            addFiles(files.map(function (file, index) {
                if (!isImage(file) || (file.name && file.name !== 'image.png')) {
                    return file;
                }
                try {
                    return new File([file], pastedName(file, index), {type: file.type});
                } catch (e) {
                    return file;
                }
            }));
        });
        // Glisser-déposer sur la fenêtre du tuteur.
        panel.addEventListener('dragover', function (event) {
            if (hasFiles(event)) {
                event.preventDefault();
                panel.classList.add('local-aichat-dropping');
            }
        });
        panel.addEventListener('dragleave', function (event) {
            if (!event.relatedTarget || !panel.contains(event.relatedTarget)) {
                panel.classList.remove('local-aichat-dropping');
            }
        });
        panel.addEventListener('drop', function (event) {
            if (hasFiles(event)) {
                event.preventDefault();
                panel.classList.remove('local-aichat-dropping');
                addFiles(event.dataTransfer.files);
            }
        });
    }

    try {
        if (window.localStorage.getItem(STORAGE_KEY) === '1') {
            openPanel();
        }
    } catch (e) {
        // stockage indisponible : le volet reste fermé par défaut
    }
}());

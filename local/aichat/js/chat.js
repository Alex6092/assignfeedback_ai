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

    if (CFG.notice) {
        panel.appendChild(el('p', 'local-aichat-notice', CFG.notice));
    }

    var list = el('div', 'local-aichat-messages');
    list.setAttribute('aria-live', 'polite');
    list.setAttribute('aria-atomic', 'false');
    panel.appendChild(list);

    var status = el('div', 'local-aichat-status');
    status.hidden = true;
    panel.appendChild(status);

    var form = el('form', 'local-aichat-form');
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

    function renderMessage(message) {
        if (message.role === 'user') {
            addBubble('user').textContent = message.content || '';
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
            parts.push(Math.round(quota.tokensused / 1000) + 'k/'
                + Math.round(quota.tokensmax / 1000) + 'k');
        }
        quotaLine.textContent = (S.widget_quota || '') + ' ' + parts.join(' · ');
        quotaLine.hidden = false;
    }

    function setBusy(busy) {
        btnSend.hidden = busy;
        btnStop.hidden = !busy;
        input.disabled = busy;
    }

    // --- Appels réseau ------------------------------------------------------
    function post(action, params) {
        var body = new URLSearchParams();
        body.set('cmid', CFG.cmid);
        body.set('sesskey', CFG.sesskey);
        body.set('action', action);
        Object.keys(params || {}).forEach(function (key) {
            body.set(key, params[key]);
        });
        return fetch(CFG.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
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
            // Recherche Web en cours : effacée par le fragment de texte suivant.
            // (remplacement par fonction : une requête contenant « $& » ne doit
            // pas être interprétée comme un motif de remplacement)
            setStatus((S.widget_searching || '').replace('{$a}', function () {
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
        if (text === '' || state !== 'idle') {
            return;
        }
        state = 'sending';
        setBusy(true);
        setStatus(S.widget_connecting || '', 'queue');

        var welcome = list.querySelector('.local-aichat-welcome');
        if (welcome) {
            welcome.remove();
        }
        addBubble('user').textContent = text;
        input.value = '';

        post('send', {content: text}).then(function (data) {
            if (!data.ok) {
                fail(data.message);
                if (data.error === 'quota' && data.quota) {
                    setQuota(data.quota);
                }
                return;
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

    // --- Ouverture / fermeture ---------------------------------------------
    var loaded = false;

    function openPanel() {
        panel.hidden = false;
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

    try {
        if (window.localStorage.getItem(STORAGE_KEY) === '1') {
            openPanel();
        }
    } catch (e) {
        // stockage indisponible : le volet reste fermé par défaut
    }
}());

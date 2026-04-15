<?php
/** @var \Kirby\Cms\Page $page */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($page->title()) ?> - Signage Onboarding</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #101316;
            --surface: rgba(24, 32, 38, 0.92);
            --surface-border: rgba(255, 255, 255, 0.1);
            --text: #f5f7f8;
            --muted: #a9b6bf;
            --accent: #f1c232;
            --danger: #ff7d66;
            --ok: #65d18a;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 2rem;
            background:
                radial-gradient(circle at top, rgba(241, 194, 50, 0.12), transparent 30%),
                linear-gradient(160deg, #0c0f12, var(--bg));
            color: var(--text);
            font-family: "Helvetica Neue", Arial, sans-serif;
        }

        .onboarding-card {
            width: min(42rem, 100%);
            padding: 2rem;
            border: 1px solid var(--surface-border);
            border-radius: 1.5rem;
            background: var(--surface);
            backdrop-filter: blur(18px);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.28);
        }

        .eyebrow {
            display: inline-flex;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            background: rgba(241, 194, 50, 0.14);
            color: var(--accent);
            font-size: 0.875rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        h1 {
            margin: 1rem 0 0.5rem;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1;
        }

        p {
            color: var(--muted);
            line-height: 1.5;
        }

        .status {
            margin-top: 1.5rem;
            padding: 1rem 1.25rem;
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--surface-border);
        }

        .status strong {
            display: block;
            margin-bottom: 0.35rem;
            color: var(--text);
        }

        .status.status-ok strong {
            color: var(--ok);
        }

        .status.status-denied strong {
            color: var(--danger);
        }

        .meta {
            display: grid;
            gap: 0.75rem;
            margin-top: 1.5rem;
            font-size: 0.95rem;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .meta-row span:first-child {
            color: var(--muted);
        }

        .uuid {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <main class="onboarding-card">
        <span class="eyebrow">Signage</span>
        <h1>Display wird verbunden</h1>
        <p>
            Dieses Gerät meldet sich zentral an und wartet auf die Zuordnung zu einem freigegebenen Bildschirm.
        </p>

        <section class="status" id="status-box">
            <strong id="status-title">Verbindung wird aufgebaut</strong>
            <div id="status-message">Bitte warten.</div>
        </section>

        <section class="meta">
            <div class="meta-row">
                <span>Backend</span>
                <span id="backend-host"></span>
            </div>
            <div class="meta-row">
                <span>Geräte-ID</span>
                <span class="uuid" id="device-uuid"></span>
            </div>
        </section>
    </main>

    <script>
        const onboarding = {
            apiBase: '<?= url('api/signage') ?>',
            uuidStorageKey: 'signage_onboarding_uuid',
            legacyUuidStorageKey: 'signage_device_uuid',
            statusEl: document.getElementById('status-box'),
            titleEl: document.getElementById('status-title'),
            messageEl: document.getElementById('status-message'),
            backendEl: document.getElementById('backend-host'),
            uuidEl: document.getElementById('device-uuid'),
            pollId: null,

            async init() {
                this.uuid = this.getOrCreateUUID();
                this.backendEl.textContent = window.location.origin;
                this.uuidEl.textContent = this.uuid;
                await this.register();
            },

            getOrCreateUUID() {
                let uuid = window.localStorage.getItem(this.uuidStorageKey);
                if (!uuid) {
                    uuid = window.localStorage.getItem(this.legacyUuidStorageKey);
                }

                if (!uuid) {
                    uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (char) {
                        const rand = Math.random() * 16 | 0;
                        const value = char === 'x' ? rand : (rand & 0x3 | 0x8);
                        return value.toString(16);
                    });
                }

                window.localStorage.setItem(this.uuidStorageKey, uuid);
                window.localStorage.setItem(this.legacyUuidStorageKey, uuid);

                return uuid;
            },

            async register() {
                this.render('Verbinde Gerät', 'Das Display meldet sich am Backend an.');

                try {
                    const response = await fetch(this.apiBase + '/onboarding/request', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            uuid: this.uuid,
                            backend: window.location.origin,
                            url: window.location.href,
                        }),
                    });

                    const data = await response.json();
                    this.handleStatus(data);
                } catch (error) {
                    this.render('Verbindung fehlgeschlagen', 'Das Gerät konnte nicht angemeldet werden.', 'status-denied');
                }
            },

            async refreshStatus() {
                try {
                    const response = await fetch(this.apiBase + '/onboarding-status/' + this.uuid);
                    const data = await response.json();
                    this.handleStatus(data);
                } catch (error) {
                    this.render('Warte auf Rueckmeldung', 'Status konnte nicht aktualisiert werden.');
                }
            },

            handleStatus(data) {
                if (data.access === 'granted' && data.screen) {
                    this.render('Bildschirm freigegeben', 'Weiterleitung zum zugewiesenen Screen.', 'status-ok');
                    this.stopPolling();
                    window.setTimeout(() => {
                        window.location.href = '<?= url('signage') ?>/' + data.screen;
                    }, 800);
                    return;
                }

                if (data.access === 'denied') {
                    this.render(
                        'Anfrage abgelehnt',
                        (data.message || 'Dieses Gerät wurde nicht freigegeben.') + ' Die Seite prüft weiter, ob später doch eine Freigabe erfolgt.',
                        'status-denied'
                    );
                    this.startPolling();
                    return;
                }

                this.render('Warte auf Freigabe', 'Das Gerät ist angemeldet. Bitte im Panel einem Bildschirm zuweisen.');
                this.startPolling();
            },

            startPolling() {
                if (this.pollId) {
                    return;
                }

                this.pollId = window.setInterval(() => this.refreshStatus(), 5000);
            },

            stopPolling() {
                if (!this.pollId) {
                    return;
                }

                window.clearInterval(this.pollId);
                this.pollId = null;
            },

            render(title, message, className = '') {
                this.statusEl.className = 'status' + (className ? ' ' + className : '');
                this.titleEl.textContent = title;
                this.messageEl.textContent = message;
            },
        };

        onboarding.init();
    </script>
</body>
</html>

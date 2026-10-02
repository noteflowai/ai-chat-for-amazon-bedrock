/**
 * Read aloud with Amazon Polly: the Listen to this post button, and the player the chat's
 * Listen buttons share. One thing plays at a time. Audio comes in parts, each fetched while
 * the one before it plays.
 */
(function () {
    'use strict';

    const settings = window.aicfabSpeech || {};
    const i18n = settings.i18n || {};
    let session = null;

    /**
     * POST to the speech endpoint. Rejects with the endpoint's message, status and code.
     */
    function request(body, nonce) {
        const headers = { 'Content-Type': 'application/json' };
        if (nonce) {
            headers['X-WP-Nonce'] = nonce;
        }
        return window.fetch(String(settings.url || ''), {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (data) {
                if (!response.ok) {
                    const error = new Error(data && data.message ? String(data.message) : String(i18n.error || ''));
                    error.status = response.status;
                    error.code = data && data.code ? String(data.code) : '';
                    throw error;
                }
                return data || {};
            });
        });
    }

    /**
     * Something the audio element can play: the saved file, or the audio sent inline.
     */
    function audioSource(data, urls) {
        if (data && 'string' === typeof data.url && /^https?:\/\//i.test(data.url)) {
            return data.url;
        }
        if (!data || 'string' !== typeof data.audio || !data.audio) {
            throw new Error(String(i18n.error || ''));
        }
        const binary = window.atob(data.audio);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        const url = window.URL.createObjectURL(new window.Blob([bytes], { type: String(data.type || 'audio/mpeg') }));
        urls.push(url);
        return url;
    }

    function say(status, text) {
        if (status) {
            status.textContent = text || '';
        }
    }

    function pressed(button, on) {
        if (!button.getAttribute('data-label')) {
            button.setAttribute('data-label', button.textContent);
        }
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.textContent = on ? String(i18n.stop || '') : button.getAttribute('data-label');
    }

    function stop() {
        const current = session;
        if (!current) {
            return;
        }
        session = null;
        current.stopped = true;
        current.audio.pause();
        current.audio.removeAttribute('src');
        current.urls.forEach(function (url) {
            window.URL.revokeObjectURL(url);
        });
        pressed(current.button, false);
        say(current.status, '');
    }

    function fail(current, error) {
        if (current !== session) {
            return;
        }
        stop();
        say(current.status, error && error.message ? error.message : String(i18n.error || ''));
    }

    function fetchSource(current, index) {
        return current.fetchPart(index).then(function (data) {
            current.parts = Math.max(1, Number(data && data.parts) || 1);
            return audioSource(data, current.urls);
        });
    }

    function playing(current) {
        current.blocked = false;
        say(current.status, String(i18n.playing || '') + (current.parts > 1 ? ' ' + (current.index + 1) + '/' + current.parts : ''));
        if (current.index + 1 < current.parts && !current.next) {
            current.next = fetchSource(current, current.index + 1);
            // A failure is reported when that part is due, not while this one plays.
            current.next.catch(function () {});
        }
    }

    function start(current, index) {
        const pending = current.next && index === current.index + 1 ? current.next : fetchSource(current, index);
        current.next = null;
        current.index = index;
        return pending.then(function (source) {
            if (current.stopped) {
                return;
            }
            current.audio.src = source;
            return current.audio.play().then(function () {
                if (!current.stopped) {
                    playing(current);
                }
            }, function (error) {
                // The browser wants a fresh click before it plays sound.
                if (error && 'NotAllowedError' === error.name && !current.stopped) {
                    current.blocked = true;
                    say(current.status, String(i18n.blocked || ''));
                    return;
                }
                throw error;
            });
        }).catch(function (error) {
            fail(current, error);
        });
    }

    /**
     * Start reading with this button, or stop when it is the one already reading.
     *
     * fetchPart( index ) resolves to the endpoint's answer for that part.
     */
    function play(button, fetchPart, status) {
        if (session && session.button === button) {
            if (session.blocked) {
                const current = session;
                current.audio.play().then(function () {
                    playing(current);
                }, function (error) {
                    fail(current, error);
                });
                return;
            }
            stop();
            return;
        }
        stop();
        const current = {
            button: button,
            fetchPart: fetchPart,
            status: status || null,
            audio: new window.Audio(),
            index: 0,
            parts: 1,
            next: null,
            urls: [],
            blocked: false,
            stopped: false
        };
        current.audio.addEventListener('ended', function () {
            if (current !== session) {
                return;
            }
            if (current.index + 1 < current.parts) {
                start(current, current.index + 1);
                return;
            }
            stop();
        });
        current.audio.addEventListener('error', function () {
            if (current === session && current.audio.getAttribute('src')) {
                fail(current, null);
            }
        });
        session = current;
        pressed(button, true);
        say(current.status, String(i18n.loading || ''));
        start(current, 0);
    }

    window.aicfabSpeak = { play: play, stop: stop, request: request, label: String(i18n.listen || '') };

    function setUp() {
        document.querySelectorAll('.aicfab-listen').forEach(function (player) {
            const button = player.querySelector('.aicfab-listen-button');
            const status = player.querySelector('.aicfab-listen-status');
            const post = parseInt(player.getAttribute('data-post'), 10) || 0;
            if (!button || post < 1) {
                return;
            }
            button.addEventListener('click', function () {
                play(button, function (part) {
                    return request({ post: post, part: part }, '');
                }, status);
            });
        });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', setUp);
    } else {
        setUp();
    }
})();

/**
 * Behavior checks for the read-aloud player.
 *
 * The script runs in a VM context with a fake audio element, fetch and document, so the
 * order of requests and playback can be followed.
 *
 * Run: node tests/js/speech-script.test.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '../../public/js/ai-chat-bedrock-speech.js'), 'utf8');
const failures = [];

function check(condition, message) {
    if (!condition) {
        failures.push(message);
    }
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function element(text) {
    const attributes = {};
    const listeners = {};
    return {
        textContent: text || '',
        setAttribute(name, value) { attributes[name] = String(value); },
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null; },
        addEventListener(name, callback) { (listeners[name] = listeners[name] || []).push(callback); },
        click() { (listeners.click || []).forEach((callback) => callback()); }
    };
}

const audios = [];
let refuse = 0;
class FakeAudio {
    constructor() {
        this.listeners = {};
        this.attributes = {};
        this.paused = true;
        this.plays = [];
        audios.push(this);
    }
    set src(value) { this.attributes.src = value; }
    get src() { return this.attributes.src; }
    getAttribute(name) { return this.attributes[name] || null; }
    removeAttribute(name) { delete this.attributes[name]; }
    addEventListener(name, callback) { (this.listeners[name] = this.listeners[name] || []).push(callback); }
    emit(name) { (this.listeners[name] || []).forEach((callback) => callback()); }
    play() {
        this.plays.push(this.attributes.src);
        if (refuse > 0) {
            refuse--;
            const error = new Error('blocked');
            error.name = 'NotAllowedError';
            return Promise.reject(error);
        }
        this.paused = false;
        return Promise.resolve();
    }
    pause() { this.paused = true; }
}

const requests = [];
let reply = () => ({ status: 200, body: { url: 'https://site.test/a.mp3', parts: 1 } });
const created = [];
const revoked = [];
const players = [];

const document = {
    readyState: 'complete',
    addEventListener() {},
    querySelectorAll() { return players; }
};
const button = element('Listen to this post');
const status = element('');
players.push({
    getAttribute: (name) => ('data-post' === name ? '42' : null),
    querySelector: (selector) => ('.aicfab-listen-button' === selector ? button : status)
});

const window = {
    aicfabSpeech: {
        url: 'https://site.test/wp-json/ai-chat-bedrock/v1/speech',
        i18n: { listen: 'Listen', stop: 'Stop', loading: 'Preparing audio…', playing: 'Playing', blocked: 'Press Listen again', error: 'The audio could not be played.' }
    },
    Audio: FakeAudio,
    Blob: class { constructor(parts, options) { this.parts = parts; this.type = options.type; } },
    URL: {
        createObjectURL(blob) { created.push(blob); return 'blob:' + created.length; },
        revokeObjectURL(url) { revoked.push(url); }
    },
    atob: (value) => Buffer.from(value, 'base64').toString('binary'),
    fetch(url, options) {
        const body = JSON.parse(options.body);
        requests.push({ url, body, headers: options.headers, credentials: options.credentials });
        const answer = reply(body);
        return Promise.resolve({
            ok: answer.status < 400,
            status: answer.status,
            json: () => Promise.resolve(answer.body)
        });
    }
};
vm.runInContext(source, vm.createContext({ window, document, Uint8Array, Buffer }));
const speak = window.aicfabSpeak;

check(speak && 'function' === typeof speak.play && 'function' === typeof speak.stop && 'Listen' === speak.label, 'The player is shared with the chat.');

// --- A post in two parts -------------------------------------------------------------------

reply = (body) => ({ status: 200, body: { url: 'https://site.test/uploads/42-' + body.part + '.mp3', parts: 2 } });
button.click();
check('true' === button.getAttribute('aria-pressed') && 'Stop' === button.textContent && 'Preparing audio…' === status.textContent, 'Pressing Listen turns the button into Stop and says the audio is coming.');
await tick(); await tick(); await tick();
let audio = audios[audios.length - 1];
check(1 <= requests.length && 42 === requests[0].body.post && 0 === requests[0].body.part && undefined === requests[0].headers['X-WP-Nonce'], 'A post part is asked for without a nonce, so a cached page works.');
check('same-origin' === requests[0].credentials, 'Requests stay on the site.');
check('https://site.test/uploads/42-0.mp3' === audio.plays[0] && 'Playing 1/2' === status.textContent, 'The first part plays and the progress is shown.');
check(2 === requests.length && 1 === requests[1].body.part, 'The next part is fetched while the first plays.');
audio.emit('ended');
await tick(); await tick(); await tick();
check('https://site.test/uploads/42-1.mp3' === audio.plays[1] && 2 === requests.length, 'The second part plays from the prefetch, without asking again.');
check('Playing 2/2' === status.textContent, 'Progress follows the parts.');
audio.emit('ended');
check('false' === button.getAttribute('aria-pressed') && 'Listen to this post' === button.textContent && '' === status.textContent, 'At the end the button is Listen again.');

// --- Stop, and one thing at a time ---------------------------------------------------------

requests.length = 0;
reply = () => ({ status: 200, body: { url: 'https://site.test/uploads/42-0.mp3', parts: 3 } });
button.click();
await tick(); await tick(); await tick();
audio = audios[audios.length - 1];
button.click();
check(audio.paused && 'false' === button.getAttribute('aria-pressed'), 'Pressing Stop stops the audio.');

const other = element('Listen');
speak.play(other, () => Promise.resolve({ url: 'https://site.test/b.mp3', parts: 1 }), null);
await tick(); await tick();
const otherAudio = audios[audios.length - 1];
button.click();
await tick(); await tick(); await tick();
check(otherAudio.paused && 'false' === other.getAttribute('aria-pressed') && 'true' === button.getAttribute('aria-pressed'), 'Starting one stops the other.');
speak.stop();

// --- Inline audio, refused autoplay, and errors -------------------------------------------

speak.play(other, () => Promise.resolve({ audio: Buffer.from('ID3 fake').toString('base64'), type: 'audio/mpeg', parts: 1 }), null);
await tick(); await tick();
check(1 === created.length && 'audio/mpeg' === created[0].type && 'blob:1' === audios[audios.length - 1].plays[0], 'Audio sent inline is played from a blob.');
speak.stop();
check(-1 !== revoked.indexOf('blob:1'), 'The blob is released when playback stops.');

refuse = 1;
reply = () => ({ status: 200, body: { url: 'https://site.test/uploads/42-0.mp3', parts: 1 } });
button.click();
await tick(); await tick(); await tick();
audio = audios[audios.length - 1];
check('Press Listen again' === status.textContent && 'true' === button.getAttribute('aria-pressed'), 'When the browser blocks the sound, the visitor is asked to press again.');
button.click();
await tick();
check(2 === audio.plays.length && !audio.paused && 'Playing' === status.textContent, 'Pressing again plays the same audio, without fetching it again.');
speak.stop();

reply = () => ({ status: 429, body: { code: 'aicfab_speech_limit', message: 'Reading aloud has reached today\'s limit.' } });
button.click();
await tick(); await tick(); await tick();
check('Reading aloud has reached today\'s limit.' === status.textContent && 'false' === button.getAttribute('aria-pressed'), 'An error from the site is shown and the button resets.');

let caught = null;
reply = () => ({ status: 403, body: { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' } });
await speak.request({ text: 'x', token: 't', part: 0 }, 'abc').catch((error) => { caught = error; });
check(caught && 403 === caught.status && 'rest_cookie_invalid_nonce' === caught.code && 'abc' === requests[requests.length - 1].headers['X-WP-Nonce'], 'A request carries the chat nonce and reports the status and code, so the chat can renew it.');

if (failures.length) {
    process.stderr.write('FAILED\n- ' + failures.join('\n- ') + '\n');
    process.exit(1);
}
process.stdout.write('OK: speech script checks passed\n');

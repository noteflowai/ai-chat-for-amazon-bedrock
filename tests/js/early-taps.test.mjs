/**
 * Behavior checks for taps made before the chat script runs.
 *
 * ai-chat-bedrock-early.js runs in a VM context with a small fake document, and the part of
 * ai-chat-bedrock-public.js that repeats the taps is taken from that file and run after it,
 * as a page with a script-delaying optimizer would run them.
 *
 * Run: node tests/js/early-taps.test.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const early = readFileSync(join(here, '../../public/js/ai-chat-bedrock-early.js'), 'utf8');
const chat = readFileSync(join(here, '../../public/js/ai-chat-bedrock-public.js'), 'utf8');
const failures = [];

function check(condition, message) {
    if (!condition) {
        failures.push(message);
    }
}

const start = chat.indexOf('const early = window.aiChatBedrockEarly;');
const end = chat.indexOf('\n    }\n', start);
check(start > 0 && end > start, 'The chat script repeats the early taps.');
// In a block of its own, as it is inside the chat script, so it can run more than once.
const repeat = '{' + chat.slice(start, end + 6) + '}';

let now = 1000;
const FakeDate = { now: () => now };

function page() {
    const captures = [];
    const attached = [];
    const document = {
        documentElement: { contains: (element) => attached.includes(element) },
        addEventListener(name, callback, capture) {
            if ('click' === name && true === capture) {
                captures.push(callback);
            }
        }
    };

    function dispatch(target, trusted) {
        let stopped = false;
        let prevented = false;
        const event = {
            target,
            isTrusted: trusted,
            preventDefault() { prevented = true; },
            stopImmediatePropagation() { stopped = true; }
        };
        captures.forEach((callback) => { if (!stopped) { callback(event); } });
        // Capture runs from the document down, so a stopped event never reaches the control.
        for (let node = target; node && !stopped; node = node.parent) {
            node.handlers.forEach((handler) => handler(event));
        }
        return { stopped, prevented };
    }

    function element(className, parent) {
        const attributes = {};
        const node = {
            className,
            parent: parent || null,
            handlers: [],
            getAttribute(name) { return Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null; },
            setAttribute(name, value) { attributes[name] = String(value); },
            closest(selector) {
                const wanted = selector.split(',').map((part) => part.trim().replace(/^\./, ''));
                for (let current = node; current; current = current.parent) {
                    if (wanted.includes(current.className)) {
                        return current;
                    }
                }
                return null;
            },
            click() { dispatch(node, false); }
        };
        attached.push(node);
        return node;
    }

    const window = {};
    const context = vm.createContext({ window, document, Date: FakeDate });
    return {
        window,
        element,
        dispatch,
        detach: (node) => attached.splice(attached.indexOf(node), 1),
        run: (code) => vm.runInContext(code, context),
        tap: (node) => dispatch(node, true)
    };
}

// --- A tap before the chat script is repeated once it runs --------------------------

let site = page();
site.run(early);
const launcher = site.element('ai-chat-bedrock-launcher');
launcher.setAttribute('aria-expanded', 'false');
const icon = site.element('ai-chat-bedrock-launcher-icon', launcher);
let opened = 0;
let result = site.tap(icon);
check(1 === site.window.aiChatBedrockEarly.taps.length && launcher === site.window.aiChatBedrockEarly.taps[0], 'A tap on the chat button, even on its icon, is noted before the chat script runs.');
check(!result.prevented && !result.stopped, 'A noted tap is left alone, for anything else on the page that handles it.');
site.tap(launcher);
check(1 === site.window.aiChatBedrockEarly.taps.length, 'A control tapped twice is repeated once.');

const other = site.element('site-menu');
site.tap(other);
check(1 === site.window.aiChatBedrockEarly.taps.length, 'Taps elsewhere on the page are not noted.');

// The chat script binds its handlers and then repeats the taps.
launcher.handlers.push(() => {
    opened++;
    launcher.setAttribute('aria-expanded', 'true');
});
site.run(repeat);
check(1 === opened, 'The noted tap opens the chat once the chat script runs.');
check(true === site.window.aiChatBedrockEarly.ready && 0 === site.window.aiChatBedrockEarly.taps.length, 'Once the chat is ready, nothing more is noted.');

// --- An optimizer that repeats the tap too ------------------------------------------

now += 200;
result = site.dispatch(icon, false);
check(1 === opened && result.stopped && result.prevented, 'The optimizer\'s own copy of the tap is ignored, so the chat does not close again.');
result = site.tap(launcher);
check(2 === opened && !result.stopped, 'A visitor\'s tap straight after is handled, not taken for a copy.');
now += 6000;
site.dispatch(launcher, false);
check(3 === opened, 'A scripted tap long after is handled as usual.');

site.run(repeat);
check(3 === opened, 'Running the repeat a second time repeats nothing.');

// --- Taps that should not be repeated ------------------------------------------------

site = page();
site.run(early);
const open = site.element('ai-chat-bedrock-launcher');
open.setAttribute('aria-expanded', 'true');
const gone = site.element('ai-chat-bedrock-suggestion');
const send = site.element('ai-chat-bedrock-submit');
let sent = 0;
let closed = 0;
let suggested = 0;
site.tap(open);
site.tap(gone);
site.tap(send);
site.detach(gone);
open.handlers.push(() => { closed++; });
gone.handlers.push(() => { suggested++; });
send.handlers.push(() => { sent++; });
site.run(repeat);
check(0 === closed, 'A tap meant to open a chat that is already open is not repeated, which would close it.');
check(0 === suggested, 'A control no longer on the page is not tapped.');
check(1 === sent, 'A tap on Send is repeated, so the typed question is sent.');

// --- Loaded twice -------------------------------------------------------------------

site = page();
site.run(early);
const first = site.window.aiChatBedrockEarly;
site.run(early);
const button = site.element('ai-chat-bedrock-contact-open');
site.tap(button);
check(first === site.window.aiChatBedrockEarly && 1 === first.taps.length, 'Printed twice, the script still notes each tap once.');

// --- Without the early script -------------------------------------------------------

site = page();
let threw = false;
try {
    site.run(repeat);
} catch (error) {
    threw = true;
}
check(!threw, 'The chat script runs as before where the early script is missing.');

if (failures.length) {
    process.stderr.write('FAILED\n- ' + failures.join('\n- ') + '\n');
    process.exit(1);
}
process.stdout.write('OK: early tap checks passed\n');

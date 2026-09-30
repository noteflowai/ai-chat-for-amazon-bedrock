/**
 * Behavior checks for the pure parts of the public chat script.
 *
 * The script is one jQuery closure, so the functions under test are cut out of it by name
 * and run in a VM context with the few globals they use.
 *
 * Run: node tests/js/public-script.test.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '../../public/js/ai-chat-bedrock-public.js'), 'utf8');
const failures = [];

function check(condition, message) {
    if (!condition) {
        failures.push(message);
    }
}

/**
 * The text of a named function declaration, found by matching braces.
 */
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    if (-1 === start) {
        throw new Error('Function not found: ' + name);
    }
    let depth = 0;
    for (let i = source.indexOf('{', start); i < source.length; i++) {
        if ('{' === source[i]) {
            depth++;
        } else if ('}' === source[i] && 0 === --depth) {
            return source.slice(start, i + 1);
        }
    }
    throw new Error('Unbalanced function: ' + name);
}

const events = [];
const context = vm.createContext({
    URL,
    window: { location: { href: 'https://site.test/page/', host: 'site.test' } },
    handleEvent: function (event) {
        events.push({ name: event.name, data: event.data });
    }
});
vm.runInContext([extract('parseChunk'), extract('isNonceError'), extract('safeUrl')].join('\n'), context);

// --- Event stream parsing --------------------------------------------------------------

let rest = context.parseChunk('event: delta\ndata: {"text":"Hi"}\n\nevent: done\ndata: {"mes', {});
check(1 === events.length && 'delta' === events[0].name && '{"text":"Hi"}' === events[0].data, 'A complete event is dispatched.');
check('event: done\ndata: {"mes' === rest, 'An incomplete event is kept for the next chunk.');
rest = context.parseChunk(rest + 'sage":"ok"}\n\n', {});
check(2 === events.length && 'done' === events[1].name && '{"message":"ok"}' === events[1].data, 'An event split across chunks is joined.');
check('' === rest, 'Nothing is left once every event is complete.');

events.length = 0;
context.parseChunk('event: delta\r\ndata: {"text":"a"}\r\n\r\nevent:delta\rdata:{"text":"b"}\r\r', {});
check(2 === events.length && '{"text":"a"}' === events[0].data && '{"text":"b"}' === events[1].data, 'CRLF and CR line endings end events too.');

events.length = 0;
context.parseChunk(': keep-alive\n\nevent: delta\ndata: {"text":\ndata: "x"}\n\nevent: empty\n\n', {});
check(1 === events.length, 'Comments and events without data are not dispatched.');
check('{"text":\n"x"}' === events[0].data && 'x' === JSON.parse(events[0].data).text, 'Several data lines are joined with a line break.');

events.length = 0;
context.parseChunk('data:  two spaces\n\n', {});
check(' two spaces' === events[0].data, 'Only one space after the colon is dropped.');
check('message' === events[0].name, 'An event without a name is a message.');

// --- Stale nonces ------------------------------------------------------------------------

check(true === context.isNonceError(403, { code: 'aicfab_bad_nonce' }), 'The chat nonce error is recognised.');
check(true === context.isNonceError(403, { code: 'rest_cookie_invalid_nonce' }), 'The REST nonce error is recognised.');
check(false === context.isNonceError(401, { code: 'aicfab_bad_nonce' }), 'Only a 403 is a nonce error.');
check(false === context.isNonceError(403, { code: 'aicfab_forbidden' }), 'Other refusals are not retried.');
check(false === context.isNonceError(403, null), 'A refusal without a body is not retried.');

// --- Source links ------------------------------------------------------------------------

check('https://site.test/refunds/' === context.safeUrl('https://site.test/refunds/'), 'A web link is kept.');
check('https://site.test/about/' === context.safeUrl('/about/'), 'A relative link resolves against the page.');
check('' === context.safeUrl('javascript:alert(1)'), 'A script link is dropped.');
check('' === context.safeUrl('data:text/html,<b>x</b>'), 'A data link is dropped.');
check('' === context.safeUrl('http://['), 'A malformed link is dropped.');

// --- Wiring ------------------------------------------------------------------------------

check(-1 === extract('stopAnswering').indexOf('$stop.on('), 'The Stop button is not bound from inside its own handler.');
check(/\n\s*\$stop\.on\('click', stopAnswering\);/.test(source), 'The Stop button is bound when the chat starts.');
check(-1 === source.indexOf("isUser ? 'You' : 'AI'"), 'Avatar labels come from the translations.');
check(/sources\.slice\(0, 5\)/.test(extract('attachSources')) && -1 !== extract('attachSources').indexOf('.text(title)'), 'Source titles are inserted as text.');

if (failures.length) {
    process.stderr.write('FAILED\n- ' + failures.join('\n- ') + '\n');
    process.exit(1);
}
process.stdout.write('OK: public script checks passed\n');

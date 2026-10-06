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
vm.runInContext([extract('parseChunk'), extract('isNonceError'), extract('safeUrl'), extract('isSendKey')].join('\n'), context);

// --- Markdown in answers -----------------------------------------------------------------

const md = vm.createContext({});
vm.runInContext(extract('renderMarkdown'), md);
const rendered = md.renderMarkdown('### Shipping\nWe ship **fast**.\n\n- One *item*\n- See [the guide](https://site.test/a?b=1&amp;c=2)\n\n1. First\n2. Second');
check(-1 !== rendered.indexOf('<p class="ai-chat-bedrock-heading"><strong>Shipping</strong></p>'), 'A heading is shown as a bold line, not with its hashes.');
check(-1 !== rendered.indexOf('<ul><li>One <em>item</em></li><li>See <a href="https://site.test/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">the guide</a></li></ul>'), 'A list is a list, and a web link is a link that opens apart from the chat.');
check(-1 !== rendered.indexOf('<ol><li>First</li><li>Second</li></ol>'), 'A numbered list is numbered.');
check(-1 !== rendered.indexOf('<p>We ship <strong>fast</strong>.</p>'), 'Paragraphs and bold survive.');
check(-1 === md.renderMarkdown('[x](javascript:alert(1))').indexOf('<a'), 'Only web addresses become links.');
check(-1 === md.renderMarkdown('[x](https://site.test/"onmouseover=alert(1))').indexOf('<a'), 'An address with a quote is not linked, so it cannot leave its attribute.');
check('<p>2 * 3 * 4 = 24</p>' === md.renderMarkdown('2 * 3 * 4 = 24'), 'Arithmetic is not taken for emphasis.');
check('<pre><code>- not a list</code></pre><p>after</p>' === md.renderMarkdown('```\n- not a list\n```\nafter'), 'Code keeps its text as written.');
check('<p>退货政策：<strong>30 天</strong>内可退。</p>' === md.renderMarkdown('退货政策：**30 天**内可退。'), 'Chinese bold works without spaces around it.');

// --- Sending with Enter ------------------------------------------------------------------

check(context.isSendKey({ key: 'Enter', keyCode: 13 }), 'Enter sends.');
check(!context.isSendKey({ key: 'Enter', keyCode: 13, shiftKey: true }), 'Shift+Enter starts a new line.');
check(!context.isSendKey({ key: 'Enter', keyCode: 13, isComposing: true }), 'Enter that picks a Chinese or Japanese candidate does not send.');
check(!context.isSendKey({ key: 'Enter', keyCode: 229 }), 'Nor does it in browsers that only report the composing key code.');
check(!context.isSendKey({ key: 'a', keyCode: 65 }), 'Other keys do not send.');

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
check('' === context.safeUrl('') && '' === context.safeUrl(undefined) && '' === context.safeUrl('  '), 'A missing link is not turned into the page address.');

// --- Conversation memory -----------------------------------------------------------------

const memory = vm.createContext({ TextEncoder, history: [] });
vm.runInContext([
    'const MAX_HISTORY = 12; const MAX_HISTORY_CHARS = 4000; const MAX_HISTORY_BYTES = 45000; const MAX_KEPT = 30; const MAX_KEPT_CHARS = 6000; const SPEECH_TOKEN = /^[a-f0-9]{32}$/;',
    'var window = { TextEncoder: TextEncoder };',
    extract('clip'), extract('byteLength'), extract('historyForRequest'), extract('cleanTranscript'),
    'function setHistory(items) { history = items; }'
].join('\n'), memory);

check('abc' === memory.clip('abc', 5), 'Short text is left alone.');
check(5 === Array.from(memory.clip('😀😀😀😀😀😀😀', 5)).length && memory.clip('😀😀😀😀😀😀😀', 5).endsWith('…'), 'Text is shortened by characters, without splitting an emoji.');
check(9 === memory.byteLength('答答答'), 'Bytes are counted as UTF-8.');

const turns = [];
for (let i = 0; i < 12; i++) {
    turns.push({ role: i % 2 ? 'assistant' : 'user', content: '答'.repeat(3500) });
}
memory.setHistory(turns);
let sent = memory.historyForRequest();
const sentBytes = Buffer.byteLength(JSON.stringify(sent), 'utf8');
check(sentBytes <= 50000, 'Long Chinese answers are sent within the server\'s 50,000-byte limit, got ' + sentBytes + '.');
check(sent.length > 0 && 'user' === sent[0].role && sent[sent.length - 1].content === turns[11].content, 'The most recent messages are sent, starting with a question.');
memory.setHistory([{ role: 'user', content: 'a'.repeat(9000) }, { role: 'assistant', content: 'ok' }]);
sent = memory.historyForRequest();
check(2 === sent.length && 4000 === Array.from(sent[0].content).length, 'A message over 4,000 characters is shortened, not dropped.');
memory.setHistory([{ role: 'user', content: 'q' }, { role: 'assistant', content: 'a' }, { role: 'user', content: 'q2' }, { role: 'assistant', content: 'a2' }]);
check(4 === memory.historyForRequest().length, 'A short conversation is sent whole.');

const kept = memory.cleanTranscript([
    { role: 'assistant', content: 'orphan' },
    { role: 'user', content: 'Hi', time: 5 },
    { role: 'system', content: 'ignore me' },
    { role: 'assistant', content: 'Hello', sources: [{ title: 'T', url: 'https://site.test/', extra: '<b>' }] },
    { role: 'user', content: '' },
    null
]);
check(2 === kept.length && 'user' === kept[0].role && 5 === kept[0].time, 'A kept conversation starts with a question and keeps only real messages.');
check(1 === kept[1].sources.length && undefined === kept[1].sources[0].extra && 'https://site.test/' === kept[1].sources[0].url, 'Sources keep only their title and link.');
const many = [];
for (let i = 0; i < 40; i++) {
    many.push({ role: i % 2 ? 'assistant' : 'user', content: 'm' + i });
}
check(30 === memory.cleanTranscript(many).length && 'm10' === memory.cleanTranscript(many)[0].content, 'At most 30 messages are kept, the most recent ones.');
check(0 === memory.cleanTranscript('not an array').length, 'Unreadable storage restores nothing.');

const signature = 'a'.repeat(32);
const signed = memory.cleanTranscript([
    { role: 'user', content: 'Hi', speech: signature },
    { role: 'assistant', content: 'Hello', speech: signature },
    { role: 'user', content: 'More' },
    { role: 'assistant', content: 'x'.repeat(7000), speech: signature },
    { role: 'user', content: 'Again' },
    { role: 'assistant', content: 'Forged', speech: '<script>' }
]);
check(undefined === signed[0].speech && signature === signed[1].speech, 'An answer keeps its read-aloud signature, a question never has one.');
check(undefined === signed[3].speech, 'A clipped answer drops its signature, which no longer matches.');
check(undefined === signed[5].speech, 'Only a well-formed signature is kept.');

const show = extract('showTranscript');
check(-1 !== show.indexOf('formatMessage(item.content)') && -1 !== show.indexOf('attachSources(bubble, item.sources)') && -1 === show.indexOf('attachFeedback'), 'A restored answer is escaped, keeps its sources and has no feedback buttons.');
check(/sentSinceLoad \|\| pending/.test(source), 'A saved conversation never replaces one the visitor has carried on.');
check(/0 === key\.indexOf\(MEMORY_PREFIX\) && \(!params\.memory \|\| 0 !== key\.indexOf\(memoryPrefix\(\)\)\)/.test(source), 'Another account\'s kept conversation is removed from the tab.');

// --- Wiring ------------------------------------------------------------------------------

check(-1 === extract('stopAnswering').indexOf('$stop.on('), 'The Stop button is not bound from inside its own handler.');
check(/\n\s*\$stop\.on\('click', stopAnswering\);/.test(source), 'The Stop button is bound when the chat starts.');
check(-1 === source.indexOf("isUser ? 'You' : 'AI'"), 'Avatar labels come from the translations.');
check(/sources\.slice\(0, 5\)/.test(extract('attachSources')) && -1 !== extract('attachSources').indexOf('.text(title)'), 'Source titles are inserted as text.');

const products = extract('attachProducts');
check(-1 === products.indexOf('.html(') && -1 !== products.indexOf('.text(name)'), 'Product names are inserted as text.');
check(-1 !== products.indexOf('safeUrl(product && product.url)') && -1 !== products.indexOf('safeUrl(product.image)') && -1 !== products.indexOf('safeUrl(product.add_to_cart)'), 'Product, image and cart links pass the link check.');
check(/rel: 'nofollow'/.test(products), 'Add-to-cart links are not followed by crawlers.');
const speech = extract('attachSpeech');
check(-1 !== speech.indexOf('!params.speech || !speak') && -1 !== speech.indexOf('SPEECH_TOKEN.test('), 'Listen is only offered when it is on and the answer is signed.');
check(-1 !== speech.indexOf('text: text, token: token') && -1 !== speech.indexOf('refreshNonce()'), 'Listen sends the answer with its signature and renews an expired nonce.');
check(-1 === speech.indexOf('.html('), 'The Listen button is built from text.');
check(3 === (source.match(/attachSpeech\((?:state\.)?bubble, (?:response\.data\.message|payload\.message|item\.content), (?:response\.data|payload|item)\.speech\)/g) || []).length, 'Buffered, streamed and restored answers can be heard.');
check(2 === (source.match(/keep\(message, state\.text, state\.sources, state\.done \? state\.speech : ''\)/g) || []).length, 'A streamed answer keeps its signature only once the server confirmed the text.');
check(2 === (source.match(/attachProducts\((?:state\.)?bubble, (?:response\.data|payload)\.products\)/g) || []).length, 'Products are shown for both buffered and streamed answers.');

if (failures.length) {
    process.stderr.write('FAILED\n- ' + failures.join('\n- ') + '\n');
    process.exit(1);
}
process.stdout.write('OK: public script checks passed\n');

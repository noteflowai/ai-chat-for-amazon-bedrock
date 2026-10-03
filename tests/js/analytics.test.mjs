/**
 * Behavior checks for the chat events sent to a site's analytics.
 *
 * track() is taken from ai-chat-bedrock-public.js and run in a VM context with the globals
 * the analytics plugins define: gtag from Site Kit, __gtagTracker from MonsterInsights, a
 * Google Tag Manager container and data layer, GTM4WP's layer name, Matomo's _paq and
 * Plausible's queue, and the WP Consent API's wp_has_consent.
 *
 * Run: node tests/js/analytics.test.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const chat = readFileSync(join(here, '../../public/js/ai-chat-bedrock-public.js'), 'utf8');
const failures = [];

function check(condition, message) {
    if (!condition) {
        failures.push(message);
    }
}

const start = chat.indexOf('    function track(name, details) {');
const end = chat.indexOf('\n    }\n', start);
check(start > 0 && end > start, 'The chat script has a track function.');
const source = chat.slice(start, end + 6);

function page(globals, analytics) {
    const dispatched = [];
    class CustomEvent {
        constructor(type, init) {
            this.type = type;
            this.detail = init && init.detail;
        }
    }
    const window = Object.assign({}, globals);
    const context = vm.createContext({
        window,
        CustomEvent,
        params: { analytics: false !== analytics },
        document: { dispatchEvent: (event) => dispatched.push(event) }
    });
    vm.runInContext(source + '\nwindow.track = track;', context);
    return { window, dispatched, track: window.track };
}

const data = { chat_profile: 'support', link_url: '', rating: null, sources: 0 };

// --- Always a DOM event, and nothing else while the setting is off ----------------

let calls = [];
let site = page({ gtag: (...args) => calls.push(args), dataLayer: [] }, false);
site.track('ai_chat_answer', data);
check(1 === site.dispatched.length && 'ai-chat-bedrock:event' === site.dispatched[0].type, 'The DOM event is dispatched whatever the setting.');
check('ai_chat_answer' === site.dispatched[0].detail.name && 'support' === site.dispatched[0].detail.data.chat_profile, 'It carries the event name and its data.');
check(!('link_url' in site.dispatched[0].detail.data) && !('rating' in site.dispatched[0].detail.data) && 0 === site.dispatched[0].detail.data.sources, 'Empty values are left out, a count of zero is kept.');
check(0 === calls.length && 0 === site.window.dataLayer.length, 'With the setting off nothing reaches the analytics tag.');

// --- Google Analytics through gtag ------------------------------------------------

calls = [];
site = page({ gtag: (...args) => calls.push(args), dataLayer: [] });
site.track('ai_chat_open', { chat_profile: '' });
check(1 === calls.length && 'event' === calls[0][0] && 'ai_chat_open' === calls[0][1], 'Site Kit\'s gtag receives the event.');
check(0 === Object.keys(calls[0][2]).length, 'An event without data sends an empty parameter list.');
check(0 === site.window.dataLayer.length, 'The event is not pushed to the data layer as well, so it is not counted twice.');

calls = [];
site = page({ __gtagTracker: (...args) => calls.push(args) });
site.track('ai_chat_feedback', { rating: 'down' });
check(1 === calls.length && 'down' === calls[0][2].rating, 'MonsterInsights\' tracker is used where it does not define gtag.');

// --- Google Tag Manager --------------------------------------------------------------

calls = [];
site = page({ gtag: (...args) => calls.push(args), dataLayer: [], google_tag_manager: { 'GTM-ABC123': {}, 'G-XYZ': {} } });
site.track('ai_chat_contact', { chat_profile: 'sales' });
check(1 === site.window.dataLayer.length && 'ai_chat_contact' === site.window.dataLayer[0].event && 'sales' === site.window.dataLayer[0].chat_profile, 'A Tag Manager container gets the event in its data layer.');
check(0 === calls.length, 'gtag is then left alone, so a site with both counts it once.');

calls = [];
site = page({ gtag: (...args) => calls.push(args), dataLayer: [], google_tag_manager: { 'G-XYZ': {} } });
site.track('ai_chat_open', {});
check(1 === calls.length && 0 === site.window.dataLayer.length, 'gtag.js alone, which also defines google_tag_manager, still gets the event through gtag.');

site = page({ gtm4wp_datalayer_name: 'siteLayer', siteLayer: [], dataLayer: [], google_tag_manager: { 'GTM-ABC123': {} } });
site.track('ai_chat_question', { question_source: 'typed' });
check(1 === site.window.siteLayer.length && 0 === site.window.dataLayer.length, 'GTM4WP\'s own data layer name is respected.');

site = page({ dataLayer: [] });
site.track('ai_chat_open', {});
check(1 === site.window.dataLayer.length && 'ai_chat_open' === site.window.dataLayer[0].event, 'A data layer whose container is still loading gets the event too.');

// --- Matomo and Plausible ------------------------------------------------------------

const paq = [];
const plausible = [];
site = page({ _paq: paq, plausible: (...args) => plausible.push(args) });
site.track('ai_chat_product_click', { link_url: 'https://shop.test/p/1', product_action: 'add_to_cart' });
check(1 === paq.length && 'trackEvent' === paq[0][0] && 'AI chat' === paq[0][1] && 'ai_chat_product_click' === paq[0][2] && 'add_to_cart' === paq[0][3], 'Matomo records it as an event in an AI chat category, labelled by what was done.');
check(1 === plausible.length && 'ai_chat_product_click' === plausible[0][0] && 'add_to_cart' === plausible[0][1].props.product_action, 'Plausible records it as a custom event with its properties.');

// --- Consent ---------------------------------------------------------------------------

calls = [];
let asked = [];
site = page({ gtag: (...args) => calls.push(args), wp_has_consent: (category) => { asked.push(category); return false; } });
site.track('ai_chat_answer', { sources: 2 });
check(0 === calls.length && 'statistics' === asked[0], 'Without consent to statistics, nothing reaches the analytics tag.');
check(1 === site.dispatched.length, 'The DOM event is still dispatched, since it leaves nothing on the page.');
site = page({ gtag: (...args) => calls.push(args), wp_has_consent: () => true });
site.track('ai_chat_answer', { sources: 2 });
check(1 === calls.length && 2 === calls[0][2].sources, 'Once statistics are allowed, the event is sent.');

// --- A failing tag ----------------------------------------------------------------------

site = page({ gtag: () => { throw new Error('blocked'); }, _paq: { push: () => { throw new Error('blocked'); } } });
let threw = false;
try {
    site.track('ai_chat_open', {});
} catch (error) {
    threw = true;
}
check(!threw, 'An analytics tag that throws does not break the chat.');

if (failures.length) {
    process.stderr.write('FAILED\n- ' + failures.join('\n- ') + '\n');
    process.exit(1);
}
process.stdout.write('OK: analytics event checks passed\n');

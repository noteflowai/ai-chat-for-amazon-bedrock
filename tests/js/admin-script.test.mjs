/**
 * Behavior checks for the pure parts of the admin script.
 *
 * Run: node tests/js/admin-script.test.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '../../admin/js/ai-chat-bedrock-admin.js'), 'utf8');
const failures = [];

function check(condition, message) {
    if (!condition) {
        failures.push(message);
    }
}

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

const context = vm.createContext({});
vm.runInContext(extract('dependencyMet'), context);

// Stand-ins for a jQuery collection holding one control.
function finder(controls) {
    return function (name) {
        return controls[name] || { length: 0 };
    };
}

const unticked = { length: 1, is: function (selector) { return ':checkbox' === selector; }, val: function () { return '1'; } };
const ticked = { length: 1, is: function () { return true; }, val: function () { return '1'; } };
const select = function (value) { return { length: 1, is: function () { return false; }, val: function () { return value; } }; };

check(!context.dependencyMet(['a'], '', finder({ a: unticked })), 'Fields stay hidden while their feature is off.');
check(context.dependencyMet(['a'], '', finder({ a: ticked })), 'They show once it is on.');
check(context.dependencyMet(['a', 'b'], '', finder({ a: unticked, b: ticked })), 'With two switches, either one shows them.');
check(!context.dependencyMet(['a'], 's3_vectors', finder({ a: select('post_meta') })), 'A select must have the chosen value.');
check(context.dependencyMet(['a'], 's3_vectors', finder({ a: select('s3_vectors') })), 'And they show when it does.');
check(!context.dependencyMet(['missing'], '', finder({})), 'A control that is not on the page shows nothing.');

if (failures.length) {
    console.error('FAILED\n- ' + failures.join('\n- '));
    process.exit(1);
}
console.log('OK: admin script checks passed');

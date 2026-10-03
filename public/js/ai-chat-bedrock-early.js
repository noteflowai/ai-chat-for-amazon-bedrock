/*
 * Keep taps on the chat that arrive before its script runs.
 *
 * Optimizers that delay scripts until the visitor interacts, such as LiteSpeed Cache, WP Rocket
 * and Perfmatters, hold the chat script back until the first tap. LiteSpeed does not repeat
 * that tap once the scripts have loaded, so on a phone the first press of the chat button did
 * nothing. This small script is printed before the chat script and marked so those optimizers
 * leave it alone. It notes such taps, and the chat script repeats them when it is ready. An
 * optimizer that repeats the tap as well is then ignored, so nothing happens twice.
 */
(function (window, document) {
    'use strict';

    if (window.aiChatBedrockEarly) {
        return;
    }

    var CONTROLS = '.ai-chat-bedrock-launcher, .ai-chat-bedrock-suggestion, .ai-chat-bedrock-submit, .ai-chat-bedrock-contact-open';
    // How long after the chat repeats a tap an optimizer's own copy of it is still expected.
    var REPEAT_WINDOW = 5000;
    var early = window.aiChatBedrockEarly = { ready: false, taps: [], repeated: [] };

    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest ? event.target.closest(CONTROLS) : null;
        if (!target) {
            return;
        }
        if (!early.ready) {
            if (early.taps.indexOf(target) < 0) {
                early.taps.push(target);
            }
            return;
        }
        // A visitor's own tap is always handled; only a scripted copy can be a repeat.
        if (event.isTrusted) {
            return;
        }
        for (var i = 0; i < early.repeated.length; i++) {
            if (early.repeated[i].element === target && Date.now() - early.repeated[i].at < REPEAT_WINDOW) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }
        }
    }, true);
})(window, document);

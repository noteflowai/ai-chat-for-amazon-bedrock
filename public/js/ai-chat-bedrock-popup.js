/*
 * The floating chat's button and panel, and the events the chat reports.
 *
 * Kept apart from the chat itself and free of jQuery, so a page where no chat can be used,
 * such as one that only asks a signed-out visitor to sign in, loads this and not the chat.
 * Where a chat can be used, ai-chat-bedrock-public.js loads after it and uses its track().
 */
(function (window, document) {
    'use strict';

    const params = window.ai_chat_bedrock_popup || {};

    /**
     * Report what the chat did, never what was written in it.
     *
     * A DOM event on document always carries it, for a site's own code. When the site has
     * turned analytics events on, it also goes to the analytics tag on the page: a Google Tag
     * Manager container gets it in its data layer, Google Analytics otherwise through gtag
     * (MonsterInsights names its copy __gtagTracker), and Matomo and Plausible through their
     * queues. With a consent plugin on the WP Consent API, it waits for consent to statistics.
     */
    function track(name, details) {
        const data = {};
        Object.keys(details || {}).forEach(function (key) {
            if (null != details[key] && '' !== details[key]) {
                data[key] = details[key];
            }
        });
        try {
            document.dispatchEvent(new CustomEvent('ai-chat-bedrock:event', { detail: { name: name, data: Object.assign({}, data) } }));
        } catch (error) {
            // A browser without CustomEvent still gets the analytics below.
        }
        if (!params.analytics || ('function' === typeof window.wp_has_consent && !window.wp_has_consent('statistics'))) {
            return;
        }
        try {
            const layer = window[window.gtm4wp_datalayer_name || 'dataLayer'];
            const gtm = window.google_tag_manager && Object.keys(window.google_tag_manager).some(function (key) {
                return 0 === key.indexOf('GTM-');
            });
            const gtag = 'function' === typeof window.gtag ? window.gtag : ('function' === typeof window.__gtagTracker ? window.__gtagTracker : null);
            if (gtm && Array.isArray(layer)) {
                layer.push(Object.assign({ event: name }, data));
            } else if (gtag) {
                gtag('event', name, Object.assign({}, data));
            } else if (Array.isArray(layer)) {
                // A tag manager that has not finished loading reads the layer when it does.
                layer.push(Object.assign({ event: name }, data));
            }
            if (window._paq && 'function' === typeof window._paq.push) {
                window._paq.push(['trackEvent', 'AI chat', name, String(data.rating || data.product_action || data.question_source || data.link_url || '')]);
            }
            if ('function' === typeof window.plausible) {
                window.plausible(name, { props: Object.assign({}, data) });
            }
        } catch (error) {
            // An analytics tag that fails must not break the chat.
        }
    }

    /*
     * Taps on the chat made while an optimizer held the scripts back, as noted by
     * ai-chat-bedrock-early.js, repeated once the chat can answer them. A tap on the chat
     * button is skipped when the chat is already open, since remembering an open chat may
     * have opened it, and repeating the tap would close it again.
     */
    function replayEarlyTaps() {
        const early = window.aiChatBedrockEarly;
        if (early) {
            early.ready = true;
            early.taps.splice(0).forEach(function (element) {
                if (!document.documentElement.contains(element) || 'true' === element.getAttribute('aria-expanded')) {
                    return;
                }
                element.click();
                early.repeated.push({ element: element, at: Date.now() });
            });
        }
    }

    function visible(element) {
        return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    }

    function bind(popup) {
        const launcher = popup.querySelector('.ai-chat-bedrock-launcher');
        const panel = popup.querySelector('.ai-chat-bedrock-popup-panel');
        if (!launcher || !panel) {
            return;
        }
        const stateKey = 'aicfabPopupOpen';

        function remember(open) {
            try {
                window.sessionStorage.setItem(stateKey, open ? '1' : '0');
            } catch (error) {
                // Session storage is unavailable; the state simply is not remembered.
            }
        }

        // On a phone the open chat covers the page, so it is modal there: the page behind is
        // held still and Tab stays in the chat. On a wider screen it sits beside the page.
        const phone = window.matchMedia ? window.matchMedia('(max-width: 600px)') : null;

        function modal() {
            return !!(phone && phone.matches) && 'open' === popup.getAttribute('data-state') && !popup.classList.contains('is-signed-out');
        }

        function applyModal() {
            if (modal()) {
                panel.setAttribute('aria-modal', 'true');
            } else {
                panel.removeAttribute('aria-modal');
            }
            document.documentElement.classList.toggle('aicfab-chat-open', modal());
        }

        function setOpen(open, focus) {
            popup.setAttribute('data-state', open ? 'open' : 'closed');
            launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
            applyModal();
            if (open && false !== focus) {
                /*
                 * Focus goes into the panel either way. On a touch screen it goes to the panel
                 * itself rather than the message box, whose keyboard would cover the welcome
                 * and the suggested questions the moment the chat opened.
                 */
                const touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
                const target = panel.querySelector(touch ? '.ai-chat-bedrock-sign-in-link' : '.ai-chat-bedrock-textarea, .ai-chat-bedrock-sign-in-link');
                (target || panel).focus();
            }
        }

        function close() {
            setOpen(false);
            remember(false);
            launcher.focus();
        }

        launcher.addEventListener('click', function () {
            const open = 'open' !== popup.getAttribute('data-state');
            setOpen(open);
            remember(open);
            if (open) {
                const container = panel.querySelector('.ai-chat-bedrock-container');
                track('ai_chat_open', { chat_profile: String((container && container.getAttribute('data-profile')) || '') });
            }
        });

        const closeButton = popup.querySelector('.ai-chat-bedrock-close');
        if (closeButton) {
            closeButton.addEventListener('click', close);
        }

        // Keep the panel open while the visitor browses other pages in this tab, beside the
        // page. On a phone it would cover each new page whole, so there it waits to be opened.
        try {
            if ('1' === window.sessionStorage.getItem(stateKey) && !(phone && phone.matches && !popup.classList.contains('is-signed-out'))) {
                setOpen(true, false);
            }
        } catch (error) {
            // Ignore storage failures.
        }

        // Only while focus is in the chat, so Escape still closes a theme's menu or dialog
        // without closing the chat behind it.
        document.addEventListener('keydown', function (event) {
            if ('Escape' === event.key && 'open' === popup.getAttribute('data-state') && popup.contains(document.activeElement)) {
                close();
                return;
            }
            if ('Tab' === event.key && modal()) {
                const focusable = Array.prototype.filter.call(
                    panel.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex="0"]'),
                    visible
                );
                const index = focusable.indexOf(document.activeElement);
                if (focusable.length && event.shiftKey && index <= 0) {
                    event.preventDefault();
                    focusable[focusable.length - 1].focus();
                } else if (focusable.length && !event.shiftKey && (-1 === index || index === focusable.length - 1)) {
                    event.preventDefault();
                    focusable[0].focus();
                }
            }
        });

        // Turning a phone, or resizing a window across the breakpoint, changes whether it is modal.
        if (phone && phone.addEventListener) {
            phone.addEventListener('change', applyModal);
        }
    }

    window.aiChatBedrockTrack = track;
    window.aiChatBedrockReplay = replayEarlyTaps;

    Array.prototype.forEach.call(document.querySelectorAll('.ai-chat-bedrock-popup'), bind);

    // With no chat on the page that can be used, nothing else is coming to repeat the taps.
    if (!document.querySelector('.ai-chat-bedrock-container:not(.is-signed-out)')) {
        replayEarlyTaps();
    }
})(window, document);

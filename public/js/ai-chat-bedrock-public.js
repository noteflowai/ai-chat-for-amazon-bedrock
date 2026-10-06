(function ($) {
    'use strict';

    const params = window.ai_chat_bedrock_params || {};
    const MAX_HISTORY = 12;
    // What the server accepts as history: 4,000 characters a message and 50,000 bytes in all.
    const MAX_HISTORY_CHARS = 4000;
    const MAX_HISTORY_BYTES = 45000;
    // A remembered conversation, as the server keeps it.
    const MAX_KEPT = 30;
    const MAX_KEPT_CHARS = 6000;
    const MEMORY_PREFIX = 'aicfabChat:';
    // The signature the site gives an answer, so it can be read aloud.
    const SPEECH_TOKEN = /^[a-f0-9]{32}$/;

    function escapeHtml(value) {
        return $('<div>').text(String(value == null ? '' : value)).html();
    }

    function formatMessage(value) {
        return renderMarkdown(escapeHtml(value));
    }

    /**
     * The Markdown a model writes, from text that is already escaped: code, emphasis,
     * headings, lists and web links. A link's address may hold no quote, space or angle
     * bracket, so it cannot leave its attribute, and only http and https are linked.
     */
    function renderMarkdown(escaped) {
        const blocks = [];
        let text = String(escaped).replace(/```[^\n]*\n?([\s\S]*?)```/g, function (match, code) {
            blocks.push('<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');
            return '\u0000' + (blocks.length - 1) + '\u0000';
        });

        function inline(line) {
            return line
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s<>"'()]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/(^|[^*\w])\*(?!\s)([^*\n]+?)\*(?!\w)/g, '$1<em>$2</em>');
        }

        const out = [];
        let list = null;
        let lines = [];
        function flushLines() {
            if (lines.length) {
                out.push('<p>' + lines.join('<br>') + '</p>');
                lines = [];
            }
        }
        function flushList() {
            if (list) {
                out.push('<' + list.tag + '>' + list.items.map(function (item) {
                    return '<li>' + item + '</li>';
                }).join('') + '</' + list.tag + '>');
                list = null;
            }
        }

        text.split('\n').forEach(function (line) {
            const bullet = /^\s{0,3}[-*+]\s+(.*)$/.exec(line);
            const number = /^\s{0,3}\d{1,3}[.)]\s+(.*)$/.exec(line);
            const heading = /^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/.exec(line);
            const item = bullet || number;
            if (item) {
                const tag = bullet ? 'ul' : 'ol';
                flushLines();
                if (list && list.tag !== tag) {
                    flushList();
                }
                list = list || { tag: tag, items: [] };
                list.items.push(inline(item[1]));
                return;
            }
            flushList();
            if (/^\u0000\d+\u0000$/.test(line.trim())) {
                flushLines();
                out.push(line.trim());
            } else if (heading) {
                flushLines();
                out.push('<p class="ai-chat-bedrock-heading"><strong>' + inline(heading[1]) + '</strong></p>');
            } else if ('' === line.trim()) {
                flushLines();
            } else {
                lines.push(inline(line));
            }
        });
        flushLines();
        flushList();

        return out.join('').replace(/\u0000(\d+)\u0000/g, function (match, index) {
            return blocks[Number(index)];
        });
    }

    function avatar(isUser) {
        const i18n = params.i18n || {};
        return $('<div>', {
            'class': 'ai-chat-bedrock-avatar',
            'aria-hidden': 'true',
            text: isUser ? (i18n.you || 'You') : (i18n.assistant || 'AI')
        });
    }

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

    /**
     * Whether a refused request was refused only for a stale nonce.
     *
     * A cached page carries the nonces it was rendered with, which stop verifying after a
     * day. The chat endpoints say so with a code; the REST API refuses a stale X-WP-Nonce
     * before the route is reached.
     */
    function isNonceError(status, data) {
        const code = data && data.code ? String(data.code) : '';
        return 403 === status && ('aicfab_bad_nonce' === code || 'rest_cookie_invalid_nonce' === code);
    }

    let nonceRequest = null;

    /**
     * Fetch fresh nonces, sharing one request between chats on the same page.
     */
    function refreshNonce() {
        if (!nonceRequest) {
            nonceRequest = Promise.resolve($.ajax({
                url: params.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: { action: 'ai_chat_bedrock_refresh_nonce' }
            })).then(function (response) {
                if (!response || !response.success || !response.data || !response.data.nonce) {
                    throw new Error(params.i18n.generic_error);
                }
                params.nonce = String(response.data.nonce);
                params.rest_nonce = String(response.data.rest_nonce || '');
            }).finally(function () {
                nonceRequest = null;
            });
        }
        return nonceRequest;
    }

    /**
     * Whether a key press sends the message: Enter without Shift, and not the Enter that
     * picks a candidate in a Chinese or Japanese input method, which would send half a word.
     */
    function isSendKey(event) {
        if ('Enter' !== event.key || event.shiftKey) {
            return false;
        }
        return !event.isComposing && 229 !== event.keyCode;
    }

    /**
     * Only web links are rendered, whatever a filter put in the list.
     */
    function safeUrl(value) {
        // An empty value would resolve to the page itself.
        if (!value || !String(value).trim()) {
            return '';
        }
        try {
            const url = new URL(String(value || ''), window.location.href);
            return 'https:' === url.protocol || 'http:' === url.protocol ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    /**
     * The other way to reach the site may also be an email or phone link.
     */
    function contactUrl(value) {
        const web = safeUrl(value);
        if (web) {
            return web;
        }
        return /^(mailto|tel):[^\s<>"]+$/i.test(String(value || '').trim()) ? String(value).trim() : '';
    }

    /**
     * Shorten text to a number of characters, counting as the server does.
     */
    function clip(value, max) {
        const text = String(value == null ? '' : value);
        if (text.length <= max) {
            return text;
        }
        const chars = Array.from(text);
        return chars.length <= max ? text : chars.slice(0, max - 1).join('') + '…';
    }

    function byteLength(text) {
        if (window.TextEncoder) {
            return new window.TextEncoder().encode(text).length;
        }
        return text.length * 3;
    }

    function memoryPrefix() {
        return MEMORY_PREFIX + String(params.user_key || '0') + ':';
    }

    /*
     * A conversation kept in this tab belongs to whoever was signed in when it was kept. Drop
     * any other, so signing out or switching accounts on a shared computer does not leave the
     * previous conversation readable, and drop all of them once the site stops keeping them.
     */
    try {
        const storage = window.sessionStorage;
        for (let i = storage.length - 1; i >= 0; i--) {
            const key = storage.key(i);
            if (key && 0 === key.indexOf(MEMORY_PREFIX) && (!params.memory || 0 !== key.indexOf(memoryPrefix()))) {
                storage.removeItem(key);
            }
        }
    } catch (error) {
        // Session storage is unavailable, so nothing was kept in it either.
    }

    function streamingAvailable() {
        return Boolean(
            params.streaming &&
            params.stream_url &&
            window.fetch &&
            window.TextDecoder &&
            window.AbortController
        );
    }

    $('.ai-chat-bedrock-popup').each(function () {
        const $popup = $(this);
        const $launcher = $popup.find('.ai-chat-bedrock-launcher');
        const $panel = $popup.find('.ai-chat-bedrock-popup-panel');

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
            return !!(phone && phone.matches) && 'open' === $popup.attr('data-state') && !$popup.hasClass('is-signed-out');
        }

        function setOpen(open, focus) {
            $popup.attr('data-state', open ? 'open' : 'closed');
            $launcher.attr('aria-expanded', open ? 'true' : 'false');
            $panel.prop('hidden', !open);
            $panel.attr('aria-modal', modal() ? 'true' : null);
            $(document.documentElement).toggleClass('aicfab-chat-open', modal());
            if (open && false !== focus) {
                /*
                 * Focus goes into the panel either way. On a touch screen it goes to the panel
                 * itself rather than the message box, whose keyboard would cover the welcome
                 * and the suggested questions the moment the chat opened.
                 */
                const touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
                const $target = $panel.find(touch ? '.ai-chat-bedrock-sign-in-link' : '.ai-chat-bedrock-textarea, .ai-chat-bedrock-sign-in-link').first();
                ($target.length ? $target : $panel).trigger('focus');
            }
        }

        $launcher.on('click', function () {
            const open = 'open' !== $popup.attr('data-state');
            setOpen(open);
            remember(open);
            if (open) {
                track('ai_chat_open', { chat_profile: String($panel.find('.ai-chat-bedrock-container').attr('data-profile') || '') });
            }
        });

        // Keep the panel open while the visitor browses other pages in this tab, beside the
        // page. On a phone it would cover each new page whole, so there it waits to be opened.
        try {
            if ('1' === window.sessionStorage.getItem(stateKey) && !(phone && phone.matches && !$popup.hasClass('is-signed-out'))) {
                setOpen(true, false);
            }
        } catch (error) {
            // Ignore storage failures.
        }

        function close() {
            setOpen(false);
            remember(false);
            $launcher.trigger('focus');
        }

        $popup.find('.ai-chat-bedrock-close').on('click', close);

        // Only while focus is in the chat, so Escape still closes a theme's menu or dialog
        // without closing the chat behind it.
        $(document).on('keydown', function (event) {
            if ('Escape' === event.key && 'open' === $popup.attr('data-state') && $popup[0].contains(document.activeElement)) {
                close();
                return;
            }
            if ('Tab' === event.key && modal()) {
                const $focusable = $panel.find('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex="0"]').filter(':visible');
                const index = $focusable.index(document.activeElement);
                if ($focusable.length && event.shiftKey && index <= 0) {
                    event.preventDefault();
                    $focusable.last().trigger('focus');
                } else if ($focusable.length && !event.shiftKey && (-1 === index || index === $focusable.length - 1)) {
                    event.preventDefault();
                    $focusable.first().trigger('focus');
                }
            }
        });

        // Turning a phone, or resizing a window across the breakpoint, changes whether it is modal.
        if (phone && phone.addEventListener) {
            phone.addEventListener('change', function () {
                $panel.attr('aria-modal', modal() ? 'true' : null);
                $(document.documentElement).toggleClass('aicfab-chat-open', modal());
            });
        }
    });

    $('.ai-chat-bedrock-container').each(function () {
        const $container = $(this);
        // A visitor who has to sign in first sees a link, not a message box.
        if ($container.hasClass('is-signed-out')) {
            return;
        }
        const $messages = $container.find('.ai-chat-bedrock-messages');
        const $textarea = $container.find('.ai-chat-bedrock-textarea');
        const $submit = $container.find('.ai-chat-bedrock-submit');
        const $clear = $container.find('.ai-chat-bedrock-clear');
        const $form = $container.find('.ai-chat-bedrock-form');
        const $suggestions = $container.find('.ai-chat-bedrock-suggestions');
        const $usage = $container.find('.ai-chat-bedrock-usage');
        const contact = params.contact && params.contact.url ? params.contact : null;
        const profile = String($container.attr('data-profile') || '');
        // The profile's own greeting; the site-wide one replaced it when the chat was cleared.
        const welcome = $container.attr('data-welcome') || params.welcome_message;
        let history = [];
        let pending = false;
        // The conversation as kept between pages, with the links shown under each answer.
        const storageKey = params.memory ? memoryPrefix() + (profile || 'default') : '';
        let transcript = [];
        let sentSinceLoad = false;

        // The list follows a growing answer only while the reader is at the bottom. Scrolled
        // up to read something earlier, they are not dragged back down by every chunk.
        let follow = true;
        $messages.on('scroll', function () {
            follow = this.scrollHeight - this.scrollTop - this.clientHeight < 48;
        });

        function scrollToBottom(force) {
            if (force) {
                follow = true;
            }
            if (follow) {
                $messages.scrollTop($messages.prop('scrollHeight'));
            }
        }

        /**
         * Render a streamed answer at most once a frame. Rendering on every chunk redid the
         * whole growing answer dozens of times a second, which slows as the answer grows.
         */
        function schedulePaint(state) {
            if (state.frame) {
                return;
            }
            if (!window.requestAnimationFrame) {
                paint(state);
                return;
            }
            state.frame = window.requestAnimationFrame(function () {
                state.frame = 0;
                paint(state);
            });
        }

        function paint(state) {
            if (state.frame) {
                window.cancelAnimationFrame(state.frame);
                state.frame = 0;
            }
            if (state.bubble) {
                state.bubble.content.html(formatMessage(state.text));
                scrollToBottom();
            }
        }

        // What is waiting for the next frame is shown now, before the answer is read or kept.
        function settle(state) {
            if (state.frame) {
                paint(state);
            }
        }

        function timeLabel(seconds) {
            try {
                return (seconds ? new Date(seconds * 1000) : new Date()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (error) {
                return '';
            }
        }

        function copyButton($content) {
            const label = params.i18n.copy || '';
            const $button = $('<button>', {
                type: 'button',
                'class': 'ai-chat-bedrock-copy',
                title: label,
                'aria-label': label
            }).text(label);

            $button.on('click', function () {
                // innerText keeps the breaks between paragraphs and list items; text() runs them together.
                const text = String(($content[0] && $content[0].innerText) || $content.text() || '');
                const done = function () {
                    $button.text(params.i18n.copied || label);
                    window.setTimeout(function () {
                        $button.text(label);
                    }, 1600);
                };
                const legacyCopy = function () {
                    const helper = document.createElement('textarea');
                    helper.value = text;
                    helper.setAttribute('readonly', 'readonly');
                    helper.style.position = 'absolute';
                    helper.style.left = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    let copied = false;
                    try {
                        copied = document.execCommand('copy');
                    } catch (error) {
                        copied = false;
                    }
                    document.body.removeChild(helper);
                    if (copied) {
                        done();
                    }
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    // Fall back when the clipboard is blocked, for example on insecure origins.
                    navigator.clipboard.writeText(text).then(done, legacyCopy);
                    return;
                }
                legacyCopy();
            });

            return $button;
        }

        function stepTrace(steps, truncated) {
            if (!Array.isArray(steps) || !steps.length) {
                return null;
            }

            const $details = $('<details>', { 'class': 'ai-chat-bedrock-steps' });
            const label = (params.i18n.steps_summary || '').replace('%d', steps.length);
            $details.append($('<summary>').text(label));

            const $list = $('<ol>', { 'class': 'ai-chat-bedrock-steps-list' });
            steps.forEach(function (step) {
                const tool = String((step && step.tool) || '');
                const label = String((step && step.label) || tool);
                const ok = !step || 'error' !== step.status;
                const round = Number((step && step.round) || 1);
                const $item = $('<li>');
                $item.append($('<code>', { title: tool }).text(label));
                $item.append(
                    $('<span>', { 'class': 'ai-chat-bedrock-step-status' + (ok ? '' : ' is-error') })
                        .text(ok ? (params.i18n.step_ok || '') : (step.code || params.i18n.step_error || ''))
                );
                if (params.i18n.step_round) {
                    $item.append($('<span>', { 'class': 'ai-chat-bedrock-step-round' }).text(params.i18n.step_round.replace('%d', round)));
                }
                $list.append($item);
            });
            $details.append($list);

            if (truncated && params.i18n.steps_truncated) {
                $details.append($('<p>', { 'class': 'ai-chat-bedrock-steps-note' }).text(params.i18n.steps_truncated));
            }

            return $details;
        }

        function attachNote(bubble, text) {
            if (!bubble || !bubble.message || !text) {
                return;
            }
            const $body = bubble.message.find('.ai-chat-bedrock-message-body').first();
            if (!$body.length || $body.find('.ai-chat-bedrock-fallback-note').length) {
                return;
            }
            $('<p>', { 'class': 'ai-chat-bedrock-fallback-note' })
                .text(text)
                .insertAfter($body.find('.ai-chat-bedrock-message-content').first());
        }

        function fallbackNote(model) {
            if (!model || !params.i18n.fallback_used) {
                return '';
            }
            return params.i18n.fallback_used.replace('%s', String(model));
        }

        function attachSteps(bubble, steps, truncated) {
            if (!bubble || !bubble.message) {
                return;
            }
            const trace = stepTrace(steps, truncated);
            if (!trace) {
                return;
            }
            const $body = bubble.message.find('.ai-chat-bedrock-message-body').first();
            if ($body.length) {
                $body.find('.ai-chat-bedrock-steps').remove();
                trace.insertAfter($body.find('.ai-chat-bedrock-message-content').first());
            }
        }

        function attachSources(bubble, sources) {
            if (!bubble || !bubble.message || !Array.isArray(sources) || !sources.length) {
                return;
            }
            const $list = $('<ol>', { 'class': 'ai-chat-bedrock-sources-list' });
            sources.slice(0, 5).forEach(function (source) {
                const href = safeUrl(source && source.url);
                if (!href) {
                    return;
                }
                const title = String((source && source.title) || href);
                const external = new URL(href).host !== window.location.host;
                const $link = $('<a>', { href: href, rel: external ? 'noopener noreferrer' : 'noopener' }).text(title);
                if (external) {
                    $link.attr('target', '_blank');
                    if (params.i18n.opens_new_tab) {
                        $link.append($('<span>', { 'class': 'screen-reader-text' }).text(' ' + params.i18n.opens_new_tab));
                    }
                }
                $list.append($('<li>').append($link));
            });
            if (!$list.children().length) {
                return;
            }
            const $body = bubble.message.find('.ai-chat-bedrock-message-body').first();
            if (!$body.length) {
                return;
            }
            $body.find('.ai-chat-bedrock-sources').remove();
            const $sources = $('<nav>', { 'class': 'ai-chat-bedrock-sources', 'aria-label': params.i18n.sources || '' });
            $sources.append($('<span>', { 'class': 'ai-chat-bedrock-sources-label' }).text(params.i18n.sources || ''), $list);
            $sources.insertAfter($body.find('.ai-chat-bedrock-message-content').first());
        }

        /**
         * Products the answer drew on, as cards under it. Every value is inserted as text and
         * every link must be a web link, whatever a filter put in the list.
         */
        function attachProducts(bubble, products) {
            if (!bubble || !bubble.message || !Array.isArray(products) || !products.length) {
                return;
            }
            const i18n = params.i18n || {};
            const $list = $('<ul>', { 'class': 'ai-chat-bedrock-products-list' });
            products.slice(0, 8).forEach(function (product) {
                const href = safeUrl(product && product.url);
                if (!href) {
                    return;
                }
                const name = String(product.name || href);
                const $card = $('<li>', { 'class': 'ai-chat-bedrock-product' });
                const image = safeUrl(product.image);
                if (image) {
                    $card.append($('<img>', { 'class': 'ai-chat-bedrock-product-image', src: image, alt: '', loading: 'lazy', width: 64, height: 64 }));
                }
                const $info = $('<div>', { 'class': 'ai-chat-bedrock-product-info' });
                $info.append($('<a>', { 'class': 'ai-chat-bedrock-product-name', href: href }).text(name));
                if (product.price) {
                    const $price = $('<span>', { 'class': 'ai-chat-bedrock-product-price' });
                    if (product.regular) {
                        $price.append(
                            $('<del>').append($('<span>', { 'class': 'screen-reader-text' }).text((i18n.original_price || '') + ' '), document.createTextNode(String(product.regular))),
                            ' ',
                            $('<ins>').append($('<span>', { 'class': 'screen-reader-text' }).text((i18n.current_price || '') + ' '), document.createTextNode(String(product.price)))
                        );
                    } else {
                        $price.text(String(product.price));
                    }
                    $info.append($price);
                }
                const details = [];
                if (product.stock) {
                    details.push(String(product.stock));
                }
                if (Number(product.rating) > 0 && i18n.rating) {
                    details.push('★ ' + String(i18n.rating).replace('%s', Number(product.rating).toFixed(1)));
                }
                if (details.length) {
                    $info.append($('<span>', { 'class': 'ai-chat-bedrock-product-meta' + (false === product.in_stock ? ' is-out-of-stock' : '') }).text(details.join(' · ')));
                }
                const $actions = $('<span>', { 'class': 'ai-chat-bedrock-product-actions' });
                $actions.append($('<a>', { 'class': 'ai-chat-bedrock-product-view', href: href }).text(i18n.view_product || name));
                const cart = safeUrl(product.add_to_cart);
                if (cart) {
                    $actions.append($('<a>', { 'class': 'ai-chat-bedrock-product-cart', href: cart, rel: 'nofollow' }).text(i18n.add_to_cart || ''));
                }
                $info.append($actions);
                $list.append($card.append($info));
            });
            if (!$list.children().length) {
                return;
            }
            const $body = bubble.message.find('.ai-chat-bedrock-message-body').first();
            if (!$body.length) {
                return;
            }
            $body.find('.ai-chat-bedrock-products').remove();
            const $products = $('<nav>', { 'class': 'ai-chat-bedrock-products', 'aria-label': i18n.products || '' });
            $products.append($list);
            const $anchor = $body.find('.ai-chat-bedrock-sources').first();
            $products.insertAfter($anchor.length ? $anchor : $body.find('.ai-chat-bedrock-message-content').first());
        }

        function feedbackControls(entryId) {
            if (!params.feedback_url || !entryId) {
                return null;
            }

            const $wrap = $('<span>', { 'class': 'ai-chat-bedrock-feedback' });
            const $label = $('<span>', { 'class': 'ai-chat-bedrock-feedback-label' }).text(params.i18n.feedback_prompt || '');
            const buttons = {};

            const rate = function (value) {
                if ($wrap.data('sent')) {
                    return;
                }
                $wrap.data('sent', true);
                $.ajax({
                    url: params.feedback_url,
                    method: 'POST',
                    dataType: 'json',
                    contentType: 'application/json',
                    headers: params.rest_nonce ? { 'X-WP-Nonce': params.rest_nonce } : {},
                    data: JSON.stringify({ entry: entryId, rating: value, profile: profile })
                }).done(function () {
                    track('ai_chat_feedback', { chat_profile: profile, rating: value });
                    $wrap.empty().append($('<span>', { 'class': 'ai-chat-bedrock-feedback-label' }).text(params.i18n.feedback_thanks || ''));
                    // An answer that did not help is where a person can.
                    if ('down' === value && contact) {
                        $wrap.append(
                            $('<span>', { 'class': 'ai-chat-bedrock-feedback-label' }).text(params.i18n.contact_offer || ''),
                            $('<button>', { type: 'button', 'class': 'ai-chat-bedrock-copy ai-chat-bedrock-contact-offer' }).text(params.i18n.contact_open || '').on('click', openContact)
                        );
                    }
                }).fail(function () {
                    $wrap.data('sent', false);
                    buttons.up.prop('disabled', false);
                    buttons.down.prop('disabled', false);
                });
            };

            buttons.up = $('<button>', {
                type: 'button',
                'class': 'ai-chat-bedrock-feedback-button',
                title: params.i18n.feedback_up || '',
                'aria-label': params.i18n.feedback_up || ''
            }).text('👍').on('click', function () { rate('up'); });

            buttons.down = $('<button>', {
                type: 'button',
                'class': 'ai-chat-bedrock-feedback-button',
                title: params.i18n.feedback_down || '',
                'aria-label': params.i18n.feedback_down || ''
            }).text('👎').on('click', function () { rate('down'); });

            $wrap.append($label, buttons.up, buttons.down);
            return $wrap;
        }

        /**
         * A Listen button for an answer the site signed. The text sent back is the answer as
         * it was given, so the signature matches.
         */
        function attachSpeech(bubble, text, token) {
            const speak = window.aicfabSpeak;
            if (!params.speech || !speak || !bubble || !bubble.message || 'string' !== typeof text || !text || !SPEECH_TOKEN.test(String(token || ''))) {
                return;
            }
            const $meta = bubble.message.find('.ai-chat-bedrock-message-meta').first();
            if ($meta.find('.ai-chat-bedrock-listen').length) {
                return;
            }
            const $status = $('<span>', { 'class': 'ai-chat-bedrock-listen-status', role: 'status' });
            const $button = $('<button>', {
                type: 'button',
                'class': 'ai-chat-bedrock-copy ai-chat-bedrock-listen',
                'aria-pressed': 'false'
            }).text(speak.label || '');
            const fetchPart = function (part, retried) {
                return speak.request({ text: text, token: token, part: part, lang: params.language || '' }, params.rest_nonce).catch(function (error) {
                    if (!retried && error && isNonceError(error.status, { code: error.code })) {
                        return refreshNonce().then(function () {
                            return fetchPart(part, true);
                        });
                    }
                    throw error;
                });
            };
            $button.on('click', function () {
                speak.play($button[0], function (part) {
                    return fetchPart(part, false);
                }, $status[0]);
            });
            const $copy = $meta.find('.ai-chat-bedrock-copy').first();
            if ($copy.length) {
                $button.insertAfter($copy);
            } else {
                $meta.append($button);
            }
            $status.insertAfter($button);
        }

        function attachFeedback(bubble, entryId) {
            if (!bubble || !bubble.message || !entryId) {
                return;
            }
            const $meta = bubble.message.find('.ai-chat-bedrock-message-meta').first();
            if (!$meta.length || $meta.find('.ai-chat-bedrock-feedback').length) {
                return;
            }
            const controls = feedbackControls(entryId);
            if (controls) {
                $meta.append(controls);
            }
        }

        /**
         * The form a visitor leaves their details in, as a message from the assistant. Only
         * one is open at a time, and nothing is sent until they agree to it.
         */
        function openContact() {
            if (!contact) {
                return;
            }
            const $open = $messages.find('.ai-chat-bedrock-contact');
            if ($open.length) {
                $open.find('input, textarea').filter(':visible').first().trigger('focus');
                return;
            }
            const i18n = params.i18n || {};
            const id = 'aicfab-contact-' + Math.random().toString(36).slice(2, 10);
            const $form = $('<form>', { 'class': 'ai-chat-bedrock-contact', novalidate: 'novalidate', 'aria-labelledby': id + '-title' });
            const field = function (name, label, $control, help) {
                const $wrap = $('<p>', { 'class': 'ai-chat-bedrock-contact-field' });
                $control.attr({ id: id + '-' + name, name: name });
                $wrap.append($('<label>', { 'for': id + '-' + name }).text(label), $control);
                if (help) {
                    $control.attr('aria-describedby', id + '-' + name + '-help');
                    $wrap.append($('<small>', { id: id + '-' + name + '-help' }).text(help));
                }
                return $wrap;
            };
            const lastQuestion = (function () {
                for (let i = history.length - 1; i >= 0; i--) {
                    if ('user' === history[i].role) {
                        return history[i].content;
                    }
                }
                return '';
            }());
            const $error = $('<p>', { 'class': 'ai-chat-bedrock-contact-error', role: 'alert' });
            const $send = $('<button>', { type: 'submit', 'class': 'button button-primary' }).text(i18n.contact_send || '');
            const $cancel = $('<button>', { type: 'button', 'class': 'button button-secondary' }).text(i18n.contact_cancel || '');
            const $consent = $('<input>', { type: 'checkbox', name: 'consent', value: '1', required: 'required' });
            const $include = $('<input>', { type: 'checkbox', name: 'include', value: '1', checked: 'checked' });
            const $consentLabel = $('<label>').append($consent, ' ', document.createTextNode(i18n.contact_consent || ''));
            const privacy = safeUrl(contact.privacy_url);
            if (privacy) {
                $consentLabel.append(' ', $('<a>', { href: privacy, target: '_blank', rel: 'noopener noreferrer' }).text(i18n.contact_privacy || ''));
            }

            $form.append(
                $('<p>', { id: id + '-title', 'class': 'ai-chat-bedrock-contact-title' }).text(i18n.contact_title || ''),
                field('name', i18n.contact_name || '', $('<input>', { type: 'text', maxlength: 100, autocomplete: 'name' })),
                field('email', i18n.contact_email || '', $('<input>', { type: 'email', maxlength: 200, autocomplete: 'email' }), contact.signed_in ? i18n.contact_email_own : ''),
                field('phone', i18n.contact_phone || '', $('<input>', { type: 'tel', maxlength: 40, autocomplete: 'tel' })),
                field('message', i18n.contact_message || '', $('<textarea>', { rows: 3, maxlength: 2000 }).val(lastQuestion)),
                // Left empty by people, who never see it.
                $('<p>', { 'class': 'ai-chat-bedrock-contact-trap', 'aria-hidden': 'true' }).append($('<input>', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off' })),
                history.length ? $('<p>', { 'class': 'ai-chat-bedrock-contact-check' }).append($('<label>').append($include, ' ', document.createTextNode(i18n.contact_include || ''))) : null,
                $('<p>', { 'class': 'ai-chat-bedrock-contact-check' }).append($consentLabel),
                $error,
                $('<p>', { 'class': 'ai-chat-bedrock-contact-actions' }).append($send, ' ', $cancel)
            );
            const other = contactUrl(contact.link);
            if (other) {
                $form.append($('<p>', { 'class': 'ai-chat-bedrock-contact-other' }).append(
                    $('<a>', { href: other, target: '_blank', rel: 'noopener noreferrer' }).text(i18n.contact_other || other)
                ));
            }

            const bubble = appendBubble(false);
            bubble.message.addClass('is-contact');
            bubble.message.find('.ai-chat-bedrock-copy').remove();
            bubble.content.append($form);
            scrollToBottom();
            $form.find('input[name="name"]').trigger('focus');

            $cancel.on('click', function () {
                bubble.message.remove();
                $textarea.trigger('focus');
            });

            const send = function (body, retried) {
                return $.ajax({
                    url: contact.url,
                    method: 'POST',
                    dataType: 'json',
                    contentType: 'application/json',
                    headers: params.rest_nonce ? { 'X-WP-Nonce': params.rest_nonce } : {},
                    data: JSON.stringify(body)
                }).then(null, function (xhr) {
                    const data = xhr && xhr.responseJSON;
                    if (!retried && isNonceError(xhr.status, data)) {
                        return refreshNonce().then(function () {
                            return send(body, true);
                        });
                    }
                    return $.Deferred().reject(data && data.message ? data.message : (i18n.generic_error || '')).promise();
                });
            };

            $form.on('submit', function (event) {
                event.preventDefault();
                const value = function (name) {
                    return String($form.find('[name="' + name + '"]').val() || '').trim();
                };
                const hasContact = value('email') || value('phone').replace(/[^0-9]/g, '').length >= 5 || contact.signed_in;
                if (!hasContact || !$consent.prop('checked')) {
                    $error.text(i18n.contact_needed || '');
                    return;
                }
                $error.text('');
                $send.prop('disabled', true);
                send({
                    name: value('name'),
                    email: value('email'),
                    phone: value('phone'),
                    message: value('message'),
                    website: value('website'),
                    consent: true,
                    conversation: history.length && $include.prop('checked') ? history.slice(-20) : [],
                    page: window.location.href,
                    lang: params.language || '',
                    profile: profile
                }).then(function () {
                    track('ai_chat_contact', { chat_profile: profile });
                    const thanks = i18n.contact_sent || '';
                    bubble.content.empty().text(thanks);
                    announce(thanks);
                    scrollToBottom();
                }, function (message) {
                    $send.prop('disabled', false);
                    $error.text(String(message || i18n.generic_error || ''));
                });
            });
        }

        $container.find('.ai-chat-bedrock-contact-open').on('click', openContact);

        function answered(data) {
            track('ai_chat_answer', {
                chat_profile: profile,
                sources: Array.isArray(data.sources) ? Math.min(5, data.sources.length) : 0,
                products: Array.isArray(data.products) ? Math.min(8, data.products.length) : 0
            });
        }

        $messages.on('click', '.ai-chat-bedrock-sources a', function () {
            track('ai_chat_source_click', { chat_profile: profile, link_url: String($(this).attr('href') || '') });
        });
        $messages.on('click', '.ai-chat-bedrock-product a', function () {
            const $link = $(this);
            track('ai_chat_product_click', {
                chat_profile: profile,
                link_url: String($link.attr('href') || ''),
                product_action: $link.hasClass('ai-chat-bedrock-product-cart') ? 'add_to_cart' : 'view'
            });
        });

        function appendBubble(isUser, seconds) {
            hideSuggestions();
            const $content = $('<div>', { 'class': 'ai-chat-bedrock-message-content' });
            const $body = $('<div>', { 'class': 'ai-chat-bedrock-message-body' });
            const $meta = $('<div>', { 'class': 'ai-chat-bedrock-message-meta' });
            const $message = $('<div>', { 'class': 'ai-chat-bedrock-message ' + (isUser ? 'user-message' : 'ai-message') });

            $meta.append($('<time>', { 'class': 'ai-chat-bedrock-time' }).text(timeLabel(seconds)));
            if (!isUser) {
                $meta.append(copyButton($content));
            }

            // The avatar is hidden from screen readers, so who is speaking is said in words.
            const i18n = params.i18n || {};
            const $speaker = $('<span>', { 'class': 'screen-reader-text' }).text(isUser ? (i18n.you_said || 'You said:') : (i18n.assistant_said || 'Assistant:'));
            $content.attr('dir', 'auto');
            $body.append($speaker, $content, $meta);
            $message.append(avatar(isUser), $body);
            $messages.find('.ai-chat-bedrock-welcome-message').remove();
            $messages.append($message);
            scrollToBottom(isUser);
            return { message: $message, content: $content };
        }

        function typingIndicator() {
            const $typing = $('<div>', { 'class': 'ai-chat-bedrock-message ai-message' });
            const $dots = $('<div>', { 'class': 'ai-chat-bedrock-typing', 'aria-hidden': 'true' });
            $dots.append($('<span>'), $('<span>'), $('<span>'));
            $typing.append(avatar(false), $dots);
            $messages.find('.ai-chat-bedrock-welcome-message').remove();
            $messages.append($typing);
            scrollToBottom();
            return $typing;
        }

        function showStatus(text) {
            $messages.find('.ai-chat-bedrock-status').remove();
            const $status = $('<div>', { 'class': 'ai-chat-bedrock-status', role: 'status' }).text(text);
            $messages.append($status);
            scrollToBottom();
            return $status;
        }

        function showUsage(usage) {
            if (!usage || !$usage.length) {
                return;
            }
            const input = Number(usage.input_tokens || 0);
            const output = Number(usage.output_tokens || 0);
            if (!input && !output) {
                return;
            }
            const template = params.i18n.usage || '';
            $usage.text(template ? template.replace('%1$d', input).replace('%2$d', output) : (input + ' / ' + output));
        }

        function remember(role, content) {
            history.push({ role: role, content: String(content) });
            history = history.slice(-MAX_HISTORY);
        }

        /**
         * The recent conversation, within what the server accepts. A long answer in Chinese or
         * Japanese takes three bytes a character, so a dozen of them could exceed the byte limit
         * and the next question was refused as too large.
         */
        function historyForRequest() {
            const picked = [];
            let bytes = 2;
            for (let i = history.length - 1; i >= 0 && picked.length < MAX_HISTORY; i--) {
                const item = { role: history[i].role, content: clip(history[i].content, MAX_HISTORY_CHARS) };
                const size = byteLength(JSON.stringify(item)) + 1;
                if (bytes + size > MAX_HISTORY_BYTES) {
                    break;
                }
                bytes += size;
                picked.unshift(item);
            }
            while (picked.length && 'user' !== picked[0].role) {
                picked.shift();
            }
            return picked;
        }

        function cleanTranscript(items) {
            const clean = [];
            (Array.isArray(items) ? items : []).forEach(function (item) {
                if (!item || ('user' !== item.role && 'assistant' !== item.role) || 'string' !== typeof item.content || !item.content) {
                    return;
                }
                const entry = { role: item.role, content: clip(item.content, MAX_KEPT_CHARS), time: Number(item.time) || 0 };
                // A clipped answer no longer matches its signature.
                if ('assistant' === item.role && entry.content === item.content && SPEECH_TOKEN.test(String(item.speech || ''))) {
                    entry.speech = String(item.speech);
                }
                if ('assistant' === item.role && Array.isArray(item.sources) && item.sources.length) {
                    entry.sources = item.sources.slice(0, 5).map(function (source) {
                        return { title: String((source && source.title) || ''), url: String((source && source.url) || '') };
                    });
                }
                clean.push(entry);
            });
            const kept = clean.slice(-MAX_KEPT);
            while (kept.length && 'user' !== kept[0].role) {
                kept.shift();
            }
            return kept;
        }

        function storeTranscript() {
            if (!storageKey) {
                return;
            }
            try {
                if (transcript.length) {
                    window.sessionStorage.setItem(storageKey, JSON.stringify(transcript));
                } else {
                    window.sessionStorage.removeItem(storageKey);
                }
            } catch (error) {
                // Storage is full or unavailable; the conversation is simply not kept.
            }
        }

        function keep(question, answer, sources, speech) {
            if (!storageKey || !answer) {
                return;
            }
            const time = Math.floor(Date.now() / 1000);
            transcript = cleanTranscript(transcript.concat([
                { role: 'user', content: question, time: time },
                { role: 'assistant', content: answer, time: time, sources: sources, speech: speech }
            ]));
            storeTranscript();
        }

        /**
         * Show a kept conversation in place of the greeting. Answers come back with their
         * sources; feedback buttons and product cards belonged to the moment and do not.
         */
        function showTranscript(items) {
            const clean = cleanTranscript(items);
            if (!clean.length) {
                return false;
            }
            $messages.empty();
            history = [];
            clean.forEach(function (item) {
                const bubble = appendBubble('user' === item.role, item.time);
                bubble.content.html(formatMessage(item.content));
                remember(item.role, item.content);
                if ('assistant' === item.role) {
                    attachSources(bubble, item.sources);
                    attachSpeech(bubble, item.content, item.speech);
                }
            });
            transcript = clean;
            scrollToBottom();
            return true;
        }

        function sameTranscript(a, b) {
            return a.length === b.length && a.every(function (item, index) {
                return item.role === b[index].role && item.content === b[index].content;
            });
        }

        function savedHistory(method, retried) {
            const url = String(params.history_url || '');
            return Promise.resolve($.ajax({
                url: url + (-1 === url.indexOf('?') ? '?' : '&') + 'profile=' + encodeURIComponent(profile),
                method: method,
                dataType: 'json',
                headers: params.rest_nonce ? { 'X-WP-Nonce': params.rest_nonce } : {}
            })).catch(function (xhr) {
                if (!retried && xhr && isNonceError(xhr.status, xhr.responseJSON)) {
                    return refreshNonce().then(function () {
                        return savedHistory(method, true);
                    });
                }
                throw xhr;
            });
        }

        function hideSuggestions() {
            if ($suggestions.length) {
                $suggestions.attr('hidden', 'hidden');
            }
        }

        function showSuggestions() {
            if ($suggestions.length) {
                $suggestions.removeAttr('hidden');
            }
        }

        function showWelcome() {
            history = [];
            $messages.empty();
            $usage.text('');
            showSuggestions();
            const $welcome = $('<div>', { 'class': 'ai-chat-bedrock-welcome-message' });
            const $message = $('<div>', { 'class': 'ai-chat-bedrock-message ai-message' });
            $message.append(avatar(false), $('<div>', { 'class': 'ai-chat-bedrock-message-content' }).text(welcome));
            $messages.append($welcome.append($message));
        }

        function addMessage(content, isUser) {
            const bubble = appendBubble(isUser);
            bubble.content.html(formatMessage(content));
            remember(isUser ? 'user' : 'assistant', content);
            scrollToBottom();
            return bubble;
        }

        function showError(message, retry) {
            const $error = $('<div>', { 'class': 'ai-chat-bedrock-error', role: 'alert' }).text(message);
            if ('function' === typeof retry && params.i18n.retry) {
                const $retry = $('<button>', { type: 'button', 'class': 'ai-chat-bedrock-retry' }).text(params.i18n.retry);
                $retry.on('click', function () {
                    $error.remove();
                    retry();
                });
                $error.append($retry);
            }
            $messages.append($error);
            scrollToBottom();
        }

        const $stop = $container.find('.ai-chat-bedrock-stop').first();
        let controller = null;
        let stopped = false;

        function setPending(value) {
            pending = value;
            $textarea.prop('disabled', value);
            $submit.prop('disabled', value);
            // Busy is the message list, not the whole chat: the announcement region is outside
            // it, as some screen readers hold back what changes inside a busy element.
            $messages.attr('aria-busy', value ? 'true' : 'false');
            $container.toggleClass('is-busy', !!value);
            refreshStop();
        }

        /**
         * Offer Stop only while there is something to stop that can be aborted.
         */
        function refreshStop() {
            $stop.prop('hidden', !(pending && controller));
        }

        /**
         * Abort the request in flight, keeping whatever answer has arrived.
         *
         * The server notices the connection has gone and tears down the Bedrock stream,
         * so stopping also stops paying for the rest of the answer.
         */
        function stopAnswering() {
            if (!controller) {
                return;
            }
            stopped = true;
            try {
                controller.abort();
            } catch (error) {
                // An already-finished request cannot be aborted, which is fine.
            }
        }

        $stop.on('click', stopAnswering);

        function finish() {
            controller = null;
            setPending(false);
            $textarea.trigger('focus');
        }

        /**
         * Say something once, for assistive technology.
         *
         * The message list is not a live region: streaming replaces the whole answer on
         * every chunk, so announcing the list read the growing answer out repeatedly.
         */
        function announce(text) {
            const value = $.trim(String(text || ''));
            if (!value) {
                return;
            }
            const $region = $container.find('.ai-chat-bedrock-announce').first();
            if (!$region.length) {
                return;
            }
            // Set it synchronously. Clearing and setting on a timer raced with anything
            // that re-rendered the conversation, which left the announcement unmade.
            // A trailing space forces a change when the same answer comes back twice,
            // since an unchanged region is not announced again.
            $region.text($.trim($region.text()) === value ? value + '\u00a0' : value);
        }

        /**
         * Send without streaming. The returned promise always resolves: every failure is
         * shown in the conversation here, so a caller only has to wait for it.
         */
        function sendBuffered(message, requestHistory, retried) {
            const $typing = typingIndicator();
            const settled = $.Deferred();

            $.ajax({
                url: params.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'ai_chat_bedrock_message',
                    nonce: params.nonce,
                    message: message,
                    history: JSON.stringify(requestHistory),
                    profile: profile,
                    lang: params.language || '',
                    product: params.product_id || 0
                }
            }).done(function (response) {
                $typing.remove();
                $messages.find('.ai-chat-bedrock-status').remove();
                if (response && response.success && response.data && typeof response.data.message === 'string') {
                    const bubble = addMessage(response.data.message, false);
                    keep(message, response.data.message, response.data.sources, response.data.speech);
                    // The message list is not a live region, so a buffered answer is
                    // announced here just as a streamed one is when it completes.
                    announce(bubble && bubble.content ? bubble.content.text() : response.data.message);
                    attachNote(bubble, fallbackNote(response.data.fallback_model));
                    attachSteps(bubble, response.data.steps, response.data.steps_truncated);
                    attachSources(bubble, response.data.sources);
                    attachProducts(bubble, response.data.products);
                    attachSpeech(bubble, response.data.message, response.data.speech);
                    attachFeedback(bubble, response.data.entry);
                    showUsage(response.usage);
                    answered(response.data);
                } else {
                    showError(
                        response && response.data && response.data.message ? response.data.message : params.i18n.generic_error,
                        retryWith(message, requestHistory)
                    );
                }
                settled.resolve();
            }).fail(function (xhr) {
                $typing.remove();
                $messages.find('.ai-chat-bedrock-status').remove();
                const response = xhr.responseJSON;
                const error = function () {
                    showError(
                        response && response.data && response.data.message ? response.data.message : params.i18n.generic_error,
                        retryWith(message, requestHistory)
                    );
                    settled.resolve();
                };
                if (!retried && isNonceError(xhr.status, response && response.data)) {
                    refreshNonce().then(function () {
                        sendBuffered(message, requestHistory, true).always(settled.resolve);
                    }, error);
                    return;
                }
                error();
            });

            return settled.promise();
        }

        function handleEvent(event, state) {
            if (!event || !event.data) {
                return;
            }

            let payload;
            try {
                payload = JSON.parse(event.data);
            } catch (error) {
                return;
            }

            if ('delta' === event.name && typeof payload.text === 'string') {
                if (!state.bubble) {
                    state.bubble = appendBubble(false);
                    state.bubble.content.addClass('is-streaming');
                }
                if (state.typing) {
                    state.typing.remove();
                    state.typing = null;
                }
                $messages.find('.ai-chat-bedrock-status').remove();
                state.text += payload.text;
                schedulePaint(state);
                return;
            }

            if ('tools' === event.name) {
                settle(state);
                state.text = '';
                if (state.bubble) {
                    state.bubble.content.empty();
                }
                const names = (Array.isArray(payload.labels) && payload.labels.length ? payload.labels : payload.tools);
                const list = Array.isArray(names) ? names.filter(Boolean) : [];
                if (list.length && params.i18n.using_named_tools) {
                    showStatus(params.i18n.using_named_tools.replace('%s', list.join(', ')));
                } else {
                    const template = params.i18n.using_tools || '';
                    showStatus(template ? template.replace('%d', Number(payload.count || 0)) : '');
                }
                return;
            }

            if ('fallback' === event.name) {
                state.fallback = true;
                return;
            }

            if ('error' === event.name) {
                state.error = payload.message || params.i18n.generic_error;
                return;
            }

            if ('done' === event.name) {
                settle(state);
                state.done = true;
                $messages.find('.ai-chat-bedrock-status').remove();
                if (typeof payload.message === 'string' && payload.message.length) {
                    state.text = payload.message;
                    if (!state.bubble) {
                        state.bubble = appendBubble(false);
                    }
                    state.bubble.content.html(formatMessage(state.text));
                    scrollToBottom();
                }
                state.sources = payload.sources;
                state.speech = typeof payload.message === 'string' ? payload.speech : '';
                if (state.bubble) {
                    state.bubble.content.removeClass('is-streaming');
                    attachNote(state.bubble, fallbackNote(payload.fallback_model));
                    attachSteps(state.bubble, payload.steps, payload.steps_truncated);
                    attachSources(state.bubble, payload.sources);
                    attachProducts(state.bubble, payload.products);
                    attachSpeech(state.bubble, payload.message, payload.speech);
                    attachFeedback(state.bubble, payload.entry);
                    answered(payload);
                }
                showUsage(payload.usage);
                // The rendered text, not the raw reply: announcing markdown makes a screen
                // reader read "star star Blue star star".
                announce(state.bubble ? state.bubble.content.text() : state.text);
            }
        }

        /**
         * Dispatch every complete event in the buffer and return what is left over.
         *
         * Follows the event stream format: lines end in LF, CRLF or CR, an event ends at a
         * blank line, a single space after the colon is dropped, and several data lines are
         * joined with a line break. A proxy is free to rewrite line endings or split data.
         */
        function parseChunk(buffer, state) {
            buffer = buffer.replace(/\r\n?/g, '\n');
            let index = buffer.indexOf('\n\n');
            while (index !== -1) {
                const raw = buffer.slice(0, index);
                buffer = buffer.slice(index + 2);

                const event = { name: 'message', data: '' };
                const data = [];
                raw.split('\n').forEach(function (line) {
                    const colon = line.indexOf(':');
                    if (0 === colon || !line) {
                        return;
                    }
                    const field = -1 === colon ? line : line.slice(0, colon);
                    let value = -1 === colon ? '' : line.slice(colon + 1);
                    if (' ' === value.charAt(0)) {
                        value = value.slice(1);
                    }
                    if ('event' === field) {
                        event.name = value;
                    } else if ('data' === field) {
                        data.push(value);
                    }
                });
                if (data.length) {
                    event.data = data.join('\n');
                    handleEvent(event, state);
                }
                index = buffer.indexOf('\n\n');
            }
            return buffer;
        }

        function sendStreaming(message, requestHistory, retried) {
            const state = { text: '', bubble: null, error: '', done: false, fallback: false, renew: false, frame: 0, typing: typingIndicator() };
            const body = new URLSearchParams();
            body.set('message', message);
            body.set('history', JSON.stringify(requestHistory));
            body.set('nonce', params.nonce);
            if (profile) {
                body.set('profile', profile);
            }
            if (params.language) {
                body.set('lang', params.language);
            }
            if (params.product_id) {
                body.set('product', String(params.product_id));
            }

            controller = window.AbortController ? new window.AbortController() : null;
            stopped = false;
            // setPending ran before this existed, so the button has to be refreshed here
            // or it would stay hidden for the whole answer.
            refreshStop();

            return window.fetch(params.stream_url, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    Accept: 'text/event-stream',
                    'X-WP-Nonce': String(params.rest_nonce || '')
                },
                body: body.toString()
            }).then(function (response) {
                if (!response.ok || !response.body) {
                    return response.json().then(function (data) {
                        if (!retried && isNonceError(response.status, data)) {
                            state.renew = true;
                            return null;
                        }
                        throw new Error(data && data.message ? data.message : params.i18n.generic_error);
                    }, function () {
                        throw new Error(params.i18n.generic_error);
                    });
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder('utf-8');
                let buffer = '';

                function read() {
                    return reader.read().then(function (chunk) {
                        if (chunk.done) {
                            // Flush a character split across the last chunk, and an event
                            // the server closed without the final blank line.
                            buffer += decoder.decode();
                            parseChunk(buffer + '\n\n', state);
                            return null;
                        }
                        buffer += decoder.decode(chunk.value, { stream: true });
                        buffer = parseChunk(buffer, state);
                        return read();
                    });
                }
                return read();
            }).then(function () {
                settle(state);
                if (state.typing) {
                    state.typing.remove();
                    state.typing = null;
                }
                $messages.find('.ai-chat-bedrock-status').remove();
                if (state.renew) {
                    return refreshNonce().then(function () {
                        return sendStreaming(message, requestHistory, true);
                    });
                }
                if (state.bubble) {
                    state.bubble.content.removeClass('is-streaming');
                }
                if (state.fallback) {
                    if (state.bubble) {
                        state.bubble.message.remove();
                    }
                    return sendBuffered(message, requestHistory);
                }
                if (state.error) {
                    if (state.bubble && !state.text) {
                        state.bubble.message.remove();
                    }
                    showError(state.error);
                    return null;
                }
                if (state.text) {
                    remember('assistant', state.text);
                    keep(message, state.text, state.sources, state.done ? state.speech : '');
                    if (stopped) {
                        announce(state.bubble ? state.bubble.content.text() : state.text);
                    }
                } else if (stopped) {
                    // Stopped before anything arrived: drop the empty bubble quietly.
                    if (state.bubble) {
                        state.bubble.message.remove();
                    }
                    announce(params.i18n.stopped || '');
                } else if (!state.done) {
                    showError(params.i18n.generic_error);
                }
                return null;
            }).catch(function (error) {
                // Aborting rejects the fetch. That is the visitor's own doing, so it must
                // not be reported as a failure.
                if (stopped) {
                    settle(state);
                    if (state.typing) {
                        state.typing.remove();
                        state.typing = null;
                    }
                    $messages.find('.ai-chat-bedrock-status').remove();
                    if (state.bubble) {
                        state.bubble.content.removeClass('is-streaming');
                    }
                    if (state.text) {
                        remember('assistant', state.text);
                        keep(message, state.text, state.sources, state.done ? state.speech : '');
                        announce(state.bubble ? state.bubble.content.text() : state.text);
                    } else {
                        if (state.bubble) {
                            state.bubble.message.remove();
                        }
                        announce(params.i18n.stopped || '');
                    }
                    return null;
                }
                throw error;
            });
        }

        function retryWith(message, requestHistory) {
            return function () {
                if (pending) {
                    return;
                }
                setPending(true);
                if (streamingAvailable()) {
                    sendStreaming(message, requestHistory).catch(function (error) {
                        $messages.find('.ai-chat-bedrock-typing').closest('.ai-chat-bedrock-message').remove();
                        $messages.find('.ai-chat-bedrock-status').remove();
                        showError(error && error.message ? error.message : params.i18n.generic_error, retryWith(message, requestHistory));
                    }).then(finish, finish);
                    return;
                }
                sendBuffered(message, requestHistory).always(finish);
            };
        }

        function submitMessage(event, suggested) {
            if (event) {
                event.preventDefault();
            }
            if (pending) {
                return;
            }
            const message = String($textarea.val() || '').trim();
            if (!message) {
                return;
            }
            if (message.length > Number(params.max_message_chars || 4000)) {
                showError(params.i18n.too_long);
                return;
            }

            track('ai_chat_question', { chat_profile: profile, question_source: true === suggested ? 'suggestion' : 'typed' });
            const requestHistory = historyForRequest();
            sentSinceLoad = true;
            addMessage(message, true);
            $textarea.val('');
            setPending(true);

            if (streamingAvailable()) {
                sendStreaming(message, requestHistory).catch(function (error) {
                    $messages.find('.ai-chat-bedrock-typing').closest('.ai-chat-bedrock-message').remove();
                    $messages.find('.ai-chat-bedrock-status').remove();
                    showError(error && error.message ? error.message : params.i18n.generic_error, retryWith(message, requestHistory));
                }).then(finish, finish);
                return;
            }

            sendBuffered(message, requestHistory).always(finish);
        }

        function autoGrow() {
            const element = $textarea.get(0);
            if (!element) {
                return;
            }
            element.style.height = 'auto';
            element.style.height = Math.min(220, Math.max(58, element.scrollHeight)) + 'px';
        }

        $suggestions.on('click', '.ai-chat-bedrock-suggestion', function () {
            const text = String($(this).text() || '').trim();
            if (!text || pending) {
                return;
            }
            $textarea.val(text);
            autoGrow();
            submitMessage(null, true);
        });

        $form.on('submit', submitMessage);
        $submit.on('click', submitMessage);
        $textarea.on('input', autoGrow);
        $textarea.on('keydown', function (event) {
            if (isSendKey(event.originalEvent || event)) {
                submitMessage(event);
            }
        });
        $clear.on('click', function () {
            if (!window.confirm(params.i18n.clear_confirm)) {
                return;
            }
            showWelcome();
            $textarea.val('');
            autoGrow();
            transcript = [];
            storeTranscript();
            // Cleared here, cleared everywhere: the copy saved with the account goes too.
            if (params.history_url) {
                savedHistory('DELETE').catch(function () {
                    // Nothing to tell the visitor; the next page shows what is still saved.
                });
            }
            $textarea.trigger('focus');
        });

        // Bring back the conversation kept in this tab, then the one saved with the account,
        // which is newer when the visitor last chatted on another device.
        if (storageKey) {
            try {
                showTranscript(JSON.parse(window.sessionStorage.getItem(storageKey) || '[]'));
            } catch (error) {
                // A kept conversation that cannot be read is left out.
            }
        }
        if (params.history_url) {
            savedHistory('GET').then(function (data) {
                // Never replace a conversation the visitor has already carried on.
                if (sentSinceLoad || pending || !data || !data.enabled || !Array.isArray(data.messages)) {
                    return;
                }
                const saved = cleanTranscript(data.messages);
                if (sameTranscript(saved, transcript)) {
                    return;
                }
                if (!saved.length) {
                    showWelcome();
                    transcript = [];
                } else {
                    showTranscript(saved);
                }
                storeTranscript();
            }).catch(function () {
                // The kept or empty conversation stays as it is.
            });
        }
    });

    /*
     * Taps on the chat made while an optimizer held this script back, as noted by
     * ai-chat-bedrock-early.js. They are repeated now that the chat can answer them. A tap on
     * the chat button is skipped when the chat is already open, since remembering an open
     * chat may have opened it, and repeating the tap would close it again.
     */
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
})(jQuery);

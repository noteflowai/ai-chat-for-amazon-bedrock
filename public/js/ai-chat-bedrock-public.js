(function ($) {
    'use strict';

    const params = window.ai_chat_bedrock_params || {};
    const MAX_HISTORY = 12;

    function escapeHtml(value) {
        return $('<div>').text(String(value == null ? '' : value)).html();
    }

    function formatMessage(value) {
        let text = escapeHtml(value);
        text = text.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        return text.replace(/\n/g, '<br>');
    }

    function avatar(isUser) {
        return $('<div>', {
            'class': 'ai-chat-bedrock-avatar',
            'aria-hidden': 'true',
            text: isUser ? 'You' : 'AI'
        });
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

        function setOpen(open, focus) {
            $popup.attr('data-state', open ? 'open' : 'closed');
            $launcher.attr('aria-expanded', open ? 'true' : 'false');
            $panel.prop('hidden', !open);
            if (open && false !== focus) {
                $panel.find('.ai-chat-bedrock-textarea').trigger('focus');
            }
        }

        $launcher.on('click', function () {
            const open = 'open' !== $popup.attr('data-state');
            setOpen(open);
            remember(open);
        });

        // Keep the panel open while the visitor browses other pages in this tab.
        try {
            if ('1' === window.sessionStorage.getItem(stateKey)) {
                setOpen(true, false);
            }
        } catch (error) {
            // Ignore storage failures.
        }

        $(document).on('keydown', function (event) {
            if ('Escape' === event.key && 'open' === $popup.attr('data-state')) {
                setOpen(false);
                remember(false);
                $launcher.trigger('focus');
            }
        });
    });

    $('.ai-chat-bedrock-container').each(function () {
        const $container = $(this);
        const $messages = $container.find('.ai-chat-bedrock-messages');
        const $textarea = $container.find('.ai-chat-bedrock-textarea');
        const $submit = $container.find('.ai-chat-bedrock-submit');
        const $clear = $container.find('.ai-chat-bedrock-clear');
        const $form = $container.find('.ai-chat-bedrock-form');
        const $suggestions = $container.find('.ai-chat-bedrock-suggestions');
        const $usage = $container.find('.ai-chat-bedrock-usage');
        const profile = String($container.attr('data-profile') || '');
        let history = [];
        let pending = false;

        function scrollToBottom() {
            $messages.scrollTop($messages.prop('scrollHeight'));
        }

        function timeLabel() {
            try {
                return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
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
                const text = String($content.text() || '');
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
                    data: JSON.stringify({ entry: entryId, rating: value })
                }).done(function () {
                    $wrap.empty().append($('<span>', { 'class': 'ai-chat-bedrock-feedback-label' }).text(params.i18n.feedback_thanks || ''));
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

        function appendBubble(isUser) {
            hideSuggestions();
            const $content = $('<div>', { 'class': 'ai-chat-bedrock-message-content' });
            const $body = $('<div>', { 'class': 'ai-chat-bedrock-message-body' });
            const $meta = $('<div>', { 'class': 'ai-chat-bedrock-message-meta' });
            const $message = $('<div>', { 'class': 'ai-chat-bedrock-message ' + (isUser ? 'user-message' : 'ai-message') });

            $meta.append($('<time>', { 'class': 'ai-chat-bedrock-time' }).text(timeLabel()));
            if (!isUser) {
                $meta.append(copyButton($content));
            }

            $body.append($content, $meta);
            $message.append(avatar(isUser), $body);
            $messages.find('.ai-chat-bedrock-welcome-message').remove();
            $messages.append($message);
            scrollToBottom();
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

        function setPending(value) {
            pending = value;
            $textarea.prop('disabled', value);
            $submit.prop('disabled', value);
            $container.attr('aria-busy', value ? 'true' : 'false');
        }

        function finish() {
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

        function sendBuffered(message, requestHistory) {
            const $typing = typingIndicator();

            return $.ajax({
                url: params.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'ai_chat_bedrock_message',
                    nonce: params.nonce,
                    message: message,
                    history: JSON.stringify(requestHistory),
                    profile: profile
                }
            }).done(function (response) {
                $typing.remove();
                $messages.find('.ai-chat-bedrock-status').remove();
                if (response && response.success && response.data && typeof response.data.message === 'string') {
                    const bubble = addMessage(response.data.message, false);
                    // The message list is not a live region, so a buffered answer is
                    // announced here just as a streamed one is when it completes.
                    announce(bubble && bubble.content ? bubble.content.text() : response.data.message);
                    attachNote(bubble, fallbackNote(response.data.fallback_model));
                    attachSteps(bubble, response.data.steps, response.data.steps_truncated);
                    attachFeedback(bubble, response.data.entry);
                    showUsage(response.usage);
                } else {
                    showError(
                        response && response.data && response.data.message ? response.data.message : params.i18n.generic_error,
                        retryWith(message, requestHistory)
                    );
                }
            }).fail(function (xhr) {
                $typing.remove();
                $messages.find('.ai-chat-bedrock-status').remove();
                const response = xhr.responseJSON;
                showError(
                    response && response.data && response.data.message ? response.data.message : params.i18n.generic_error,
                    retryWith(message, requestHistory)
                );
            });
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
                state.bubble.content.html(formatMessage(state.text));
                scrollToBottom();
                return;
            }

            if ('tools' === event.name) {
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
                if (state.bubble) {
                    state.bubble.content.removeClass('is-streaming');
                    attachNote(state.bubble, fallbackNote(payload.fallback_model));
                    attachSteps(state.bubble, payload.steps, payload.steps_truncated);
                    attachFeedback(state.bubble, payload.entry);
                }
                showUsage(payload.usage);
                // The rendered text, not the raw reply: announcing markdown makes a screen
                // reader read "star star Blue star star".
                announce(state.bubble ? state.bubble.content.text() : state.text);
            }
        }

        function parseChunk(buffer, state) {
            let index = buffer.indexOf('\n\n');
            while (index !== -1) {
                const raw = buffer.slice(0, index);
                buffer = buffer.slice(index + 2);

                const event = { name: 'message', data: '' };
                raw.split('\n').forEach(function (line) {
                    if (line.indexOf('event:') === 0) {
                        event.name = line.slice(6).trim();
                    } else if (line.indexOf('data:') === 0) {
                        event.data += line.slice(5).trim();
                    }
                });
                handleEvent(event, state);
                index = buffer.indexOf('\n\n');
            }
            return buffer;
        }

        function sendStreaming(message, requestHistory) {
            const state = { text: '', bubble: null, error: '', done: false, fallback: false, typing: typingIndicator() };
            const body = new URLSearchParams();
            body.set('message', message);
            body.set('history', JSON.stringify(requestHistory));
            body.set('nonce', params.nonce);
            if (profile) {
                body.set('profile', profile);
            }

            return window.fetch(params.stream_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    Accept: 'text/event-stream',
                    'X-WP-Nonce': String(params.rest_nonce || '')
                },
                body: body.toString()
            }).then(function (response) {
                if (!response.ok || !response.body) {
                    return response.json().then(function (data) {
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
                            return null;
                        }
                        buffer += decoder.decode(chunk.value, { stream: true });
                        buffer = parseChunk(buffer, state);
                        return read();
                    });
                }
                return read();
            }).then(function () {
                if (state.typing) {
                    state.typing.remove();
                    state.typing = null;
                }
                $messages.find('.ai-chat-bedrock-status').remove();
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
                } else if (!state.done) {
                    showError(params.i18n.generic_error);
                }
                return null;
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

        function submitMessage(event) {
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

            const requestHistory = history.slice(-MAX_HISTORY);
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
            submitMessage();
        });

        $form.on('submit', submitMessage);
        $submit.on('click', submitMessage);
        $textarea.on('input', autoGrow);
        $textarea.on('keydown', function (event) {
            if ('Enter' === event.key && !event.shiftKey) {
                submitMessage(event);
            }
        });
        $clear.on('click', function () {
            if (!window.confirm(params.i18n.clear_confirm)) {
                return;
            }
            history = [];
            $messages.empty();
            $usage.text('');
            $textarea.val('');
            autoGrow();
            showSuggestions();
            const $welcome = $('<div>', { 'class': 'ai-chat-bedrock-welcome-message' });
            const $message = $('<div>', { 'class': 'ai-chat-bedrock-message ai-message' });
            $message.append(avatar(false), $('<div>', { 'class': 'ai-chat-bedrock-message-content' }).text(params.welcome_message));
            $messages.append($welcome.append($message));
            $textarea.trigger('focus');
        });
    });
})(jQuery);

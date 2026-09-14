(function ($) {
    'use strict';

    const settings = window.ai_chat_bedrock_admin || {};
    const i18n = settings.i18n || {};

    const AIChatBedrockAdmin = {
        showNotice: function (message, type) {
            const allowed = ['success', 'error', 'warning', 'info'];
            type = allowed.indexOf(type) === -1 ? 'info' : type;
            const $notice = $('<div>', { 'class': 'notice notice-' + type + ' is-dismissible' });
            $notice.append($('<p>').text(String(message || '')));
            const $button = $('<button>', { type: 'button', 'class': 'notice-dismiss' });
            $button.append($('<span>', { 'class': 'screen-reader-text', text: 'Dismiss this notice.' }));
            $notice.append($button);
            $('.wrap h1').first().after($notice);
            $button.on('click', function () { $notice.remove(); });
        },

        post: function (action, data) {
            return $.ajax({
                url: settings.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ action: action, nonce: settings.nonce }, data || {})
            });
        },

        errorMessage: function (xhr) {
            const response = xhr && xhr.responseJSON;
            if (response && response.data && response.data.message) {
                return response.data.message;
            }
            return i18n.ajax_error || 'The request could not be completed.';
        }
    };

    function bindModelRefresh() {
        const $button = $('#aicfab-refresh-models');
        if (!$button.length) {
            return;
        }
        const $status = $('#aicfab-refresh-models-status');

        $button.on('click', function () {
            $button.prop('disabled', true);
            $status.text(i18n.refreshing || 'Refreshing…');

            AIChatBedrockAdmin.post('ai_chat_bedrock_refresh_models').done(function (response) {
                if (response && response.success && response.data && response.data.message) {
                    $status.text(response.data.message);
                    AIChatBedrockAdmin.showNotice(response.data.message, 'success');
                } else {
                    $status.text('');
                    AIChatBedrockAdmin.showNotice(i18n.ajax_error || 'The request could not be completed.', 'error');
                }
            }).fail(function (xhr) {
                $status.text('');
                AIChatBedrockAdmin.showNotice(AIChatBedrockAdmin.errorMessage(xhr), 'error');
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    }

    function statusLabel(status) {
        if ('pass' === status) {
            return i18n.status_pass || 'Pass';
        }
        if ('fail' === status) {
            return i18n.status_fail || 'Action required';
        }
        return i18n.status_warn || 'Review';
    }

    function renderChecks(checks) {
        const $tbody = $('#aicfab-diagnostics-table tbody');
        if (!$tbody.length) {
            return;
        }
        $tbody.empty();

        checks.forEach(function (check) {
            const status = String(check.status || 'warn');
            const $row = $('<tr>', { 'data-check': String(check.id || '') });
            $row.append($('<td>').text(String(check.label || '')));
            $row.append($('<td>', { 'class': 'aicfab-status aicfab-status-' + status }).text(statusLabel(status)));
            $row.append($('<td>').text(String(check.message || '')));
            $tbody.append($row);
        });
    }

    function bindDiagnostics() {
        const $button = $('#aicfab-run-diagnostics');
        if (!$button.length) {
            return;
        }
        const $status = $('#aicfab-diagnostics-status');

        $button.on('click', function () {
            $button.prop('disabled', true);
            $status.text(i18n.testing || 'Testing…');

            AIChatBedrockAdmin.post('ai_chat_bedrock_run_diagnostics').done(function (response) {
                if (response && response.success && response.data && Array.isArray(response.data.checks)) {
                    renderChecks(response.data.checks);
                    $status.text('');
                } else {
                    $status.text('');
                    AIChatBedrockAdmin.showNotice(i18n.ajax_error || 'The request could not be completed.', 'error');
                }
            }).fail(function (xhr) {
                $status.text('');
                AIChatBedrockAdmin.showNotice(AIChatBedrockAdmin.errorMessage(xhr), 'error');
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    }

    $(function () {
        bindModelRefresh();
        bindDiagnostics();
    });

    window.AIChatBedrockAdmin = AIChatBedrockAdmin;
})(jQuery);

/**
 * Streaming content generation.
 *
 * The form still posts normally when this script does not run, so the generator
 * keeps working without JavaScript. When it does run, the draft is streamed into
 * the page and the post is only created once the model has finished.
 */
( function ( $ ) {
    'use strict';

    $( function () {
        var settings = window.ai_chat_bedrock_admin || {};
        var labels = settings.i18n || {};
        var $panel = $( '#aicfab-generator-stream' );

        if ( ! $panel.length || ! settings.generate_url || ! window.fetch || ! window.TextDecoder ) {
            return;
        }

        var $form = $panel.closest( '.wrap' ).find( 'form' ).filter( function () {
            return $( this ).find( '#aicfab_topic' ).length > 0;
        } ).first();

        if ( ! $form.length ) {
            return;
        }

        var $status = $panel.find( '.aicfab-generator-status' );
        var $output = $panel.find( '.aicfab-generator-output' );
        var $result = $panel.find( '.aicfab-generator-result' );
        var $submit = $form.find( 'input[type="submit"], button[type="submit"]' );

        var buffer = '';

        /**
         * The prompt asks the model to start with a "TITLE:" line, which the draft
         * parser consumes. Showing that line to the author would just be noise, so
         * it is stripped for display and surfaced in the status line instead.
         */
        function render() {
            var text = buffer;
            var match = /^TITLE:\s*(.*)\r?\n?/.exec( text );

            if ( match ) {
                text = text.slice( match[ 0 ].length );
                if ( match[ 1 ] && labels.generating_titled ) {
                    $status.text( labels.generating_titled.replace( '%s', match[ 1 ].trim() ) );
                }
            }

            $output.text( text.replace( /^\s+/, '' ) );
            $output.scrollTop( $output.prop( 'scrollHeight' ) );
        }

        function handleEvent( name, payload ) {
            if ( 'delta' === name && payload && typeof payload.text === 'string' ) {
                buffer += payload.text;
                render();
                return;
            }

            if ( 'error' === name ) {
                $status.text( ( payload && payload.message ) || labels.ajax_error || '' );
                return;
            }

            if ( 'done' === name && payload ) {
                var summary = ( labels.generate_done || '' )
                    .replace( '%1$s', String( payload.title || '' ) )
                    .replace( '%2$d', Number( payload.words || 0 ) );
                $status.text( summary );

                $result.empty();
                if ( payload.edit_url ) {
                    $result.append(
                        $( '<a>', { href: String( payload.edit_url ), 'class': 'button button-primary' } ).text( labels.generate_edit || '' )
                    );
                }
                if ( payload.fallback_model && labels.generate_fallback ) {
                    $result.append(
                        $( '<p>', { 'class': 'description' } ).text(
                            labels.generate_fallback.replace( '%s', String( payload.fallback_model ) )
                        )
                    );
                }
            }
        }

        function parse( buffer ) {
            var index = buffer.indexOf( '\n\n' );
            while ( index !== -1 ) {
                var raw = buffer.slice( 0, index );
                buffer = buffer.slice( index + 2 );

                var name = '';
                var data = '';
                raw.split( '\n' ).forEach( function ( line ) {
                    if ( 0 === line.indexOf( 'event: ' ) ) {
                        name = line.slice( 7 ).trim();
                    } else if ( 0 === line.indexOf( 'data: ' ) ) {
                        data += line.slice( 6 );
                    }
                } );

                if ( name && data ) {
                    try {
                        handleEvent( name, JSON.parse( data ) );
                    } catch ( error ) {
                        // Ignore a frame we cannot parse and keep reading.
                    }
                }

                index = buffer.indexOf( '\n\n' );
            }
            return buffer;
        }

        $form.on( 'submit', function ( event ) {
            var topic = String( $form.find( '#aicfab_topic' ).val() || '' ).trim();
            if ( ! topic ) {
                return;
            }

            event.preventDefault();

            $panel.removeAttr( 'hidden' );
            $status.text( labels.generating || '' );
            buffer = '';
            $output.empty();
            $result.empty();
            $submit.prop( 'disabled', true );

            fetch( settings.generate_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': settings.rest_nonce || ''
                },
                body: JSON.stringify( {
                    topic: topic,
                    notes: String( $form.find( '#aicfab_notes' ).val() || '' ),
                    tone: String( $form.find( '#aicfab_tone' ).val() || '' ),
                    length: String( $form.find( '#aicfab_length' ).val() || '' ),
                    language: String( $form.find( '#aicfab_language' ).val() || '' )
                } )
            } ).then( function ( response ) {
                if ( ! response.ok || ! response.body ) {
                    return response.json().then( function ( body ) {
                        throw new Error( ( body && body.message ) || labels.ajax_error || '' );
                    }, function () {
                        throw new Error( labels.ajax_error || '' );
                    } );
                }

                var reader = response.body.getReader();
                var decoder = new TextDecoder();
                var buffer = '';

                function read() {
                    return reader.read().then( function ( chunk ) {
                        if ( chunk.done ) {
                            return;
                        }
                        buffer += decoder.decode( chunk.value, { stream: true } );
                        buffer = parse( buffer );
                        return read();
                    } );
                }

                return read();
            } ).catch( function ( error ) {
                $status.text( ( error && error.message ) || labels.ajax_error || '' );
            } ).then( function () {
                $submit.prop( 'disabled', false );
            } );
        } );
    } );
} )( jQuery );

/**
 * Batch indexing for semantic search.
 *
 * Embedding every post in one request would time out on a large site, so the
 * browser drives the loop and each request handles a small batch.
 */
( function ( $ ) {
    'use strict';

    $( function () {
        var settings = window.ai_chat_bedrock_admin || {};
        var labels = settings.i18n || {};
        var $button = $( '#aicfab-index-embeddings' );
        var $progress = $( '#aicfab-index-progress' );

        if ( ! $button.length ) {
            return;
        }

        var totals = { indexed: 0, skipped: 0, failed: 0 };

        function report( remaining ) {
            if ( labels.index_progress ) {
                $progress.text(
                    labels.index_progress
                        .replace( '%1$d', totals.indexed )
                        .replace( '%2$d', Math.max( 0, Number( remaining || 0 ) ) )
                );
            }
        }

        function finish() {
            $button.prop( 'disabled', false ).text( labels.index_button || '' );
            if ( labels.index_done ) {
                $progress.text(
                    labels.index_done
                        .replace( '%1$d', totals.indexed )
                        .replace( '%2$d', totals.skipped )
                        .replace( '%3$d', totals.failed )
                );
            }
        }

        function runBatch() {
            $.post( settings.ajax_url, {
                action: 'ai_chat_bedrock_index_embeddings',
                nonce: settings.nonce
            } ).done( function ( response ) {
                if ( ! response || ! response.success || ! response.data ) {
                    $progress.text( ( response && response.data && response.data.message ) || labels.ajax_error || '' );
                    finish();
                    return;
                }

                var data = response.data;
                totals.indexed += Number( data.indexed || 0 );
                totals.skipped += Number( data.skipped || 0 );
                totals.failed += Number( data.failed || 0 );
                report( data.remaining );

                var progressed = Number( data.indexed || 0 ) + Number( data.skipped || 0 ) > 0;
                if ( Number( data.remaining || 0 ) > 0 && progressed ) {
                    runBatch();
                    return;
                }
                finish();
            } ).fail( function () {
                $progress.text( labels.ajax_error || '' );
                finish();
            } );
        }

        $button.on( 'click', function () {
            totals = { indexed: 0, skipped: 0, failed: 0 };
            $button.prop( 'disabled', true ).text( labels.indexing || '' );
            $progress.text( labels.indexing || '' );
            runBatch();
        } );
    } );
} )( jQuery );

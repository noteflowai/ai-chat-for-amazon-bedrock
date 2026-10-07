(function ($) {
    'use strict';

    const config = window.ai_chat_bedrock_admin;
    const notice = function (message, type) {
        window.AIChatBedrockAdmin.showNotice(message, type);
    };

    function request(data, method) {
        return $.ajax({ url: config.ajax_url, method: method || 'POST', dataType: 'json', data: data });
    }

    // Every row has the same buttons, so each names its server for screen readers. The
    // visible part is a span of its own, so a busy label can replace it and leave the name.
    function rowButton(className, label, server) {
        return $('<button>', { type: 'button', 'class': 'button ' + className })
            .attr('data-server', server)
            .append($('<span>', { 'class': 'aicfab-button-label', text: label }), $('<span>', { 'class': 'screen-reader-text', text: ' ' + server }));
    }

    function busy($button, label) {
        $button.prop('disabled', !!label).find('.aicfab-button-label').text(label || $button.data('label'));
    }

    function renderServers(servers) {
        const $body = $('#ai-chat-bedrock-mcp-servers-table tbody').empty();
        const names = servers ? Object.keys(servers) : [];
        if (!names.length) {
            $body.append($('<tr>', { 'class': 'no-items' }).append($('<td>', { colspan: 5, text: [config.i18n.no_servers, config.i18n.no_servers_hint].filter(Boolean).join(' ') })));
            return;
        }
        names.forEach(function (name) {
            const server = servers[name];
            const $row = $('<tr>').attr('data-server-name', name);
            $row.append($('<td>').text(name));
            $row.append($('<td>').text(server.url));
            $row.append($('<td>').append($('<span>', {
                'class': 'ai-chat-bedrock-server-status ' + (server.available ? 'status-available' : 'status-unavailable'),
                // Say why it is unavailable. Part of this text comes from the remote server,
                // so it is set as text and never as markup.
                text: server.available
                    ? config.i18n.available
                    : (server.reason ? config.i18n.unavailable + ' — ' + String(server.reason) : config.i18n.unavailable)
            })));
            const $tools = $('<td>');
            $tools.append(rowButton('ai-chat-bedrock-view-tools', config.i18n.view_tools, name));
            $tools.append(' ', rowButton('ai-chat-bedrock-refresh-tools', config.i18n.refresh, name));
            $row.append($tools);
            $row.append($('<td>').append(rowButton('ai-chat-bedrock-remove-server', config.i18n.remove, name)));
            $body.append($row);
        });
    }

    function loadServers() {
        request({ action: 'ai_chat_bedrock_get_mcp_servers', nonce: config.mcp_nonce }, 'GET')
            .done(function (response) {
                if (response.success) {
                    renderServers(response.data.servers);
                } else {
                    notice(response.data.message, 'error');
                }
            }).fail(function () { notice(config.i18n.ajax_error, 'error'); });
    }

    function renderTools(tools) {
        const $list = $('#ai-chat-bedrock-mcp-tools-list').empty();
        if (!tools || !tools.length) {
            $list.append($('<p>').text(config.i18n.no_tools));
            return;
        }
        tools.forEach(function (tool) {
            const $item = $('<div>', { 'class': 'ai-chat-bedrock-mcp-tool-item' });
            $item.append($('<h4>').text(tool.name || ''));
            $item.append($('<p>', { 'class': 'description' }).text(tool.description || ''));
            const $section = $('<div>', { 'class': 'ai-chat-bedrock-mcp-tool-parameters' });
            $section.append($('<h5>').text(config.i18n.parameters));
            const $items = $('<ul>');
            const properties = tool.parameters && tool.parameters.properties ? tool.parameters.properties : {};
            Object.keys(properties).forEach(function (name) {
                $items.append($('<li>').append($('<strong>').text(name), document.createTextNode(': ' + (properties[name].description || ''))));
            });
            if (!$items.children().length) {
                $items.append($('<li>').text(config.i18n.no_parameters));
            }
            $list.append($item.append($section.append($items)));
        });
    }

    // Saved with the form's button; the servers list shows as soon as tools are switched on.
    $('#ai_chat_bedrock_enable_mcp').on('change', function () {
        $('#ai-chat-bedrock-mcp-servers-section').toggleClass('hidden', !$(this).is(':checked'));
    });

    function authFields() {
        const type = String($('#ai_chat_bedrock_mcp_auth_type').val() || 'none');
        const fields = { auth_type: type };
        if ('bearer' === type) {
            fields.auth_token = String($('#ai_chat_bedrock_mcp_auth_token').val() || '').trim();
        }
        if ('sigv4' === type) {
            fields.auth_service = String($('#ai_chat_bedrock_mcp_auth_service').val() || 'bedrock-agentcore').trim();
            fields.auth_region = String($('#ai_chat_bedrock_mcp_auth_region').val() || '').trim();
        }
        return fields;
    }

    $('#ai_chat_bedrock_mcp_auth_type').on('change', function () {
        const type = String($(this).val() || 'none');
        $('.aicfab-auth-bearer').toggle('bearer' === type);
        $('.aicfab-auth-sigv4').toggle('sigv4' === type);
    }).trigger('change');

    $('#ai_chat_bedrock_add_mcp_server').on('click', function () {
        const $button = $(this);
        const name = String($('#ai_chat_bedrock_mcp_server_name').val() || '').trim();
        const url = String($('#ai_chat_bedrock_mcp_server_url').val() || '').trim();
        if (!name || !url) {
            notice(config.i18n.missing_fields, 'error');
            return;
        }
        $button.prop('disabled', true).text(config.i18n.adding);
        request($.extend({ action: 'ai_chat_bedrock_register_mcp_server', nonce: config.mcp_nonce, server_name: name, server_url: url }, authFields()))
            .done(function (response) {
                notice(response.success ? response.data.message : response.data.message, response.success ? 'success' : 'error');
                if (response.success) {
                    $('#ai_chat_bedrock_mcp_server_name, #ai_chat_bedrock_mcp_server_url, #ai_chat_bedrock_mcp_auth_token').val('');
                    loadServers();
                }
            }).fail(function (xhr) {
                notice(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : config.i18n.ajax_error, 'error');
            }).always(function () { $button.prop('disabled', false).text(config.i18n.add_server); });
    });

    $('#ai-chat-bedrock-mcp-servers-table').on('click', '.ai-chat-bedrock-remove-server', function () {
        if (!window.confirm(config.i18n.confirm_remove_server)) { return; }
        const $button = $(this).data('label', config.i18n.remove);
        busy($button, config.i18n.removing);
        // A removal that fails leaves the row, so its button has to come back: it used to
        // stay disabled on "Removing…" until the page was reloaded.
        request({ action: 'ai_chat_bedrock_unregister_mcp_server', nonce: config.mcp_nonce, server_name: $button.data('server') })
            .done(function (response) { notice(response.data.message, response.success ? 'success' : 'error'); if (response.success) { loadServers(); } })
            .fail(function () { notice(config.i18n.ajax_error, 'error'); })
            .always(function () { busy($button, ''); });
    }).on('click', '.ai-chat-bedrock-refresh-tools', function () {
        const $button = $(this).data('label', config.i18n.refresh);
        busy($button, config.i18n.refreshing);
        request({ action: 'ai_chat_bedrock_discover_mcp_tools', nonce: config.mcp_nonce, server_name: $button.data('server') })
            .done(function (response) { notice(response.data.message, response.success ? 'success' : 'error'); if (response.success) { loadServers(); } })
            .fail(function () { notice(config.i18n.ajax_error, 'error'); })
            .always(function () { busy($button, ''); });
    }).on('click', '.ai-chat-bedrock-view-tools', function () {
        const name = $(this).data('server');
        openModal(this);
        $('#ai-chat-bedrock-mcp-tools-list').empty().append($('<p>').text(config.i18n.loading_tools));
        request({ action: 'ai_chat_bedrock_get_mcp_servers', nonce: config.mcp_nonce }, 'GET')
            .done(function (response) { renderTools(response.success && response.data.servers[name] ? response.data.servers[name].tools : []); })
            .fail(function () { $('#ai-chat-bedrock-mcp-tools-list').empty().append($('<p>').text(config.i18n.ajax_error)); });
    });

    // The tools dialog: focus moves into it, Escape or a click outside closes it, and focus
    // returns to the button that opened it.
    let opener = null;
    function openModal(button) {
        opener = button;
        $('#ai-chat-bedrock-mcp-tools-modal').show().find('.ai-chat-bedrock-modal-close').trigger('focus');
    }
    function closeModal() {
        const $modal = $('#ai-chat-bedrock-mcp-tools-modal');
        if (!$modal.is(':visible')) { return; }
        $modal.hide();
        if (opener && document.body.contains(opener)) { opener.focus(); }
        opener = null;
    }
    $('.ai-chat-bedrock-modal-close').on('click', closeModal);
    $('#ai-chat-bedrock-mcp-tools-modal').on('click', function (event) {
        if (event.target === this) { closeModal(); }
    });
    $(document).on('keydown', function (event) {
        if ('Escape' === event.key) { closeModal(); }
    });
    loadServers();
})(jQuery);


/**
 * Local section navigation for the MCP settings page.
 *
 * Sections start hidden through the hidden attribute only when this script runs,
 * so the page stays fully readable if JavaScript fails.
 */
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        var nav = document.querySelector( '.aicfab-mcp-nav' );
        var sections = Array.prototype.slice.call( document.querySelectorAll( '.aicfab-mcp-section' ) );

        if ( ! nav || sections.length < 2 ) {
            return;
        }

        var tabs = Array.prototype.slice.call( nav.querySelectorAll( '[data-aicfab-section]' ) );

        function show( id ) {
            sections.forEach( function ( section ) {
                if ( section.id === id ) {
                    section.removeAttribute( 'hidden' );
                } else {
                    section.setAttribute( 'hidden', 'hidden' );
                }
            } );
            tabs.forEach( function ( tab ) {
                var active = tab.getAttribute( 'data-aicfab-section' ) === id;
                tab.classList.toggle( 'nav-tab-active', active );
                if ( active ) {
                    tab.setAttribute( 'aria-current', 'true' );
                } else {
                    tab.removeAttribute( 'aria-current' );
                }
            } );
            try {
                window.sessionStorage.setItem( 'aicfabMcpSection', id );
            } catch ( error ) {
                // Session storage is unavailable; selection simply is not remembered.
            }
        }

        tabs.forEach( function ( tab ) {
            tab.addEventListener( 'click', function ( event ) {
                event.preventDefault();
                var id = tab.getAttribute( 'data-aicfab-section' );
                show( id );
                // Keep the address in step, so the section can be bookmarked or shared.
                if ( window.history && window.history.replaceState ) {
                    window.history.replaceState( null, '', '#' + id );
                }
            } );
        } );

        function fromHash() {
            var hash = String( window.location.hash || '' ).replace( '#', '' );
            var target = hash ? document.getElementById( hash ) : null;
            return target && target.classList.contains( 'aicfab-mcp-section' ) ? hash : '';
        }

        // A link to another section of this page changes only the hash, which reloads nothing.
        window.addEventListener( 'hashchange', function () {
            var id = fromHash();
            if ( id ) {
                show( id );
            }
        } );

        var initial = sections[ 0 ].id;
        var hash = fromHash();

        if ( hash ) {
            initial = hash;
        } else {
            try {
                var stored = window.sessionStorage.getItem( 'aicfabMcpSection' );
                if ( stored && document.getElementById( stored ) ) {
                    initial = stored;
                }
            } catch ( error ) {
                // Ignore storage failures.
            }
        }

        show( initial );
    } );
} )();

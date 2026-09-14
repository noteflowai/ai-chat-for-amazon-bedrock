( function ( plugins, editPost, editor, element, components, data, apiFetch, i18n ) {
	'use strict';

	// wp.editPost.PluginSidebar was deprecated in WordPress 6.6 in favour of wp.editor.
	// Prefer the current one and fall back, since this plugin supports 6.4 and 6.5 as well.
	var host = ( editor && editor.PluginSidebar ) ? editor : editPost;

	if ( ! plugins || ! host || ! element || ! components ) {
		return;
	}

	var settings = window.aiChatBedrockEditor || {};
	var labels = settings.i18n || {};
	var actions = settings.actions || {};
	var el = element.createElement;
	var useState = element.useState;

	var PluginSidebar = host.PluginSidebar;
	var PluginSidebarMoreMenuItem = host.PluginSidebarMoreMenuItem;
	var PanelBody = components.PanelBody;
	var TextareaControl = components.TextareaControl;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var Spinner = components.Spinner;
	var Notice = components.Notice;

	function selectedBlockText() {
		try {
			var store = data.select( 'core/block-editor' );
			var block = store && store.getSelectedBlock ? store.getSelectedBlock() : null;
			if ( ! block || ! block.attributes ) {
				return '';
			}
			var attributes = block.attributes;
			var candidate = attributes.content || attributes.text || attributes.value || '';
			if ( candidate && typeof candidate === 'object' && typeof candidate.toString === 'function' ) {
				candidate = candidate.toString();
			}
			return String( candidate || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		} catch ( error ) {
			return '';
		}
	}

	function insertParagraph( text ) {
		try {
			var blocks = window.wp && window.wp.blocks;
			var dispatch = data.dispatch( 'core/block-editor' );
			if ( ! blocks || ! dispatch ) {
				return false;
			}
			dispatch.insertBlocks( blocks.createBlock( 'core/paragraph', { content: text } ) );
			return true;
		} catch ( error ) {
			return false;
		}
	}

	function currentPostId() {
		try {
			var store = data.select( 'core/editor' );
			return store && store.getCurrentPostId ? Number( store.getCurrentPostId() || 0 ) : 0;
		} catch ( error ) {
			return 0;
		}
	}

	function applyExcerpt( value ) {
		try {
			var dispatch = data.dispatch( 'core/editor' );
			if ( ! dispatch || ! dispatch.editPost ) {
				return false;
			}
			dispatch.editPost( { excerpt: value } );
			return true;
		} catch ( error ) {
			return false;
		}
	}

	function AssistantPanel() {
		var textState = useState( '' );
		var text = textState[ 0 ];
		var setText = textState[ 1 ];

		var actionState = useState( 'improve' );
		var action = actionState[ 0 ];
		var setAction = actionState[ 1 ];

		var languageState = useState( '' );
		var language = languageState[ 0 ];
		var setLanguage = languageState[ 1 ];

		var resultState = useState( '' );
		var result = resultState[ 0 ];
		var setResult = resultState[ 1 ];

		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		var usageState = useState( '' );
		var usage = usageState[ 0 ];
		var setUsage = usageState[ 1 ];

		var noticeState = useState( '' );
		var notice = noticeState[ 0 ];
		var setNotice = noticeState[ 1 ];

		function runExcerpt() {
			var postId = currentPostId();
			if ( ! postId ) {
				setError( labels.error || '' );
				return;
			}
			setBusy( true );
			setError( '' );
			setNotice( '' );

			apiFetch( {
				path: settings.excerptEndpoint,
				method: 'POST',
				data: { post: postId, save: false }
			} ).then( function ( response ) {
				var value = String( ( response && response.excerpt ) || '' );
				if ( value && applyExcerpt( value ) ) {
					setNotice( labels.excerptDone || '' );
				} else {
					setError( labels.error || '' );
				}
			} ).catch( function ( failure ) {
				setError( ( failure && failure.message ) || labels.error || '' );
			} ).finally( function () {
				setBusy( false );
			} );
		}

		var options = Object.keys( actions ).map( function ( key ) {
			return { label: actions[ key ], value: key };
		} );

		function run() {
			var payload = String( text || '' ).trim();
			if ( ! payload ) {
				setError( labels.empty || '' );
				return;
			}
			setBusy( true );
			setError( '' );
			setResult( '' );
			setUsage( '' );

			apiFetch( {
				path: settings.endpoint,
				method: 'POST',
				data: { action_type: action, text: payload, language: language }
			} ).then( function ( response ) {
				setResult( String( ( response && response.text ) || '' ) );
				var tokens = ( response && response.usage ) || {};
				if ( labels.tokens && ( tokens.input_tokens || tokens.output_tokens ) ) {
					setUsage(
						labels.tokens
							.replace( '%1$d', Number( tokens.input_tokens || 0 ) )
							.replace( '%2$d', Number( tokens.output_tokens || 0 ) )
					);
				}
			} ).catch( function ( failure ) {
				setError( ( failure && failure.message ) || labels.error || '' );
			} ).finally( function () {
				setBusy( false );
			} );
		}

		var children = [
			el( 'p', { key: 'intro', style: { color: '#50575e' } }, labels.intro ),
			el( SelectControl, {
				key: 'action',
				label: labels.result ? labels.title : 'Action',
				value: action,
				options: options,
				onChange: setAction,
				__nextHasNoMarginBottom: true
			} )
		];

		if ( 'translate' === action ) {
			children.push(
				el( TextControl, {
					key: 'language',
					label: labels.language,
					value: language,
					onChange: setLanguage,
					__nextHasNoMarginBottom: true
				} )
			);
		}

		children.push(
			el( TextareaControl, {
				key: 'text',
				label: labels.placeholder,
				value: text,
				rows: 6,
				onChange: setText,
				__nextHasNoMarginBottom: true
			} ),
			el(
				'div',
				{ key: 'buttons', style: { display: 'flex', gap: '8px', marginBottom: '12px' } },
				el(
					Button,
					{
						variant: 'secondary',
						onClick: function () {
							var selected = selectedBlockText();
							if ( selected ) {
								setText( selected );
								setError( '' );
							} else {
								setError( labels.empty || '' );
							}
						}
					},
					labels.useSelected
				),
				el( Button, { variant: 'primary', disabled: busy, onClick: run }, busy ? labels.working : labels.title )
			)
		);

		if ( settings.excerptEndpoint ) {
			children.push(
				el(
					Button,
					{ key: 'excerpt', variant: 'secondary', disabled: busy, onClick: runExcerpt },
					labels.excerpt
				)
			);
		}

		if ( notice ) {
			children.push( el( Notice, { key: 'notice', status: 'success', isDismissible: false }, notice ) );
		}

		if ( busy ) {
			children.push( el( Spinner, { key: 'spinner' } ) );
		}

		if ( error ) {
			children.push( el( Notice, { key: 'error', status: 'error', isDismissible: false }, error ) );
		}

		if ( result ) {
			children.push(
				el( TextareaControl, {
					key: 'result',
					label: labels.result,
					value: result,
					rows: 8,
					onChange: setResult,
					__nextHasNoMarginBottom: true
				} ),
				el(
					Button,
					{
						key: 'insert',
						variant: 'secondary',
						onClick: function () {
							if ( ! insertParagraph( result ) ) {
								setError( labels.error || '' );
							}
						}
					},
					labels.insert
				)
			);
			if ( usage ) {
				children.push( el( 'p', { key: 'usage', style: { marginTop: '10px', color: '#646970' } }, usage ) );
			}
		}

		return el( PanelBody, { title: labels.title, initialOpen: true }, children );
	}

	plugins.registerPlugin( 'ai-chat-bedrock-editor-assistant', {
		render: function () {
			return el(
				element.Fragment,
				null,
				PluginSidebarMoreMenuItem
					? el( PluginSidebarMoreMenuItem, { target: 'ai-chat-bedrock-assistant' }, labels.title )
					: null,
				el(
					PluginSidebar,
					{ name: 'ai-chat-bedrock-assistant', title: labels.title, icon: 'format-chat' },
					el( AssistantPanel, null )
				)
			);
		}
	} );
} )(
	window.wp.plugins,
	window.wp.editPost,
	window.wp.editor,
	window.wp.element,
	window.wp.components,
	window.wp.data,
	window.wp.apiFetch,
	window.wp.i18n
);

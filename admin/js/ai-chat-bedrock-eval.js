/**
 * Answer checks screen.
 *
 * Renders the case table, saves it, proposes cases from recorded questions and runs the set.
 * Every value shown comes from the server; nothing is scored here.
 */
( function () {
	'use strict';

	var config = window.AICFABEval || {};
	var rows = document.getElementById( 'aicfab-eval-rows' );
	var status = document.getElementById( 'aicfab-eval-status' );
	if ( ! rows || ! config.ajaxUrl ) {
		return;
	}

	var FIELDS = [
		{ key: 'question', type: 'textarea', label: config.i18n.question },
		{ key: 'expect', type: 'select', label: config.i18n.expect, options: [ 'grounded', 'unsupported', 'tool' ] },
		{ key: 'must_include', type: 'text', label: config.i18n.mustInclude },
		{ key: 'must_not_include', type: 'text', label: config.i18n.mustNotInclude },
		{ key: 'cite', type: 'text', label: config.i18n.cite },
		{ key: 'min_relevance', type: 'number', label: config.i18n.minRelevance, step: '0.01', min: '0', max: '1' },
		{ key: 'max_output_tokens', type: 'number', label: config.i18n.tokenCeiling, step: '1', min: '0' }
	];

	function say( message ) {
		status.textContent = message || '';
	}

	function cell( row, field, value ) {
		var td = document.createElement( 'td' );
		var control;
		if ( 'textarea' === field.type ) {
			control = document.createElement( 'textarea' );
			control.rows = 2;
			control.value = value || '';
		} else if ( 'select' === field.type ) {
			control = document.createElement( 'select' );
			field.options.forEach( function ( option ) {
				var element = document.createElement( 'option' );
				element.value = option;
				element.textContent = option;
				if ( option === value ) {
					element.selected = true;
				}
				control.appendChild( element );
			} );
		} else {
			control = document.createElement( 'input' );
			control.type = field.type;
			if ( field.step ) {
				control.step = field.step;
			}
			if ( undefined !== field.min ) {
				control.min = field.min;
			}
			if ( undefined !== field.max ) {
				control.max = field.max;
			}
			control.value = ( value === 0 || value ) ? value : '';
		}
		// Every control carries its own accessible name, since the column heading alone does
		// not name a control inside a repeated row.
		control.setAttribute( 'aria-label', field.label );
		control.dataset.key = field.key;
		control.className = 'aicfab-eval-field';
		td.appendChild( control );
		row.appendChild( td );
	}

	function addRow( item ) {
		var data = item || {};
		var row = document.createElement( 'tr' );
		FIELDS.forEach( function ( field ) {
			var value = data[ field.key ];
			if ( Array.isArray( value ) ) {
				value = value.join( ', ' );
			}
			cell( row, field, value );
		} );
		var actions = document.createElement( 'td' );
		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link delete';
		remove.textContent = config.i18n.remove;
		remove.addEventListener( 'click', function () {
			row.remove();
			say( config.i18n.removed );
		} );
		actions.appendChild( remove );
		row.appendChild( actions );
		rows.appendChild( row );
		return row;
	}

	function collect() {
		var out = [];
		Array.prototype.forEach.call( rows.querySelectorAll( 'tr' ), function ( row ) {
			var item = {};
			Array.prototype.forEach.call( row.querySelectorAll( '.aicfab-eval-field' ), function ( control ) {
				var key = control.dataset.key;
				var value = control.value.trim();
				if ( 'must_include' === key || 'must_not_include' === key ) {
					item[ key ] = value ? value.split( ',' ).map( function ( part ) {
						return part.trim();
					} ).filter( Boolean ) : [];
				} else if ( 'min_relevance' === key || 'max_output_tokens' === key ) {
					item[ key ] = value ? Number( value ) : 0;
				} else {
					item[ key ] = value;
				}
			} );
			if ( item.question ) {
				out.push( item );
			}
		} );
		return out;
	}

	function post( action, body ) {
		var data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce', config.nonce );
		Object.keys( body || {} ).forEach( function ( key ) {
			data.append( key, body[ key ] );
		} );
		return window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function renderChecks( checks ) {
		var list = document.createElement( 'ul' );
		list.className = 'aicfab-eval-checks';
		checks.forEach( function ( check ) {
			if ( null === check.passed ) {
				return;
			}
			var item = document.createElement( 'li' );
			item.textContent = ( check.passed ? '✓ ' : '✗ ' ) + check.category + ' — ' + check.detail;
			item.className = check.passed ? 'aicfab-eval-ok' : 'aicfab-eval-bad';
			list.appendChild( item );
		} );
		return list;
	}

	function renderReport( payload ) {
		var report = payload.report;
		var results = document.getElementById( 'aicfab-eval-results' );
		var summary = document.getElementById( 'aicfab-eval-summary' );
		var body = document.getElementById( 'aicfab-eval-result-rows' );
		results.hidden = false;
		body.textContent = '';

		var lines = [];
		lines.push( report.passed + ' / ' + report.cases + ' ' + config.i18n.casesPassed );
		Object.keys( report.categories ).forEach( function ( name ) {
			var detail = report.categories[ name ];
			if ( detail.checked ) {
				lines.push( name + ' ' + detail.passed + '/' + detail.checked );
			}
		} );
		if ( report.unchecked && report.unchecked.length ) {
			lines.push( config.i18n.neverChecked + ' ' + report.unchecked.join( ', ' ) );
		}
		if ( payload.comparison ) {
			Object.keys( payload.comparison.categories ).forEach( function ( name ) {
				var row = payload.comparison.categories[ name ];
				if ( ! row.comparable ) {
					lines.push( name + ': ' + config.i18n.notComparable );
				} else if ( row.change ) {
					lines.push( name + ': ' + ( row.change > 0 ? '+' : '' ) + row.change + ' ' + config.i18n.sinceLastRun );
				}
			} );
		}
		summary.textContent = '';
		lines.forEach( function ( line ) {
			var p = document.createElement( 'p' );
			p.textContent = line;
			summary.appendChild( p );
		} );
		var method = document.createElement( 'p' );
		method.className = 'description';
		method.textContent = report.method;
		summary.appendChild( method );

		report.results.forEach( function ( result ) {
			var row = document.createElement( 'tr' );
			var name = document.createElement( 'td' );
			name.textContent = result.question;
			var verdict = document.createElement( 'td' );
			verdict.textContent = result.passed ? config.i18n.pass : config.i18n.fail;
			verdict.className = result.passed ? 'aicfab-eval-ok' : 'aicfab-eval-bad';
			var checks = document.createElement( 'td' );
			checks.appendChild( renderChecks( result.checks ) );
			row.appendChild( name );
			row.appendChild( verdict );
			row.appendChild( checks );
			body.appendChild( row );
		} );
	}

	document.getElementById( 'aicfab-eval-add' ).addEventListener( 'click', function () {
		var row = addRow( { expect: 'grounded' } );
		var first = row.querySelector( 'textarea' );
		if ( first ) {
			first.focus();
		}
	} );

	document.getElementById( 'aicfab-eval-save' ).addEventListener( 'click', function () {
		say( config.i18n.saving );
		post( 'aicfab_eval_save', { cases: JSON.stringify( collect() ) } ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				say( ( response && response.data && response.data.message ) || config.i18n.failed );
				return;
			}
			rows.textContent = '';
			response.data.cases.forEach( addRow );
			say( response.data.dropped
				? config.i18n.savedWithDrops.replace( '%d', response.data.dropped )
				: config.i18n.saved );
		} ).catch( function () {
			say( config.i18n.failed );
		} );
	} );

	document.getElementById( 'aicfab-eval-propose' ).addEventListener( 'click', function () {
		say( config.i18n.proposing );
		post( 'aicfab_eval_propose', {} ).then( function ( response ) {
			var box = document.getElementById( 'aicfab-eval-proposals' );
			var list = document.getElementById( 'aicfab-eval-proposal-list' );
			list.textContent = '';
			box.hidden = false;
			if ( ! response || ! response.success ) {
				say( ( response && response.data && response.data.message ) || config.i18n.failed );
				return;
			}
			( response.data.skipped || [] ).forEach( function ( reason ) {
				var item = document.createElement( 'li' );
				item.textContent = reason;
				list.appendChild( item );
			} );
			( response.data.cases || [] ).forEach( function ( proposal ) {
				var item = document.createElement( 'li' );
				item.textContent = proposal.question + ' — ' + proposal.origin + ' ' + proposal.todo + ' ';
				var add = document.createElement( 'button' );
				add.type = 'button';
				add.className = 'button button-small';
				add.textContent = config.i18n.addThis;
				add.addEventListener( 'click', function () {
					addRow( { question: proposal.question, expect: proposal.expect } );
					add.disabled = true;
					say( config.i18n.addedUnsaved );
				} );
				item.appendChild( add );
				list.appendChild( item );
			} );
			say( config.i18n.proposed.replace( '%d', ( response.data.cases || [] ).length ) );
		} ).catch( function () {
			say( config.i18n.failed );
		} );
	} );

	document.getElementById( 'aicfab-eval-run' ).addEventListener( 'click', function () {
		say( config.i18n.running );
		post( 'aicfab_eval_run', {} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				say( ( response && response.data && response.data.message ) || config.i18n.failed );
				return;
			}
			renderReport( response.data );
			say( config.i18n.ran );
		} ).catch( function () {
			say( config.i18n.failed );
		} );
	} );

	var initial = document.getElementById( 'aicfab-eval-initial' );
	if ( initial ) {
		try {
			JSON.parse( initial.textContent ).forEach( addRow );
		} catch ( error ) {
			addRow( { expect: 'grounded' } );
		}
	}
	if ( ! rows.querySelector( 'tr' ) ) {
		addRow( { expect: 'grounded' } );
	}
}() );

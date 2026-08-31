/**
 * Duplicate prevention on the ingredient editor.
 *
 * As the INCI name is typed, this asks the server whether the normalised key is
 * already taken. An exact collision blocks the save and names the existing entry
 * with a link to edit it; an alias match or a close resemblance is shown as a
 * warning that still allows the save. The server enforces the block again on
 * save, so this is an early, friendly warning rather than the only guard.
 */
( function () {
	'use strict';

	var cfg = window.ILD_Check || {};
	var messages = cfg.messages || {};

	var title = document.getElementById( 'title' );
	var form = document.getElementById( 'post' );
	if ( ! title || ! form ) {
		return;
	}

	// Whether an exact collision is currently in force (blocks the save).
	var blocked = false;
	var timer = null;

	// A place to show the messages, right under the title field.
	var box = document.createElement( 'div' );
	box.id = 'ild-inci-check';
	box.setAttribute( 'aria-live', 'polite' );
	box.style.margin = '8px 0 0';
	var titlediv = document.getElementById( 'titlediv' );
	if ( titlediv && titlediv.parentNode ) {
		titlediv.parentNode.insertBefore( box, titlediv.nextSibling );
	} else {
		title.parentNode.insertBefore( box, title.nextSibling );
	}

	/**
	 * Fill %s / %1$s / %2$s placeholders in a localised template.
	 *
	 * @param {string} template The message template.
	 * @param {Array}  args     The replacements, in order.
	 * @return {string}
	 */
	function fmt( template, args ) {
		var i = 0;
		return String( template || '' )
			.replace( /%(\d+)\$s/g, function ( m, n ) { return args[ parseInt( n, 10 ) - 1 ] || ''; } )
			.replace( /%s/g, function () { return args[ i++ ] || ''; } );
	}

	/**
	 * Draw one message row, with an optional "edit it" link to an entry.
	 *
	 * @param {string} tone  'error' or 'warning'.
	 * @param {string} text  The message text (entry name already substituted).
	 * @param {string} edit  The edit URL, or ''.
	 */
	function addRow( tone, text, edit ) {
		var row = document.createElement( 'div' );
		row.className = 'notice notice-' + ( 'error' === tone ? 'error' : 'warning' );
		row.style.margin = '6px 0';
		row.style.padding = '6px 12px';

		var p = document.createElement( 'p' );
		p.style.margin = '0.4em 0';
		p.textContent = text;

		if ( edit ) {
			p.appendChild( document.createTextNode( ' ' ) );
			var a = document.createElement( 'a' );
			a.href = edit;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = messages.edit || 'Edit it';
			p.appendChild( a );
		}

		row.appendChild( p );
		box.appendChild( row );
	}

	/**
	 * Enable or disable the save/publish buttons.
	 *
	 * @param {boolean} disable Whether to disable them.
	 */
	function setSaveDisabled( disable ) {
		[ 'publish', 'save-post' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.disabled = !! disable;
				el.classList.toggle( 'disabled', !! disable );
			}
		} );
	}

	/**
	 * Render the server's answer.
	 *
	 * @param {Object} data { collision, alias, near }.
	 */
	function render( data ) {
		box.innerHTML = '';
		blocked = false;

		if ( data && data.collision ) {
			blocked = true;
			addRow( 'error', fmt( messages.collision, [ data.collision.name ] ), data.collision.edit );
		}
		if ( data && data.alias ) {
			addRow( 'warning', fmt( messages.alias, [ data.alias.name, data.alias.alias ] ), data.alias.edit );
		}
		if ( data && data.near ) {
			addRow( 'warning', fmt( messages.near, [ data.near.name ] ), data.near.edit );
		}

		setSaveDisabled( blocked );
	}

	/**
	 * Ask the server about the current title.
	 */
	function check() {
		var name = title.value;
		if ( ! name || ! name.trim() ) {
			render( null );
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'name', name );
		body.append( 'post_id', String( cfg.postId || 0 ) );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( payload ) {
				if ( payload && payload.success ) {
					render( payload.data );
				}
			} )
			.catch( function () { /* A failed check must never block editing. */ } );
	}

	// Check shortly after typing stops, and once on load for an existing entry.
	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( check, 400 );
	}

	title.addEventListener( 'input', schedule );
	title.addEventListener( 'change', check );
	if ( title.value && title.value.trim() ) {
		check();
	}

	// Belt and braces: stop the submit while a collision stands. The server would
	// force a draft anyway, but this keeps the block honest in the browser too.
	form.addEventListener( 'submit', function ( event ) {
		if ( blocked ) {
			event.preventDefault();
			box.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	} );
} )();

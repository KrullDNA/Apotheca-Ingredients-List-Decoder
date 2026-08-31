/**
 * Front-end behaviour for the Ingredient List Decoder.
 *
 * Submits the form over AJAX with no page reload: it shows the loading region,
 * posts the form (nonce and all) to admin-ajax, and drops the returned HTML
 * fragment into the results region. It also keeps a live character count, moves
 * focus to the result so keyboard and screen-reader users are taken to it, and
 * clears everything when "start again" is pressed.
 *
 * No wording lives here — every visible string is rendered server-side from the
 * one PHP phrases file. The only text this file composes is the character count,
 * whose template comes through from PHP with %used% and %max% tokens.
 */
( function () {
	'use strict';

	var settings = window.ILD_Frontend || {};

	/**
	 * Find the tool wrapper a given element belongs to.
	 *
	 * @param {Element} el The starting element.
	 * @return {Element|null} The .ild-tool wrapper, or null.
	 */
	function toolOf( el ) {
		return el && el.closest ? el.closest( '.ild-tool' ) : null;
	}

	/**
	 * Update the live character count under a textarea.
	 *
	 * @param {HTMLTextAreaElement} textarea The list textarea.
	 */
	function updateCount( textarea ) {
		var tool = toolOf( textarea );
		if ( ! tool ) {
			return;
		}

		var counter = tool.querySelector( '[data-ild-charcount]' );
		if ( ! counter ) {
			return;
		}

		var max = parseInt( textarea.getAttribute( 'maxlength' ), 10 ) || 0;
		var used = textarea.value.length;

		var template = settings.charCount || '%used% / %max%';
		counter.textContent = template
			.replace( '%used%', String( used ) )
			.replace( '%max%', String( max ) );
	}

	/**
	 * Reset a tool to its starting state: clear the field, the count, the result
	 * and any messages, then return focus to the textarea.
	 *
	 * @param {Element} tool The .ild-tool wrapper.
	 */
	function restart( tool ) {
		if ( ! tool ) {
			return;
		}

		var textarea = tool.querySelector( '.ild-textarea' );
		var product = tool.querySelector( '.ild-product' );
		var results = tool.querySelector( '.ild-results' );
		var netError = tool.querySelector( '.ild-error--network' );

		if ( textarea ) {
			textarea.value = '';
			updateCount( textarea );
		}
		if ( product ) {
			product.value = '';
		}
		if ( results ) {
			results.innerHTML = '';
		}
		if ( netError ) {
			netError.hidden = true;
		}
		if ( textarea ) {
			textarea.focus();
		}
	}

	// Keep the character count current as the visitor types or pastes.
	document.addEventListener( 'input', function ( event ) {
		var target = event.target;
		if ( target && target.classList && target.classList.contains( 'ild-textarea' ) ) {
			updateCount( target );
		}
	} );

	// Prime the count for every tool already on the page.
	document.addEventListener( 'DOMContentLoaded', function () {
		var boxes = document.querySelectorAll( '.ild-tool .ild-textarea' );
		Array.prototype.forEach.call( boxes, updateCount );
	} );

	// "Start again" clears the tool. The button only exists inside a result.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var button = target.closest( '[data-ild-restart]' );
		if ( button ) {
			event.preventDefault();
			restart( toolOf( button ) );
		}
	} );

	// Submit the form over AJAX. One document-level listener covers every tool,
	// now or added later.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'ild-form' ) ) {
			return;
		}

		event.preventDefault();

		var tool = toolOf( form );
		if ( ! tool ) {
			return;
		}

		var results = tool.querySelector( '.ild-results' );
		var loading = tool.querySelector( '.ild-loading' );
		var netError = tool.querySelector( '.ild-error--network' );

		// Reset the regions: clear the last result, hide the network error, show
		// the loading message, and mark the tool busy for assistive tech.
		if ( netError ) {
			netError.hidden = true;
		}
		if ( results ) {
			results.innerHTML = '';
		}
		if ( loading ) {
			loading.hidden = false;
		}
		tool.setAttribute( 'aria-busy', 'true' );

		// Send the whole form (list, product name, nonce, honeypot) plus the action.
		var data = new FormData( form );
		data.append( 'action', 'ild_analyse' );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( loading ) {
					loading.hidden = true;
				}
				tool.removeAttribute( 'aria-busy' );

				// The server always renders a fragment (result, empty or error).
				if ( payload && payload.data && typeof payload.data.html === 'string' ) {
					if ( results ) {
						results.innerHTML = payload.data.html;

						// Move focus to the result so keyboard users land on it and
						// screen readers announce it (the region is aria-live too).
						var focusable = results.querySelector( '.ild-result, .ild-state' );
						if ( focusable ) {
							focusable.setAttribute( 'tabindex', '-1' );
							focusable.focus();
						}
					}
				} else if ( netError ) {
					netError.hidden = false;
				}
			} )
			.catch( function () {
				// The request itself failed (offline, server error). Show the
				// last-resort message rather than leaving the spinner up.
				if ( loading ) {
					loading.hidden = true;
				}
				tool.removeAttribute( 'aria-busy' );
				if ( netError ) {
					netError.hidden = false;
				}
			} );
	} );
} )();

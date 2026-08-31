/**
 * Front-end behaviour for the Ingredient List Decoder.
 *
 * Submits the form over AJAX with no page reload: it shows the loading region,
 * posts the form (nonce and all) to admin-ajax, and drops the returned HTML
 * fragment into the results region. No wording lives here — every visible string
 * is rendered server-side from the one PHP phrases file.
 */
( function () {
	'use strict';

	// Work for any number of tools on the page, now or added later, by listening
	// once on the document and finding the tool from the submitted form.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList || ! form.classList.contains( 'ild-form' ) ) {
			return;
		}

		event.preventDefault();

		var tool = form.closest( '.ild-tool' );
		if ( ! tool ) {
			return;
		}

		var results = tool.querySelector( '.ild-results' );
		var loading = tool.querySelector( '.ild-loading' );
		var netError = tool.querySelector( '.ild-error--network' );

		// Reset the regions: clear the last result, hide the network error, show
		// the loading message.
		if ( netError ) {
			netError.hidden = true;
		}
		if ( results ) {
			results.innerHTML = '';
		}
		if ( loading ) {
			loading.hidden = false;
		}

		// Send the whole form (list, product name, nonce, honeypot) plus the action.
		var data = new FormData( form );
		data.append( 'action', 'ild_analyse' );

		fetch( ILD_Frontend.ajaxUrl, {
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

				// The server always renders a fragment (result, empty or error).
				if ( payload && payload.data && typeof payload.data.html === 'string' ) {
					if ( results ) {
						results.innerHTML = payload.data.html;
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
				if ( netError ) {
					netError.hidden = false;
				}
			} );
	} );
} )();

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

	/* -------------------------------------------------------------------
	 * Photo route: upload/camera → convert & resize → transcribe → verify.
	 * The image is prepared in the browser and read on the server; nothing
	 * is analysed until the visitor confirms the transcription.
	 * ----------------------------------------------------------------- */

	// Remember each tool's current thumbnail URL so it can be revoked.
	var thumbUrls = new WeakMap();

	/**
	 * Look up a photo-handling message by key, with a sensible fallback.
	 *
	 * @param {string} key The message id.
	 * @return {string}
	 */
	function photoMsg( key ) {
		var m = settings.photoMessages || {};
		return m[ key ] || m.network || '';
	}

	/**
	 * Draw an image source onto a canvas, scaled down, and export it as JPEG.
	 * Works for JPEG/PNG everywhere, and for HEIC on browsers that can decode
	 * it (Safari, i.e. most iPhones). Resolves null if the source can't decode.
	 *
	 * @param {Blob} src     The image blob or file.
	 * @param {number} maxDim The longest-edge cap in pixels.
	 * @param {number} quality JPEG quality 0–1.
	 * @return {Promise<Blob|null>}
	 */
	function drawToCanvas( src, maxDim, quality ) {
		return new Promise( function ( resolve ) {
			var url = URL.createObjectURL( src );
			var img = new Image();
			img.onload = function () {
				var w = img.naturalWidth;
				var h = img.naturalHeight;
				if ( ! w || ! h ) {
					URL.revokeObjectURL( url );
					resolve( null );
					return;
				}
				var scale = Math.min( 1, maxDim / Math.max( w, h ) );
				var canvas = document.createElement( 'canvas' );
				canvas.width = Math.round( w * scale );
				canvas.height = Math.round( h * scale );
				canvas.getContext( '2d' ).drawImage( img, 0, 0, canvas.width, canvas.height );
				URL.revokeObjectURL( url );
				canvas.toBlob( function ( blob ) { resolve( blob ); }, 'image/jpeg', quality );
			};
			img.onerror = function () {
				URL.revokeObjectURL( url );
				resolve( null );
			};
			img.src = url;
		} );
	}

	// heic2any is loaded on demand, only when a HEIC can't be decoded natively.
	var heicPromise = null;
	function loadHeic2any() {
		if ( window.heic2any ) {
			return Promise.resolve();
		}
		if ( heicPromise ) {
			return heicPromise;
		}
		if ( ! settings.heicUrl ) {
			return Promise.reject( new Error( 'no-heic-url' ) );
		}
		heicPromise = new Promise( function ( resolve, reject ) {
			var s = document.createElement( 'script' );
			s.src = settings.heicUrl;
			s.onload = resolve;
			s.onerror = reject;
			document.head.appendChild( s );
		} );
		return heicPromise;
	}

	/**
	 * Convert a HEIC file to a JPEG blob using heic2any, loaded on demand.
	 *
	 * @param {File} file The HEIC file.
	 * @return {Promise<Blob|null>}
	 */
	function convertHeic( file ) {
		return loadHeic2any()
			.then( function () {
				if ( ! window.heic2any ) {
					return null;
				}
				return window.heic2any( { blob: file, toType: 'image/jpeg', quality: 0.9 } );
			} )
			.then( function ( out ) {
				return Array.isArray( out ) ? out[ 0 ] : out;
			} )
			.catch( function () {
				return null;
			} );
	}

	/**
	 * Prepare a chosen file for upload: decode (converting HEIC where the
	 * browser can't), resize to the configured cap, and compress under the
	 * size limit. Resolves a JPEG blob, or null if it couldn't be prepared.
	 *
	 * @param {File} file The chosen file.
	 * @return {Promise<Blob|null>}
	 */
	function prepareImage( file ) {
		var dim = settings.maxImageDim || 1800;
		var maxBytes = settings.maxImageBytes || 0;
		var isHeic = /heic|heif/i.test( file.type ) || /\.(heic|heif)$/i.test( file.name );

		var decodable = file;

		return drawToCanvas( decodable, dim, 0.85 )
			.then( function ( blob ) {
				if ( blob || ! isHeic ) {
					return blob;
				}
				// Native decode failed on a HEIC: convert, then draw again.
				return convertHeic( file ).then( function ( converted ) {
					if ( ! converted ) {
						return null;
					}
					decodable = converted;
					return drawToCanvas( decodable, dim, 0.85 );
				} );
			} )
			.then( function ( blob ) {
				if ( ! blob ) {
					return null;
				}
				// Shrink further, by quality then dimension, to fit the cap.
				function fit( current, quality, dimension ) {
					if ( ! maxBytes || current.size <= maxBytes || quality < 0.4 ) {
						return Promise.resolve( current );
					}
					return drawToCanvas( decodable, dimension, quality ).then( function ( next ) {
						if ( ! next ) {
							return current;
						}
						return fit( next, quality - 0.15, Math.round( dimension * 0.85 ) );
					} );
				}
				return fit( blob, 0.7, dim );
			} );
	}

	/* --- Photo UI state helpers --------------------------------------- */
	function photoParts( tool ) {
		return {
			field:    tool.querySelector( '.ild-field--photo' ),
			zone:     tool.querySelector( '[data-ild-dropzone]' ),
			progress: tool.querySelector( '[data-ild-photo-progress]' ),
			status:   tool.querySelector( '[data-ild-photo-status]' ),
			error:    tool.querySelector( '[data-ild-photo-error]' ),
			verify:   tool.querySelector( '[data-ild-verify]' ),
			text:     tool.querySelector( '[data-ild-verify-text]' ),
			thumb:    tool.querySelector( '[data-ild-verify-thumb]' )
		};
	}

	function setUploading( tool, on ) {
		var p = photoParts( tool );
		if ( p.zone ) {
			p.zone.classList.toggle( 'is-uploading', !! on );
			if ( on ) {
				p.zone.classList.remove( 'is-error' );
			}
		}
		if ( p.progress ) {
			p.progress.hidden = ! on;
		}
		if ( p.status ) {
			p.status.hidden = ! on;
			p.status.textContent = on ? ( settings.photoReading || '' ) : '';
		}
	}

	function showPhotoError( tool, message ) {
		var p = photoParts( tool );
		setUploading( tool, false );
		if ( p.zone ) {
			p.zone.classList.add( 'is-error' );
		}
		if ( p.error ) {
			p.error.textContent = message;
			p.error.hidden = false;
		}
	}

	function clearPhotoError( tool ) {
		var p = photoParts( tool );
		if ( p.zone ) {
			p.zone.classList.remove( 'is-error' );
		}
		if ( p.error ) {
			p.error.hidden = true;
			p.error.textContent = '';
		}
	}

	function showVerify( tool, text, thumbUrl ) {
		var p = photoParts( tool );
		if ( p.text ) {
			p.text.value = text;
		}
		if ( p.thumb && thumbUrl ) {
			var old = thumbUrls.get( tool );
			if ( old ) {
				URL.revokeObjectURL( old );
			}
			thumbUrls.set( tool, thumbUrl );
			p.thumb.src = thumbUrl;
		}
		if ( p.field ) {
			p.field.hidden = true;
		}
		if ( p.verify ) {
			p.verify.hidden = false;
			if ( p.text ) {
				p.text.focus();
			}
		}
	}

	function hideVerify( tool, keepField ) {
		var p = photoParts( tool );
		if ( p.verify ) {
			p.verify.hidden = true;
		}
		if ( p.field && ! keepField ) {
			p.field.hidden = false;
		}
		var old = thumbUrls.get( tool );
		if ( old ) {
			URL.revokeObjectURL( old );
			thumbUrls.delete( tool );
		}
	}

	/**
	 * Send a prepared image to the transcription endpoint.
	 *
	 * @param {Element} tool     The tool wrapper.
	 * @param {Blob}    blob     The prepared JPEG.
	 * @param {string}  thumbUrl An object URL for the thumbnail.
	 */
	function uploadPhoto( tool, blob, thumbUrl ) {
		var form = tool.querySelector( '.ild-form' );
		var nonceField = form ? form.querySelector( 'input[name="ild_transcribe_nonce"]' ) : null;

		var data = new FormData();
		data.append( 'action', settings.transcribeAction );
		if ( nonceField ) {
			data.append( 'ild_transcribe_nonce', nonceField.value );
		}
		data.append( 'ild_image', blob, 'ingredients.jpg' );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( payload ) {
				setUploading( tool, false );
				if ( payload && payload.success && payload.data && typeof payload.data.text === 'string' ) {
					showVerify( tool, payload.data.text, thumbUrl );
				} else {
					URL.revokeObjectURL( thumbUrl );
					var msg = ( payload && payload.data && payload.data.message ) ? payload.data.message : photoMsg( 'read_failed' );
					showPhotoError( tool, msg );
				}
			} )
			.catch( function () {
				URL.revokeObjectURL( thumbUrl );
				showPhotoError( tool, photoMsg( 'network' ) );
			} );
	}

	/**
	 * Validate and process a chosen file end to end.
	 *
	 * @param {Element} tool The tool wrapper.
	 * @param {File}    file The chosen file.
	 */
	function processFile( tool, file ) {
		if ( ! file ) {
			return;
		}
		clearPhotoError( tool );

		if ( settings.photoEnabled === false ) {
			showPhotoError( tool, photoMsg( 'not_ready' ) );
			return;
		}

		var okType = /image\/(jpeg|png|heic|heif)/i.test( file.type ) || /\.(jpe?g|png|heic|heif)$/i.test( file.name );
		if ( ! okType ) {
			showPhotoError( tool, photoMsg( 'wrong_type' ) );
			return;
		}
		if ( settings.maxImageBytes && file.size > settings.maxImageBytes ) {
			showPhotoError( tool, photoMsg( 'too_large' ) );
			return;
		}

		setUploading( tool, true );
		prepareImage( file )
			.then( function ( blob ) {
				if ( ! blob ) {
					showPhotoError( tool, photoMsg( 'convert_failed' ) );
					return;
				}
				uploadPhoto( tool, blob, URL.createObjectURL( blob ) );
			} )
			.catch( function () {
				showPhotoError( tool, photoMsg( 'convert_failed' ) );
			} );
	}

	// A file chosen through the picker or the camera.
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input || ! input.matches || ! input.matches( '[data-ild-photo]' ) ) {
			return;
		}
		var tool = toolOf( input );
		if ( tool && input.files && input.files.length ) {
			processFile( tool, input.files[ 0 ] );
		}
		// Reset so choosing the same file again still fires a change.
		input.value = '';
	} );

	// Drag and drop onto the dropzone.
	document.addEventListener( 'dragover', function ( event ) {
		var zone = event.target && event.target.closest ? event.target.closest( '[data-ild-dropzone]' ) : null;
		if ( zone ) {
			event.preventDefault();
			zone.classList.add( 'is-dragover' );
		}
	} );
	document.addEventListener( 'dragleave', function ( event ) {
		var zone = event.target && event.target.closest ? event.target.closest( '[data-ild-dropzone]' ) : null;
		if ( zone && ! zone.contains( event.relatedTarget ) ) {
			zone.classList.remove( 'is-dragover' );
		}
	} );
	document.addEventListener( 'drop', function ( event ) {
		var zone = event.target && event.target.closest ? event.target.closest( '[data-ild-dropzone]' ) : null;
		if ( ! zone ) {
			return;
		}
		event.preventDefault();
		zone.classList.remove( 'is-dragover' );
		var tool = toolOf( zone );
		if ( tool && event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length ) {
			processFile( tool, event.dataTransfer.files[ 0 ] );
		}
	} );

	// Confirm the transcription, or retake.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var confirm = target.closest( '[data-ild-verify-confirm]' );
		if ( confirm ) {
			event.preventDefault();
			var tool = toolOf( confirm );
			if ( ! tool ) {
				return;
			}
			var parts = photoParts( tool );
			var textarea = tool.querySelector( '.ild-textarea' );
			if ( textarea && parts.text ) {
				textarea.value = parts.text.value;
				updateCount( textarea );
			}
			hideVerify( tool, true );
			var form = tool.querySelector( '.ild-form' );
			if ( form ) {
				if ( form.requestSubmit ) {
					form.requestSubmit();
				} else {
					form.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
				}
			}
			return;
		}

		var retake = target.closest( '[data-ild-verify-retake]' );
		if ( retake ) {
			event.preventDefault();
			var t = toolOf( retake );
			if ( t ) {
				var pp = photoParts( t );
				if ( pp.text ) {
					pp.text.value = '';
				}
				clearPhotoError( t );
				hideVerify( t );
			}
		}
	} );

	/* -------------------------------------------------------------------
	 * Email gate: enable the button once consent is ticked, submit the
	 * gate, and reveal the breakdown in place.
	 * ----------------------------------------------------------------- */

	// Enable the submit button only while the consent box is ticked, and show
	// the reason it is disabled rather than failing silently.
	document.addEventListener( 'change', function ( event ) {
		var box = event.target;
		if ( ! box || ! box.matches || ! box.matches( '[data-ild-gate-consent]' ) ) {
			return;
		}
		var form = box.closest( '.ild-gate__form' );
		if ( ! form ) {
			return;
		}
		var button = form.querySelector( '[data-ild-gate-submit]' );
		var note = form.querySelector( '[data-ild-gate-note]' );
		if ( button ) {
			button.disabled = ! box.checked;
		}
		if ( note ) {
			note.hidden = box.checked;
		}
	} );

	function showGateError( form, message ) {
		var el = form.querySelector( '[data-ild-gate-error]' );
		if ( el ) {
			el.textContent = message;
			el.hidden = false;
		}
	}

	function submitGate( form ) {
		var gate = form.closest( '[data-ild-gate]' );
		var button = form.querySelector( '[data-ild-gate-submit]' );
		var source = form.querySelector( '[data-ild-gate-source]' );
		var errorEl = form.querySelector( '[data-ild-gate-error]' );

		if ( source ) {
			source.value = window.location.href;
		}
		if ( errorEl ) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}
		if ( button ) {
			button.disabled = true;
		}

		var data = new FormData( form );
		data.append( 'action', settings.gateAction );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( payload ) {
				if ( payload && payload.success && payload.data && typeof payload.data.html === 'string' && gate && gate.parentNode ) {
					// Replace the gate with the now-unlocked breakdown, and move
					// focus to it so it is announced.
					var holder = document.createElement( 'div' );
					holder.innerHTML = payload.data.html;
					var node = holder.firstElementChild;
					if ( node ) {
						gate.parentNode.replaceChild( node, gate );
						node.setAttribute( 'tabindex', '-1' );
						node.focus();
					}
					return;
				}
				var messages = settings.gateMessages || {};
				var message = ( payload && payload.data && payload.data.message ) ? payload.data.message : ( messages.network || '' );
				showGateError( form, message );
				if ( button ) {
					button.disabled = false;
				}
			} )
			.catch( function () {
				var messages = settings.gateMessages || {};
				showGateError( form, messages.network || '' );
				if ( button ) {
					button.disabled = false;
				}
			} );
	}

	// Submit the form over AJAX. One document-level listener covers every tool,
	// now or added later.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.classList ) {
			return;
		}

		// The email gate has its own submission.
		if ( form.classList.contains( 'ild-gate__form' ) ) {
			event.preventDefault();
			submitGate( form );
			return;
		}

		if ( ! form.classList.contains( 'ild-form' ) ) {
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

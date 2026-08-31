/**
 * Admin tools for the result email settings: the media picker, the colour
 * pickers, a live preview that reflects unsaved changes, and a test send.
 */
( function ( $ ) {
	'use strict';

	var settings = window.ILD_EmailAdmin || {};

	/**
	 * Collect the current email settings from the form, keyed without the
	 * ild_settings[] wrapper, so they can be posted as live overrides.
	 *
	 * @return {Object}
	 */
	function collectSettings() {
		var out = {};
		$( '[name^="ild_settings["]' ).each( function () {
			var name = $( this ).attr( 'name' ) || '';
			var match = name.match( /^ild_settings\[(.+?)\]$/ );
			if ( ! match ) {
				return;
			}
			var key = match[ 1 ];
			if ( 'checkbox' === this.type && ! this.checked ) {
				return;
			}
			out[ key ] = $( this ).val();
		} );
		return out;
	}

	var previewTimer = null;

	/**
	 * Fetch a fresh preview and drop it into the iframe.
	 */
	function refreshPreview() {
		var frame = document.getElementById( 'ild-email-preview' );
		if ( ! frame ) {
			return;
		}
		$.post( settings.ajaxUrl, {
			action: settings.previewAction,
			nonce: settings.nonce,
			settings: collectSettings()
		} ).done( function ( response ) {
			if ( response && response.success && response.data && typeof response.data.html === 'string' ) {
				frame.srcdoc = response.data.html;
			}
		} );
	}

	/**
	 * Debounced preview refresh, so typing doesn't hammer the endpoint.
	 */
	function queuePreview() {
		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( refreshPreview, 400 );
	}

	$( function () {
		// Colour pickers, refreshing the preview as they change.
		if ( $.fn.wpColorPicker ) {
			$( '.ild-color-field' ).wpColorPicker( {
				change: queuePreview,
				clear: queuePreview
			} );
		}

		// The media picker for the logo.
		$( document ).on( 'click', '.ild-media-button', function ( event ) {
			event.preventDefault();
			var button = $( this );
			var input = button.siblings( '.ild-media-field' );
			var preview = button.siblings( '.ild-media-preview' );

			var frame = wp.media( {
				title: settings.chooseLogo || 'Choose logo',
				multiple: false,
				library: { type: 'image' }
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				input.val( attachment.url );
				preview.html( '<img src="' + attachment.url + '" alt="" style="max-width:160px;height:auto;display:block;margin-top:8px;" />' );
				queuePreview();
			} );

			frame.open();
		} );

		// Refresh on any other field change (text, number, textarea, url).
		$( document ).on( 'input change', '[name^="ild_settings["]', function () {
			if ( $( this ).hasClass( 'ild-color-field' ) ) {
				return; // Handled by the colour picker.
			}
			queuePreview();
		} );

		// The Refresh button.
		$( '#ild-email-refresh' ).on( 'click', function ( event ) {
			event.preventDefault();
			refreshPreview();
		} );

		// The test send.
		$( '#ild-email-test-send' ).on( 'click', function ( event ) {
			event.preventDefault();
			var to = $( '#ild-email-test-to' ).val();
			var status = $( '#ild-email-test-status' );

			if ( ! to || to.indexOf( '@' ) === -1 ) {
				status.text( settings.testInvalid || '' );
				return;
			}
			status.text( '…' );

			$.post( settings.ajaxUrl, {
				action: settings.testAction,
				nonce: settings.nonce,
				to: to,
				settings: collectSettings()
			} ).done( function ( response ) {
				status.text( ( response && response.success ) ? ( settings.testSent || '' ) : ( settings.testFailed || '' ) );
			} ).fail( function () {
				status.text( settings.testFailed || '' );
			} );
		} );

		// First preview on load.
		refreshPreview();
	} );
} )( jQuery );

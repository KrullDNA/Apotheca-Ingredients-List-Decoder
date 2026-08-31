/**
 * The Campaign Monitor test-connection button.
 *
 * Posts the API key and list ID currently in the form to the test endpoint and
 * shows the result, so credentials can be checked before saving.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.ILDCM || {};

	$( function () {
		$( '#ildcm-test' ).on( 'click', function ( event ) {
			event.preventDefault();

			var status = $( '#ildcm-test-status' );
			status.css( 'color', '' ).text( cfg.testing || '' );

			$.post( cfg.ajaxUrl, {
				action: cfg.action,
				nonce: cfg.nonce,
				api_key: $( '[name="ild_settings[cm_api_key]"]' ).val(),
				list_id: $( '[name="ild_settings[cm_list_id]"]' ).val()
			} ).done( function ( response ) {
				if ( response && response.success ) {
					status.css( 'color', '#1f7a3d' ).text( cfg.ok || '' );
				} else {
					var message = ( response && response.data && response.data.message ) ? response.data.message : ( cfg.failed || '' );
					status.css( 'color', '#b3261e' ).text( message );
				}
			} ).fail( function () {
				status.css( 'color', '#b3261e' ).text( cfg.failed || '' );
			} );
		} );
	} );
} )( jQuery );

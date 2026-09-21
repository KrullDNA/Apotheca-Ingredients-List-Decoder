/**
 * Accordion animation for the Product Ingredients widget's expanders.
 *
 * A native <details> opens and closes instantly. This animates its height so an
 * ingredient expands and collapses smoothly, like an accordion, without changing
 * the markup or breaking the native open/close behaviour (or keyboard use). It
 * respects the visitor's reduced-motion preference, and re-initialises inside the
 * Elementor editor so the effect is visible there too.
 */
( function () {
	'use strict';

	var DURATION = 220;

	// Whether the visitor prefers reduced motion (checked live, so a change applies).
	function reducedMotion() {
		return window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	/**
	 * Wire one <details> element for animated open/close.
	 *
	 * @param {HTMLDetailsElement} el The details element.
	 */
	function Accordion( el ) {
		this.el      = el;
		this.summary = el.querySelector( 'summary' );
		if ( ! this.summary ) {
			return;
		}
		this.animation  = null;
		this.isClosing  = false;
		this.isExpanding = false;

		var self = this;
		this.summary.addEventListener( 'click', function ( e ) {
			self.onClick( e );
		} );
	}

	Accordion.prototype.onClick = function ( e ) {
		// With reduced motion, let the browser toggle it instantly.
		if ( reducedMotion() ) {
			return;
		}

		e.preventDefault();
		this.el.style.overflow = 'hidden';

		if ( this.isClosing || ! this.el.open ) {
			this.open();
		} else if ( this.isExpanding || this.el.open ) {
			this.shrink();
		}
	};

	Accordion.prototype.open = function () {
		this.el.style.height = this.el.offsetHeight + 'px';
		this.el.open = true;
		var self = this;
		window.requestAnimationFrame( function () {
			self.expand();
		} );
	};

	Accordion.prototype.expand = function () {
		this.isExpanding = true;
		var start = this.el.offsetHeight + 'px';
		var end   = this.el.scrollHeight + 'px';

		if ( this.animation ) {
			this.animation.cancel();
		}
		this.animate( start, end, true );
	};

	Accordion.prototype.shrink = function () {
		this.isClosing = true;
		var start = this.el.offsetHeight + 'px';
		var end   = this.summary.offsetHeight + 'px';

		if ( this.animation ) {
			this.animation.cancel();
		}
		this.animate( start, end, false );
	};

	Accordion.prototype.animate = function ( start, end, open ) {
		var self = this;

		// If the browser lacks the Web Animations API, just toggle.
		if ( typeof this.el.animate !== 'function' ) {
			this.finish( open );
			return;
		}

		this.animation = this.el.animate(
			{ height: [ start, end ] },
			{ duration: DURATION, easing: 'ease' }
		);

		this.animation.onfinish = function () {
			self.finish( open );
		};
		this.animation.oncancel = function () {
			self.isClosing = false;
			self.isExpanding = false;
		};
	};

	Accordion.prototype.finish = function ( open ) {
		this.el.open = open;
		this.animation = null;
		this.isClosing = false;
		this.isExpanding = false;
		this.el.style.height = '';
		this.el.style.overflow = '';
	};

	/**
	 * Initialise every not-yet-wired expander within a root element.
	 *
	 * @param {ParentNode} root Where to look (defaults to the document).
	 */
	function init( root ) {
		var scope = root || document;
		var boxes = scope.querySelectorAll( 'details.ild-product-ing__box:not([data-ild-acc])' );
		for ( var i = 0; i < boxes.length; i++ ) {
			boxes[ i ].setAttribute( 'data-ild-acc', '1' );
			new Accordion( boxes[ i ] );
		}
	}

	if ( 'loading' !== document.readyState ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	}

	// Re-initialise when Elementor (re)renders the widget, so it works in the editor.
	document.addEventListener( 'DOMContentLoaded', function () {
		if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
			window.elementorFrontend.hooks.addAction(
				'frontend/element_ready/ild_product_ingredients.default',
				function ( $scope ) {
					init( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
				}
			);
		}
	} );
}() );

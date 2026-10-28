/**
 * "Consultar pago en Recurrente" in Woo's order actions: while the lookup runs,
 * show the pending row and ignore any further submit of the order form, so a
 * second click cannot start a second lookup or replace the first one's result.
 */
( function () {
	'use strict';

	var ACTION = 'recurrente_reconcile_payment';

	function init() {
		var select = document.querySelector( 'select[name="wc_order_action"]' );
		var pending = document.querySelector( '.recurrente-recovery-pending' );
		if ( ! select || ! select.form || ! pending ) {
			return;
		}
		var form = select.form;
		var box = document.getElementById( 'woocommerce-order-actions' );
		var submitted = false;

		form.addEventListener( 'submit', function ( event ) {
			if ( submitted ) {
				event.preventDefault();
				return;
			}
			if ( select.value !== ACTION ) {
				return;
			}
			submitted = true;
			pending.style.display = '';
			if ( box ) {
				box.setAttribute( 'aria-busy', 'true' );
			}
			// Disable after the browser has collected the form data, so the submitter is still sent.
			window.setTimeout( function () {
				var controls = box ? box.querySelectorAll( 'button, select' ) : [];
				for ( var i = 0; i < controls.length; i++ ) {
					controls[ i ].disabled = true;
				}
			}, 0 );
		} );

		// Coming back through the browser history restores the page as it was left.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( ! event.persisted || ! submitted ) {
				return;
			}
			submitted = false;
			pending.style.display = 'none';
			if ( box ) {
				box.removeAttribute( 'aria-busy' );
				var controls = box.querySelectorAll( 'button, select' );
				for ( var i = 0; i < controls.length; i++ ) {
					controls[ i ].disabled = false;
				}
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

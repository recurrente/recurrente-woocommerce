/**
 * Registra Recurrente en el checkout por bloques de WooCommerce.
 *
 * El gateway es de redirección: este componente solo pinta el título,
 * descripción e ícono del método. El "Realizar pedido" llega al
 * process_payment() de PHP igual que en el checkout clásico.
 */
( function () {
	'use strict';

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var el = window.wp.element.createElement;

	var settings = getSetting( 'recurrente_data', {} );
	var label = decodeEntities( settings.title || 'Recurrente' );

	var Content = function () {
		return el( 'div', null, decodeEntities( settings.description || '' ) );
	};

	// Título con el logo de Recurrente a la derecha (como los demás gateways).
	var Label = function () {
		return el(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center', width: '100%' } },
			el( 'span', null, label ),
			settings.icon
				? el( 'img', {
						src: settings.icon,
						alt: label,
						style: { height: '24px', width: 'auto', marginLeft: 'auto' },
				  } )
				: null
		);
	};

	registerPaymentMethod( {
		name: 'recurrente',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();

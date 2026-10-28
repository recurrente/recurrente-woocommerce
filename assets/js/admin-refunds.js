/* global jQuery, recurrenteRefunds */
(function ($) {
	'use strict';
	const config = recurrenteRefunds;
	const storageKey = 'recurrente-refund-' + config.orderId;
	function uuid() {
		if (window.crypto.randomUUID) { return window.crypto.randomUUID(); }
		const bytes = window.crypto.getRandomValues(new Uint8Array(16));
		bytes[6] = (bytes[6] & 15) | 64;
		bytes[8] = (bytes[8] & 63) | 128;
		const hex = Array.from(bytes, function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
		return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
	}

	$.ajaxPrefilter(function (options, original, xhr) {
		const data = new URLSearchParams(options.data);
		if (data.get('action') !== 'woocommerce_refund_line_items' || data.get('order_id') !== config.orderId || data.get('api_refund') !== 'true') {
			return;
		}
		try {
			let operation = JSON.parse(window.sessionStorage.getItem(storageKey));
			if (operation && config.terminalKeys.indexOf('woo_refund_' + operation.id) !== -1) {
				operation = null;
			}
			if (!operation) {
				operation = { id: uuid() };
			}
			window.sessionStorage.setItem(storageKey, JSON.stringify(operation));
			xhr.setRequestHeader('X-Recurrente-Refund-Operation', operation.id);
			xhr.done(function (response) {
				if (response && response.success === true) {
					window.sessionStorage.removeItem(storageKey);
				}
			});
		} catch (error) {
			xhr.abort();
			window.alert(config.blocked);
		}
	});
})(jQuery);

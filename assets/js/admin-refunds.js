/* global jQuery, recurrenteRefunds */
(function ($) {
	'use strict';
	const config = recurrenteRefunds;
	const storageKey = 'recurrente-refund-' + config.orderId;
	const doneKey = 'recurrente-refund-done-' + config.orderId;
	const reloadEvent = 'ajaxComplete.recurrenteRefundNotice';

	function reducedMotion() {
		return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	}

	function showNotice(message, kind) {
		const inside = $('#woocommerce-order-items .inside');
		if (!inside.length) { return null; }
		inside.find('.recurrente-refund-notice').remove();
		const notice = $('<div>').addClass('notice inline recurrente-refund-notice ' + kind).attr('role', 'status');
		notice.append($('<p>').text(message));
		inside.prepend(notice);
		if (!reducedMotion() && notice[0].animate) {
			notice[0].animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200, easing: 'ease-out' });
		}
		if (window.wp && window.wp.a11y) { window.wp.a11y.speak(message); }
		return notice[0];
	}

	function afterItemsReload(message) {
		$(document).off(reloadEvent).on(reloadEvent, function (_event, _xhr, settings) {
			const data = new URLSearchParams(settings.data);
			if (data.get('action') !== 'woocommerce_load_order_items' || data.get('order_id') !== config.orderId) { return; }
			$(document).off(reloadEvent);
			$('#woocommerce-order-items').unblock();
			showNotice(message, 'notice-warning');
		});
	}

	function highlightRow(row, notice) {
		if (!row.animate || !notice) { return; }
		const inside = document.querySelector('#woocommerce-order-items .inside');
		if (window.getComputedStyle(inside).position === 'static') { inside.style.position = 'relative'; }
		const parent = inside.getBoundingClientRect();
		const rect = row.getBoundingClientRect();
		const overlay = document.createElement('span');
		overlay.setAttribute('aria-hidden', 'true');
		Object.assign(overlay.style, {
			position: 'absolute', pointerEvents: 'none', left: (rect.left - parent.left) + 'px',
			top: (rect.top - parent.top) + 'px', width: rect.width + 'px', height: rect.height + 'px',
			backgroundColor: window.getComputedStyle(notice).borderLeftColor
		});
		inside.appendChild(overlay);
		overlay.animate([{ opacity: 0.12 }, { opacity: 0 }], { duration: 600, easing: 'ease-out' })
			.finished.then(function () { overlay.remove(); }, function () { overlay.remove(); });
	}

	function showConfirmedRefund() {
		let operation;
		try {
			operation = window.sessionStorage.getItem(doneKey);
			if (!operation) { return; }
			window.sessionStorage.removeItem(doneKey);
		} catch (_error) { return; }
		const refundId = config.refundRows && config.refundRows['woo_refund_' + operation];
		if (!refundId) { return; }
		const notice = showNotice(config.success, 'notice-success');
		const row = document.querySelector('tr.refund[data-order_refund_id="' + Number(refundId) + '"]');
		if (!row || reducedMotion()) { return; }
		row.scrollIntoView({ block: 'center', behavior: 'smooth' });
		highlightRow(row, notice);
	}

	function afterLoad() {
		window.requestAnimationFrame(function () { window.requestAnimationFrame(function () {
			showConfirmedRefund();
			const result = document.getElementById('recurrente-refund-result');
			if (result) { result.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' }); }
		}); });
	}
	if (document.readyState === 'complete') { afterLoad(); } else { window.addEventListener('load', afterLoad); }
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
					try { window.sessionStorage.removeItem(storageKey); } catch (_error) { /* Server terminal keys remain authoritative. */ }
					try { window.sessionStorage.setItem(doneKey, operation.id); } catch (_error) { /* Optional presentation cue. */ }
				} else if (response && response.data && response.data.error) {
					afterItemsReload(response.data.error);
				}
			});
		} catch (error) {
			xhr.abort();
			$('#woocommerce-order-items').unblock({ fadeOut: 0 });
			window.alert(config.blocked);
			showNotice(config.blocked, 'notice-error');
		}
	});
})(jQuery);

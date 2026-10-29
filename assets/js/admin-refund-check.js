/** Native order-action feedback; never changes the selected action or refund identity. */
(function () {
	'use strict';
	function init() {
		const select = document.querySelector('select[name="wc_order_action"]');
		const pending = document.querySelector('.recurrente-refund-check-pending');
		if (!select || !select.form || !pending) { return; }
		const box = document.getElementById('woocommerce-order-actions');
		let submitted = false;
		select.form.addEventListener('submit', function (event) {
			if (submitted) { event.preventDefault(); return; }
			if (select.value !== 'recurrente_check_refund') { return; }
			submitted = true;
			pending.hidden = false;
			if (box) { box.setAttribute('aria-busy', 'true'); }
			// Keep native action/submitter fields until the browser has collected the form.
			window.setTimeout(function () {
				const controls = box ? box.querySelectorAll('button, select') : [];
				controls.forEach(function (control) { control.disabled = true; });
			}, 0);
		});
		window.addEventListener('pageshow', function (event) {
			if (!event.persisted || !submitted) { return; }
			submitted = false;
			pending.hidden = true;
			if (box) {
				box.removeAttribute('aria-busy');
				box.querySelectorAll('button, select').forEach(function (control) { control.disabled = false; });
			}
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();

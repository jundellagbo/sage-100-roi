(function () {
	'use strict';

	var cfg = window.sageRoiCheckoutBlockNotice || {};
	if (!cfg.templateParts) {
		return;
	}

	function findDeliverySelect() {
		var root = document.querySelector('.wc-block-checkout__order-fields');
		if (!root) {
			return null;
		}
		if (cfg.selectId) {
			var byId = document.getElementById(cfg.selectId);
			if (byId && byId.tagName === 'SELECT') {
				return byId;
			}
		}
		var candidates = root.querySelectorAll('select');
		var i;
		var el;
		for (i = 0; i < candidates.length; i++) {
			el = candidates[i];
			if (cfg.selectId && el.id === cfg.selectId) {
				return el;
			}
			if (el.name && el.name.indexOf('sage-100-roi') !== -1) {
				return el;
			}
			if (el.id && el.id.indexOf('sage-100-roi-order-date') !== -1) {
				return el;
			}
		}
		return null;
	}

	function esc(s) {
		if (s == null) {
			return '';
		}
		var d = document.createElement('div');
		d.textContent = String(s);
		return d.innerHTML;
	}

	function buildHtml(ymd) {
		var labels = cfg.labels || {};
		var label = labels[ymd] || '';
		if (!label) {
			return '';
		}
		var p = cfg.templateParts;
		return (p[0] || '') + esc(label) + (p[1] || '');
	}

	function placeNotice() {
		var root = document.querySelector('.wc-block-checkout__order-fields');
		if (!root) {
			return false;
		}
		var sel = findDeliverySelect();
		if (!sel) {
			return false;
		}

		var row =
			sel.closest('.wc-block-components-form-row') ||
			sel.closest('.wc-block-components-field-wrapper') ||
			sel.parentElement;
		var ymd = sel.value || cfg.fallbackYmd || '';
		var html = buildHtml(ymd);

		var existing = root.querySelector('.sage-roi-block-checkout-notice');
		if (existing) {
			existing.innerHTML = html;
		} else {
			var wrap = document.createElement('div');
			wrap.className = 'sage-roi-cart-delivery-notice sage-roi-block-checkout-notice';
			wrap.style.marginTop = '12px';
			wrap.innerHTML = html;
			if (row && row.parentNode) {
				row.parentNode.insertBefore(wrap, row.nextSibling);
			} else {
				root.appendChild(wrap);
			}
		}

		sel.removeEventListener('change', onChange);
		sel.addEventListener('change', onChange);
		return true;
	}

	function onChange() {
		var sel = findDeliverySelect();
		if (!sel) {
			return;
		}
		var ymd = sel.value || cfg.fallbackYmd || '';
		var html = buildHtml(ymd);
		var root = document.querySelector('.wc-block-checkout__order-fields');
		var existing = root ? root.querySelector('.sage-roi-block-checkout-notice') : null;
		if (existing) {
			existing.innerHTML = html;
		} else {
			placeNotice();
		}
		if (cfg.ajaxUrl && cfg.nonce) {
			var fd = new FormData();
			fd.append('action', 'sage_roi_order_date_save_cart_delivery');
			fd.append('nonce', cfg.nonce);
			fd.append('delivery_date', sel.value || '');
			fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(
				function () {}
			);
		}
	}

	var t;
	function debounced() {
		clearTimeout(t);
		t = setTimeout(function () {
			placeNotice();
		}, 60);
	}

	function startObserver(scope) {
		if (!window.MutationObserver) {
			return;
		}
		var obs = new MutationObserver(debounced);
		obs.observe(scope, { childList: true, subtree: true });
		setTimeout(function () {
			obs.disconnect();
		}, 45000);
	}

	function boot() {
		if (placeNotice()) {
			var root = document.querySelector('.wc-block-checkout__order-fields');
			if (root) {
				startObserver(root);
			}
			return;
		}
		if (!window.MutationObserver) {
			return;
		}
		var obs = new MutationObserver(function () {
			if (placeNotice()) {
				obs.disconnect();
				var r = document.querySelector('.wc-block-checkout__order-fields');
				if (r) {
					startObserver(r);
				}
			}
		});
		obs.observe(document.body, { childList: true, subtree: true });
		setTimeout(function () {
			obs.disconnect();
		}, 45000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();

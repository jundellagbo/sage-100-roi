(function () {
	'use strict';

	var cfg = window.sageRoiCartDelivery || {};
	if (!cfg.markup || !cfg.ajaxUrl || !cfg.nonce) {
		return;
	}

	function findProceedAnchor() {
		var classic = document.querySelector('.cart_totals .wc-proceed-to-checkout');
		if (classic && classic.parentNode) {
			return { parent: classic.parentNode, before: classic };
		}
		var blockWrap = document.querySelector(
			'.wp-block-woocommerce-cart .wc-block-cart__submit-container, .wp-block-woocommerce-cart .wc-block-cart__submit-button'
		);
		if (blockWrap && blockWrap.parentNode) {
			var first = blockWrap.firstElementChild;
			if (first) {
				return { parent: blockWrap, before: first };
			}
			return { parent: blockWrap.parentNode, before: blockWrap };
		}
		var blockBtn = document.querySelector(
			'.wp-block-woocommerce-cart a.wc-block-components-button[href*="checkout"], .wp-block-woocommerce-cart a[href*="checkout"].wc-block-components-button'
		);
		if (blockBtn && blockBtn.parentNode) {
			return { parent: blockBtn.parentNode, before: blockBtn };
		}
		return null;
	}

	function mount() {
		if (document.querySelector('.sage-roi-cart-delivery-wrap')) {
			return true;
		}
		var anchor = findProceedAnchor();
		if (!anchor) {
			return false;
		}
		var tpl = document.createElement('div');
		tpl.innerHTML = cfg.markup.trim();
		var node = tpl.firstElementChild;
		if (!node) {
			return false;
		}
		node.id = 'sage-roi-cart-delivery-mounted';
		anchor.parent.insertBefore(node, anchor.before);
		return true;
	}

	function bindAjaxDelivery() {
		document.body.addEventListener('change', function (e) {
			var sel = e.target;
			if (!sel || !sel.classList || !sel.classList.contains('sage-roi-order-date-cart-ajax')) {
				return;
			}
			var wrap = sel.closest('.sage-roi-cart-delivery-wrap');
			if (!wrap || wrap.getAttribute('data-sage-roi-ajax') !== '1') {
				return;
			}
			var fd = new FormData();
			fd.append('action', 'sage_roi_order_date_save_cart_delivery');
			fd.append('nonce', cfg.nonce);
			fd.append('delivery_date', sel.value || '');
			fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (!res || !res.success) {
						return;
					}
					var notice = wrap.querySelector('.sage-roi-cart-delivery-notice');
					if (notice && res.data && res.data.notice_html) {
						notice.outerHTML = res.data.notice_html;
					}
				})
				.catch(function () {});
		});
	}

	function run() {
		if (mount()) {
			return;
		}
		var obs = new MutationObserver(function () {
			if (mount()) {
				obs.disconnect();
			}
		});
		obs.observe(document.body, { childList: true, subtree: true });
		setTimeout(function () {
			obs.disconnect();
		}, 20000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
	bindAjaxDelivery();
})();

(function () {
	"use strict";

	var config = window.adamCookieConsentConfig || {};
	var root = document.querySelector("[data-adam-cookie-root]");

	if (!root) {
		return;
	}

	var banner = root.querySelector("[data-adam-cookie-banner]");
	var modal = root.querySelector("[data-adam-cookie-modal]");
	var state = Object.assign(
		{
			has_decision: false,
			necessary: true,
			preferences: false,
			analytics: false,
			marketing: false,
		},
		config.state || {}
	);
	var lastTrigger = null;

	function setCookie(value) {
		var secure = window.location.protocol === "https:" ? "; Secure" : "";

		document.cookie =
			(config.cookieName || "adam_cookie_consent") +
			"=" +
			encodeURIComponent(JSON.stringify(value)) +
			"; path=/; max-age=" +
			String(config.maxAge || 15552000) +
			"; SameSite=Lax" +
			secure;
	}

	function syncInputs() {
		root.querySelectorAll("[data-adam-cookie-category]").forEach(function (input) {
			var category = input.getAttribute("data-adam-cookie-category");

			if (!category) {
				return;
			}

			input.checked = Boolean(state[category]);
		});
	}

	function hideBanner() {
		if (banner) {
			banner.classList.add("is-hidden");
		}
	}

	function showBanner() {
		if (banner) {
			banner.classList.remove("is-hidden");
		}
	}

	function openModal(trigger) {
		lastTrigger = trigger || document.activeElement;
		syncInputs();

		if (!modal) {
			return;
		}

		modal.hidden = false;
		document.documentElement.classList.add("adam-cookie-modal-open");

		var firstControl = modal.querySelector("[data-adam-cookie-category], [data-adam-cookie-action='save']");

		if (firstControl) {
			firstControl.focus();
		}
	}

	function closeModal() {
		if (!modal) {
			return;
		}

		modal.hidden = true;
		document.documentElement.classList.remove("adam-cookie-modal-open");

		if (lastTrigger && typeof lastTrigger.focus === "function") {
			lastTrigger.focus();
		}
	}

	function activateDeferredScripts() {
		document.querySelectorAll("script[data-adam-consent][type='text/plain']").forEach(function (node) {
			var category = node.getAttribute("data-adam-consent");

			if (!category || (category !== "necessary" && !state[category])) {
				return;
			}

			var replacement = document.createElement("script");

			Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
				if (attribute.name === "type" || attribute.name === "data-adam-consent-blocked") {
					return;
				}

				replacement.setAttribute(attribute.name, attribute.value);
			});

			replacement.text = node.text || node.textContent || "";
			node.parentNode.insertBefore(replacement, node);
			node.parentNode.removeChild(node);
		});
	}

	function persist(nextState) {
		state = Object.assign({}, state, nextState, {
			has_decision: true,
			necessary: true,
		});

		setCookie(state);
		hideBanner();
		closeModal();
		activateDeferredScripts();
		window.adamCookieConsent = window.adamCookieConsent || {};
		window.adamCookieConsent.state = state;
		window.dispatchEvent(new CustomEvent("adam:cookie-consent-updated", { detail: state }));
	}

	function acceptAll() {
		persist({
			preferences: true,
			analytics: true,
			marketing: true,
		});
	}

	function rejectNonEssential() {
		persist({
			preferences: false,
			analytics: false,
			marketing: false,
		});
	}

	function saveSelection() {
		var preferencesInput = root.querySelector("[data-adam-cookie-category='preferences']");
		var analyticsInput = root.querySelector("[data-adam-cookie-category='analytics']");
		var marketingInput = root.querySelector("[data-adam-cookie-category='marketing']");

		persist({
			preferences: Boolean(preferencesInput && preferencesInput.checked),
			analytics: Boolean(analyticsInput && analyticsInput.checked),
			marketing: Boolean(marketingInput && marketingInput.checked),
		});
	}

	root.addEventListener("click", function (event) {
		var target = event.target;

		if (!(target instanceof HTMLElement)) {
			return;
		}

		if (target.hasAttribute("data-adam-cookie-close")) {
			closeModal();
			return;
		}

		var action = target.getAttribute("data-adam-cookie-action");

		if (!action) {
			return;
		}

		if (action === "accept") {
			acceptAll();
			return;
		}

		if (action === "reject") {
			rejectNonEssential();
			return;
		}

		if (action === "customize" || action === "reopen") {
			openModal(target);
			return;
		}

		if (action === "save") {
			saveSelection();
		}
	});

	document.addEventListener("keydown", function (event) {
		if (event.key === "Escape" && modal && !modal.hidden) {
			closeModal();
		}
	});

	syncInputs();

	if (!state.has_decision) {
		showBanner();
	} else {
		hideBanner();
		activateDeferredScripts();
	}
})();

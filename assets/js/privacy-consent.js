(function () {
	"use strict";

	var config = window.adamCookieConsentConfig || {};
	var root = document.querySelector("[data-adam-cookie-root]");

	if (!root) {
		return;
	}

	var banner = root.querySelector("[data-adam-cookie-banner]");
	var panel = root.querySelector("[data-adam-cookie-modal]");
	var customizeButtons = root.querySelectorAll("[data-adam-cookie-action='customize']");
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
	var storageKey = (config.cookieName || "adam_cookie_consent") + "_state";
	var lastTrigger = null;

	function writeCookie(value) {
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

	function writeStorage(value) {
		try {
			window.localStorage.setItem(storageKey, JSON.stringify(value));
		} catch (error) {
			return;
		}
	}

	function readStorage() {
		try {
			var stored = window.localStorage.getItem(storageKey);

			return stored ? JSON.parse(stored) : null;
		} catch (error) {
			return null;
		}
	}

	function syncInputs() {
		root.querySelectorAll("[data-adam-cookie-category]").forEach(function (input) {
			var category = input.getAttribute("data-adam-cookie-category");

			if (category) {
				input.checked = Boolean(state[category]);
			}
		});
	}

	function syncCustomizeButtons(expanded) {
		customizeButtons.forEach(function (button) {
			button.setAttribute("aria-expanded", expanded ? "true" : "false");
		});
	}

	function hideBanner() {
		if (!banner) {
			return;
		}

		banner.hidden = true;
		banner.classList.add("is-hidden");
		root.classList.remove("is-banner-visible");
	}

	function showBanner() {
		if (!banner) {
			return;
		}

		banner.hidden = false;
		banner.classList.remove("is-hidden");
		root.classList.add("is-banner-visible");
	}

	function closePanel() {
		if (!panel) {
			return;
		}

		panel.hidden = true;
		root.classList.remove("is-panel-open");
		syncCustomizeButtons(false);

		if (lastTrigger && typeof lastTrigger.focus === "function") {
			lastTrigger.focus();
		}
	}

	function openPanel(trigger) {
		if (!panel) {
			return;
		}

		lastTrigger = trigger || document.activeElement;
		syncInputs();
		panel.hidden = false;
		root.classList.add("is-panel-open");
		syncCustomizeButtons(true);

		var firstControl = panel.querySelector("[data-adam-cookie-category]");

		if (firstControl) {
			firstControl.focus();
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

		writeCookie(state);
		writeStorage(state);
		hideBanner();
		closePanel();
		activateDeferredScripts();
		window.adamCookieConsent = window.adamCookieConsent || {};
		window.adamCookieConsent.state = state;
		window.dispatchEvent(
			new CustomEvent("adam:cookie-consent-updated", {
				detail: state,
			})
		);
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

	function initializeState() {
		var stored = readStorage();

		if (stored && typeof stored === "object") {
			state = Object.assign({}, state, stored);
		}

		window.adamCookieConsent = window.adamCookieConsent || {};
		window.adamCookieConsent.state = state;
		syncInputs();
		syncCustomizeButtons(false);

		if (state.has_decision) {
			hideBanner();
			closePanel();
			activateDeferredScripts();
			return;
		}

		showBanner();
		closePanel();
	}

	root.addEventListener("click", function (event) {
		var target = event.target;

		if (!(target instanceof HTMLElement)) {
			return;
		}

		var button = target.closest("[data-adam-cookie-action], [data-adam-cookie-close]");

		if (!button || !root.contains(button)) {
			return;
		}

		if (button.hasAttribute("data-adam-cookie-close")) {
			closePanel();
			return;
		}

		var action = button.getAttribute("data-adam-cookie-action");

		if (action === "accept") {
			persist({
				preferences: true,
				analytics: true,
				marketing: true,
			});
			return;
		}

		if (action === "reject") {
			persist({
				preferences: false,
				analytics: false,
				marketing: false,
			});
			return;
		}

		if (action === "customize" || action === "reopen") {
			openPanel(button);
			return;
		}

		if (action === "save") {
			saveSelection();
		}
	});

	document.addEventListener("keydown", function (event) {
		if (event.key === "Escape" && panel && !panel.hidden) {
			closePanel();
		}
	});

	initializeState();
})();

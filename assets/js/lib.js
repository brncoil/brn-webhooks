/**
 * BRN Webhooks — admin UI core: DOM helpers, icons, API client, i18n, toasts, modals,
 * menus, router, JSON viewer and the field picker.
 *
 * No build step, no framework. Everything user-supplied is inserted as text nodes;
 * innerHTML is only used for the plugin's own static SVG icons and server help texts.
 */
(function () {
	'use strict';

	const BOOT = window.BRNWH_BOOT || {};
	const W = (window.BRNWH = window.BRNWH || {});
	W.boot = BOOT;
	W.views = W.views || {};
	W.state = { integrations: null, settings: null, connections: null };

	/* ------------------------------------------------------------------ i18n */

	const I18N = BOOT.i18n || {};
	function t(s) {
		let out = Object.prototype.hasOwnProperty.call(I18N, s) && I18N[s] ? I18N[s] : s;
		const args = Array.prototype.slice.call(arguments, 1);
		if (args.length) {
			let i = 0;
			out = out.replace(/%(\d+)\$[sd]|%[sd]/g, function (m, pos) {
				const idx = pos ? parseInt(pos, 10) - 1 : i++;
				return args[idx] !== undefined ? String(args[idx]) : '';
			});
		}
		return out;
	}

	/* ------------------------------------------------------------------- DOM */

	function append(el, children) {
		const flat = [];
		(function walk(list) {
			for (const c of list) {
				if (Array.isArray(c)) walk(c);
				else flat.push(c);
			}
		})(children);
		for (const c of flat) {
			if (c === null || c === undefined || c === false || c === true) continue;
			el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
		}
		return el;
	}

	function h(tag, attrs) {
		const children = Array.prototype.slice.call(arguments, 2);
		const el = document.createElement(tag);
		if (attrs) {
			for (const k in attrs) {
				const v = attrs[k];
				if (v === null || v === undefined || v === false) continue;
				if (k === 'class') el.className = Array.isArray(v) ? v.filter(Boolean).join(' ') : v;
				else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
				else if (k.slice(0, 2) === 'on' && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
				else if (k === 'html') el.innerHTML = v;
				else if (k === 'ref') v(el);
				else if (k === 'value') el.value = v;
				else if (k === 'checked') el.checked = !!v;
				else if (k === 'dataset') Object.assign(el.dataset, v);
				else if (v === true) el.setAttribute(k, '');
				else el.setAttribute(k, v);
			}
		}
		return append(el, children);
	}

	function clear(el) {
		while (el.firstChild) el.removeChild(el.firstChild);
		return el;
	}

	function mount(el, node) {
		clear(el);
		return append(el, [node]);
	}

	/* ----------------------------------------------------------------- icons */

	const I = {
		plus: '<path d="M12 5v14M5 12h14"/>',
		x: '<path d="M18 6 6 18M6 6l12 12"/>',
		check: '<path d="M20 6 9 17l-5-5"/>',
		trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
		copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
		edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
		play: '<path d="M6 4v16l14-8z"/>',
		pause: '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
		down: '<path d="m6 9 6 6 6-6"/>',
		up: '<path d="m18 15-6-6-6 6"/>',
		right: '<path d="m9 18 6-6-6-6"/>',
		left: '<path d="m15 18-6-6 6-6"/>',
		search: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
		refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
		retry: '<path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3v5h5"/>',
		link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
		zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
		filter: '<path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3z"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
		eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		eyeOff: '<path d="M9.9 4.2A10 10 0 0 1 12 4c6.5 0 10 8 10 8a17 17 0 0 1-2.2 3.2M6.6 6.6A17 17 0 0 0 2 12s3.5 8 10 8a9.7 9.7 0 0 0 5.4-1.6M2 2l20 20"/><path d="M14.1 14.1a3 3 0 0 1-4.2-4.2"/>',
		download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
		upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
		more: '<circle cx="12" cy="5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="19" r="1.2"/>',
		arrowRight: '<path d="M5 12h14M13 6l6 6-6 6"/>',
		arrowLeft: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
		key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
		shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
		activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
		grid: '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
		flow: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/><path d="M6.5 10v3.5a3 3 0 0 0 3 3H14"/>',
		plug: '<path d="M12 22v-5M9 8V2M15 8V2M18 8v4a6 6 0 0 1-12 0V8z"/>',
		logs: '<path d="M4 6h16M4 12h16M4 18h10"/>',
		gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		braces: '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1M16 21h1a2 2 0 0 0 2-2v-5a2 2 0 0 1 2-2 2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/>',
		radar: '<path d="M4.9 19.1a10 10 0 0 1 0-14.2M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4M19.1 4.9a10 10 0 0 1 0 14.2"/><circle cx="12" cy="12" r="2"/>',
		send: '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
		sparkles: '<path d="M12 3l1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2z"/><path d="M19 3v4M17 5h4"/>',
		external: '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		branch: '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="9" r="2.5"/><path d="M6 8.5v7M18 11.5c0 3-3 4.5-9.5 5.5"/>',
		power: '<path d="M12 2v10M18.4 6.6a9 9 0 1 1-12.8 0"/>',
		save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
		dice: '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1"/><circle cx="15.5" cy="15.5" r="1"/><circle cx="15.5" cy="8.5" r="1"/><circle cx="8.5" cy="15.5" r="1"/>',
		bolt: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
	};

	function icon(name, size) {
		const s = size || 18;
		const span = document.createElement('span');
		span.className = 'brnwh-i';
		span.innerHTML = '<svg width="' + s + '" height="' + s + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (I[name] || '') + '</svg>';
		return span;
	}

	const W_ = 'fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"';
	const APP = {
		webhook: ['linear-gradient(135deg,#7c3aed,#4f46e5)', '<g ' + W_ + '><path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/></g>'],
		http: ['linear-gradient(135deg,#7c3aed,#4f46e5)', '<g ' + W_ + '><path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/></g>'],
		elementor: ['#92003b', '<g fill="#fff"><rect x="7" y="6" width="2.6" height="12" rx=".4"/><rect x="11.6" y="6" width="5.6" height="2.6" rx=".4"/><rect x="11.6" y="10.7" width="5.6" height="2.6" rx=".4"/><rect x="11.6" y="15.4" width="5.6" height="2.6" rx=".4"/></g>'],
		cf7: ['#1aa3c9', '<g ' + W_ + '><rect x="4" y="6" width="16" height="12" rx="2"/><path d="m4 8 8 5 8-5"/></g>'],
		wpforms: ['#e27730', '<g ' + W_ + '><rect x="5" y="4" width="14" height="16" rx="2"/><path d="M8.5 9h7M8.5 12.5h7M8.5 16h4"/></g>'],
		gravity: ['#f15a2b', '<g ' + W_ + '><path d="M12 3.5 19.5 8v8L12 20.5 4.5 16V8z"/><path d="M15 10.2h-4.5a1.8 1.8 0 0 0 0 3.6H15v-2"/></g>'],
		fluentforms: ['#1a7efb', '<g ' + W_ + '><path d="M6 6h12M6 11h9M6 16h6"/></g>'],
		meta: ['#0866ff', '<path d="M3.8 15c0-4 2.2-8 4.7-8 2 0 3 2 3.5 3 .5-1 1.6-3 3.5-3 2.5 0 4.7 4 4.7 8 0 1.8-.9 3-2.3 3-2.6 0-4.3-4.5-5.9-7.6C10.4 13.5 8.7 18 6.1 18c-1.4 0-2.3-1.2-2.3-3z" fill="none" stroke="#fff" stroke-width="1.7" stroke-linejoin="round"/>'],
		meta_leads: null,
		meta_capi: null,
		woocommerce: ['#7f54b3', '<g ' + W_ + '><path d="M6 7h12l-1 12H7z"/><path d="M9 7a3 3 0 0 1 6 0"/></g>'],
		woo_order: null,
		wordpress: ['#21759b', '<circle cx="12" cy="12" r="8" fill="none" stroke="#fff" stroke-width="1.6"/><text x="12" y="16" text-anchor="middle" font-size="10.5" font-weight="700" fill="#fff" font-family="Georgia,serif">W</text>'],
		wp_user: null,
		wp_post: null,
		google_sheets: ['#0f9d58', '<g ' + W_ + '><rect x="6" y="3.5" width="12" height="17" rx="1.5"/><path d="M6 10h12M6 15h12M11 10v10.5"/></g>'],
		google: null,
		excel: ['#107c41', '<g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="m9 8.5 6 7M15 8.5l-6 7"/></g>'],
		microsoft: null,
		email: ['#f59e0b', '<g ' + W_ + '><rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="m4 7.5 8 5.5 8-5.5"/></g>'],
		slack: ['#4a154b', '<g ' + W_ + '><path d="M9.5 4v16M14.5 4v16M4 9.5h16M4 14.5h16"/></g>'],
		telegram: ['#229ed9', '<g ' + W_ + '><path d="m21 4-18 7 6 2 2 6 3.5-4.5L19 18z"/><path d="m9 13 9-6"/></g>'],
		key: ['#475569', '<g ' + W_ + '><circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2.5 2.5"/></g>'],
		http_auth: null,
		filter: ['#0ea5e9', '<path d="M20 4H4l6.5 7.7V18l3 1.5v-7.8z" fill="none" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/>'],
	};
	const ALIAS = { meta_leads: 'meta', meta_capi: 'meta', woo_order: 'woocommerce', wp_user: 'wordpress', wp_post: 'wordpress', google: 'google_sheets', microsoft: 'excel', http_auth: 'key' };

	function appIcon(key, size, label) {
		const k = APP[key] ? key : ALIAS[key] || null;
		const def = k ? APP[k] : null;
		const s = size || 36;
		const span = document.createElement('span');
		span.className = 'brnwh-app-icon';
		span.style.width = s + 'px';
		span.style.height = s + 'px';
		if (def) {
			span.style.background = def[0];
			span.innerHTML = '<svg viewBox="0 0 24 24" width="' + Math.round(s * 0.62) + '" height="' + Math.round(s * 0.62) + '" aria-hidden="true">' + def[1] + '</svg>';
		} else {
			span.style.background = 'linear-gradient(135deg,#64748b,#334155)';
			span.textContent = (label || key || '?').charAt(0).toUpperCase();
		}
		return span;
	}

	/* ------------------------------------------------------------------- API */

	async function api(path, opts) {
		opts = opts || {};
		let url = BOOT.root + String(path).replace(/^\//, '');
		// Translate server responses into the user's admin language (like wp.apiFetch does).
		url += (url.indexOf('?') >= 0 ? '&' : '?') + '_locale=user';
		if (opts.query) {
			const qs = new URLSearchParams();
			Object.keys(opts.query).forEach(function (k) {
				const v = opts.query[k];
				if (v !== undefined && v !== null && v !== '') qs.append(k, v);
			});
			const s = qs.toString();
			if (s) url += (url.indexOf('?') >= 0 ? '&' : '?') + s;
		}
		const init = {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': BOOT.nonce, Accept: 'application/json' },
		};
		if (opts.body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		const res = await fetch(url, init);
		const text = await res.text();
		let data = null;
		try {
			data = text ? JSON.parse(text) : null;
		} catch (e) {
			data = { message: text.replace(/<[^>]+>/g, ' ').trim().slice(0, 300) };
		}
		if (!res.ok) {
			const err = new Error((data && data.message) || 'HTTP ' + res.status);
			err.status = res.status;
			err.data = data;
			if (res.status === 403 && data && data.code === 'rest_cookie_invalid_nonce') {
				err.message = t('Your session expired. Reload the page.');
			}
			throw err;
		}
		return data;
	}

	/* ---------------------------------------------------------------- format */

	const locale = (BOOT.locale || 'en-US').replace('_', '-');
	function fmtDate(iso, withTime) {
		if (!iso) return '—';
		const d = new Date(iso);
		if (isNaN(d)) return iso;
		try {
			return d.toLocaleString(locale, withTime === false ? { dateStyle: 'medium' } : { dateStyle: 'medium', timeStyle: 'short' });
		} catch (e) {
			return d.toLocaleString();
		}
	}
	function timeAgo(iso) {
		if (!iso) return t('never');
		const d = new Date(iso);
		const diff = (d.getTime() - Date.now()) / 1000;
		const abs = Math.abs(diff);
		let rtf;
		try {
			rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
		} catch (e) {
			return fmtDate(iso);
		}
		if (abs < 45) return rtf.format(Math.round(diff), 'second');
		if (abs < 2700) return rtf.format(Math.round(diff / 60), 'minute');
		if (abs < 79200) return rtf.format(Math.round(diff / 3600), 'hour');
		if (abs < 2592000) return rtf.format(Math.round(diff / 86400), 'day');
		return fmtDate(iso, false);
	}
	function fmtMs(ms) {
		if (ms === null || ms === undefined) return '—';
		if (ms < 1000) return ms + ' ms';
		return (ms / 1000).toFixed(ms < 10000 ? 1 : 0) + ' s';
	}
	function num(n) {
		try {
			return new Intl.NumberFormat(locale).format(n);
		} catch (e) {
			return String(n);
		}
	}

	/* -------------------------------------------------------------- feedback */

	let toastWrap = null;
	function toast(message, type, ms) {
		if (!toastWrap) {
			toastWrap = h('div', { class: 'brnwh-toasts brnwh', dir: BOOT.rtl ? 'rtl' : 'ltr', 'aria-live': 'polite' });
			document.body.appendChild(toastWrap);
		}
		const kind = type || 'success';
		const el = h('div', { class: 'brnwh-toast is-' + kind }, icon(kind === 'error' ? 'alert' : kind === 'info' ? 'info' : 'check', 16), h('span', null, message));
		toastWrap.appendChild(el);
		requestAnimationFrame(function () {
			el.classList.add('is-in');
		});
		setTimeout(function () {
			el.classList.remove('is-in');
			setTimeout(function () {
				el.remove();
			}, 300);
		}, ms || (kind === 'error' ? 6000 : 3200));
	}

	function errorToast(e) {
		toast(e && e.message ? e.message : t('Something went wrong.'), 'error');
	}

	function modal(opts) {
		const prevFocus = document.activeElement;
		let closed = false;
		const box = h('div', { class: ['brnwh-modal', opts.size ? 'is-' + opts.size : ''], role: 'dialog', 'aria-modal': 'true' });
		const overlay = h('div', { class: 'brnwh-overlay brnwh', dir: BOOT.rtl ? 'rtl' : 'ltr' }, box);
		function close(result) {
			if (closed) return;
			closed = true;
			overlay.classList.remove('is-in');
			document.removeEventListener('keydown', onKey);
			setTimeout(function () {
				overlay.remove();
			}, 200);
			if (prevFocus && prevFocus.focus) prevFocus.focus();
			if (opts.onClose) opts.onClose(result);
		}
		function onKey(e) {
			if (e.key === 'Escape') close();
		}
		const head = h(
			'div',
			{ class: 'brnwh-modal-head' },
			opts.icon || null,
			h('div', { class: 'brnwh-modal-titles' }, h('h2', null, opts.title || ''), opts.subtitle ? h('p', null, opts.subtitle) : null),
			h('button', { class: 'brnwh-icon-btn', type: 'button', 'aria-label': t('Close'), onClick: function () { close(); } }, icon('x'))
		);
		const body = h('div', { class: 'brnwh-modal-body' });
		const foot = h('div', { class: 'brnwh-modal-foot' });
		append(box, [head, body, foot]);
		const api_ = { close: close, body: body, foot: foot, box: box };
		if (typeof opts.body === 'function') append(body, [opts.body(api_)]);
		else if (opts.body) append(body, [opts.body]);
		if (typeof opts.footer === 'function') append(foot, [opts.footer(api_)]);
		else if (opts.footer) append(foot, [opts.footer]);
		else foot.remove();
		overlay.addEventListener('mousedown', function (e) {
			if (e.target === overlay && opts.dismissable !== false) close();
		});
		document.addEventListener('keydown', onKey);
		document.body.appendChild(overlay);
		requestAnimationFrame(function () {
			overlay.classList.add('is-in');
			const f = box.querySelector('[autofocus], input:not([type=hidden]), textarea, select');
			if (f && opts.autofocus !== false) f.focus();
		});
		return api_;
	}

	function confirmDialog(o) {
		return new Promise(function (resolve) {
			const m = modal({
				title: o.title,
				size: 'sm',
				body: h('p', { class: 'brnwh-confirm-text' }, o.message || ''),
				onClose: function (r) {
					resolve(r === true);
				},
				footer: function (m_) {
					return [
						h('button', { class: 'brnwh-btn is-ghost', type: 'button', onClick: function () { m_.close(false); } }, t('Cancel')),
						h('button', { class: ['brnwh-btn', o.danger ? 'is-danger' : 'is-primary'], type: 'button', autofocus: true, onClick: function () { m_.close(true); } }, o.confirm || t('Confirm')),
					];
				},
			});
			return m;
		});
	}

	function promptDialog(o) {
		return new Promise(function (resolve) {
			let input;
			let done = function () {};
			modal({
				title: o.title,
				size: 'sm',
				body: h('div', { class: 'brnwh-field' }, o.label ? h('label', { class: 'brnwh-label' }, o.label) : null, h('input', { class: 'brnwh-input', type: 'text', value: o.value || '', placeholder: o.placeholder || '', ref: function (el) { input = el; }, onKeydown: function (e) { if (e.key === 'Enter') { e.preventDefault(); done(); } } })),
				onClose: function (r) {
					resolve(typeof r === 'string' ? r : null);
				},
				footer: function (m_) {
					done = function () {
						m_.close(input.value);
					};
					return [h('button', { class: 'brnwh-btn is-ghost', type: 'button', onClick: function () { m_.close(null); } }, t('Cancel')), h('button', { class: 'brnwh-btn is-primary', type: 'button', onClick: function () { done(); } }, o.confirm || t('Save'))];
				},
			});
		});
	}

	/* ------------------------------------------------------------------ menu */

	let openMenu = null;
	function closeMenu() {
		if (openMenu) {
			openMenu.remove();
			openMenu = null;
			document.removeEventListener('mousedown', outsideMenu, true);
		}
	}
	function outsideMenu(e) {
		if (openMenu && !openMenu.contains(e.target)) closeMenu();
	}
	function menu(anchor, items) {
		closeMenu();
		const el = h(
			'div',
			{ class: 'brnwh-menu brnwh', dir: BOOT.rtl ? 'rtl' : 'ltr', role: 'menu' },
			items.filter(Boolean).map(function (it) {
				if (it === '-') return h('div', { class: 'brnwh-menu-sep' });
				return h('button', { type: 'button', role: 'menuitem', class: ['brnwh-menu-item', it.danger ? 'is-danger' : ''], onClick: function () { closeMenu(); it.onClick(); } }, it.icon ? icon(it.icon, 16) : null, h('span', null, it.label));
			})
		);
		document.body.appendChild(el);
		positionPopover(el, anchor, 'end');
		openMenu = el;
		setTimeout(function () {
			document.addEventListener('mousedown', outsideMenu, true);
		}, 0);
	}

	function positionPopover(el, anchor, align) {
		const r = anchor.getBoundingClientRect();
		el.style.position = 'fixed';
		el.style.top = Math.min(r.bottom + 6, window.innerHeight - 20) + 'px';
		const w = el.offsetWidth;
		let left;
		const endAligned = align === 'end';
		if (BOOT.rtl) left = endAligned ? r.left : r.right - w;
		else left = endAligned ? r.right - w : r.left;
		left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
		el.style.left = left + 'px';
		const eh = el.offsetHeight;
		if (r.bottom + 6 + eh > window.innerHeight - 8 && r.top - eh - 6 > 8) el.style.top = r.top - eh - 6 + 'px';
	}

	/* ----------------------------------------------------------------- utils */

	function copy(text) {
		const done = function () {
			toast(t('Copied to clipboard'));
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, function () {
				fallback();
			});
		} else fallback();
		function fallback() {
			const ta = h('textarea', { style: { position: 'fixed', opacity: '0' } });
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			try {
				document.execCommand('copy');
				done();
			} catch (e) {
				/* ignore */
			}
			ta.remove();
		}
	}

	function download(filename, data) {
		const blob = new Blob([typeof data === 'string' ? data : JSON.stringify(data, null, 2)], { type: 'application/json' });
		const a = h('a', { href: URL.createObjectURL(blob), download: filename });
		document.body.appendChild(a);
		a.click();
		setTimeout(function () {
			URL.revokeObjectURL(a.href);
			a.remove();
		}, 500);
	}

	function pickFile(accept) {
		return new Promise(function (resolve) {
			const input = h('input', { type: 'file', accept: accept || '', style: { display: 'none' } });
			input.addEventListener('change', function () {
				const f = input.files && input.files[0];
				if (!f) return resolve(null);
				const reader = new FileReader();
				reader.onload = function () {
					resolve({ name: f.name, text: String(reader.result) });
				};
				reader.readAsText(f);
				input.remove();
			});
			document.body.appendChild(input);
			input.click();
		});
	}

	function debounce(fn, ms) {
		let tm;
		return function () {
			const a = arguments;
			clearTimeout(tm);
			tm = setTimeout(function () {
				fn.apply(null, a);
			}, ms || 250);
		};
	}

	function clone(o) {
		return o === undefined ? undefined : JSON.parse(JSON.stringify(o));
	}

	function uid() {
		return Math.random().toString(36).slice(2, 12).replace(/[^a-z0-9]/g, 'x').padEnd(10, '0');
	}

	function randomToken(len) {
		const a = new Uint8Array(len || 24);
		crypto.getRandomValues(a);
		return Array.from(a, function (b) {
			return ('0' + b.toString(16)).slice(-2);
		}).join('');
	}

	/** Flatten data into [{path, value}] for the field picker. */
	function flatten(obj, prefix, out, depth) {
		out = out || [];
		depth = depth || 0;
		if (obj === null || typeof obj !== 'object' || depth > 6) return out;
		const isArr = Array.isArray(obj);
		Object.keys(obj).forEach(function (k) {
			const v = obj[k];
			const path = prefix ? prefix + '.' + k : String(k);
			if (v !== null && typeof v === 'object') {
				const isScalarList = Array.isArray(v) && v.every(function (x) { return x === null || typeof x !== 'object'; });
				if (isScalarList || (Array.isArray(v) && v.length > 0)) out.push({ path: path, value: v });
				if (!isScalarList) flatten(v, path, out, depth + 1);
			} else {
				out.push({ path: path, value: v });
			}
			if (isArr && Number(k) >= 2) return; // keep lists short
		});
		return out;
	}

	function preview(v) {
		if (v === null || v === undefined) return '';
		if (typeof v === 'object') {
			const s = JSON.stringify(v);
			return s.length > 60 ? s.slice(0, 60) + '…' : s;
		}
		const s = String(v);
		return s.length > 60 ? s.slice(0, 60) + '…' : s;
	}

	/* ---------------------------------------------------------------- pieces */

	const STATUS = {
		success: ['Success', 'ok'],
		failed: ['Failed', 'err'],
		partial: ['Partial', 'warn'],
		retrying: ['Retrying', 'warn'],
		queued: ['Queued', 'muted'],
		running: ['Running', 'info'],
		filtered: ['Filtered', 'muted'],
		skipped: ['Skipped', 'muted'],
		not_run: ['Not run', 'muted'],
		active: ['Active', 'ok'],
		paused: ['Paused', 'muted'],
		ok: ['Working', 'ok'],
		error: ['Error', 'err'],
		untested: ['Not tested', 'muted'],
	};
	function badge(status, extra) {
		const s = STATUS[status] || [status || '—', 'muted'];
		return h('span', { class: 'brnwh-badge is-' + s[1] }, h('i', { class: 'brnwh-dot' }), t(s[0]), extra || null);
	}

	function toggle(o) {
		const input = h('input', { type: 'checkbox', checked: !!o.checked, disabled: o.disabled || null, onChange: function (e) { if (o.onChange) o.onChange(e.target.checked, e); } });
		return h('label', { class: ['brnwh-toggle', o.small ? 'is-small' : ''], title: o.title || null }, input, h('span', { class: 'brnwh-toggle-track' }, h('span', { class: 'brnwh-toggle-thumb' })), o.label ? h('span', { class: 'brnwh-toggle-label' }, o.label) : null);
	}

	function btn(label, o) {
		o = o || {};
		return h(
			'button',
			{ type: o.type || 'button', class: ['brnwh-btn', o.variant ? 'is-' + o.variant : '', o.size ? 'is-' + o.size : '', o.class || ''], onClick: o.onClick || null, disabled: o.disabled || null, title: o.title || null, 'aria-label': o.aria || null },
			o.icon ? icon(o.icon, o.iconSize || 16) : null,
			label ? h('span', null, label) : null
		);
	}

	function iconBtn(name, o) {
		o = o || {};
		return h('button', { type: 'button', class: ['brnwh-icon-btn', o.class || ''], title: o.title || null, 'aria-label': o.title || null, onClick: o.onClick || null, disabled: o.disabled || null }, icon(name, o.size || 17));
	}

	async function busy(button, fn) {
		if (button) {
			button.disabled = true;
			button.classList.add('is-busy');
		}
		try {
			return await fn();
		} finally {
			if (button) {
				button.disabled = false;
				button.classList.remove('is-busy');
			}
		}
	}

	function empty(o) {
		return h('div', { class: 'brnwh-empty' }, h('div', { class: 'brnwh-empty-art' }, icon(o.icon || 'sparkles', 30)), h('h3', null, o.title), o.text ? h('p', null, o.text) : null, o.action || null);
	}

	function skeleton(n) {
		const out = [];
		for (let i = 0; i < (n || 3); i++) out.push(h('div', { class: 'brnwh-skel' }));
		return h('div', { class: 'brnwh-skel-wrap' }, out);
	}

	function card(title, body, o) {
		o = o || {};
		return h('section', { class: ['brnwh-card', o.class || ''] }, title || o.actions ? h('header', { class: 'brnwh-card-head' }, h('h3', null, title || ''), o.actions || null) : null, h('div', { class: 'brnwh-card-body' }, body));
	}

	/* ----------------------------------------------------------- JSON viewer */

	function jsonView(value, o) {
		o = o || {};
		const open = o.open === undefined ? 2 : o.open;
		function node(v, key, depth) {
			const keyEl = key !== null ? h('span', { class: 'brnwh-j-key' }, key, ': ') : null;
			if (v === null || typeof v !== 'object') {
				let cls = 'brnwh-j-str';
				let txt;
				if (v === null) {
					cls = 'brnwh-j-null';
					txt = 'null';
				} else if (typeof v === 'number') {
					cls = 'brnwh-j-num';
					txt = String(v);
				} else if (typeof v === 'boolean') {
					cls = 'brnwh-j-bool';
					txt = String(v);
				} else {
					txt = '"' + v + '"';
				}
				return h('div', { class: 'brnwh-j-row' }, keyEl, h('span', { class: cls }, txt));
			}
			const isArr = Array.isArray(v);
			const keys = Object.keys(v);
			const kids = h('div', { class: 'brnwh-j-kids' });
			let rendered = false;
			const row = h('div', { class: 'brnwh-j-node' });
			const toggleEl = h('button', { type: 'button', class: 'brnwh-j-toggle' }, icon('right', 12));
			const summary = h('span', { class: 'brnwh-j-sum' }, isArr ? '[' + keys.length + ']' : '{' + keys.length + '}');
			function setOpen(isOpen) {
				row.classList.toggle('is-open', isOpen);
				if (isOpen && !rendered) {
					rendered = true;
					keys.forEach(function (k) {
						kids.appendChild(node(v[k], k, depth + 1));
					});
				}
			}
			toggleEl.addEventListener('click', function () {
				setOpen(!row.classList.contains('is-open'));
			});
			append(row, [h('div', { class: 'brnwh-j-row is-branch' }, toggleEl, keyEl, summary), kids]);
			setOpen(depth < open && keys.length > 0);
			return row;
		}
		let parsed = value;
		if (typeof value === 'string') {
			try {
				const p = JSON.parse(value);
				if (p && typeof p === 'object') parsed = p;
			} catch (e) {
				/* not JSON */
			}
		}
		if (typeof parsed === 'string') return h('pre', { class: 'brnwh-pre' }, parsed);
		const wrap = h('div', { class: 'brnwh-json' }, node(parsed, null, 0));
		if (o.copy !== false) {
			wrap.appendChild(h('button', { type: 'button', class: 'brnwh-json-copy', title: t('Copy JSON'), onClick: function () { copy(typeof value === 'string' ? value : JSON.stringify(value, null, 2)); } }, icon('copy', 14)));
		}
		return wrap;
	}

	/* ---------------------------------------------------------- field picker */

	/**
	 * Popover listing available fields; inserts {{path}} at the caret of `input`.
	 * groups: [{label, items:[{path, label, value}]}]
	 */
	function fieldPicker(anchor, input, groups, onInsert) {
		closeMenu();
		const search = h('input', { class: 'brnwh-input is-sm', type: 'search', placeholder: t('Search fields…') });
		const list = h('div', { class: 'brnwh-picker-list' });
		const pop = h('div', { class: 'brnwh-menu brnwh brnwh-picker', dir: BOOT.rtl ? 'rtl' : 'ltr' }, h('div', { class: 'brnwh-picker-search' }, search), list);
		function render(q) {
			clear(list);
			q = (q || '').toLowerCase();
			let count = 0;
			groups.forEach(function (g) {
				const items = g.items.filter(function (it) {
					return !q || it.path.toLowerCase().indexOf(q) >= 0 || (it.label || '').toLowerCase().indexOf(q) >= 0 || preview(it.value).toLowerCase().indexOf(q) >= 0;
				});
				if (!items.length) return;
				list.appendChild(h('div', { class: 'brnwh-picker-group' }, g.label));
				items.slice(0, 200).forEach(function (it) {
					count++;
					list.appendChild(
						h(
							'button',
							{ type: 'button', class: 'brnwh-picker-item', onClick: function () { insert(it.path); } },
							h('span', { class: 'brnwh-picker-path' }, it.label && it.label !== it.path ? it.label : it.path),
							it.label && it.label !== it.path ? h('code', null, it.path) : null,
							it.value !== undefined && it.value !== '' ? h('span', { class: 'brnwh-picker-val' }, preview(it.value)) : null
						)
					);
				});
			});
			if (!count) list.appendChild(h('div', { class: 'brnwh-picker-empty' }, t('No fields yet. Send test data to the trigger to see its fields here.')));
		}
		function insert(path) {
			const token = '{{' + path + '}}';
			if (input) {
				const start = input.selectionStart !== null && input.selectionStart !== undefined ? input.selectionStart : input.value.length;
				const end = input.selectionEnd !== null && input.selectionEnd !== undefined ? input.selectionEnd : input.value.length;
				input.setRangeText(token, start, end, 'end');
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.focus();
			}
			if (onInsert) onInsert(token);
			closeMenu();
		}
		search.addEventListener('input', function () {
			render(search.value);
		});
		search.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeMenu();
			if (e.key === 'Enter') {
				const first = list.querySelector('.brnwh-picker-item');
				if (first) {
					e.preventDefault();
					first.click();
				}
			}
		});
		render('');
		document.body.appendChild(pop);
		positionPopover(pop, anchor, 'end');
		openMenu = pop;
		setTimeout(function () {
			document.addEventListener('mousedown', outsideMenu, true);
			search.focus();
		}, 0);
	}

	/* ---------------------------------------------------------------- router */

	const routes = [];
	function route(pattern, handler) {
		const keys = [];
		const re = new RegExp('^' + pattern.replace(/:(\w+)/g, function (m, k) { keys.push(k); return '([^/]+)'; }) + '/?$');
		routes.push({ re: re, keys: keys, handler: handler, pattern: pattern });
	}
	function currentPath() {
		const hsh = window.location.hash.replace(/^#/, '');
		return hsh || '/';
	}
	function navigate(path) {
		if (currentPath() === path) W.router.resolve();
		else window.location.hash = path;
	}
	W.router = {
		guard: null,
		resolve: function () {
			const full = currentPath();
			const qpos = full.indexOf('?');
			const path = qpos >= 0 ? full.slice(0, qpos) : full;
			const query = {};
			if (qpos >= 0) {
				new URLSearchParams(full.slice(qpos + 1)).forEach(function (v, k) {
					query[k] = v;
				});
			}
			for (const r of routes) {
				const m = path.match(r.re);
				if (m) {
					const params = { query: query };
					r.keys.forEach(function (k, i) {
						params[k] = decodeURIComponent(m[i + 1]);
					});
					r.handler(params, path);
					return;
				}
			}
			navigate('/');
		},
	};

	/* --------------------------------------------------------------- exports */

	Object.assign(W, {
		t: t,
		h: h,
		append: append,
		clear: clear,
		mount: mount,
		icon: icon,
		appIcon: appIcon,
		api: api,
		fmtDate: fmtDate,
		timeAgo: timeAgo,
		fmtMs: fmtMs,
		num: num,
		toast: toast,
		errorToast: errorToast,
		modal: modal,
		confirm: confirmDialog,
		prompt: promptDialog,
		menu: menu,
		closeMenu: closeMenu,
		positionPopover: positionPopover,
		copy: copy,
		download: download,
		pickFile: pickFile,
		debounce: debounce,
		clone: clone,
		uid: uid,
		randomToken: randomToken,
		flatten: flatten,
		preview: preview,
		badge: badge,
		toggle: toggle,
		btn: btn,
		iconBtn: iconBtn,
		busy: busy,
		empty: empty,
		skeleton: skeleton,
		card: card,
		jsonView: jsonView,
		fieldPicker: fieldPicker,
		route: route,
		navigate: navigate,
		currentPath: currentPath,
	});
})();

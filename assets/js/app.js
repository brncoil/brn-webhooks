/**
 * BRN Webhooks — app shell and routes.
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;
	const root = document.getElementById('brnwh-app');
	if (!root) return;

	const NAV = [
		['/', 'Dashboard', 'grid'],
		['/flows', 'Flows', 'flow'],
		['/connections', 'Connections', 'plug'],
		['/logs', 'Logs', 'activity'],
		['/settings', 'Settings', 'gear'],
	];

	let main;
	let navLinks = [];
	let lastHash = window.location.hash;
	let ignoreNext = false;

	/** Lookup an integration definition. kind: triggers | actions | connections */
	W.integration = function (kind, id) {
		const list = (W.state.integrations && W.state.integrations[kind]) || [];
		return list.find(function (i) { return i.id === id; }) || null;
	};

	W.refreshConnections = async function () {
		W.state.connections = await W.api('connections');
		return W.state.connections;
	};

	function logo() {
		const s = document.createElement('span');
		s.className = 'brnwh-logo';
		s.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/></svg>';
		return s;
	}

	function shell() {
		navLinks = NAV.map(function (n) {
			return h('a', { href: '#' + n[0], class: 'brnwh-nav-link', dataset: { path: n[0] } }, icon(n[2], 17), h('span', null, t(n[1])));
		});
		main = h('main', { class: 'brnwh-main' }, W.skeleton(4));
		W.clear(root);
		W.append(root, [
			h(
				'header',
				{ class: 'brnwh-top' },
				h('div', { class: 'brnwh-brand' }, logo(), h('div', null, h('strong', null, 'BRN Webhooks'), h('span', null, 'v' + W.boot.version))),
				h('nav', { class: 'brnwh-nav', 'aria-label': 'BRN Webhooks' }, navLinks),
				h('div', { class: 'brnwh-top-actions' }, W.btn(t('New flow'), { icon: 'plus', variant: 'primary', onClick: function () { W.navigate('/flows/new'); } }))
			),
			main,
		]);
	}

	function setActive(path) {
		const section = '/' + (path.split('?')[0].split('/')[1] || '');
		navLinks.forEach(function (a) {
			a.classList.toggle('is-active', a.dataset.path === section);
		});
		// Keep the WordPress submenu highlight in sync.
		document.querySelectorAll('#toplevel_page_brn-webhooks .wp-submenu li').forEach(function (li) {
			const a = li.querySelector('a');
			if (!a) return;
			const href = a.getAttribute('href') || '';
			const target = href.indexOf('#') >= 0 ? href.slice(href.indexOf('#') + 1) : '/';
			li.classList.toggle('current', target === section || (section === '/' && target === '/'));
		});
	}

	/** Render a page into the main area. */
	W.page = function (node) {
		W.closeMenu();
		W.mount(main, node);
	};
	W.main = function () {
		return main;
	};

	function onHash() {
		if (ignoreNext) {
			ignoreNext = false;
			return;
		}
		if (W.router.guard && !W.router.guard()) {
			ignoreNext = true;
			window.location.hash = lastHash;
			return;
		}
		W.router.guard = null;
		lastHash = window.location.hash;
		if (W.onLeave) {
			const fn = W.onLeave;
			W.onLeave = null;
			fn();
		}
		const path = W.currentPath();
		setActive(path);
		W.router.resolve();
	}

	async function start() {
		shell();
		try {
			const res = await Promise.all([W.api('bootstrap'), W.api('connections')]);
			W.state.integrations = res[0].integrations;
			W.state.settings = res[0].settings;
			W.state.connections = res[1];
		} catch (e) {
			W.page(W.empty({ icon: 'alert', title: t('Could not load BRN Webhooks'), text: e.message, action: W.btn(t('Reload'), { icon: 'refresh', onClick: function () { window.location.reload(); } }) }));
			return;
		}
		W.route('/', function () { W.views.dashboard(); });
		W.route('/flows', function () { W.views.flows(); });
		W.route('/flows/new', function () { W.views.editor(null); });
		W.route('/flows/new/:template', function (p) { W.views.editor(null, p.template); });
		W.route('/flows/:id', function (p) { W.views.editor(parseInt(p.id, 10)); });
		W.route('/connections', function () { W.views.connections(); });
		W.route('/logs', function (p) { W.views.logs(p.query); });
		W.route('/logs/:id', function (p) { W.views.logDetail(parseInt(p.id, 10)); });
		W.route('/settings', function () { W.views.settings(); });
		window.addEventListener('hashchange', onHash);
		window.addEventListener('beforeunload', function (e) {
			if (W.router.guard && !W.router.guard(true)) {
				e.preventDefault();
				e.returnValue = '';
			}
		});
		setActive(W.currentPath());
		W.router.resolve();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})();

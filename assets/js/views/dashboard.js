/**
 * BRN Webhooks — dashboard and starter templates.
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;

	/** Starter templates: a trigger plus pre-filled actions. */
	W.templates = [
		{
			id: 'elementor-sheets',
			title: 'Elementor form → Google Sheets',
			desc: 'Every lead becomes a row in your spreadsheet.',
			trigger: 'elementor',
			actions: [{ type: 'google_sheets', config: { mode: 'append' } }],
		},
		{
			id: 'cf7-capi',
			title: 'Contact Form 7 → Meta Lead event',
			desc: 'Server-side Lead event for better ad attribution.',
			trigger: 'cf7',
			actions: [{ type: 'meta_capi', config: { event_name: 'Lead', 'u_email': '{{your-email}}', 'u_phone': '{{your-phone}}', 'u_first_name': '{{your-name|first_word}}' } }],
		},
		{
			id: 'fbleads-excel',
			title: 'Facebook Lead Ads → Excel 365',
			desc: 'New leads land in an Excel table instantly.',
			trigger: 'meta_leads',
			actions: [{ type: 'excel', config: { target: 'table' } }],
		},
		{
			id: 'webhook-email',
			title: 'Incoming webhook → Email',
			desc: 'Receive data from any system and email it to your team.',
			trigger: 'webhook',
			actions: [{ type: 'email', config: { to: W.boot.adminEmail || '', subject: 'New webhook data', body: '{{_all_table}}' } }],
		},
		{
			id: 'woo-purchase',
			title: 'WooCommerce paid order → Meta Purchase',
			desc: 'Send purchases with value to the Conversions API.',
			trigger: 'woo_order',
			triggerConfig: { to_status: 'processing' },
			actions: [{ type: 'meta_capi', config: { event_name: 'Purchase', action_source: 'website', value: '{{total}}', currency: '{{currency}}', u_email: '{{email}}', u_phone: '{{phone}}', u_first_name: '{{first_name}}', u_last_name: '{{last_name}}', u_city: '{{city}}', u_country: '{{country}}', event_id: 'order-{{order_id}}' } }],
		},
		{
			id: 'elementor-telegram',
			title: 'Elementor form → Telegram alert',
			desc: 'Ping your sales group the second a lead arrives.',
			trigger: 'elementor',
			actions: [{ type: 'telegram', config: {} }],
		},
		{
			id: 'webhook-sheets-crm',
			title: 'Webhook → Google Sheets + CRM',
			desc: 'Log incoming data to a sheet and forward it to your CRM.',
			trigger: 'webhook',
			actions: [{ type: 'google_sheets', config: { mode: 'append' } }, { type: 'http', config: { method: 'POST', body_type: 'all' } }],
		},
	];

	function templateTile(tpl) {
		const tr = W.integration('triggers', tpl.trigger);
		const icons = [W.appIcon(tr ? tr.icon : tpl.trigger, 30)];
		tpl.actions.forEach(function (a) {
			const ac = W.integration('actions', a.type);
			icons.push(h('span', { class: 'brnwh-chain-arrow' }, icon(W.boot.rtl ? 'left' : 'right', 14)));
			icons.push(W.appIcon(ac ? ac.icon : a.type, 30));
		});
		const unavailable = tr && !tr.available;
		return h(
			'button',
			{ type: 'button', class: ['brnwh-tpl-tile', unavailable ? 'is-dim' : ''], title: unavailable ? tr.reason : '', onClick: function () { W.navigate('/flows/new/' + tpl.id); } },
			h('div', { class: 'brnwh-chain' }, icons),
			h('strong', null, t(tpl.title)),
			h('span', null, t(tpl.desc))
		);
	}

	function stat(label, value, sub, iconName, tone) {
		return h('div', { class: ['brnwh-stat', tone ? 'is-' + tone : ''] }, h('div', { class: 'brnwh-stat-icon' }, icon(iconName, 20)), h('div', { class: 'brnwh-stat-body' }, h('span', { class: 'brnwh-stat-label' }, label), h('strong', { class: 'brnwh-stat-value' }, h('bdi', { dir: 'ltr' }, value)), sub ? h('span', { class: 'brnwh-stat-sub' }, sub) : null));
	}

	function chart(series) {
		const max = Math.max(1, ...series.map(function (d) { return d.success + d.failed + d.filtered; }));
		const wrap = h('div', { class: 'brnwh-chart' });
		series.forEach(function (d) {
			const total = d.success + d.failed + d.filtered;
			const day = new Date(d.date + 'T12:00:00Z');
			let lbl = d.date.slice(5);
			try {
				lbl = day.toLocaleDateString((W.boot.locale || 'en').replace('_', '-'), { day: 'numeric', month: 'numeric' });
			} catch (e) {
				/* ignore */
			}
			const col = h(
				'div',
				{ class: 'brnwh-bar', title: lbl + ' — ' + t('%d runs', total) + ' (' + d.success + ' ✓ · ' + d.failed + ' ✕ · ' + d.filtered + ' ⦸)' },
				h('div', { class: 'brnwh-bar-stack', style: { height: Math.round((total / max) * 100) + '%' } },
					d.failed ? h('i', { class: 'is-failed', style: { flexGrow: d.failed } }) : null,
					d.filtered ? h('i', { class: 'is-filtered', style: { flexGrow: d.filtered } }) : null,
					d.success ? h('i', { class: 'is-success', style: { flexGrow: d.success } }) : null
				),
				h('span', { class: 'brnwh-bar-label' }, lbl)
			);
			wrap.appendChild(col);
		});
		return h('div', null, wrap, h('div', { class: 'brnwh-legend' }, h('span', null, h('i', { class: 'is-success' }), t('Success')), h('span', null, h('i', { class: 'is-failed' }), t('Failed')), h('span', null, h('i', { class: 'is-filtered' }), t('Filtered'))));
	}

	W.runRow = function (r) {
		const tr = W.integration('triggers', r.trigger_type);
		return h(
			'a',
			{ class: 'brnwh-run-row', href: '#/logs/' + r.id },
			W.badge(r.status),
			W.appIcon(tr ? tr.icon : r.trigger_type, 26),
			h('span', { class: 'brnwh-run-name' }, r.flow_name || '#' + r.flow_id, r.is_test ? h('span', { class: 'brnwh-tag' }, t('test')) : null),
			h('span', { class: 'brnwh-run-steps' }, (r.steps_summary || []).filter(function (s) { return s.type !== '_filter'; }).map(function (s) {
				const a = W.integration('actions', s.type);
				const el = W.appIcon(a ? a.icon : s.type, 20);
				el.classList.add('is-' + s.status);
				return el;
			})),
			h('span', { class: 'brnwh-muted brnwh-run-time', title: W.fmtDate(r.created_at) }, W.timeAgo(r.created_at))
		);
	};

	W.views.dashboard = async function () {
		const page = h('div', { class: 'brnwh-page' });
		W.page(page);
		const hello = h(
			'div',
			{ class: 'brnwh-hero' },
			h('div', null, h('h1', null, t('Hi %s 👋', W.boot.user || '')), h('p', null, t('Here is what your automations did lately on %s.', W.boot.siteName || ''))),
			h('div', { class: 'brnwh-hero-actions' }, W.btn(t('New flow'), { icon: 'plus', variant: 'primary', size: 'lg', onClick: function () { W.navigate('/flows/new'); } }))
		);
		page.appendChild(hello);
		const body = h('div', null, W.skeleton(5));
		page.appendChild(body);

		let s;
		try {
			s = await W.api('stats');
		} catch (e) {
			W.mount(body, W.empty({ icon: 'alert', title: t('Could not load statistics'), text: e.message }));
			return;
		}

		const content = [];
		content.push(
			h('div', { class: 'brnwh-stats' },
				stat(t('Runs · 24h'), W.num(s.runs_24h), s.queue ? t('%d in queue', s.queue) : t('Queue is empty'), 'activity', 'violet'),
				stat(t('Success rate · 7 days'), s.success_rate === null ? '—' : s.success_rate + '%', s.avg_ms ? t('avg %s', W.fmtMs(s.avg_ms)) : '', 'check', 'green'),
				stat(t('Failed · 24h'), W.num(s.failed_24h), s.failed_24h ? t('Open the logs to retry') : t('All good'), 'alert', s.failed_24h ? 'red' : 'gray'),
				stat(t('Active flows'), s.flows.active + ' / ' + s.flows.total, t('%d connections', s.connections), 'zap', 'amber')
			)
		);

		if (!s.flows.total) {
			content.push(
				h('section', { class: 'brnwh-card brnwh-onboard' },
					h('div', { class: 'brnwh-onboard-text' },
						h('h2', null, t('Build your first automation')),
						h('p', null, t('Replace Zapier and Make with flows that run right here on your site — faster, private and without monthly task limits.')),
						h('ol', { class: 'brnwh-steps-list' },
							h('li', null, h('b', null, t('Connect')), ' ', t('your services (Google, Microsoft 365, Meta…) once.')),
							h('li', null, h('b', null, t('Create a flow:')), ' ', t('pick a trigger, add conditions and actions.')),
							h('li', null, h('b', null, t('Watch it run')), ' ', t('in the logs, with automatic retries.'))
						),
						h('div', { class: 'brnwh-row' }, W.btn(t('Create a flow'), { icon: 'plus', variant: 'primary', onClick: function () { W.navigate('/flows/new'); } }), W.btn(t('Add a connection'), { icon: 'plug', variant: 'soft', onClick: function () { W.navigate('/connections'); } }))
					),
					h('div', { class: 'brnwh-onboard-art' }, W.appIcon('elementor', 44), h('span', { class: 'brnwh-onboard-line' }), W.appIcon('webhook', 54), h('span', { class: 'brnwh-onboard-line' }), W.appIcon('google_sheets', 44))
				)
			);
		}

		content.push(
			h('div', { class: 'brnwh-grid-2' },
				W.card(t('Last 14 days'), chart(s.series)),
				W.card(t('Recent runs'), s.recent.length ? h('div', { class: 'brnwh-run-list' }, s.recent.map(W.runRow)) : h('p', { class: 'brnwh-muted brnwh-pad' }, t('No runs yet.')), { actions: h('a', { href: '#/logs', class: 'brnwh-link' }, t('All logs'), icon(W.boot.rtl ? 'left' : 'right', 14)) })
			)
		);

		content.push(W.card(t('Start from a template'), h('div', { class: 'brnwh-tpl-grid' }, W.templates.map(templateTile))));
		W.mount(body, h('div', null, content));
	};
})();

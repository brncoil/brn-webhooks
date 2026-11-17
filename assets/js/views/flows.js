/**
 * BRN Webhooks — flows list.
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;

	W.flowChain = function (flow, size) {
		const s = size || 30;
		const tr = W.integration('triggers', flow.trigger_type);
		const out = [W.appIcon(tr ? tr.icon : flow.trigger_type || 'zap', s, tr ? tr.label : '?')];
		if (W.conditionsCount(flow.conditions)) {
			out.push(h('span', { class: 'brnwh-chain-arrow' }, icon(W.boot.rtl ? 'left' : 'right', 13)));
			out.push(W.appIcon('filter', Math.round(s * 0.8)));
		}
		(flow.actions || []).forEach(function (a) {
			const ac = W.integration('actions', a.type);
			out.push(h('span', { class: 'brnwh-chain-arrow' }, icon(W.boot.rtl ? 'left' : 'right', 13)));
			out.push(W.appIcon(ac ? ac.icon : a.type, s, ac ? ac.label : a.type));
		});
		return h('div', { class: 'brnwh-chain' }, out);
	};

	W.exportFlow = async function (flow) {
		try {
			const data = await W.api('flows/' + flow.id + '/export');
			const slug = (flow.name || 'flow').toLowerCase().replace(/[^a-z0-9֐-׿]+/g, '-').replace(/^-|-$/g, '');
			W.download('brn-flow-' + (slug || flow.id) + '.json', data);
		} catch (e) {
			W.errorToast(e);
		}
	};

	W.importFlow = async function () {
		const file = await W.pickFile('.json,application/json');
		if (!file) return;
		let data;
		try {
			data = JSON.parse(file.text);
		} catch (e) {
			return W.toast(t('This file is not valid JSON.'), 'error');
		}
		try {
			const flow = await W.api('flows/import', { method: 'POST', body: data });
			W.toast(t('Flow imported (paused). Choose its connections and activate it.'));
			W.navigate('/flows/' + flow.id);
		} catch (e) {
			W.errorToast(e);
		}
	};

	W.deleteFlow = async function (flow, after) {
		const ok = await W.confirm({ title: t('Delete flow?'), message: t('"%s" will be deleted. Its logs are kept until they expire.', flow.name), confirm: t('Delete'), danger: true });
		if (!ok) return;
		try {
			await W.api('flows/' + flow.id, { method: 'DELETE' });
			W.toast(t('Flow deleted'));
			if (after) after();
		} catch (e) {
			W.errorToast(e);
		}
	};

	W.views.flows = async function () {
		let flows = [];
		let filter = 'all';
		let q = '';
		const list = h('div', { class: 'brnwh-flow-list' }, W.skeleton(4));
		const count = h('span', { class: 'brnwh-count' });
		const chips = h('div', { class: 'brnwh-chips' });

		function drawChips() {
			W.clear(chips);
			[['all', t('All')], ['active', t('Active')], ['paused', t('Paused')], ['failing', t('Failing')]].forEach(function (c) {
				chips.appendChild(h('button', { type: 'button', class: ['brnwh-chip', filter === c[0] ? 'is-on' : ''], onClick: function () { filter = c[0]; drawChips(); draw(); } }, c[1]));
			});
		}

		function row(flow) {
			const tr = W.integration('triggers', flow.trigger_type);
			const steps = (flow.actions || []).length;
			const conds = W.conditionsCount(flow.conditions);
			return h(
				'div',
				{ class: ['brnwh-flow-row', flow.status === 'active' ? 'is-active' : ''] },
				h('a', { class: 'brnwh-flow-main', href: '#/flows/' + flow.id },
					W.flowChain(flow, 32),
					h('div', { class: 'brnwh-flow-text' },
						h('strong', null, flow.name),
						h('span', { class: 'brnwh-muted' }, (tr ? tr.label : t('No trigger')) + ' · ' + t('%d steps', steps) + (conds ? ' · ' + t('%d conditions', conds) : ''))
					)
				),
				h('div', { class: 'brnwh-flow-meta' },
					flow.last_status ? W.badge(flow.last_status) : h('span', { class: 'brnwh-muted' }, t('Never ran')),
					h('span', { class: 'brnwh-muted', title: W.fmtDate(flow.last_run_at) }, flow.last_run_at ? W.timeAgo(flow.last_run_at) : ''),
					h('span', { class: 'brnwh-runs', title: t('Total runs') }, icon('activity', 14), W.num(flow.run_count))
				),
				W.toggle({
					checked: flow.status === 'active',
					title: flow.status === 'active' ? t('Active — click to pause') : t('Paused — click to activate'),
					onChange: async function (on, e) {
						try {
							const res = await W.api('flows/' + flow.id + '/status', { method: 'POST', body: { status: on ? 'active' : 'paused' } });
							Object.assign(flow, res);
							W.toast(on ? t('Flow activated') : t('Flow paused'));
							draw();
						} catch (err) {
							e.target.checked = !on;
							W.errorToast(err);
						}
					},
				}),
				W.iconBtn('more', {
					title: t('More'),
					onClick: function (e) {
						W.menu(e.currentTarget, [
							{ label: t('Edit'), icon: 'edit', onClick: function () { W.navigate('/flows/' + flow.id); } },
							{ label: t('View logs'), icon: 'activity', onClick: function () { W.navigate('/logs?flow=' + flow.id); } },
							{
								label: t('Duplicate'),
								icon: 'copy',
								onClick: async function () {
									try {
										const copy = await W.api('flows/' + flow.id + '/duplicate', { method: 'POST' });
										flows.unshift(copy);
										W.toast(t('Flow duplicated'));
										draw();
									} catch (err) {
										W.errorToast(err);
									}
								},
							},
							{ label: t('Export'), icon: 'download', onClick: function () { W.exportFlow(flow); } },
							'-',
							{ label: t('Delete'), icon: 'trash', danger: true, onClick: function () { W.deleteFlow(flow, function () { flows = flows.filter(function (f) { return f.id !== flow.id; }); draw(); }); } },
						]);
					},
				})
			);
		}

		function draw() {
			count.textContent = flows.length ? String(flows.length) : '';
			const shown = flows.filter(function (f) {
				if (filter === 'active' && f.status !== 'active') return false;
				if (filter === 'paused' && f.status !== 'paused') return false;
				if (filter === 'failing' && ['failed', 'partial'].indexOf(f.last_status) < 0) return false;
				if (q && f.name.toLowerCase().indexOf(q) < 0) return false;
				return true;
			});
			if (!flows.length) {
				W.mount(
					list,
					W.empty({
						icon: 'flow',
						title: t('No flows yet'),
						text: t('A flow listens for something (a form, a webhook, an order) and does things with the data.'),
						action: h('div', { class: 'brnwh-row is-center' }, W.btn(t('Create your first flow'), { icon: 'plus', variant: 'primary', onClick: function () { W.navigate('/flows/new'); } }), W.btn(t('Import'), { icon: 'upload', variant: 'ghost', onClick: W.importFlow })),
					})
				);
				return;
			}
			W.mount(list, shown.length ? h('div', null, shown.map(row)) : h('p', { class: 'brnwh-muted brnwh-pad' }, t('No flows match.')));
		}

		drawChips();
		W.page(
			h('div', { class: 'brnwh-page' },
				h('div', { class: 'brnwh-page-head' },
					h('div', null, h('h1', null, t('Flows'), ' ', count), h('p', null, t('Each flow: one trigger → optional conditions → one or more actions.'))),
					h('div', { class: 'brnwh-row' }, W.btn(t('Import'), { icon: 'upload', variant: 'ghost', onClick: W.importFlow }), W.btn(t('New flow'), { icon: 'plus', variant: 'primary', onClick: function () { W.navigate('/flows/new'); } }))
				),
				h('div', { class: 'brnwh-toolbar' },
					h('div', { class: 'brnwh-search' }, icon('search', 16), h('input', { class: 'brnwh-input', type: 'search', placeholder: t('Search flows…'), onInput: W.debounce(function (e) { q = e.target.value.toLowerCase(); draw(); }, 150) })),
					chips
				),
				list
			)
		);

		try {
			flows = await W.api('flows');
			draw();
		} catch (e) {
			W.mount(list, W.empty({ icon: 'alert', title: t('Could not load flows'), text: e.message }));
		}
	};
})();

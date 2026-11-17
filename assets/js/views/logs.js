/**
 * BRN Webhooks — run logs and run details.
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;

	function section(title, content, open) {
		const el = h('details', { class: 'brnwh-acc', open: open ? true : null }, h('summary', null, icon('right', 14), h('span', null, title)), h('div', { class: 'brnwh-acc-body' }, content));
		return el;
	}

	function requestView(r) {
		const code = r.response ? r.response.code : 0;
		const tone = r.error ? 'err' : code >= 200 && code < 300 ? 'ok' : code >= 400 ? 'err' : 'warn';
		return section(
			h('span', { class: 'brnwh-req-sum' }, h('span', { class: 'brnwh-method' }, r.method), h('code', { dir: 'ltr' }, r.url), h('span', { class: 'brnwh-code is-' + tone }, r.error ? t('error') : code), h('span', { class: 'brnwh-muted' }, W.fmtMs(r.duration_ms))),
			h('div', { class: 'brnwh-req' },
				r.error ? h('div', { class: 'brnwh-alert is-error' }, icon('alert', 15), h('span', null, r.error)) : null,
				h('div', { class: 'brnwh-req-col' }, h('h5', null, t('Request')), Object.keys(r.headers || {}).length ? W.jsonView(r.headers, { open: 1, copy: false }) : null, r.body ? W.jsonView(r.body, { open: 2 }) : h('p', { class: 'brnwh-muted' }, t('No body'))),
				r.response ? h('div', { class: 'brnwh-req-col' }, h('h5', null, t('Response')), r.response.body ? W.jsonView(r.response.body, { open: 2 }) : h('p', { class: 'brnwh-muted' }, t('Empty response'))) : null
			)
		);
	}

	function condTable(list) {
		return h('table', { class: 'brnwh-cond-table' },
			h('thead', null, h('tr', null, h('th', null, t('Field')), h('th', null, t('Rule')), h('th', null, t('Expected')), h('th', null, t('Actual')), h('th', null, ''))),
			h('tbody', null, list.map(function (c) {
				const op = W.OPS.find(function (o) { return o[0] === c.op; });
				return h('tr', { class: c.passed ? 'is-pass' : 'is-fail' }, h('td', null, h('code', null, c.field)), h('td', null, op ? t(op[1]) : c.op), h('td', null, c.value), h('td', null, c.actual === '' ? h('span', { class: 'brnwh-muted' }, t('(empty)')) : c.actual), h('td', null, icon(c.passed ? 'check' : 'x', 14)));
			}))
		);
	}

	/** One step of a run. */
	W.renderStep = function (s, inline) {
		const def = s.type === '_filter' ? null : W.integration('actions', s.type);
		const head = h('div', { class: 'brnwh-tl-head' },
			s.type === '_filter' ? W.appIcon('filter', 28) : W.appIcon(def ? def.icon : s.type, 28, s.name),
			h('strong', null, s.position ? t('Step %d', s.position + 1) + ' · ' : '', s.name || (def ? def.label : s.type)),
			W.badge(s.status),
			s.duration_ms !== undefined ? h('span', { class: 'brnwh-muted' }, W.fmtMs(s.duration_ms)) : null
		);
		const body = h('div', { class: 'brnwh-tl-body' },
			s.error ? h('div', { class: 'brnwh-alert is-error' }, icon('alert', 15), h('span', null, s.error)) : null,
			s.note ? h('p', { class: 'brnwh-muted brnwh-small' }, s.note) : null,
			s.conditions && s.conditions.length ? condTable(s.conditions) : null,
			s.output !== undefined && s.output !== null ? section(t('Output'), W.jsonView(s.output, { open: 2 }), inline || s.status === 'success') : null,
			(s.requests || []).length ? h('div', { class: 'brnwh-reqs' }, s.requests.map(requestView)) : null
		);
		return h('div', { class: ['brnwh-tl-item', 'is-' + s.status] }, h('span', { class: 'brnwh-tl-dot' }), head, body);
	};

	/** Whole run timeline. */
	W.renderRun = function (log, o) {
		o = o || {};
		const tr = W.integration('triggers', log.trigger_type);
		const items = [];
		items.push(
			h('div', { class: 'brnwh-tl-item is-success' },
				h('span', { class: 'brnwh-tl-dot' }),
				h('div', { class: 'brnwh-tl-head' }, W.appIcon(tr ? tr.icon : log.trigger_type, 28), h('strong', null, t('Trigger') + ' · ' + (tr ? tr.label : log.trigger_type)), h('span', { class: 'brnwh-muted' }, W.fmtDate(log.created_at))),
				h('div', { class: 'brnwh-tl-body' }, section(t('Data received'), W.jsonView(log.input || {}, { open: 1 }), !o.compact))
			)
		);
		(log.steps || []).forEach(function (s) {
			items.push(W.renderStep(s));
		});
		return h('div', null,
			log.message ? h('div', { class: ['brnwh-alert', log.status === 'success' ? 'is-ok' : log.status === 'filtered' ? 'is-info' : 'is-error'] }, icon(log.status === 'filtered' ? 'filter' : 'alert', 16), h('span', null, log.message)) : null,
			log.status === 'success' && !log.message ? h('div', { class: 'brnwh-alert is-ok' }, icon('check', 16), h('span', null, t('All steps completed successfully.'))) : null,
			h('div', { class: 'brnwh-timeline' }, items)
		);
	};

	W.views.logDetail = async function (id) {
		W.page(h('div', { class: 'brnwh-page' }, W.skeleton(5)));
		let log;
		try {
			log = await W.api('logs/' + id);
		} catch (e) {
			W.page(W.empty({ icon: 'alert', title: t('Run not found'), text: t('It may have been deleted by the log retention policy.'), action: W.btn(t('Back to logs'), { icon: 'arrowLeft', onClick: function () { W.navigate('/logs'); } }) }));
			return;
		}
		const actions = [];
		const replay = W.btn(t('Run again'), {
			icon: 'play',
			variant: 'soft',
			onClick: function () {
				W.busy(replay, async function () {
					try {
						const res = await W.api('logs/' + log.id + '/replay', { method: 'POST' });
						W.toast(t('Started a new run'));
						if (res && res.id) W.navigate('/logs/' + res.id);
					} catch (e) {
						W.errorToast(e);
					}
				});
			},
		});
		if (!log.is_test && log.flow_id) actions.push(replay);
		if (['failed', 'partial', 'retrying'].indexOf(log.status) >= 0 && !log.is_test) {
			const retry = W.btn(t('Retry failed steps'), {
				icon: 'retry',
				variant: 'primary',
				onClick: function () {
					W.busy(retry, async function () {
						try {
							await W.api('logs/' + log.id + '/retry', { method: 'POST' });
							W.toast(t('Retried'));
							W.views.logDetail(log.id);
						} catch (e) {
							W.errorToast(e);
						}
					});
				},
			});
			actions.push(retry);
		}
		actions.push(
			W.iconBtn('trash', {
				title: t('Delete'),
				onClick: async function () {
					if (!(await W.confirm({ title: t('Delete this run?'), confirm: t('Delete'), danger: true }))) return;
					await W.api('logs/' + log.id, { method: 'DELETE' });
					W.navigate('/logs');
				},
			})
		);
		const meta = h('div', { class: 'brnwh-run-meta' },
			W.badge(log.status),
			log.is_test ? h('span', { class: 'brnwh-tag' }, t('test')) : null,
			h('span', null, icon('clock', 14), W.fmtDate(log.created_at)),
			h('span', null, icon('zap', 14), W.fmtMs(log.duration_ms)),
			log.attempts > 1 ? h('span', null, icon('retry', 14), t('%d attempts', log.attempts)) : null,
			log.next_attempt_at ? h('span', null, icon('clock', 14), t('next retry %s', W.timeAgo(log.next_attempt_at))) : null,
			log.meta && log.meta.ip ? h('span', { dir: 'ltr' }, 'IP ' + log.meta.ip) : null
		);
		W.page(
			h('div', { class: 'brnwh-page' },
				h('div', { class: 'brnwh-page-head' },
					h('div', null,
						h('a', { class: 'brnwh-back', href: '#/logs' }, icon(W.boot.rtl ? 'right' : 'left', 14), t('Logs')),
						h('h1', null, t('Run #%d', log.id), ' · ', log.flow_id ? h('a', { href: '#/flows/' + log.flow_id }, log.flow_name) : log.flow_name),
						meta
					),
					h('div', { class: 'brnwh-row' }, actions)
				),
				h('div', { class: 'brnwh-card brnwh-card-body' }, W.renderRun(log))
			)
		);
	};

	W.views.logs = async function (query) {
		query = query || {};
		const state = { page: 1, status: '', flow_id: query.flow || '', search: '', auto: false };
		let timer = null;
		const tableWrap = h('div', null, W.skeleton(6));
		const pager = h('div', { class: 'brnwh-pager' });
		let flows = [];
		try {
			flows = await W.api('flows');
		} catch (e) {
			flows = [];
		}
		const flowSel = h('select', { class: 'brnwh-input is-sm', onChange: function (e) { state.flow_id = e.target.value; state.page = 1; load(); } }, h('option', { value: '' }, t('All flows')), flows.map(function (f) { return h('option', { value: String(f.id) }, f.name); }));
		flowSel.value = String(state.flow_id);
		const statusSel = h('select', { class: 'brnwh-input is-sm', onChange: function (e) { state.status = e.target.value; state.page = 1; load(); } },
			[['', t('All statuses')], ['success', t('Success')], ['failed,partial', t('Failed')], ['retrying,queued,running', t('In progress')], ['filtered', t('Filtered')]].map(function (o) { return h('option', { value: o[0] }, o[1]); })
		);

		async function load() {
			try {
				const res = await W.api('logs', { query: { page: state.page, per_page: 30, status: state.status, flow_id: state.flow_id, search: state.search } });
				draw(res);
			} catch (e) {
				W.mount(tableWrap, W.empty({ icon: 'alert', title: t('Could not load logs'), text: e.message }));
			}
		}

		function draw(res) {
			if (!res.items.length) {
				W.mount(tableWrap, W.empty({ icon: 'activity', title: t('No runs to show'), text: state.status || state.flow_id || state.search ? t('Try other filters.') : t('When a flow runs, every run appears here with its data, each step and the API responses.') }));
				W.clear(pager);
				return;
			}
			W.mount(tableWrap, h('div', { class: 'brnwh-card brnwh-run-list is-table' }, res.items.map(function (r) {
				const row = W.runRow(r);
				row.appendChild(h('span', { class: 'brnwh-muted brnwh-run-dur' }, W.fmtMs(r.duration_ms)));
				if (r.message && r.status !== 'success') row.title = r.message;
				return row;
			})));
			W.clear(pager);
			if (res.pages > 1) {
				W.append(pager, [
					W.btn('', { icon: W.boot.rtl ? 'right' : 'left', size: 'sm', variant: 'ghost', disabled: state.page <= 1, aria: t('Previous'), onClick: function () { state.page--; load(); } }),
					h('span', { class: 'brnwh-muted' }, t('Page %1$d of %2$d', state.page, res.pages) + ' · ' + t('%d runs', res.total)),
					W.btn('', { icon: W.boot.rtl ? 'left' : 'right', size: 'sm', variant: 'ghost', disabled: state.page >= res.pages, aria: t('Next'), onClick: function () { state.page++; load(); } }),
				]);
			} else {
				pager.appendChild(h('span', { class: 'brnwh-muted' }, t('%d runs', res.total)));
			}
		}

		function setAuto(on) {
			state.auto = on;
			if (timer) clearInterval(timer);
			timer = on ? setInterval(load, 5000) : null;
		}
		W.onLeave = function () {
			if (timer) clearInterval(timer);
		};

		W.page(
			h('div', { class: 'brnwh-page' },
				h('div', { class: 'brnwh-page-head' },
					h('div', null, h('h1', null, t('Logs')), h('p', null, t('Every run with its input, conditions, each step and the raw API calls.'))),
					h('div', { class: 'brnwh-row' },
						W.toggle({ checked: false, label: t('Live'), onChange: setAuto }),
						W.iconBtn('refresh', { title: t('Refresh'), onClick: load }),
						W.btn(t('Clear'), {
							icon: 'trash',
							variant: 'ghost',
							onClick: async function () {
								const scoped = state.flow_id || state.status;
								const ok = await W.confirm({ title: scoped ? t('Clear the runs matching the filters?') : t('Clear all logs?'), message: t('Queued and retrying runs are kept.'), confirm: t('Clear'), danger: true });
								if (!ok) return;
								try {
									const res = await W.api('logs', { method: 'DELETE', query: { flow_id: state.flow_id, status: state.status } });
									W.toast(t('%d runs deleted', res.deleted));
									state.page = 1;
									load();
								} catch (e) {
									W.errorToast(e);
								}
							},
						})
					)
				),
				h('div', { class: 'brnwh-toolbar' },
					h('div', { class: 'brnwh-search' }, icon('search', 16), h('input', { class: 'brnwh-input', type: 'search', placeholder: t('Search in data, errors…'), onInput: W.debounce(function (e) { state.search = e.target.value; state.page = 1; load(); }, 350) })),
					flowSel,
					statusSel
				),
				tableWrap,
				pager
			)
		);
		load();
	};
})();

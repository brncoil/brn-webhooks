/**
 * BRN Webhooks — connections (credentials for Google, Microsoft 365, Meta…).
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;

	/** Modal to create / edit a connection. */
	function openEditor(type, existing, onSaved) {
		const types = (W.state.integrations && W.state.integrations.connections) || [];
		let current = type ? types.find(function (c) { return c.id === type; }) : null;
		if (existing) current = types.find(function (c) { return c.id === existing.type; });

		const m = W.modal({
			title: existing ? t('Edit connection') : t('New connection'),
			size: 'lg',
			body: h('div'),
			footer: h('div'),
		});

		function chooser() {
			W.mount(m.body, h('div', { class: 'brnwh-conn-types' }, types.map(function (c) {
				return h('button', { type: 'button', class: ['brnwh-app-tile', c.available ? '' : 'is-dim'], onClick: function () { if (!c.available) return W.toast(c.reason, 'info'); current = c; form(); } }, W.appIcon(c.icon, 38, c.label), h('span', { class: 'brnwh-app-tile-text' }, h('strong', null, c.label), h('span', null, c.available ? '' : c.reason)));
			})));
			W.clear(m.foot);
			m.foot.appendChild(W.btn(t('Cancel'), { variant: 'ghost', onClick: function () { m.close(); } }));
		}

		function form() {
			const values = existing ? W.clone(existing.credentials) : {};
			let name = existing ? existing.name : current.label;
			const result = h('div', { class: 'brnwh-conn-result' });
			const nameInput = h('input', { class: 'brnwh-input', value: name, onInput: function (e) { name = e.target.value; } });
			const fields = W.renderFields(current.fields, values, { kind: 'connection', integration: current.id, values: function () { return values; } });
			W.mount(
				m.body,
				h('div', { class: 'brnwh-conn-form' },
					h('div', { class: 'brnwh-conn-main' },
						h('div', { class: 'brnwh-conn-type' }, W.appIcon(current.icon, 40, current.label), h('div', null, h('strong', null, current.label), !existing ? h('button', { type: 'button', class: 'brnwh-link', onClick: chooser }, t('Choose another service')) : null)),
						h('div', { class: 'brnwh-field' }, h('label', { class: 'brnwh-label' }, t('Connection name')), nameInput, h('p', { class: 'brnwh-help' }, t('E.g. "Sales Google account" — shown when choosing a connection in a flow.'))),
						fields,
						h('div', { class: 'brnwh-note is-secure' }, icon('lock', 15), h('span', null, t('Credentials are encrypted on your server and never shown again.'))),
						result
					),
					current.help ? h('aside', { class: 'brnwh-conn-help' }, h('h4', null, icon('book', 15), ' ', t('How to set it up')), h('div', { html: current.help })) : null
				)
			);
			W.clear(m.foot);
			const saveBtn = W.btn(existing ? t('Save & test') : t('Connect & test'), {
				icon: 'plug',
				variant: 'primary',
				onClick: function () {
					W.busy(saveBtn, async function () {
						W.clear(result);
						try {
							const res = await W.api(existing ? 'connections/' + existing.id : 'connections', { method: existing ? 'PUT' : 'POST', body: { name: name, type: current.id, credentials: values } });
							await W.refreshConnections();
							existing = res.connection;
							if (res.test.ok) {
								W.toast(res.test.message);
								m.close();
								if (onSaved) onSaved(res.connection);
							} else {
								W.append(result, [h('div', { class: 'brnwh-alert is-error' }, icon('alert', 16), h('div', null, h('strong', null, t('Saved, but the test failed:')), h('div', null, res.test.message)))]);
								if (onSaved) onSaved(res.connection);
							}
						} catch (e) {
							W.append(result, [h('div', { class: 'brnwh-alert is-error' }, icon('alert', 16), h('span', null, e.message))]);
						}
					});
				},
			});
			W.append(m.foot, [W.btn(t('Cancel'), { variant: 'ghost', onClick: function () { m.close(); } }), saveBtn]);
		}

		if (current) form();
		else chooser();
	}

	W.views.connections = async function () {
		const list = h('div', null, W.skeleton(3));
		W.page(
			h('div', { class: 'brnwh-page' },
				h('div', { class: 'brnwh-page-head' },
					h('div', null, h('h1', null, t('Connections')), h('p', null, t('Connect a service once, use it in any flow. Secrets are encrypted at rest.'))),
					W.btn(t('New connection'), { icon: 'plus', variant: 'primary', onClick: function () { openEditor(null, null, draw); } })
				),
				list
			)
		);

		function connCard(c) {
			const type = W.integration('connections', c.type);
			const status = h('span', null, W.badge(c.unreadable ? 'error' : c.status));
			const testBtn = W.btn(t('Test'), {
				icon: 'zap',
				size: 'sm',
				variant: 'soft',
				onClick: function () {
					W.busy(testBtn, async function () {
						try {
							const res = await W.api('connections/' + c.id + '/test', { method: 'POST' });
							W.toast(res.message, res.ok ? 'success' : 'error');
							W.mount(status, W.badge(res.ok ? 'ok' : 'error'));
						} catch (e) {
							W.errorToast(e);
						}
					});
				},
			});
			return h('div', { class: 'brnwh-conn-card' },
				W.appIcon(type ? type.icon : c.type, 42, type ? type.label : c.type),
				h('div', { class: 'brnwh-conn-text' },
					h('strong', null, c.name),
					h('span', { class: 'brnwh-muted' }, (type ? type.label : c.type) + (c.summary ? ' · ' + c.summary : '')),
					h('span', { class: 'brnwh-conn-meta' }, status, h('span', { class: 'brnwh-muted' }, c.usage ? t('Used by %d flows', c.usage) : t('Not used yet')), c.last_tested_at ? h('span', { class: 'brnwh-muted', title: W.fmtDate(c.last_tested_at) }, t('tested %s', W.timeAgo(c.last_tested_at))) : null),
					c.unreadable ? h('span', { class: 'brnwh-err brnwh-small' }, t('Cannot decrypt — the site keys changed. Edit and re-enter the credentials.')) : null
				),
				h('div', { class: 'brnwh-conn-actions' },
					testBtn,
					W.iconBtn('edit', { title: t('Edit'), onClick: function () { openEditor(null, c, draw); } }),
					W.iconBtn('trash', {
						title: t('Delete'),
						onClick: async function () {
							const ok = await W.confirm({ title: t('Delete connection?'), message: c.usage ? t('%d flows use this connection and will fail until you choose another one.', c.usage) : t('"%s" will be deleted.', c.name), confirm: t('Delete'), danger: true });
							if (!ok) return;
							try {
								await W.api('connections/' + c.id, { method: 'DELETE' });
								await W.refreshConnections();
								W.toast(t('Connection deleted'));
								draw();
							} catch (e) {
								W.errorToast(e);
							}
						},
					})
				)
			);
		}

		async function draw() {
			let conns;
			try {
				conns = await W.refreshConnections();
			} catch (e) {
				W.mount(list, W.empty({ icon: 'alert', title: t('Could not load connections'), text: e.message }));
				return;
			}
			const types = W.state.integrations.connections;
			const services = h('div', { class: 'brnwh-services' }, types.map(function (c) {
				return h('button', { type: 'button', class: ['brnwh-service', c.available ? '' : 'is-dim'], title: c.available ? '' : c.reason, onClick: function () { if (c.available) openEditor(c.id, null, draw); else W.toast(c.reason, 'info'); } }, W.appIcon(c.icon, 34, c.label), h('span', null, c.label), icon('plus', 15));
			}));
			W.mount(list, h('div', null,
				conns.length ? h('div', { class: 'brnwh-conn-list' }, conns.map(connCard)) : W.empty({ icon: 'plug', title: t('No connections yet'), text: t('Add the services you want your flows to talk to. Incoming webhooks, email and plain HTTP requests need no connection.') }),
				W.card(t('Available services'), services)
			));
		}
		draw();
	};

	W.views.connections.openEditor = openEditor;
})();

/**
 * BRN Webhooks — flow editor (trigger → filter → actions).
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;

	const SYSTEM_VARS = [
		['_all_table', 'All fields as an HTML table'],
		['_all_text', 'All fields as text lines'],
		['_all', 'All fields as JSON'],
		['_site.name', 'Site name'],
		['_site.url', 'Site URL'],
		['_now', 'Current date & time (ISO)'],
		['_date', 'Today (Y-m-d)'],
		['_time', 'Current time'],
		['_run.id', 'Run ID'],
		['_flow.name', 'Flow name'],
	];

	function emptyConditions() {
		return { logic: 'or', groups: [] };
	}

	function autoConnection(type) {
		const def = W.integration('actions', type);
		if (!def || !def.connection_type) return 0;
		const list = (W.state.connections || []).filter(function (c) { return c.type === def.connection_type; });
		return list.length === 1 ? list[0].id : 0;
	}

	function newAction(type, config) {
		const a = { id: W.uid(), type: type || '', name: '', connection_id: type ? autoConnection(type) : 0, config: Object.assign({}, config || {}), enabled: true, continue_on_error: false, conditions: emptyConditions() };
		const def = W.integration('actions', type);
		if (def) W.defaultsFor(def.fields, a.config);
		return a;
	}

	function blankFlow(templateId) {
		const s = W.state.settings || {};
		const f = { id: 0, name: '', status: 'paused', trigger_type: '', trigger_config: {}, conditions: emptyConditions(), actions: [], settings: { async: true, retries: s.default_retries !== undefined ? s.default_retries : 3, log: 'default' }, sample: null, endpoint: '' };
		const tpl = (W.templates || []).find(function (x) { return x.id === templateId; });
		if (tpl) {
			f.name = t(tpl.title);
			f.trigger_type = tpl.trigger;
			f.trigger_config = Object.assign({}, tpl.triggerConfig || {});
			const tr = W.integration('triggers', tpl.trigger);
			if (tr) W.defaultsFor(tr.fields, f.trigger_config);
			f.actions = tpl.actions.map(function (a) { return newAction(a.type, a.config); });
		}
		return f;
	}

	/** Grid of apps to choose a trigger / action from. */
	function appPicker(kind, onPick) {
		const items = (W.state.integrations && W.state.integrations[kind]) || [];
		const box = h('div', { class: 'brnwh-apps' });
		const search = h('input', { class: 'brnwh-input', type: 'search', placeholder: kind === 'triggers' ? t('Search triggers…') : t('Search actions…') });
		function draw(q) {
			W.clear(box);
			const groups = {};
			items.forEach(function (it) {
				if (q && (it.label + ' ' + it.description + ' ' + it.group).toLowerCase().indexOf(q) < 0) return;
				(groups[it.group || ''] = groups[it.group || ''] || []).push(it);
			});
			Object.keys(groups).forEach(function (g) {
				box.appendChild(h('div', { class: 'brnwh-apps-group' }, g));
				box.appendChild(
					h('div', { class: 'brnwh-apps-grid' }, groups[g].map(function (it) {
						return h(
							'button',
							{ type: 'button', class: ['brnwh-app-tile', it.available ? '' : 'is-dim'], title: it.available ? it.description : it.reason, onClick: function () { if (!it.available) { W.toast(it.reason, 'info'); return; } onPick(it.id); } },
							W.appIcon(it.icon, 38, it.label),
							h('span', { class: 'brnwh-app-tile-text' }, h('strong', null, it.label), h('span', null, it.available ? it.description : it.reason))
						);
					}))
				);
			});
			if (!box.children.length) box.appendChild(h('p', { class: 'brnwh-muted brnwh-pad' }, t('Nothing found.')));
		}
		search.addEventListener('input', function () { draw(search.value.toLowerCase()); });
		draw('');
		setTimeout(function () { search.focus(); }, 50);
		return h('div', { class: 'brnwh-picker-panel' }, h('div', { class: 'brnwh-search' }, icon('search', 16), search), box);
	}

	W.views.editor = async function (id, templateId) {
		let flow;
		let dirty = false;
		let sampleDirty = false;
		let listening = false;
		let pollTimer = null;
		let knownFields = {};
		let stepOutputs = {};
		const collapsed = new Set();
		const stepResults = {};
		let showFilter = false;
		const showStepCond = new Set();

		W.page(h('div', { class: 'brnwh-page' }, W.skeleton(6)));
		try {
			flow = id ? await W.api('flows/' + id) : blankFlow(templateId);
		} catch (e) {
			W.page(W.empty({ icon: 'alert', title: t('Flow not found'), text: e.message, action: W.btn(t('Back to flows'), { icon: 'arrowLeft', onClick: function () { W.navigate('/flows'); } }) }));
			return;
		}
		flow.conditions = flow.conditions || emptyConditions();
		flow.actions.forEach(function (a) {
			a.conditions = a.conditions || emptyConditions();
			a.config = a.config || {};
			if (W.conditionsCount(a.conditions)) showStepCond.add(a.id);
		});
		stepOutputs = (flow.sample && flow.sample.steps) || {};
		if (W.conditionsCount(flow.conditions)) showFilter = true;
		if (templateId && flow.trigger_type) dirty = true;

		/* ---------------------------------------------------------- helpers */

		const saveBtn = W.btn(t('Save'), { icon: 'save', variant: 'primary', onClick: function () { save(); } });
		const savedHint = h('span', { class: 'brnwh-save-hint' });
		const nameInput = h('input', { class: 'brnwh-name-input', value: flow.name, placeholder: t('Name your flow'), 'aria-label': t('Flow name'), onInput: function (e) { flow.name = e.target.value; markDirty(); } });
		const statusToggle = W.toggle({ checked: flow.status === 'active', label: '', onChange: function (on, e) { setStatus(on, e.target); } });
		const statusLabel = h('span', { class: 'brnwh-status-label' });
		const canvas = h('div', { class: 'brnwh-canvas' });
		const side = h('aside', { class: 'brnwh-side' });

		function markDirty() {
			dirty = true;
			updateSaveState();
		}
		function updateSaveState() {
			saveBtn.classList.toggle('is-pulse', dirty);
			savedHint.textContent = dirty ? t('Unsaved changes') : flow.id ? t('All changes saved') : '';
			savedHint.classList.toggle('is-dirty', dirty);
			statusLabel.textContent = flow.status === 'active' ? t('Active') : t('Paused');
			statusLabel.classList.toggle('is-on', flow.status === 'active');
		}

		W.router.guard = function (silent) {
			if (!dirty) return true;
			return silent ? false : window.confirm(t('You have unsaved changes. Leave anyway?'));
		};
		function onKey(e) {
			if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
				e.preventDefault();
				save();
			}
		}
		document.addEventListener('keydown', onKey);
		W.onLeave = function () {
			document.removeEventListener('keydown', onKey);
			stopListening();
		};

		function sampleData() {
			return (flow.sample && flow.sample.data) || {};
		}

		function sampleKeys() {
			const keys = Object.keys(sampleData()).filter(function (k) { return k.charAt(0) !== '_'; });
			Object.keys(knownFields).forEach(function (k) {
				if (k.charAt(0) !== '_' && keys.indexOf(k) < 0) keys.push(k);
			});
			return keys;
		}

		function pickerGroups(beforeIndex) {
			const flat = W.flatten(sampleData()).filter(function (x) { return x.path.indexOf('_request.headers') !== 0 && x.path !== '_lead_fetched'; });
			const items = flat.map(function (x) { return { path: x.path, value: x.value }; });
			Object.keys(knownFields).forEach(function (k) {
				const hit = items.find(function (it) { return it.path === k; });
				if (hit) hit.label = knownFields[k] !== k ? knownFields[k] : undefined;
				else items.push({ path: k, label: knownFields[k] });
			});
			const labels = sampleData()._labels || {};
			items.forEach(function (it) {
				if (!it.label && labels[it.path]) it.label = labels[it.path];
			});
			const groups = [
				{ label: t('Trigger data'), items: items.filter(function (it) { return it.path.charAt(0) !== '_'; }) },
				{ label: t('Request & form info'), items: items.filter(function (it) { return it.path.charAt(0) === '_' && it.path.indexOf('_labels') !== 0; }) },
			];
			for (let pos = 1; pos <= beforeIndex; pos++) {
				const a = flow.actions[pos - 1];
				if (!a) continue;
				const def = W.integration('actions', a.type);
				const out = stepOutputs[pos];
				const name = t('Step %d', pos + 1) + ' · ' + (a.name || (def ? def.label : a.type));
				if (out !== undefined && out !== null) {
					groups.push({ label: name, items: W.flatten(out, '_steps.' + pos).map(function (x) { return { path: x.path, value: x.value }; }) });
				} else {
					groups.push({ label: name, items: [{ path: '_steps.' + pos, label: t('Output (test this step to see its fields)') }] });
				}
			}
			groups.push({ label: t('System'), items: SYSTEM_VARS.map(function (v) { return { path: v[0], label: t(v[1]) }; }) });
			return groups;
		}

		const loadKnown = W.debounce(async function () {
			if (!flow.trigger_type) return;
			try {
				knownFields = (await W.api('fields', { method: 'POST', body: { trigger: flow.trigger_type, config: flow.trigger_config } })) || {};
			} catch (e) {
				knownFields = {};
			}
		}, 400);

		function autoName() {
			if (flow.name.trim()) return;
			const tr = W.integration('triggers', flow.trigger_type);
			const ac = flow.actions[0] && W.integration('actions', flow.actions[0].type);
			if (tr && ac) {
				flow.name = tr.label + ' → ' + ac.label;
				nameInput.value = flow.name;
			}
		}

		/* -------------------------------------------------------- save etc. */

		function validate(forActivate) {
			if (!flow.trigger_type) return t('Choose a trigger first.');
			if (forActivate) {
				const real = flow.actions.filter(function (a) { return a.type; });
				if (!real.length) return t('Add at least one action before activating.');
				for (let i = 0; i < real.length; i++) {
					const def = W.integration('actions', real[i].type);
					if (def && def.connection_type && !def.connection_optional && !real[i].connection_id) {
						return t('Step %d needs a connection.', flow.actions.indexOf(real[i]) + 2);
					}
				}
			}
			return '';
		}

		function payload() {
			const body = {
				name: flow.name,
				status: flow.status,
				trigger_type: flow.trigger_type,
				trigger_config: flow.trigger_config,
				conditions: flow.conditions,
				actions: flow.actions.filter(function (a) { return a.type; }),
				settings: flow.settings,
			};
			if (sampleDirty) body.sample = flow.sample || stepOutputs ? Object.assign({}, flow.sample || {}, { steps: stepOutputs }) : null;
			return body;
		}

		async function save(quiet) {
			const err = validate(flow.status === 'active');
			if (err) {
				W.toast(err, 'error');
				return false;
			}
			let ok = false;
			await W.busy(saveBtn, async function () {
				try {
					const wasNew = !flow.id;
					const hadEndpoint = !!flow.endpoint;
					const res = flow.id ? await W.api('flows/' + flow.id, { method: 'PUT', body: payload() }) : await W.api('flows', { method: 'POST', body: payload() });
					flow.id = res.id;
					flow.endpoint = res.endpoint;
					flow.status = res.status;
					flow.actions = res.actions.map(function (a, i) {
						const local = flow.actions.filter(function (x) { return x.type; })[i];
						return Object.assign(a, { config: local ? local.config : a.config });
					});
					dirty = false;
					sampleDirty = false;
					ok = true;
					if (wasNew) history.replaceState(null, '', '#/flows/' + flow.id);
					if (!quiet) W.toast(t('Flow saved'));
					if (!hadEndpoint && flow.endpoint) drawCanvas();
					drawSide();
				} catch (e) {
					W.errorToast(e);
				}
			});
			updateSaveState();
			return ok;
		}

		async function setStatus(on, input) {
			const prev = flow.status;
			flow.status = on ? 'active' : 'paused';
			const err = validate(on);
			if (err) {
				flow.status = prev;
				input.checked = prev === 'active';
				W.toast(err, 'error');
				return;
			}
			const ok = await save(true);
			if (!ok) {
				flow.status = prev;
				input.checked = prev === 'active';
			} else {
				W.toast(on ? t('Flow is live 🚀') : t('Flow paused'));
			}
			updateSaveState();
		}

		/* -------------------------------------------------------- canvas */

		function stepHead(num, iconEl, title, sub, actions, onToggle) {
			return h(
				'header',
				{ class: 'brnwh-step-head', onClick: onToggle ? function (e) { if (!e.target.closest('button, input, label, a, select')) onToggle(); } : null },
				h('span', { class: 'brnwh-step-num' }, num),
				iconEl,
				h('div', { class: 'brnwh-step-titles' }, h('span', { class: 'brnwh-step-kicker' }, sub), h('strong', null, title)),
				h('div', { class: 'brnwh-step-actions' }, actions || [])
			);
		}

		function connector(content) {
			return h('div', { class: 'brnwh-connector' }, h('span', { class: 'brnwh-connector-line' }), content || null);
		}

		function triggerCard() {
			const tr = W.integration('triggers', flow.trigger_type);
			const card = h('section', { class: ['brnwh-step', 'is-trigger', !tr ? 'is-picking' : ''] });
			if (!tr) {
				W.append(card, [stepHead('1', h('span', { class: 'brnwh-step-ph' }, icon('zap', 18)), t('Choose what starts this flow'), t('Trigger')), h('div', { class: 'brnwh-step-body' }, appPicker('triggers', function (tid) {
					flow.trigger_type = tid;
					flow.trigger_config = {};
					const def = W.integration('triggers', tid);
					if (def) W.defaultsFor(def.fields, flow.trigger_config);
					markDirty();
					autoName();
					loadKnown();
					drawAll();
				}))]);
				return card;
			}
			const isOpen = !collapsed.has('trigger');
			const actions = [
				W.btn(t('Change'), {
					size: 'sm',
					variant: 'ghost',
					onClick: async function () {
						const ok = !flow.trigger_type || (await W.confirm({ title: t('Change trigger?'), message: t('The trigger settings will be reset. Your actions are kept.'), confirm: t('Change') }));
						if (!ok) return;
						flow.trigger_type = '';
						flow.trigger_config = {};
						markDirty();
						drawAll();
					},
				}),
				W.iconBtn(isOpen ? 'up' : 'down', { title: isOpen ? t('Collapse') : t('Expand'), onClick: function () { toggleCollapse('trigger'); } }),
			];
			W.append(card, [stepHead('1', W.appIcon(tr.icon, 34, tr.label), tr.label, t('When this happens'), actions, function () { toggleCollapse('trigger'); })]);
			if (isOpen) {
				const body = h('div', { class: 'brnwh-step-body' });
				if (!tr.available) body.appendChild(h('div', { class: 'brnwh-alert is-warn' }, icon('alert', 16), h('span', null, tr.reason)));
				if (tr.description) body.appendChild(h('p', { class: 'brnwh-step-desc' }, tr.description));
				const form = W.renderFields(tr.fields, flow.trigger_config, {
					kind: 'trigger',
					integration: tr.id,
					values: function () { return flow.trigger_config; },
					connectionId: function () { return flow.trigger_config.connection_id || 0; },
					onChange: function (key) {
						markDirty();
						if (key === 'form_id' || key === 'connection_id') loadKnown();
					},
					pickerGroups: function () { return pickerGroups(0); },
					endpoint: function () { return flow.endpoint; },
					onRotate: flow.id
						? async function () {
								const ok = await W.confirm({ title: t('Generate a new URL?'), message: t('The current URL stops working immediately. Update it everywhere it is used.'), confirm: t('Generate'), danger: true });
								if (!ok) return;
								try {
									const res = await W.api('flows/' + flow.id + '/rotate-key', { method: 'POST' });
									flow.endpoint = res.endpoint;
									W.toast(t('New URL generated'));
									drawCanvas();
								} catch (e) {
									W.errorToast(e);
								}
						  }
						: null,
				});
				body.appendChild(form);
				card.appendChild(body);
			}
			return card;
		}

		function filterCard() {
			const n = W.conditionsCount(flow.conditions);
			if (!n && !showFilter) {
				return connector(h('button', { type: 'button', class: 'brnwh-connector-add', onClick: function () { showFilter = true; if (!flow.conditions.groups.length) flow.conditions.groups.push({ logic: 'and', rules: [{ field: '', op: 'equals', value: '' }] }); drawCanvas(); } }, icon('filter', 14), h('span', null, t('Add filter'))));
			}
			const card = h('section', { class: 'brnwh-step is-filter' });
			W.append(card, [
				stepHead(
					h('span', null, icon('filter', 14)),
					W.appIcon('filter', 30),
					t('Only continue if…'),
					t('Filter'),
					[W.iconBtn('trash', { title: t('Remove filter'), onClick: function () { flow.conditions = emptyConditions(); showFilter = false; markDirty(); drawCanvas(); } })]
				),
				h('div', { class: 'brnwh-step-body' }, W.conditionsBuilder(flow.conditions, { pickerGroups: function () { return pickerGroups(0); } }, function () { markDirty(); })),
			]);
			return h('div', null, connector(), card);
		}

		function toggleCollapse(key) {
			if (collapsed.has(key)) collapsed.delete(key);
			else collapsed.add(key);
			drawCanvas();
		}

		function moveAction(i, dir) {
			const j = i + dir;
			if (j < 0 || j >= flow.actions.length) return;
			const tmp = flow.actions[i];
			flow.actions[i] = flow.actions[j];
			flow.actions[j] = tmp;
			stepOutputs = {};
			sampleDirty = true;
			markDirty();
			drawCanvas();
		}

		function actionCard(a, i) {
			const def = W.integration('actions', a.type);
			const pos = i + 1;
			const card = h('section', { class: ['brnwh-step', 'is-action', !a.enabled ? 'is-disabled' : '', !def ? 'is-picking' : ''], dataset: { id: a.id } });
			const num = String(i + 2);
			if (!def) {
				W.append(card, [
					stepHead(num, h('span', { class: 'brnwh-step-ph' }, icon('plus', 18)), t('Choose an action'), t('Then do this'), [W.iconBtn('trash', { title: t('Remove step'), onClick: function () { flow.actions.splice(i, 1); markDirty(); drawCanvas(); } })]),
					h('div', { class: 'brnwh-step-body' }, appPicker('actions', function (type) {
						const fresh = newAction(type);
						a.type = type;
						a.config = fresh.config;
						a.connection_id = fresh.connection_id;
						markDirty();
						autoName();
						drawCanvas();
					})),
				]);
				return card;
			}
			const isOpen = !collapsed.has(a.id);
			const title = a.name || def.label;
			const menuItems = function () {
				return [
					{ label: t('Rename'), icon: 'edit', onClick: async function () { const v = await W.prompt({ title: t('Rename step'), value: a.name || def.label }); if (v !== null) { a.name = v.trim(); markDirty(); drawCanvas(); } } },
					{ label: showStepCond.has(a.id) ? t('Remove step conditions') : t('Only run this step if…'), icon: 'branch', onClick: function () { if (showStepCond.has(a.id)) { showStepCond.delete(a.id); a.conditions = emptyConditions(); } else { showStepCond.add(a.id); if (!a.conditions.groups.length) a.conditions.groups.push({ logic: 'and', rules: [{ field: '', op: 'equals', value: '' }] }); collapsed.delete(a.id); } markDirty(); drawCanvas(); } },
					{ label: a.continue_on_error ? t('Stop the flow if this step fails') : t('Continue even if this step fails'), icon: 'shield', onClick: function () { a.continue_on_error = !a.continue_on_error; markDirty(); drawCanvas(); } },
					'-',
					i > 0 ? { label: t('Move up'), icon: 'up', onClick: function () { moveAction(i, -1); } } : null,
					i < flow.actions.length - 1 ? { label: t('Move down'), icon: 'down', onClick: function () { moveAction(i, 1); } } : null,
					{ label: t('Duplicate'), icon: 'copy', onClick: function () { const c = W.clone(a); c.id = W.uid(); c.name = a.name ? a.name + ' 2' : ''; flow.actions.splice(i + 1, 0, c); markDirty(); drawCanvas(); } },
					{ label: t('Change action type'), icon: 'refresh', onClick: function () { a.type = ''; a.config = {}; markDirty(); drawCanvas(); } },
					'-',
					{ label: t('Delete step'), icon: 'trash', danger: true, onClick: async function () { if (await W.confirm({ title: t('Delete this step?'), message: title, confirm: t('Delete'), danger: true })) { flow.actions.splice(i, 1); delete stepOutputs[pos]; markDirty(); drawCanvas(); } } },
				];
			};
			const result = stepResults[a.id];
			const headActions = [
				result ? W.badge(result.status) : null,
				a.continue_on_error ? h('span', { class: 'brnwh-tag', title: t('Continues even if this step fails') }, icon('shield', 12)) : null,
				W.conditionsCount(a.conditions) ? h('span', { class: 'brnwh-tag', title: t('Has step conditions') }, icon('branch', 12)) : null,
				W.toggle({ checked: a.enabled, small: true, title: a.enabled ? t('Step enabled') : t('Step disabled'), onChange: function (on) { a.enabled = on; markDirty(); card.classList.toggle('is-disabled', !on); } }),
				W.iconBtn('more', { title: t('Step options'), onClick: function (e) { W.menu(e.currentTarget, menuItems()); } }),
				W.iconBtn(isOpen ? 'up' : 'down', { title: isOpen ? t('Collapse') : t('Expand'), onClick: function () { toggleCollapse(a.id); } }),
			];
			card.appendChild(stepHead(num, W.appIcon(def.icon, 34, def.label), title, t('Then do this'), headActions, function () { toggleCollapse(a.id); }));
			if (!isOpen) return card;

			const body = h('div', { class: 'brnwh-step-body' });
			if (!def.available) body.appendChild(h('div', { class: 'brnwh-alert is-warn' }, icon('alert', 16), h('span', null, def.reason)));
			const fields = [];
			if (def.connection_type) {
				const ct = W.integration('connections', def.connection_type);
				fields.push({ key: 'connection_id', type: 'connection', connection_type: def.connection_type, label: def.connection_optional ? t('Credentials (optional)') : (ct ? ct.label : t('Connection')) });
			}
			a.config.connection_id = a.connection_id || 0;
			const form = W.renderFields(fields.concat(def.fields), a.config, {
				kind: 'action',
				integration: def.id,
				values: function () { return a.config; },
				connectionId: function () { return a.connection_id || 0; },
				onChange: function (key) {
					if (key === 'connection_id') a.connection_id = a.config.connection_id || 0;
					markDirty();
				},
				pickerGroups: function () { return pickerGroups(i); },
				sampleKeys: sampleKeys,
			});
			body.appendChild(form);

			if (showStepCond.has(a.id)) {
				body.appendChild(
					h('div', { class: 'brnwh-step-cond' },
						h('div', { class: 'brnwh-field-heading' }, h('span', null, icon('branch', 14), ' ', t('Only run this step if…'))),
						W.conditionsBuilder(a.conditions, { pickerGroups: function () { return pickerGroups(i); } }, markDirty)
					)
				);
			}

			const out = h('div', { class: 'brnwh-step-result' });
			if (result) out.appendChild(W.renderStep(result, true));
			const testBtn = W.btn(t('Test this step'), {
				icon: 'play',
				variant: 'soft',
				onClick: function () {
					W.busy(testBtn, async function () {
						W.clear(out);
						try {
							const log = await W.api('flows/test-step', { method: 'POST', body: { flow: Object.assign({}, payload(), { id: flow.id }), index: flow.actions.filter(function (x) { return x.type; }).indexOf(a), data: sampleData(), step_outputs: stepOutputs } });
							const step = (log.steps || []).filter(function (s) { return s.action_id === a.id; }).pop();
							if (step) {
								stepResults[a.id] = step;
								if (step.status === 'success') {
									stepOutputs[pos] = step.output;
									sampleDirty = true;
									markDirty();
								}
								drawCanvas();
							}
						} catch (e) {
							out.appendChild(h('div', { class: 'brnwh-alert is-error' }, icon('alert', 16), h('span', null, e.message)));
						}
					});
				},
			});
			body.appendChild(h('div', { class: 'brnwh-step-foot' }, testBtn, h('span', { class: 'brnwh-muted' }, Object.keys(sampleData()).length ? t('Uses your test data') : t('No test data yet — values will be empty'))));
			body.appendChild(out);
			card.appendChild(body);
			return card;
		}

		function addStepButton() {
			return connector(
				h('button', {
					type: 'button',
					class: 'brnwh-add-step',
					onClick: function () {
						flow.actions.push(newAction(''));
						markDirty();
						drawCanvas();
						setTimeout(function () {
							const last = canvas.querySelector('.brnwh-step.is-action:last-of-type');
							if (last) last.scrollIntoView({ behavior: 'smooth', block: 'center' });
						}, 50);
					},
				}, icon('plus', 18), h('span', null, flow.actions.length ? t('Add another step') : t('Add an action')))
			);
		}

		function drawCanvas() {
			const parts = [triggerCard()];
			if (flow.trigger_type) {
				parts.push(filterCard());
				flow.actions.forEach(function (a, i) {
					parts.push(connector());
					parts.push(actionCard(a, i));
				});
				parts.push(addStepButton());
			}
			W.mount(canvas, h('div', null, parts));
		}

		/* -------------------------------------------------------- side panel */

		function stopListening() {
			if (pollTimer) clearInterval(pollTimer);
			pollTimer = null;
			listening = false;
		}

		async function listen() {
			if (!flow.trigger_type) return;
			if (!flow.id) {
				const ok = await save(true);
				if (!ok) return;
			}
			try {
				await W.api('flows/' + flow.id + '/listen', { method: 'POST' });
			} catch (e) {
				return W.errorToast(e);
			}
			const since = flow.sample && flow.sample.captured_at;
			listening = true;
			drawSide();
			const started = Date.now();
			pollTimer = setInterval(async function () {
				if (Date.now() - started > 10 * 60 * 1000) {
					stopListening();
					drawSide();
					return;
				}
				try {
					const res = await W.api('flows/' + flow.id + '/sample');
					if (res.sample && res.sample.captured_at && res.sample.captured_at !== since) {
						flow.sample = Object.assign({}, flow.sample || {}, { data: res.sample.data, captured_at: res.sample.captured_at });
						stopListening();
						W.toast(t('Test data received!'));
						loadKnown();
						drawSide();
						drawCanvas();
					}
				} catch (e) {
					/* keep polling */
				}
			}, 2000);
		}

		function editSample() {
			let ta;
			W.modal({
				title: t('Edit test data'),
				subtitle: t('JSON used when you test the flow or a step.'),
				size: 'lg',
				body: h('textarea', { class: 'brnwh-input is-mono', rows: 16, dir: 'ltr', spellcheck: 'false', ref: function (el) { ta = el; el.value = JSON.stringify(sampleData(), null, 2); } }),
				footer: function (m) {
					return [
						W.btn(t('Cancel'), { variant: 'ghost', onClick: function () { m.close(); } }),
						W.btn(t('Use this data'), {
							variant: 'primary',
							onClick: function () {
								let data;
								try {
									data = JSON.parse(ta.value || '{}');
								} catch (e) {
									return W.toast(t('Invalid JSON: %s', e.message), 'error');
								}
								if (!data || typeof data !== 'object' || Array.isArray(data)) return W.toast(t('Test data must be a JSON object.'), 'error');
								flow.sample = Object.assign({}, flow.sample || {}, { data: data, captured_at: new Date().toISOString() });
								sampleDirty = true;
								markDirty();
								m.close();
								drawSide();
							},
						}),
					];
				},
			});
		}

		function curlExample() {
			if (!flow.endpoint) return null;
			const c = flow.trigger_config || {};
			let auth = '';
			if (c.auth === 'token') auth = ' \\\n  -H "' + (c.token_header || 'X-Webhook-Token') + ': ' + (c.token || '…') + '"';
			if (c.auth === 'basic') auth = ' \\\n  -u "' + (c.basic_user || 'user') + ':' + (c.basic_pass || '…') + '"';
			const cmd = 'curl -X POST "' + flow.endpoint + '"' + auth + ' \\\n  -H "Content-Type: application/json" \\\n  -d \'{"name":"Dana Cohen","email":"dana@example.com","phone":"050-1234567"}\'';
			return h('div', { class: 'brnwh-curl' }, h('div', { class: 'brnwh-curl-head' }, h('span', null, t('Send a test request')), W.iconBtn('copy', { title: t('Copy'), onClick: function () { W.copy(cmd); } })), h('pre', { dir: 'ltr' }, cmd));
		}

		async function testFlow(btnEl) {
			if (!flow.trigger_type) return W.toast(t('Choose a trigger first.'), 'error');
			await W.busy(btnEl, async function () {
				try {
					const log = await W.api('flows/test', { method: 'POST', body: { flow: Object.assign({}, payload(), { id: flow.id }), data: sampleData() } });
					(log.steps || []).forEach(function (s) {
						if (s.action_id) {
							stepResults[s.action_id] = s;
							if (s.status === 'success') stepOutputs[s.position] = s.output;
						}
					});
					sampleDirty = true;
					drawCanvas();
					W.modal({ title: t('Test result'), size: 'lg', body: W.renderRun(log, { compact: true }), footer: function (m) { return [log.id ? W.btn(t('Open in logs'), { variant: 'ghost', icon: 'external', onClick: function () { m.close(); W.navigate('/logs/' + log.id); } }) : null, W.btn(t('Close'), { variant: 'primary', onClick: function () { m.close(); } })]; } });
				} catch (e) {
					W.errorToast(e);
				}
			});
		}

		function drawSide() {
			const tr = W.integration('triggers', flow.trigger_type);
			const data = sampleData();
			const has = Object.keys(data).length > 0;
			const parts = [];

			// Test data card.
			let body;
			if (listening) {
				body = h('div', { class: 'brnwh-listen' },
					h('div', { class: 'brnwh-radar' }, icon('radar', 26)),
					h('strong', null, t('Waiting for data…')),
					h('p', { class: 'brnwh-muted' }, tr && tr.has_endpoint ? t('Send a request to the webhook URL now.') : t('Submit the form / trigger the event on your site now.')),
					tr && tr.id === 'webhook' ? curlExample() : null,
					W.btn(t('Stop'), { variant: 'ghost', size: 'sm', onClick: function () { stopListening(); drawSide(); } })
				);
			} else {
				body = h('div', null,
					has
						? h('div', null, h('p', { class: 'brnwh-muted brnwh-small' }, t('Captured %s', W.timeAgo(flow.sample.captured_at))), W.jsonView(data, { open: 1 }))
						: h('div', { class: 'brnwh-sample-empty' }, icon('radar', 22), h('p', null, flow.trigger_type ? t('No test data yet. Click "Listen" and send a real request / submit the form, or enter data manually.') : t('Choose a trigger to start.'))),
					flow.trigger_type
						? h('div', { class: 'brnwh-row is-wrap' },
								W.btn(has ? t('Listen again') : t('Listen for data'), { icon: 'radar', variant: has ? 'soft' : 'primary', size: 'sm', onClick: listen }),
								W.btn(has ? t('Edit') : t('Enter manually'), { icon: 'edit', variant: 'ghost', size: 'sm', onClick: editSample })
						  )
						: null
				);
			}
			parts.push(W.card(t('Test data'), body, { class: 'brnwh-side-card' }));

			const testBtn = W.btn(t('Test the whole flow'), { icon: 'play', variant: 'dark', class: 'is-block', onClick: function () { testFlow(testBtn); } });
			parts.push(h('div', { class: 'brnwh-side-test' }, testBtn, h('p', { class: 'brnwh-muted brnwh-small' }, t('Runs every step for real with the test data (emails are sent, rows are added).'))));

			// Settings.
			const s = flow.settings;
			const retries = h('select', { class: 'brnwh-input is-sm', onChange: function (e) { s.retries = parseInt(e.target.value, 10); markDirty(); } }, [0, 1, 2, 3, 4, 5, 6].map(function (n) { return h('option', { value: String(n) }, n === 0 ? t('No retries') : t('%d retries', n)); }));
			retries.value = String(s.retries);
			const logSel = h('select', { class: 'brnwh-input is-sm', onChange: function (e) { s.log = e.target.value; markDirty(); } }, [['default', t('Site default')], ['all', t('Log everything')], ['errors', t('Only errors')], ['none', t('No logs')]].map(function (o) { return h('option', { value: o[0] }, o[1]); }));
			logSel.value = s.log || 'default';
			parts.push(
				W.card(t('Flow settings'), h('div', { class: 'brnwh-side-settings' },
					W.toggle({ checked: s.async !== false, label: t('Run in the background'), onChange: function (on) { s.async = on; markDirty(); } }),
					h('p', { class: 'brnwh-help' }, t('Visitors don\'t wait for the actions. Turn off only when the webhook must reply with the result.')),
					h('label', { class: 'brnwh-label' }, t('When a step fails')), retries,
					h('p', { class: 'brnwh-help' }, t('Retries after 1, 5, 15, 60 minutes… Successful steps are not repeated.')),
					h('label', { class: 'brnwh-label' }, t('Logging')), logSel
				), { class: 'brnwh-side-card' })
			);

			if (flow.id) {
				const runs = h('div', { class: 'brnwh-run-list is-compact' }, W.skeleton(2));
				parts.push(W.card(t('Recent runs'), runs, { class: 'brnwh-side-card', actions: h('a', { class: 'brnwh-link', href: '#/logs?flow=' + flow.id }, t('All')) }));
				W.api('logs', { query: { flow_id: flow.id, per_page: 5 } }).then(function (res) {
					W.mount(runs, res.items.length ? h('div', null, res.items.map(W.runRow)) : h('p', { class: 'brnwh-muted brnwh-small' }, t('No runs yet.')));
				}, function () { W.clear(runs); });
			}
			W.mount(side, h('div', null, parts));
		}

		function drawAll() {
			drawCanvas();
			drawSide();
		}

		/* -------------------------------------------------------- page */

		const top = h(
			'div',
			{ class: 'brnwh-editor-top' },
			W.iconBtn(W.boot.rtl ? 'arrowRight' : 'arrowLeft', { title: t('Back to flows'), onClick: function () { W.navigate('/flows'); } }),
			h('div', { class: 'brnwh-editor-name' }, nameInput, savedHint),
			h('div', { class: 'brnwh-editor-actions' },
				h('div', { class: 'brnwh-status-switch' }, statusLabel, statusToggle),
				saveBtn,
				W.iconBtn('more', {
					title: t('More'),
					onClick: function (e) {
						W.menu(e.currentTarget, [
							flow.id ? { label: t('View logs'), icon: 'activity', onClick: function () { W.navigate('/logs?flow=' + flow.id); } } : null,
							flow.id ? { label: t('Export'), icon: 'download', onClick: function () { W.exportFlow(flow); } } : null,
							flow.id ? { label: t('Duplicate'), icon: 'copy', onClick: async function () { try { const c = await W.api('flows/' + flow.id + '/duplicate', { method: 'POST' }); W.toast(t('Flow duplicated')); dirty = false; W.navigate('/flows/' + c.id); } catch (err) { W.errorToast(err); } } } : null,
							flow.id ? '-' : null,
							flow.id ? { label: t('Delete flow'), icon: 'trash', danger: true, onClick: function () { W.deleteFlow(flow, function () { dirty = false; W.navigate('/flows'); }); } } : null,
							!flow.id ? { label: t('Discard'), icon: 'x', onClick: function () { dirty = false; W.navigate('/flows'); } } : null,
						]);
					},
				})
			)
		);

		W.page(h('div', { class: 'brnwh-editor' }, top, h('div', { class: 'brnwh-editor-grid' }, canvas, side)));
		updateSaveState();
		loadKnown();
		drawAll();
		if (!flow.name) setTimeout(function () { if (flow.trigger_type) nameInput.focus(); }, 100);
	};
})();

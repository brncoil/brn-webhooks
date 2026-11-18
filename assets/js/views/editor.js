/**
 * BRN Webhooks — visual flow builder.
 *
 * The flow is a tree drawn on a pannable / zoomable canvas: the trigger at the top, steps below.
 * Every node hangs from one output port of its parent (out, error, yes, no, p:<path>, else,
 * each, done). Drag from a port to a node to connect, drop on empty space to add a step there,
 * hover a connection to insert a step in the middle. The panel on the side edits the selected node.
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;

	const NODE_W = 260;
	const GAP_X = 56;
	const GAP_Y = 110;
	const SNAP = 10;
	const MIN_Z = 0.3;
	const MAX_Z = 1.6;
	const SVGNS = 'http://www.w3.org/2000/svg';
	const RTL = !!W.boot.rtl;

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

	const LOGIC_ORDER = ['filter', 'if', 'router', 'delay', 'loop', 'formatter'];

	/* ------------------------------------------------------------ helpers */

	function emptyConditions() {
		return { logic: 'or', groups: [] };
	}
	function oneRule() {
		return { logic: 'or', groups: [{ logic: 'and', rules: [{ field: '', op: 'equals', value: '' }] }] };
	}
	function letter(i) {
		return i < 26 ? String.fromCharCode(65 + i) : String(i + 1);
	}
	function snap(v) {
		return Math.round(v / SNAP) * SNAP;
	}
	function defOf(type) {
		return W.integration('actions', type);
	}
	function kindOf(n) {
		const d = defOf(n.type);
		return d && d.kind ? d.kind : 'action';
	}
	function isTyping(e) {
		const tg = e.target;
		return tg && (tg.tagName === 'INPUT' || tg.tagName === 'TEXTAREA' || tg.tagName === 'SELECT' || tg.isContentEditable);
	}
	function svg(tag, attrs) {
		const el = document.createElementNS(SVGNS, tag);
		Object.keys(attrs || {}).forEach(function (k) {
			el.setAttribute(k, attrs[k]);
		});
		return el;
	}

	function autoConnection(type) {
		const def = defOf(type);
		if (!def || !def.connection_type) return 0;
		const list = (W.state.connections || []).filter(function (c) { return c.type === def.connection_type; });
		return list.length === 1 ? list[0].id : 0;
	}

	/** Output ports of a node. */
	function portsOf(n) {
		if (n === 'trigger') return [{ id: 'out', label: '', tone: 'out' }];
		const k = kindOf(n);
		if (k === 'if') return [{ id: 'yes', label: t('Yes'), tone: 'yes' }, { id: 'no', label: t('No'), tone: 'no' }];
		if (k === 'router') {
			const c = n.config || {};
			const ps = (c.paths || []).map(function (p, i) { return { id: 'p:' + p.id, label: p.name || t('Path %s', letter(i)), tone: 'path' }; });
			if (c.else !== false) ps.push({ id: 'else', label: t('Otherwise'), tone: 'else' });
			return ps;
		}
		if (k === 'loop') return [{ id: 'each', label: t('For each item'), tone: 'loop' }, { id: 'done', label: t('When done'), tone: 'done' }];
		if (k === 'filter' || k === 'delay') return [{ id: 'out', label: '', tone: 'out' }];
		return [{ id: 'out', label: '', tone: 'out' }, { id: 'error', label: t('On error'), tone: 'error', minor: true }];
	}
	function mainPort(n) {
		return portsOf(n)[0] ? portsOf(n)[0].id : 'out';
	}

	function newNode(type, config) {
		const def = defOf(type);
		const kind = def && def.kind ? def.kind : 'action';
		const n = {
			id: W.uid(),
			type: type,
			name: '',
			connection_id: autoConnection(type),
			config: Object.assign({}, config || {}),
			enabled: true,
			continue_on_error: false,
			conditions: kind === 'filter' || kind === 'if' ? oneRule() : emptyConditions(),
			parent: '',
			port: 'out',
			ui: { x: 0, y: 0 },
		};
		if (kind === 'router' && !n.config.paths) {
			n.config = { mode: 'first', else: true, paths: [{ id: W.uid(), name: '', conditions: oneRule() }, { id: W.uid(), name: '', conditions: oneRule() }] };
		}
		if (def) W.defaultsFor(def.fields, n.config);
		return n;
	}

	/** Deep-replace {{_steps.N…}} (old position references) with stable node ids. */
	function rewriteRefs(value, map) {
		if (typeof value === 'string') {
			return value.replace(/_steps\.(\d+)(?=[.|}\s]|$)/g, function (m, n) { return map[n] ? '_steps.' + map[n] : m; });
		}
		if (Array.isArray(value)) return value.map(function (v) { return rewriteRefs(v, map); });
		if (value && typeof value === 'object') {
			const out = {};
			Object.keys(value).forEach(function (k) { out[k] = rewriteRefs(value[k], map); });
			return out;
		}
		return value;
	}

	/** Turn an older linear flow into a canvas tree (and its flow-level filter into a Filter node). */
	function migrate(flow) {
		const nodes = flow.actions || [];
		const legacy = nodes.length && !Object.prototype.hasOwnProperty.call(nodes[0], 'parent');
		const hasFilter = W.conditionsCount(flow.conditions) > 0;
		if (!legacy && !hasFilter) return false;
		if (legacy) {
			const map = {};
			nodes.forEach(function (a, i) { map[i + 1] = a.id; });
			nodes.forEach(function (a) {
				a.config = rewriteRefs(a.config || {}, map);
				a.conditions = rewriteRefs(a.conditions || emptyConditions(), map);
			});
			if (flow.sample && flow.sample.steps) {
				const steps = {};
				Object.keys(flow.sample.steps).forEach(function (k) { steps[map[k] || k] = flow.sample.steps[k]; });
				flow.sample.steps = steps;
			}
			let prev = 'trigger';
			nodes.forEach(function (a) {
				a.parent = prev;
				a.port = 'out';
				a.ui = { x: 0, y: 0 };
				prev = a.id;
			});
		}
		if (hasFilter) {
			const f = newNode('filter');
			f.conditions = flow.conditions;
			const first = nodes.filter(function (a) { return a.parent === 'trigger'; });
			f.parent = 'trigger';
			f.port = 'out';
			first.forEach(function (a) { a.parent = f.id; a.port = 'out'; });
			nodes.unshift(f);
			flow.conditions = emptyConditions();
		}
		flow.actions = nodes;
		return true;
	}

	function blankFlow(templateId) {
		const s = W.state.settings || {};
		const f = { id: 0, name: '', status: 'paused', trigger_type: '', trigger_config: {}, conditions: emptyConditions(), actions: [], settings: { async: true, retries: s.default_retries !== undefined ? s.default_retries : 3, log: 'default', trigger_ui: { x: 0, y: 0 } }, sample: null, endpoint: '' };
		const tpl = (W.templates || []).find(function (x) { return x.id === templateId; });
		if (tpl) {
			f.name = t(tpl.title);
			f.trigger_type = tpl.trigger;
			f.trigger_config = Object.assign({}, tpl.triggerConfig || {});
			const tr = W.integration('triggers', tpl.trigger);
			if (tr) W.defaultsFor(tr.fields, f.trigger_config);
			let prev = 'trigger';
			f.actions = tpl.actions.map(function (a) {
				const n = newNode(a.type, a.config);
				n.parent = prev;
				prev = n.id;
				return n;
			});
		}
		return f;
	}

	function condSummary(c) {
		const rules = [];
		((c && c.groups) || []).forEach(function (g) { (g.rules || []).forEach(function (r) { if (r.field) rules.push(r); }); });
		if (!rules.length) return t('No conditions yet');
		const r = rules[0];
		const op = (W.OPS || []).find(function (o) { return o[0] === r.op; });
		const text = r.field.replace(/^\{\{|\}\}$/g, '') + ' ' + (op ? t(op[1]) : r.op) + (r.value ? ' ' + r.value : '');
		return rules.length > 1 ? t('%1$s (+%2$d more)', text, rules.length - 1) : text;
	}

	function summary(n) {
		const k = kindOf(n);
		const c = n.config || {};
		if (k === 'filter' || k === 'if') return condSummary(n.conditions);
		if (k === 'router') return t('%d paths', (c.paths || []).length) + (c.mode === 'all' ? ' · ' + t('every match') : '');
		if (k === 'delay') {
			if (c.mode === 'until') return t('Until %s', c.until || '…');
			const units = { minutes: t('minutes'), hours: t('hours'), days: t('days') };
			return t('Wait %1$s %2$s', c.amount || 0, units[c.unit] || c.unit || '');
		}
		if (k === 'loop') return c.items ? t('Each of %s', c.items) : t('Choose a list');
		if (n.type === 'formatter') {
			const def = defOf('formatter');
			const f = def && def.fields.find(function (x) { return x.key === 'operation'; });
			const o = f && f.options.find(function (x) { return x.value === c.operation; });
			return o ? o.label : '';
		}
		const def = defOf(n.type);
		if (def && def.connection_type) {
			const conn = (W.state.connections || []).find(function (x) { return x.id === n.connection_id; });
			if (!conn && !def.connection_optional) return t('Needs a connection');
			if (conn && n.type !== 'http') return conn.name;
		}
		if (n.type === 'http') return (c.method || 'POST') + ' ' + (c.url || '');
		if (c.to) return c.to;
		return def ? def.description : '';
	}

	/* ------------------------------------------------------ node picker */

	let pickerEl = null;
	function closePicker() {
		if (pickerEl) {
			pickerEl.remove();
			pickerEl = null;
			document.removeEventListener('mousedown', pickerOutside, true);
			document.removeEventListener('keydown', pickerKey, true);
		}
	}
	function pickerOutside(e) {
		if (pickerEl && !pickerEl.contains(e.target)) closePicker();
	}
	function pickerKey(e) {
		if (e.key === 'Escape') closePicker();
	}

	/** Searchable list of logic steps and app actions; anchor is an element or a client rect. */
	function openNodePicker(anchor, onPick) {
		W.closeMenu();
		closePicker();
		const items = ((W.state.integrations && W.state.integrations.actions) || []).slice();
		const logic = LOGIC_ORDER.map(function (id) { return items.find(function (i) { return i.id === id; }); }).filter(Boolean);
		const apps = items.filter(function (i) { return LOGIC_ORDER.indexOf(i.id) < 0; });
		const list = h('div', { class: 'brnwh-np-list' });
		const search = h('input', { class: 'brnwh-input', type: 'search', placeholder: t('Search steps and apps…') });
		function tile(it, compact) {
			return h(
				'button',
				{ type: 'button', class: ['brnwh-np-item', compact ? 'is-logic' : '', it.available ? '' : 'is-dim'], title: it.available ? it.description : it.reason, onClick: function () { if (!it.available) { W.toast(it.reason, 'info'); return; } closePicker(); onPick(it.id); } },
				W.appIcon(it.icon, compact ? 30 : 32, it.label),
				h('span', { class: 'brnwh-np-text' }, h('strong', null, it.label), compact ? null : h('span', null, it.available ? it.description : it.reason))
			);
		}
		function draw(q) {
			W.clear(list);
			const match = function (it) { return !q || (it.label + ' ' + it.description + ' ' + it.group).toLowerCase().indexOf(q) >= 0; };
			const lg = logic.filter(match);
			if (lg.length) {
				list.appendChild(h('div', { class: 'brnwh-np-group' }, t('Logic & tools')));
				list.appendChild(h('div', { class: 'brnwh-np-grid' }, lg.map(function (it) { return tile(it, true); })));
			}
			const groups = {};
			apps.filter(match).forEach(function (it) { (groups[it.group || ''] = groups[it.group || ''] || []).push(it); });
			Object.keys(groups).forEach(function (g) {
				list.appendChild(h('div', { class: 'brnwh-np-group' }, g || t('Apps')));
				groups[g].forEach(function (it) { list.appendChild(tile(it, false)); });
			});
			if (!list.children.length) list.appendChild(h('p', { class: 'brnwh-muted brnwh-pad' }, t('Nothing found.')));
		}
		search.addEventListener('input', function () { draw(search.value.trim().toLowerCase()); });
		search.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				const first = list.querySelector('.brnwh-np-item:not(.is-dim)');
				if (first) first.click();
			}
		});
		draw('');
		pickerEl = h('div', { class: 'brnwh-menu brnwh brnwh-node-picker', dir: RTL ? 'rtl' : 'ltr' }, h('div', { class: 'brnwh-np-head' }, h('strong', null, t('Add a step')), h('div', { class: 'brnwh-search' }, icon('search', 16), search)), list);
		document.body.appendChild(pickerEl);
		const a = anchor && anchor.getBoundingClientRect ? anchor : { getBoundingClientRect: function () { return anchor; } };
		W.positionPopover(pickerEl, a, 'start');
		setTimeout(function () {
			document.addEventListener('mousedown', pickerOutside, true);
			document.addEventListener('keydown', pickerKey, true);
			search.focus();
		}, 0);
	}

	/** Trigger grid (inside the panel). */
	function triggerPicker(onPick) {
		const items = (W.state.integrations && W.state.integrations.triggers) || [];
		const box = h('div', { class: 'brnwh-np-list is-inline' });
		const search = h('input', { class: 'brnwh-input', type: 'search', placeholder: t('Search triggers…') });
		function draw(q) {
			W.clear(box);
			const groups = {};
			items.forEach(function (it) {
				if (q && (it.label + ' ' + it.description + ' ' + it.group).toLowerCase().indexOf(q) < 0) return;
				(groups[it.group || ''] = groups[it.group || ''] || []).push(it);
			});
			Object.keys(groups).forEach(function (g) {
				box.appendChild(h('div', { class: 'brnwh-np-group' }, g));
				groups[g].forEach(function (it) {
					box.appendChild(
						h('button', { type: 'button', class: ['brnwh-np-item', it.available ? '' : 'is-dim'], title: it.available ? it.description : it.reason, onClick: function () { if (!it.available) { W.toast(it.reason, 'info'); return; } onPick(it.id); } },
							W.appIcon(it.icon, 34, it.label),
							h('span', { class: 'brnwh-np-text' }, h('strong', null, it.label), h('span', null, it.available ? it.description : it.reason))
						)
					);
				});
			});
			if (!box.children.length) box.appendChild(h('p', { class: 'brnwh-muted brnwh-pad' }, t('Nothing found.')));
		}
		search.addEventListener('input', function () { draw(search.value.toLowerCase()); });
		draw('');
		setTimeout(function () { search.focus(); }, 60);
		return h('div', null, h('div', { class: 'brnwh-search' }, icon('search', 16), search), box);
	}

	/* ============================================================ editor */

	W.views.editor = async function (id, templateId) {
		let flow;
		let dirty = false;
		let sampleDirty = false;
		let listening = false;
		let pollTimer = null;
		let knownFields = {};
		let stepOutputs = {};
		let results = {};
		let lastRun = null;
		let selected = null;
		let nodeEls = {};
		const view = { x: 0, y: 0, z: 1 };
		const past = [];
		const future = [];

		W.page(h('div', { class: 'brnwh-page' }, W.skeleton(6)));
		try {
			flow = id ? await W.api('flows/' + id) : blankFlow(templateId);
		} catch (e) {
			W.page(W.empty({ icon: 'alert', title: t('Flow not found'), text: e.message, action: W.btn(t('Back to flows'), { icon: 'arrowLeft', onClick: function () { W.navigate('/flows'); } }) }));
			return;
		}
		flow.conditions = flow.conditions || emptyConditions();
		flow.settings = flow.settings || {};
		flow.actions = flow.actions || [];
		const migrated = migrate(flow);
		flow.actions.forEach(function (a) {
			a.conditions = a.conditions || emptyConditions();
			a.config = a.config || {};
			a.parent = a.parent === undefined ? '' : a.parent;
			a.port = a.port || 'out';
			a.ui = a.ui || { x: 0, y: 0 };
		});
		if (!flow.settings.trigger_ui) flow.settings.trigger_ui = { x: 0, y: 0 };
		stepOutputs = (flow.sample && flow.sample.steps) || {};
		if (templateId && flow.trigger_type) dirty = true;

		/* -------------------------------------------------------- lookups */

		function node(nid) {
			return flow.actions.find(function (a) { return a.id === nid; }) || null;
		}
		function children(pid, port) {
			return flow.actions.filter(function (a) { return a.parent === pid && (port === undefined || a.port === port); });
		}
		function pos(nid) {
			return nid === 'trigger' ? flow.settings.trigger_ui : node(nid).ui;
		}
		function isConnected(n) {
			let cur = n;
			const seen = {};
			while (cur && cur !== 'trigger') {
				if (seen[cur.id]) return false;
				seen[cur.id] = true;
				if (!cur.parent) return false;
				if (cur.parent === 'trigger') return cur.port === 'out';
				const p = node(cur.parent);
				if (!p || !portsOf(p).some(function (x) { return x.id === cur.port; })) return false;
				cur = p;
			}
			return true;
		}
		function ancestors(n) {
			const out = [];
			let cur = n;
			const seen = {};
			while (cur && cur.parent && cur.parent !== 'trigger' && !seen[cur.parent]) {
				seen[cur.parent] = true;
				const p = node(cur.parent);
				if (!p) break;
				out.unshift({ node: p, via: cur.port });
				cur = p;
			}
			return out;
		}
		function isAncestor(maybe, of) {
			if (maybe === 'trigger') return true;
			return ancestors(of).some(function (x) { return x.node.id === maybe; });
		}
		function subtree(n) {
			const out = [n];
			for (let i = 0; i < out.length; i++) {
				children(out[i].id).forEach(function (c) { out.push(c); });
			}
			return out;
		}
		function title(n) {
			const def = defOf(n.type);
			return n.name || (def ? def.label : n.type);
		}

		/* -------------------------------------------------------- state */

		function snapshot() {
			return JSON.stringify({ actions: flow.actions, trigger_type: flow.trigger_type, trigger_config: flow.trigger_config, tui: flow.settings.trigger_ui });
		}
		function remember() {
			past.push(snapshot());
			if (past.length > 80) past.shift();
			future.length = 0;
		}
		function restore(s) {
			const o = JSON.parse(s);
			flow.actions = o.actions;
			flow.trigger_type = o.trigger_type;
			flow.trigger_config = o.trigger_config;
			flow.settings.trigger_ui = o.tui;
			if (selected && selected !== 'trigger' && !node(selected)) selected = null;
			markDirty();
			render();
		}
		function undo() {
			if (!past.length) return;
			future.push(snapshot());
			restore(past.pop());
		}
		function redo() {
			if (!future.length) return;
			past.push(snapshot());
			restore(future.pop());
		}

		/* -------------------------------------------------------- DOM */

		const saveBtn = W.btn(t('Save'), { icon: 'save', variant: 'primary', onClick: function () { save(); } });
		const savedHint = h('span', { class: 'brnwh-save-hint' });
		const nameInput = h('input', { class: 'brnwh-name-input', value: flow.name, placeholder: t('Name your flow'), 'aria-label': t('Flow name'), onInput: function (e) { flow.name = e.target.value; markDirty(); } });
		const statusToggle = W.toggle({ checked: flow.status === 'active', label: '', onChange: function (on, e) { setStatus(on, e.target); } });
		const statusLabel = h('span', { class: 'brnwh-status-label' });

		const edgeLayer = svg('svg', { class: 'brnwh-edges', width: '1', height: '1' });
		const nodesLayer = h('div', { class: 'brnwh-nodes' });
		const edgeTools = h('div', { class: 'brnwh-edge-tools', hidden: true });
		const world = h('div', { class: 'brnwh-world' }, edgeLayer, nodesLayer, edgeTools);
		const board = h('div', { class: 'brnwh-board', tabindex: '-1' }, world);
		const inspector = h('aside', { class: 'brnwh-inspector' });
		const zoomLabel = h('button', { type: 'button', class: 'brnwh-zoom-label', title: t('Reset zoom'), onClick: function () { zoomTo(1); } }, '100%');
		const undoBtn = W.iconBtn('retry', { title: t('Undo') + ' (Ctrl+Z)', onClick: undo });
		const redoBtn = W.iconBtn('refresh', { title: t('Redo') + ' (Ctrl+Y)', onClick: redo });
		const runBanner = h('div', { class: 'brnwh-run-banner', hidden: true });
		const testBtn = W.btn(t('Test flow'), { icon: 'play', variant: 'dark', onClick: function () { testFlow(); } });
		const emptyHint = h('div', { class: 'brnwh-board-hint', hidden: true });

		board.appendChild(
			h('div', { class: 'brnwh-board-tools' },
				W.iconBtn('down', { title: t('Zoom out'), onClick: function () { zoomTo(view.z / 1.2); } }),
				zoomLabel,
				W.iconBtn('up', { title: t('Zoom in'), onClick: function () { zoomTo(view.z * 1.2); } }),
				h('span', { class: 'brnwh-tools-sep' }),
				W.iconBtn('grid', { title: t('Fit to screen'), onClick: function () { fit(); } }),
				W.iconBtn('sparkles', { title: t('Tidy up (auto-arrange)'), onClick: function () { remember(); tidy(); markDirty(); render(); fit(); } }),
				h('span', { class: 'brnwh-tools-sep' }),
				undoBtn,
				redoBtn
			)
		);
		board.appendChild(h('div', { class: 'brnwh-board-top' }, runBanner, testBtn));
		board.appendChild(emptyHint);

		/* -------------------------------------------------------- render */

		function render() {
			renderNodes();
			renderEdges();
			renderInspector();
			updateSaveState();
		}

		function resultFor(nid) {
			return results[nid] || null;
		}

		function statusIcon(r) {
			const map = { success: 'check', failed: 'x', filtered: 'filter', skipped: 'right', waiting: 'clock', not_run: 'right' };
			return h('span', { class: 'brnwh-node-status is-' + r.status, title: t(r.status) }, icon(map[r.status] || 'info', 12), r.count > 1 ? h('b', null, '×' + r.count) : null);
		}

		function portEl(owner, p) {
			const nid = owner === 'trigger' ? 'trigger' : owner.id;
			const linked = children(nid, p.id).length > 0;
			const taken = owner !== 'trigger' && results[owner.id] && (results[owner.id].ports || []).indexOf(p.id) >= 0;
			return h(
				'div',
				{ class: ['brnwh-port', 'is-' + p.tone, p.minor ? 'is-minor' : '', linked ? 'is-linked' : '', taken ? 'is-taken' : ''], dataset: { node: nid, port: p.id }, title: p.minor ? t('Drag to connect steps that run when this step fails') : null },
				h('span', { class: 'brnwh-port-dot' }),
				p.label && !p.minor ? h('span', { class: 'brnwh-port-label' }, p.label) : null,
				!linked && !p.minor ? h('button', { type: 'button', class: 'brnwh-port-add', title: t('Add a step here'), dataset: { node: nid, port: p.id } }, icon('plus', 14)) : null
			);
		}

		function triggerEl() {
			const tr = W.integration('triggers', flow.trigger_type);
			const p = flow.settings.trigger_ui;
			const has = Object.keys(sampleData()).length > 0;
			return h(
				'div',
				{ class: ['brnwh-node', 'is-trigger', selected === 'trigger' ? 'is-selected' : '', !tr ? 'is-empty' : '', lastRun ? 'is-run-success' : ''], style: { left: p.x + 'px', top: p.y + 'px', width: NODE_W + 'px' }, dir: RTL ? 'rtl' : 'ltr', dataset: { id: 'trigger' } },
				h('div', { class: 'brnwh-node-card' },
					tr ? W.appIcon(tr.icon, 42, tr.label) : h('span', { class: 'brnwh-node-ph' }, icon('zap', 20)),
					h('div', { class: 'brnwh-node-text' },
						h('span', { class: 'brnwh-node-kicker' }, icon('zap', 11), t('Trigger')),
						h('strong', null, tr ? tr.label : t('Choose what starts the flow')),
						h('span', { class: 'brnwh-node-sub' }, tr ? (has ? t('Test data ready') : t('No test data yet')) : t('Click to choose a trigger'))
					)
				),
				tr ? h('div', { class: 'brnwh-node-ports' }, portEl('trigger', portsOf('trigger')[0])) : null
			);
		}

		function nodeEl(n) {
			const def = defOf(n.type);
			const k = kindOf(n);
			const r = resultFor(n.id);
			const ports = portsOf(n);
			const major = ports.filter(function (p) { return !p.minor; }).length;
			const width = Math.max(NODE_W, major * 112);
			const kicker = k === 'action' && LOGIC_ORDER.indexOf(n.type) < 0 ? (def ? def.group || t('Action') : t('Action')) : t('Logic');
			return h(
				'div',
				{ class: ['brnwh-node', 'is-' + k, selected === n.id ? 'is-selected' : '', !n.enabled ? 'is-disabled' : '', !isConnected(n) ? 'is-orphan' : '', r ? 'is-run-' + r.status : ''], style: { left: n.ui.x + 'px', top: n.ui.y + 'px', width: width + 'px' }, dir: RTL ? 'rtl' : 'ltr', dataset: { id: n.id } },
				h('span', { class: 'brnwh-node-in' }),
				h('div', { class: 'brnwh-node-card' },
					W.appIcon(def ? def.icon : n.type, 40, title(n)),
					h('div', { class: 'brnwh-node-text' },
						h('span', { class: 'brnwh-node-kicker' }, kicker, !n.enabled ? ' · ' + t('off') : ''),
						h('strong', null, title(n)),
						h('span', { class: 'brnwh-node-sub', dir: 'auto' }, summary(n))
					),
					r ? statusIcon(r) : null
				),
				!isConnected(n) ? h('span', { class: 'brnwh-node-warn' }, icon('alert', 12), t('Not connected')) : null,
				h('div', { class: 'brnwh-node-ports' }, ports.map(function (p) { return portEl(n, p); }))
			);
		}

		function renderNodes() {
			W.clear(nodesLayer);
			nodeEls = {};
			nodeEls.trigger = triggerEl();
			nodesLayer.appendChild(nodeEls.trigger);
			flow.actions.forEach(function (n) {
				if (!n.type) return;
				nodeEls[n.id] = nodeEl(n);
				nodesLayer.appendChild(nodeEls[n.id]);
			});
			emptyHint.hidden = !(flow.trigger_type && !flow.actions.length);
			if (!emptyHint.hidden) W.mount(emptyHint, h('div', null, icon('sparkles', 16), h('span', null, t('Click the + under the trigger to add your first step — an app action, or logic like a filter, if/else or paths.'))));
		}

		/** Offset of an element inside its node element (layout px, unaffected by zoom). */
		function offsetIn(el, root) {
			let x = 0;
			let y = 0;
			let cur = el;
			while (cur && cur !== root) {
				x += cur.offsetLeft;
				y += cur.offsetTop;
				cur = cur.offsetParent;
			}
			return { x: x, y: y };
		}

		function portPoint(nid, port) {
			const el = nodeEls[nid];
			if (!el) return null;
			const dot = el.querySelector('.brnwh-port[data-port="' + port + '"] .brnwh-port-dot');
			if (!dot) return null;
			const o = offsetIn(dot, el);
			const p = pos(nid);
			return { x: p.x + o.x + dot.offsetWidth / 2, y: p.y + o.y + dot.offsetHeight / 2 };
		}
		function inPoint(nid) {
			const el = nodeEls[nid];
			const p = pos(nid);
			return { x: p.x + el.offsetWidth / 2, y: p.y };
		}

		function curve(a, b) {
			const dy = Math.max(46, Math.abs(b.y - a.y) / 2) + (b.y < a.y ? 80 : 0);
			return { d: 'M' + a.x + ',' + a.y + ' C' + a.x + ',' + (a.y + dy) + ' ' + b.x + ',' + (b.y - dy) + ' ' + b.x + ',' + b.y, mid: { x: (a.x + 3 * a.x + 3 * b.x + b.x) / 8, y: (a.y + 3 * (a.y + dy) + 3 * (b.y - dy) + b.y) / 8 } };
		}

		let edgeHideTimer = null;
		let edgeHover = null;
		function showEdgeTools(childId, mid) {
			clearTimeout(edgeHideTimer);
			edgeHover = childId;
			edgeTools.hidden = false;
			edgeTools.style.left = mid.x + 'px';
			edgeTools.style.top = mid.y + 'px';
		}
		function hideEdgeTools() {
			clearTimeout(edgeHideTimer);
			edgeHideTimer = setTimeout(function () { edgeTools.hidden = true; edgeHover = null; }, 280);
		}
		edgeTools.addEventListener('mouseenter', function () { clearTimeout(edgeHideTimer); });
		edgeTools.addEventListener('mouseleave', hideEdgeTools);
		W.append(edgeTools, [
			h('button', { type: 'button', class: 'brnwh-edge-btn', title: t('Insert a step here'), onClick: function (e) { const c = node(edgeHover); if (c) { edgeTools.hidden = true; openNodePicker(e.currentTarget, function (type) { insertBefore(c, type); }); } } }, icon('plus', 14)),
			h('button', { type: 'button', class: 'brnwh-edge-btn is-danger', title: t('Disconnect'), onClick: function () { const c = node(edgeHover); if (c) { remember(); c.parent = ''; edgeTools.hidden = true; markDirty(); render(); } } }, icon('x', 14)),
		]);

		function renderEdges() {
			while (edgeLayer.firstChild) edgeLayer.removeChild(edgeLayer.firstChild);
			const defs = svg('defs');
			['out', 'yes', 'no', 'path', 'else', 'loop', 'done', 'error', 'run'].forEach(function (tone) {
				const m = svg('marker', { id: 'brnwh-arrow-' + tone, viewBox: '0 0 10 10', refX: '8', refY: '5', markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse' });
				m.appendChild(svg('path', { d: 'M0,0 L10,5 L0,10 z', class: 'brnwh-arrow is-' + tone }));
				defs.appendChild(m);
			});
			edgeLayer.appendChild(defs);
			flow.actions.forEach(function (n) {
				if (!n.type || !n.parent || !nodeEls[n.id]) return;
				const parentId = n.parent;
				if (parentId !== 'trigger' && !node(parentId)) return;
				const a = portPoint(parentId, n.port);
				if (!a) return;
				const b = inPoint(n.id);
				const c = curve(a, b);
				const owner = parentId === 'trigger' ? 'trigger' : node(parentId);
				const pdef = portsOf(owner).find(function (p) { return p.id === n.port; });
				const tone = pdef ? pdef.tone : 'out';
				const ran = results[n.id] && results[n.id].status !== 'not_run';
				const g = svg('g', { class: 'brnwh-edge-g' });
				const hit = svg('path', { d: c.d, class: 'brnwh-edge-hit' });
				const path = svg('path', { d: c.d, class: 'brnwh-edge is-' + tone + (ran ? ' is-run' : ''), 'marker-end': 'url(#brnwh-arrow-' + (ran ? 'run' : tone) + ')' });
				hit.addEventListener('mouseenter', function () { showEdgeTools(n.id, c.mid); });
				hit.addEventListener('mouseleave', hideEdgeTools);
				g.appendChild(path);
				g.appendChild(hit);
				edgeLayer.appendChild(g);
			});
		}

		function applyView() {
			world.style.transform = 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.z + ')';
			const size = 22 * view.z;
			board.style.backgroundSize = size + 'px ' + size + 'px';
			board.style.backgroundPosition = view.x + 'px ' + view.y + 'px';
			zoomLabel.textContent = Math.round(view.z * 100) + '%';
		}

		function zoomTo(z, cx, cy) {
			const r = board.getBoundingClientRect();
			const px = cx === undefined ? r.width / 2 : cx - r.left;
			const py = cy === undefined ? r.height / 2 : cy - r.top;
			const nz = Math.max(MIN_Z, Math.min(MAX_Z, z));
			view.x = px - ((px - view.x) * nz) / view.z;
			view.y = py - ((py - view.y) * nz) / view.z;
			view.z = nz;
			applyView();
		}

		function bounds() {
			let x1 = Infinity;
			let y1 = Infinity;
			let x2 = -Infinity;
			let y2 = -Infinity;
			Object.keys(nodeEls).forEach(function (nid) {
				const p = pos(nid);
				const el = nodeEls[nid];
				x1 = Math.min(x1, p.x);
				y1 = Math.min(y1, p.y);
				x2 = Math.max(x2, p.x + el.offsetWidth);
				y2 = Math.max(y2, p.y + el.offsetHeight + 60);
			});
			return { x1: x1, y1: y1, x2: x2, y2: y2 };
		}

		function fit() {
			const r = board.getBoundingClientRect();
			if (!r.width) return;
			const b = bounds();
			const pad = 70;
			const z = Math.max(MIN_Z, Math.min(1, (r.width - pad * 2) / (b.x2 - b.x1), (r.height - pad * 2 - 40) / (b.y2 - b.y1)));
			view.z = z;
			view.x = (r.width - (b.x2 - b.x1) * z) / 2 - b.x1 * z;
			view.y = pad + 30 - b.y1 * z;
			applyView();
		}

		/** Point on the canvas (world coordinates) for a client position. */
		function toWorld(cx, cy) {
			const r = board.getBoundingClientRect();
			return { x: (cx - r.left - view.x) / view.z, y: (cy - r.top - view.y) / view.z };
		}

		/* -------------------------------------------------------- layout */

		function widthOf(nid) {
			const el = nodeEls[nid];
			return el ? el.offsetWidth : NODE_W;
		}
		function heightOf(nid) {
			const el = nodeEls[nid];
			return el ? el.offsetHeight : 80;
		}

		/** Auto-arrange the tree top-down; branches spread sideways. */
		function tidy() {
			renderNodes();
			const kidsOf = function (nid) {
				const owner = nid === 'trigger' ? 'trigger' : node(nid);
				let ports = portsOf(owner).map(function (p) { return p.id; });
				if (RTL) ports = ports.slice().reverse();
				const out = [];
				ports.forEach(function (p) {
					children(nid, p).filter(function (c) { return c.type; }).sort(function (a, b) { return a.ui.x - b.ui.x; }).forEach(function (c) { out.push(c); });
				});
				return out;
			};
			const widths = {};
			const measure = function (nid) {
				const kids = kidsOf(nid);
				const own = widthOf(nid) + GAP_X;
				if (!kids.length) return (widths[nid] = own);
				const sum = kids.reduce(function (s, c) { return s + measure(c.id); }, 0);
				return (widths[nid] = Math.max(own, sum));
			};
			const place = function (nid, left, y) {
				const w = widths[nid];
				const p = pos(nid);
				p.x = snap(left + w / 2 - widthOf(nid) / 2);
				p.y = snap(y);
				let cursor = left + (w - kidsOf(nid).reduce(function (s, c) { return s + widths[c.id]; }, 0)) / 2;
				kidsOf(nid).forEach(function (c) {
					place(c.id, cursor, y + heightOf(nid) + GAP_Y);
					cursor += widths[c.id];
				});
			};
			measure('trigger');
			const start = flow.settings.trigger_ui.x + widthOf('trigger') / 2 - widths.trigger / 2;
			place('trigger', start, flow.settings.trigger_ui.y);
			// Disconnected nodes go in a row underneath.
			const b = bounds();
			let x = b.x1;
			flow.actions.forEach(function (n) {
				if (n.type && !isConnected(n) && (!n.parent || !node(n.parent) || !isConnected(node(n.parent)))) {
					if (n.parent && node(n.parent)) return;
					measure(n.id);
					place(n.id, x, b.y2 + 80);
					x += widths[n.id];
				}
			});
		}

		/** A free spot for a new child of nid on port. */
		function spotFor(nid, port) {
			const owner = nid === 'trigger' ? 'trigger' : node(nid);
			const pp = portPoint(nid, port);
			const p = pos(nid);
			const y = p.y + heightOf(nid) + GAP_Y;
			let x = (pp ? pp.x : p.x + widthOf(nid) / 2) - NODE_W / 2;
			const siblings = children(nid, port);
			if (siblings.length) x = Math.max.apply(null, siblings.map(function (s) { return s.ui.x + widthOf(s.id); })) + GAP_X;
			// Nudge sideways until nothing overlaps.
			for (let guard = 0; guard < 40; guard++) {
				const hit = Object.keys(nodeEls).some(function (other) {
					const o = pos(other);
					return Math.abs(o.y - y) < 90 && x < o.x + widthOf(other) + 20 && x + NODE_W + 20 > o.x;
				});
				if (!hit) break;
				x += (RTL && owner !== 'trigger' && port === mainPort(owner) ? -1 : 1) * (NODE_W + GAP_X) * 0.5;
			}
			return { x: snap(x), y: snap(y) };
		}

		/* -------------------------------------------------------- editing */

		function addChild(nid, port, type, at) {
			remember();
			const n = newNode(type);
			n.parent = nid;
			n.port = port;
			n.ui = at ? { x: snap(at.x - NODE_W / 2), y: snap(at.y) } : spotFor(nid, port);
			flow.actions.push(n);
			selected = n.id;
			markDirty();
			autoName();
			render();
			ensureVisible(n.id);
		}

		function addLoose(type, at) {
			remember();
			const n = newNode(type);
			n.ui = { x: snap(at.x - NODE_W / 2), y: snap(at.y) };
			flow.actions.push(n);
			selected = n.id;
			markDirty();
			render();
		}

		function insertBefore(c, type) {
			remember();
			const n = newNode(type);
			n.parent = c.parent;
			n.port = c.port;
			n.ui = { x: c.ui.x, y: c.ui.y };
			flow.actions.push(n);
			c.parent = n.id;
			c.port = mainPort(n);
			const shift = 80 + GAP_Y;
			subtree(c).forEach(function (s) { s.ui.y += shift; });
			selected = n.id;
			markDirty();
			render();
		}

		function removeNode(n) {
			remember();
			const kids = children(n.id);
			const k = kindOf(n);
			const main = mainPort(n);
			const passThrough = ['action', 'filter', 'delay'].indexOf(k) >= 0 || (kids.length && kids.every(function (c) { return c.port === kids[0].port; }));
			kids.forEach(function (c) {
				if (passThrough && (c.port === main || ['if', 'router', 'loop'].indexOf(k) >= 0) && c.port !== 'error') {
					c.parent = n.parent;
					c.port = n.port;
				} else {
					c.parent = '';
				}
			});
			flow.actions = flow.actions.filter(function (a) { return a.id !== n.id; });
			delete stepOutputs[n.id];
			delete results[n.id];
			if (selected === n.id) selected = null;
			markDirty();
			render();
		}

		function duplicateNode(n) {
			remember();
			const c = W.clone(n);
			c.id = W.uid();
			c.name = n.name ? n.name + ' 2' : '';
			c.ui = { x: n.ui.x + 30, y: n.ui.y + 30 };
			if (c.type === 'router') c.config.paths.forEach(function (p) { p.id = W.uid(); });
			flow.actions.push(c);
			selected = c.id;
			markDirty();
			render();
		}

		function connect(fromId, port, toId) {
			if (toId === 'trigger') return W.toast(t('The trigger is always the first step.'), 'info');
			const target = node(toId);
			if (!target || toId === fromId) return;
			if (fromId !== 'trigger' && (isAncestor(toId, node(fromId)) || toId === fromId)) return W.toast(t('That would create a loop. Use the Loop step to repeat steps.'), 'error');
			remember();
			target.parent = fromId;
			target.port = port;
			markDirty();
			render();
		}

		function ensureVisible(nid) {
			const el = nodeEls[nid];
			if (!el) return;
			const r = board.getBoundingClientRect();
			const p = pos(nid);
			const sx = p.x * view.z + view.x;
			const sy = p.y * view.z + view.y;
			const w = el.offsetWidth * view.z;
			const hh = el.offsetHeight * view.z;
			let dx = 0;
			let dy = 0;
			if (sx < 40) dx = 40 - sx;
			if (sx + w > r.width - 40) dx = r.width - 40 - sx - w;
			if (sy < 70) dy = 70 - sy;
			if (sy + hh > r.height - 90) dy = r.height - 90 - sy - hh;
			if (dx || dy) {
				view.x += dx;
				view.y += dy;
				world.classList.add('is-gliding');
				applyView();
				setTimeout(function () { world.classList.remove('is-gliding'); }, 320);
			}
		}

		function select(nid) {
			selected = nid;
			Object.keys(nodeEls).forEach(function (k) { nodeEls[k].classList.toggle('is-selected', k === nid); });
			renderInspector();
		}

		/** Re-render one node in place (after edits in the panel). */
		function refreshNode(nid) {
			if (!nodeEls[nid]) return;
			const fresh = nid === 'trigger' ? triggerEl() : nodeEl(node(nid));
			nodesLayer.replaceChild(fresh, nodeEls[nid]);
			nodeEls[nid] = fresh;
			renderEdges();
		}
		const refreshSoon = W.debounce(function (nid) { refreshNode(nid); }, 250);

		/* -------------------------------------------------------- pointer */

		let drag = null;
		let tempEdge = null;

		board.addEventListener('pointerdown', function (e) {
			if (e.button !== 0 && e.pointerType === 'mouse') return;
			const tg = e.target;
			if (tg.closest('.brnwh-board-tools, .brnwh-board-top, .brnwh-edge-tools, .brnwh-board-hint')) return;
			closePicker();
			const add = tg.closest('.brnwh-port-add');
			if (add) return; // click handler
			const port = tg.closest('.brnwh-port');
			if (port) {
				e.preventDefault();
				drag = { type: 'connect', from: port.dataset.node, port: port.dataset.port, el: port, sx: e.clientX, sy: e.clientY, moved: false };
				return;
			}
			const nEl = tg.closest('.brnwh-node');
			if (nEl) {
				const nid = nEl.dataset.id;
				const p = pos(nid);
				drag = { type: 'node', id: nid, el: nEl, sx: e.clientX, sy: e.clientY, ox: p.x, oy: p.y, moved: false, before: snapshot() };
				return;
			}
			drag = { type: 'pan', sx: e.clientX, sy: e.clientY, vx: view.x, vy: view.y, moved: false };
			board.classList.add('is-panning');
		});

		function onMove(e) {
			if (!drag) return;
			const dx = e.clientX - drag.sx;
			const dy = e.clientY - drag.sy;
			if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 4) return;
			drag.moved = true;
			if (drag.type === 'pan') {
				view.x = drag.vx + dx;
				view.y = drag.vy + dy;
				applyView();
			} else if (drag.type === 'node') {
				const p = pos(drag.id);
				p.x = snap(drag.ox + dx / view.z);
				p.y = snap(drag.oy + dy / view.z);
				drag.el.style.left = p.x + 'px';
				drag.el.style.top = p.y + 'px';
				drag.el.classList.add('is-dragging');
				renderEdges();
			} else if (drag.type === 'connect') {
				const a = portPoint(drag.from, drag.port);
				const b = toWorld(e.clientX, e.clientY);
				if (!tempEdge) {
					tempEdge = svg('path', { class: 'brnwh-edge is-temp' });
					edgeLayer.appendChild(tempEdge);
				}
				tempEdge.setAttribute('d', curve(a, b).d);
				const over = document.elementFromPoint(e.clientX, e.clientY);
				const target = over && over.closest('.brnwh-node');
				Object.keys(nodeEls).forEach(function (k) { nodeEls[k].classList.toggle('is-drop', !!target && nodeEls[k] === target && k !== drag.from); });
			}
		}

		function onUp(e) {
			if (!drag) return;
			const d = drag;
			drag = null;
			board.classList.remove('is-panning');
			if (tempEdge) {
				tempEdge.remove();
				tempEdge = null;
			}
			Object.keys(nodeEls).forEach(function (k) { nodeEls[k].classList.remove('is-drop', 'is-dragging'); });
			if (d.type === 'pan') {
				if (!d.moved && selected !== null) select(null);
				return;
			}
			if (d.type === 'node') {
				if (d.moved) {
					past.push(d.before);
					future.length = 0;
					markDirty();
					renderEdges();
				} else if (selected !== d.id) {
					select(d.id);
				}
				return;
			}
			if (d.type === 'connect') {
				if (!d.moved) {
					openNodePicker(d.el, function (type) { addChild(d.from, d.port, type); });
					return;
				}
				const over = document.elementFromPoint(e.clientX, e.clientY);
				const target = over && over.closest('.brnwh-node');
				if (target && target.dataset.id !== d.from) {
					connect(d.from, d.port, target.dataset.id);
					return;
				}
				if (over && board.contains(over)) {
					const at = toWorld(e.clientX, e.clientY);
					openNodePicker({ left: e.clientX, right: e.clientX, top: e.clientY, bottom: e.clientY, width: 0, height: 0 }, function (type) { addChild(d.from, d.port, type, at); });
				}
			}
		}
		window.addEventListener('pointermove', onMove);
		window.addEventListener('pointerup', onUp);

		board.addEventListener('click', function (e) {
			const add = e.target.closest('.brnwh-port-add');
			if (add) {
				e.stopPropagation();
				openNodePicker(add, function (type) { addChild(add.dataset.node, add.dataset.port, type); });
			}
		});

		board.addEventListener('dblclick', function (e) {
			if (e.target.closest('.brnwh-node, .brnwh-board-tools, .brnwh-board-top, .brnwh-edge-tools')) return;
			if (!flow.trigger_type) return;
			const at = toWorld(e.clientX, e.clientY);
			openNodePicker({ left: e.clientX, right: e.clientX, top: e.clientY, bottom: e.clientY, width: 0, height: 0 }, function (type) { addLoose(type, at); });
		});

		board.addEventListener(
			'wheel',
			function (e) {
				if (e.target.closest('.brnwh-node-picker')) return;
				e.preventDefault();
				if (e.ctrlKey || e.metaKey) {
					zoomTo(view.z * Math.exp(-e.deltaY * 0.0022), e.clientX, e.clientY);
				} else {
					view.x -= e.deltaX;
					view.y -= e.deltaY;
					applyView();
				}
			},
			{ passive: false }
		);

		function onKey(e) {
			const mod = e.ctrlKey || e.metaKey;
			if (mod && e.key.toLowerCase() === 's') {
				e.preventDefault();
				save();
				return;
			}
			if (isTyping(e) || document.querySelector('.brnwh-modal')) return;
			if (mod && e.key.toLowerCase() === 'z' && !e.shiftKey) {
				e.preventDefault();
				undo();
			} else if (mod && (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey))) {
				e.preventDefault();
				redo();
			} else if (mod && e.key.toLowerCase() === 'd' && selected && selected !== 'trigger') {
				e.preventDefault();
				duplicateNode(node(selected));
			} else if ((e.key === 'Delete' || e.key === 'Backspace') && selected && selected !== 'trigger') {
				e.preventDefault();
				removeNode(node(selected));
			} else if (e.key === 'Escape') {
				select(null);
			}
		}
		document.addEventListener('keydown', onKey);

		/* -------------------------------------------------------- data for fields */

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

		function lookupPath(path, vars) {
			return path.split('.').reduce(function (cur, seg) { return cur && typeof cur === 'object' ? cur[seg] : undefined; }, vars);
		}

		/** Fields available to a node: trigger data, earlier steps on its branch, loop item, system. */
		function pickerGroups(n) {
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
			if (n && n !== 'trigger') {
				const vars = Object.assign({}, sampleData(), { _steps: stepOutputs });
				ancestors(n).forEach(function (x) {
					const a = x.node;
					const k = kindOf(a);
					if (k === 'loop' && x.via === 'each') {
						const list = [{ path: '_loop.index', label: t('Item number (1, 2, 3…)') }, { path: '_loop.count', label: t('Number of items') }];
						const m = /^\s*\{\{\s*([^}|]+?)\s*\}\}\s*$/.exec(a.config.items || '');
						const arr = m ? lookupPath(m[1], vars) : null;
						if (Array.isArray(arr) && arr.length) {
							if (arr[0] !== null && typeof arr[0] === 'object') W.flatten(arr[0], '_loop.item').forEach(function (f) { list.push({ path: f.path, value: f.value }); });
							else list.push({ path: '_loop.item', label: t('Current item'), value: arr[0] });
						} else list.push({ path: '_loop.item', label: t('Current item') });
						groups.push({ label: t('Loop item'), items: list });
						return;
					}
					if (k === 'filter' || k === 'delay') return;
					const name = title(a);
					const out = stepOutputs[a.id];
					const list = [];
					if (x.via === 'error') list.push({ path: '_steps.' + a.id + '.error', label: t('Error message') });
					if (out !== undefined && out !== null && typeof out === 'object') W.flatten(out, '_steps.' + a.id).forEach(function (f) { list.push({ path: f.path, value: f.value }); });
					else if (!list.length) list.push({ path: '_steps.' + a.id, label: t('Output (test this step to see its fields)') });
					groups.push({ label: name, items: list });
				});
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
			const first = flow.actions.find(function (a) { return a.type && kindOf(a) === 'action'; });
			const ac = first && defOf(first.type);
			if (tr && ac) {
				flow.name = tr.label + ' → ' + ac.label;
				nameInput.value = flow.name;
			}
		}

		/* -------------------------------------------------------- save etc. */

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
			undoBtn.disabled = !past.length;
			redoBtn.disabled = !future.length;
		}

		W.router.guard = function (silent) {
			if (!dirty) return true;
			return silent ? false : window.confirm(t('You have unsaved changes. Leave anyway?'));
		};
		W.main().classList.add('is-full');
		W.onLeave = function () {
			document.removeEventListener('keydown', onKey);
			window.removeEventListener('pointermove', onMove);
			window.removeEventListener('pointerup', onUp);
			window.removeEventListener('resize', onResize);
			W.main().classList.remove('is-full');
			closePicker();
			stopListening();
		};
		const onResize = W.debounce(function () { fit(); }, 150);
		window.addEventListener('resize', onResize);

		function validate(forActivate) {
			if (!flow.trigger_type) return t('Choose a trigger first.');
			if (forActivate) {
				const live = flow.actions.filter(function (a) { return a.type && isConnected(a) && a.enabled; });
				if (!live.some(function (a) { return kindOf(a) === 'action'; })) return t('Add at least one action before activating.');
				for (let i = 0; i < live.length; i++) {
					const def = defOf(live[i].type);
					if (def && def.connection_type && !def.connection_optional && !live[i].connection_id) {
						select(live[i].id);
						return t('"%s" needs a connection.', title(live[i]));
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
			if (sampleDirty || migrated) body.sample = Object.assign({}, flow.sample || {}, { steps: stepOutputs });
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
					dirty = false;
					sampleDirty = false;
					ok = true;
					if (wasNew) history.replaceState(null, '', '#/flows/' + flow.id);
					if (!quiet) W.toast(t('Flow saved'));
					if (!hadEndpoint && flow.endpoint && selected === 'trigger') renderInspector();
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

		/* -------------------------------------------------------- testing */

		function applyLog(log) {
			results = {};
			(log.steps || []).forEach(function (s) {
				if (!s.action_id) return;
				const prev = results[s.action_id];
				const r = Object.assign({}, s, { count: prev ? prev.count + 1 : 1, ports: prev ? prev.ports : [] });
				if (s.branch_taken) r.ports.push(s.branch_taken);
				if (s.kind === 'router') {
					const n = node(s.action_id);
					if (n) {
						(n.config.paths || []).forEach(function (p, i) {
							const label = p.name || t('Path %s', letter(i));
							if ((s.output && s.output.paths || []).indexOf(label) >= 0) r.ports.push('p:' + p.id);
						});
						if ((s.output && s.output.paths || []).indexOf(t('Otherwise')) >= 0) r.ports.push('else');
					}
				}
				if (s.kind === 'loop') r.ports.push('each', 'done');
				if (s.status === 'failed' && s.note) r.ports.push('error');
				if (s.status === 'success' && ['action', 'filter', 'delay'].indexOf(s.kind || 'action') >= 0) r.ports.push('out');
				// Show a failure even if a later iteration succeeded.
				if (prev && prev.status === 'failed') r.status = 'failed';
				results[s.action_id] = r;
				if (s.status === 'success' && s.output !== undefined && (s.kind || 'action') === 'action') stepOutputs[s.action_id] = s.output;
			});
		}

		function showBanner(log) {
			runBanner.hidden = false;
			const ok = log.status === 'success';
			W.mount(runBanner, h('div', { class: ['brnwh-run-banner-in', 'is-' + log.status] },
				W.badge(log.status),
				h('span', { class: 'brnwh-run-banner-msg' }, log.message || (ok ? t('All steps completed successfully.') : '')),
				W.btn(t('Details'), { size: 'sm', variant: 'ghost', onClick: function () { runModal(log); } }),
				W.iconBtn('x', { title: t('Clear test results'), onClick: function () { results = {}; lastRun = null; runBanner.hidden = true; render(); } })
			));
		}

		function runModal(log) {
			W.modal({ title: t('Test result'), size: 'lg', body: W.renderRun(log, { compact: true }), footer: function (m) { return [log.id && !log.deleted ? W.btn(t('Open in logs'), { variant: 'ghost', icon: 'external', onClick: function () { m.close(); W.navigate('/logs/' + log.id); } }) : null, W.btn(t('Close'), { variant: 'primary', onClick: function () { m.close(); } })]; } });
		}

		async function testFlow() {
			if (!flow.trigger_type) return W.toast(t('Choose a trigger first.'), 'error');
			await W.busy(testBtn, async function () {
				try {
					const log = await W.api('flows/test', { method: 'POST', body: { flow: Object.assign({}, payload(), { id: flow.id }), data: sampleData() } });
					lastRun = log;
					applyLog(log);
					sampleDirty = true;
					render();
					showBanner(log);
				} catch (e) {
					W.errorToast(e);
				}
			});
		}

		/* -------------------------------------------------------- listening */

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
			renderInspector();
			const started = Date.now();
			pollTimer = setInterval(async function () {
				if (Date.now() - started > 10 * 60 * 1000) {
					stopListening();
					renderInspector();
					return;
				}
				try {
					const res = await W.api('flows/' + flow.id + '/sample');
					if (res.sample && res.sample.captured_at && res.sample.captured_at !== since) {
						flow.sample = Object.assign({}, flow.sample || {}, { data: res.sample.data, captured_at: res.sample.captured_at });
						stopListening();
						W.toast(t('Test data received!'));
						loadKnown();
						refreshNode('trigger');
						renderInspector();
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
								refreshNode('trigger');
								renderInspector();
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

		function testDataCard() {
			const tr = W.integration('triggers', flow.trigger_type);
			const data = sampleData();
			const has = Object.keys(data).length > 0;
			let body;
			if (listening) {
				body = h('div', { class: 'brnwh-listen' },
					h('div', { class: 'brnwh-radar' }, icon('radar', 26)),
					h('strong', null, t('Waiting for data…')),
					h('p', { class: 'brnwh-muted' }, tr && tr.has_endpoint ? t('Send a request to the webhook URL now.') : t('Submit the form / trigger the event on your site now.')),
					tr && tr.id === 'webhook' ? curlExample() : null,
					W.btn(t('Stop'), { variant: 'ghost', size: 'sm', onClick: function () { stopListening(); renderInspector(); } })
				);
			} else {
				body = h('div', null,
					has
						? h('div', null, h('p', { class: 'brnwh-muted brnwh-small' }, t('Captured %s', W.timeAgo(flow.sample.captured_at))), W.jsonView(data, { open: 1 }))
						: h('div', { class: 'brnwh-sample-empty' }, icon('radar', 22), h('p', null, t('No test data yet. Click "Listen" and send a real request / submit the form, or enter data manually.'))),
					h('div', { class: 'brnwh-row is-wrap' },
						W.btn(has ? t('Listen again') : t('Listen for data'), { icon: 'radar', variant: has ? 'soft' : 'primary', size: 'sm', onClick: listen }),
						W.btn(has ? t('Edit') : t('Enter manually'), { icon: 'edit', variant: 'ghost', size: 'sm', onClick: editSample })
					)
				);
			}
			return section(t('Test data'), body, 'radar');
		}

		/* -------------------------------------------------------- inspector */

		function section(label, content, ic, extra) {
			return h('section', { class: 'brnwh-insp-sec' }, h('h4', null, ic ? icon(ic, 15) : null, h('span', null, label), extra || null), content);
		}

		function inspectorHead(ic, kicker, nameEl, actions) {
			return h('header', { class: 'brnwh-insp-head' }, ic, h('div', { class: 'brnwh-insp-titles' }, h('span', { class: 'brnwh-node-kicker' }, kicker), nameEl), h('div', { class: 'brnwh-insp-actions' }, actions || []), W.iconBtn('x', { title: t('Close'), onClick: function () { select(null); } }));
		}

		function renderInspector() {
			closePicker();
			let content;
			if (selected === 'trigger') content = triggerPanel();
			else if (selected && node(selected)) content = nodePanel(node(selected));
			else content = flowPanel();
			W.mount(inspector, content);
			board.classList.toggle('has-selection', !!selected);
		}

		function flowPanel() {
			const s = flow.settings;
			const retries = h('select', { class: 'brnwh-input is-sm', onChange: function (e) { s.retries = parseInt(e.target.value, 10); markDirty(); } }, [0, 1, 2, 3, 4, 5, 6].map(function (n) { return h('option', { value: String(n) }, n === 0 ? t('No retries') : t('%d retries', n)); }));
			retries.value = String(s.retries);
			const logSel = h('select', { class: 'brnwh-input is-sm', onChange: function (e) { s.log = e.target.value; markDirty(); } }, [['default', t('Site default')], ['all', t('Log everything')], ['errors', t('Only errors')], ['none', t('No logs')]].map(function (o) { return h('option', { value: o[0] }, o[1]); }));
			logSel.value = s.log || 'default';
			const parts = [
				h('header', { class: 'brnwh-insp-head is-flow' }, h('span', { class: 'brnwh-insp-flowicon' }, icon('flow', 20)), h('div', { class: 'brnwh-insp-titles' }, h('span', { class: 'brnwh-node-kicker' }, t('Flow')), h('strong', null, flow.name || t('Untitled flow')))),
				h('div', { class: 'brnwh-insp-tips' },
					h('strong', null, t('How to build')),
					h('ul', null,
						h('li', null, icon('plus', 13), t('Click + under a step to add the next one.')),
						h('li', null, icon('link', 13), t('Drag from a dot to another step to connect them.')),
						h('li', null, icon('branch', 13), t('Use If / else, Paths and Filter to make decisions.')),
						h('li', null, icon('play', 13), t('Test the flow — each step shows what happened.')),
						h('li', null, icon('grid', 13), t('Drag the background to move, Ctrl + scroll to zoom.'))
					)
				),
			];
			if (flow.trigger_type) parts.push(testDataCard());
			parts.push(
				section(t('Flow settings'), h('div', { class: 'brnwh-side-settings' },
					W.toggle({ checked: s.async !== false, label: t('Run in the background'), onChange: function (on) { s.async = on; markDirty(); } }),
					h('p', { class: 'brnwh-help' }, t('Visitors don\'t wait for the actions. Turn off only when the webhook must reply with the result.')),
					h('label', { class: 'brnwh-label' }, t('When a step fails')), retries,
					h('p', { class: 'brnwh-help' }, t('Retries after 1, 5, 15, 60 minutes… Successful steps are not repeated.')),
					h('label', { class: 'brnwh-label' }, t('Logging')), logSel
				), 'gear')
			);
			if (flow.id) {
				const runs = h('div', { class: 'brnwh-run-list is-compact' }, W.skeleton(2));
				parts.push(section(t('Recent runs'), runs, 'activity', h('a', { class: 'brnwh-link', href: '#/logs?flow=' + flow.id }, t('All'))));
				W.api('logs', { query: { flow_id: flow.id, per_page: 5 } }).then(function (res) {
					W.mount(runs, res.items.length ? h('div', null, res.items.map(W.runRow)) : h('p', { class: 'brnwh-muted brnwh-small' }, t('No runs yet.')));
				}, function () { W.clear(runs); });
			}
			return h('div', { class: 'brnwh-insp' }, parts);
		}

		function triggerPanel() {
			const tr = W.integration('triggers', flow.trigger_type);
			if (!tr) {
				return h('div', { class: 'brnwh-insp' },
					inspectorHead(h('span', { class: 'brnwh-node-ph' }, icon('zap', 18)), t('Trigger'), h('strong', null, t('What starts this flow?'))),
					h('div', { class: 'brnwh-insp-body' }, triggerPicker(function (tid) {
						remember();
						flow.trigger_type = tid;
						flow.trigger_config = {};
						const def = W.integration('triggers', tid);
						if (def) W.defaultsFor(def.fields, flow.trigger_config);
						markDirty();
						autoName();
						loadKnown();
						render();
						fit();
					}))
				);
			}
			const body = h('div', { class: 'brnwh-insp-body' });
			if (!tr.available) body.appendChild(h('div', { class: 'brnwh-alert is-warn' }, icon('alert', 16), h('span', null, tr.reason)));
			if (tr.description) body.appendChild(h('p', { class: 'brnwh-step-desc' }, tr.description));
			body.appendChild(
				W.renderFields(tr.fields, flow.trigger_config, {
					kind: 'trigger',
					integration: tr.id,
					values: function () { return flow.trigger_config; },
					connectionId: function () { return flow.trigger_config.connection_id || 0; },
					onChange: function (key) {
						markDirty();
						if (key === 'form_id' || key === 'connection_id') loadKnown();
					},
					pickerGroups: function () { return pickerGroups('trigger'); },
					endpoint: function () { return flow.endpoint; },
					onRotate: flow.id
						? async function () {
								const ok = await W.confirm({ title: t('Generate a new URL?'), message: t('The current URL stops working immediately. Update it everywhere it is used.'), confirm: t('Generate'), danger: true });
								if (!ok) return;
								try {
									const res = await W.api('flows/' + flow.id + '/rotate-key', { method: 'POST' });
									flow.endpoint = res.endpoint;
									W.toast(t('New URL generated'));
									renderInspector();
								} catch (e) {
									W.errorToast(e);
								}
						  }
						: null,
				})
			);
			return h('div', { class: 'brnwh-insp' },
				inspectorHead(W.appIcon(tr.icon, 36, tr.label), t('Trigger'), h('strong', null, tr.label), [
					W.btn(t('Change'), {
						size: 'sm',
						variant: 'ghost',
						onClick: async function () {
							const ok = await W.confirm({ title: t('Change trigger?'), message: t('The trigger settings will be reset. Your steps are kept.'), confirm: t('Change') });
							if (!ok) return;
							remember();
							flow.trigger_type = '';
							flow.trigger_config = {};
							markDirty();
							render();
						},
					}),
				]),
				body,
				testDataCard()
			);
		}

		function nodePanel(n) {
			const def = defOf(n.type);
			const k = kindOf(n);
			const nameEl = h('input', { class: 'brnwh-insp-name', value: n.name, placeholder: def ? def.label : n.type, 'aria-label': t('Step name'), onInput: function (e) { n.name = e.target.value; markDirty(); refreshSoon(n.id); } });
			const head = inspectorHead(W.appIcon(def ? def.icon : n.type, 36, title(n)), k === 'action' && LOGIC_ORDER.indexOf(n.type) < 0 ? (def ? def.group || t('Action') : t('Action')) : t('Logic'), nameEl, [
				W.toggle({ checked: n.enabled, small: true, title: n.enabled ? t('Step enabled') : t('Step disabled'), onChange: function (on) { n.enabled = on; markDirty(); refreshNode(n.id); } }),
				W.iconBtn('more', {
					title: t('Step options'),
					onClick: function (e) {
						W.menu(e.currentTarget, [
							{ label: t('Duplicate'), icon: 'copy', onClick: function () { duplicateNode(n); } },
							n.parent ? { label: t('Disconnect from the flow'), icon: 'link', onClick: function () { remember(); n.parent = ''; markDirty(); render(); } } : null,
							k === 'action' ? { label: n.continue_on_error ? t('Stop the flow if this step fails') : t('Continue even if this step fails'), icon: 'shield', onClick: function () { n.continue_on_error = !n.continue_on_error; markDirty(); renderInspector(); } } : null,
							'-',
							{ label: t('Delete step'), icon: 'trash', danger: true, onClick: function () { removeNode(n); } },
						]);
					},
				}),
			]);
			const body = h('div', { class: 'brnwh-insp-body' });
			if (def && !def.available) body.appendChild(h('div', { class: 'brnwh-alert is-warn' }, icon('alert', 16), h('span', null, def.reason)));
			if (!isConnected(n)) body.appendChild(h('div', { class: 'brnwh-alert is-warn' }, icon('alert', 16), h('span', null, t('This step is not connected, so it never runs. Drag a connection to it from a dot of another step.'))));
			if (def && def.description) body.appendChild(h('p', { class: 'brnwh-step-desc' }, def.description));
			const onCond = function () { markDirty(); refreshSoon(n.id); };

			if (k === 'filter') {
				body.appendChild(section(t('Only continue if…'), W.conditionsBuilder(n.conditions, { pickerGroups: function () { return pickerGroups(n); } }, onCond), 'filter'));
			} else if (k === 'if') {
				body.appendChild(section(t('Go down "Yes" when…'), W.conditionsBuilder(n.conditions, { pickerGroups: function () { return pickerGroups(n); } }, onCond), 'branch'));
				body.appendChild(h('div', { class: 'brnwh-yesno' }, h('span', { class: 'brnwh-chip-yes' }, t('Yes')), h('span', null, t('when the conditions match')), h('span', { class: 'brnwh-chip-no' }, t('No')), h('span', null, t('otherwise'))));
			} else if (k === 'router') {
				body.appendChild(routerEditor(n));
			} else {
				const fields = [];
				if (def && def.connection_type) {
					const ct = W.integration('connections', def.connection_type);
					fields.push({ key: 'connection_id', type: 'connection', connection_type: def.connection_type, label: def.connection_optional ? t('Credentials (optional)') : ct ? ct.label : t('Connection') });
				}
				n.config.connection_id = n.connection_id || 0;
				body.appendChild(
					W.renderFields(fields.concat(def ? def.fields : []), n.config, {
						kind: 'action',
						integration: def ? def.id : n.type,
						values: function () { return n.config; },
						connectionId: function () { return n.connection_id || 0; },
						onChange: function (key) {
							if (key === 'connection_id') n.connection_id = n.config.connection_id || 0;
							markDirty();
							refreshSoon(n.id);
						},
						pickerGroups: function () { return pickerGroups(n); },
						sampleKeys: sampleKeys,
					})
				);
				if (W.conditionsCount(n.conditions)) {
					body.appendChild(section(t('Only run this step if…'), h('div', null, W.conditionsBuilder(n.conditions, { pickerGroups: function () { return pickerGroups(n); } }, markDirty), W.btn(t('Remove step conditions'), { size: 'sm', variant: 'ghost', icon: 'x', onClick: function () { n.conditions = emptyConditions(); markDirty(); renderInspector(); } })), 'branch'));
				}
				if (n.continue_on_error) body.appendChild(h('div', { class: 'brnwh-alert is-info' }, icon('shield', 16), h('span', null, t('If this step fails, the flow continues with the next step.'))));
			}
			if (k === 'action') body.appendChild(h('p', { class: 'brnwh-help brnwh-insp-errhint' }, icon('alert', 13), t('Tip: drag from the small red dot to add steps that run only if this step fails.')));

			const r = resultFor(n.id);
			const out = h('div', { class: 'brnwh-step-result' });
			if (r) out.appendChild(W.renderStep(r, true));
			const foot = [];
			if (k === 'action') {
				const testBtn2 = W.btn(t('Test this step'), {
					icon: 'play',
					variant: 'soft',
					onClick: function () {
						W.busy(testBtn2, async function () {
							W.clear(out);
							try {
								const log = await W.api('flows/test-step', { method: 'POST', body: { flow: Object.assign({}, payload(), { id: flow.id }), step_id: n.id, data: sampleData(), step_outputs: stepOutputs } });
								const step = (log.steps || []).filter(function (s) { return s.action_id === n.id; }).pop();
								if (step) {
									results[n.id] = Object.assign({}, step, { count: 1, ports: step.status === 'success' ? ['out'] : [] });
									if (step.status === 'success') {
										stepOutputs[n.id] = step.output;
										sampleDirty = true;
										markDirty();
									}
									refreshNode(n.id);
									renderInspector();
								}
							} catch (e) {
								out.appendChild(h('div', { class: 'brnwh-alert is-error' }, icon('alert', 16), h('span', null, e.message)));
							}
						});
					},
				});
				foot.push(h('div', { class: 'brnwh-step-foot' }, testBtn2, h('span', { class: 'brnwh-muted' }, Object.keys(sampleData()).length ? t('Uses your test data') : t('No test data yet — values will be empty'))));
			}
			return h('div', { class: 'brnwh-insp' }, head, body, foot, r || foot.length ? section(t('Last test'), out, 'activity') : null);
		}

		function routerEditor(n) {
			const c = n.config;
			c.paths = c.paths || [];
			const box = h('div');
			function draw() {
				W.clear(box);
				const mode = h('div', { class: 'brnwh-segment', role: 'radiogroup' }, [['first', t('First matching path')], ['all', t('Every matching path')]].map(function (o) {
					return h('button', { type: 'button', class: ['brnwh-segment-item', (c.mode || 'first') === o[0] ? 'is-on' : ''], onClick: function () { c.mode = o[0]; markDirty(); refreshNode(n.id); draw(); } }, o[1]);
				}));
				box.appendChild(section(t('Which paths run?'), h('div', null, mode, h('p', { class: 'brnwh-help' }, (c.mode || 'first') === 'first' ? t('Paths are checked top to bottom; only the first one that matches runs (if / else if).') : t('Every path whose rules match runs, one after the other.'))), 'branch'));
				c.paths.forEach(function (p, i) {
					const nameIn = h('input', { class: 'brnwh-input is-sm', value: p.name, placeholder: t('Path %s', letter(i)), onInput: function (e) { p.name = e.target.value; markDirty(); refreshSoon(n.id); } });
					box.appendChild(
						h('div', { class: 'brnwh-path-card' },
							h('div', { class: 'brnwh-path-head' },
								h('span', { class: 'brnwh-path-letter' }, letter(i)),
								nameIn,
								i > 0 ? W.iconBtn('up', { title: t('Move up'), onClick: function () { c.paths.splice(i - 1, 0, c.paths.splice(i, 1)[0]); markDirty(); refreshNode(n.id); draw(); } }) : null,
								W.iconBtn('trash', {
									title: t('Delete path'),
									onClick: async function () {
										const kids = children(n.id, 'p:' + p.id);
										if (kids.length && !(await W.confirm({ title: t('Delete this path?'), message: t('Steps on this path will be disconnected (not deleted).'), confirm: t('Delete'), danger: true }))) return;
										remember();
										kids.forEach(function (k) { k.parent = ''; });
										c.paths.splice(i, 1);
										markDirty();
										render();
									},
								})
							),
							h('p', { class: 'brnwh-help' }, t('Run this path when… (no rules = always)')),
							W.conditionsBuilder(p.conditions, { pickerGroups: function () { return pickerGroups(n); } }, function () { markDirty(); })
						)
					);
				});
				box.appendChild(
					h('div', { class: 'brnwh-row is-wrap' },
						W.btn(t('Add path'), { icon: 'plus', variant: 'soft', size: 'sm', onClick: function () { remember(); c.paths.push({ id: W.uid(), name: '', conditions: oneRule() }); markDirty(); refreshNode(n.id); draw(); } })
					)
				);
				box.appendChild(
					h('div', { class: 'brnwh-path-card is-else' },
						W.toggle({
							checked: c.else !== false,
							label: t('"Otherwise" path — runs when no other path matches'),
							onChange: function (on) {
								remember();
								c.else = on;
								if (!on) children(n.id, 'else').forEach(function (k) { k.parent = ''; });
								markDirty();
								render();
							},
						})
					)
				);
			}
			draw();
			return box;
		}

		/* -------------------------------------------------------- page */

		const top = h(
			'div',
			{ class: 'brnwh-editor-top' },
			W.iconBtn(RTL ? 'arrowRight' : 'arrowLeft', { title: t('Back to flows'), onClick: function () { W.navigate('/flows'); } }),
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

		W.page(h('div', { class: 'brnwh-editor is-canvas' }, top, h('div', { class: 'brnwh-studio' }, board, inspector)));
		if (!flow.trigger_type) selected = 'trigger';
		renderNodes();
		const needsLayout = migrated || flow.actions.some(function (a) { return a.parent && a.ui.x === 0 && a.ui.y === 0; });
		if (needsLayout) tidy();
		render();
		applyView();
		requestAnimationFrame(function () {
			fit();
		});
		loadKnown();
		if (!flow.name) setTimeout(function () { if (flow.trigger_type) nameInput.focus(); }, 100);
	};
})();

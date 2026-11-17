/**
 * BRN Webhooks — schema-driven form renderer and the conditions builder.
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon, append, clear } = W;
	const MASK = '__brnwh_keep__';

	function defaultsFor(fields, values) {
		(fields || []).forEach(function (f) {
			if (!f.key || f.key.charAt(0) === '_') return;
			if (values[f.key] === undefined && f.default !== undefined) values[f.key] = W.clone(f.default);
		});
		return values;
	}

	function visible(f, values) {
		if (!f.show_if) return true;
		return Object.keys(f.show_if).every(function (k) {
			const want = f.show_if[k];
			const field = values[k];
			const have = field === undefined || field === null ? '' : String(field);
			return Array.isArray(want) ? want.map(String).indexOf(have) >= 0 : String(want) === have;
		});
	}

	/** Small "{ }" button that opens the field picker for an input. */
	function pickerButton(input, ctx) {
		if (!ctx || !ctx.pickerGroups) return null;
		return h(
			'button',
			{
				type: 'button',
				class: 'brnwh-picker-btn',
				title: t('Insert a field'),
				'aria-label': t('Insert a field'),
				onMousedown: function (e) {
					e.preventDefault();
				},
				onClick: function (e) {
					W.fieldPicker(e.currentTarget, input, ctx.pickerGroups());
				},
			},
			icon('braces', 15)
		);
	}

	/** Highlight {{tokens}} under an input (a light visual hint). */
	function withPicker(input, ctx, template) {
		if (!template) return input;
		return h('div', { class: 'brnwh-tpl' }, input, pickerButton(input, ctx));
	}

	/**
	 * Render fields.
	 * ctx: { kind, integration, values, onChange, pickerGroups, endpoint, connections, onNewConnection, connectionId }
	 */
	function renderFields(fields, values, ctx) {
		ctx = ctx || {};
		defaultsFor(fields, values);
		const wrap = h('div', { class: 'brnwh-fields' });
		const rows = [];
		const reloaders = {};

		function changed(key) {
			rows.forEach(function (r) {
				r.el.hidden = !visible(r.f, values);
			});
			Object.keys(reloaders).forEach(function (k) {
				const r = reloaders[k];
				if (r.deps.indexOf(key) >= 0) r.reload();
			});
			if (ctx.onChange) ctx.onChange(key);
		}
		ctx._changed = changed;

		(fields || []).forEach(function (f) {
			const el = renderField(f, values, ctx, changed, reloaders);
			if (!el) return;
			el.hidden = !visible(f, values);
			rows.push({ f: f, el: el });
			wrap.appendChild(el);
		});
		wrap._refresh = function () {
			changed('__external__');
		};
		return wrap;
	}

	function label(f) {
		if (!f.label) return null;
		return h('label', { class: 'brnwh-label' }, f.label, f.required ? h('span', { class: 'brnwh-req', 'aria-hidden': 'true' }, '*') : null);
	}

	function help(f) {
		return f.help ? h('p', { class: 'brnwh-help' }, f.help) : null;
	}

	function renderField(f, values, ctx, changed, reloaders) {
		const set = function (v) {
			values[f.key] = v;
			changed(f.key);
		};
		const cls = ['brnwh-field', 'is-' + f.type, f.width ? 'w-' + f.width : ''];

		switch (f.type) {
			case 'heading':
				return h('div', { class: 'brnwh-field-heading' }, h('span', null, f.label));

			case 'info':
				return h('div', { class: 'brnwh-note', html: f.html || '' });

			case 'endpoint': {
				const url = ctx.endpoint ? ctx.endpoint() : '';
				if (!url) {
					return h('div', { class: cls }, label(f), h('div', { class: 'brnwh-endpoint is-pending' }, icon('info', 16), h('span', null, t('Save the flow to get your unique URL.'))));
				}
				return h(
					'div',
					{ class: cls },
					label(f),
					h(
						'div',
						{ class: 'brnwh-endpoint' },
						h('span', { class: 'brnwh-method' }, 'URL'),
						h('code', { dir: 'ltr' }, url),
						W.btn(t('Copy'), { icon: 'copy', size: 'sm', variant: 'soft', onClick: function () { W.copy(url); } }),
						ctx.onRotate ? W.iconBtn('refresh', { title: t('Generate a new URL'), onClick: ctx.onRotate }) : null
					),
					help(f)
				);
			}

			case 'toggle':
				return h('div', { class: cls }, W.toggle({ checked: !!values[f.key], label: f.label, onChange: set }), help(f));

			case 'choice': {
				const group = h('div', { class: 'brnwh-segment', role: 'radiogroup' });
				const draw = function () {
					clear(group);
					(f.options || []).forEach(function (o) {
						const on = String(values[f.key]) === String(o.value);
						group.appendChild(
							h('button', { type: 'button', role: 'radio', 'aria-checked': on ? 'true' : 'false', class: ['brnwh-segment-item', on ? 'is-on' : ''], onClick: function () { set(o.value); draw(); } }, o.label)
						);
					});
				};
				draw();
				return h('div', { class: cls }, label(f), group, help(f));
			}

			case 'select':
				return selectField(f, values, ctx, changed, reloaders, cls);

			case 'connection':
				return connectionField(f, values, ctx, changed, cls);

			case 'keyvalue':
				return h('div', { class: cls }, label(f), keyValue(values, f, ctx, changed), help(f));

			case 'mapping':
				return mappingField(f, values, ctx, changed, reloaders, cls);

			case 'action_button': {
				const out = h('div', { class: 'brnwh-inline-result' });
				const b = W.btn(f.button_label || f.label, {
					icon: 'zap',
					variant: 'soft',
					onClick: function () {
						W.busy(b, async function () {
							clear(out);
							try {
								const res = await loadOptions(f.source, ctx);
								append(out, [h('span', { class: 'brnwh-ok' }, icon('check', 15), res && res.message ? res.message : t('Done'))]);
							} catch (e) {
								append(out, [h('span', { class: 'brnwh-err' }, icon('alert', 15), e.message)]);
							}
						});
					},
				});
				return h('div', { class: cls }, h('div', { class: 'brnwh-row' }, b, out), help(f));
			}

			case 'textarea':
			case 'code': {
				const isMask = values[f.key] === MASK;
				const ta = h('textarea', {
					class: ['brnwh-input', f.type === 'code' || f.mono ? 'is-mono' : ''],
					rows: f.rows || 4,
					placeholder: isMask ? t('Saved — leave empty to keep the current value') : f.placeholder || '',
					dir: f.type === 'code' ? 'ltr' : null,
					spellcheck: f.type === 'code' ? 'false' : null,
					onInput: function (e) {
						values[f.key] = isMask && e.target.value === '' ? MASK : e.target.value;
						changed(f.key);
					},
				});
				ta.value = isMask ? '' : values[f.key] === undefined || values[f.key] === null ? '' : String(values[f.key]);
				const tools = f.file
					? h(
							'div',
							{ class: 'brnwh-field-tools' },
							W.btn(t('Upload file'), {
								icon: 'upload',
								size: 'sm',
								variant: 'ghost',
								onClick: async function () {
									const file = await W.pickFile(f.file);
									if (file) {
										ta.value = file.text;
										values[f.key] = file.text;
										changed(f.key);
									}
								},
							})
					  )
					: null;
				return h('div', { class: cls }, h('div', { class: 'brnwh-label-row' }, label(f), tools), withPicker(ta, ctx, f.template), help(f));
			}

			default: {
				// text, url, email, number, password.
				const isPw = f.type === 'password';
				const isMask = values[f.key] === MASK;
				const input = h('input', {
					class: ['brnwh-input', f.mono || isPw || f.type === 'url' ? 'is-mono' : ''],
					type: isPw && !f.reveal ? 'password' : f.type === 'number' ? 'number' : 'text',
					placeholder: isMask ? t('Saved — leave empty to keep the current value') : f.placeholder || '',
					autocomplete: isPw ? 'new-password' : 'off',
					dir: f.type === 'url' || isPw || f.mono ? 'ltr' : null,
					spellcheck: 'false',
					onInput: function (e) {
						let v = e.target.value;
						if (f.type === 'number') v = v === '' ? '' : Number(v);
						values[f.key] = isMask && v === '' ? MASK : v;
						changed(f.key);
					},
				});
				input.value = isMask ? '' : values[f.key] === undefined || values[f.key] === null ? '' : String(values[f.key]);
				let control = withPicker(input, ctx, f.template);
				if (isPw) {
					const tools = [];
					const eye = W.iconBtn(input.type === 'password' ? 'eye' : 'eyeOff', {
						title: t('Show / hide'),
						onClick: function () {
							input.type = input.type === 'password' ? 'text' : 'password';
							eye.replaceChild(icon(input.type === 'password' ? 'eye' : 'eyeOff', 17), eye.firstChild);
						},
					});
					tools.push(eye);
					if (f.generate) {
						tools.push(
							W.iconBtn('dice', {
								title: t('Generate'),
								onClick: function () {
									input.value = W.randomToken(20);
									input.type = 'text';
									values[f.key] = input.value;
									changed(f.key);
								},
							})
						);
						tools.push(
							W.iconBtn('copy', {
								title: t('Copy'),
								onClick: function () {
									if (input.value) W.copy(input.value);
								},
							})
						);
					}
					control = h('div', { class: 'brnwh-input-group' }, input, h('div', { class: 'brnwh-input-addons' }, tools));
				}
				return h('div', { class: cls }, label(f), control, help(f));
			}
		}
	}

	/* -------------------------------------------------- dynamic options */

	async function loadOptions(source, ctx) {
		const values = ctx.values();
		return W.api('options', {
			method: 'POST',
			body: {
				kind: ctx.kind,
				integration: ctx.integration,
				source: source,
				config: values,
				connection_id: ctx.connectionId ? ctx.connectionId() : values.connection_id || 0,
			},
		});
	}

	function selectField(f, values, ctx, changed, reloaders, cls) {
		const sel = h('select', { class: 'brnwh-input' });
		const status = h('span', { class: 'brnwh-select-status' });
		let options = (f.options || []).slice();

		function draw() {
			clear(sel);
			const cur = values[f.key] === undefined || values[f.key] === null ? '' : String(values[f.key]);
			let found = false;
			if (!options.length || (f.options_source && !options.some(function (o) { return o.value === ''; }) && cur === '')) {
				sel.appendChild(h('option', { value: '' }, f.options_source ? t('— Choose —') : '—'));
			}
			options.forEach(function (o) {
				if (String(o.value) === cur) found = true;
				sel.appendChild(h('option', { value: o.value }, o.label));
			});
			if (cur !== '' && !found) sel.appendChild(h('option', { value: cur }, cur));
			if (f.allow_custom) sel.appendChild(h('option', { value: '__custom__' }, '✎ ' + t('Custom value…')));
			sel.value = cur;
		}
		sel.addEventListener('change', async function () {
			if (sel.value === '__custom__') {
				const v = await W.prompt({ title: f.label, label: t('Enter a value'), value: values[f.key] || '' });
				if (v !== null) values[f.key] = v.trim();
				draw();
				changed(f.key);
				return;
			}
			values[f.key] = sel.value;
			changed(f.key);
		});

		async function reload(silent) {
			if (!f.options_source) return;
			status.className = 'brnwh-select-status is-loading';
			status.textContent = '';
			try {
				const res = await loadOptions(f.options_source, ctx);
				if (Array.isArray(res)) {
					options = res.length ? res : (f.options || []).slice();
					status.className = 'brnwh-select-status';
				}
			} catch (e) {
				status.className = 'brnwh-select-status is-error';
				status.textContent = silent ? '' : e.message;
				status.title = e.message;
			}
			draw();
		}
		draw();
		if (f.options_source) {
			reloaders[f.key] = { deps: f.depends_on || [], reload: W.debounce(function () { reload(true); }, 500) };
			// Load once without blocking.
			setTimeout(function () {
				reload(true);
			}, 30);
		}
		const refresh = f.options_source ? W.iconBtn('refresh', { title: t('Reload'), onClick: function () { reload(false); } }) : null;
		return h('div', { class: cls }, label(f), h('div', { class: 'brnwh-select-row' }, sel, refresh), status, help(f));
	}

	function connectionField(f, values, ctx, changed, cls) {
		const type = f.connection_type;
		const sel = h('select', { class: 'brnwh-input' });
		function draw() {
			clear(sel);
			const list = (W.state.connections || []).filter(function (c) {
				return c.type === type;
			});
			sel.appendChild(h('option', { value: '0' }, list.length ? t('— Choose a connection —') : t('No connections yet')));
			list.forEach(function (c) {
				sel.appendChild(h('option', { value: String(c.id) }, c.name + (c.summary ? ' · ' + c.summary : '')));
			});
			sel.value = String(values[f.key] || 0);
		}
		sel.addEventListener('change', function () {
			values[f.key] = parseInt(sel.value, 10) || 0;
			changed(f.key);
		});
		draw();
		const add = W.btn(t('New'), {
			icon: 'plus',
			size: 'sm',
			variant: 'soft',
			onClick: function () {
				W.views.connections.openEditor(type, null, function (conn) {
					values[f.key] = conn.id;
					draw();
					changed(f.key);
				});
			},
		});
		return h('div', { class: cls }, label(f), h('div', { class: 'brnwh-select-row' }, sel, add), help(f));
	}

	/* ------------------------------------------------------- key / value */

	function keyValue(values, f, ctx, changed) {
		if (!Array.isArray(values[f.key])) values[f.key] = [];
		const list = values[f.key];
		const box = h('div', { class: 'brnwh-kv' });
		function draw() {
			clear(box);
			list.forEach(function (row, i) {
				const k = h('input', { class: 'brnwh-input is-mono', dir: 'ltr', placeholder: f.key_placeholder || t('key'), value: row.key || '', onInput: function (e) { row.key = e.target.value; changed(f.key); } });
				const v = h('input', { class: 'brnwh-input', placeholder: f.value_placeholder || t('value or {{field}}'), value: row.value || '', onInput: function (e) { row.value = e.target.value; changed(f.key); } });
				box.appendChild(h('div', { class: 'brnwh-kv-row' }, k, withPicker(v, ctx, f.template !== false), W.iconBtn('x', { title: t('Remove'), onClick: function () { list.splice(i, 1); draw(); changed(f.key); } })));
			});
			box.appendChild(
				h('div', { class: 'brnwh-kv-actions' },
					W.btn(t('Add field'), { icon: 'plus', size: 'sm', variant: 'ghost', onClick: function () { list.push({ key: '', value: '' }); draw(); changed(f.key); const inputs = box.querySelectorAll('.brnwh-kv-row input'); if (inputs.length) inputs[inputs.length - 2].focus(); } }),
					ctx.sampleKeys && f.key === 'body'
						? W.btn(t('Add all trigger fields'), {
								icon: 'sparkles',
								size: 'sm',
								variant: 'ghost',
								onClick: function () {
									const keys = ctx.sampleKeys();
									if (!keys.length) return W.toast(t('No sample data yet. Send test data to the trigger first.'), 'info');
									keys.forEach(function (key) {
										if (!list.some(function (r) { return r.key === key; })) list.push({ key: key, value: '{{' + key + '}}' });
									});
									draw();
									changed(f.key);
								},
						  })
						: null
				)
			);
		}
		draw();
		return box;
	}

	/* ------------------------------------------------- column mapping */

	function mappingField(f, values, ctx, changed, reloaders, cls) {
		if (!Array.isArray(values[f.key])) values[f.key] = [];
		const box = h('div', { class: 'brnwh-mapping' });
		const status = h('div', { class: 'brnwh-inline-result' });

		function draw() {
			clear(box);
			const cols = values[f.key];
			if (!cols.length) {
				box.appendChild(h('div', { class: 'brnwh-mapping-empty' }, icon('grid', 18), h('span', null, t('Load the columns from the sheet to map your fields.'))));
				return;
			}
			cols.forEach(function (col) {
				const input = h('input', { class: 'brnwh-input', placeholder: t('Leave empty to skip'), value: col.value || '', onInput: function (e) { col.value = e.target.value; changed(f.key); } });
				box.appendChild(h('div', { class: 'brnwh-map-row' }, h('div', { class: 'brnwh-map-col' }, h('span', { class: 'brnwh-map-letter' }, col.letter || ''), h('span', { class: 'brnwh-map-name' }, col.column)), icon(W.boot.rtl ? 'arrowLeft' : 'arrowRight', 14), withPicker(input, ctx, true)));
			});
		}
		async function load(btnEl) {
			clear(status);
			await W.busy(btnEl, async function () {
				try {
					const res = await loadOptions(f.columns_source, ctx);
					const fresh = (res && res.columns) || [];
					const old = values[f.key];
					const auto = ctx.sampleKeys ? ctx.sampleKeys() : [];
					values[f.key] = fresh.map(function (c) {
						const prev = old.find(function (o) { return o.column === c.column; }) || old.find(function (o) { return o.index === c.index; });
						let guess = '';
						if (!prev) {
							const norm = String(c.column).toLowerCase().replace(/[\s_\-]+/g, '');
							const hit = auto.find(function (k) { return String(k).toLowerCase().replace(/[\s_\-]+/g, '') === norm; });
							if (hit) guess = '{{' + hit + '}}';
						}
						return { index: c.index, column: c.column, letter: c.letter, value: prev ? prev.value : guess };
					});
					append(status, [h('span', { class: 'brnwh-ok' }, icon('check', 15), t('%d columns loaded', fresh.length))]);
					draw();
					changed(f.key);
				} catch (e) {
					append(status, [h('span', { class: 'brnwh-err' }, icon('alert', 15), e.message)]);
				}
			});
		}
		const loadBtn = W.btn(t('Load columns'), { icon: 'refresh', size: 'sm', variant: 'soft', onClick: function () { load(loadBtn); } });
		draw();
		return h('div', { class: cls }, h('div', { class: 'brnwh-label-row' }, label(f), loadBtn), status, box, help(f));
	}

	/* ------------------------------------------------------- conditions */

	const OPS = [
		['equals', 'is'],
		['not_equals', 'is not'],
		['contains', 'contains'],
		['not_contains', 'does not contain'],
		['starts_with', 'starts with'],
		['ends_with', 'ends with'],
		['empty', 'is empty'],
		['not_empty', 'is not empty'],
		['gt', 'greater than'],
		['gte', 'greater or equal'],
		['lt', 'less than'],
		['lte', 'less or equal'],
		['in_list', 'is one of (comma list)'],
		['not_in_list', 'is not one of'],
		['regex', 'matches regex'],
		['is_email', 'is a valid email'],
		['is_phone', 'is a valid phone'],
		['is_true', 'is true / yes'],
		['is_false', 'is false / no'],
		['exists', 'exists'],
		['not_exists', 'does not exist'],
		['date_before', 'date is before'],
		['date_after', 'date is after'],
	];
	const UNARY = ['empty', 'not_empty', 'is_true', 'is_false', 'is_email', 'is_phone', 'exists', 'not_exists'];

	function conditionsBuilder(cond, ctx, onChange) {
		if (!cond.groups) cond.groups = [];
		if (!cond.logic) cond.logic = 'or';
		const box = h('div', { class: 'brnwh-conds' });
		const fire = function () {
			if (onChange) onChange();
		};

		function draw() {
			clear(box);
			cond.groups.forEach(function (g, gi) {
				if (gi > 0) {
					box.appendChild(
						h('div', { class: 'brnwh-cond-join' },
							h('button', { type: 'button', class: 'brnwh-pill', onClick: function () { cond.logic = cond.logic === 'or' ? 'and' : 'or'; draw(); fire(); } }, cond.logic === 'or' ? t('OR') : t('AND'))
						)
					);
				}
				const rows = h('div', { class: 'brnwh-cond-rows' });
				g.rules.forEach(function (r, ri) {
					if (ri > 0) {
						rows.appendChild(h('div', { class: 'brnwh-cond-inner' }, h('button', { type: 'button', class: 'brnwh-pill is-small', onClick: function () { g.logic = g.logic === 'and' ? 'or' : 'and'; draw(); fire(); } }, g.logic === 'or' ? t('OR') : t('AND'))));
					}
					const field = h('input', { class: 'brnwh-input is-mono', dir: 'ltr', placeholder: t('field, e.g. email'), value: r.field || '', onInput: function (e) { r.field = e.target.value; fire(); } });
					const pick = h(
						'button',
						{
							type: 'button',
							class: 'brnwh-picker-btn',
							title: t('Choose a field'),
							onClick: function (e) {
								W.fieldPicker(e.currentTarget, null, ctx.pickerGroups(), function (token) {
									r.field = token.replace(/^\{\{|\}\}$/g, '');
									field.value = r.field;
									fire();
								});
							},
						},
						icon('braces', 15)
					);
					const op = h('select', { class: 'brnwh-input', onChange: function (e) { r.op = e.target.value; draw(); fire(); } }, OPS.map(function (o) { return h('option', { value: o[0] }, t(o[1])); }));
					op.value = r.op || 'equals';
					const value = UNARY.indexOf(r.op) >= 0 ? h('div', { class: 'brnwh-cond-novalue' }) : withPicker(h('input', { class: 'brnwh-input', placeholder: t('value'), value: r.value || '', onInput: function (e) { r.value = e.target.value; fire(); } }), ctx, true);
					rows.appendChild(
						h('div', { class: 'brnwh-cond-row' },
							h('div', { class: 'brnwh-tpl' }, field, pick),
							op,
							value,
							W.iconBtn('x', { title: t('Remove'), onClick: function () { g.rules.splice(ri, 1); if (!g.rules.length) cond.groups.splice(gi, 1); draw(); fire(); } })
						)
					);
				});
				rows.appendChild(h('div', { class: 'brnwh-cond-add' }, W.btn(t('And / or another rule'), { icon: 'plus', size: 'sm', variant: 'ghost', onClick: function () { g.rules.push({ field: '', op: 'equals', value: '' }); draw(); fire(); } })));
				box.appendChild(h('div', { class: 'brnwh-cond-group' }, rows));
			});
			box.appendChild(
				h('div', { class: 'brnwh-cond-footer' },
					W.btn(cond.groups.length ? t('Add rule group') : t('Add a condition'), {
						icon: 'plus',
						size: 'sm',
						variant: 'soft',
						onClick: function () {
							cond.groups.push({ logic: 'and', rules: [{ field: '', op: 'equals', value: '' }] });
							draw();
							fire();
						},
					}),
					cond.groups.length > 1 ? h('span', { class: 'brnwh-muted' }, cond.logic === 'or' ? t('Runs when any group matches') : t('Runs when all groups match')) : null
				)
			);
		}
		draw();
		return box;
	}

	W.MASK = MASK;
	W.renderFields = renderFields;
	W.defaultsFor = defaultsFor;
	W.conditionsBuilder = conditionsBuilder;
	W.conditionsCount = function (c) {
		return c && c.groups ? c.groups.reduce(function (n, g) { return n + (g.rules ? g.rules.length : 0); }, 0) : 0;
	};
	W.OPS = OPS;
})();

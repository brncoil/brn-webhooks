/**
 * BRN Webhooks — settings and updates.
 */
(function () {
	'use strict';

	const W = window.BRNWH;
	const { h, t, icon } = W;

	W.views.settings = async function () {
		W.page(h('div', { class: 'brnwh-page' }, W.skeleton(6)));
		let s;
		try {
			s = await W.api('settings');
		} catch (e) {
			W.page(W.empty({ icon: 'alert', title: t('Could not load settings'), text: e.message }));
			return;
		}
		let dirty = false;
		const v = W.clone(s);
		const mark = function () {
			dirty = true;
			saveBtn.classList.add('is-pulse');
		};
		W.router.guard = function (silent) {
			return !dirty || (!silent && window.confirm(t('You have unsaved changes. Leave anyway?')));
		};

		function num(key, min, max, suffix) {
			return h('div', { class: 'brnwh-num' }, h('input', { class: 'brnwh-input', type: 'number', min: min, max: max, value: v[key], onInput: function (e) { v[key] = parseInt(e.target.value, 10) || 0; mark(); } }), suffix ? h('span', { class: 'brnwh-muted' }, suffix) : null);
		}
		function row(labelText, control, helpText) {
			return h('div', { class: 'brnwh-set-row' }, h('div', { class: 'brnwh-set-label' }, h('strong', null, labelText), helpText ? h('p', { class: 'brnwh-help' }, helpText) : null), h('div', { class: 'brnwh-set-control' }, control));
		}
		function tog(key) {
			return W.toggle({ checked: !!v[key], onChange: function (on) { v[key] = on; mark(); } });
		}

		// Updates card.
		const u = s.update || {};
		const updBox = h('div', { class: 'brnwh-update' });
		function drawUpdate(info) {
			W.mount(updBox, h('div', { class: 'brnwh-update-inner' },
				h('div', { class: 'brnwh-update-ver' },
					h('div', null, h('span', { class: 'brnwh-muted' }, t('Installed')), h('strong', null, 'v' + info.current)),
					h('div', null, h('span', { class: 'brnwh-muted' }, t('Latest on GitHub')), h('strong', null, info.latest ? 'v' + info.latest : '—')),
					info.available ? h('a', { class: 'brnwh-btn is-primary', href: info.update_url }, icon('download', 16), h('span', null, t('Update now'))) : info.latest ? h('span', { class: 'brnwh-ok' }, icon('check', 15), t('Up to date')) : null
				),
				info.error ? h('div', { class: 'brnwh-alert is-warn' }, icon('alert', 15), h('span', null, info.error === 'not_found_or_private' ? t('No release found on GitHub yet.') : t('GitHub check failed: %s', info.error))) : null,
				h('p', { class: 'brnwh-muted brnwh-small' }, t('Repository: %s', info.repo), info.checked_at ? ' · ' + t('checked %s', W.timeAgo(info.checked_at)) : '')
			));
		}
		drawUpdate(u);
		const checkBtn = W.btn(t('Check now'), {
			icon: 'refresh',
			size: 'sm',
			variant: 'soft',
			onClick: function () {
				W.busy(checkBtn, async function () {
					try {
						const res = await W.api('updates/check', { method: 'POST' });
						drawUpdate(res);
						W.toast(res.available ? t('Version %s is available', res.latest) : t('You have the latest version'), res.available ? 'info' : 'success');
					} catch (e) {
						W.errorToast(e);
					}
				});
			},
		});

		const level = h('div', { class: 'brnwh-segment' });
		function drawLevel() {
			W.clear(level);
			[['all', t('Everything')], ['errors', t('Errors only')], ['none', t('Nothing')]].forEach(function (o) {
				level.appendChild(h('button', { type: 'button', class: ['brnwh-segment-item', v.log_level === o[0] ? 'is-on' : ''], onClick: function () { v.log_level = o[0]; mark(); drawLevel(); } }, o[1]));
			});
		}
		drawLevel();

		const notifyEmail = h('input', { class: 'brnwh-input', type: 'email', placeholder: s.notify_email_default, value: v.notify_email || '', onInput: function (e) { v.notify_email = e.target.value; mark(); } });
		const metaVer = h('input', { class: 'brnwh-input is-mono', dir: 'ltr', value: v.meta_api_version, onInput: function (e) { v.meta_api_version = e.target.value.trim(); mark(); } });

		const saveBtn = W.btn(t('Save settings'), {
			icon: 'save',
			variant: 'primary',
			onClick: function () {
				W.busy(saveBtn, async function () {
					try {
						const res = await W.api('settings', { method: 'PUT', body: v });
						Object.assign(v, res);
						W.state.settings = res;
						dirty = false;
						saveBtn.classList.remove('is-pulse');
						if (res.update) drawUpdate(res.update);
						W.toast(t('Settings saved'));
					} catch (e) {
						W.errorToast(e);
					}
				});
			},
		});

		W.page(
			h('div', { class: 'brnwh-page brnwh-settings' },
				h('div', { class: 'brnwh-page-head' }, h('div', null, h('h1', null, t('Settings')), h('p', null, t('Logs, reliability, security and automatic updates.'))), saveBtn),
				W.card(t('Updates'), h('div', null,
					updBox,
					row(t('Automatic updates'), tog('auto_update'), t('Install new versions from GitHub automatically (WordPress checks twice a day).'))
				), { actions: checkBtn }),
				W.card(t('Logs'), h('div', null,
					row(t('What to log'), level, t('Runs waiting for a retry are always kept until they finish.')),
					row(t('Keep logs for'), num('log_retention_days', 1, 3650, t('days'))),
					row(t('Log filtered runs'), tog('log_filtered'), t('Runs that stopped because conditions were not met.')),
					row(t('Mask sensitive fields'), tog('mask_sensitive'), t('Values of fields like password, token, card number are hidden in logs.'))
				)),
				W.card(t('Reliability'), h('div', null,
					row(t('Default retries for new flows'), num('default_retries', 0, 10)),
					row(t('Email me when a flow fails'), tog('notify_failures'), t('At most one email per flow per hour.')),
					row(t('Notification email'), notifyEmail)
				)),
				W.card(t('Security'), h('div', null,
					row(t('Rate limit'), num('rate_limit', 1, 100000, t('requests / minute / webhook')), t('Incoming requests above the limit get HTTP 429.')),
					row(t('Max payload size'), num('max_payload_kb', 16, 20480, 'KB')),
					row(t('Allow requests to internal addresses'), tog('allow_internal_urls'), t('Off = HTTP steps cannot call localhost / private network IPs (SSRF protection). Turn on only if you need to call a server on your network.')),
					row(t('Trust proxy headers'), tog('trust_proxy'), t('Use X-Forwarded-For / CF-Connecting-IP for IP allowlists. Enable only behind Cloudflare or a trusted proxy.')),
					row(t('Encryption key'), s.encryption_constant ? h('span', { class: 'brnwh-ok' }, icon('shield', 15), t('BRNWH_ENCRYPTION_KEY is defined')) : h('span', { class: 'brnwh-muted' }, icon('lock', 15), ' ', t('Automatic (site keys)')), t('Automatic — nothing to set up. Saved credentials are encrypted with the secret keys of this site.'))
				)),
				W.card(t('Advanced'), h('div', null,
					row(t('Meta Graph API version'), metaVer, t('Used for the Conversions API and Lead Ads.')),
					row(t('Delete all data on uninstall'), tog('delete_on_uninstall'), t('Flows, connections and logs are removed when the plugin is deleted.'))
				))
			)
		);
	};
})();

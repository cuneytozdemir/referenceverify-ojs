/**
 * @file plugins/generic/referenceVerify/js/referenceVerify.js
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief OJS 3.5 workflow: adds a "ReferenceVerify" item to the submission's side menu (editorial dashboard only)
 *  through the supported extension point pkp.registry.storeExtend('workflow'). The item appears only when the server
 *  allows the current user to use it for this submission (status request, editors with access to the submission).
 *  Its panel lists the manuscript files with the report buttons, the waiting summaries ("Get results") and the
 *  reviewer suggestions. Configuration and texts come from window.pkpReferenceVerify (set by the plugin).
 */
(function () {
	if (!window.pkp || !pkp.registry || !pkp.modules || !pkp.modules.vue) {
		return;
	}
	var vue = pkp.modules.vue;
	var MENU_KEY = 'referenceVerify';

	function config() {
		return window.pkpReferenceVerify || {urls: {}, i18n: {}};
	}

	function t(key, count) {
		var text = config().i18n[key] || key;
		return count === undefined ? text : text.split('{$count}').join(String(count));
	}

	// Per submission: {loading, allowed, data, error}. Reactive, so the menu label and the panel follow it.
	var state = vue.reactive({});

	function entry(submissionId) {
		if (!state[submissionId]) {
			state[submissionId] = {loading: false, allowed: false, data: null, error: ''};
		}
		return state[submissionId];
	}

	function post(url, fields) {
		var body = new URLSearchParams();
		body.append('csrfToken', config().csrfToken);
		Object.keys(fields).forEach(function (k) {
			body.append(k, fields[k]);
		});
		return fetch(url, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			headers: {Accept: 'application/json'},
		}).then(function (res) {
			return res.text().then(function (text) {
				try {
					return JSON.parse(text);
				} catch (e) {
					return null; // not JSON: no access (OJS answers with a page) or the server failed
				}
			});
		});
	}

	function load(submissionId) {
		var e = entry(submissionId);
		if (e.loading) {
			return Promise.resolve();
		}
		e.loading = true;
		return post(config().urls.status, {submissionId: submissionId})
			.then(function (json) {
				if (json && json.status && json.content) {
					e.allowed = true;
					e.data = json.content;
					e.error = '';
				} else if (json && typeof json.content === 'string') {
					e.allowed = true;
					e.error = json.content;
				} else {
					e.allowed = false;
				}
			})
			.catch(function () {
				// Network problem: show the item with an error rather than hiding it.
				e.allowed = true;
				e.error = t('tab.loadError');
			})
			.then(function () {
				e.loading = false;
			});
	}

	// A form posted into a new tab (the check and the reviewer list open on their own pages).
	function postToNewTab(url, fields) {
		var form = document.createElement('form');
		form.method = 'post';
		form.action = url;
		form.target = '_blank';
		form.style.display = 'none';
		fields.csrfToken = config().csrfToken;
		Object.keys(fields).forEach(function (k) {
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = k;
			input.value = fields[k];
			form.appendChild(input);
		});
		document.body.appendChild(form);
		form.submit();
		document.body.removeChild(form);
	}

	pkp.registry.registerComponent('ReferenceVerifyPanel', {
		name: 'ReferenceVerifyPanel',
		props: {
			submissionId: {type: Number, required: true},
		},
		data: function () {
			return {pulling: false, pullMessage: '', pullError: '', pullWritten: 0};
		},
		computed: {
			entry: function () {
				return entry(this.submissionId);
			},
			info: function () {
				return this.entry.data;
			},
		},
		methods: {
			t: t,
			check: function (file, tool) {
				postToNewTab(config().urls.check, {submissionId: this.submissionId, submissionFileId: file.id, tool: tool});
			},
			reviewers: function () {
				postToNewTab(config().urls.reviewers, {submissionId: this.submissionId});
			},
			pull: function () {
				var self = this;
				self.pulling = true;
				self.pullMessage = '';
				self.pullError = '';
				post(config().urls.pull, {submissionId: self.submissionId, format: 'json'})
					.then(function (json) {
						if (json && json.status && json.content) {
							self.pullMessage = json.content.message;
							self.pullWritten = json.content.written;
						} else {
							self.pullError = json && typeof json.content === 'string' ? json.content : t('error.connection');
						}
					})
					.catch(function () {
						self.pullError = t('error.connection');
					})
					.then(function () {
						self.pulling = false;
						return load(self.submissionId);
					});
			},
			// The stage page of the workflow, where the new discussions are listed.
			openDiscussions: function () {
				var store = pkp.registry.getPiniaStore('workflow');
				var submission = store.submission;
				if (!submission) {
					return;
				}
				var key = 'workflow_' + submission.stageId;
				if (submission.stageId === pkp.const.WORKFLOW_STAGE_ID_EXTERNAL_REVIEW || submission.stageId === pkp.const.WORKFLOW_STAGE_ID_INTERNAL_REVIEW) {
					var rounds = (submission.reviewRounds || []).filter(function (r) {
						return r.stageId === submission.stageId;
					});
					if (rounds.length) {
						key += '_' + rounds[rounds.length - 1].id;
					}
				}
				store.navigateToMenu(key);
			},
		},
		template:
			'<div class="rvPanel flex flex-col gap-y-4 bg-secondary text-base-normal" data-cy="reference-verify-panel">' +
			'  <p class="m-0">{{ t("tab.intro") }}</p>' +
			'  <p v-if="entry.loading && !info" class="m-0 text-secondary" role="status">{{ t("tab.loading") }}</p>' +
			'  <p v-if="entry.error" class="m-0 border-s-4 border-negative bg-tertiary px-3 py-2 text-negative" role="alert">{{ entry.error }}</p>' +
			'  <template v-if="info">' +
			'    <p v-if="info.autoWritten" class="m-0 border-s-4 border-success bg-tertiary px-3 py-2" role="status">{{ t("tab.autoWritten", info.autoWritten) }}</p>' +
			'    <div v-if="info.pendingCount" class="flex flex-wrap items-center gap-3 border-s-4 border-attention bg-tertiary px-3 py-2" role="status">' +
			'      <span class="flex-1 text-base-bold">{{ t("tab.pendingBanner", info.pendingCount) }}</span>' +
			'      <PkpButton :is-primary="true" :is-disabled="pulling" @click="pull">{{ t("tab.pull") }}</PkpButton>' +
			'    </div>' +
			'    <p v-if="!info.configured" class="m-0 border-s-4 border-attention bg-tertiary px-3 py-2 text-base-bold">{{ t("tab.notConfigured") }}</p>' +
			'    <p v-else-if="!info.files.length" class="m-0">{{ t("tab.noFiles") }}</p>' +
			'    <template v-else>' +
			'      <table class="w-full border-collapse border border-light bg-tertiary">' +
			'        <thead><tr class="border-b border-light text-start">' +
			'          <th class="px-3 py-2 text-start text-base-bold">{{ t("tab.file") }}</th>' +
			'          <th class="px-3 py-2 text-start text-base-bold">{{ t("tab.stage") }}</th>' +
			'          <th class="px-3 py-2"></th>' +
			'        </tr></thead>' +
			'        <tbody>' +
			'          <tr v-for="file in info.files" :key="file.id" class="border-b border-light align-top" data-cy="reference-verify-file">' +
			'            <td class="px-3 py-2 break-all">{{ file.name }}' +
			'              <span v-if="file.pending" class="ms-2 inline-block rounded border border-attention px-2 text-sm-normal">{{ t("tab.pendingFile") }}</span>' +
			'            </td>' +
			'            <td class="px-3 py-2">{{ file.stage }}</td>' +
			'            <td class="px-3 py-2">' +
			'              <div v-if="file.supported" class="flex flex-wrap justify-end gap-2">' +
			'                <PkpButton v-for="b in info.tools" :key="b.tool" :title="b.help" @click="check(file, b.tool)">{{ b.label }}</PkpButton>' +
			'              </div>' +
			'              <span v-else class="block text-sm-normal text-secondary" style="text-align:end">{{ t("tab.unsupported") }}</span>' +
			'            </td>' +
			'          </tr>' +
			'        </tbody>' +
			'      </table>' +
			'      <p class="m-0 text-sm-normal text-secondary">{{ t("tab.privacy") }}</p>' +
			'    </template>' +
			'    <div v-if="info.configured" class="flex flex-wrap gap-6 border-t border-light pt-4">' +
			'      <div class="flex-1" style="min-width:16rem">' +
			'        <p class="m-0 mb-2 text-base-bold">{{ t("tab.results") }}</p>' +
			'        <p class="m-0 mb-3 text-sm-normal text-secondary">{{ t("tab.resultsHelp") }}</p>' +
			'        <PkpButton :is-disabled="pulling" @click="pull">{{ t("tab.pull") }}</PkpButton>' +
			'        <div v-if="pullMessage" class="mt-3 flex flex-col items-start gap-y-1" role="status">' +
			'          <p class="m-0">{{ pullMessage }}</p>' +
			'          <PkpButton v-if="pullWritten" :is-link="true" @click="openDiscussions">{{ t("tab.openDiscussions") }}</PkpButton>' +
			'        </div>' +
			'        <p v-if="pullError" class="m-0 mt-3 text-negative" role="alert">{{ pullError }}</p>' +
			'      </div>' +
			'      <div class="flex-1" style="min-width:16rem">' +
			'        <p class="m-0 mb-2 text-base-bold">{{ t("tab.reviewers") }}</p>' +
			'        <p class="m-0 mb-3 text-sm-normal text-secondary">{{ t("tab.reviewersHelp") }}</p>' +
			'        <PkpButton @click="reviewers">{{ t("tab.reviewersButton") }}</PkpButton>' +
			'      </div>' +
			'    </div>' +
			'  </template>' +
			'</div>',
	});

	pkp.registry.storeExtend('workflow', function (piniaContext) {
		var store = piniaContext.store;
		if (store.dashboardPage !== 'editorialDashboard') {
			return; // authors ("My submissions") and reviewers never see it
		}

		vue.watch(
			function () {
				return store.submission ? store.submission.id : null;
			},
			function (id) {
				if (id) {
					load(id);
				}
			},
			{immediate: true}
		);

		store.extender.extendFn('getMenuItems', function (menuItems, args) {
			var submission = args && args.submission;
			if (!submission || args.dashboardPage !== 'editorialDashboard') {
				return menuItems;
			}
			var e = state[submission.id]; // read only here: entries are created by load()
			if (!e || !e.allowed) {
				return menuItems;
			}
			var pending = e.data && e.data.pendingCount ? e.data.pendingCount : 0;
			return menuItems.concat([
				{
					key: MENU_KEY,
					label: t('tab') + (pending ? ' (' + pending + ')' : ''),
					icon: 'Book',
					state: {primaryMenuItem: MENU_KEY, title: t('tab')},
				},
			]);
		});

		store.extender.extendFn('getPrimaryItems', function (primaryItems, args) {
			if (args && args.selectedMenuState && args.selectedMenuState.primaryMenuItem === MENU_KEY && args.submission) {
				return [{component: 'ReferenceVerifyPanel', props: {submissionId: args.submission.id}}];
			}
			return primaryItems;
		});
	});
})();

<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifyPlugin.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class ReferenceVerifyPlugin
 * @ingroup plugins_generic_referenceVerify
 *
 * @brief Adds a "ReferenceVerify" tab to the editorial workflow. For each Word/PDF submission file an editor
 *  can click "Check with ReferenceVerify": the file is handed over (server to server, HTTPS, journal plugin key)
 *  to ReferenceVerify as a short-lived, encrypted, single-use transfer, and the editor's browser opens the
 *  ReferenceVerify check with that file. The verification itself runs in the editor's browser; nothing is
 *  stored permanently on the ReferenceVerify side.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class ReferenceVerifyPlugin extends GenericPlugin {

	/** Workflow roles allowed to send a file (journal manager / editor, section editor, site admin). */
	const ALLOWED_ROLES = [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR];

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled($mainContextId)) {
			HookRegistry::register('Template::Workflow', [$this, 'addWorkflowTab']);
			HookRegistry::register('LoadHandler', [$this, 'setupHandler']);
		}
		return $success;
	}

	function getDisplayName() {
		return __('plugins.generic.referenceVerify.displayName');
	}

	function getDescription() {
		return __('plugins.generic.referenceVerify.description');
	}

	/**
	 * Site the editor's browser is sent to and the transfer endpoint.
	 * @return array [apiBase, siteBase, langPrefix]
	 */
	function getEndpoints($contextId) {
		// Site language follows the OJS interface language of the editor at click time:
		// Turkish OJS -> kaynakcadogrula.com, any other language -> referenceverify.com. No setting needed.
		$lang = strpos((string) AppLocale::getLocale(), 'tr') === 0 ? 'tr' : 'en';
		$custom = rtrim((string) $this->getSetting($contextId, 'baseUrl'), '/');
		$base = $custom !== '' ? $custom : ($lang === 'en' ? 'https://referenceverify.com' : 'https://kaynakcadogrula.com');
		// referenceverify.com serves the English site at its root; any other host (e.g. a test server)
		// serves it under /en.
		$prefix = ($lang === 'en' && !preg_match('#^https?://(www\.)?referenceverify\.com$#i', $base)) ? '/en' : '';
		return [$base, $base, $prefix, $lang];
	}

	/** Can the current user send files from this submission? */
	function userMaySend($request, $contextId) {
		$user = $request->getUser();
		if (!$user) return false;
		return $user->hasRole(self::ALLOWED_ROLES, $contextId) || $user->hasRole([ROLE_ID_SITE_ADMIN], CONTEXT_SITE);
	}

	/**
	 * Template::Workflow — append a "ReferenceVerify" tab to the workflow tabs (editors only).
	 */
	function addWorkflowTab($hookName, $params) {
		$templateMgr = $params[1];
		$output =& $params[2];
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$submission = $templateMgr->getTemplateVars('submission');
		if (!$context || !$submission || !$this->userMaySend($request, $context->getId())) return false;

		$files = [];
		$submissionFiles = Services::get('submissionFile')->getMany(['submissionIds' => [$submission->getId()]]);
		foreach ($submissionFiles as $sf) {
			if (!$this->isManuscriptStage((int) $sf->getData('fileStage'))) continue;
			$files[] = [
				'id' => $sf->getId(),
				'name' => (string) $sf->getLocalizedData('name'),
				'stage' => $this->stageLabel((int) $sf->getData('fileStage')),
				'supported' => $this->isSupportedFile($sf),
			];
		}
		$apiKey = trim((string) $this->getSetting($context->getId(), 'apiKey'));

		// 1.1.1 — summaries the editor sent from ReferenceVerify but not yet written to OJS. One short request per
		// workflow view (only counts, never content); if ReferenceVerify is slow or unreachable the tab simply
		// shows no badge. With the "write automatically" setting they are written here as editor discussions.
		$pendingFiles = [];
		$autoWritten = 0;
		if ($apiKey !== '') {
			$pending = $this->pendingResults($context->getId(), $apiKey, (int) $submission->getId());
			if ($pending && $pending['count'] > 0 && $this->getSetting($context->getId(), 'autoPull')) {
				list($written, $errorKey) = $this->writeResults($request, $submission);
				if ($errorKey === null && $written > 0) {
					$autoWritten = $written;
					$pending = $this->pendingResults($context->getId(), $apiKey, (int) $submission->getId());
				}
			}
			if ($pending) $pendingFiles = $pending['files'];
		}
		$pendingByFile = array_count_values(array_map('intval', $pendingFiles));
		foreach ($files as $i => $f) $files[$i]['pending'] = isset($pendingByFile[(int) $f['id']]) ? $pendingByFile[(int) $f['id']] : 0;

		$templateMgr->assign([
			'rvPendingCount' => count($pendingFiles),
			'rvAutoWritten' => $autoWritten,
			'rvFiles' => $files,
			'rvSubmissionId' => $submission->getId(),
			'rvConfigured' => $apiKey !== '',
			'rvActionUrl' => $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'referenceverify', 'check'),
			'rvPullUrl' => $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'referenceverify', 'pull'),
			'rvReviewersUrl' => $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'referenceverify', 'reviewers'),
		]);
		$output .= $templateMgr->fetch($this->getTemplateResource('workflowTab.tpl'));
		return false;
	}

	/**
	 * 1.1.1 — how many report summaries are waiting for this submission (sent from ReferenceVerify, not yet written
	 * to OJS). Short timeouts: this runs while the workflow page renders. @return array|null ['count', 'files'[]]
	 */
	function pendingResults($contextId, $apiKey, $submissionId) {
		list($apiBase) = $this->getEndpoints($contextId);
		list($status, $data) = $this->callApi($apiBase . '/api/ojs/result/pending', $apiKey, ['submissionId' => (int) $submissionId], 4, 3);
		if ($status !== 200 || !is_array($data) || !isset($data['files']) || !is_array($data['files'])) return null;
		$files = [];
		foreach ($data['files'] as $id) if ((int) $id > 0) $files[] = (int) $id;
		return ['count' => count($files), 'files' => $files];
	}

	/**
	 * Writes every summary sent for this submission as an OJS discussion between the editors, then confirms it to
	 * ReferenceVerify, which deletes it. Participants: the current user and the editors assigned to the submission
	 * (never its authors). Used by "Get results" and, when enabled, by the workflow tab (1.1.1).
	 * @return array [int writtenCount, string|null errorLocaleKey]
	 */
	function writeResults($request, $submission) {
		$context = $request->getContext();
		$apiKey = trim((string) $this->getSetting($context->getId(), 'apiKey'));
		if ($apiKey === '') return [0, 'plugins.generic.referenceVerify.error.notConfigured'];
		list($apiBase) = $this->getEndpoints($context->getId());

		list($status, $data) = $this->callApi($apiBase . '/api/ojs/result/pull', $apiKey, ['submissionId' => (int) $submission->getId()], 30);
		if ($status !== 200 || !is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
			return [0, $this->errorKey($data)];
		}

		$user = $request->getUser();
		$stageId = (int) $submission->getData('stageId') ?: WORKFLOW_STAGE_ID_SUBMISSION;
		$participants = $this->editorParticipants((int) $submission->getId(), (int) $user->getId());
		// 1.2.0: a report the editor explicitly chose to share with the author ("Share with the author" on
		// ReferenceVerify) is written to a discussion that also includes the submission's authors.
		$withAuthors = array_values(array_unique(array_merge($participants, $this->authorParticipants((int) $submission->getId()))));
		$queryDao = DAORegistry::getDAO('QueryDAO'); /** @var QueryDAO $queryDao */
		$noteDao = DAORegistry::getDAO('NoteDAO'); /** @var NoteDAO $noteDao */
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();
		$written = [];
		foreach ($data['results'] as $r) {
			if (!is_array($r) || empty($r['id']) || !isset($r['subject'], $r['text'])) continue;
			$query = $queryDao->newDataObject(); /** @var Query $query */
			$query->setAssocType(ASSOC_TYPE_SUBMISSION);
			$query->setAssocId($submission->getId());
			$query->setStageId($stageId);
			$query->setSequence(REALLY_BIG_NUMBER);
			$queryDao->insertObject($query);
			$queryDao->resequence(ASSOC_TYPE_SUBMISSION, $submission->getId());
			$people = (isset($r['audience']) && $r['audience'] === 'author') ? $withAuthors : $participants;
			foreach ($people as $userId) $queryDao->insertParticipant($query->getId(), $userId);

			$note = $noteDao->newDataObject();
			$note->setUserId($user->getId());
			$note->setAssocType(ASSOC_TYPE_QUERY);
			$note->setAssocId($query->getId());
			$note->setDateCreated(Core::getCurrentDate());
			$note->setTitle(PKPString::substr((string) $r['subject'], 0, 255));
			$note->setContents($this->textToHtml((string) $r['text']));
			$noteDao->insertObject($note);

			foreach ($people as $userId) {
				if ($userId === (int) $user->getId()) continue;
				$notificationManager->createNotification($request, $userId, NOTIFICATION_TYPE_NEW_QUERY, $context->getId(),
					ASSOC_TYPE_QUERY, $query->getId(), NOTIFICATION_LEVEL_TASK);
			}
			$written[] = (int) $r['id'];
		}

		if ($written) {
			$submission->stampLastActivity();
			DAORegistry::getDAO('SubmissionDAO')->updateObject($submission);
			// Written to OJS: ReferenceVerify can delete them. If this confirmation fails the rows expire on their own
			// within 7 days; a second "Get results" in the meantime would add them again, so it is retried once.
			list($ackStatus) = $this->callApi($apiBase . '/api/ojs/result/ack', $apiKey, ['ids' => $written], 15);
			if ($ackStatus !== 200) $this->callApi($apiBase . '/api/ojs/result/ack', $apiKey, ['ids' => $written], 15);
		}
		return [count($written), null];
	}

	/** Editors assigned to the submission + the current user; anyone also assigned as an author is left out. */
	function editorParticipants($submissionId, $currentUserId) {
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao */
		$ids = [$currentUserId => true];
		foreach ([ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR] as $roleId) {
			$assignments = $stageAssignmentDao->getBySubmissionAndRoleId($submissionId, $roleId);
			while ($a = $assignments->next()) $ids[(int) $a->getUserId()] = true;
		}
		$authorAssignments = $stageAssignmentDao->getBySubmissionAndRoleId($submissionId, ROLE_ID_AUTHOR);
		while ($a = $authorAssignments->next()) {
			if ((int) $a->getUserId() !== $currentUserId) unset($ids[(int) $a->getUserId()]);
		}
		return array_keys($ids);
	}

	/** Users assigned to the submission as authors (1.2.0: only for reports the editor shared with the author). */
	function authorParticipants($submissionId) {
		$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao */
		$ids = [];
		$assignments = $stageAssignmentDao->getBySubmissionAndRoleId($submissionId, ROLE_ID_AUTHOR);
		while ($a = $assignments->next()) $ids[(int) $a->getUserId()] = true;
		return array_keys($ids);
	}

	/** Plain text from ReferenceVerify → safe discussion HTML: escaped, paragraphs, line breaks, report link. */
	function textToHtml($text) {
		$html = [];
		foreach (preg_split("/\n{2,}/", trim(str_replace("\r", '', $text))) as $block) {
			$p = nl2br(htmlspecialchars($block, ENT_QUOTES, 'UTF-8'), false);
			$p = preg_replace('#(https://[A-Za-z0-9./_-]+)#', '<a href="$1" target="_blank" rel="noopener">$1</a>', $p);
			$html[] = '<p>' . $p . '</p>';
		}
		return implode("\n", $html);
	}

	/** JSON POST to ReferenceVerify with the journal key. @return array [httpStatus, decodedBody|null] */
	function callApi($url, $apiKey, $payload, $timeout, $connectTimeout = 15) {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CONNECTTIMEOUT => $connectTimeout,
			CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'],
			CURLOPT_POSTFIELDS => json_encode($payload),
		]);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		$data = is_string($body) ? json_decode($body, true) : null;
		if ($status !== 200) {
			$code = is_array($data) && isset($data['code']) ? $data['code'] : '';
			error_log('ReferenceVerify ' . basename($url) . ' failed: HTTP ' . $status . ($curlError ? ' ' . $curlError : '') . ($code ? ' ' . $code : ''));
		}
		return [$status, $data];
	}

	/** Locale key for an error response; key problems first, then the operation's own codes. */
	function errorKey($data, $own = [], $fallback = 'plugins.generic.referenceVerify.error.connection') {
		$code = is_array($data) && isset($data['code']) ? $data['code'] : '';
		$map = array_merge([
			'key' => 'plugins.generic.referenceVerify.error.key',
			'revoked' => 'plugins.generic.referenceVerify.error.revoked',
			'rate' => 'plugins.generic.referenceVerify.error.rate',
		], $own);
		return isset($map[$code]) ? $map[$code] : $fallback;
	}

	/**
	 * Manuscript file stages only: submission, review round, revisions, copyediting, production/proofs.
	 * Reviewer attachments, discussion (query) files, notes and dependent files (images of a galley) are not
	 * manuscripts and are not listed.
	 */
	function isManuscriptStage($fileStage) {
		return in_array((int) $fileStage, [
			SUBMISSION_FILE_SUBMISSION,
			SUBMISSION_FILE_REVIEW_FILE,
			SUBMISSION_FILE_REVIEW_REVISION,
			SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
			SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
			SUBMISSION_FILE_FINAL,
			SUBMISSION_FILE_COPYEDIT,
			SUBMISSION_FILE_PRODUCTION_READY,
			SUBMISSION_FILE_PROOF,
		], true);
	}

	/**
	 * Word / PDF / RTF, recognised by file name OR by the stored MIME type (OJS keeps names without an
	 * extension when the author typed one). ReferenceVerify checks the content again on arrival.
	 */
	function isSupportedFile($sf) {
		$name = (string) $sf->getLocalizedData('name');
		if (preg_match('/\.(docx?|pdf|rtf)$/i', $name)) return true;
		$mime = strtolower((string) $sf->getData('mimetype'));
		return in_array($mime, [
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/msword',
			'application/pdf',
			'application/rtf',
			'text/rtf',
		], true);
	}

	function stageLabel($fileStage) {
		switch ($fileStage) {
			case SUBMISSION_FILE_SUBMISSION: return __('plugins.generic.referenceVerify.stage.submission');
			case SUBMISSION_FILE_REVIEW_FILE:
			case SUBMISSION_FILE_REVIEW_ATTACHMENT:
			case SUBMISSION_FILE_REVIEW_REVISION: return __('plugins.generic.referenceVerify.stage.review');
			case SUBMISSION_FILE_FINAL:
			case SUBMISSION_FILE_COPYEDIT: return __('plugins.generic.referenceVerify.stage.copyedit');
			case SUBMISSION_FILE_PRODUCTION_READY:
			case SUBMISSION_FILE_PROOF: return __('plugins.generic.referenceVerify.stage.production');
			default: return __('plugins.generic.referenceVerify.stage.other');
		}
	}

	/**
	 * LoadHandler — route index.php/<journal>/referenceverify/check to our handler.
	 */
	function setupHandler($hookName, $params) {
		$page = $params[0];
		if ($page !== 'referenceverify') return false;
		$this->import('ReferenceVerifyHandler');
		define('HANDLER_CLASS', 'ReferenceVerifyHandler');
		ReferenceVerifyHandler::setPlugin($this);
		return true;
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled() ? [
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			] : [],
			parent::getActions($request, $verb)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		switch ($request->getUserVar('verb')) {
			case 'settings':
				$context = $request->getContext();
				AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
				$this->import('ReferenceVerifySettingsForm');
				$form = new ReferenceVerifySettingsForm($this, $context->getId());
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}
}

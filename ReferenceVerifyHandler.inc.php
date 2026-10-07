<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifyHandler.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class ReferenceVerifyHandler
 * @ingroup plugins_generic_referenceVerify
 *
 * @brief POST index.php/<journal>/referenceverify/check — sends one submission file to ReferenceVerify
 *  (server to server, HTTPS, journal plugin key) and redirects the editor's browser to the check page with
 *  a short-lived single-use token.
 *  1.1.0:
 *  - POST …/referenceverify/pull — fetches the report summaries the editor chose to send from ReferenceVerify and
 *    adds each one to the submission as a discussion between the editors (authors are never participants).
 *  - POST …/referenceverify/reviewers — reviewer suggestions for the submission's title/abstract/authors.
 *  1.1.1: the discussion-writing core moved to ReferenceVerifyPlugin::writeResults() so the workflow tab can show
 *  "N summaries waiting" and (optional setting) write them automatically.
 */

import('classes.handler.Handler');

class ReferenceVerifyHandler extends Handler {

	/** @var ReferenceVerifyPlugin */
	static $plugin;

	static function setPlugin($plugin) {
		self::$plugin = $plugin;
	}

	function __construct() {
		parent::__construct();
		$this->addRoleAssignment([ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_SITE_ADMIN], ['check', 'pull', 'reviewers']);
	}

	/**
	 * Editors only, and only for submissions they can access (section editors: their assignments).
	 */
	function authorize($request, &$args, $roleAssignments) {
		import('lib.pkp.classes.security.authorization.SubmissionAccessPolicy');
		$this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments, 'submissionId'));
		return parent::authorize($request, $args, $roleAssignments);
	}

	function check($args, $request) {
		$plugin = self::$plugin;
		$context = $request->getContext();
		if (!$request->isPost() || !$request->checkCSRF()) {
			return $this->fail($request, __('plugins.generic.referenceVerify.error.request'));
		}
		$submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
		$submissionFileId = (int) $request->getUserVar('submissionFileId');
		$sf = $submissionFileId ? Services::get('submissionFile')->get($submissionFileId) : null;
		if (!$sf || (int) $sf->getData('submissionId') !== (int) $submission->getId() || !$plugin->isManuscriptStage((int) $sf->getData('fileStage'))) {
			return $this->fail($request, __('plugins.generic.referenceVerify.error.file'));
		}
		if (!$plugin->isSupportedFile($sf)) {
			return $this->fail($request, __('plugins.generic.referenceVerify.error.type'));
		}

		$apiKey = trim((string) $plugin->getSetting($context->getId(), 'apiKey'));
		if ($apiKey === '') return $this->fail($request, __('plugins.generic.referenceVerify.error.notConfigured'));
		list($apiBase, $siteBase, $prefix, $lang) = $plugin->getEndpoints($context->getId());
		// 1.2.0: the editor picks the report per file (pilot journal request). bib = reference list, cite = in-text
		// citations, full = consolidated (every reference + in-text citations <-> reference list in both directions).
		$tool = (string) $request->getUserVar('tool');
		if (!in_array($tool, ['bib', 'cite', 'full'], true)) $tool = 'bib';

		// Large files over slow links can take longer than the host's default 30 s limit (on Windows hosts network
		// waiting counts towards it). Raise it for this request only; ignored where the host forbids it.
		if (function_exists('set_time_limit')) @set_time_limit(300);

		// Copy the stored file (OJS 3.3 file service, Flysystem) to a temporary file as a STREAM: a 50 MB file is
		// never held in PHP memory (hosts often allow 128 MB). The temporary file is deleted right after the transfer.
		$fileService = Services::get('file');
		$file = $fileService->get($sf->getData('fileId'));
		if (!$file) return $this->fail($request, __('plugins.generic.referenceVerify.error.file'));
		$name = (string) $sf->getLocalizedData('name');
		$tmp = tempnam(sys_get_temp_dir(), 'rvojs');
		$in = $fileService->fs->readStream($file->path);
		$out = $tmp !== false ? fopen($tmp, 'wb') : false;
		$copied = is_resource($in) && is_resource($out) && stream_copy_to_stream($in, $out) !== false;
		if (is_resource($in)) fclose($in);
		if (is_resource($out)) fclose($out);
		if (!$copied) {
			if ($tmp !== false) @unlink($tmp);
			return $this->fail($request, __('plugins.generic.referenceVerify.error.file'));
		}

		// Transfer: multipart POST from the temporary file.
		$ch = curl_init($apiBase . '/api/ojs/handoff');
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 240,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
			CURLOPT_POSTFIELDS => [
				'file' => new CURLFile($tmp, $file->mimetype ?: 'application/octet-stream', $name),
				'name' => $name,
				// 1.1.0: lets ReferenceVerify file the editor's optional report summary under this submission/file.
				'ref' => $submission->getId() . ':' . $sf->getId(),
			],
		]);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		@unlink($tmp);

		$data = is_string($body) ? json_decode($body, true) : null;
		if ($status !== 200 || !is_array($data) || empty($data['token'])) {
			$code = is_array($data) && isset($data['code']) ? $data['code'] : '';
			$key = [
				'key' => 'plugins.generic.referenceVerify.error.key',
				'revoked' => 'plugins.generic.referenceVerify.error.revoked',
				'too_large' => 'plugins.generic.referenceVerify.error.tooLarge',
				'type' => 'plugins.generic.referenceVerify.error.type',
				'rate' => 'plugins.generic.referenceVerify.error.rate',
			][$code] ?? 'plugins.generic.referenceVerify.error.transfer';
			error_log('ReferenceVerify transfer failed: HTTP ' . $status . ($curlError ? ' ' . $curlError : '') . ($code ? ' ' . $code : ''));
			return $this->fail($request, __($key));
		}

		$path = $tool === 'bib' ? '/' : ($lang === 'en' ? '/citation-check' : '/atif-dogrula');
		$target = $siteBase . $prefix . ($path === '/' && $prefix !== '' ? '' : $path);
		if ($target === $siteBase) $target .= '/';
		$request->redirectUrl($target . '?ojs=' . rawurlencode($data['token']) . ($tool === 'full' ? '&rv=full' : ''));
	}

	/**
	 * "Get results": adds every summary sent for this submission as an OJS discussion, then confirms it to
	 * ReferenceVerify, which deletes it. Participants: the current user and the editors assigned to the submission;
	 * a report the editor explicitly shared with the author (1.2.0) also includes the submission's authors.
	 */
	function pull($args, $request) {
		$plugin = self::$plugin;
		$context = $request->getContext();
		if (!$request->isPost() || !$request->checkCSRF()) {
			return $this->fail($request, __('plugins.generic.referenceVerify.error.request'));
		}
		$submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
		if (trim((string) $plugin->getSetting($context->getId(), 'apiKey')) === '') {
			return $this->fail($request, __('plugins.generic.referenceVerify.error.notConfigured'));
		}
		list($written, $errorKey) = $plugin->writeResults($request, $submission);
		if ($errorKey !== null) return $this->fail($request, __($errorKey));

		$message = $written
			? __('plugins.generic.referenceVerify.pull.done', ['count' => $written])
			: __('plugins.generic.referenceVerify.pull.none');
		$templateMgr = TemplateManager::getManager($request);
		$this->setupTemplate($request);
		$templateMgr->assign([
			'rvMessage' => $message,
			'rvBackUrl' => $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'workflow', 'access', $submission->getId()),
		]);
		return $templateMgr->display(self::$plugin->getTemplateResource('message.tpl'));
	}

	/**
	 * "Reviewer suggestions": sends the submission's title, abstract and author names to ReferenceVerify's reviewer
	 * search (the account linked to the plugin key must have editor tools) and lists the candidates with ORCID,
	 * institution and conflict-of-interest flags. Nothing is assigned automatically.
	 */
	function reviewers($args, $request) {
		$plugin = self::$plugin;
		$context = $request->getContext();
		if (!$request->isPost() || !$request->checkCSRF()) {
			return $this->fail($request, __('plugins.generic.referenceVerify.error.request'));
		}
		$submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
		$apiKey = trim((string) $plugin->getSetting($context->getId(), 'apiKey'));
		if ($apiKey === '') return $this->fail($request, __('plugins.generic.referenceVerify.error.notConfigured'));
		list($apiBase) = $plugin->getEndpoints($context->getId());

		$publication = $submission->getCurrentPublication();
		$title = $publication ? (string) $publication->getLocalizedTitle() : '';
		$abstract = $publication ? (string) $publication->getLocalizedData('abstract') : '';
		$authors = [];
		foreach (($publication ? (array) $publication->getData('authors') : []) as $author) {
			$authors[] = (string) $author->getFullName(false);
		}
		if (trim(strip_tags($title . ' ' . $abstract)) === '') {
			return $this->fail($request, __('plugins.generic.referenceVerify.reviewers.error.short'));
		}
		if (function_exists('set_time_limit')) @set_time_limit(120);
		list($status, $data) = $this->callApi($apiBase . '/api/ojs/reviewers', $apiKey,
			['title' => $title, 'abstract' => $abstract, 'authors' => $authors], 90);
		if ($status !== 200 || !is_array($data) || !isset($data['candidates']) || !is_array($data['candidates'])) {
			return $this->fail($request, __($this->errorKey($data, [
				'not_editor' => 'plugins.generic.referenceVerify.reviewers.error.notEditor',
				'rate' => 'plugins.generic.referenceVerify.reviewers.error.rate',
				'short' => 'plugins.generic.referenceVerify.reviewers.error.short',
			], 'plugins.generic.referenceVerify.reviewers.error.fetch')));
		}

		$candidates = [];
		foreach ($data['candidates'] as $c) {
			if (!is_array($c) || empty($c['name'])) continue;
			$sample = isset($c['sample']) && is_array($c['sample']) ? $c['sample'] : null;
			$doi = $sample && !empty($sample['doi']) ? preg_replace('#^https?://(dx\.)?doi\.org/#i', '', (string) $sample['doi']) : '';
			$candidates[] = [
				'name' => (string) $c['name'],
				'orcid' => isset($c['orcid']) && preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', (string) $c['orcid']) ? (string) $c['orcid'] : '',
				'institution' => trim((string) ($c['institution'] ?? '') . (!empty($c['country']) ? ' (' . $c['country'] . ')' : '')),
				'topicWorks' => (int) ($c['topicWorks'] ?? 0),
				'lastYear' => !empty($c['lastYear']) ? (int) $c['lastYear'] : '',
				'sampleTitle' => $sample ? (string) ($sample['title'] ?? '') : '',
				'sampleYear' => $sample && !empty($sample['year']) ? (int) $sample['year'] : '',
				'sampleDoi' => preg_match('#^10\.\d{4,9}/\S+$#', $doi) ? $doi : '',
				'coauthor' => !empty($c['conflict']['coauthor']),
				'sameInstitution' => !empty($c['conflict']['sameInstitution']),
				'retracted' => !empty($c['conflict']['retracted']),
			];
		}

		$templateMgr = TemplateManager::getManager($request);
		$this->setupTemplate($request);
		$templateMgr->assign([
			'rvTitle' => strip_tags($title),
			'rvCandidates' => $candidates,
			'rvBroad' => !empty($data['broad']),
			'rvBackUrl' => $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'workflow', 'access', $submission->getId()),
		]);
		return $templateMgr->display(self::$plugin->getTemplateResource('reviewers.tpl'));
	}

	/** Thin wrappers — the implementations live in the plugin (shared with the workflow tab, 1.1.1). */
	function callApi($url, $apiKey, $payload, $timeout) {
		return self::$plugin->callApi($url, $apiKey, $payload, $timeout);
	}

	function errorKey($data, $own = [], $fallback = 'plugins.generic.referenceVerify.error.connection') {
		return self::$plugin->errorKey($data, $own, $fallback);
	}

	/** Simple error page in the journal's layout. */
	function fail($request, $message) {
		$templateMgr = TemplateManager::getManager($request);
		$this->setupTemplate($request);
		$templateMgr->assign('rvMessage', $message);
		return $templateMgr->display(self::$plugin->getTemplateResource('error.tpl'));
	}
}

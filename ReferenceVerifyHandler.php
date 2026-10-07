<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifyHandler.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class ReferenceVerifyHandler
 *
 * @brief index.php/<journal>/referenceverify/<op>. All operations are POST with the OJS CSRF token, for editors with
 *  access to the submission (section editors: their assignments only).
 *  - check: sends one manuscript file to ReferenceVerify (server to server, journal plugin key) and redirects the
 *    editor's browser (new tab) to the check page with a short-lived single-use token.
 *  - pull: writes the report summaries sent from ReferenceVerify as discussions (JSON for the 3.5 panel, a page for
 *    the 3.4 tab).
 *  - reviewers: reviewer suggestions for the submission's title/abstract/authors (new tab).
 *  - status (3.5): JSON for the workflow panel — files, waiting summaries, buttons.
 */

namespace APP\plugins\generic\referenceVerify;

use APP\core\Application;
use APP\handler\Handler;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\security\authorization\SubmissionAccessPolicy;
use PKP\security\Role;

class ReferenceVerifyHandler extends Handler
{
    protected ReferenceVerifyPlugin $plugin;

    public function __construct(ReferenceVerifyPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_SITE_ADMIN],
            ['check', 'pull', 'reviewers', 'status']
        );
    }

    /**
     * Editors only, and only for submissions they can access (section editors: their assignments).
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments, 'submissionId'));
        return parent::authorize($request, $args, $roleAssignments);
    }

    protected function submission()
    {
        return $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
    }

    /** POST with a valid CSRF token. */
    protected function validPost($request): bool
    {
        return $request->isPost() && $request->checkCSRF();
    }

    /**
     * 3.5 workflow panel: files with their buttons and waiting summaries (writes them first with "autoPull").
     */
    public function status($args, $request)
    {
        if (!$this->validPost($request)) {
            return new JSONMessage(false, __('plugins.generic.referenceVerify.error.request'));
        }
        $submission = $this->submission();
        $data = $this->plugin->workflowData($request, $submission);
        $data['submissionId'] = (int) $submission->getId();
        $data['stageId'] = (int) $submission->getData('stageId');
        return new JSONMessage(true, $data);
    }

    public function check($args, $request)
    {
        $plugin = $this->plugin;
        $context = $request->getContext();
        if (!$this->validPost($request)) {
            return $this->fail($request, __('plugins.generic.referenceVerify.error.request'));
        }
        $submission = $this->submission();
        $submissionFileId = (int) $request->getUserVar('submissionFileId');
        $sf = $submissionFileId ? \APP\facades\Repo::submissionFile()->get($submissionFileId) : null;
        if (!$sf || (int) $sf->getData('submissionId') !== (int) $submission->getId() || !$plugin->isManuscriptStage((int) $sf->getData('fileStage'))) {
            return $this->fail($request, __('plugins.generic.referenceVerify.error.file'));
        }
        if (!$plugin->isSupportedFile($sf)) {
            return $this->fail($request, __('plugins.generic.referenceVerify.error.type'));
        }

        $apiKey = trim((string) $plugin->getSetting($context->getId(), 'apiKey'));
        if ($apiKey === '') {
            return $this->fail($request, __('plugins.generic.referenceVerify.error.notConfigured'));
        }
        [$apiBase, $siteBase, $prefix, $lang] = $plugin->getEndpoints($context->getId());
        // The editor picks the report per file: bib = reference list, cite = in-text citations, full = consolidated.
        $tool = ReferenceVerifyPlugin::normalizeTool($request->getUserVar('tool'));

        // Large files over slow links can take longer than the host's default 30 s limit (on Windows hosts network
        // waiting counts towards it). Raise it for this request only; ignored where the host forbids it.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        // Copy the stored file to a temporary file as a STREAM: a 50 MB file is never held in PHP memory. The
        // temporary file is deleted right after the transfer.
        $fileService = $plugin->fileService();
        $file = $fileService->get($sf->getData('fileId'));
        if (!$file) {
            return $this->fail($request, __('plugins.generic.referenceVerify.error.file'));
        }
        $name = (string) $sf->getLocalizedData('name');
        $tmp = tempnam(sys_get_temp_dir(), 'rvojs');
        try {
            $in = $fileService->fs->readStream($file->path);
        } catch (\Throwable $e) {
            $in = false;
        }
        $out = $tmp !== false ? fopen($tmp, 'wb') : false;
        $copied = is_resource($in) && is_resource($out) && stream_copy_to_stream($in, $out) !== false;
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if (!$copied) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
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
                'file' => new \CURLFile($tmp, $file->mimetype ?: 'application/octet-stream', $name),
                'name' => $name,
                // Lets ReferenceVerify file the editor's optional report summary under this submission/file.
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
            ][$code] ?? ($status === 0 ? 'plugins.generic.referenceVerify.error.connection' : 'plugins.generic.referenceVerify.error.transfer');
            error_log('ReferenceVerify transfer failed: HTTP ' . $status . ($curlError ? ' ' . $curlError : '') . ($code ? ' ' . $code : ''));
            return $this->fail($request, __($key));
        }

        $request->redirectUrl($plugin->targetUrl($siteBase, $prefix, $lang, $tool, (string) $data['token']));
    }

    /**
     * "Get results": adds every summary sent for this submission as an OJS discussion, then confirms it to
     * ReferenceVerify, which deletes it (see ReferenceVerifyPlugin::writeResults()).
     */
    public function pull($args, $request)
    {
        $json = $request->getUserVar('format') === 'json';
        $context = $request->getContext();
        if (!$this->validPost($request)) {
            return $this->failAs($json, $request, __('plugins.generic.referenceVerify.error.request'));
        }
        $submission = $this->submission();
        if (trim((string) $this->plugin->getSetting($context->getId(), 'apiKey')) === '') {
            return $this->failAs($json, $request, __('plugins.generic.referenceVerify.error.notConfigured'));
        }
        [$written, $errorKey] = $this->plugin->writeResults($request, $submission);
        if ($errorKey !== null) {
            return $this->failAs($json, $request, __($errorKey));
        }

        $message = $written
            ? __('plugins.generic.referenceVerify.pull.done', ['count' => $written])
            : __('plugins.generic.referenceVerify.pull.none');
        if ($json) {
            return new JSONMessage(true, ['written' => $written, 'message' => $message]);
        }
        $templateMgr = TemplateManager::getManager($request);
        $this->setupTemplate($request);
        $templateMgr->assign([
            'rvMessage' => $message,
            'rvBackUrl' => $this->backUrl($request, $submission),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('message.tpl'));
    }

    /**
     * "Reviewer suggestions": sends the submission's title, abstract and author names to ReferenceVerify's reviewer
     * search (the account linked to the plugin key must have editor tools) and lists the candidates with ORCID,
     * institution and conflict-of-interest flags. Nothing is assigned automatically.
     */
    public function reviewers($args, $request)
    {
        $plugin = $this->plugin;
        $context = $request->getContext();
        if (!$this->validPost($request)) {
            return $this->fail($request, __('plugins.generic.referenceVerify.error.request'));
        }
        $submission = $this->submission();
        $apiKey = trim((string) $plugin->getSetting($context->getId(), 'apiKey'));
        if ($apiKey === '') {
            return $this->fail($request, __('plugins.generic.referenceVerify.error.notConfigured'));
        }
        [$apiBase] = $plugin->getEndpoints($context->getId());

        $publication = $submission->getCurrentPublication();
        $title = $publication ? (string) $publication->getLocalizedTitle() : '';
        $abstract = $publication ? (string) $publication->getLocalizedData('abstract') : '';
        $authors = [];
        foreach (($publication ? $publication->getData('authors') : null) ?? [] as $author) {
            $authors[] = (string) $author->getFullName(false);
        }
        if (trim(strip_tags($title . ' ' . $abstract)) === '') {
            return $this->fail($request, __('plugins.generic.referenceVerify.reviewers.error.short'));
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        [$status, $data] = $plugin->callApi(
            $apiBase . '/api/ojs/reviewers',
            $apiKey,
            ['title' => $title, 'abstract' => $abstract, 'authors' => $authors],
            90
        );
        if ($status !== 200 || !is_array($data) || !isset($data['candidates']) || !is_array($data['candidates'])) {
            return $this->fail($request, __($plugin->errorKey($data, [
                'not_editor' => 'plugins.generic.referenceVerify.reviewers.error.notEditor',
                'rate' => 'plugins.generic.referenceVerify.reviewers.error.rate',
                'short' => 'plugins.generic.referenceVerify.reviewers.error.short',
            ], 'plugins.generic.referenceVerify.reviewers.error.fetch')));
        }

        $candidates = [];
        foreach ($data['candidates'] as $c) {
            if (!is_array($c) || empty($c['name'])) {
                continue;
            }
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
            'rvBackUrl' => $this->backUrl($request, $submission),
        ]);
        return $templateMgr->display($this->plugin->getTemplateResource('reviewers.tpl'));
    }

    /** The submission's workflow (3.5 redirects this to the dashboard with the workflow open). */
    protected function backUrl($request, $submission): string
    {
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'workflow', 'access', [$submission->getId()]);
    }

    protected function failAs(bool $json, $request, string $message)
    {
        return $json ? new JSONMessage(false, $message) : $this->fail($request, $message);
    }

    /** Simple error page in the journal's layout. */
    protected function fail($request, string $message)
    {
        $templateMgr = TemplateManager::getManager($request);
        $this->setupTemplate($request);
        $templateMgr->assign('rvMessage', $message);
        return $templateMgr->display($this->plugin->getTemplateResource('error.tpl'));
    }
}

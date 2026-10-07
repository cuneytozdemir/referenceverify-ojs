<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifyPlugin.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class ReferenceVerifyPlugin
 *
 * @brief ReferenceVerify (Kaynakça Doğrula) for OJS 3.4 and 3.5.
 *
 *  For each Word/PDF manuscript file of a submission an editor can click "Check with ReferenceVerify": the file is
 *  handed over (server to server, HTTPS, journal plugin key) to ReferenceVerify as a short-lived, encrypted,
 *  single-use transfer, and the editor's browser opens the ReferenceVerify check with that file. Report summaries the
 *  editor sends back are written to the submission as discussions; reviewer suggestions can be listed.
 *
 *  Where the UI lives:
 *  - OJS 3.5: the workflow is a Vue page (side modal of the editorial dashboard) and the Template::Workflow hook no
 *    longer exists. The plugin adds a "ReferenceVerify" item to the workflow side menu through the supported
 *    pkp.registry.storeExtend('workflow') extension point (js/referenceVerify.js); its panel loads its data from
 *    index.php/<journal>/referenceverify/status.
 *  - OJS 3.4: a "ReferenceVerify" tab in the workflow (Template::Workflow), as in the OJS 3.3 plugin.
 */

namespace APP\plugins\generic\referenceVerify;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\submissionFile\SubmissionFile;

class ReferenceVerifyPlugin extends GenericPlugin
{
    /** Workflow roles allowed to send a file (journal manager / editor, section editor); site admins too. */
    public const EDITOR_ROLES = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];

    /**
     * Reports an editor can open per file (1.2.0): bib = reference list, cite = in-text citations,
     * full = consolidated (every reference + in-text citations <-> reference list in both directions).
     */
    public const TOOLS = ['bib', 'cite', 'full'];

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || Application::isUnderMaintenance()) {
            return true;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', [$this, 'setupHandler']);
            if (self::isOjs35()) {
                Hook::add('TemplateManager::display', [$this, 'addWorkflowScript']);
            } else {
                Hook::add('Template::Workflow', [$this, 'addWorkflowTab']);
            }
        }
        return $success;
    }

    /** OJS 3.5+ (Vue workflow, Eloquent queries/stage assignments) or 3.4. */
    public static function isOjs35(): bool
    {
        return class_exists(\PKP\query\Repository::class);
    }

    public function getDisplayName()
    {
        return __('plugins.generic.referenceVerify.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.referenceVerify.description');
    }

    /**
     * Site the editor's browser is sent to and the transfer endpoint.
     *
     * @return array [apiBase, siteBase, langPrefix, lang]
     */
    public function getEndpoints($contextId): array
    {
        // Site language follows the OJS interface language of the editor at click time:
        // Turkish OJS -> kaynakcadogrula.com, any other language -> referenceverify.com. No setting needed.
        $lang = str_starts_with((string) Locale::getLocale(), 'tr') ? 'tr' : 'en';
        $custom = rtrim((string) $this->getSetting($contextId, 'baseUrl'), '/');
        $base = $custom !== '' ? $custom : ($lang === 'en' ? 'https://referenceverify.com' : 'https://kaynakcadogrula.com');
        // referenceverify.com serves the English site at its root; any other host (e.g. a test server) under /en.
        $prefix = ($lang === 'en' && !preg_match('#^https?://(www\.)?referenceverify\.com$#i', $base)) ? '/en' : '';
        return [$base, $base, $prefix, $lang];
    }

    /** bib | cite | full; anything else falls back to bib (as in the OJS 3.3 plugin). */
    public static function normalizeTool($tool): string
    {
        $tool = (string) $tool;
        return in_array($tool, self::TOOLS, true) ? $tool : 'bib';
    }

    /** Page on ReferenceVerify that opens the transferred file for this report. */
    public function targetUrl(string $siteBase, string $prefix, string $lang, string $tool, string $token): string
    {
        $path = $tool === 'bib' ? '/' : ($lang === 'en' ? '/citation-check' : '/atif-dogrula');
        $url = $siteBase . $prefix . ($path === '/' && $prefix !== '' ? '' : $path);
        if ($url === $siteBase) {
            $url .= '/';
        }
        return $url . '?ojs=' . rawurlencode($token) . ($tool === 'full' ? '&rv=full' : '');
    }

    /** The per-file buttons: [['tool', 'label', 'help']]. */
    public function toolButtons(): array
    {
        $buttons = [];
        foreach (['bib' => 'Bib', 'cite' => 'Cite', 'full' => 'Full'] as $tool => $key) {
            $buttons[] = [
                'tool' => $tool,
                'label' => __('plugins.generic.referenceVerify.tab.check' . $key),
                'help' => __('plugins.generic.referenceVerify.tab.check' . $key . 'Help'),
            ];
        }
        return $buttons;
    }

    /** Can the current user use the plugin in this journal? */
    public function userMaySend($request, $contextId): bool
    {
        $user = $request->getUser();
        if (!$user) {
            return false;
        }
        return $user->hasRole(self::EDITOR_ROLES, $contextId) || $user->hasRole([Role::ROLE_ID_SITE_ADMIN], Application::CONTEXT_SITE);
    }

    /**
     * OJS 3.5 — TemplateManager::display: on the editorial dashboard (which hosts the workflow) load the script that
     * adds the ReferenceVerify item to the workflow side menu. Editors only; the panel asks the server again (status),
     * which applies the submission access rules (section editors: their assignments only).
     */
    public function addWorkflowScript($hookName, $args)
    {
        $templateMgr = $args[0]; /** @var TemplateManager $templateMgr */
        $template = $args[1];
        if ($template !== 'dashboard/editors.tpl') {
            return false;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || !$this->userMaySend($request, $context->getId())) {
            return false;
        }
        $dispatcher = $request->getDispatcher();
        $url = fn (string $op) => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'referenceverify', $op);
        $config = [
            'csrfToken' => $request->getSession()->token(),
            'urls' => [
                'status' => $url('status'),
                'check' => $url('check'),
                'pull' => $url('pull'),
                'reviewers' => $url('reviewers'),
            ],
            'i18n' => $this->scriptStrings(),
        ];
        $templateMgr->addJavaScript(
            'referenceVerifyConfig',
            'window.pkpReferenceVerify = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';',
            ['inline' => true, 'contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );
        $version = $this->getCurrentVersion();
        $templateMgr->addJavaScript(
            'referenceVerify',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/referenceVerify.js?v=' . rawurlencode($version ? $version->getVersionString() : '1'),
            ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );
        return false;
    }

    /** Interface texts for the 3.5 workflow panel. */
    protected function scriptStrings(): array
    {
        $keys = [
            'tab', 'tab.intro', 'tab.notConfigured', 'tab.unsupported', 'tab.noFiles', 'tab.file', 'tab.stage',
            'tab.privacy', 'tab.results', 'tab.resultsHelp', 'tab.pull', 'tab.reviewers', 'tab.reviewersHelp',
            'tab.reviewersButton', 'tab.pendingFile', 'tab.loading', 'tab.loadError', 'tab.openDiscussions',
            'error.connection',
        ];
        $strings = [];
        foreach ($keys as $k) {
            $strings[$k] = __('plugins.generic.referenceVerify.' . $k);
        }
        // Messages with a count: {$count} is replaced in the browser.
        foreach (['tab.pendingBanner', 'tab.autoWritten', 'pull.done'] as $k) {
            $strings[$k] = __('plugins.generic.referenceVerify.' . $k, ['count' => '{$count}']);
        }
        return $strings;
    }

    /**
     * Everything the workflow UI shows for one submission: files with their buttons, waiting summaries (and, with the
     * "write automatically" setting, writes them first). Shared by the 3.5 status endpoint and the 3.4 tab.
     */
    public function workflowData($request, $submission): array
    {
        $context = $request->getContext();
        $contextId = $context->getId();
        $files = [];
        $submissionFiles = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();
        foreach ($submissionFiles as $sf) {
            $fileStage = (int) $sf->getData('fileStage');
            if (!$this->isManuscriptStage($fileStage)) {
                continue;
            }
            $files[] = [
                'id' => (int) $sf->getId(),
                'name' => (string) $sf->getLocalizedData('name'),
                'stage' => $this->stageLabel($fileStage),
                'supported' => $this->isSupportedFile($sf),
                'pending' => 0,
            ];
        }
        $apiKey = trim((string) $this->getSetting($contextId, 'apiKey'));

        // Summaries the editor sent from ReferenceVerify but not yet written to OJS. One short request (only counts,
        // never content); if ReferenceVerify is slow or unreachable no badge is shown. With the "write automatically"
        // setting they are written here as discussions.
        $pendingFiles = [];
        $autoWritten = 0;
        if ($apiKey !== '') {
            $pending = $this->pendingResults($contextId, $apiKey, (int) $submission->getId());
            if ($pending && $pending['count'] > 0 && $this->getSetting($contextId, 'autoPull')) {
                [$written, $errorKey] = $this->writeResults($request, $submission);
                if ($errorKey === null && $written > 0) {
                    $autoWritten = $written;
                    $pending = $this->pendingResults($contextId, $apiKey, (int) $submission->getId());
                }
            }
            if ($pending) {
                $pendingFiles = $pending['files'];
            }
        }
        $pendingByFile = array_count_values(array_map('intval', $pendingFiles));
        foreach ($files as $i => $f) {
            $files[$i]['pending'] = $pendingByFile[$f['id']] ?? 0;
        }
        return [
            'configured' => $apiKey !== '',
            'files' => $files,
            'tools' => $this->toolButtons(),
            'pendingCount' => count($pendingFiles),
            'autoWritten' => $autoWritten,
        ];
    }

    /**
     * OJS 3.4 — Template::Workflow: append a "ReferenceVerify" tab to the workflow tabs (editors only).
     */
    public function addWorkflowTab($hookName, $params)
    {
        $templateMgr = $params[1];
        $output = &$params[2];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $submission = $templateMgr->getTemplateVars('submission');
        if (!$context || !$submission || !$this->userMaySend($request, $context->getId())) {
            return false;
        }
        $data = $this->workflowData($request, $submission);
        $dispatcher = $request->getDispatcher();
        $templateMgr->assign([
            'rvPendingCount' => $data['pendingCount'],
            'rvAutoWritten' => $data['autoWritten'],
            'rvFiles' => $data['files'],
            'rvTools' => $data['tools'],
            'rvSubmissionId' => $submission->getId(),
            'rvConfigured' => $data['configured'],
            'rvActionUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'referenceverify', 'check'),
            'rvPullUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'referenceverify', 'pull'),
            'rvReviewersUrl' => $dispatcher->url($request, Application::ROUTE_PAGE, null, 'referenceverify', 'reviewers'),
        ]);
        $output .= $templateMgr->fetch($this->getTemplateResource('workflowTab.tpl'));
        return false;
    }

    /**
     * How many report summaries are waiting for this submission (sent from ReferenceVerify, not yet written to OJS).
     * Short timeouts. @return array|null ['count', 'files'[]]
     */
    public function pendingResults($contextId, $apiKey, $submissionId): ?array
    {
        [$apiBase] = $this->getEndpoints($contextId);
        [$status, $data] = $this->callApi($apiBase . '/api/ojs/result/pending', $apiKey, ['submissionId' => (int) $submissionId], 4, 3);
        if ($status !== 200 || !is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return null;
        }
        $files = [];
        foreach ($data['files'] as $id) {
            if ((int) $id > 0) {
                $files[] = (int) $id;
            }
        }
        return ['count' => count($files), 'files' => $files];
    }

    /**
     * Writes every summary sent for this submission as an OJS discussion, then confirms it to ReferenceVerify, which
     * deletes it. Participants: the current user and the editors assigned to the submission; the submission's authors
     * only when ReferenceVerify marks the summary for them (audience "author", 1.2.0). Each participant gets a
     * "new discussion" notification (no e-mail).
     *
     * @return array [int writtenCount, string|null errorLocaleKey]
     */
    public function writeResults($request, $submission): array
    {
        $context = $request->getContext();
        $apiKey = trim((string) $this->getSetting($context->getId(), 'apiKey'));
        if ($apiKey === '') {
            return [0, 'plugins.generic.referenceVerify.error.notConfigured'];
        }
        [$apiBase] = $this->getEndpoints($context->getId());

        [$status, $data] = $this->callApi($apiBase . '/api/ojs/result/pull', $apiKey, ['submissionId' => (int) $submission->getId()], 30);
        if ($status !== 200 || !is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return [0, $this->errorKey($data)];
        }

        $user = $request->getUser();
        $stageId = (int) $submission->getData('stageId') ?: WORKFLOW_STAGE_ID_SUBMISSION;
        $written = [];
        foreach ($data['results'] as $r) {
            if (!is_array($r) || empty($r['id']) || !isset($r['subject'], $r['text'])) {
                continue;
            }
            $withAuthors = ($r['audience'] ?? '') === 'author';
            $participants = $this->participants((int) $submission->getId(), (int) $user->getId(), $withAuthors);
            $title = mb_substr((string) $r['subject'], 0, 255);
            $html = $this->textToHtml((string) $r['text']);
            $queries = self::isOjs35() ? Repo::query() : DAORegistry::getDAO('QueryDAO');
            $queries->addQuery((int) $submission->getId(), $stageId, $title, $html, $user, $participants, (int) $context->getId(), false);
            $written[] = (int) $r['id'];
        }

        if ($written) {
            Repo::submission()->edit($submission, []); // stamps the last activity
            // Written to OJS: ReferenceVerify can delete them. If this confirmation fails the rows expire on their own
            // within 7 days; a second "Get results" in the meantime would add them again, so it is retried once.
            [$ackStatus] = $this->callApi($apiBase . '/api/ojs/result/ack', $apiKey, ['ids' => $written], 15);
            if ($ackStatus !== 200) {
                $this->callApi($apiBase . '/api/ojs/result/ack', $apiKey, ['ids' => $written], 15);
            }
        }
        return [count($written), null];
    }

    /**
     * Discussion participants: the current user + the editors assigned to the submission. Authors are left out
     * (also an editor who is an author of this submission), unless $withAuthors.
     *
     * @return int[]
     */
    public function participants(int $submissionId, int $currentUserId, bool $withAuthors = false): array
    {
        $ids = [$currentUserId => true];
        foreach ($this->stageUserIds($submissionId, self::EDITOR_ROLES) as $id) {
            $ids[$id] = true;
        }
        foreach ($this->stageUserIds($submissionId, [Role::ROLE_ID_AUTHOR]) as $id) {
            if ($withAuthors) {
                $ids[$id] = true;
            } elseif ($id !== $currentUserId) {
                unset($ids[$id]);
            }
        }
        return array_keys($ids);
    }

    /** Users assigned to the submission with one of the roles. @return int[] */
    protected function stageUserIds(int $submissionId, array $roleIds): array
    {
        if (self::isOjs35()) {
            return StageAssignment::withSubmissionIds([$submissionId])
                ->withRoleIds($roleIds)
                ->get()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }
        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var \PKP\stageAssignment\StageAssignmentDAO $stageAssignmentDao */
        $ids = [];
        $assignments = $stageAssignmentDao->getBySubmissionAndRoleIds($submissionId, $roleIds);
        while ($a = $assignments->next()) {
            $ids[(int) $a->getUserId()] = true;
        }
        return array_keys($ids);
    }

    /** Plain text from ReferenceVerify → safe discussion HTML: escaped, paragraphs, line breaks, report link. */
    public function textToHtml(string $text): string
    {
        $html = [];
        foreach (preg_split("/\n{2,}/", trim(str_replace("\r", '', $text))) as $block) {
            $p = nl2br(htmlspecialchars($block, ENT_QUOTES, 'UTF-8'), false);
            $p = preg_replace('#(https://[A-Za-z0-9./_-]+)#', '<a href="$1" target="_blank" rel="noopener">$1</a>', $p);
            $html[] = '<p>' . $p . '</p>';
        }
        return implode("\n", $html);
    }

    /** JSON POST to ReferenceVerify with the journal key. @return array [httpStatus, decodedBody|null] */
    public function callApi(string $url, string $apiKey, array $payload, int $timeout, int $connectTimeout = 15): array
    {
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
    public function errorKey($data, array $own = [], string $fallback = 'plugins.generic.referenceVerify.error.connection'): string
    {
        $code = is_array($data) && isset($data['code']) ? $data['code'] : '';
        $map = array_merge([
            'key' => 'plugins.generic.referenceVerify.error.key',
            'revoked' => 'plugins.generic.referenceVerify.error.revoked',
            'rate' => 'plugins.generic.referenceVerify.error.rate',
        ], $own);
        return $map[$code] ?? $fallback;
    }

    /**
     * Manuscript file stages only: submission, review round, revisions, copyediting, production/proofs.
     * Reviewer attachments, discussion (query) files, notes, JATS and dependent files are not listed.
     */
    public function isManuscriptStage(int $fileStage): bool
    {
        return in_array($fileStage, [
            SubmissionFile::SUBMISSION_FILE_SUBMISSION,
            SubmissionFile::SUBMISSION_FILE_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
            SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
            SubmissionFile::SUBMISSION_FILE_FINAL,
            SubmissionFile::SUBMISSION_FILE_COPYEDIT,
            SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY,
            SubmissionFile::SUBMISSION_FILE_PROOF,
        ], true);
    }

    /**
     * Word / PDF / RTF, recognised by file name OR by the stored MIME type (OJS keeps names without an extension
     * when the author typed one). ReferenceVerify checks the content again on arrival.
     */
    public function isSupportedFile($sf): bool
    {
        $name = (string) $sf->getLocalizedData('name');
        if (preg_match('/\.(docx?|pdf|rtf)$/i', $name)) {
            return true;
        }
        $mime = strtolower((string) $sf->getData('mimetype'));
        return in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'application/pdf',
            'application/rtf',
            'text/rtf',
        ], true);
    }

    public function stageLabel(int $fileStage): string
    {
        switch ($fileStage) {
            case SubmissionFile::SUBMISSION_FILE_SUBMISSION:
                return __('plugins.generic.referenceVerify.stage.submission');
            case SubmissionFile::SUBMISSION_FILE_REVIEW_FILE:
            case SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION:
            case SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE:
            case SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION:
                return __('plugins.generic.referenceVerify.stage.review');
            case SubmissionFile::SUBMISSION_FILE_FINAL:
            case SubmissionFile::SUBMISSION_FILE_COPYEDIT:
                return __('plugins.generic.referenceVerify.stage.copyedit');
            case SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY:
            case SubmissionFile::SUBMISSION_FILE_PROOF:
                return __('plugins.generic.referenceVerify.stage.production');
            default:
                return __('plugins.generic.referenceVerify.stage.other');
        }
    }

    /** The file storage service (3.5: container, 3.4: Services). */
    public function fileService()
    {
        return self::isOjs35() ? app()->get('file') : \APP\core\Services::get('file');
    }

    /**
     * LoadHandler — route index.php/<journal>/referenceverify/<op> to our handler.
     */
    public function setupHandler($hookName, $params)
    {
        if ($params[0] !== 'referenceverify') {
            return false;
        }
        $params[3] = new ReferenceVerifyHandler($this);
        return true;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
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
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            $context = $request->getContext();
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

<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifySettingsForm.php
 *
 * Copyright (c) 2026 Cüneyt Özdemir
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ReferenceVerifySettingsForm
 *
 * @brief Journal manager settings: the plugin key only. 1.2.1: the server address is no longer a journal setting
 *  (see ReferenceVerifyPlugin::testServer()) and summaries are never written automatically.
 */

namespace APP\plugins\generic\referenceVerify;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorRegExp;

class ReferenceVerifySettingsForm extends Form
{
    protected int $contextId;

    protected ReferenceVerifyPlugin $plugin;

    public function __construct(ReferenceVerifyPlugin $plugin, int $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorRegExp($this, 'apiKey', 'required', 'plugins.generic.referenceVerify.settings.apiKeyInvalid', '/^rvojs_[A-Za-z0-9_-]{43}$/'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        $this->_data = [
            'apiKey' => $this->plugin->getSetting($this->contextId, 'apiKey'),
        ];
    }

    public function readInputData()
    {
        $this->readUserVars(['apiKey']);
        $this->setData('apiKey', trim((string) $this->getData('apiKey')));
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'rvSettingsUrl' => $request->getRouter()->url($request, null, null, 'manage', null, [
                'verb' => 'settings', 'plugin' => $this->plugin->getName(), 'category' => 'generic', 'save' => true,
            ]),
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting($this->contextId, 'apiKey', (string) $this->getData('apiKey'), 'string');
        parent::execute(...$functionArgs);
    }
}

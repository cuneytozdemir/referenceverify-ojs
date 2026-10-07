<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifySettingsForm.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class ReferenceVerifySettingsForm
 *
 * @brief Journal manager settings: plugin key, automatic summaries, optional server address (testing).
 *  Same setting names as the OJS 3.3 plugin. Since 1.2.0 the report is chosen per file, so there is no tool setting.
 */

namespace APP\plugins\generic\referenceVerify;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorInSet;
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
        $this->addCheck(new FormValidatorCustom($this, 'baseUrl', 'optional', 'plugins.generic.referenceVerify.settings.baseUrlInvalid', function ($v) {
            return $v === '' || (bool) preg_match('~^https?://[^\s/?#]+(:\d+)?$~', $v);
        }));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        $this->_data = [
            'apiKey' => $this->plugin->getSetting($this->contextId, 'apiKey'),
            'baseUrl' => $this->plugin->getSetting($this->contextId, 'baseUrl') ?: '',
            'autoPull' => (bool) $this->plugin->getSetting($this->contextId, 'autoPull'),
        ];
    }

    public function readInputData()
    {
        $this->readUserVars(['apiKey', 'baseUrl', 'autoPull']);
        $this->setData('apiKey', trim((string) $this->getData('apiKey')));
        $this->setData('baseUrl', rtrim(trim((string) $this->getData('baseUrl')), '/'));
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
        foreach (['apiKey', 'baseUrl'] as $k) {
            $this->plugin->updateSetting($this->contextId, $k, (string) $this->getData($k), 'string');
        }
        // Write waiting summaries automatically when the ReferenceVerify panel opens (off by default).
        $this->plugin->updateSetting($this->contextId, 'autoPull', $this->getData('autoPull') ? 1 : 0, 'bool');
        parent::execute(...$functionArgs);
    }
}

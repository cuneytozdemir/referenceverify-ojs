<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifySettingsForm.inc.php
 *
 * Copyright (c) 2026 Cüneyt Özdemir
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ReferenceVerifySettingsForm
 * @ingroup plugins_generic_referenceVerify
 *
 * @brief Journal manager settings: the plugin key only. (1.2.1: the server address is no longer a journal setting —
 *  see ReferenceVerifyPlugin::testServer() — and summaries are never written automatically.)
 */

import('lib.pkp.classes.form.Form');

class ReferenceVerifySettingsForm extends Form {

	/** @var int */
	var $_contextId;

	/** @var ReferenceVerifyPlugin */
	var $_plugin;

	function __construct($plugin, $contextId) {
		$this->_contextId = $contextId;
		$this->_plugin = $plugin;
		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
		$this->addCheck(new FormValidatorRegExp($this, 'apiKey', 'required', 'plugins.generic.referenceVerify.settings.apiKeyInvalid', '/^rvojs_[A-Za-z0-9_-]{43}$/'));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	function initData() {
		$this->_data = [
			'apiKey' => $this->_plugin->getSetting($this->_contextId, 'apiKey'),
		];
	}

	function readInputData() {
		$this->readUserVars(['apiKey']);
		$this->setData('apiKey', trim((string) $this->getData('apiKey')));
	}

	function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(['pluginName' => $this->_plugin->getName()]);
		return parent::fetch($request, $template, $display);
	}

	function execute(...$functionArgs) {
		$this->_plugin->updateSetting($this->_contextId, 'apiKey', (string) $this->getData('apiKey'), 'string');
		parent::execute(...$functionArgs);
	}
}

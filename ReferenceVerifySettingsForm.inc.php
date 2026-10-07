<?php

/**
 * @file plugins/generic/referenceVerify/ReferenceVerifySettingsForm.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class ReferenceVerifySettingsForm
 * @ingroup plugins_generic_referenceVerify
 *
 * @brief Journal manager settings: plugin key, site language, tool, optional base URL (testing).
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
		$this->addCheck(new FormValidatorCustom($this, 'baseUrl', 'optional', 'plugins.generic.referenceVerify.settings.baseUrlInvalid', function ($v) {
			return $v === '' || (bool) preg_match('~^https?://[^\s/?#]+(:\d+)?$~', $v);
		}));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	function initData() {
		$this->_data = [
			'apiKey' => $this->_plugin->getSetting($this->_contextId, 'apiKey'),
			'baseUrl' => $this->_plugin->getSetting($this->_contextId, 'baseUrl') ?: '',
			'autoPull' => $this->_plugin->getSetting($this->_contextId, 'autoPull') ? true : false,
		];
	}

	function readInputData() {
		$this->readUserVars(['apiKey', 'baseUrl', 'autoPull']);
		$this->setData('apiKey', trim((string) $this->getData('apiKey')));
		$this->setData('baseUrl', rtrim(trim((string) $this->getData('baseUrl')), '/'));
	}

	function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(['pluginName' => $this->_plugin->getName()]);
		return parent::fetch($request, $template, $display);
	}

	function execute(...$functionArgs) {
		foreach (['apiKey', 'baseUrl'] as $k) {
			$this->_plugin->updateSetting($this->_contextId, $k, (string) $this->getData($k), 'string');
		}
		// 1.1.1: write waiting summaries automatically when the workflow tab opens (off by default).
		$this->_plugin->updateSetting($this->_contextId, 'autoPull', $this->getData('autoPull') ? 1 : 0, 'bool');
		parent::execute(...$functionArgs);
	}
}

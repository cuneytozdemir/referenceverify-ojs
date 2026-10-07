<?php

/**
 * @defgroup plugins_generic_referenceVerify ReferenceVerify plugin
 */

/**
 * @file plugins/generic/referenceVerify/index.php
 *
 * Copyright (c) 2026 Cüneyt Özdemir
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_generic_referenceVerify
 * @brief Wrapper for the ReferenceVerify plugin.
 */
require_once('ReferenceVerifyPlugin.inc.php');

return new ReferenceVerifyPlugin();

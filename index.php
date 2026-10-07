<?php

/**
 * @defgroup plugins_generic_referenceVerify ReferenceVerify plugin
 */

/**
 * @file plugins/generic/referenceVerify/index.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @ingroup plugins_generic_referenceVerify
 * @brief Wrapper for the ReferenceVerify plugin.
 */
require_once('ReferenceVerifyPlugin.inc.php');

return new ReferenceVerifyPlugin();

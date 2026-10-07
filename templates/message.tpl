{**
 * plugins/generic/referenceVerify/templates/message.tpl
 *
 * Copyright (c) 2026 Cüneyt Özdemir
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Outcome of "Get results" with a link back to the submission.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.referenceVerify.displayName"}
<div class="page page_referenceVerify">
	<h1>{translate key="plugins.generic.referenceVerify.displayName"}</h1>
	<p class="rv-message">{$rvMessage|escape}</p>
	<p><a href="{$rvBackUrl|escape}">{translate key="plugins.generic.referenceVerify.back"}</a></p>
</div>
{include file="frontend/components/footer.tpl"}

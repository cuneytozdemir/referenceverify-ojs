{**
 * plugins/generic/referenceVerify/templates/error.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Transfer error page.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.referenceVerify.displayName"}
<div class="page page_referenceVerify">
	<h1>{translate key="plugins.generic.referenceVerify.displayName"}</h1>
	<p>{$rvMessage|escape}</p>
	<p><a href="javascript:window.close()">{translate key="plugins.generic.referenceVerify.error.close"}</a></p>
</div>
{include file="frontend/components/footer.tpl"}

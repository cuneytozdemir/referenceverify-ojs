{**
 * plugins/generic/referenceVerify/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Cüneyt Özdemir
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * ReferenceVerify plugin settings.
 *}
<script>
	$(function() {ldelim}
		$('#referenceVerifySettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="referenceVerifySettingsForm" method="post" action="{$rvSettingsUrl|escape}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="referenceVerifySettingsFormNotification"}

	<p>{translate key="plugins.generic.referenceVerify.settings.description"}</p>
	{include file="common/formErrors.tpl"}

	{fbvFormArea id="referenceVerifySettingsArea"}
		{fbvFormSection title="plugins.generic.referenceVerify.settings.apiKey" required=true}
			{fbvElement type="text" password=true id="apiKey" value=$apiKey size=$fbvStyles.size.LARGE}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>

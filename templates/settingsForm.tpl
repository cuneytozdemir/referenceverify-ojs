{**
 * plugins/generic/referenceVerify/templates/settingsForm.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * ReferenceVerify plugin settings.
 *}
<script>
	$(function() {ldelim}
		$('#referenceVerifySettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="referenceVerifySettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="referenceVerifySettingsFormNotification"}

	<p>{translate key="plugins.generic.referenceVerify.settings.description"}</p>
	{include file="common/formErrors.tpl"}

	{fbvFormArea id="referenceVerifySettingsArea"}
		{fbvFormSection title="plugins.generic.referenceVerify.settings.apiKey" required=true}
			{fbvElement type="text" id="apiKey" value=$apiKey size=$fbvStyles.size.LARGE}
		{/fbvFormSection}
		{fbvFormSection list=true title="plugins.generic.referenceVerify.settings.autoPullTitle"}
			{fbvElement type="checkbox" id="autoPull" checked=$autoPull label="plugins.generic.referenceVerify.settings.autoPull"}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.referenceVerify.settings.baseUrl" description="plugins.generic.referenceVerify.settings.baseUrlDescription"}
			{fbvElement type="text" id="baseUrl" value=$baseUrl size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>

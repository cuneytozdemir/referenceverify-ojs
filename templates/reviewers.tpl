{**
 * plugins/generic/referenceVerify/templates/reviewers.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Reviewer suggestions for one submission: candidates with ORCID, institution, topic works and
 * conflict-of-interest flags. Suggestions only — nobody is assigned automatically.
 *}
{include file="frontend/components/header.tpl" pageTitle="plugins.generic.referenceVerify.reviewers.title"}
<div class="page page_referenceVerify" style="max-width:64rem">
	<h1>{translate key="plugins.generic.referenceVerify.reviewers.title"}</h1>
	<p style="margin:0 0 .25rem;font-weight:700">{$rvTitle|escape}</p>
	<p style="margin:0 0 1rem;font-size:.875rem;color:#555">{translate key="plugins.generic.referenceVerify.reviewers.intro"}</p>
	{if $rvBroad}
		<p class="rv-broad" style="margin:0 0 1rem;font-weight:700">{translate key="plugins.generic.referenceVerify.reviewers.broad"}</p>
	{/if}
	{if !$rvCandidates}
		<p class="rv-empty">{translate key="plugins.generic.referenceVerify.reviewers.none"}</p>
	{else}
		<table class="rv-reviewers" style="width:100%;border-collapse:collapse;font-size:.9375rem">
			<thead>
				<tr>
					<th style="text-align:left;padding:.5rem">{translate key="plugins.generic.referenceVerify.reviewers.name"}</th>
					<th style="text-align:left;padding:.5rem">{translate key="plugins.generic.referenceVerify.reviewers.institution"}</th>
					<th style="text-align:right;padding:.5rem">{translate key="plugins.generic.referenceVerify.reviewers.topicWorks"}</th>
					<th style="text-align:right;padding:.5rem">{translate key="plugins.generic.referenceVerify.reviewers.lastYear"}</th>
					<th style="text-align:left;padding:.5rem">{translate key="plugins.generic.referenceVerify.reviewers.sample"}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$rvCandidates item=rvC}
					<tr class="rv-candidate" style="border-top:1px solid #ddd;vertical-align:top">
						<td style="padding:.5rem">
							<strong>{$rvC.name|escape}</strong>
							{if $rvC.orcid}<br><a href="https://orcid.org/{$rvC.orcid|escape}" target="_blank" rel="noopener" style="font-size:.8125rem">ORCID {$rvC.orcid|escape}</a>{/if}
							{if $rvC.coauthor || $rvC.sameInstitution || $rvC.retracted}
								<br>
								{if $rvC.coauthor}<span class="rv-flag" style="display:inline-block;margin:.25rem .25rem 0 0;padding:0 .375rem;border:1px solid #b00;color:#b00;font-size:.75rem;font-weight:700">{translate key="plugins.generic.referenceVerify.reviewers.flag.coauthor"}</span>{/if}
								{if $rvC.sameInstitution}<span class="rv-flag" style="display:inline-block;margin:.25rem .25rem 0 0;padding:0 .375rem;border:1px solid #b00;color:#b00;font-size:.75rem;font-weight:700">{translate key="plugins.generic.referenceVerify.reviewers.flag.sameInstitution"}</span>{/if}
								{if $rvC.retracted}<span class="rv-flag" style="display:inline-block;margin:.25rem .25rem 0 0;padding:0 .375rem;border:1px solid #b00;color:#b00;font-size:.75rem;font-weight:700">{translate key="plugins.generic.referenceVerify.reviewers.flag.retracted"}</span>{/if}
							{/if}
						</td>
						<td style="padding:.5rem">{$rvC.institution|escape}</td>
						<td style="padding:.5rem;text-align:right">{$rvC.topicWorks|escape}</td>
						<td style="padding:.5rem;text-align:right">{$rvC.lastYear|escape}</td>
						<td style="padding:.5rem;font-size:.8125rem">
							{if $rvC.sampleTitle}
								{if $rvC.sampleDoi}<a href="https://doi.org/{$rvC.sampleDoi|escape}" target="_blank" rel="noopener">{$rvC.sampleTitle|escape}</a>{else}{$rvC.sampleTitle|escape}{/if}
								{if $rvC.sampleYear} ({$rvC.sampleYear|escape}){/if}
							{/if}
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
	<p style="margin:1rem 0 0;font-size:.875rem;color:#555">{translate key="plugins.generic.referenceVerify.reviewers.note"}</p>
	<p><a href="{$rvBackUrl|escape}">{translate key="plugins.generic.referenceVerify.back"}</a></p>
</div>
{include file="frontend/components/footer.tpl"}

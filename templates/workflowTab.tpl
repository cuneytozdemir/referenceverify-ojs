{**
 * plugins/generic/referenceVerify/templates/workflowTab.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * OJS 3.4 workflow tab: three report buttons per Word/PDF manuscript file (editors only).
 * Rendered inside the Vue <tabs> of the workflow page — plain HTML only.
 *}
<tab id="referenceVerify" label="{translate key="plugins.generic.referenceVerify.tab"}{if $rvPendingCount} ({$rvPendingCount}){/if}">
	<div class="pkp_referenceVerify" style="padding:1rem 1.25rem;max-width:56rem">
		<p style="margin:0 0 1rem">{translate key="plugins.generic.referenceVerify.tab.intro"}</p>
		{if $rvAutoWritten}
			<p role="status" style="margin:0 0 1rem;padding:.6rem .8rem;border-left:4px solid #00b28d;background:#f0faf7">{translate key="plugins.generic.referenceVerify.tab.autoWritten" count=$rvAutoWritten}</p>
		{/if}
		{if $rvPendingCount}
			<form method="post" action="{$rvPullUrl|escape}" role="status" style="margin:0 0 1rem;padding:.6rem .8rem;border-left:4px solid #d08a00;background:#fff8ec;display:flex;flex-wrap:wrap;align-items:center;gap:.75rem">
				{csrf}
				<input type="hidden" name="submissionId" value="{$rvSubmissionId|escape}">
				<span style="flex:1 1 20rem;font-weight:700">{translate key="plugins.generic.referenceVerify.tab.pendingBanner" count=$rvPendingCount}</span>
				<button type="submit" class="pkp_button pkpButton">{translate key="plugins.generic.referenceVerify.tab.pull"}</button>
			</form>
		{/if}
		{if !$rvConfigured}
			<p class="pkp_helpers_text_warn" style="font-weight:700">{translate key="plugins.generic.referenceVerify.tab.notConfigured"}</p>
		{elseif !$rvFiles}
			<p>{translate key="plugins.generic.referenceVerify.tab.noFiles"}</p>
		{else}
			<table class="pkpTable" style="width:100%;border-collapse:collapse">
				<thead>
					<tr>
						<th style="text-align:left;padding:.5rem">{translate key="plugins.generic.referenceVerify.tab.file"}</th>
						<th style="text-align:left;padding:.5rem">{translate key="plugins.generic.referenceVerify.tab.stage"}</th>
						<th style="padding:.5rem"></th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$rvFiles item=rvFile}
						<tr style="border-top:1px solid #ddd">
							<td style="padding:.5rem">{$rvFile.name|escape}{if $rvFile.pending} <span style="display:inline-block;margin-left:.4rem;padding:0 .45rem;border-radius:1rem;background:#fff1d6;color:#7a4b00;font-size:.75rem;font-weight:700">{translate key="plugins.generic.referenceVerify.tab.pendingFile"}</span>{/if}</td>
							<td style="padding:.5rem">{$rvFile.stage|escape}</td>
							<td style="padding:.5rem;text-align:right">
								{if $rvFile.supported}
									<form method="post" action="{$rvActionUrl|escape}" target="_blank" style="margin:0">
										{csrf}
										<input type="hidden" name="submissionId" value="{$rvSubmissionId|escape}">
										<input type="hidden" name="submissionFileId" value="{$rvFile.id|escape}">
										<span style="display:inline-flex;flex-wrap:wrap;gap:.4rem;justify-content:flex-end">
											{foreach from=$rvTools item=rvTool}
												<button type="submit" name="tool" value="{$rvTool.tool|escape}" class="pkp_button pkpButton" title="{$rvTool.help|escape}">{$rvTool.label|escape}</button>
											{/foreach}
										</span>
									</form>
								{else}
									<span style="font-size:.875rem;color:#666">{translate key="plugins.generic.referenceVerify.tab.unsupported"}</span>
								{/if}
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
			<p style="margin:1rem 0 0;font-size:.875rem;color:#555">{translate key="plugins.generic.referenceVerify.tab.privacy"}</p>
		{/if}
		{if $rvConfigured}
			<div style="display:flex;flex-wrap:wrap;gap:1.5rem;margin-top:1.5rem;padding-top:1rem;border-top:1px solid #ddd">
				<form method="post" action="{$rvPullUrl|escape}" style="margin:0;flex:1 1 18rem">
					{csrf}
					<input type="hidden" name="submissionId" value="{$rvSubmissionId|escape}">
					<p style="margin:0 0 .5rem;font-weight:700">{translate key="plugins.generic.referenceVerify.tab.results"}</p>
					<p style="margin:0 0 .75rem;font-size:.875rem;color:#555">{translate key="plugins.generic.referenceVerify.tab.resultsHelp"}</p>
					<button type="submit" class="pkp_button pkpButton">{translate key="plugins.generic.referenceVerify.tab.pull"}</button>
				</form>
				<form method="post" action="{$rvReviewersUrl|escape}" target="_blank" style="margin:0;flex:1 1 18rem">
					{csrf}
					<input type="hidden" name="submissionId" value="{$rvSubmissionId|escape}">
					<p style="margin:0 0 .5rem;font-weight:700">{translate key="plugins.generic.referenceVerify.tab.reviewers"}</p>
					<p style="margin:0 0 .75rem;font-size:.875rem;color:#555">{translate key="plugins.generic.referenceVerify.tab.reviewersHelp"}</p>
					<button type="submit" class="pkp_button pkpButton">{translate key="plugins.generic.referenceVerify.tab.reviewersButton"}</button>
				</form>
			</div>
		{/if}
	</div>
</tab>

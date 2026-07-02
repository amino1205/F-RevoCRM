{*+**********************************************************************************
 * Issue #1621 項目自動セットタブ
 * reference 項目ごとに「コピー元(選択レコードの項目) → コピー先(このフォームの項目)」を編集。
 ************************************************************************************}
{strip}
<div class="autoSetContent referenceRuleContent" data-source-module="{$SELECTED_MODULE_NAME}" data-rule-type="auto_set" data-fields-meta="{$FIELDS_META|escape:'html'}">
	<div class="alert alert-info"><i class="fa fa-info-circle"></i> {vtranslate('LBL_AUTO_SET_DESC', $QUALIFIED_MODULE)}</div>

	{if php7_count($REFERENCE_FIELDS) eq 0}
		<div class="alert alert-warning">{vtranslate('LBL_REFERENCE_RULE_NO_FIELDS', $QUALIFIED_MODULE)}</div>
	{else}
		<table class="table table-bordered themeTableColor referenceRuleTable">
			<thead><tr>
				<th width="40%">{vtranslate('LBL_FIELD', $QUALIFIED_MODULE)}</th>
				<th width="25%">{vtranslate('LBL_REFERENCE_TARGET_MODULE', $QUALIFIED_MODULE)}</th>
				<th width="35%">{vtranslate('LBL_REFERENCE_RULE_STATUS', $QUALIFIED_MODULE)}</th>
			</tr></thead>
			<tbody>
			{foreach from=$REFERENCE_FIELDS key=FIELD_NAME item=FIELD_MODEL}
				{assign var=FIELD_RULES value=$REFERENCE_RULES.$FIELD_NAME|default:null}
				{assign var=AS_DATA value=$FIELD_RULES.auto_set|default:null}
				{assign var=ENABLED value=false}
				{assign var=RULES value=[]}
				{if $AS_DATA}{assign var=ENABLED value=$AS_DATA.enabled}{assign var=RULES value=$AS_DATA.rules}{/if}
				{assign var=REF_LIST value=$FIELD_MODEL->getReferenceList()}
				{assign var=POPUP_MODULE value=''}
				{if $REF_LIST}{foreach from=$REF_LIST item=RM}{assign var=POPUP_MODULE value=$RM}{/foreach}{/if}

				<tr class="referenceFieldRow" data-field-name="{$FIELD_NAME}" data-popup-module="{$POPUP_MODULE|escape:'html'}">
					<td>
						<a href="javascript:void(0);" class="toggleAccordion">
							<i class="fa fa-chevron-right accordionIcon"></i>
							<strong>{vtranslate($FIELD_MODEL->get('label'), $SELECTED_MODULE_NAME)}</strong>
						</a>
					</td>
					<td>{vtranslate($POPUP_MODULE, $POPUP_MODULE)}</td>
					<td class="referenceFieldStatus"
						data-label-set="{vtranslate('LBL_RR_CONFIGURED', $QUALIFIED_MODULE)|escape:'html'}"
						data-label-unset="{vtranslate('LBL_RR_UNSET', $QUALIFIED_MODULE)|escape:'html'}">
						{if $ENABLED and $RULES|@count gt 0}
							<span class="label label-success">{vtranslate('LBL_RR_CONFIGURED', $QUALIFIED_MODULE)} ({$RULES|@count})</span>
						{else}
							<span class="label label-default">{vtranslate('LBL_RR_UNSET', $QUALIFIED_MODULE)}</span>
						{/if}
					</td>
				</tr>
				<tr class="referenceFieldEditor hide" data-field-name="{$FIELD_NAME}">
					<td colspan="3">
						<div class="referenceRuleEditorInner">
							<div class="checkbox referenceRuleEnableRow marginBottom10">
								<label>
									<input type="checkbox" class="sectionEnabled" {if $ENABLED}checked{/if} value="1" />
									{vtranslate('LBL_RR_ENABLED', $QUALIFIED_MODULE)}
								</label>
							</div>

						<table class="table mappingTable">
							<thead><tr>
								<th>{vtranslate('LBL_RR_COPY_FROM', $QUALIFIED_MODULE)}</th>
								<th width="40px"></th>
								<th>{vtranslate('LBL_RR_COPY_TO', $QUALIFIED_MODULE)}</th>
								<th width="40px"></th>
							</tr></thead>
							<tbody class="mappingRows">
								{foreach from=$RULES item=RULE}
									<tr class="savedRule hide"
										data-srcfield="{$RULE.srcfield|escape:'html'}"
										data-targetfield="{$RULE.targetfield|escape:'html'}"
										data-targetmodule="{$RULE.targetmodule|escape:'html'}"></tr>
								{/foreach}
							</tbody>
						</table>
						<button type="button" class="btn btn-default btn-sm addMappingRow"><i class="fa fa-plus"></i> {vtranslate('LBL_RR_ADD_MAPPING', $QUALIFIED_MODULE)}</button>

						<div class="alert alert-success rrPreview marginTop10"><i class="fa fa-eye"></i> <span class="rrPreviewText"></span></div>

						<div class="textAlignRight marginTop10">
							<button type="button" class="btn btn-default cancelTab">{vtranslate('LBL_CANCEL', $QUALIFIED_MODULE)}</button>
							<button type="button" class="btn btn-primary saveTab"><i class="fa fa-save"></i> {vtranslate('LBL_SAVE', $QUALIFIED_MODULE)}</button>
						</div>
						</div>
					</td>
				</tr>
			{/foreach}
			</tbody>
		</table>
	{/if}
</div>
{/strip}

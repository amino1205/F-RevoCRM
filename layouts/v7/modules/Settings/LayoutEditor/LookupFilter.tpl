{*+**********************************************************************************
 * Issue #1621 ルックアップ絞り込みタブ
 * reference 項目ごとに「条件式ビルダ（参照先項目 ＝ 値）」を編集する。
 ************************************************************************************}
{strip}
<div class="lookupFilterContent referenceRuleContent" data-source-module="{$SELECTED_MODULE_NAME}" data-rule-type="filter" data-fields-meta="{$FIELDS_META|escape:'html'}">
	<div class="alert alert-info"><i class="fa fa-info-circle"></i> {vtranslate('LBL_LOOKUP_FILTER_DESC', $QUALIFIED_MODULE)}</div>
	<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> {vtranslate('LBL_LOOKUP_FILTER_NOTE', $QUALIFIED_MODULE)}</div>

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
			{assign var=SHOWN_COUNT value=0}
			{foreach from=$REFERENCE_FIELDS key=FIELD_NAME item=FIELD_MODEL}
				{assign var=FIELD_RULES value=$REFERENCE_RULES.$FIELD_NAME|default:null}
				{assign var=FILTER_DATA value=$FIELD_RULES.filter|default:null}
				{assign var=ENABLED value=false}
				{assign var=RULES value=[]}
				{if $FILTER_DATA}{assign var=ENABLED value=$FILTER_DATA.enabled}{assign var=RULES value=$FILTER_DATA.rules}{/if}
				{assign var=REF_LIST value=$FIELD_MODEL->getReferenceList()}
				{assign var=POPUP_MODULE value=''}
				{if $REF_LIST}{foreach from=$REF_LIST item=RM}{assign var=POPUP_MODULE value=$RM}{/foreach}{/if}
				{* フィルタ非対応モジュール（Leads/Products/Users 等）を参照する項目は絞り込みできないため描画しない *}
				{if in_array($POPUP_MODULE, $FILTER_UNSUPPORTED_MODULES)}{continue}{/if}
				{assign var=SHOWN_COUNT value=$SHOWN_COUNT+1}

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
							<span class="label label-warning">{vtranslate('LBL_RR_CONFIGURED', $QUALIFIED_MODULE)} ({$RULES|@count})</span>
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

						<table class="table conditionTable">
							<thead><tr>
								<th>{vtranslate('LBL_RR_POPUP_FIELD', $QUALIFIED_MODULE)}</th>
								<th width="40px"></th>
								<th>{vtranslate('LBL_RR_VALUE_KIND', $QUALIFIED_MODULE)}</th>
								<th>{vtranslate('LBL_RR_VALUE', $QUALIFIED_MODULE)}</th>
								<th width="40px"></th>
							</tr></thead>
							<tbody class="conditionRows">
								{* 既存ルールを data-* で JS に渡す（JS が行を再構築） *}
								{foreach from=$RULES item=RULE}
									<tr class="savedRule hide"
										data-srcfield="{$RULE.srcfield|escape:'html'}"
										data-srcfield-type="{$RULE.srcfield_type|default:'field'|escape:'html'}"
										data-fixed-value="{$RULE.fixed_value|default:''|escape:'html'}"
										data-targetfield="{$RULE.targetfield|escape:'html'}"
										data-targetmodule="{$RULE.targetmodule|escape:'html'}"></tr>
								{/foreach}
							</tbody>
						</table>
						<button type="button" class="btn btn-default btn-sm addConditionRow"><i class="fa fa-plus"></i> {vtranslate('LBL_RR_ADD_CONDITION', $QUALIFIED_MODULE)}</button>

						<div class="alert alert-warning rrPreview marginTop10"><i class="fa fa-eye"></i> <span class="rrPreviewText"></span></div>

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
		{if $SHOWN_COUNT eq 0}
			<div class="alert alert-warning">{vtranslate('LBL_LOOKUP_FILTER_NO_FILTERABLE_FIELDS', $QUALIFIED_MODULE)}</div>
		{/if}
	{/if}
</div>
{/strip}

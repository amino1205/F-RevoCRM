{*+**********************************************************************************
 * Issue #1621 [要望]関連項目フィルタ／自動セット機能
 * モジュールレイアウトエディタ「関連項目設定」タブのテンプレート
 ************************************************************************************}
{strip}
{* Issue #1621 セキュリティ:
 *  data-fields-meta は JSON 文字列を HTML 属性として保持する。シングルクォート区切り
 *  かつ JSON 内のクォート/タグを HTML 文字参照に変換しないと、ラベルにシングルクォートを
 *  含むカスタムフィールドや翻訳で属性が早期終端し XSS の経路となる。
 *  escape:'html' で <>"'& を文字参照に変換する（jQuery .data() は HTML 復号後にパースする）。
 *}
<div class="referenceRuleContent" data-source-module="{$SELECTED_MODULE_NAME}" data-fields-meta="{$FIELDS_META|escape:'html'}">
	<div class="alert alert-info">
		<i class="fa fa-info-circle"></i>
		{vtranslate('LBL_REFERENCE_RULE_DESCRIPTION', $QUALIFIED_MODULE)}
	</div>

	{if php7_count($REFERENCE_FIELDS) eq 0}
		<div class="alert alert-warning">
			{vtranslate('LBL_REFERENCE_RULE_NO_FIELDS', $QUALIFIED_MODULE)}
		</div>
	{else}
		<table class="table table-bordered themeTableColor referenceRuleTable">
			<thead>
				<tr>
					<th width="35%">{vtranslate('LBL_FIELD', $QUALIFIED_MODULE)}</th>
					<th width="25%">{vtranslate('LBL_REFERENCE_TARGET_MODULE', $QUALIFIED_MODULE)}</th>
					<th width="40%">{vtranslate('LBL_REFERENCE_RULE_STATUS', $QUALIFIED_MODULE)}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$REFERENCE_FIELDS key=FIELD_NAME item=FIELD_MODEL}
					{assign var=FIELD_RULES value=$REFERENCE_RULES.$FIELD_NAME|default:null}
					{assign var=HAS_FILTER value=false}
					{assign var=HAS_AUTO_SET value=false}
					{if $FIELD_RULES}
						{if $FIELD_RULES.filter.enabled}{assign var=HAS_FILTER value=true}{/if}
						{if $FIELD_RULES.auto_set.enabled}{assign var=HAS_AUTO_SET value=true}{/if}
					{/if}
					<tr class="referenceFieldRow" data-field-name="{$FIELD_NAME}">
						<td>
							<a href="javascript:void(0);" class="toggleAccordion">
								<i class="fa fa-chevron-right accordionIcon"></i>
								<strong>{vtranslate($FIELD_MODEL->get('label'), $SELECTED_MODULE_NAME)}</strong>
								<small class="muted">({$FIELD_NAME})</small>
							</a>
						</td>
						<td>
							{assign var=REF_LIST value=$FIELD_MODEL->getReferenceList()}
							{if $REF_LIST}
								{foreach from=$REF_LIST item=REF_MODULE name=refMods}
									{vtranslate($REF_MODULE, $REF_MODULE)}{if !$smarty.foreach.refMods.last}, {/if}
								{/foreach}
							{/if}
						</td>
						{* Issue #1621: JS が Ajax 保存後にバッジを更新できるよう、各ラベルを
						 *  data-label-* に出力。.referenceFieldStatus クラスで JS が参照する。
						 *}
						<td class="referenceFieldStatus"
							data-label-both="{vtranslate('LBL_REFERENCE_RULE_BOTH', $QUALIFIED_MODULE)|escape:'html'}"
							data-label-filter-only="{vtranslate('LBL_REFERENCE_RULE_FILTER_ONLY', $QUALIFIED_MODULE)|escape:'html'}"
							data-label-auto-set-only="{vtranslate('LBL_REFERENCE_RULE_AUTO_SET_ONLY', $QUALIFIED_MODULE)|escape:'html'}"
							data-label-unset="{vtranslate('LBL_REFERENCE_RULE_UNSET', $QUALIFIED_MODULE)|escape:'html'}">
							{if $HAS_FILTER and $HAS_AUTO_SET}
								<span class="label label-info">{vtranslate('LBL_REFERENCE_RULE_BOTH', $QUALIFIED_MODULE)}</span>
							{elseif $HAS_AUTO_SET}
								<span class="label label-success">{vtranslate('LBL_REFERENCE_RULE_AUTO_SET_ONLY', $QUALIFIED_MODULE)}</span>
							{elseif $HAS_FILTER}
								<span class="label label-warning">{vtranslate('LBL_REFERENCE_RULE_FILTER_ONLY', $QUALIFIED_MODULE)}</span>
							{else}
								<span class="label label-default">{vtranslate('LBL_REFERENCE_RULE_UNSET', $QUALIFIED_MODULE)}</span>
							{/if}
						</td>
					</tr>
					<tr class="referenceFieldEditor hide" data-field-name="{$FIELD_NAME}">
						<td colspan="3">
							{assign var=FILTER_DATA value=$FIELD_RULES.filter|default:null}
							{assign var=AUTO_SET_DATA value=$FIELD_RULES.auto_set|default:null}

							{include file=vtemplate_path('ReferenceRuleSection.tpl', $QUALIFIED_MODULE)
								RULE_TYPE='filter'
								SECTION_LABEL_KEY='LBL_REFERENCE_RULE_FILTER_SECTION'
								SECTION_DESCRIPTION_KEY='LBL_REFERENCE_RULE_FILTER_DESC'
								SECTION_DATA=$FILTER_DATA
								FIELD_NAME=$FIELD_NAME}

							{include file=vtemplate_path('ReferenceRuleSection.tpl', $QUALIFIED_MODULE)
								RULE_TYPE='auto_set'
								SECTION_LABEL_KEY='LBL_REFERENCE_RULE_AUTO_SET_SECTION'
								SECTION_DESCRIPTION_KEY='LBL_REFERENCE_RULE_AUTO_SET_DESC'
								SECTION_DATA=$AUTO_SET_DATA
								FIELD_NAME=$FIELD_NAME}

							<div class="referenceRuleActions textAlignRight marginTop10">
								<button type="button" class="btn btn-default cancelReferenceRule">
									{vtranslate('LBL_CANCEL', $QUALIFIED_MODULE)}
								</button>
								<button type="button" class="btn btn-primary saveReferenceRule">
									<i class="fa fa-save"></i> {vtranslate('LBL_SAVE', $QUALIFIED_MODULE)}
								</button>
							</div>
						</td>
					</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
</div>
{/strip}

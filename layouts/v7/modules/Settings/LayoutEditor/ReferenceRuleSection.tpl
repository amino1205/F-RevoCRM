{*+**********************************************************************************
 * Issue #1621 [要望]関連項目フィルタ／自動セット機能
 * 関連項目設定タブのフィルタ／自動セットそれぞれのセクション部分
 * 親テンプレート(ReferenceRule.tpl) から RULE_TYPE / SECTION_LABEL_KEY /
 * SECTION_DESCRIPTION_KEY / SECTION_DATA / FIELD_NAME を渡されて利用される。
 ************************************************************************************}
{strip}
{assign var=SECTION_ENABLED value=false}
{assign var=SECTION_RULES value=[]}
{if $SECTION_DATA}
	{assign var=SECTION_ENABLED value=$SECTION_DATA.enabled}
	{assign var=SECTION_RULES value=$SECTION_DATA.rules}
{/if}
<div class="referenceRuleSection" data-rule-type="{$RULE_TYPE}">
	<div class="referenceRuleSectionHeader">
		<label class="referenceRuleEnabledLabel">
			<input type="checkbox" class="referenceRuleEnabled"
				   {if $SECTION_ENABLED}checked{/if} value="1" />
			<strong>{vtranslate($SECTION_LABEL_KEY, $QUALIFIED_MODULE)}</strong>
		</label>
		<small class="muted marginLeft10">{vtranslate($SECTION_DESCRIPTION_KEY, $QUALIFIED_MODULE)}</small>
	</div>

	<div class="referenceRuleList marginTop10">
		{if php7_count($SECTION_RULES) eq 0}
			<div class="referenceRuleEmpty muted">
				{vtranslate('LBL_REFERENCE_RULE_NO_RULES', $QUALIFIED_MODULE)}
			</div>
		{else}
			{foreach from=$SECTION_RULES item=RULE}
				{* Issue #1621 セキュリティ:
				 *  - data 属性として JS が読み取る srcfield/targetfield/targetmodule を出力
				 *    （これが無いと bindSave 時に undefined が送信され、既存ルールが消失する）
				 *  - 表示も含めすべて escape:'html' を通す。DB 値は validateSection で実在チェック済みだが
				 *    過去データや手動編集に対する多重防御として明示的にエスケープする
				 *  - 翻訳キー側に DB 由来の文字列を渡さない（vtranslate にユーザ入力を渡すとキー名インジェクション）
				 *}
				<div class="referenceRuleCard"
					data-rule-id="{$RULE.rule_id}"
					data-srcfield="{$RULE.srcfield|escape:'html'}"
					data-targetfield="{$RULE.targetfield|escape:'html'}"
					data-targetmodule="{$RULE.targetmodule|escape:'html'}">
					<span class="ruleCardText">
						<strong>{$RULE.srcfield|escape:'html'}</strong>
						<i class="fa fa-arrow-right"></i>
						<strong>{$RULE.targetfield|escape:'html'}</strong>
						<small class="muted">({$RULE.targetmodule|escape:'html'})</small>
					</span>
					<a href="javascript:void(0);" class="removeRule pull-right" title="{vtranslate('LBL_DELETE', $QUALIFIED_MODULE)}">
						<i class="fa fa-times"></i>
					</a>
				</div>
			{/foreach}
		{/if}
	</div>

	<div class="referenceRuleAddForm hide marginTop10">
		<div class="row">
			<div class="col-sm-5">
				<label>{vtranslate('LBL_REFERENCE_RULE_SRCFIELD', $QUALIFIED_MODULE)}</label>
				<select class="form-control srcfieldSelect"></select>
			</div>
			<div class="col-sm-5">
				<label>{vtranslate('LBL_REFERENCE_RULE_TARGETFIELD', $QUALIFIED_MODULE)}</label>
				<select class="form-control targetfieldSelect" disabled></select>
			</div>
			<div class="col-sm-2">
				<label>&nbsp;</label>
				<button type="button" class="btn btn-success btn-block confirmAddRule" disabled>
					<i class="fa fa-plus"></i> {vtranslate('LBL_ADD', $QUALIFIED_MODULE)}
				</button>
			</div>
		</div>
		<div class="row marginTop10">
			<div class="col-sm-12">
				<small class="muted referenceRulePreview"></small>
			</div>
		</div>
	</div>

	<div class="marginTop10">
		<button type="button" class="btn btn-default btn-sm openAddRuleForm">
			<i class="fa fa-plus"></i> {vtranslate('LBL_REFERENCE_RULE_ADD_RULE', $QUALIFIED_MODULE)}
		</button>
	</div>
</div>
{/strip}

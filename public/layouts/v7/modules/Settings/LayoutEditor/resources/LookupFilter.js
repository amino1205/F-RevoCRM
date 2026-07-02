/*+***********************************************************************************
 * Issue #1621 [要望]関連項目フィルタ／自動セット機能
 * ルックアップ絞り込みタブ クライアント挙動
 *  - アコーディオン開閉／条件行の追加・削除
 *  - 値の種類切替（項目参照⇄固定値で「値」セルを差し替え）
 *  - 自然言語プレビュー更新
 *  - 保存（Ajax action=ReferenceRule&mode=save&rule_type=filter）／未保存警告
 *************************************************************************************/

Settings_LayoutEditor_LookupFilter_Js = (function () {

	function Cls() {}

	Cls.prototype = {
		container: null,
		meta: null,

		registerEvents: function (container) {
			this.container = container.find('.lookupFilterContent');
			if (this.container.length === 0) { return; }
			this.meta = this.container.data('fields-meta') || {};
			this.bindAccordion();
			this.bindAddCondition();
			this.bindRowEvents();
			this.bindSaveCancel();
			this.restoreSavedRules();
		},

		bindAccordion: function () {
			var self = this;
			this.container.on('click', '.toggleAccordion', function () {
				var row = jQuery(this).closest('.referenceFieldRow');
				var editor = row.next('.referenceFieldEditor');
				editor.toggleClass('hide');
				jQuery(this).find('.accordionIcon')
					.toggleClass('fa-chevron-right').toggleClass('fa-chevron-down');
				self.updatePreview(editor);
			});
		},

		// ポップアップ側（参照先）項目の候補。フィルタでは主要項目/関連一覧のみ使用可。
		popupFieldOptions: function (fieldRow) {
			var popupModule = fieldRow.data('popup-module');
			var byTarget = this.meta.all_fields_by_target || {};
			var list = byTarget[popupModule] || [];
			return list.filter(function (f) { return f.filterable === true; });
		},

		// 動的生成した select を F-RevoCRM 共通ヘルパで select2 化する
		applySelect2: function ($scope) {
			if (typeof vtUtils !== 'undefined' && vtUtils.showSelect2ElementView) {
				$scope.find('select').each(function () {
					vtUtils.showSelect2ElementView(jQuery(this));
				});
			}
		},

		// フォーム側 reference 項目（srcfield 候補）
		formReferenceOptions: function () {
			return this.meta.reference_fields || [];
		},

		// 内部名→ラベルを引く（見つからなければ内部名にフォールバック）
		labelFor: function (options, name) {
			for (var i = 0; i < options.length; i++) { if (options[i].name === name) { return options[i].label; } }
			return name;
		},

		// XSS 対策: option の value/text は jQuery API で安全に挿入する
		buildSelect: function (cls, options, valueKey, labelKey) {
			var $sel = jQuery('<select class="form-control input-sm"></select>').addClass(cls);
			$sel.append('<option value=""></option>');
			for (var i = 0; i < options.length; i++) {
				jQuery('<option></option>')
					.attr('value', options[i][valueKey])
					.text(options[i][labelKey])
					.appendTo($sel);
			}
			return $sel;
		},

		// 1 行の DOM を作る。saved があれば値を復元。
		makeConditionRow: function (editor, saved) {
			var self = this;
			var fieldRow = editor.prev('.referenceFieldRow');
			var $tr = jQuery('<tr class="conditionRow"></tr>');

			// 参照先項目
			var $tgt = this.buildSelect('targetfieldSelect', this.popupFieldOptions(fieldRow), 'name', 'label');
			$tr.append(jQuery('<td></td>').append($tgt));
			// 演算子（固定）
			$tr.append('<td class="textAlignCenter"><strong>＝</strong></td>');
			// 値の種類
			var $kind = jQuery('<select class="form-control input-sm valueKind"></select>');
			$kind.append(jQuery('<option value="field"></option>').text(app.vtranslate('JS_RR_KIND_FIELD')));
			$kind.append(jQuery('<option value="fixed_value"></option>').text(app.vtranslate('JS_RR_KIND_FIXED')));
			$tr.append(jQuery('<td></td>').append($kind));
			// 値（種類で差し替え）
			var $valTd = jQuery('<td class="valueCell"></td>');
			$tr.append($valTd);
			// 削除
			$tr.append('<td><a href="javascript:void(0)" class="removeConditionRow text-danger"><i class="fa fa-times"></i></a></td>');

			editor.find('.conditionRows').append($tr);

			// 値セルを種類に応じて構築する関数
			function renderValueCell(kind, savedRule) {
				$valTd.empty();
				if (kind === 'fixed_value') {
					var $inp = jQuery('<input type="text" class="form-control input-sm fixedValueInput" maxlength="255" />');
					if (savedRule) { $inp.val(savedRule.fixed_value || ''); }
					$valTd.append($inp);
				} else {
					var $src = self.buildSelect('srcfieldSelect', self.formReferenceOptions(), 'name', 'label');
					if (savedRule) { $src.val(savedRule.srcfield); }
					$valTd.append($src);
					// append 後に select2 化（値の種類切替で再描画されたときも適用）
					self.applySelect2($valTd);
				}
			}

			// 初期化（saved 復元）
			if (saved) {
				$tgt.val(saved.targetfield);
				$kind.val(saved.srcfield_type || 'field');
				renderValueCell($kind.val(), saved);
			} else {
				renderValueCell('field', null);
			}

			$kind.on('change', function () { renderValueCell(jQuery(this).val(), null); self.updatePreview(editor); });
			$tr.on('change', 'select, input', function () { self.updatePreview(editor); });

			// 値の復元(.val())が済んだ後に行内 select を select2 化する
			this.applySelect2($tr);
			return $tr;
		},

		restoreSavedRules: function () {
			var self = this;
			this.container.find('.referenceFieldEditor').each(function () {
				var editor = jQuery(this);
				editor.find('.savedRule').each(function () {
					var s = {
						srcfield: jQuery(this).data('srcfield') || '',
						srcfield_type: jQuery(this).data('srcfield-type') || 'field',
						fixed_value: jQuery(this).data('fixed-value') || '',
						targetfield: jQuery(this).data('targetfield') || '',
						targetmodule: jQuery(this).data('targetmodule') || ''
					};
					jQuery(this).remove();
					self.makeConditionRow(editor, s);
				});
				self.updatePreview(editor);
			});
		},

		bindAddCondition: function () {
			var self = this;
			this.container.on('click', '.addConditionRow', function () {
				var editor = jQuery(this).closest('.referenceFieldEditor');
				self.makeConditionRow(editor, null);
				editor.data('dirty', true);
			});
		},

		bindRowEvents: function () {
			var self = this;
			this.container.on('click', '.removeConditionRow', function () {
				var editor = jQuery(this).closest('.referenceFieldEditor');
				jQuery(this).closest('.conditionRow').remove();
				editor.data('dirty', true);
				self.updatePreview(editor);
			});
			this.container.on('change', '.conditionRow select, .conditionRow input, .sectionEnabled', function () {
				jQuery(this).closest('.referenceFieldEditor').data('dirty', true);
			});
		},

		// srcfield 名から reference_fields の target を引く
		targetModuleForSrc: function (srcName) {
			var refs = this.meta.reference_fields || [];
			for (var i = 0; i < refs.length; i++) { if (refs[i].name === srcName) { return refs[i].target; } }
			return '';
		},

		collectRules: function (editor) {
			var self = this;
			var rules = [];
			editor.find('.conditionRow').each(function () {
				var $r = jQuery(this);
				// select2 化されると .select2-container(span) にも元 select のクラスがコピーされ
				// クラスのみのセレクタが span/select の2要素にマッチして .val() が空を返すため、
				// select2 化対象の項目は必ず select. でタグ限定する（fixedValueInput は input 限定）。
				var targetfield = $r.find('select.targetfieldSelect').val();
				var kind = $r.find('select.valueKind').val();
				if (!targetfield) { return; }
				if (kind === 'fixed_value') {
					var fv = $r.find('input.fixedValueInput').val();
					if (!fv) { return; }
					rules.push({ srcfield_type: 'fixed_value', fixed_value: fv, targetfield: targetfield, srcfield: '', targetmodule: '' });
				} else {
					var src = $r.find('select.srcfieldSelect').val();
					if (!src) { return; }
					rules.push({ srcfield_type: 'field', srcfield: src, targetfield: targetfield, targetmodule: self.targetModuleForSrc(src), fixed_value: '' });
				}
			});
			return rules;
		},

		updatePreview: function (editor) {
			var fieldRow = editor.prev('.referenceFieldRow');
			var fieldLabel = fieldRow.find('a strong').text();
			var rules = this.collectRules(editor);
			var parts = [];
			for (var i = 0; i < rules.length; i++) {
				var r = rules[i];
				// 内部名ではなく項目ラベルでプレビュー表示する
				var targetLabel = this.labelFor(this.popupFieldOptions(fieldRow), r.targetfield);
				if (r.srcfield_type === 'fixed_value') {
					parts.push(targetLabel + ' = 「' + r.fixed_value + '」');
				} else {
					parts.push(targetLabel + ' = ' + this.labelFor(this.formReferenceOptions(), r.srcfield));
				}
			}
			var text = rules.length === 0 ? '—' :
				(fieldLabel + ' を選ぶとき、' + parts.join(' かつ ') + ' に一致する候補だけ表示します。');
			editor.find('.rrPreviewText').text(text);
		},

		bindSaveCancel: function () {
			var self = this;
			this.container.on('click', '.saveTab', function () {
				var editor = jQuery(this).closest('.referenceFieldEditor');
				self.save(editor);
			});
			this.container.on('click', '.cancelTab', function () {
				var editor = jQuery(this).closest('.referenceFieldEditor');
				editor.addClass('hide');
				editor.data('dirty', false);
			});
			// 未保存警告
			jQuery(window).off('beforeunload.rrFilter').on('beforeunload.rrFilter', function () {
				if (self.container.find('.referenceFieldEditor').filter(function () { return jQuery(this).data('dirty'); }).length > 0) {
					return app.vtranslate('JS_RR_UNSAVED_CONFIRM');
				}
			});
		},

		save: function (editor) {
			var self = this;
			var fieldRow = editor.prev('.referenceFieldRow');
			var fieldName = fieldRow.data('field-name');
			var enabled = editor.find('.sectionEnabled').is(':checked') ? 1 : 0;
			var rules = this.collectRules(editor);

			// 有効ONなのにルールが0件の場合は保存させない
			if (enabled && rules.length === 0) {
				app.helper.showErrorNotification({ message: app.vtranslate('JS_RR_SELECT_REQUIRED') });
				return;
			}

			var params = {
				module: 'LayoutEditor', parent: 'Settings', action: 'ReferenceRule', mode: 'save',
				source_module: this.container.data('source-module'),
				field_name: fieldName, rule_type: 'filter',
				is_enabled: enabled, rules: JSON.stringify(rules)
			};

			// Ajax 呼び出しは旧 ReferenceRule.js / LayoutEditor.js と同じ app.request.post 形式に合わせる。
			// then のコールバック第1引数は error（成功時 null）、第2引数が data。
			app.helper.showProgress();
			app.request.post({ data: params }).then(
				function (error, data) {
					app.helper.hideProgress();
					if (error === null) {
						app.helper.showSuccessNotification({ message: app.vtranslate('JS_RR_SAVED') });
						editor.data('dirty', false);
						editor.addClass('hide');
						// バッジ更新
						var badge = fieldRow.find('.referenceFieldStatus');
						if (rules.length > 0 && enabled) {
							badge.empty().append(
								jQuery('<span class="label label-warning"></span>')
									.text(badge.data('label-set') + ' (' + rules.length + ')')
							);
						} else {
							badge.empty().append(
								jQuery('<span class="label label-default"></span>').text(badge.data('label-unset'))
							);
						}
					} else {
						app.helper.showErrorNotification({ message: (error && error.message) ? error.message : app.vtranslate('JS_ERROR') });
					}
				}
			);
		}
	};

	return Cls;
})();

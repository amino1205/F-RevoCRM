/*+***********************************************************************************
 * Issue #1621 [要望]関連項目フィルタ／自動セット機能
 * 項目自動セットタブ クライアント挙動
 *  - 行＝「コピー元(targetfield: ポップアップ側全項目) → コピー先(srcfield: フォーム側全項目)」
 *  - targetmodule は コピー先が reference 型なら その target を自動採用（参照コピー）、
 *    そうでなければ '' （通常値コピー）
 *  - コピー先重複はローカルでも弾く／自然言語プレビュー／未保存警告
 *  - 保存（Ajax action=ReferenceRule&mode=save&rule_type=auto_set）
 *************************************************************************************/

Settings_LayoutEditor_AutoSet_Js = (function () {

	function Cls() {}

	Cls.prototype = {
		container: null,
		meta: null,

		registerEvents: function (container) {
			this.container = container.find('.autoSetContent');
			if (this.container.length === 0) { return; }
			this.meta = this.container.data('fields-meta') || {};
			this.bindAccordion();
			this.bindAddMapping();
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

		// コピー元候補＝ポップアップ(選択レコード)側モジュールの全項目
		copyFromOptions: function (fieldRow) {
			var popupModule = fieldRow.data('popup-module');
			var byTarget = this.meta.all_fields_by_target || {};
			return byTarget[popupModule] || [];
		},
		// コピー先候補＝フォーム側の全項目
		copyToOptions: function () {
			return this.meta.form_fields || [];
		},
		// フォーム側項目名→ reference の target（無ければ ''）
		formFieldTarget: function (name) {
			var ff = this.meta.form_fields || [];
			for (var i = 0; i < ff.length; i++) { if (ff[i].name === name) { return ff[i].target || ''; } }
			return '';
		},

		// 内部名→ラベルを引く（見つからなければ内部名にフォールバック）
		labelFor: function (options, name) {
			for (var i = 0; i < options.length; i++) { if (options[i].name === name) { return options[i].label; } }
			return name;
		},

		// テンプレート中の %s を args の要素で先頭から順に置換する（i18n プレビュー組み立て用）
		fmt: function (tpl, args) {
			var i = 0;
			return String(tpl).replace(/%s/g, function () { return (i < args.length) ? args[i++] : ''; });
		},

		// XSS 対策: option の value/text は jQuery API で安全に挿入する
		buildSelect: function (cls, options) {
			var $sel = jQuery('<select class="form-control input-sm"></select>').addClass(cls);
			$sel.append('<option value=""></option>');
			for (var i = 0; i < options.length; i++) {
				jQuery('<option></option>').attr('value', options[i].name).text(options[i].label).appendTo($sel);
			}
			return $sel;
		},

		// 動的生成した select を F-RevoCRM 共通ヘルパで select2 化する
		applySelect2: function ($scope) {
			if (typeof vtUtils !== 'undefined' && vtUtils.showSelect2ElementView) {
				$scope.find('select').each(function () {
					vtUtils.showSelect2ElementView(jQuery(this));
				});
			}
		},

		makeMappingRow: function (editor, saved) {
			var self = this;
			var fieldRow = editor.prev('.referenceFieldRow');
			var $tr = jQuery('<tr class="mappingRow"></tr>');
			var $from = this.buildSelect('copyFromSelect', this.copyFromOptions(fieldRow));
			var $to = this.buildSelect('copyToSelect', this.copyToOptions());
			$tr.append(jQuery('<td></td>').append($from));
			$tr.append('<td class="textAlignCenter"><i class="fa fa-arrow-right"></i></td>');
			$tr.append(jQuery('<td></td>').append($to));
			$tr.append('<td><a href="javascript:void(0)" class="removeMappingRow text-danger"><i class="fa fa-times"></i></a></td>');
			editor.find('.mappingRows').append($tr);

			if (saved) {
				$from.val(saved.targetfield); // コピー元 = targetfield
				$to.val(saved.srcfield);      // コピー先 = srcfield
			}
			$tr.on('change', 'select', function () { self.updatePreview(editor); });

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
						targetfield: jQuery(this).data('targetfield') || '',
						targetmodule: jQuery(this).data('targetmodule') || ''
					};
					jQuery(this).remove();
					self.makeMappingRow(editor, s);
				});
				self.updatePreview(editor);
			});
		},

		bindAddMapping: function () {
			var self = this;
			this.container.on('click', '.addMappingRow', function () {
				var editor = jQuery(this).closest('.referenceFieldEditor');
				self.makeMappingRow(editor, null);
				editor.data('dirty', true);
			});
		},

		bindRowEvents: function () {
			var self = this;
			this.container.on('click', '.removeMappingRow', function () {
				var editor = jQuery(this).closest('.referenceFieldEditor');
				jQuery(this).closest('.mappingRow').remove();
				editor.data('dirty', true);
				self.updatePreview(editor);
			});
			this.container.on('change', '.mappingRow select, .sectionEnabled', function () {
				jQuery(this).closest('.referenceFieldEditor').data('dirty', true);
			});
		},

		collectRules: function (editor) {
			var self = this;
			var rules = [];
			var seenTo = {};
			var dup = false;
			editor.find('.mappingRow').each(function () {
				// select2 化されると .select2-container(span) にも元 select のクラスがコピーされ
				// クラスのみのセレクタが span/select の2要素にマッチして .val() が空を返すため select. で限定する
				var copyFrom = jQuery(this).find('select.copyFromSelect').val(); // targetfield
				var copyTo = jQuery(this).find('select.copyToSelect').val();     // srcfield
				if (!copyFrom || !copyTo) { return; }
				if (seenTo[copyTo]) { dup = true; return; }
				seenTo[copyTo] = true;
				rules.push({
					srcfield_type: 'field',
					srcfield: copyTo,
					targetfield: copyFrom,
					targetmodule: self.formFieldTarget(copyTo), // reference 型なら target、でなければ ''
					fixed_value: ''
				});
			});
			return { rules: rules, dup: dup };
		},

		updatePreview: function (editor) {
			var fieldRow = editor.prev('.referenceFieldRow');
			var fieldLabel = fieldRow.find('a strong').text();
			var res = this.collectRules(editor);
			var parts = [];
			for (var i = 0; i < res.rules.length; i++) {
				// 内部名ではなく項目ラベルでプレビュー表示する
				var fromLabel = this.labelFor(this.copyFromOptions(fieldRow), res.rules[i].targetfield);
				var toLabel = this.labelFor(this.copyToOptions(), res.rules[i].srcfield);
				parts.push(this.fmt(app.vtranslate('JS_RR_AUTOSET_MAP'), [fromLabel, toLabel]));
			}
			var text = res.rules.length === 0 ? '—' :
				this.fmt(app.vtranslate('JS_RR_AUTOSET_PREVIEW'),
					[fieldLabel, parts.join(app.vtranslate('JS_RR_MAP_SEP'))]);
			editor.find('.rrPreviewText').text(text);
		},

		bindSaveCancel: function () {
			var self = this;
			this.container.on('click', '.saveTab', function () {
				self.save(jQuery(this).closest('.referenceFieldEditor'));
			});
			this.container.on('click', '.cancelTab', function () {
				var editor = jQuery(this).closest('.referenceFieldEditor');
				editor.addClass('hide');
				editor.data('dirty', false);
			});
			jQuery(window).off('beforeunload.rrAutoset').on('beforeunload.rrAutoset', function () {
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
			var res = this.collectRules(editor);
			if (res.dup) {
				app.helper.showErrorNotification({ message: app.vtranslate('JS_RR_DUPLICATE') });
				return;
			}
			// 有効ONなのにルールが0件の場合は保存させない
			if (enabled && res.rules.length === 0) {
				app.helper.showErrorNotification({ message: app.vtranslate('JS_RR_SELECT_REQUIRED') });
				return;
			}
			var params = {
				module: 'LayoutEditor', parent: 'Settings', action: 'ReferenceRule', mode: 'save',
				source_module: this.container.data('source-module'),
				field_name: fieldName, rule_type: 'auto_set',
				is_enabled: enabled, rules: JSON.stringify(res.rules)
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
						var badge = fieldRow.find('.referenceFieldStatus');
						if (res.rules.length > 0 && enabled) {
							badge.empty().append(
								jQuery('<span class="label label-success"></span>')
									.text(badge.data('label-set') + ' (' + res.rules.length + ')')
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

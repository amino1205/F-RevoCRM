/*+***********************************************************************************
 * Issue #1621 [要望]関連項目フィルタ／自動セット機能
 * モジュールレイアウトエディタ「関連項目設定」タブのクライアント挙動
 *  - アコーディオン展開／折りたたみ
 *  - ルール追加フォームの開閉と入力ハンドリング（プルダウン選択肢の動的構築）
 *  - 「追加」「削除」「保存」 → Ajax
 *************************************************************************************/

Vtiger.Class('Settings_LayoutEditor_ReferenceRule_Js', {}, {

	container: null,
	sourceModule: null,
	fieldsMeta: null,

	getInstance: function () {
		return this;
	},

	/**
	 * registerEvents は LayoutEditor タブ切替時に外部から呼ばれる想定。
	 * jQuery 上のセレクタは関連項目設定タブが描画されてから初めて存在するため、
	 * タブ切替後に呼び出して初期化する。
	 */
	registerEvents: function () {
		var container = jQuery('.referenceRuleContent');
		if (container.length === 0) {
			return;
		}
		this.container     = container;
		this.sourceModule  = container.data('source-module');
		this.fieldsMeta    = container.data('fields-meta') || { reference_fields: [], all_fields_by_target: {} };

		this.bindAccordion();
		this.bindEnableToggle();
		this.bindAddRuleFormOpen();
		this.bindFieldSelects();
		this.bindAddRule();
		this.bindRemoveRule();
		this.bindSave();
		this.bindCancel();
	},

	/**
	 * フィールド行クリックでアコーディオンを開閉
	 */
	bindAccordion: function () {
		this.container.on('click', '.referenceFieldRow .toggleAccordion', function (e) {
			e.preventDefault();
			var row = jQuery(this).closest('.referenceFieldRow');
			var fieldName = row.data('field-name');
			var icon = row.find('.accordionIcon');
			var editor = jQuery('.referenceFieldEditor[data-field-name="' + fieldName + '"]');
			if (editor.hasClass('hide')) {
				editor.removeClass('hide');
				icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');
			} else {
				editor.addClass('hide');
				icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
			}
		});
	},

	/**
	 * セクション有効/無効トグルの変更時にビジュアル状態を更新（保存は「保存」ボタンで）
	 */
	bindEnableToggle: function () {
		this.container.on('change', '.referenceRuleEnabled', function () {
			var section = jQuery(this).closest('.referenceRuleSection');
			section.toggleClass('referenceRuleDisabled', !this.checked);
		});
	},

	/**
	 * 「+ルールを追加」ボタンで追加フォームを表示し、プルダウン選択肢を構築する
	 */
	bindAddRuleFormOpen: function () {
		var self = this;
		this.container.on('click', '.openAddRuleForm', function () {
			var section = jQuery(this).closest('.referenceRuleSection');
			var addForm = section.find('.referenceRuleAddForm');
			addForm.removeClass('hide');
			self.populateSrcfieldOptions(section);
		});
	},

	/**
	 * srcfield プルダウンには、編集対象モジュールの reference 型フィールド一覧を出す
	 *
	 * XSS 対策: f.label はカスタムフィールドラベル由来でユーザ入力。文字列連結による
	 * HTML 構築をやめ、jQuery で <option> を生成し .text() / .val() / .attr() で値を渡す。
	 */
	populateSrcfieldOptions: function (section) {
		var refFields = this.fieldsMeta.reference_fields || [];
		var select = section.find('.srcfieldSelect');
		select.empty();
		select.append(jQuery('<option>').val('').text(app.vtranslate('LBL_SELECT_OPTION')));
		refFields.forEach(function (f) {
			if (!f.target) {
				return; // 単一参照のみ
			}
			select.append(
				jQuery('<option>')
					.val(f.name)
					.attr('data-target', f.target)
					.text(f.label + ' (' + f.target + ')')
			);
		});
		section.find('.targetfieldSelect').empty().prop('disabled', true);
		section.find('.confirmAddRule').prop('disabled', true);
		section.find('.referenceRulePreview').text('');
	},

	/**
	 * 編集中の親 reference フィールドが参照するモジュール（ポップアップに出るレコードの所属モジュール）を取得する。
	 * targetfield の候補はこのモジュール内のフィールドから選ぶ必要がある。
	 * 例: Quotes.potential_id を編集中なら、popupModule = 'Potentials'
	 */
	getPopupModuleFor: function (editor) {
		var parentFieldName = editor.data('field-name');
		var refFields = this.fieldsMeta.reference_fields || [];
		for (var i = 0; i < refFields.length; i++) {
			if (refFields[i].name === parentFieldName) {
				return refFields[i].target;
			}
		}
		return null;
	},

	/**
	 * srcfield 変更時の挙動:
	 *  - targetmodule のプレビューを更新（srcfield の参照先モジュール）
	 *  - targetfield 候補は「親フィールドが参照するモジュール」のフィールド一覧から populate
	 *    （= ポップアップに出るレコードの所属モジュール内のフィールド）
	 */
	bindFieldSelects: function () {
		var self = this;
		this.container.on('change', '.srcfieldSelect', function () {
			var section = jQuery(this).closest('.referenceRuleSection');
			var editor  = section.closest('.referenceFieldEditor');
			var srcOption    = jQuery(this).find('option:selected');
			var srcTargetMod = srcOption.data('target'); // srcfield の参照先 → targetmodule に保存
			var popupModule  = self.getPopupModuleFor(editor); // targetfield 候補の取り出し元
			var targetSelect = section.find('.targetfieldSelect');

			targetSelect.empty();
			targetSelect.append(jQuery('<option>').val('').text(app.vtranslate('LBL_SELECT_OPTION')));

			if (!srcTargetMod || !popupModule) {
				targetSelect.prop('disabled', true);
				section.find('.confirmAddRule').prop('disabled', true);
				section.find('.referenceRulePreview').text('');
				return;
			}

			var candidates = (self.fieldsMeta.all_fields_by_target || {})[popupModule] || [];
			candidates.forEach(function (f) {
				// XSS 対策: f.label は翻訳されたフィールドラベル。.text() で安全に挿入
				targetSelect.append(jQuery('<option>').val(f.name).text(f.label));
			});
			targetSelect.prop('disabled', false);
			section.find('.referenceRulePreview').text(
				app.vtranslate('LBL_REFERENCE_RULE_TARGET_MODULE') + ': ' + srcTargetMod
			);
		});

		this.container.on('change', '.targetfieldSelect', function () {
			var section = jQuery(this).closest('.referenceRuleSection');
			var srcVal = section.find('.srcfieldSelect').val();
			var targetVal = jQuery(this).val();
			section.find('.confirmAddRule').prop('disabled', !(srcVal && targetVal));
		});
	},

	/**
	 * 追加ボタン → ルールカードを DOM に追加（未保存状態）
	 */
	bindAddRule: function () {
		this.container.on('click', '.confirmAddRule', function () {
			var section = jQuery(this).closest('.referenceRuleSection');
			var srcSelect = section.find('.srcfieldSelect');
			var srcVal = srcSelect.val();
			var srcLabel = srcSelect.find('option:selected').text();
			var targetModule = srcSelect.find('option:selected').data('target');
			var targetSelect = section.find('.targetfieldSelect');
			var targetVal = targetSelect.val();
			var targetLabel = targetSelect.find('option:selected').text();
			if (!srcVal || !targetVal) {
				return;
			}

			// 重複チェック
			var dup = section.find('.referenceRuleCard').filter(function () {
				return jQuery(this).data('srcfield') === srcVal
					&& jQuery(this).data('targetfield') === targetVal;
			});
			if (dup.length > 0) {
				app.helper.showErrorNotification({ message: app.vtranslate('JS_REFERENCE_RULE_DUPLICATE') });
				return;
			}

			// XSS 対策: srcVal / targetVal / targetModule はカスタムフィールド名・モジュール名
			// で通常は英数字+_ のみだが、API 経由で書き込まれた値や将来の拡張に備えて文字列連結
			// による HTML 生成を避け、jQuery の DOM API + .text() で安全に組み立てる。
			var card = jQuery('<div class="referenceRuleCard"></div>');
			card.attr('data-srcfield', srcVal);
			card.attr('data-targetfield', targetVal);
			card.attr('data-targetmodule', targetModule);

			var text = jQuery('<span class="ruleCardText"></span>');
			text.append(jQuery('<strong>').text(srcVal));
			text.append(' ');
			text.append(jQuery('<i class="fa fa-arrow-right"></i>'));
			text.append(' ');
			text.append(jQuery('<strong>').text(targetVal));
			text.append(' ');
			text.append(jQuery('<small class="muted">').text('(' + targetModule + ')'));

			var removeLink = jQuery('<a href="javascript:void(0);" class="removeRule pull-right"></a>');
			removeLink.append(jQuery('<i class="fa fa-times"></i>'));

			card.append(text).append(removeLink);

			section.find('.referenceRuleEmpty').remove();
			section.find('.referenceRuleList').append(card);

			// ルールが 1 件でも入った時点でセクション「有効」トグルを自動 ON にする。
			// （ユーザがトグルを忘れて保存すると is_enabled=0 で DB に入り、
			//   loadForEditor が無視するためフィルタ／自動セットが効かなくなる）
			var enableToggle = section.find('.referenceRuleEnabled');
			if (!enableToggle.is(':checked')) {
				enableToggle.prop('checked', true).trigger('change');
			}

			// フォームをリセット
			section.find('.referenceRuleAddForm').addClass('hide');
			srcSelect.val('').trigger('change');
		});
	},

	/**
	 * ルールカード ✕ ボタンで未保存削除（DOM のみ）
	 */
	bindRemoveRule: function () {
		this.container.on('click', '.removeRule', function () {
			var card = jQuery(this).closest('.referenceRuleCard');
			var list = card.closest('.referenceRuleList');
			card.remove();
			if (list.find('.referenceRuleCard').length === 0) {
				list.append('<div class="referenceRuleEmpty muted">'
					+ app.vtranslate('LBL_REFERENCE_RULE_NO_RULES') + '</div>');
			}
		});
	},

	/**
	 * 保存ボタン → セクション単位で Ajax 送信。filter / auto_set を順次保存する。
	 * 成功後はアコーディオンを折りたたみ、ステータスバッジを最新状態に更新する。
	 */
	bindSave: function () {
		var self = this;
		this.container.on('click', '.saveReferenceRule', function () {
			var editor = jQuery(this).closest('.referenceFieldEditor');
			var fieldName = editor.data('field-name');
			var sections = editor.find('.referenceRuleSection');
			var requests = [];
			// 保存後のバッジ表示用に enabled 状態を控える
			var newState = { filter: false, auto_set: false };
			sections.each(function () {
				var section = jQuery(this);
				var ruleType  = section.data('rule-type');
				var isEnabled = section.find('.referenceRuleEnabled').is(':checked') ? 1 : 0;
				var rules = [];
				section.find('.referenceRuleCard').each(function () {
					rules.push({
						srcfield:     jQuery(this).data('srcfield'),
						targetfield:  jQuery(this).data('targetfield'),
						targetmodule: jQuery(this).data('targetmodule'),
					});
				});
				requests.push({ ruleType: ruleType, isEnabled: isEnabled, rules: rules });
				newState[ruleType] = (isEnabled === 1);
			});

			self.postSave(fieldName, requests).then(function () {
				app.helper.showSuccessNotification({ message: app.vtranslate('JS_REFERENCE_RULE_SAVED') });
				// アコーディオン折りたたみ
				editor.addClass('hide');
				jQuery('.referenceFieldRow[data-field-name="' + fieldName + '"] .accordionIcon')
					.removeClass('fa-chevron-down').addClass('fa-chevron-right');
				// ステータスバッジを最新状態に更新
				self.updateStatusBadge(fieldName, newState);
			}, function (err) {
				app.helper.showErrorNotification({ message: (err && err.message) || 'save failed' });
			});
		});
	},

	/**
	 * 指定フィールド行のステータスバッジを再描画する。
	 *
	 * バッジ翻訳テキストはサーバ側で td.referenceFieldStatus の data-label-* 属性として
	 * 出力されているため、JS から $jsLanguageStrings を参照せず読み取れる。
	 *
	 * @param {string} fieldName
	 * @param {Object} state  { filter: bool, auto_set: bool }
	 */
	updateStatusBadge: function (fieldName, state) {
		var row = jQuery('.referenceFieldRow[data-field-name="' + fieldName + '"]');
		var statusTd = row.find('.referenceFieldStatus');
		if (statusTd.length === 0) {
			return;
		}

		var labelClass, labelText;
		if (state.filter && state.auto_set) {
			labelClass = 'label-info';
			labelText  = statusTd.data('label-both');
		} else if (state.auto_set) {
			labelClass = 'label-success';
			labelText  = statusTd.data('label-auto-set-only');
		} else if (state.filter) {
			labelClass = 'label-warning';
			labelText  = statusTd.data('label-filter-only');
		} else {
			labelClass = 'label-default';
			labelText  = statusTd.data('label-unset');
		}

		// XSS 対策: .text() で挿入。data 属性の値はサーバ側で escape:'html' 済み
		statusTd.empty().append(
			jQuery('<span class="label">').addClass(labelClass).text(labelText)
		);
	},

	/**
	 * filter / auto_set 双方を順次 POST する。失敗時は最初のエラーで打ち切り。
	 */
	postSave: function (fieldName, requests) {
		var self = this;
		var deferred = jQuery.Deferred();
		var index = 0;
		var sendNext = function () {
			if (index >= requests.length) {
				deferred.resolve();
				return;
			}
			var req = requests[index++];
			var params = {
				module:        'Settings:LayoutEditor',
				action:        'ReferenceRule',
				mode:          'save',
				source_module: self.sourceModule,
				field_name:    fieldName,
				rule_type:     req.ruleType,
				is_enabled:    req.isEnabled,
				rules:         JSON.stringify(req.rules),
			};
			app.request.post({ data: params }).then(function () {
				sendNext();
			}, function (err) {
				deferred.reject(err);
			});
		};
		sendNext();
		return deferred.promise();
	},

	/**
	 * キャンセル → アコーディオン折りたたみ（DOM の変更は破棄せずそのまま、リロードまで保持）
	 */
	bindCancel: function () {
		this.container.on('click', '.cancelReferenceRule', function () {
			var editor = jQuery(this).closest('.referenceFieldEditor');
			var fieldName = editor.data('field-name');
			editor.addClass('hide');
			jQuery('.referenceFieldRow[data-field-name="' + fieldName + '"] .accordionIcon')
				.removeClass('fa-chevron-down').addClass('fa-chevron-right');
		});
	},
});

// タブクリックによるコンテンツ pjax ロードは LayoutEditor.js の
// triggerReferenceRuleTabClickEvent / showReferenceRuleUI が担当する。
// pjax 描画後に LayoutEditor.js 側が `new Settings_LayoutEditor_ReferenceRule_Js().registerEvents()`
// を呼び出すことで、このファイルが定義する内部イベント（アコーディオン展開・追加・保存等）が
// 動作する。本ファイルは Vtiger.Class 定義の提供のみを担う。

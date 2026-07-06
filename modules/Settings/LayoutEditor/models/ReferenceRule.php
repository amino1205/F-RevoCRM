<?php
/*+**********************************************************************************
 * Issue #1621 [要望]関連項目フィルタ／自動セット機能
 * Settings_LayoutEditor_ReferenceRule_Model
 *
 * vtiger_reference_rule_section / vtiger_reference_rule の CRUD を担うモデル。
 * - 編集画面ランタイム向けに JS が読む JSON 形式へ整形して返す loadForEditor()
 * - 設定画面向けにモジュール単位でアコーディオン表示用データを返す loadForSettings()
 * - 設定画面からの保存 save()
 ************************************************************************************/

class Settings_LayoutEditor_ReferenceRule_Model {

    const RULE_TYPE_FILTER   = 'filter';
    const RULE_TYPE_AUTO_SET = 'auto_set';

    /**
     * フィルタ（ポップアップ検索）が search_params を無視するモジュール。
     * これらを参照先とする reference 項目は「関連絞り込み（フィルタ）」では対象外にする。
     *  - Users : searchRecord を独自実装しており（vtiger_users を直接検索・crmentity 非経由）、
     *            $search_params 引数を受け取るが本文で一切参照しないため絞り込みが効かない。
     * ※ Leads / Products は search_params 分岐を実装したため対象外から除外した（Issue #1621 Phase 10）。
     *   - Leads は base 経路へフォールバックし converted=0 除外を維持。
     *   - Products / Services は EnhancedQueryGenerator 経路に discontinued=1 を後付けして絞り込みに対応。
     * ※ 自動セット（AutoSet）は検索を伴わないため除外しない。
     */
    const FILTER_UNSUPPORTED_MODULES = array('Users');

    public static function getFilterUnsupportedModules() {
        return self::FILTER_UNSUPPORTED_MODULES;
    }

    /**
     * テーブル存在キャッシュ。マイグレーション未実行環境で全画面が SQL エラーを
     * 出し続けるのを避けるため、最初の判定結果を 1 リクエスト内で再利用する。
     * @var bool|null
     */
    private static $tablesExistCache = null;

    /**
     * 編集画面ランタイム向けに、有効なルールを Edit.js が読める配列形式で返す。
     * 既存 config.customize.php の構造と互換：
     *   [
     *     ['module' => 'Quotes', 'field' => 'potential_id',
     *      'param' => [
     *        ['srcfield' => 'account_id', 'targetfield' => 'related_to',
     *         'targetmodule' => 'Accounts', 'settargetfield' => 'label'],
     *      ]
     *     ],
     *     ...
     *   ]
     *
     * @param string $ruleType self::RULE_TYPE_FILTER または self::RULE_TYPE_AUTO_SET
     * @return array
     */
    public static function loadForEditor($ruleType) {
        if (!self::isValidRuleType($ruleType)) {
            return array();
        }
        // マイグレーション未実行環境では空配列を返す（fetchByAssoc(false) によるクラッシュ防止）
        if (!self::tablesExist()) {
            return array();
        }

        $db = PearDatabase::getInstance();
        $sql = "SELECT s.module_name, s.field_name,
                       r.srcfield, r.srcfield_type, r.fixed_value,
                       r.targetfield, r.targetmodule, r.sequence
                FROM vtiger_reference_rule_section s
                INNER JOIN vtiger_reference_rule r ON r.section_id = s.section_id
                WHERE s.rule_type = ? AND s.is_enabled = 1
                ORDER BY s.module_name, s.field_name, r.sequence";
        $result = $db->pquery($sql, array($ruleType));
        if (!$result) {
            return array();
        }

        // (module, field) ごとにグルーピング
        $grouped = array();
        while ($row = $db->fetchByAssoc($result)) {
            $key = $row['module_name'] . '|' . $row['field_name'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'module' => $row['module_name'],
                    'field'  => $row['field_name'],
                    'param'  => array(),
                );
            }
            $grouped[$key]['param'][] = array(
                'srcfield'       => $row['srcfield'],
                'srcfield_type'  => $row['srcfield_type'],
                'fixed_value'    => $row['fixed_value'],
                'targetfield'    => $row['targetfield'],
                'targetmodule'   => $row['targetmodule'],
                'settargetfield' => 'label', // 既存実装は常に 'label' 固定のため定数化
            );
        }

        return array_values($grouped);
    }

    /**
     * 設定画面で当該モジュールの全 reference フィールド分のルールをまとめて返す。
     * 各フィールドに対し filter / auto_set 両方の「セクション + ルール一覧」を含む。
     *
     * @param string $moduleName
     * @return array  ['field_name' => ['filter' => [...], 'auto_set' => [...]], ...]
     */
    public static function loadForSettings($moduleName) {
        if (!self::tablesExist()) {
            return array();
        }

        $db = PearDatabase::getInstance();
        $sql = "SELECT s.section_id, s.field_name, s.rule_type, s.is_enabled,
                       r.rule_id, r.srcfield, r.srcfield_type, r.fixed_value,
                       r.targetfield, r.targetmodule, r.sequence
                FROM vtiger_reference_rule_section s
                LEFT JOIN vtiger_reference_rule r ON r.section_id = s.section_id
                WHERE s.module_name = ?
                ORDER BY s.field_name, s.rule_type, r.sequence";
        $result = $db->pquery($sql, array($moduleName));
        if (!$result) {
            return array();
        }

        $bucket = array();
        while ($row = $db->fetchByAssoc($result)) {
            $field    = $row['field_name'];
            $ruleType = $row['rule_type'];

            if (!isset($bucket[$field])) {
                $bucket[$field] = array(
                    self::RULE_TYPE_FILTER   => array('enabled' => false, 'rules' => array()),
                    self::RULE_TYPE_AUTO_SET => array('enabled' => false, 'rules' => array()),
                );
            }
            $bucket[$field][$ruleType]['enabled'] = ((int)$row['is_enabled'] === 1);

            if (!empty($row['rule_id'])) {
                $bucket[$field][$ruleType]['rules'][] = array(
                    'rule_id'       => (int)$row['rule_id'],
                    'srcfield'      => $row['srcfield'],
                    // srcfield_type が null（fixed_value カラム追加前の旧データ／ダンプ互換）の
                    // 場合は 'field'（項目参照）に統一する。
                    'srcfield_type' => $row['srcfield_type'] ? $row['srcfield_type'] : 'field',
                    'fixed_value'   => $row['fixed_value'],
                    'targetfield'   => $row['targetfield'],
                    'targetmodule'  => $row['targetmodule'],
                    'sequence'      => (int)$row['sequence'],
                );
            }
        }

        return $bucket;
    }

    /**
     * 指定モジュール・フィールド・ルール種別のセクション 1 件を保存する（upsert）。
     * セクション配下の既存ルールは一旦すべて削除し、入力された rules で置き換える。
     *
     * トランザクション内で実行する。部分失敗時はロールバックして「有効だがルール 0 件」の
     * 孤児セクションが残らないようにする。
     *
     * @param string $moduleName 例: 'Quotes'
     * @param string $fieldName  例: 'potential_id'
     * @param string $ruleType   self::RULE_TYPE_FILTER または self::RULE_TYPE_AUTO_SET
     * @param bool   $isEnabled  セクションの有効/無効
     * @param array  $rules      各要素 ['srcfield','targetfield','targetmodule'] を持つ配列
     * @throws Exception バリデーション失敗時 / SQL 失敗時
     */
    public static function saveSection($moduleName, $fieldName, $ruleType, $isEnabled, array $rules) {
        if (!self::isValidRuleType($ruleType)) {
            throw new Exception('invalid rule_type: ' . $ruleType);
        }

        // バリデーション（ruleType で分岐するため種別を渡す）
        self::validateSection($moduleName, $fieldName, $ruleType, $rules);

        $db = PearDatabase::getInstance();
        $db->database->StartTrans();
        try {
            // 既存セクション検索
            $sectionResult = $db->pquery(
                "SELECT section_id FROM vtiger_reference_rule_section
                 WHERE module_name = ? AND field_name = ? AND rule_type = ?",
                array($moduleName, $fieldName, $ruleType)
            );

            if ($db->num_rows($sectionResult) > 0) {
                $sectionId = (int)$db->query_result($sectionResult, 0, 'section_id');
                $db->pquery(
                    "UPDATE vtiger_reference_rule_section
                     SET is_enabled = ?, updated_at = NOW()
                     WHERE section_id = ?",
                    array($isEnabled ? 1 : 0, $sectionId)
                );
                // 配下ルールは全削除して入れ替え
                $db->pquery("DELETE FROM vtiger_reference_rule WHERE section_id = ?", array($sectionId));
            } else {
                $sectionId = $db->getUniqueID('vtiger_reference_rule_section');
                $db->pquery(
                    "INSERT INTO vtiger_reference_rule_section
                        (section_id, module_name, field_name, rule_type, is_enabled, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    array($sectionId, $moduleName, $fieldName, $ruleType, $isEnabled ? 1 : 0)
                );
            }

            $sequence = 0;
            foreach ($rules as $rule) {
                $ruleId = $db->getUniqueID('vtiger_reference_rule');
                $srcfieldType = (isset($rule['srcfield_type']) && $rule['srcfield_type'] === 'fixed_value')
                    ? 'fixed_value' : 'field';
                $fixedValue = ($srcfieldType === 'fixed_value' && isset($rule['fixed_value']))
                    ? (string)$rule['fixed_value'] : null;
                $db->pquery(
                    "INSERT INTO vtiger_reference_rule
                        (rule_id, section_id, srcfield, srcfield_type, fixed_value,
                         targetfield, targetmodule, sequence, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    array(
                        $ruleId, $sectionId,
                        isset($rule['srcfield']) ? $rule['srcfield'] : '',
                        $srcfieldType,
                        $fixedValue,
                        $rule['targetfield'],
                        isset($rule['targetmodule']) ? $rule['targetmodule'] : '',
                        $sequence,
                    )
                );
                $sequence++;
            }

            // pquery は dieOnError=false 設定下でも失敗時に _transOK を false にする。
            // ここで明示的に確認して例外を投げ、CompleteTrans に巻き戻させる。
            if (!$db->database->_transOK) {
                throw new Exception('SQL error during saveSection (see vtigercrm log)');
            }
            $db->database->CompleteTrans();
        } catch (Exception $e) {
            $db->database->FailTrans();
            $db->database->CompleteTrans();
            throw $e;
        }
    }

    /**
     * 設定画面のプルダウン候補生成用に、当該モジュールの reference 型フィールド情報を返す。
     * - 各 reference フィールドの参照先モジュール一覧
     * - 参照先モジュール内のフィールド一覧（targetfield 候補）
     *
     * 複数参照（uitype 10 等）のフィールドは現実装で扱えないため、reference_fields からは
     * 完全に除外する（UI 上にも出さない方針）。
     *
     * @param string $moduleName
     * @return array
     */
    public static function buildFieldsMeta($moduleName) {
        $moduleModel = Vtiger_Module_Model::getInstance($moduleName);
        if (!$moduleModel) {
            return array('reference_fields' => array(), 'all_fields_by_target' => array());
        }

        $referenceFields = array();
        $allFieldsByTarget = array();

        // getFields() は画面順（vtiger_blocks.sequence → vtiger_field.sequence）を保持する。
        // 画面順を維持したまま「非表示(!isViewable)を除外し、単一参照の reference 型」だけを拾う。
        foreach ($moduleModel->getFields() as $fieldModel) {
            if (!$fieldModel->isViewable()) {
                continue;
            }
            if ($fieldModel->getFieldDataType() !== 'reference') {
                continue;
            }
            $referenceList = $fieldModel->getReferenceList();
            // 単一参照のみ対象。複数参照（uitype 10 等）は UI から除外する。
            if (!is_array($referenceList) || count($referenceList) !== 1) {
                continue;
            }
            $targetModule = reset($referenceList);

            $referenceFields[] = array(
                'name'   => $fieldModel->getName(),
                'label'  => vtranslate($fieldModel->get('label'), $moduleName),
                'target' => $targetModule,
            );

            // targetfield 候補：参照先モジュールのフィールド一覧
            if (!isset($allFieldsByTarget[$targetModule])) {
                $allFieldsByTarget[$targetModule] = self::collectFieldsForTarget($targetModule);
            }
        }

        return array(
            'reference_fields'      => $referenceFields,   // フォーム側の単一参照 reference 項目（filter の srcfield 候補）
            'all_fields_by_target'  => $allFieldsByTarget,  // 各参照先モジュールの全項目（targetfield/コピー元 候補）
            'form_fields'           => self::collectFieldsForTarget($moduleName), // フォーム側の全項目（auto_set のコピー先候補）
        );
    }

    /**
     * モジュールのフィールド一覧を返す（プルダウン候補）。
     * 各要素に type（データ型）と target（reference 単一参照時の参照先モジュール、無ければ ''）を含める。
     * JS が auto_set のコピー先選択時に targetmodule を自動導出するために type/target を使う。
     */
    private static function collectFieldsForTarget($targetModule) {
        $moduleModel = Vtiger_Module_Model::getInstance($targetModule);
        if (!$moduleModel) {
            return array();
        }

        $list = array();
        // getFields() は vtiger_blocks.sequence → vtiger_field.sequence 順（画面順）を保持する。
        // 並べ替えず順序を維持し、非表示（!isViewable）項目は候補から除外する。
        $allFields = $moduleModel->getFields();
        foreach ($allFields as $fieldModel) {
            if (!$fieldModel->isViewable()) {
                continue;
            }
            $type = $fieldModel->getFieldDataType();
            $refTarget = '';
            if ($type === 'reference') {
                $refList = $fieldModel->getReferenceList();
                if (is_array($refList) && count($refList) === 1) {
                    $refTarget = reset($refList);
                }
            }
            $list[] = array(
                'name'       => $fieldModel->getName(),
                'label'      => vtranslate($fieldModel->get('label'), $targetModule),
                'type'       => $type,
                'target'     => $refTarget,
                // フィルタで参照先項目に使えるのは「主要項目(summaryfield)」または
                // 「関連一覧(headerfield)」が有効な項目のみ（ポップアップ検索が扱える項目）。
                // フィルタタブでのみ参照。自動セットのコピー元は全項目を許可する。
                'filterable' => ($fieldModel->isSummaryField() || $fieldModel->isHeaderField()) ? true : false,
            );
        }
        return $list; // usort は行わない（画面順を維持）
    }

    /**
     * セクション保存時の入力バリデーション。ruleType と各ルールの形態で検証を分岐する。
     *
     * 形態:
     *  (A) filter + 固定値 (srcfield_type='fixed_value'):
     *      targetfield が「fieldName の参照先モジュール（ポップアップ側）」に実在 ＋ fixed_value が安全。
     *      srcfield/targetmodule は不要。
     *  (B) filter + 項目参照 / auto_set + 参照コピー (targetmodule 非空):
     *      srcfield が moduleName に実在し reference 型、targetmodule が srcfield の参照先候補に含まれ、
     *      targetfield が fieldName の参照先モジュールに実在。
     *  (C) auto_set + 通常値コピー (targetmodule 空, srcfield_type='field'):
     *      srcfield が moduleName に実在（型不問）、targetfield が fieldName の参照先モジュールに実在（型不問）。
     *      ※ filter で targetmodule 空（通常値参照）は本UIでは出さないため許可しない。
     */
    private static function validateSection($moduleName, $fieldName, $ruleType, array $rules) {
        if (!self::isValidRuleType($ruleType)) {
            throw new Exception('invalid rule_type: ' . $ruleType);
        }

        require_once 'modules/Settings/LayoutEditor/models/Module.php';
        $supportedModules = Settings_LayoutEditor_Module_Model::getSupportedModules();
        if (!array_key_exists($moduleName, $supportedModules)) {
            throw new Exception('module is not supported by LayoutEditor: ' . $moduleName);
        }

        $moduleModel = Vtiger_Module_Model::getInstance($moduleName);
        if (!$moduleModel) {
            throw new Exception('module not found: ' . $moduleName);
        }
        $fieldModel = Vtiger_Field_Model::getInstance($fieldName, $moduleModel);
        if (!$fieldModel) {
            throw new Exception('field not found: ' . $moduleName . '.' . $fieldName);
        }
        if ($fieldModel->getFieldDataType() !== 'reference') {
            throw new Exception('field is not reference type: ' . $moduleName . '.' . $fieldName);
        }
        $fieldReferenceList = $fieldModel->getReferenceList();
        if (!is_array($fieldReferenceList) || count($fieldReferenceList) !== 1) {
            throw new Exception('field is not a single-reference type: ' . $moduleName . '.' . $fieldName);
        }
        $fieldRefModuleName  = reset($fieldReferenceList);
        $fieldRefModuleModel = Vtiger_Module_Model::getInstance($fieldRefModuleName);
        if (!$fieldRefModuleModel) {
            throw new Exception('field reference module not found: ' . $fieldRefModuleName);
        }

        // フィルタ非対応モジュール（ポップアップ検索が search_params を無視する）を参照する
        // reference 項目には、フィルタ（絞り込み）ルールを設定できない。
        if ($ruleType === self::RULE_TYPE_FILTER
            && in_array($fieldRefModuleName, self::FILTER_UNSUPPORTED_MODULES, true)) {
            throw new Exception('lookup filter is not supported for module: ' . $fieldRefModuleName);
        }

        $seenTargets = array(); // (C)/(B) のコピー先重複検出（同じ srcfield に二重割当て不可）

        foreach ($rules as $idx => $rule) {
            $srcfieldType = (isset($rule['srcfield_type']) && $rule['srcfield_type'] === 'fixed_value')
                ? 'fixed_value' : 'field';
            $targetField  = isset($rule['targetfield']) ? $rule['targetfield'] : '';
            $targetModule = isset($rule['targetmodule']) ? $rule['targetmodule'] : '';
            $srcField     = isset($rule['srcfield']) ? $rule['srcfield'] : '';

            if (empty($targetField)) {
                throw new Exception('rule[' . $idx . '] requires targetfield');
            }
            // targetfield は常にポップアップ側（fieldName の参照先モジュール）に実在
            $targetFieldModel = Vtiger_Field_Model::getInstance($targetField, $fieldRefModuleModel);
            if (!$targetFieldModel) {
                throw new Exception('targetfield not found: ' . $fieldRefModuleName . '.' . $targetField);
            }

            // (A) filter + 固定値
            if ($ruleType === self::RULE_TYPE_FILTER && $srcfieldType === 'fixed_value') {
                self::validateFixedValue($rule, $idx);
                continue;
            }

            // (B)/(C) 項目参照系：srcfield 必須
            if (empty($srcField)) {
                throw new Exception('rule[' . $idx . '] requires srcfield');
            }
            $srcFieldModel = Vtiger_Field_Model::getInstance($srcField, $moduleModel);
            if (!$srcFieldModel) {
                throw new Exception('srcfield not found: ' . $moduleName . '.' . $srcField);
            }

            // コピー先重複（同じ srcfield に複数割当て不可）
            if (isset($seenTargets[$srcField])) {
                throw new Exception('duplicate target field assignment: ' . $srcField);
            }
            $seenTargets[$srcField] = true;

            if ($targetModule !== '') {
                // (B) 参照コピー / フィルタ項目参照：srcfield は reference 型で targetmodule と整合
                if ($srcFieldModel->getFieldDataType() !== 'reference') {
                    throw new Exception('srcfield is not reference type: ' . $moduleName . '.' . $srcField);
                }
                if (!Vtiger_Module_Model::getInstance($targetModule)) {
                    throw new Exception('targetmodule not found: ' . $targetModule);
                }
                $srcReferenceList = $srcFieldModel->getReferenceList();
                if (!is_array($srcReferenceList) || !in_array($targetModule, $srcReferenceList, true)) {
                    throw new Exception(
                        'targetmodule does not match srcfield reference candidates: '
                        . $srcField . ' -> ' . $targetModule
                    );
                }
            } else {
                // (C) auto_set の通常値コピーのみ許可
                if ($ruleType !== self::RULE_TYPE_AUTO_SET) {
                    throw new Exception('filter requires targetmodule (item-reference) or fixed_value');
                }
                // 型は厳密一致を要求しない（ランタイムは input.val(targetdata) で値をそのまま
                // セットするだけのため、型が違っても保存・実行は可能。例: 電話番号→文字列項目）。
                // srcfield/targetfield の実在は上で確認済み。型不一致は UI 側で将来的に
                // 警告表示する余地はあるが、ここではハード拒否しない。
            }
        }
    }

    /**
     * 固定値の安全性検証。EnhancedQueryGenerator は comparator "e" で escapeSqlString を
     * 通さない経路があるため、ここで多重防御として長さ・制御文字を弾く。
     */
    private static function validateFixedValue(array $rule, $idx) {
        $value = isset($rule['fixed_value']) ? (string)$rule['fixed_value'] : '';
        if ($value === '') {
            throw new Exception('rule[' . $idx . '] fixed_value is empty');
        }
        if (mb_strlen($value) > 255) {
            throw new Exception('rule[' . $idx . '] fixed_value too long (max 255)');
        }
        // 制御文字を禁止。ただし TAB(\x09) / LF(\x0A) / CR(\x0D) は許可する。
        //   \x00-\x08 : NUL〜BS（\x09=TAB は除外＝許可）
        //   \x0B,\x0C : VT, FF（\x0A=LF / \x0D=CR は除外＝許可）
        //   \x0E-\x1F : SO〜US
        //   \x7F      : DEL
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new Exception('rule[' . $idx . '] fixed_value contains control characters');
        }
    }

    /**
     * テーブル存在チェック。マイグレーション未実行環境で SQL エラーログを大量に
     * 出さないよう、static キャッシュに結果を保持する。
     *
     * UI 側（Index.tpl の関連項目設定タブ表示判定）からも参照されるため public。
     */
    public static function tablesExist() {
        if (self::$tablesExistCache !== null) {
            return self::$tablesExistCache;
        }
        $db = PearDatabase::getInstance();
        $result = $db->pquery("SHOW TABLES LIKE 'vtiger_reference_rule_section'", array());
        self::$tablesExistCache = ($result && $db->num_rows($result) > 0);
        return self::$tablesExistCache;
    }

    private static function isValidRuleType($ruleType) {
        return $ruleType === self::RULE_TYPE_FILTER || $ruleType === self::RULE_TYPE_AUTO_SET;
    }
}

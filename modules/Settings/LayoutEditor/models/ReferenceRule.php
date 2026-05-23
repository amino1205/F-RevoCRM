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
                       r.srcfield, r.targetfield, r.targetmodule, r.sequence
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
                       r.rule_id, r.srcfield, r.targetfield, r.targetmodule, r.sequence
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
                    'rule_id'      => (int)$row['rule_id'],
                    'srcfield'     => $row['srcfield'],
                    'targetfield'  => $row['targetfield'],
                    'targetmodule' => $row['targetmodule'],
                    'sequence'     => (int)$row['sequence'],
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

        // バリデーション
        self::validateSection($moduleName, $fieldName, $rules);

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
                $db->pquery(
                    "INSERT INTO vtiger_reference_rule
                        (rule_id, section_id, srcfield, targetfield, targetmodule, sequence, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    array(
                        $ruleId, $sectionId,
                        $rule['srcfield'], $rule['targetfield'],
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

        $fields = $moduleModel->getFieldsByType('reference');
        foreach ($fields as $fieldModel) {
            if (!$fieldModel->isActiveField()) {
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
            'reference_fields'      => $referenceFields,
            'all_fields_by_target'  => $allFieldsByTarget,
        );
    }

    /**
     * 参照先モジュールのフィールド一覧（targetfield 選択候補）を返す。
     */
    private static function collectFieldsForTarget($targetModule) {
        $moduleModel = Vtiger_Module_Model::getInstance($targetModule);
        if (!$moduleModel) {
            return array();
        }

        $list = array();
        $allFields = $moduleModel->getFields();
        foreach ($allFields as $fieldModel) {
            $list[] = array(
                'name'  => $fieldModel->getName(),
                'label' => vtranslate($fieldModel->get('label'), $targetModule),
            );
        }
        // 名前ソート
        usort($list, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });
        return $list;
    }

    /**
     * セクション保存時の入力バリデーション。
     *
     * 用語整理（既存 config.customize.php / Edit.js の挙動に従う）:
     *  - $fieldName: 編集対象の reference 型フィールド (例: Quotes.potential_id)。
     *                クリックすると参照先モジュール（例: Potentials）のポップアップが開く。
     *  - rule['srcfield']: ポップアップを開く現在フォームの reference 型フィールド (例: Quotes.account_id)。
     *  - rule['targetfield']: ポップアップに表示されるレコードの中の参照先項目
     *                         （fieldName が参照するモジュール内のフィールド。例: Potentials.related_to）。
     *  - rule['targetmodule']: targetfield が更に参照するモジュール（例: Accounts）。
     *                          srcfield の参照先と一致する必要がある（auto_set が同じ Account ID をやり取りするため）。
     *
     * 検証内容:
     *  - moduleName が LayoutEditor の対象モジュールに含まれること（許可リスト照合）
     *  - moduleName.fieldName が実在し、reference 型で単一参照であること
     *  - 各 rule について:
     *    - srcfield が moduleName 内に実在し、reference 型であること
     *    - targetmodule が実在し、srcfield の参照先候補に含まれること
     *    - targetfield が「fieldName の参照先モジュール」内に実在すること
     */
    private static function validateSection($moduleName, $fieldName, array $rules) {
        // 許可リスト（LayoutEditor が編集対象とするモジュール）
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

        // fieldName が参照するモジュール（単一参照のみ対応）
        $fieldReferenceList = $fieldModel->getReferenceList();
        if (!is_array($fieldReferenceList) || count($fieldReferenceList) !== 1) {
            throw new Exception('field is not a single-reference type: ' . $moduleName . '.' . $fieldName);
        }
        $fieldRefModuleName  = reset($fieldReferenceList);
        $fieldRefModuleModel = Vtiger_Module_Model::getInstance($fieldRefModuleName);
        if (!$fieldRefModuleModel) {
            throw new Exception('field reference module not found: ' . $fieldRefModuleName);
        }

        foreach ($rules as $idx => $rule) {
            if (empty($rule['srcfield']) || empty($rule['targetfield'])) {
                throw new Exception('rule[' . $idx . '] requires srcfield and targetfield');
            }

            // srcfield は編集中モジュール側の reference 型フィールド
            $srcFieldModel = Vtiger_Field_Model::getInstance($rule['srcfield'], $moduleModel);
            if (!$srcFieldModel) {
                throw new Exception('srcfield not found: ' . $moduleName . '.' . $rule['srcfield']);
            }
            if ($srcFieldModel->getFieldDataType() !== 'reference') {
                throw new Exception('srcfield is not reference type: ' . $moduleName . '.' . $rule['srcfield']);
            }

            // targetmodule の実在
            $targetModule = isset($rule['targetmodule']) ? $rule['targetmodule'] : '';
            if (empty($targetModule)) {
                throw new Exception('rule[' . $idx . '] requires targetmodule');
            }
            if (!Vtiger_Module_Model::getInstance($targetModule)) {
                throw new Exception('targetmodule not found: ' . $targetModule);
            }

            // srcfield の参照先候補に targetmodule が含まれること
            // （srcfield に targetfield の値を入れる以上、両者の参照先モジュールは一致しなければならない）
            $srcReferenceList = $srcFieldModel->getReferenceList();
            if (!is_array($srcReferenceList) || !in_array($targetModule, $srcReferenceList, true)) {
                throw new Exception(
                    'targetmodule does not match srcfield reference candidates: '
                    . $rule['srcfield'] . ' -> ' . $targetModule
                );
            }

            // targetfield は「fieldName の参照先モジュール」（= ポップアップに出るレコードの所属モジュール）内に実在
            $targetFieldModel = Vtiger_Field_Model::getInstance($rule['targetfield'], $fieldRefModuleModel);
            if (!$targetFieldModel) {
                throw new Exception('targetfield not found: ' . $fieldRefModuleName . '.' . $rule['targetfield']);
            }
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

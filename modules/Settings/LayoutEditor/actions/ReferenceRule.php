<?php
/*+**********************************************************************************
 * Issue #1621 [要望]関連項目フィルタ／自動セット機能
 * 関連項目設定の保存／取得 Ajax アクション
 *
 * - save     : 1 セクション（フィルタ or 自動セット）分のルールを保存
 * - get      : 指定モジュール・フィールドの最新状態を取得
 *
 * 依存クラス (Settings_LayoutEditor_ReferenceRule_Model 等) は Vtiger_Loader::autoLoad
 * によって解決されるため require は不要。
 ************************************************************************************/

class Settings_LayoutEditor_ReferenceRule_Action extends Settings_Vtiger_Index_Action {

    function __construct() {
        parent::__construct();
        $this->exposeMethod('save');
        $this->exposeMethod('get');
    }

    /**
     * セクション 1 件分（filter または auto_set）のルールを保存する。
     * リクエスト例:
     *   POST module=Settings:LayoutEditor&action=ReferenceRule&mode=save
     *     source_module=Quotes
     *     field_name=potential_id
     *     rule_type=filter
     *     is_enabled=1
     *     rules=[{"srcfield":"account_id","targetfield":"related_to","targetmodule":"Accounts"}, ...]
     */
    public function save(Vtiger_Request $request) {
        $response = new Vtiger_Response();
        try {
            $sourceModule = $request->get('source_module');
            $fieldName    = $request->get('field_name');
            $ruleType     = $request->get('rule_type');
            $isEnabled    = (int)$request->get('is_enabled') === 1;
            $rules        = $request->get('rules');

            if (is_string($rules)) {
                $rules = json_decode($rules, true);
            }
            if (!is_array($rules)) {
                $rules = array();
            }

            Settings_LayoutEditor_ReferenceRule_Model::saveSection(
                $sourceModule, $fieldName, $ruleType, $isEnabled, $rules
            );

            // 保存後の最新状態をまとめて返却（UI 即時反映用）
            $latest = Settings_LayoutEditor_ReferenceRule_Model::loadForSettings($sourceModule);
            $response->setResult(array(
                'success' => true,
                'data'    => isset($latest[$fieldName]) ? $latest[$fieldName] : null,
            ));
        } catch (Exception $e) {
            $response->setError($e->getCode(), $e->getMessage());
        }
        $response->emit();
    }

    /**
     * 指定モジュール・フィールドの現在のルール状態を返す。
     */
    public function get(Vtiger_Request $request) {
        $response = new Vtiger_Response();
        try {
            $sourceModule = $request->get('source_module');
            $fieldName    = $request->get('field_name');
            $latest = Settings_LayoutEditor_ReferenceRule_Model::loadForSettings($sourceModule);
            $response->setResult(isset($latest[$fieldName]) ? $latest[$fieldName] : null);
        } catch (Exception $e) {
            $response->setError($e->getCode(), $e->getMessage());
        }
        $response->emit();
    }
}

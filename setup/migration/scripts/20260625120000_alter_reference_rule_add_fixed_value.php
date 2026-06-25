<?php
/**
 * マイグレーション: alter_reference_rule_add_fixed_value
 * 生成日時: 20260625120000
 *
 * Issue #1621「[要望]関連項目フィルタ／自動セット機能」UI改善対応
 *
 * vtiger_reference_rule に srcfield_type / fixed_value カラムを追加する。
 *  - srcfield_type: 'field'(項目参照) / 'fixed_value'(固定値)。フィルタの固定値条件で使用。
 *  - fixed_value  : 固定値時の定数文字列。
 *
 * 冪等性：カラムが既に存在する場合はスキップ（別環境ダンプでの二重適用を防ぐ）。
 * テーブル未作成（前段マイグレーション未適用）の環境では何もしない。
 */

require_once dirname(__FILE__) . '/../FRMigrationClass.php';

class Migration20260625120000_AlterReferenceRuleAddFixedValue extends FRMigrationClass {

    public function process() {
        // テーブル未作成（前段の create マイグレーション未適用）の環境では何もしない
        if (!$this->checkTableExists('vtiger_reference_rule')) {
            $this->log('vtiger_reference_rule が未作成のためスキップしました');
            return;
        }

        // 既存カラムを取得
        $cols = array();
        $descResult = $this->db->pquery('SHOW COLUMNS FROM vtiger_reference_rule', array());
        while ($row = $this->db->fetchByAssoc($descResult)) {
            $cols[$row['Field']] = true;
        }

        // srcfield_type 追加（項目参照 / 固定値 の区分）
        if (!isset($cols['srcfield_type'])) {
            // 値は 'field' / 'fixed_value'（11文字）。VARCHAR(10) では 'fixed_value' が
            // 切り詰められるため、余裕を持って VARCHAR(20) とする。
            $this->db->pquery(
                "ALTER TABLE vtiger_reference_rule
                 ADD COLUMN srcfield_type VARCHAR(20) NOT NULL DEFAULT 'field' AFTER srcfield",
                array()
            );
            $this->log('vtiger_reference_rule に srcfield_type カラムを追加しました');
        }

        // fixed_value 追加（固定値の定数）
        // 型は TEXT だが、UI 上で入力される固定値は通常短く、モデルの validateFixedValue() で
        // 255 文字を超えると弾いている。将来 255 文字制限を拡張する場合は、この TEXT 定義と
        // validateFixedValue() の上限の両方を見直すこと（一方だけ変えると不整合になる）。
        if (!isset($cols['fixed_value'])) {
            $this->db->pquery(
                "ALTER TABLE vtiger_reference_rule
                 ADD COLUMN fixed_value TEXT NULL AFTER srcfield_type",
                array()
            );
            $this->log('vtiger_reference_rule に fixed_value カラムを追加しました');
        }
    }
}

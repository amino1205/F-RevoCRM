<?php
/**
 * マイグレーション: alter_reference_rule_drop_is_enabled
 * 生成日時: 20260729120000
 *
 * Issue #1621「[要望]関連項目フィルタ／自動セット機能」仕様変更対応
 *
 * 「関連絞り込み」「項目自動セット」の設定画面から「有効」チェックボックスを廃止し、
 * 「設定（ルール）があれば有効」の運用に変更する。これに伴い
 * vtiger_reference_rule_section.is_enabled カラムを廃止する。
 *
 * 移行時のデータ整理:
 *  1. is_enabled=0 のセクションは配下ルールごと削除する。
 *     設定画面では is_enabled=0 のセクションを「未設定」バッジで表示していたため、
 *     残したままカラムを落とすと管理者が「未設定」と認識していた絞り込み／自動セットが
 *     黙って有効化され、参照ポップアップの候補や自動入力の挙動が変わってしまう。
 *  2. ルールが 0 件のセクション（過去の「有効だがルール0件」状態）も削除する。
 *     新仕様では「ルールが無い＝未設定」であり、空セクションは無意味なため。
 *  3. is_enabled カラムを DROP する。
 *
 * 冪等性：カラムが既に存在しない場合はスキップ（別環境ダンプでの二重適用を防ぐ）。
 * テーブル未作成（前段マイグレーション未適用）の環境では何もしない。
 */

require_once dirname(__FILE__) . '/../FRMigrationClass.php';

class Migration20260729120000_AlterReferenceRuleDropIsEnabled extends FRMigrationClass {

    public function process() {
        // テーブル未作成（前段の create マイグレーション未適用）の環境では何もしない
        if (!$this->checkTableExists('vtiger_reference_rule_section')) {
            $this->log('vtiger_reference_rule_section が未作成のためスキップしました');
            return;
        }

        // 既存カラムを取得。
        // ※ SHOW COLUMNS + fetchByAssoc は使わない：fetchByAssoc が array_change_key_case で
        //   キーを小文字化するため 'Field' では取り出せず、判定が常に「カラム無し」になる。
        $cols = $this->db->getColumnNames('vtiger_reference_rule_section');
        if (!is_array($cols)) {
            $cols = array();
        }

        if (!in_array('is_enabled', $cols, true)) {
            $this->log('is_enabled カラムが存在しないためスキップしました');
            return;
        }

        // ① 無効（is_enabled=0）のセクションを配下ルールごと削除
        //    FK は ON DELETE CASCADE だが、環境差（FK 未作成のダンプ等）を考慮して子から明示削除する。
        $this->db->pquery(
            "DELETE FROM vtiger_reference_rule
              WHERE section_id IN (
                    SELECT section_id FROM vtiger_reference_rule_section WHERE is_enabled = 0
              )",
            array()
        );
        $this->db->pquery("DELETE FROM vtiger_reference_rule_section WHERE is_enabled = 0", array());
        $this->log('無効（is_enabled=0）だった関連項目ルールのセクションを削除しました');

        // ② ルールが 0 件のセクション（空セクション）を削除
        //    ルール側が空のときは NOT IN が全件 TRUE となり、全セクションが削除される（＝全て未設定）。
        $this->db->pquery(
            "DELETE FROM vtiger_reference_rule_section
              WHERE section_id NOT IN (
                    SELECT DISTINCT section_id FROM vtiger_reference_rule
              )",
            array()
        );
        $this->log('ルールが 0 件の関連項目ルールセクションを削除しました');

        // ③ is_enabled カラムを廃止
        $this->db->pquery(
            "ALTER TABLE vtiger_reference_rule_section DROP COLUMN is_enabled",
            array()
        );
        $this->log('vtiger_reference_rule_section から is_enabled カラムを削除しました');
    }
}

<?php
/**
 * マイグレーション: create_reference_rule_tables
 * 生成日時: 20260523141621
 *
 * Issue #1621「[要望]関連項目フィルタ／自動セット機能」対応
 *
 * 関連項目フィルタ／項目自動セットのルール定義を保持する DB テーブルを新設する。
 *
 * 既存 config.customize.php 内の $customizeconfig['edit_reference_filter'] /
 * $customizeconfig['edit_reference_auto_set'] 配列の自動取り込みは行わない。
 * 既存環境で該当配列に定義があった場合は、マイグレーション後に GUI から
 * 手動で再登録する運用とする（設計書 §8.1 / §9 参照）。
 */

require_once dirname(__FILE__) . '/../FRMigrationClass.php';

class Migration20260523141621_CreateReferenceRuleTables extends FRMigrationClass {

    public function process() {
        // ───────────── テーブル作成（親：セクション） ─────────────
        if (!$this->checkTableExists('vtiger_reference_rule_section')) {
            $this->db->pquery(
                "CREATE TABLE vtiger_reference_rule_section (
                    section_id   INT NOT NULL PRIMARY KEY,
                    module_name  VARCHAR(50) NOT NULL,
                    field_name   VARCHAR(50) NOT NULL,
                    rule_type    ENUM('filter','auto_set') NOT NULL,
                    is_enabled   TINYINT(1) NOT NULL DEFAULT 0,
                    updated_at   DATETIME NOT NULL,
                    UNIQUE KEY uq_section (module_name, field_name, rule_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
                array()
            );
            $this->log('vtiger_reference_rule_section テーブルを作成しました');
        }

        if (!$this->checkTableExists('vtiger_reference_rule_section_seq')) {
            $this->db->pquery(
                "CREATE TABLE vtiger_reference_rule_section_seq (
                    id INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
                array()
            );
            $this->db->pquery("INSERT INTO vtiger_reference_rule_section_seq (id) VALUES (0)", array());
            $this->log('vtiger_reference_rule_section_seq テーブルを作成しました');
        }

        // ───────────── テーブル作成（子：ルール） ─────────────
        if (!$this->checkTableExists('vtiger_reference_rule')) {
            $this->db->pquery(
                "CREATE TABLE vtiger_reference_rule (
                    rule_id      INT NOT NULL PRIMARY KEY,
                    section_id   INT NOT NULL,
                    srcfield     VARCHAR(50) NOT NULL,
                    targetfield  VARCHAR(50) NOT NULL,
                    targetmodule VARCHAR(50) NOT NULL,
                    sequence     INT NOT NULL DEFAULT 0,
                    created_at   DATETIME NOT NULL,
                    updated_at   DATETIME NOT NULL,
                    KEY idx_rule_section (section_id),
                    CONSTRAINT fk_reference_rule_section
                        FOREIGN KEY (section_id) REFERENCES vtiger_reference_rule_section(section_id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
                array()
            );
            $this->log('vtiger_reference_rule テーブルを作成しました');
        }

        if (!$this->checkTableExists('vtiger_reference_rule_seq')) {
            $this->db->pquery(
                "CREATE TABLE vtiger_reference_rule_seq (
                    id INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3",
                array()
            );
            $this->db->pquery("INSERT INTO vtiger_reference_rule_seq (id) VALUES (0)", array());
            $this->log('vtiger_reference_rule_seq テーブルを作成しました');
        }
    }
}

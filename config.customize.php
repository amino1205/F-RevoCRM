<?php
// PDF
$is_headlesschrome = false;// trueの場合：headless chromeを使用。falseの場合：TCPDFを使用。
$chromeurl = "http://localhost:30080/converthtmltopdf.php";// headlless chromeの場所またはコマンド
#$chromeurl = "google-chrome";// headlless chromeの場所（Linux）
#$chromeurl = "\"C:\Program Files\Google\Chrome\Application\chrome.exe\"";// headlless chromeの場所（Windows）
$hostfiledirectory = "/var/www/html2pdf/";//PDF作成場所（Linux）
#$hostfiledirectory = "D:/Applications/F-RevoCRM/crm/test/pdf/";//PDF作成場所（Windows）
$dokerfiledirectory = "/html2pdf/";//コマンド実行の場合はコメントアウトする
$show_subordinate_roles_list = true;// trueの場合：共有リスト欄に下位の役割が作成した全てのリストを表示。


// 関連項目検索時のフィルター
global $customizeconfig;
$customizeconfig['edit_reference_filter'] = array(
    /**
     * array(    'module'=>'編集するモジュール',    'field' => '編集するフィールド',
     *     'param'=> array(
     *         array('srcfield'=>'検索に使用する元となるフィールド', 'targetfield'=>'検索時の使用先となるフィールド', 'targetmodule'=>'対象が関連項目の場合、対象のモジュールそれ以外は空'),
     *     ),
     * ),
     */
    // 案件：顧客担当者
    array('module'=>'Potentials', 'field' => 'contact_id',
        'param'=> array(
            array('srcfield'=>'related_to', 'targetfield'=>'account_id', 'targetmodule'=>'Accounts', 'settargetfield' => 'label'),
        ),
    ),
    // 見積：顧客担当者
    array('module'=>'Quotes', 'field' => 'contact_id',
        'param'=> array(
            array('srcfield'=>'account_id', 'targetfield'=>'account_id', 'targetmodule'=>'Accounts', 'settargetfield' => 'label'),
        ),
    ),
    // 見積：案件
    array('module'=>'Quotes', 'field' => 'potential_id',
        'param'=> array(
            array('srcfield'=>'account_id', 'targetfield'=>'related_to', 'targetmodule'=>'Accounts', 'settargetfield' => 'label'),
            array('srcfield'=>'contact_id', 'targetfield'=>'contact_id', 'targetmodule'=>'Contacts', 'settargetfield' => 'label'),
        ),
    ),
    // 請求：顧客担当者
    array('module'=>'Invoice', 'field' => 'contact_id',
        'param'=> array(
            array('srcfield'=>'account_id', 'targetfield'=>'account_id', 'targetmodule'=>'Accounts', 'settargetfield' => 'label'),
        ),
    ),
    // 請求：案件
    array('module'=>'Invoice', 'field' => 'potential_id',
        'param'=> array(
            array('srcfield'=>'account_id', 'targetfield'=>'related_to', 'targetmodule'=>'Accounts', 'settargetfield' => 'label'),
            array('srcfield'=>'contact_id', 'targetfield'=>'contact_id', 'targetmodule'=>'Contacts', 'settargetfield' => 'label'),
        ),
    ),
);
// 関連項目選択時の自動セット
$customizeconfig['edit_reference_auto_set'] = array(
    /**
     * array(    'module'=>'編集するモジュール',    'field' => '編集するフィールド',
     *     'param'=> array(
     *         array('srcfield'=>'検索に使用する元となるフィールド', 'targetfield'=>'検索時の使用先となるフィールド', 'targetmodule'=>'対象が関連項目の場合、対象のモジュールそれ以外は空'),
     *     ),
     * ),
     */
    // 案件：顧客担当者
    array('module'=>'Potentials', 'field' => 'contact_id',
        'param'=> array(
            array('srcfield'=>'related_to', 'targetfield'=>'account_id', 'targetmodule'=>'Accounts', 'settargetfield' => 'label'),
        ),
    ),
    // 見積：案件
    array('module'=>'Quotes', 'field' => 'potential_id',
        'param'=> array(
            array('srcfield'=>'account_id', 'targetfield'=>'related_to', 'targetmodule'=>'Accounts', 'settargetfield' => 'label'),
            array('srcfield'=>'contact_id', 'targetfield'=>'contact_id', 'targetmodule'=>'Contacts', 'settargetfield' => 'label'),
        ),
    ),
);
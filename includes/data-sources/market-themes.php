<?php
/**
 * WP Stocks Manager — テーマ・基準指数の定義（相場スコア用）
 * 2026-10-11 追加。ここに載せた系列は、指数の履歴（wp_stock_index_history）に日次で保存される。
 * 第1段階：基準になる系列の履歴を貯め、期間別騰落率で確認できるようにする。
 * 銘柄へのテーマのタグ付けと相場スコアへの反映は、このあとの段階で追加する。
 *
 * ※シンボルはYahoo!ファイナンス形式。日本のETFは「コード.T」。取得できないシンボルは
 *   マーケット情報「指数」タブの履歴状況表に件数0で出るので、取得ログで理由を確認する。
 */

if (!defined('ABSPATH')) exit;

// グループ別：[グループ名 => [['label' => .., 'symbol' => ..], ...]]
function wp_stocks_theme_reference_groups() {
    return [
        '基準指数' => [
            ['label' => 'TOPIX（連動ETF 1306）', 'symbol' => '1306.T'],
        ],
        'テーマETF（日本）' => [
            ['label' => '防衛テック・日本株式（513A）',      'symbol' => '513A.T'],
            ['label' => '防衛テック・世界（466A）',          'symbol' => '466A.T'],
            ['label' => 'ゴールド（SPDRゴールド 1326）',     'symbol' => '1326.T'],
            ['label' => '高配当（日経高配当50 1489）',       'symbol' => '1489.T'],
        ],
        'テーマETF（米国）' => [
            ['label' => '防衛（ITA）',                       'symbol' => 'ITA'],
            ['label' => 'サイバーセキュリティ（CIBR）',      'symbol' => 'CIBR'],
            ['label' => '再生可能エネルギー（ICLN）',        'symbol' => 'ICLN'],
            ['label' => 'バイオ（XBI）',                     'symbol' => 'XBI'],
            ['label' => 'ウラン・原子力（URA）',             'symbol' => 'URA'],
            ['label' => 'ゴールド（GLD）',                   'symbol' => 'GLD'],
        ],
    ];
}

// 履歴の取得対象に加える用：[symbol => label]
function wp_stocks_theme_reference_symbols() {
    $list = [];
    foreach (wp_stocks_theme_reference_groups() as $items) {
        foreach ($items as $item) {
            $list[$item['symbol']] = $item['label'];
        }
    }
    return $list;
}

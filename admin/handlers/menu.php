<?php
/**
 * WP Stocks Manager — 管理画面メニュー登録
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// 管理画面メニュー
// --------------------------------------------------
add_action('admin_menu', function() {
    add_menu_page('Stocks Manager', 'Stocks', 'manage_options', 'wp-stocks-market', 'wp_stocks_market_info_page', 'dashicons-chart-line', 26);
    add_submenu_page('wp-stocks-market', 'マーケット情報',         'マーケット情報',         'manage_options', 'wp-stocks-market',       'wp_stocks_market_info_page');
    add_submenu_page('wp-stocks-market', 'ダッシュボード',         'ダッシュボード',         'manage_options', 'wp-stocks-dashboard',    'wp_stocks_dashboard_page');
    add_submenu_page('wp-stocks-market', 'ポートフォリオ',         'ポートフォリオ',         'manage_options', 'wp-stocks-portfolio',    'wp_stocks_portfolio_page');
    add_submenu_page('wp-stocks-market', '銘柄管理',               '銘柄管理',               'manage_options', 'wp-stocks-manager',      'wp_stocks_manager_admin_page');
    add_submenu_page('wp-stocks-market', '企業情報',               '企業情報',               'manage_options', 'wp-stocks-company',      'wp_stocks_company_page');
    add_submenu_page('wp-stocks-market', '銘柄比較',               '銘柄比較',               'manage_options', 'wp-stocks-compare',      'wp_stocks_compare_page');
	add_submenu_page('wp-stocks-market', '今日のシグナル一覧', '今日のシグナル一覧', 'manage_options', 'wp-stocks-signal', 'wp_stocks_composite_signal_page');
    add_submenu_page('wp-stocks-market', '決算カレンダー',         '決算カレンダー',         'manage_options', 'wp-stocks-calendar',     'wp_stocks_calendar_page');
    add_submenu_page('wp-stocks-market', '運用メモ',               '運用メモ',               'manage_options', 'wp-stocks-memos',        'wp_stocks_daily_memos_page');
    add_submenu_page('wp-stocks-market', 'インポート/エクスポート','インポート/エクスポート','manage_options', 'wp-stocks-importexport', 'wp_stocks_importexport_page');
    add_submenu_page('wp-stocks-market', 'ログ',                   'ログ',                   'manage_options', 'wp-stocks-logs',         'wp_stocks_logs_page');
    add_submenu_page('wp-stocks-market', '設定',                   '設定',                   'manage_options', 'wp-stocks-settings',     'wp_stocks_settings_page');
    add_submenu_page('wp-stocks-market', '投資信託',               '投資信託',               'manage_options', 'wp-stocks-funds',        'wp_stocks_funds_page');
    add_submenu_page('wp-stocks-market', 'APIデバッグ',            'APIデバッグ',            'manage_options', 'wp-stocks-debug',        'wp_stocks_debug_page');
});

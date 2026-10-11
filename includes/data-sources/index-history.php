<?php
/**
 * WP Stocks Manager — マーケット指数の日次履歴（テーブル・保存・読み出し）
 * 2026-10-11 追加。
 * 取得（Yahoo等）・日次cron・バックフィルボタンは別ステップで追加する。
 * テーブルは WP_STOCKS_VERSION とは独立した専用バージョンで作成する（バージョンの上げ忘れで作られない事故を避けるため）。
 */

if (!defined('ABSPATH')) exit;

define('WP_STOCKS_INDEX_HISTORY_DB_VERSION', '1');

function wp_stocks_index_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'stock_index_history';
}

// --------------------------------------------------
// テーブル作成（専用バージョンが一致していれば何もしない）
// --------------------------------------------------
function wp_stocks_index_history_ensure_table() {
    if (get_option('wp_stocks_index_history_db_version') === WP_STOCKS_INDEX_HISTORY_DB_VERSION) return;

    global $wpdb;
    $table   = wp_stocks_index_history_table();
    $charset = $wpdb->get_charset_collate();

    // dbDelta の書式に合わせる（IF NOT EXISTS は使わない／PRIMARY KEY の後ろは半角スペース2つ）
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        symbol VARCHAR(32) NOT NULL,
        trade_date DATE NOT NULL,
        open_price DOUBLE DEFAULT NULL,
        high_price DOUBLE DEFAULT NULL,
        low_price DOUBLE DEFAULT NULL,
        close_price DOUBLE NOT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'yahoo',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY symbol_date (symbol, trade_date),
        KEY trade_date (trade_date)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // 実際にテーブルができたときだけバージョンを記録（失敗時は次回また試す）
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
        update_option('wp_stocks_index_history_db_version', WP_STOCKS_INDEX_HISTORY_DB_VERSION);
    }
}
add_action('plugins_loaded', 'wp_stocks_index_history_ensure_table');

// --------------------------------------------------
// 保存（同じ symbol × 日付は上書き）
// $rows: [['date' => 'Y-m-d', 'open' => .., 'high' => .., 'low' => .., 'close' => ..], ...]
// 戻り値: 保存に成功した行数
// --------------------------------------------------
function wp_stocks_index_history_upsert($symbol, array $rows, $source = 'yahoo') {
    global $wpdb;
    $table = wp_stocks_index_history_table();

    $num = function ($v) {
        return ($v === null || $v === '') ? 'NULL' : sprintf('%.6F', floatval($v));
    };

    $values = [];
    foreach ($rows as $r) {
        $date = isset($r['date']) ? (string) $r['date'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        if (!isset($r['close']) || $r['close'] === null || $r['close'] === '') continue;

        $values[] = $wpdb->prepare(
            "(%s, %s, " . $num($r['open'] ?? null) . ", " . $num($r['high'] ?? null) . ", "
            . $num($r['low'] ?? null) . ", " . $num($r['close']) . ", %s)",
            $symbol, $date, $source
        );
    }

    $saved = 0;
    foreach (array_chunk($values, 100) as $chunk) {
        $sql = "INSERT INTO {$table} (symbol, trade_date, open_price, high_price, low_price, close_price, source) VALUES "
            . implode(',', $chunk)
            . " ON DUPLICATE KEY UPDATE open_price = VALUES(open_price), high_price = VALUES(high_price),"
            . " low_price = VALUES(low_price), close_price = VALUES(close_price), source = VALUES(source)";
        if ($wpdb->query($sql) !== false) $saved += count($chunk);
    }
    return $saved;
}

// --------------------------------------------------
// 読み出し（日付昇順）。直近 $days 日分
// 戻り値: [['trade_date' => .., 'open_price' => .., 'high_price' => .., 'low_price' => .., 'close_price' => ..], ...]
// --------------------------------------------------
function wp_stocks_index_history_get($symbol, $days = 400) {
    global $wpdb;
    $table = wp_stocks_index_history_table();
    $since = gmdate('Y-m-d', time() - intval($days) * DAY_IN_SECONDS);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT trade_date, open_price, high_price, low_price, close_price FROM {$table}
         WHERE symbol = %s AND trade_date >= %s ORDER BY trade_date ASC",
        $symbol, $since
    ), ARRAY_A);
    return $rows ?: [];
}

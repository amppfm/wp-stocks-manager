<?php
/**
 * WP Stocks Manager — DBテーブル作成・有効化処理
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// DBテーブル作成
// --------------------------------------------------
function wp_stocks_manager_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stocks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(20) NOT NULL,
        name VARCHAR(255) NOT NULL,
        status ENUM('watch','portfolio') DEFAULT 'watch',
        market VARCHAR(100) DEFAULT '',
        sector VARCHAR(100) DEFAULT '',
        industry VARCHAR(100) DEFAULT '',
        website VARCHAR(255) DEFAULT '',
        employees INT DEFAULT 0,
        revenue BIGINT DEFAULT 0,
        net_income BIGINT DEFAULT 0,
        profit_margin FLOAT DEFAULT 0,
        revenue_growth FLOAT DEFAULT 0,
        earnings_growth FLOAT DEFAULT 0,
        equity_ratio FLOAT DEFAULT 0,
        per FLOAT DEFAULT 0,
        forward_per FLOAT DEFAULT 0,
        pbr FLOAT DEFAULT 0,
        eps FLOAT DEFAULT 0,
        forward_eps FLOAT DEFAULT 0,
        roe FLOAT DEFAULT 0,
        roa FLOAT DEFAULT 0,
        peg FLOAT DEFAULT 0,
        dividend_yield FLOAT DEFAULT 0,
        market_cap BIGINT DEFAULT 0,
        ipo_year VARCHAR(20) DEFAULT '',
        currency VARCHAR(10) DEFAULT 'JPY',
        theme_tags TEXT,
        earnings_date DATE DEFAULT NULL,
        purchase_price FLOAT DEFAULT 0,
        purchase_qty INT DEFAULT 0,
        info_updated_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_prices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id BIGINT UNSIGNED NOT NULL,
        price FLOAT,
        previous_close FLOAT DEFAULT NULL,
        volume BIGINT DEFAULT NULL,
        datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX(stock_id)
    ) $charset;";

    $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_news (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id BIGINT UNSIGNED NOT NULL,
        uuid VARCHAR(100) NOT NULL,
        title TEXT NOT NULL,
        publisher VARCHAR(255) DEFAULT '',
        link TEXT NOT NULL,
        thumbnail TEXT DEFAULT '',
        published_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uuid (uuid),
        INDEX(stock_id)
    ) $charset;";

    $sql4 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        level VARCHAR(10) NOT NULL DEFAULT 'info',
        action VARCHAR(50) NOT NULL DEFAULT '',
        symbol VARCHAR(30) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX(created_at)
    ) $charset;";

    $sql5 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_technicals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id BIGINT UNSIGNED NOT NULL,
        trend VARCHAR(10) DEFAULT 'flat',
        trend_strength TINYINT DEFAULT 1,
        ma5 FLOAT DEFAULT NULL,
        ma25 FLOAT DEFAULT NULL,
        ma75 FLOAT DEFAULT NULL,
        macd FLOAT DEFAULT NULL,
        macd_signal FLOAT DEFAULT NULL,
        macd_hist FLOAT DEFAULT NULL,
        rsi FLOAT DEFAULT NULL,
        cross_signal VARCHAR(10) DEFAULT NULL,
        calculated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY stock_id (stock_id)
    ) $charset;";

    // ★追加：複合シグナル判定の履歴テーブル（論点1=B）
    // 前日値との比較（論点2=A）はstock_technicals側にprev_*列を追加して対応する
    $sql_signal_history = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_signal_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id BIGINT UNSIGNED NOT NULL,
        signal_date DATE NOT NULL,
        composite_score INT DEFAULT 0,
        composite_label VARCHAR(20) DEFAULT '',
        composite_detail TEXT,
        flags TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY stock_date (stock_id, signal_date),
        INDEX(signal_date)
    ) $charset;";

    $sql6 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_ai_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id BIGINT UNSIGNED NOT NULL,
        analysis_date DATE NOT NULL,
        raw_data TEXT NOT NULL,
        valuation VARCHAR(10) DEFAULT '',
        financial_health VARCHAR(10) DEFAULT '',
        growth VARCHAR(10) DEFAULT '',
        market_status VARCHAR(20) DEFAULT '',
        risk_factor TEXT DEFAULT '',
        catalyst TEXT DEFAULT '',
        dividend_eval VARCHAR(10) DEFAULT '',
        fair_price VARCHAR(30) DEFAULT '',
        short_outlook TEXT DEFAULT '',
        mid_outlook TEXT DEFAULT '',
        total_score VARCHAR(20) DEFAULT '',
        comment TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX(stock_id),
        INDEX(analysis_date)
    ) $charset;";

    $sql_fin = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_financials (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stock_id BIGINT UNSIGNED NOT NULL,
        fiscal_year VARCHAR(10) NOT NULL,
        period_label VARCHAR(20) DEFAULT '',
        revenue BIGINT DEFAULT NULL,
        operating_profit BIGINT DEFAULT NULL,
        net_income BIGINT DEFAULT NULL,
        equity BIGINT DEFAULT NULL,
        total_assets BIGINT DEFAULT NULL,
        equity_ratio FLOAT DEFAULT NULL,
        eps FLOAT DEFAULT NULL,
        dividend FLOAT DEFAULT NULL,
        doc_id VARCHAR(20) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY stock_year (stock_id, fiscal_year)
    ) $charset;";
    // NOTE: 以前は未定義変数 $charset_collate を使用し、かつ dbDelta() が
    // require_once(...upgrade.php) の前に呼ばれていたため、環境によっては
    // 致命的エラーやcharset未反映の原因になっていた。下のdbDelta一括呼び出しに統合する。

    $sql7 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_funds (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fund_code VARCHAR(20) NOT NULL,
        fund_name VARCHAR(255) NOT NULL,
        fund_units FLOAT DEFAULT 0,
        cost_per_unit FLOAT DEFAULT 0,
        fund_type VARCHAR(20) DEFAULT 'growth',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY fund_code (fund_code)
    ) $charset;";

    $sql8 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_fund_prices (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fund_id BIGINT UNSIGNED NOT NULL,
        price FLOAT NOT NULL,
        price_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY fund_date (fund_id, price_date),
        INDEX(fund_id)
    ) $charset;";
    $sql9 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_daily_memos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        memo_date DATE NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX(memo_date)
    ) $charset;";
    $sql10 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_quarterly_financials (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id BIGINT UNSIGNED NOT NULL,
        period_end DATE NOT NULL,
        revenue BIGINT DEFAULT NULL,
        net_income BIGINT DEFAULT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'yahoo',
        fiscal_year SMALLINT DEFAULT NULL,
        fiscal_quarter TINYINT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY stock_period_source (stock_id, period_end, source)
    ) $charset;";

    $sql_jpx_sector_per = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_jpx_sector_per (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        report_month VARCHAR(7) NOT NULL,
        market_segment VARCHAR(20) NOT NULL,
        sector VARCHAR(50) NOT NULL,
        company_count INT DEFAULT NULL,
        simple_per FLOAT DEFAULT NULL,
        simple_pbr FLOAT DEFAULT NULL,
        weighted_per FLOAT DEFAULT NULL,
        weighted_pbr FLOAT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY ym_market_sector (report_month, market_segment, sector)
	) $charset;";
    $sql_valuation_history = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}stock_valuation_history (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        stock_id BIGINT UNSIGNED NOT NULL,
        record_date DATE NOT NULL,
        price FLOAT DEFAULT NULL,
        actual_eps FLOAT DEFAULT NULL,
        forecast_eps FLOAT DEFAULT NULL,
        bps FLOAT DEFAULT NULL,
        actual_per FLOAT DEFAULT NULL,
        forecast_per FLOAT DEFAULT NULL,
        pbr FLOAT DEFAULT NULL,
        dividend_yield FLOAT DEFAULT NULL,
        eps_source VARCHAR(20) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY stock_date (stock_id, record_date),
        INDEX(stock_id)
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
//    dbDelta($sql1); dbDelta($sql2); dbDelta($sql3); dbDelta($sql4); dbDelta($sql5); dbDelta($sql6); dbDelta($sql_fin); dbDelta($sql7); dbDelta($sql8); dbDelta($sql9); dbDelta($sql10); dbDelta($sql_signal_history); dbDelta($sql_jpx_sector_per);
    dbDelta($sql1); dbDelta($sql2); dbDelta($sql3); dbDelta($sql4); dbDelta($sql5); dbDelta($sql6); dbDelta($sql_fin); dbDelta($sql7); dbDelta($sql8); dbDelta($sql9); dbDelta($sql10); dbDelta($sql_signal_history); dbDelta($sql_jpx_sector_per); dbDelta($sql_valuation_history);
	
	// dbDelta()は "CREATE TABLE IF NOT EXISTS" の書き方だとテーブル名の抽出に失敗し
    // （正規表現が最初の1単語である"IF"をテーブル名と誤認識する既知の不具合）、
    // 既存テーブルへのカラム追加が反映されないため、ここだけ明示的にALTER TABLEで追加する。
    $wsm_qf_columns = $wpdb->get_col("DESC {$wpdb->prefix}stock_quarterly_financials", 0);
    if (!in_array('fiscal_year', $wsm_qf_columns, true)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}stock_quarterly_financials ADD COLUMN fiscal_year SMALLINT DEFAULT NULL AFTER source");
    }
    if (!in_array('fiscal_quarter', $wsm_qf_columns, true)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}stock_quarterly_financials ADD COLUMN fiscal_quarter TINYINT DEFAULT NULL AFTER fiscal_year");
    }

    // 既存テーブルへのカラム追加
    $columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}stocks", 0);
    $new_cols = [
        'status'          => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN status ENUM('watch','portfolio') DEFAULT 'watch' AFTER name",
        'earnings_date'   => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN earnings_date DATE DEFAULT NULL AFTER theme_tags",
        'purchase_price'  => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN purchase_price FLOAT DEFAULT 0 AFTER earnings_date",
        'purchase_qty'    => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN purchase_qty INT DEFAULT 0 AFTER purchase_price",
        'currency'        => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN currency VARCHAR(10) DEFAULT 'JPY' AFTER ipo_year",
        'is_watchlist'    => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN is_watchlist TINYINT(1) DEFAULT 0 AFTER currency",
        'edinet_code'     => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN edinet_code VARCHAR(20) DEFAULT '' AFTER is_watchlist",
        'memo'            => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN memo TEXT AFTER purchase_qty",
        'shikiho'         => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN shikiho LONGTEXT AFTER memo",
        'shikiho_updated_at' => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN shikiho_updated_at DATETIME DEFAULT NULL AFTER shikiho",
        'ai_analysis'     => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN ai_analysis LONGTEXT AFTER shikiho_updated_at",
        'ai_analysis_updated_at' => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN ai_analysis_updated_at DATETIME DEFAULT NULL AFTER ai_analysis",
        'peg_trailing'    => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN peg_trailing FLOAT DEFAULT 0 AFTER peg",
        'screen_op_profit'  => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN screen_op_profit TINYINT(1) DEFAULT 0 AFTER market",
        'screen_net_income' => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN screen_net_income TINYINT(1) DEFAULT 0 AFTER screen_op_profit",
        'screen_shikiho'    => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN screen_shikiho TINYINT(1) DEFAULT 0 AFTER screen_net_income",
        'is_sector_etf'     => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN is_sector_etf TINYINT(1) DEFAULT 0 AFTER sector",
        'sector_override'   => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN sector_override VARCHAR(50) DEFAULT '' AFTER sector",
        'sector_override'   => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN sector_override VARCHAR(50) DEFAULT '' AFTER sector",
        'jquants_eps'                      => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_eps FLOAT DEFAULT NULL AFTER sector_override",
        'jquants_forecast_eps'             => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_forecast_eps FLOAT DEFAULT NULL AFTER jquants_eps",
        'jquants_next_fy_forecast_eps'     => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_next_fy_forecast_eps FLOAT DEFAULT NULL AFTER jquants_forecast_eps",
        'jquants_bps'                      => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_bps FLOAT DEFAULT NULL AFTER jquants_next_fy_forecast_eps",
        'jquants_equity_ratio'             => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_equity_ratio FLOAT DEFAULT NULL AFTER jquants_bps",
        'jquants_roe'                      => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_roe FLOAT DEFAULT NULL AFTER jquants_equity_ratio",
        'jquants_forecast_dividend_annual' => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_forecast_dividend_annual FLOAT DEFAULT NULL AFTER jquants_roe",
        'jquants_industry_ja'              => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_industry_ja VARCHAR(100) DEFAULT '' AFTER jquants_forecast_dividend_annual",
        // ★追加：J-Quants equities/master（上場銘柄一覧）由来の33業種・市場区分
        'jquants_sector33_code'            => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_sector33_code VARCHAR(10) DEFAULT '' AFTER jquants_industry_ja",
        'jquants_sector33_name'            => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_sector33_name VARCHAR(100) DEFAULT '' AFTER jquants_sector33_code",
        'jquants_market_code'              => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_market_code VARCHAR(10) DEFAULT '' AFTER jquants_sector33_name",
        'jquants_market_name'              => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_market_name VARCHAR(50) DEFAULT '' AFTER jquants_market_code",
        'jquants_updated_at'               => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN jquants_updated_at DATETIME DEFAULT NULL AFTER jquants_market_name",
        'manual_forecast_eps'              => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN manual_forecast_eps FLOAT DEFAULT 0 AFTER jquants_updated_at",
        'manual_next_fy_forecast_eps'      => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN manual_next_fy_forecast_eps FLOAT DEFAULT 0 AFTER manual_forecast_eps",
        // ★追加：J-Quantsで市場区分を判定できない銘柄（TOKYO PRO Market等）向けの手動指定
        // 「market」列自体はJ-Quants由来の自動判定値と共用のため、J-Quants値がnullの場合のみ
        // このmanual_market_nameがmarketへ反映される（wp_stocks_jquants_sync_stock()側で判定）
        'manual_market_name'               => "ALTER TABLE {$wpdb->prefix}stocks ADD COLUMN manual_market_name VARCHAR(30) DEFAULT '' AFTER manual_next_fy_forecast_eps",
    ];

    foreach ($new_cols as $col => $sql) {
        if (!in_array($col, $columns)) $wpdb->query($sql);
    }

    // ★追加：TOPIX-17セクターETFのマーカーを sector='TOPIX-17' 文字列から is_sector_etf 列へ移行（一度だけ実行）
    if (!get_option('wp_stocks_topix17_migrated')) {
        $wpdb->query("UPDATE {$wpdb->prefix}stocks SET is_sector_etf = 1, sector = '' WHERE sector = 'TOPIX-17'");
        update_option('wp_stocks_topix17_migrated', 1);
    }

    // ★追加：TOPIX-17セクターETF銘柄（証券コード1617〜1633）を is_sector_etf=1 として明示的にフラグ付け
    // （sector列は文字列マーカーとしては使われておらず、実際の業種名リストが入っていたため、コード指定に変更。
    //  sector列の中身（業種名リスト）は表示用として残す）
    if (!get_option('wp_stocks_topix17_code_migrated')) {
        $topix17_codes = ['1617','1618','1619','1620','1621','1622','1623','1624','1625','1626','1627','1628','1629','1630','1631','1632','1633'];
        $placeholders  = implode(',', array_fill(0, count($topix17_codes), '%s'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}stocks SET is_sector_etf = 1 WHERE code IN ($placeholders)",
            ...$topix17_codes
        ));
        update_option('wp_stocks_topix17_code_migrated', 1);
    }

    $tech_cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}stock_technicals", 0);
    if (!in_array('ma75', $tech_cols)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN ma75 FLOAT DEFAULT NULL AFTER ma25");
    }
    foreach ([
        'rci'      => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN rci FLOAT DEFAULT NULL AFTER cross_signal",
        'bb_upper' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN bb_upper FLOAT DEFAULT NULL AFTER rci",
        'bb_mid'   => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN bb_mid FLOAT DEFAULT NULL AFTER bb_upper",
        'bb_lower' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN bb_lower FLOAT DEFAULT NULL AFTER bb_mid",
        'plus_di'  => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN plus_di FLOAT DEFAULT NULL AFTER bb_lower",
        'minus_di' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN minus_di FLOAT DEFAULT NULL AFTER plus_di",
        'adx'      => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN adx FLOAT DEFAULT NULL AFTER minus_di",
        'candle_pattern' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN candle_pattern VARCHAR(24) DEFAULT NULL AFTER adx",
        'stoch_k'      => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN stoch_k FLOAT DEFAULT NULL AFTER candle_pattern",
        'stoch_d'      => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN stoch_d FLOAT DEFAULT NULL AFTER stoch_k",
        'sar'          => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN sar FLOAT DEFAULT NULL AFTER stoch_d",
        'sar_trend'    => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN sar_trend VARCHAR(10) DEFAULT NULL AFTER sar",
        'sar_reversal' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN sar_reversal TINYINT(1) DEFAULT 0 AFTER sar_trend",
        'fib_level'    => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN fib_level VARCHAR(10) DEFAULT NULL AFTER sar_reversal",
        'fib_near'     => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN fib_near TINYINT(1) DEFAULT 0 AFTER fib_level",
        // ★追加：複合シグナル判定用（前日値・スコア・ラベル・詳細）
        'prev_macd'        => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN prev_macd FLOAT DEFAULT NULL AFTER fib_near",
        'prev_macd_signal' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN prev_macd_signal FLOAT DEFAULT NULL AFTER prev_macd",
        'prev_rci'         => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN prev_rci FLOAT DEFAULT NULL AFTER prev_macd_signal",
        'prev_stoch_k'     => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN prev_stoch_k FLOAT DEFAULT NULL AFTER prev_rci",
        'prev_stoch_d'     => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN prev_stoch_d FLOAT DEFAULT NULL AFTER prev_stoch_k",
        'composite_score'  => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN composite_score INT DEFAULT 0 AFTER prev_stoch_d",
        'composite_label'  => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN composite_label VARCHAR(20) DEFAULT '' AFTER composite_score",
        'composite_detail' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN composite_detail TEXT AFTER composite_label",
        // ★追加：打診買い/売り（ボリンジャーσタッチ反転）・バンドウォーク・オシレーター反転確認
        'tasin_buy'                => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN tasin_buy TINYINT(1) DEFAULT 0 AFTER composite_detail",
        'tasin_sell'               => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN tasin_sell TINYINT(1) DEFAULT 0 AFTER tasin_buy",
        'bandwalk_detected'        => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN bandwalk_detected TINYINT(1) DEFAULT 0 AFTER tasin_sell",
        'bandwalk_direction'       => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN bandwalk_direction VARCHAR(10) DEFAULT NULL AFTER bandwalk_detected",
        'oscillator_reversal_buy'  => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN oscillator_reversal_buy TINYINT(1) DEFAULT 0 AFTER bandwalk_direction",
        'oscillator_reversal_sell' => "ALTER TABLE {$wpdb->prefix}stock_technicals ADD COLUMN oscillator_reversal_sell TINYINT(1) DEFAULT 0 AFTER oscillator_reversal_buy",
    ] as $col => $sql) {
        if (!in_array($col, $tech_cols)) $wpdb->query($sql);
    }

    $price_cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}stock_prices", 0);
    if (!in_array('previous_close', $price_cols))
        $wpdb->query("ALTER TABLE {$wpdb->prefix}stock_prices ADD COLUMN previous_close FLOAT DEFAULT NULL AFTER price");
    if (!in_array('volume', $price_cols))
        $wpdb->query("ALTER TABLE {$wpdb->prefix}stock_prices ADD COLUMN volume BIGINT DEFAULT NULL AFTER previous_close");

    update_option('wp_stocks_db_version', WP_STOCKS_VERSION);
}

// --------------------------------------------------
// プラグイン有効化・無効化
// --------------------------------------------------
register_activation_hook(WP_STOCKS_PLUGIN_FILE, 'wp_stocks_manager_activate');
function wp_stocks_manager_activate() {
    wp_stocks_manager_create_tables();
    $fetch_time = get_option('wp_stocks_fetch_time', '15:30');
    list($h, $m) = explode(':', $fetch_time);
    if (!wp_next_scheduled('wp_stocks_cron_event')) {
        $now  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next = new DateTime("today {$h}:{$m}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now >= $next) $next->modify('+1 day');
        wp_schedule_event($next->getTimestamp(), 'daily', 'wp_stocks_cron_event');
    }
    if (!wp_next_scheduled('wp_stocks_news_cron')) {
        $next_news = new DateTime('today 09:00:00', new DateTimeZone('Asia/Tokyo'));
        $now2      = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        if ($now2 >= $next_news) $next_news->modify('+12 hours');
        wp_schedule_event($next_news->getTimestamp(), 'twice_daily_news', 'wp_stocks_news_cron');
    }
    if (!wp_next_scheduled('wp_stocks_technical_cron')) {
        $technical_time_activate = get_option('wp_stocks_technical_time', '21:00');
        if (!preg_match('/^\d{2}:\d{2}$/', $technical_time_activate)) $technical_time_activate = '21:00';
        list($th3, $tm3) = explode(':', $technical_time_activate);
        $now3  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next3 = new DateTime("today {$th3}:{$tm3}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now3 >= $next3) $next3->modify('+1 day');
        wp_schedule_event($next3->getTimestamp(), 'daily', 'wp_stocks_technical_cron');
    }
    if (!wp_next_scheduled('wp_stocks_log_cleanup')) {
        wp_schedule_event(time(), 'daily', 'wp_stocks_log_cleanup');
    }
    if (!wp_next_scheduled('wp_stocks_price_cleanup')) {
        // 株価クリーンアップ: 毎日03:00
        $now4  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next4 = new DateTime('today 03:00:00', new DateTimeZone('Asia/Tokyo'));
        if ($now4 >= $next4) $next4->modify('+1 day');
        wp_schedule_event($next4->getTimestamp(), 'daily', 'wp_stocks_price_cleanup');
    }
    if (!wp_next_scheduled('wp_stocks_company_cron')) {
        $now5  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next5 = new DateTime('next Sunday 22:00:00', new DateTimeZone('Asia/Tokyo'));
        wp_schedule_event($next5->getTimestamp(), 'weekly', 'wp_stocks_company_cron');
    }
    if (!wp_next_scheduled('wp_stocks_us_cron_event')) {
        $us_time = get_option('wp_stocks_us_fetch_time', '06:30');
        list($uh, $um) = explode(':', $us_time);
        $now_u  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next_u = new DateTime("today {$uh}:{$um}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now_u >= $next_u) $next_u->modify('+1 day');
        wp_schedule_event($next_u->getTimestamp(), 'daily', 'wp_stocks_us_cron_event');
    }
    if (!wp_next_scheduled('wp_stocks_us_technical_cron')) {
        $us_tech_time = get_option('wp_stocks_us_technical_time', '07:30');
        list($uth, $utm) = explode(':', $us_tech_time);
        $now_ut  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next_ut = new DateTime("today {$uth}:{$utm}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now_ut >= $next_ut) $next_ut->modify('+1 day');
        wp_schedule_event($next_ut->getTimestamp(), 'daily', 'wp_stocks_us_technical_cron');
    }
    if (!wp_next_scheduled('wp_stocks_fund_price_cron')) {
        $now6  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next6 = new DateTime('today 16:00:00', new DateTimeZone('Asia/Tokyo'));
        if ($now6 >= $next6) $next6->modify('+1 day');
        wp_schedule_event($next6->getTimestamp(), 'daily', 'wp_stocks_fund_price_cron');
    }
    if (!wp_next_scheduled('wp_stocks_jpx_sector_per_cron')) {
        // JPXの公表が毎月第1営業日13時以降のため、月初2日目の14時に実行
        $next7 = new DateTime('first day of next month 14:00:00', new DateTimeZone('Asia/Tokyo'));
        wp_schedule_event($next7->getTimestamp(), 'monthly', 'wp_stocks_jpx_sector_per_cron');
    }
}

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wp_stocks_cron_event');
    wp_clear_scheduled_hook('wp_stocks_news_cron');
    wp_clear_scheduled_hook('wp_stocks_technical_cron');
    wp_clear_scheduled_hook('wp_stocks_log_cleanup');
    wp_clear_scheduled_hook('wp_stocks_price_cleanup');
    wp_clear_scheduled_hook('wp_stocks_company_cron');
    wp_clear_scheduled_hook('wp_stocks_us_cron_event');
    wp_clear_scheduled_hook('wp_stocks_us_technical_cron');
    wp_clear_scheduled_hook('wp_stocks_fund_price_cron');
    wp_clear_scheduled_hook('wp_stocks_jpx_sector_per_cron');
});

add_action('plugins_loaded', function() {
    if (get_option('wp_stocks_db_version') !== WP_STOCKS_VERSION) {
        wp_stocks_manager_create_tables();
    }
});

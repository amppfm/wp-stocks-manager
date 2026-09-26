<?php
/**
 * WP Stocks Manager — Cronスケジュール・イベント登録（※末尾にadmin_post_delete_ai_historyハンドラーを1つ含む）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// Cronスケジュール登録
// --------------------------------------------------
add_filter('cron_schedules', function($schedules) {
    $schedules['every_hour']       = ['interval' => 3600,  'display' => 'Every Hour'];
    $schedules['twice_daily_news'] = ['interval' => 43200, 'display' => 'Twice Daily'];
    $schedules['weekly']           = ['interval' => 604800, 'display' => 'Weekly'];
    $schedules['monthly']          = ['interval' => 2678400, 'display' => 'Monthly'];
    return $schedules;
});

// --------------------------------------------------
// JPX業種別PER 月次自動取り込み Cron
// --------------------------------------------------
add_action('wp_stocks_jpx_sector_per_cron', function() {
    wp_stocks_jpx_sync_sector_per();
});

// --------------------------------------------------
// 株価自動取得 Cron（平日15:30・Yahoo!ブロック対策でsleep付き）
// --------------------------------------------------
add_action('wp_stocks_cron_event', function() {
    global $wpdb;
    $now  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    $wday = (int)$now->format('w');
    if ($wday === 0 || $wday === 6) return;

    $holidays = wp_stocks_get_holidays();
    if (in_array($now->format('Y-m-d'), $holidays, true)) return;

    // 米国株は専用のwp_stocks_us_cron_eventが正しい時間帯（米国市場クローズ後）に
    // 取得するため、ここで重複取得すると市場が閉まっている時間帯のデータで
    // 正しい前日比を上書きしてしまう。日本株のみを対象にする。
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE (currency IS NULL OR currency != 'USD')");
    $count  = 0;
    foreach ($stocks as $s) {
        $sym = ($s->currency ?? 'JPY') === 'USD' ? $s->code : $s->code . '.T';
        wp_stocks_save_price($s->id, $sym);
        $count++;
        // Yahoo!ブロック対策: ランダムsleep
        sleep(rand(3, 8));
    }
    wp_stocks_log('info', 'cron_price', 'ALL', $count . '銘柄の株価を取得しました');
});

// --------------------------------------------------
// テクニカル指標自動計算 Cron（毎日21:00）
// 1mo日足データ取得→MA5/MA25/MACD/RSI/トレンド計算→DB保存
// Yahoo!ブロック対策: 銘柄間にsleep
// --------------------------------------------------
add_action('wp_stocks_technical_cron', function() {
    global $wpdb;
    // 日本株のみ
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE (currency = 'JPY' OR currency IS NULL OR currency = '')");
    $ok = $ng = 0;
    foreach ($stocks as $s) {
        $result = wp_stocks_save_technicals($s->id, $s->code . '.T');
        if ($result) $ok++; else $ng++;
        sleep(rand(1, 2));
    }
    wp_stocks_log('info', 'technical_cron', 'JP', "日本株テクニカル計算完了: 成功{$ok}件 / 失敗{$ng}件");
});

// --------------------------------------------------
// 米国株テクニカル指標自動計算 Cron
// --------------------------------------------------
add_action('wp_stocks_us_technical_cron', function() {
    global $wpdb;
    // 米国株のみ
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE currency = 'USD'");
    $ok = $ng = 0;
    foreach ($stocks as $s) {
        $result = wp_stocks_save_technicals($s->id, $s->code);
        if ($result) $ok++; else $ng++;
        sleep(rand(1, 2));
    }
    wp_stocks_log('info', 'us_technical_cron', 'US', "米国株テクニカル計算完了: 成功{$ok}件 / 失敗{$ng}件");
});

// --------------------------------------------------
// 適時開示自動取得 Cron（1日2回）
// --------------------------------------------------
add_action('wp_stocks_news_cron', function() {
    global $wpdb;
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks");
    $total  = 0;
    foreach ($stocks as $s) {
        $sym_news = ($s->currency ?? 'JPY') === 'USD' ? $s->code : $s->code . '.T';
        $saved = wp_stocks_fetch_news($s->id, $sym_news, $s->name);
        $total += $saved;
        usleep(300000); // 0.3秒
    }
    if ($total > 0) {
        wp_stocks_log('info', 'news_cron', 'ALL', "適時開示 {$total}件を保存しました");
    }
});

// --------------------------------------------------
// ログ自動削除 Cron（30日）
// --------------------------------------------------
add_action('wp_stocks_log_cleanup', function() {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}stock_logs WHERE created_at < %s",
        date('Y-m-d H:i:s', strtotime('-' . WP_STOCKS_LOG_DAYS . ' days'))
    ));
});

// --------------------------------------------------
// 株価データ自動クリーンアップ Cron（1年超を削除・毎日03:00）
// --------------------------------------------------
add_action('wp_stocks_price_cleanup', function() {
    global $wpdb;
    $cutoff = date('Y-m-d H:i:s', strtotime('-1 year'));
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}stock_prices WHERE datetime < %s", $cutoff
    ));
    if ($deleted > 0) {
        wp_stocks_log('info', 'price_cleanup', 'ALL', "1年超の株価データ {$deleted}件を削除しました");
    }
});

// --------------------------------------------------
// AI分析履歴削除（admin-post）
// --------------------------------------------------
add_action('admin_post_delete_ai_history', function() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    $id       = intval($_GET['id'] ?? 0);
    $stock_id = intval($_GET['stock_id'] ?? 0);
    if (!$id || !check_admin_referer('wp_stocks_delete_ai_' . $id)) wp_die('不正なリクエスト');
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'stock_ai_history', ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&message=ai_deleted'));
    exit;
});
// --------------------------------------------------
// 米国株 株価自動取得 Cron（毎日06:30 ※NY市場終了後）
// --------------------------------------------------
add_action('wp_stocks_us_cron_event', function() {
    // ★変更：Yahoo Financeへの個別リクエスト(sleep付きループ)から、
    // Webullのバッチ取得(curl_multi並列)に置き換え。
    // 取得できなかった銘柄は wp_stocks_us_technical_cron 実行時に自動でYahooへフォールバックする。
    $result = wp_stocks_webull_batch_update_us_prices();
    wp_stocks_log('info', 'us_cron_price', 'ALL', 'Webullバッチで米国株株価を取得しました：成功' . $result['ok'] . '件 / 失敗' . $result['ng'] . '件（全' . $result['total'] . '銘柄）');
});

// --------------------------------------------------
// 投資信託 基準価額自動取得 Cron（毎日16:00）
// --------------------------------------------------
add_action('wp_stocks_fund_price_cron', function() {
    global $wpdb;
    $funds = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_funds");
    if (!$funds) return;
    $ok = $ng = 0;
    foreach ($funds as $f) {
        $result = wp_stocks_save_fund_price($f->id, $f->fund_code);
        if ($result) $ok++; else $ng++;
        sleep(1);
    }
    wp_stocks_log('info', 'fund_price_cron', 'ALL', "基準価額更新完了：成功{$ok}件 / 失敗{$ng}件");
});

// --------------------------------------------------
// 企業情報週次自動更新 Cron（日曜22:00・負荷対策付き）
// --------------------------------------------------
add_action('wp_stocks_company_cron', function() {
    global $wpdb;
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks ORDER BY id ASC");
    $ok = $ng = 0;
    $edgar_ok = $edgar_ng = 0;
    $jquants_ok = $jquants_ng = 0;

    foreach ($stocks as $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        $sym = $is_usd ? $s->code : $s->code . '.T';
        $result = wp_stocks_save_company_info($s->id, $sym);
        if ($result) $ok++; else $ng++;

        // 米国株はついでにSEC EDGARの四半期財務データも週次で取得する
        if ($is_usd) {
            $edgar_result = wp_stocks_fetch_quarterly_financials_edgar($s->id, $s->code);
            if ($edgar_result) $edgar_ok++; else $edgar_ng++;
        } else {
            // 日本株はついでにJ-Quantsの予想EPS等も週次で取得する
            // ★2026-09-26修正：sector列は他の処理で書き換わり得て不安定なため、
            // TOPIX-17指数連動ETFの判定はwp_stocks_get_topix17_sector_map()の固定コード一覧を使う。
            if (!array_key_exists($s->code, wp_stocks_get_topix17_sector_map())) {
                $jquants_result = wp_stocks_jquants_sync_stock($s->id, $s->code);
                if ($jquants_result) $jquants_ok++; else $jquants_ng++;
            }
        }

        // 負荷対策：3件ごとに5秒sleep、それ以外は2秒
        if (($ok + $ng) % 3 === 0) sleep(5);
        else sleep(2);
    }

    $msg = sprintf(
        '企業情報週次更新完了：成功%d件 / 失敗%d件（うち米国株EDGAR財務：成功%d件 / 失敗%d件、日本株J-Quants：成功%d件 / 失敗%d件）',
        $ok, $ng, $edgar_ok, $edgar_ng, $jquants_ok, $jquants_ng
    );
    wp_stocks_log('info', 'company_cron', 'ALL', $msg);
});

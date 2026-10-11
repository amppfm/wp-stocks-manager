<?php
/**
 * WP Stocks Manager — マーケット指数の日次履歴：取得・バックフィル・日次cron・状況表示
 * 2026-10-11 追加。保存先は index-history.php のテーブル（wp_stock_index_history）。
 * - 取得元はYahoo chart API（指数＋テーマ・基準指数ETF）。日次cronは直近3ヶ月、バックフィルは2年分を取る。
 * - cronは本ファイル内で自己登録する（db.php の有効化フックに依存しない）。
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// 取得対象の指数（マーケット情報ページの定義を流用）：[symbol => label]
// --------------------------------------------------
function wp_stocks_index_history_symbols() {
    $list = [];
    foreach (wp_stocks_get_market_index_groups() as $items) {
        foreach ($items as $item) {
            $list[$item['symbol']] = $item['label'];
        }
    }
    // テーマ・基準指数の系列（market-themes.php）も履歴の取得対象に含める
    if (function_exists('wp_stocks_theme_reference_symbols')) {
        foreach (wp_stocks_theme_reference_symbols() as $symbol => $label) {
            $list[$symbol] = $label;
        }
    }
    return $list;
}

// --------------------------------------------------
// Yahoo chart API から日足を取得。成功: [['date','open','high','low','close'], ...] / 失敗: false
// $range: 例 '3mo' / '2y'
// --------------------------------------------------
function wp_stocks_fetch_index_history_rows($symbol, $range = '2y') {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=1d&range=' . rawurlencode($range);
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'index_history', $symbol, '通信エラー: ' . $response->get_error_message());
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        wp_stocks_log('error', 'index_history', $symbol, 'HTTPエラー: ' . $code);
        return false;
    }

    $body   = json_decode(wp_remote_retrieve_body($response), true);
    $result = $body['chart']['result'][0] ?? null;
    if (!$result || empty($result['timestamp'])) {
        wp_stocks_log('error', 'index_history', $symbol, '日足データが空です（range=' . $range . '）');
        return false;
    }

    $offset = intval($result['meta']['gmtoffset'] ?? 0);   // 取引所のローカル日付に直すため
    $q      = $result['indicators']['quote'][0] ?? [];
    $rows   = [];
    foreach ($result['timestamp'] as $i => $ts) {
        $close = $q['close'][$i] ?? null;
        if ($close === null) continue;
        $rows[] = [
            'date'  => gmdate('Y-m-d', intval($ts) + $offset),
            'open'  => $q['open'][$i]  ?? null,
            'high'  => $q['high'][$i]  ?? null,
            'low'   => $q['low'][$i]   ?? null,
            'close' => $close,
        ];
    }
    if (!$rows) {
        wp_stocks_log('error', 'index_history', $symbol, '有効な終値が0件でした（range=' . $range . '）');
        return false;
    }
    return $rows;
}

// 1銘柄分を取得して保存。戻り値: 保存した行数 / 失敗時 false
function wp_stocks_index_history_fetch_and_save($symbol, $range = '2y') {
    $rows = wp_stocks_fetch_index_history_rows($symbol, $range);
    if ($rows === false) return false;
    return wp_stocks_index_history_upsert($symbol, $rows, 'yahoo');
}

// 指定銘柄の保存済み件数
function wp_stocks_index_history_count($symbol) {
    global $wpdb;
    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . wp_stocks_index_history_table() . " WHERE symbol = %s", $symbol
    )));
}

// --------------------------------------------------
// 全指数を順に処理（日次cron用）。件数が少ない指数は自動で2年分を取り直す。
// 戻り値: ['ok' => 成功数, 'fail' => 失敗数]
// --------------------------------------------------
function wp_stocks_index_history_run_all() {
    wp_stocks_index_history_ensure_table();
    $ok = 0; $fail = 0;
    foreach (array_keys(wp_stocks_index_history_symbols()) as $symbol) {
        $range = wp_stocks_index_history_count($symbol) < 200 ? '2y' : '3mo';
        $saved = wp_stocks_index_history_fetch_and_save($symbol, $range);
        if ($saved === false) $fail++; else $ok++;
        usleep(300000);
    }
    wp_stocks_log($fail ? 'error' : 'info', 'index_history', 'ALL', "指数履歴の日次更新：成功 {$ok} / 失敗 {$fail}");
    return ['ok' => $ok, 'fail' => $fail];
}

// --------------------------------------------------
// 日次cron（毎朝7:00 JST。米国引け後）。自己登録・自己解除
// --------------------------------------------------
add_action('init', function () {
    if (wp_next_scheduled('wp_stocks_index_history_cron')) return;
    $tz   = new DateTimeZone('Asia/Tokyo');
    $now  = new DateTime('now', $tz);
    $next = new DateTime('today 07:00:00', $tz);
    if ($now >= $next) $next->modify('+1 day');
    wp_schedule_event($next->getTimestamp(), 'daily', 'wp_stocks_index_history_cron');
});

add_action('wp_stocks_index_history_cron', function () {
    @set_time_limit(300);
    wp_stocks_index_history_run_all();
});

register_deactivation_hook(WP_STOCKS_PLUGIN_FILE, function () {
    wp_clear_scheduled_hook('wp_stocks_index_history_cron');
});

// --------------------------------------------------
// バックフィル（2年分）：1リクエストあたり6指数ずつ処理し、画面が自動で次へ進む
// --------------------------------------------------
add_action('admin_post_wp_stocks_index_history_backfill', function () {
    wp_stocks_require_admin_action('wp_stocks_index_history_backfill');
    @set_time_limit(120);
    wp_stocks_index_history_ensure_table();

    $symbols = array_keys(wp_stocks_index_history_symbols());
    $total   = count($symbols);
    $batch   = 6;
    $offset  = max(0, intval($_GET['offset'] ?? 0));
    $ok      = max(0, intval($_GET['ok'] ?? 0));
    $fail    = max(0, intval($_GET['fail'] ?? 0));

    foreach (array_slice($symbols, $offset, $batch) as $symbol) {
        $saved = wp_stocks_index_history_fetch_and_save($symbol, '2y');
        if ($saved === false) $fail++; else $ok++;
        usleep(300000);
    }
    $offset += $batch;

    $market_url = admin_url('admin.php?page=wp-stocks-market&mtab=index');
    if ($offset >= $total) {
        wp_stocks_log($fail ? 'error' : 'info', 'index_history', 'BACKFILL', "指数履歴の2年分取得：成功 {$ok} / 失敗 {$fail}");
        wp_safe_redirect(add_query_arg(['idxhist' => 'done', 'ok' => $ok, 'fail' => $fail], $market_url));
        exit;
    }

    $next_url = add_query_arg([
        'action'   => 'wp_stocks_index_history_backfill',
        'offset'   => $offset,
        'ok'       => $ok,
        'fail'     => $fail,
        '_wpnonce' => wp_create_nonce('wp_stocks_index_history_backfill'),
    ], admin_url('admin-post.php'));

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>指数の履歴を取得中</title></head>'
        . '<body style="font-family:sans-serif;padding:40px;">'
        . '<h2>指数の履歴を取得中…</h2>'
        . '<p>' . intval(min($offset, $total)) . ' / ' . intval($total) . ' 指数を処理しました。このまま次の処理へ進みます。</p>'
        . '<noscript><p><a href="' . esc_url($next_url) . '">続ける</a></p></noscript>'
        . '<script>setTimeout(function(){ location.href = ' . wp_json_encode($next_url) . '; }, 500);</script>'
        . '</body></html>';
    exit;
});

// --------------------------------------------------
// マーケット情報「指数」タブの下に出す：履歴の保存状況＋取得ボタン
// --------------------------------------------------
function wp_stocks_render_index_history_panel() {
    global $wpdb;
    wp_stocks_index_history_ensure_table();

    echo '<div style="margin-top:35px;">';
    echo '<h3>&#x1F4C8; 指数の履歴（日次）</h3>';

    if (($_GET['idxhist'] ?? '') === 'done') {
        $ok   = intval($_GET['ok'] ?? 0);
        $fail = intval($_GET['fail'] ?? 0);
        $cls  = $fail ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . $cls . ' inline"><p>2年分の取得が完了しました：成功 ' . $ok . ' / 失敗 ' . $fail
            . ($fail ? '（失敗の理由は「取得ログ」で確認できます）' : '') . '</p></div>';
    }

    $stats = [];
    $table = wp_stocks_index_history_table();
    $rows  = $wpdb->get_results("SELECT symbol, COUNT(*) AS n, MIN(trade_date) AS oldest, MAX(trade_date) AS newest FROM {$table} GROUP BY symbol");
    foreach ((array) $rows as $r) $stats[$r->symbol] = $r;

    $backfill_url = add_query_arg([
        'action'   => 'wp_stocks_index_history_backfill',
        '_wpnonce' => wp_create_nonce('wp_stocks_index_history_backfill'),
    ], admin_url('admin-post.php'));

    $next_ts = wp_next_scheduled('wp_stocks_index_history_cron');
    echo '<p style="color:#666;font-size:13px;">日次の自動更新：'
        . ($next_ts ? '次回 ' . esc_html(wp_date('Y-m-d H:i', $next_ts, new DateTimeZone('Asia/Tokyo'))) . '（日本時間）' : '未登録')
        . ' ／ 取得元：Yahoo!ファイナンス</p>';
    echo '<p><a href="' . esc_url($backfill_url) . '" class="button" onclick="return confirm(\'登録されている指数・ETFの約2年分をYahooから取得します（数分かかります）。よろしいですか？\');">'
        . '&#x1F504; 指数の履歴を今すぐ取得（約2年分）</a></p>';

    echo '<table class="widefat striped" style="max-width:760px;"><thead><tr>'
        . '<th>指数</th><th>コード</th><th style="text-align:right;">件数</th><th>最古</th><th>最新</th></tr></thead><tbody>';
    $today_ts = time();
    foreach (wp_stocks_index_history_symbols() as $symbol => $label) {
        $s = $stats[$symbol] ?? null;
        $stale = $s && (($today_ts - strtotime($s->newest)) > 5 * DAY_IN_SECONDS);
        echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html($symbol) . '</td>';
        if ($s) {
            echo '<td style="text-align:right;">' . intval($s->n) . '</td><td>' . esc_html($s->oldest) . '</td>'
                . '<td' . ($stale ? ' style="color:#c0392b;font-weight:bold;"' : '') . '>' . esc_html($s->newest) . ($stale ? '（更新が止まっています）' : '') . '</td>';
        } else {
            echo '<td style="text-align:right;color:#aaa;">0</td><td style="color:#aaa;">-</td><td style="color:#aaa;">-</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

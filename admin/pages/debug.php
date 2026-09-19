<?php
/**
 * WP Stocks Manager — 管理画面: APIデバッグページ
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// APIデバッグページ
// --------------------------------------------------
function wp_stocks_debug_page() {
    echo '<div class="wrap"><h1>API診断（Yahoo Finance / Webull）</h1>';
    $symbol = isset($_GET['symbol']) ? sanitize_text_field($_GET['symbol']) : '7974.T';
    echo '<form method="get"><input type="hidden" name="page" value="wp-stocks-debug">';
    echo '<input type="text" name="symbol" value="' . esc_attr($symbol) . '" placeholder="例: 7974.T" style="width:150px;margin-right:8px;">';
    echo '<button type="submit" class="button button-primary">APIレスポンス確認</button></form><br>';

    $code_only = str_replace('.T', '', $symbol);
    echo '<h2>TradingView ウィジェットテスト（TSE:' . esc_html($code_only) . '）</h2>';
    echo wp_stocks_tradingview_widget($code_only, 400);

    // v8 API
    $res1  = wp_remote_get("https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=5d", ['headers' => ['User-Agent' => 'Mozilla/5.0'], 'timeout' => 15]);
    $body1 = json_decode(wp_remote_retrieve_body($res1), true);
    $meta  = $body1['chart']['result'][0]['meta'] ?? [];
    echo '<h2>v8 chart API</h2>';
    echo '<pre style="background:#e8f8e8;padding:10px;border-radius:4px;">' . esc_html(json_encode(['regularMarketPrice' => $meta['regularMarketPrice'] ?? 'N/A', 'chartPreviousClose' => $meta['chartPreviousClose'] ?? 'N/A', 'regularMarketVolume' => $meta['regularMarketVolume'] ?? 'N/A'], JSON_PRETTY_PRINT)) . '</pre>';

    // テクニカル計算テスト
    echo '<h2>テクニカル指標テスト（1mo日足）</h2>';
    $ohlcv = wp_stocks_get_ohlcv($symbol);
    if ($ohlcv && count($ohlcv) >= 5) {
        $closes = array_column($ohlcv, 'close');
        $highs  = array_column($ohlcv, 'high');
        $lows   = array_column($ohlcv, 'low');
        $opens  = array_column($ohlcv, 'open');
        $tech   = wp_stocks_calc_technicals($closes, $highs, $lows, $opens);
        echo '<pre style="background:#e8f8e8;padding:10px;border-radius:4px;">' . esc_html(json_encode($tech, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<p>' . wp_stocks_trend_icon_html((object)$tech) . '</p>';
    } else {
        echo '<p style="color:red;">日足データの取得に失敗しました。</p>';
    }

    // Crumb テスト
    echo '<h2>Crumb取得テスト</h2>';
    $auth = wp_stocks_get_crumb();
    if ($auth) {
        echo '<p style="color:green;">✅ Crumb取得成功: ' . esc_html($auth['crumb']) . '</p>';
        $modules = 'assetProfile,defaultKeyStatistics,financialData,summaryDetail,calendarEvents';
        $url2    = 'https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . $symbol . '?modules=' . $modules . '&crumb=' . urlencode($auth['crumb']);
        $res2    = wp_remote_get($url2, ['headers' => ['User-Agent' => 'Mozilla/5.0', 'Cookie' => $auth['cookie'], 'Accept' => 'application/json'], 'timeout' => 20]);
        $code2   = wp_remote_retrieve_response_code($res2);
        $body2   = json_decode(wp_remote_retrieve_body($res2), true);
        echo '<h3>quoteSummary HTTP: ' . esc_html($code2) . '</h3>';
        if ($code2 == 200 && isset($body2['quoteSummary']['result'][0])) {
            echo '<p style="color:green;">✅ 取得成功！</p>';
        } else {
            echo '<pre style="background:#fde8e8;padding:10px;">' . esc_html(json_encode($body2, JSON_PRETTY_PRINT)) . '</pre>';
        }
    } else {
        echo '<p style="color:red;">❌ Crumb取得失敗</p>';
    }

    // --------------------------------------------------
    // Webull診断（設定ページから移動・2026/09/15 単一銘柄フォームに簡素化）
    // 2026/09/15 Yahoo側と同様、ページ表示時にその場でリクエストして結果を表示する方式に変更
    // --------------------------------------------------
    echo '<hr style="margin:30px 0;">';
    echo '<h1>Webull API診断</h1>';

    $webull_debug_symbol   = isset($_GET['webull_symbol'])   ? sanitize_text_field($_GET['webull_symbol'])   : 'AAPL';
    $webull_debug_category = isset($_GET['webull_category']) ? sanitize_text_field($_GET['webull_category']) : 'US_STOCK';

    echo '<form method="get" style="margin-bottom:10px;">';
    echo '<input type="hidden" name="page" value="wp-stocks-debug">';
    echo '<input type="text" name="webull_symbol" value="' . esc_attr($webull_debug_symbol) . '" placeholder="例: AAPL / 7203" style="width:150px;margin-right:8px;">';
    echo '<select name="webull_category" style="margin-right:8px;">';
    echo '<option value="US_STOCK"' . selected($webull_debug_category, 'US_STOCK', false) . '>US_STOCK</option>';
    echo '<option value="JP_STOCK"' . selected($webull_debug_category, 'JP_STOCK', false) . '>JP_STOCK</option>';
    echo '</select>';
    echo '<button type="submit" class="button button-primary">Webullレスポンス確認</button>';
    echo '</form>';

    $webull_debug_host = 'api.webull.co.jp';
    $webull_debug_path = '/market-data/stocks/bars/list';
    $webull_debug_body = wp_json_encode(array(
        'symbols'            => array($webull_debug_symbol),
        'category'           => $webull_debug_category,
        'timespan'           => 'D',
        'count'              => 10,
        'real_time_required' => false,
    ));

    $webull_debug_signed  = wp_stocks_webull_sign_request('POST', $webull_debug_path, array(), $webull_debug_body, $webull_debug_host, 'v3', 'HMAC-SHA1');
    $webull_debug_headers = $webull_debug_signed['headers'];
    $webull_debug_headers['Content-Type'] = 'application/json';
    $webull_debug_headers['Accept']       = 'application/json';

    $webull_debug_response = wp_remote_post('https://' . $webull_debug_host . $webull_debug_path, array(
        'headers' => $webull_debug_headers,
        'body'    => $webull_debug_body,
        'timeout' => 30,
    ));

    if (is_wp_error($webull_debug_response)) {
        echo '<p style="color:red;">通信エラー: ' . esc_html($webull_debug_response->get_error_message()) . '</p>';
    } else {
        $webull_debug_status = wp_remote_retrieve_response_code($webull_debug_response);
        $webull_debug_raw    = wp_remote_retrieve_body($webull_debug_response);
        $webull_debug_bg     = ($webull_debug_status == 200) ? '#e8f8e8' : '#fde8e8';
        echo '<p>HTTP Status: <strong>' . esc_html($webull_debug_status) . '</strong></p>';
        echo '<pre style="background:' . $webull_debug_bg . ';padding:10px;border-radius:4px;max-height:400px;overflow:auto;">' . esc_html($webull_debug_raw) . '</pre>';
    }

    echo '</div>';
}

<?php
/**
 * WP Stocks Manager — Yahoo Finance / 株探 スクレイピング連携
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// Yahoo! Finance APIから株価取得（前日終値・出来高も取得）
// --------------------------------------------------
function wp_stocks_get_price($symbol) {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=5d";
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'get_price', $symbol, 'APIエラー: ' . $response->get_error_message());
        return false;
    }
    $body   = json_decode(wp_remote_retrieve_body($response), true);
    $result = $body['chart']['result'][0] ?? null;
    $meta   = $result['meta'] ?? null;
    if (!$meta) {
        wp_stocks_log('error', 'get_price', $symbol, 'レスポンス異常');
        return false;
    }

    $price  = $meta['regularMarketPrice']  ?? null;
    $volume = $meta['regularMarketVolume'] ?? null;

    // --- 前日終値は日足配列(close[])から算出（休場日でもmetaのchartPreviousCloseより信頼できる） ---
    $closes = $result['indicators']['quote'][0]['close'] ?? [];
    $closes = array_values(array_filter($closes, function($c) { return $c !== null; }));

    $previous_close = null;
    if (count($closes) >= 2) {
        // 末尾2件のうち、ひとつ前を前日終値として使用
        $previous_close = floatval($closes[count($closes) - 2]);
    } else {
        // データ不足時のみmetaにフォールバック
        $fallback = $meta['chartPreviousClose'] ?? $meta['previousClose'] ?? null;
        $previous_close = $fallback !== null ? floatval($fallback) : null;
    }

    if ($price !== null && $price > 0) {
        return [
            'c'              => floatval($price),
            'previous_close' => $previous_close,
            'volume'         => $volume !== null ? intval($volume) : null,
        ];
    }
    wp_stocks_log('error', 'get_price', $symbol, '株価が取得できませんでした');
    return false;
}

// --------------------------------------------------
// Yahoo! Finance APIから1ヶ月分の日足データ取得（テクニカル計算用）
// トランジェントキャッシュ付き（6時間）
// --------------------------------------------------
function wp_stocks_get_ohlcv($symbol) {
    $cache_key = 'wp_stocks_ohlcv_' . md5($symbol);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $url      = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=6mo";
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) return false;

    $body    = json_decode(wp_remote_retrieve_body($response), true);
    $result  = $body['chart']['result'][0] ?? null;
    if (!$result) return false;

    $timestamps = $result['timestamp']                        ?? [];
    $closes     = $result['indicators']['quote'][0]['close']  ?? [];
    $volumes    = $result['indicators']['quote'][0]['volume'] ?? [];
    $highs      = $result['indicators']['quote'][0]['high']   ?? [];
    $lows       = $result['indicators']['quote'][0]['low']    ?? [];
    $opens      = $result['indicators']['quote'][0]['open']   ?? [];

    if (empty($closes)) return false;

    $data = [];
    foreach ($timestamps as $i => $ts) {
        if (!isset($closes[$i]) || $closes[$i] === null) continue;
        $data[] = [
            'date'   => date('Y-m-d', $ts),
            'open'   => isset($opens[$i]) && $opens[$i] !== null ? floatval($opens[$i]) : floatval($closes[$i]),
            'close'  => floatval($closes[$i]),
            'high'   => isset($highs[$i]) && $highs[$i] !== null ? floatval($highs[$i]) : floatval($closes[$i]),
            'low'    => isset($lows[$i])  && $lows[$i]  !== null ? floatval($lows[$i])  : floatval($closes[$i]),
            'volume' => intval($volumes[$i] ?? 0),
        ];
    }

    set_transient($cache_key, $data, 6 * HOUR_IN_SECONDS);
    return $data;
}

// --------------------------------------------------
// 株探 銘柄ページから「テーマ」を取得（会社情報テーブル内）
// --------------------------------------------------
function wp_stocks_scrape_kabutan_theme($code) {
    $url      = 'https://kabutan.jp/stock/?code=' . $code;
    $response = wp_remote_get($url, [
        'headers' => [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language' => 'ja,en;q=0.9',
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return '';
    if (wp_remote_retrieve_response_code($response) !== 200) return '';

    $html   = wp_remote_retrieve_body($response);
    $themes = [];

    // 会社情報テーブル内の <th>テーマ</th><td><ul><li><a>...</a></li>...</ul></td> を抽出
    if (preg_match("/<th\s+scope=['\"]row['\"]>\s*テーマ\s*<\/th>\s*<td[^>]*>(.*?)<\/td>/s", $html, $m)) {
        preg_match_all('/<a[^>]*>([^<]+)<\/a>/', $m[1], $links);
        if (!empty($links[1])) $themes = $links[1];
    }

    $themes = array_map('trim', $themes);
    $themes = array_filter($themes, function($t) { return $t !== ''; });

    return implode(', ', $themes); // 件数制限なし
}
// --------------------------------------------------
// Yahoo!ファイナンス Japan スクレイピング
// --------------------------------------------------
function wp_stocks_scrape_yahoo_japan($code) {
    $url      = 'https://finance.yahoo.co.jp/quote/' . $code . '.T';
    $response = wp_remote_get($url, [
        'headers' => [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
            'Accept-Language' => 'ja,en;q=0.9',
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return [];
    if (wp_remote_retrieve_response_code($response) !== 200) return [];

    $html   = wp_remote_retrieve_body($response);
    $result = [];
    $labels = [
        '自己資本比率' => 'equity_ratio',
        '配当利回り'   => 'dividend_yield_yj',
        'ROE'          => 'roe_yj',
        'PER'          => 'per_yj',
        'PBR'          => 'pbr_yj',
    ];

    preg_match_all('/<dl[^>]*>.*?<\/dl>/s', $html, $dl_blocks);
    foreach ($dl_blocks[0] as $block) {
        foreach ($labels as $label => $key) {
            if (mb_strpos($block, $label) === false) continue;
            if (preg_match('/<dd[^>]*>.*?<\/dd>/s', $block, $dd_match)) {
                $dd = $dd_match[0];
                preg_match_all('/_StyledNumber__value_[^"]*"[^>]*>([\d,\.]+)</', $dd, $vals);
                if (!empty($vals[1])) {
                    $val = floatval(str_replace(',', '', $vals[1][0]));
                    if ($val > 0 && $val < 100000) $result[$key] = $val;
                }
            }
        }
    }

    // 日本語業種名をHTMLクラスから取得
    if (preg_match('/_CommonPriceBoard__industryName[^>]+>([^<]+)</', $html, $m)) {
        $result['industry_ja'] = $m[1];
    }

    return $result;
}

// --------------------------------------------------
// Cookie & Crumb 取得（キャッシュ付き）
// --------------------------------------------------
function wp_stocks_get_crumb() {
    $cached = get_transient('wp_stocks_yahoo_crumb');
    if ($cached !== false) return $cached;

    $headers = [
        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'ja,en;q=0.9',
    ];

    $consent_res   = wp_remote_get('https://fc.yahoo.com', ['headers' => $headers, 'timeout' => 15]);
    $cookie_header = wp_remote_retrieve_header($consent_res, 'set-cookie');
    $cookie_str    = is_array($cookie_header) ? implode('; ', $cookie_header) : $cookie_header;
    if (empty($cookie_str)) return false;

    $crumb_res  = wp_remote_get('https://query1.finance.yahoo.com/v1/test/getcrumb', [
        'headers' => array_merge($headers, ['Cookie' => $cookie_str]),
        'timeout' => 15,
    ]);
    $crumb_code = wp_remote_retrieve_response_code($crumb_res);
    $crumb      = trim(wp_remote_retrieve_body($crumb_res));

    if ($crumb_code !== 200 || empty($crumb) || strlen($crumb) > 50) return false;

    $data = ['crumb' => $crumb, 'cookie' => $cookie_str];
    set_transient('wp_stocks_yahoo_crumb', $data, 12 * HOUR_IN_SECONDS);
    return $data;
}

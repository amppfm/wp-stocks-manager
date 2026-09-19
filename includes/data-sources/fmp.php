<?php
/**
 * WP Stocks Manager — Financial Modeling Prep (FMP) API 連携
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// Financial Modeling Prep (FMP) API 連携
// 米国株の財務スコアカード（PER/PBR/ROE/自己資本比率/配当利回り/PEG/利益率）を
// Yahoo Financeベースの取得から置き換えるために追加（2026-09 実機検証済み・無料Basicプランで動作確認）
// --------------------------------------------------
function wp_stocks_fmp_api_key() {
    return get_option('wp_stocks_fmp_api_key', '');
}

function wp_stocks_fmp_fetch($endpoint, $params = array()) {
    $api_key = wp_stocks_fmp_api_key();
    if (empty($api_key)) return false;

    $params['apikey'] = $api_key;
    $url = 'https://financialmodelingprep.com/stable/' . $endpoint . '?' . http_build_query($params);

    $response = wp_remote_get($url, array('timeout' => 20));
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'fmp_fetch', $endpoint, 'リクエスト失敗：' . $response->get_error_message());
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200) {
        wp_stocks_log('error', 'fmp_fetch', $endpoint, 'HTTPエラー：' . $code . ' / ' . wp_remote_retrieve_body($response));
        return false;
    }
    if (is_array($body) && isset($body['Error Message'])) {
        wp_stocks_log('error', 'fmp_fetch', $endpoint, 'APIエラー：' . $body['Error Message']);
        return false;
    }
    return $body;
}

// 米国株の財務スコアカード用比率をFMPから取得（ratios-ttm + key-metrics-ttm）
// 失敗時（APIキー未設定・通信失敗・データ無し）はfalseを返す。
// 呼び出し側はfalseの場合Yahoo由来の値をそのまま使うフォールバックとする。
function wp_stocks_fmp_get_ratios($symbol) {
    $ratios = wp_stocks_fmp_fetch('ratios-ttm', array('symbol' => $symbol));
    if (!is_array($ratios) || empty($ratios[0])) return false;
    $r = $ratios[0];

    $metrics = wp_stocks_fmp_fetch('key-metrics-ttm', array('symbol' => $symbol));
    $m = (is_array($metrics) && !empty($metrics[0])) ? $metrics[0] : array();

    // 自己資本比率 = 1 / 財務レバレッジ比率（総資産÷純資産）× 100
    // financialLeverageRatioTTMはkey-metrics-ttmではなくratios-ttm（$r）側のフィールド
    $leverage     = floatval($r['financialLeverageRatioTTM'] ?? 0);
    $equity_ratio = $leverage > 0 ? round(100 / $leverage, 1) : 0;

    return array(
        'per'            => floatval($r['priceToEarningsRatioTTM']              ?? 0),
        'pbr'            => floatval($r['priceToBookRatioTTM']                  ?? 0),
        'eps'            => floatval($r['netIncomePerShareTTM']                 ?? 0),
        'profit_margin'  => floatval($r['netProfitMarginTTM']                   ?? 0) * 100,
        'dividend_yield' => floatval($r['dividendYieldTTM']                     ?? 0) * 100,
        'peg_trailing'   => floatval($r['priceToEarningsGrowthRatioTTM']        ?? 0),
        'peg'            => floatval($r['forwardPriceToEarningsGrowthRatioTTM'] ?? 0),
        'roe'            => floatval($m['returnOnEquityTTM']  ?? 0) * 100,
        'roa'            => floatval($m['returnOnAssetsTTM']  ?? 0) * 100,
        'market_cap'     => intval($m['marketCap'] ?? 0),
        'equity_ratio'   => $equity_ratio,
    );
}

// 保有米国株の「次回決算予定日」を一括取得してtransientにキャッシュ（12時間）。
// FMPのearnings-calendarはsymbolでの絞り込みが効かず期間内の全銘柄が返ってくるため、
// 銘柄ごとに叩くのではなく1回だけ取得してsymbol => dateの連想配列に変換して使い回す。
function wp_stocks_fmp_get_earnings_dates() {
    $cached = get_transient('wp_stocks_fmp_earnings_dates');
    if ($cached !== false) return $cached;

    $from = date('Y-m-d');
    $to   = date('Y-m-d', strtotime('+90 days'));
    $data = wp_stocks_fmp_fetch('earnings-calendar', array('from' => $from, 'to' => $to));

    $map = array();
    if (is_array($data)) {
        foreach ($data as $row) {
            $sym  = $row['symbol'] ?? '';
            $date = $row['date']   ?? '';
            if ($sym === '' || $date === '') continue;
            // 同一銘柄が複数含まれる場合は最初（最も近い予定日）を採用
            if (!isset($map[$sym])) $map[$sym] = $date;
        }
    }

    set_transient('wp_stocks_fmp_earnings_dates', $map, 12 * HOUR_IN_SECONDS);
    return $map;
}

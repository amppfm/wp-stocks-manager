<?php
/**
 * WP Stocks Manager — マーケット指数取得（日経平均・NYダウ・為替等）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// マーケット情報ページ：表示する指数の定義
// --------------------------------------------------
function wp_stocks_get_market_index_groups() {
    return [
        '日本' => [
            ['label' => '日経平均',        'symbol' => '^N225'],
            ['label' => '日経平均先物',    'symbol' => 'NIY=F'],
            ['label' => '日本グロース250', 'symbol' => '2516.T'],
            ['label' => 'ドル円',          'symbol' => 'USDJPY=X'],
            ['label' => 'ユーロ円',        'symbol' => 'EURJPY=X'],
            ['label' => 'ユーロドル',      'symbol' => 'EURUSD=X'],
        ],
        '米国' => [
            ['label' => 'NYダウ',                   'symbol' => '^DJI'],
            ['label' => 'ナスダック総合',           'symbol' => '^IXIC'],
            ['label' => 'S&P500',                   'symbol' => '^GSPC'],
            ['label' => 'ラッセル2000',             'symbol' => '^RUT'],
            ['label' => 'フィラデルフィア半導体',   'symbol' => '^SOX'],
            ['label' => 'NYSE FANG+',                'symbol' => '^NYFANG'],
            ['label' => 'VIX恐怖指数',              'symbol' => '^VIX'],
            ['label' => '米国債10年利回り',         'symbol' => '^TNX'],
            ['label' => 'WTI原油先物',              'symbol' => 'CL=F'],
        ],
        '海外' => [
            ['label' => '英国FTSE100',   'symbol' => '^FTSE'],
            ['label' => 'ドイツDAX',     'symbol' => '^GDAXI'],
            ['label' => 'フランスCAC40', 'symbol' => '^FCHI'],
            ['label' => '韓国KOSPI',     'symbol' => '^KS11'],
            ['label' => '中国上海総合',  'symbol' => '000001.SS'],
            ['label' => '香港ハンセン',  'symbol' => '^HSI'],
            ['label' => 'インドNifty',   'symbol' => '^NSEI'],
        ],
    ];
}

// --------------------------------------------------
// マーケット情報「指数」タブの、日本株／米国株の分け方（サブタブ）
//   index_groups : wp_stocks_get_market_index_groups() のグループ名
//   theme_groups : wp_stocks_theme_reference_groups() のグループ名
// 「海外」（欧州・アジア）は日本株側に置いている。米国株側に移すときは、index_groups の配列を入れ替える。
// --------------------------------------------------
function wp_stocks_market_region_defs() {
    return [
        'jp' => ['label' => '日本株', 'index_groups' => ['日本', '海外'], 'theme_groups' => ['基準指数', 'テーマETF（日本）']],
        'us' => ['label' => '米国株', 'index_groups' => ['米国'],         'theme_groups' => ['米国セクターETF（SPDR）', 'テーマETF（米国）']],
    ];
}

// $kind: 'index'（指数・為替）／'theme'（テーマ・基準指数）。該当グループだけを返す
function wp_stocks_market_region_groups($region, $kind) {
    $defs = wp_stocks_market_region_defs();
    if (!isset($defs[$region])) return [];
    if ($kind === 'theme') {
        $all  = function_exists('wp_stocks_theme_reference_groups') ? wp_stocks_theme_reference_groups() : [];
        $keys = $defs[$region]['theme_groups'];
    } else {
        $all  = wp_stocks_get_market_index_groups();
        $keys = $defs[$region]['index_groups'];
    }
    return array_intersect_key($all, array_flip($keys));
}

// 履歴の状況表用：その側の [symbol => label]。日本株側にはTOPIX-17業種ETFも含める
function wp_stocks_market_region_symbols($region) {
    $list = [];
    foreach (['index', 'theme'] as $kind) {
        foreach (wp_stocks_market_region_groups($region, $kind) as $items) {
            foreach ($items as $item) $list[$item['symbol']] = $item['label'];
        }
    }
    if ($region === 'jp' && function_exists('wp_stocks_get_topix17_sector_map')) {
        foreach (wp_stocks_get_topix17_sector_map() as $code => $d) $list[$code . '.T'] = $d['name'];
    }
    return $list;
}

// --------------------------------------------------
// マーケット情報ページ：指数データ取得（10分キャッシュ）
// --------------------------------------------------
function wp_stocks_fetch_index_quote($symbol) {
    $cache_key = 'wp_stocks_idx_' . md5($symbol);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=1d&range=5d';
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $meta = $body['chart']['result'][0]['meta'] ?? null;
    if (!$meta || !isset($meta['regularMarketPrice'])) return false;

    $data = [
        'price'          => floatval($meta['regularMarketPrice']),
        'previous_close' => isset($meta['chartPreviousClose']) ? floatval($meta['chartPreviousClose'])
                             : (isset($meta['previousClose']) ? floatval($meta['previousClose']) : null),
    ];
    set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);
    return $data;
}

function wp_stocks_format_index_value($price) {
    if ($price === null) return '-';
    $abs = abs($price);
    if ($abs < 10)   return number_format($price, 3);
    if ($abs < 1000) return number_format($price, 2);
    return number_format($price, 0);
}

function wp_stocks_index_change_html($price, $previous_close) {
    if (!$previous_close || $previous_close <= 0) return '<span style="color:#888;">-</span>';
    $change     = $price - $previous_close;
    $change_pct = $change / $previous_close * 100;
    $color = $change >= 0 ? '#e74c3c' : '#3498db';
    $arrow = $change >= 0 ? '▲' : '▼';
    return '<span style="color:' . $color . ';font-weight:bold;font-size:12px;">'
        . $arrow . ($change >= 0 ? '+' : '') . wp_stocks_format_index_value($change)
        . ' (' . ($change_pct >= 0 ? '+' : '') . number_format($change_pct, 2) . '%)'
        . '</span>';
}

// $groups: 表示するグループ（wp_stocks_market_region_groups の結果）。省略時は全グループ
function wp_stocks_render_market_indices($groups = null) {
    if ($groups === null) $groups = wp_stocks_get_market_index_groups();
    $group_links = [
        '日本' => 'https://nikkei225jp.com/',
        '米国' => 'https://nikkei225jp.com/nasdaq/',
    ];
    foreach ($groups as $group_label => $items) {
        echo '<div style="margin-bottom:25px;">';
        echo '<div style="font-size:14px;font-weight:bold;color:#555;margin-bottom:10px;padding-bottom:4px;border-bottom:1px solid #eee;">' . esc_html($group_label) . '</div>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:10px;">';
        foreach ($items as $item) {
            $q = wp_stocks_fetch_index_quote($item['symbol']);
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;min-width:150px;">';
            echo '<div style="font-size:12px;color:#888;margin-bottom:4px;">' . esc_html($item['label']) . '</div>';
            if ($q) {
                echo '<div style="font-size:18px;font-weight:bold;">' . wp_stocks_format_index_value($q['price']) . '</div>';
                echo '<div style="margin-top:4px;">' . wp_stocks_index_change_html($q['price'], $q['previous_close']) . '</div>';
            } else {
                echo '<div style="font-size:13px;color:#aaa;">取得失敗</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        if (!empty($group_links[$group_label])) {
            echo '<div style="margin-top:8px;"><a href="' . esc_url($group_links[$group_label]) . '" target="_blank" rel="noopener" style="font-size:12px;color:#0073aa;text-decoration:none;">&#x1F517; ' . esc_html($group_label) . 'の詳細情報を見る</a></div>';
        }
        echo '</div>';
    }
}

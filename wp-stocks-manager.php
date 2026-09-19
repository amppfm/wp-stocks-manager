<?php
/*
Plugin Name: WP Stocks Manager
Description: 銘柄管理・株価取得・企業情報・ニュース・株価履歴・祝日スキップ・ダッシュボード・ポートフォリオ・財務スコアカード・決算カレンダー・テクニカル指標・銘柄比較・AI分析履歴・銘柄メモ・インポート/エクスポート・ログ
Version: 3.2
*/

if (!defined('ABSPATH')) exit;

// ★一時措置：OPcacheが更新されたファイルを認識していない可能性があるため、
// 一度だけ opcache_reset() を実行する（完了後は自動的に何もしなくなる）
if (!get_option('wp_stocks_temp_opcache_reset_done')) {
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    update_option('wp_stocks_temp_opcache_reset_done', 1);
}

define('WP_STOCKS_VERSION', '4.9');
define('WP_STOCKS_LOG_DAYS', 30);

// --------------------------------------------------
// Stage 1 ファイル分割：外部データソース連携モジュール
// --------------------------------------------------
define('WP_STOCKS_PLUGIN_FILE', __FILE__);
define('WP_STOCKS_PLUGIN_DIR', plugin_dir_path(__FILE__));
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/webull.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/edgar.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/fmp.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/edinet.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/jpx.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/yahoo-scraping.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/index-quotes.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/technicals.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/scoring.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/db.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/misc-pages.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/funds-page.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/dashboard.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/stock-list.php';

// --------------------------------------------------
// セクター名 英語→日本語変換
// --------------------------------------------------
// --------------------------------------------------
// 市場区分の略号（プライム=P/スタンダード=S/グロース=G）を返す
// --------------------------------------------------
function wp_stocks_market_segment_suffix($market) {
    $map = ['プライム' => 'P', 'スタンダード' => 'S', 'グロース' => 'G'];
    return isset($map[$market]) ? ' ' . $map[$market] : '';
}

function wp_stocks_sector_ja($sector) {
    // 既に日本語の場合はそのまま返す
    if (preg_match('/[\x{3000}-\x{9FFF}]/u', $sector)) return $sector;
    $map = [
        'Technology'              => 'テクノロジー',
        'Healthcare'              => 'ヘルスケア',
        'Financial Services'      => '金融サービス',
        'Consumer Cyclical'       => '消費財（景気敏感）',
        'Consumer Defensive'      => '消費財（生活必需品）',
        'Industrials'             => '資本財・サービス',
        'Basic Materials'         => '素材',
        'Energy'                  => 'エネルギー',
        'Real Estate'             => '不動産',
        'Communication Services'  => '通信サービス',
        'Utilities'               => '公益事業',
        'Financial'               => '金融',
        'Services'                => 'サービス',
        'Manufacturing'           => '製造業',
        'Retail'                  => '小売',
        'Transportation'          => '輸送',
        'Pharmaceutical'          => '医薬品',
        'Electronic Technology'   => '電子技術',
        'Producer Manufacturing'  => '生産財製造',
        'Commercial Services'     => '商業サービス',
        'Health Technology'       => 'ヘルステクノロジー',
        'Consumer Non-Durables'   => '消費財（非耐久財）',
        'Consumer Durables'       => '消費財（耐久財）',
        'Distribution Services'   => '流通サービス',
        'Process Industries'      => 'プロセス産業',
        'Finance'                 => '金融',
        'Miscellaneous'           => 'その他',
    ];
    return $map[$sector] ?? $sector;
}

// --------------------------------------------------
// 東証33業種 → TOPIX-17セクターETF の対応表
// 「その他製品」は暫定的に1626に含めている（後日、手動上書き設定を追加予定）
// --------------------------------------------------
function wp_stocks_get_topix17_sector_map() {
    return [
        '1617' => ['name' => 'NF・食品',           'sectors' => ['水産・農林業', '食料品']],
        '1618' => ['name' => 'NF・エネルギー資源', 'sectors' => ['鉱業', '石油・石炭製品']],
        '1619' => ['name' => 'NF・建設・資材',     'sectors' => ['建設業', '金属製品', 'ガラス・土石製品']],
        '1620' => ['name' => 'NF・素材・化学',     'sectors' => ['化学', '繊維製品', 'パルプ・紙', 'ゴム製品']],
        '1621' => ['name' => 'NF・医薬品',         'sectors' => ['医薬品']],
        '1622' => ['name' => 'NF・自動車・輸送機', 'sectors' => ['輸送用機器']],
        '1623' => ['name' => 'NF・鉄鋼・非鉄',     'sectors' => ['鉄鋼', '非鉄金属']],
        '1624' => ['name' => 'NF・機械',           'sectors' => ['機械']],
        '1625' => ['name' => 'NF・電機・精密',     'sectors' => ['電気機器', '精密機器']],
        '1626' => ['name' => 'NF・情報通信・サービス', 'sectors' => ['情報・通信業', 'サービス業', 'その他製品']],
        '1627' => ['name' => 'NF・電力・ガス',     'sectors' => ['電気・ガス業']],
        '1628' => ['name' => 'NF・運輸・物流',     'sectors' => ['陸運業', '海運業', '空運業', '倉庫・運輸関連業']],
        '1629' => ['name' => 'NF・商社・卸売',     'sectors' => ['卸売業']],
        '1630' => ['name' => 'NF・小売',           'sectors' => ['小売業']],
        '1631' => ['name' => 'NF・銀行',           'sectors' => ['銀行業']],
        '1632' => ['name' => 'NF・金融（除く銀行）', 'sectors' => ['保険業', '証券・商品先物取引業', 'その他金融業']],
        '1633' => ['name' => 'NF・不動産',         'sectors' => ['不動産業']],
    ];
}

// --------------------------------------------------
// 東証33業種名 → TOPIX-17セクターコード の逆引き
// マーケット情報ヒートマップ（日本株タブ）でTOPIX-17階層に集約するために使用
// 対応表に存在しない（＝分類不能）業種名を渡された場合は null を返す
// --------------------------------------------------
function wp_stocks_sector33_to_topix17($sector33) {
    static $reverse_map = null;
    if ($reverse_map === null) {
        $reverse_map = [];
        foreach (wp_stocks_get_topix17_sector_map() as $topix17_code => $info) {
            foreach ($info['sectors'] as $s33) {
                $reverse_map[$s33] = $topix17_code;
            }
        }
    }
    return $reverse_map[$sector33] ?? null;
}

// --------------------------------------------------
// TOPIX-17セクターETFの日次価格履歴を取得（stock_id => [['date','price','previous_close'], ...] 昇順）
// マーケット情報ヒートマップの「カレンダー」「期間別」タブで使用
// --------------------------------------------------
function wp_stocks_get_etf_price_history($etf_stocks, $days = 95) {
    global $wpdb;
    if (empty($etf_stocks)) return [];
    $ids = array_map(fn($s) => $s->id, $etf_stocks);
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $cutoff = date('Y-m-d', strtotime('-' . intval($days) . ' days'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT stock_id, DATE(datetime) AS d, price, previous_close
         FROM {$wpdb->prefix}stock_prices
         WHERE stock_id IN ($placeholders) AND DATE(datetime) >= %s
         ORDER BY stock_id ASC, d ASC",
        array_merge($ids, [$cutoff])
    ));
    $history = [];
    foreach ($rows as $r) {
        $history[$r->stock_id][] = [
            'date'           => $r->d,
            'price'          => (float) $r->price,
            'previous_close' => (float) $r->previous_close,
        ];
    }
    return $history;
}

// --------------------------------------------------
// 四季報情報の1行目「［ ］」内の文字列をセクター名として抽出
// 例: 9519　(株)レノバ　れのば　［ 電気・ガス業 ］ → 電気・ガス業
// --------------------------------------------------
function wp_stocks_extract_sector_from_shikiho($shikiho) {
    if (empty($shikiho)) return '';
    $lines      = preg_split('/\r\n|\r|\n/', $shikiho);
    $first_line = trim($lines[0] ?? '');
    if (preg_match('/[\[［]\s*([^\]］]+?)\s*[\]］]/u', $first_line, $m)) {
        $sector = trim($m[1]);
        // 全角スペース等の除去
        $sector = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $sector);
        return $sector;
    }
    return '';
}


// --------------------------------------------------
// TradingView Symbol Overview ウィジェット
// --------------------------------------------------
function wp_stocks_tradingview_widget($code, $height = 450, $is_usd = false) {
    if ($is_usd) {
        $tv_url = 'https://jp.tradingview.com/chart/?symbol=' . urlencode($code);
        $yf_url = 'https://finance.yahoo.co.jp/quote/' . urlencode($code) . '/chart';
        $kb_url = 'https://kabutan.jp/us/stock/chart?code=' . urlencode($code);
        ob_start();
        ?>
    <div style="margin-bottom:20px;background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:16px;">
        <h4 style="margin:0 0 12px 0;font-size:13px;color:#555;">&#x1F4CA; 外部チャートで確認する</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?php echo esc_url($tv_url); ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;background:#2962ff;color:#fff;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;">
                &#x1F4CA; TradingView
            </a>
            <a href="<?php echo esc_url($yf_url); ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;background:#ff0033;color:#fff;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;">
                &#x1F4C8; Yahoo! Finance
            </a>
            <a href="<?php echo esc_url($kb_url); ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;background:#f47f1a;color:#fff;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;">
                &#x1F4C9; 株探（米国）
            </a>
        </div>
    </div>
        <?php
        return ob_get_clean();
    } else {
        $tv_url = 'https://jp.tradingview.com/chart/?symbol=' . urlencode('TSE:' . $code);
        $yf_url = 'https://finance.yahoo.co.jp/quote/' . $code . '.T/chart';
        $kb_url = 'https://kabutan.jp/stock/chart?code=' . $code;
        ob_start();
        ?>
    <div style="margin-bottom:20px;background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:16px;">
        <h4 style="margin:0 0 12px 0;font-size:13px;color:#555;">&#x1F4CA; 外部チャートで確認する</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?php echo esc_url($tv_url); ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;background:#2962ff;color:#fff;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;">
                &#x1F4CA; TradingView
            </a>
            <a href="<?php echo esc_url($yf_url); ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;background:#ff0033;color:#fff;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;">
                &#x1F4C8; Yahoo!ファイナンス
            </a>
            <a href="<?php echo esc_url($kb_url); ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;background:#f47f1a;color:#fff;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;">
                &#x1F4C9; 株探
            </a>
            <a href="https://www.asset-alive.com/tech/code2.php?code=<?php echo esc_attr($code); ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;background:#27ae60;color:#fff;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;">
                &#x1F4C8; Asset Alive
            </a>
        </div>
    </div>
        <?php
        return ob_get_clean();
    }
}


// --------------------------------------------------
// 企業情報取得
// --------------------------------------------------

function wp_stocks_get_company_info($symbol) {
    $auth = wp_stocks_get_crumb();
    if (!$auth) return false;

    $headers = [
        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept'          => 'application/json',
        'Accept-Language' => 'ja,en;q=0.9',
        'Cookie'          => $auth['cookie'],
    ];

    $modules  = 'assetProfile,defaultKeyStatistics,financialData,summaryDetail,calendarEvents';
    $url      = 'https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . $symbol . '?modules=' . $modules . '&crumb=' . urlencode($auth['crumb']);
    $response = wp_remote_get($url, ['headers' => $headers, 'timeout' => 20]);

    if (is_wp_error($response)) return false;

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        delete_transient('wp_stocks_yahoo_crumb');
        $auth = wp_stocks_get_crumb();
        if (!$auth) return false;
        $url      = 'https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . $symbol . '?modules=' . $modules . '&crumb=' . urlencode($auth['crumb']);
        $response = wp_remote_get($url, ['headers' => array_merge($headers, ['Cookie' => $auth['cookie']]), 'timeout' => 20]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return false;
    }

    $body    = json_decode(wp_remote_retrieve_body($response), true);
    $result  = $body['quoteSummary']['result'][0] ?? null;
    if (!$result) return false;

    $profile  = $result['assetProfile']         ?? [];
    $stats    = $result['defaultKeyStatistics']  ?? [];
    $fin      = $result['financialData']         ?? [];
    $summary  = $result['summaryDetail']         ?? [];
    $calendar = $result['calendarEvents']        ?? [];

    $de = floatval($stats['debtToEquity']['raw'] ?? 0);
    if ($de > 0) {
        $equity_ratio = round(1 / (1 + $de / 100) * 100, 1);
    } else {
        $bv  = floatval($stats['bookValue']['raw']         ?? 0);
        $shr = floatval($stats['sharesOutstanding']['raw'] ?? 0);
        $mc  = floatval($summary['marketCap']['raw']       ?? 0);
        $equity_ratio = ($bv > 0 && $shr > 0 && $mc > 0) ? round($bv * $shr / $mc * 100, 1) : 0;
    }

    $earnings_date = null;
    if (!empty($calendar['earnings']['earningsDate'][0]['raw'])) {
        $earnings_date = date('Y-m-d', $calendar['earnings']['earningsDate'][0]['raw']);
    }

    // トレーリングPEG（実績PER ÷ 実績利益成長率）を別途算出
    // ※'peg'（Yahoo由来）はフォワード予想ベースのことが多く、
    //   両者は計算方法が異なるため別カラムとして両方保持する
    $per_for_peg_trailing    = floatval($summary['trailingPE']['raw'] ?? 0);
    $growth_for_peg_trailing = floatval($fin['earningsGrowth']['raw'] ?? 0) * 100;
    $peg_trailing = 0;
    if ($per_for_peg_trailing > 0 && $growth_for_peg_trailing > 0) {
        $peg_trailing = round($per_for_peg_trailing / $growth_for_peg_trailing, 2);
    }

    $result = [
        'market'          => $profile['exchange']                      ?? '',
        'sector'          => wp_stocks_sector_ja($profile['sector'] ?? ''),
        'industry'        => $profile['industry']                      ?? '',
        'website'         => $profile['website']                       ?? '',
        'employees'       => intval($profile['fullTimeEmployees']      ?? 0),
        'revenue'         => intval($fin['totalRevenue']['raw']        ?? 0),
        'net_income'      => intval($fin['netIncomeToCommon']['raw']   ?? 0),
        'profit_margin'   => floatval($fin['profitMargins']['raw']     ?? 0) * 100,
        'revenue_growth'  => floatval($fin['revenueGrowth']['raw']     ?? 0) * 100,
        'earnings_growth' => floatval($fin['earningsGrowth']['raw']    ?? 0) * 100,
        'per'             => floatval($summary['trailingPE']['raw']    ?? 0),
        'forward_per'     => floatval($summary['forwardPE']['raw']     ?? 0),
        'pbr'             => floatval($stats['priceToBook']['raw']      ?? 0),
        'eps'             => floatval($stats['trailingEps']['raw']      ?? 0),
        'forward_eps'     => floatval($stats['forwardEps']['raw']       ?? 0),
        'roe'             => floatval($fin['returnOnEquity']['raw']     ?? 0) * 100,
        'roa'             => floatval($fin['returnOnAssets']['raw']     ?? 0) * 100,
        'peg'             => floatval($stats['pegRatio']['raw']         ?? 0),
        'peg_trailing'    => $peg_trailing,
        'dividend_yield'  => floatval($summary['dividendYield']['raw'] ?? $summary['trailingAnnualDividendYield']['raw'] ?? 0) * 100,
        'market_cap'      => intval($summary['marketCap']['raw']       ?? 0),
        'equity_ratio'    => $equity_ratio,
        'earnings_date'   => $earnings_date,
        'ipo_year'        => '',
    ];

    // --------------------------------------------------
    // 米国株：財務スコアカード関連の値をFMPで置き換え（yfinance/Yahoo脱却）
    // FMP取得に失敗した場合は上記のYahoo由来の値をそのままフォールバックとして使う
    // --------------------------------------------------
    $is_us_symbol = (strpos($symbol, '.T') === false);
    if ($is_us_symbol) {
        $fmp = wp_stocks_fmp_get_ratios($symbol);
        if ($fmp !== false) {
            $result['per']            = $fmp['per'];
            $result['pbr']            = $fmp['pbr'];
            $result['eps']            = $fmp['eps'];
            $result['profit_margin']  = $fmp['profit_margin'];
            $result['dividend_yield'] = $fmp['dividend_yield'];
            $result['peg_trailing']   = $fmp['peg_trailing'];
            $result['peg']            = $fmp['peg'];
            $result['roe']            = $fmp['roe'];
            $result['roa']            = $fmp['roa'];
            if ($fmp['equity_ratio'] > 0) $result['equity_ratio'] = $fmp['equity_ratio'];
            if ($fmp['market_cap'] > 0) $result['market_cap'] = $fmp['market_cap'];
        } else {
            wp_stocks_log('error', 'fmp_ratios', $symbol, 'FMPからの財務指標取得に失敗、Yahoo由来の値にフォールバック');
        }

        $earnings_dates = wp_stocks_fmp_get_earnings_dates();
        if (!empty($earnings_dates[$symbol])) {
            $result['earnings_date'] = $earnings_dates[$symbol];
        }
    }

    return $result;
}


// --------------------------------------------------
// 財務スコアカード計算
// --------------------------------------------------
// --------------------------------------------------
// 適正株価計算用: 同一セクター・同一通貨圏の平均PER（自分自身は除外）
// セクターが空、または比較対象が1件も無い場合は null を返す
// --------------------------------------------------
function wp_stocks_get_sector_avg_per($sector, $is_usd, $exclude_stock_id = null, $use_forward = false, $market = null) {
    global $wpdb;
    if (empty($sector)) return null;

    // 日本株・実績PERベースで市場区分が分かっている場合は、JPX公式の業種別PERを優先する
    if (!$is_usd && !$use_forward && !empty($market)) {
        $jpx_per = wp_stocks_get_jpx_sector_per($sector, $market);
        if ($jpx_per !== null) return $jpx_per;
    }

    // フォールバック: 登録銘柄同士の平均PER（従来ロジック。予想PER・米国株・市場区分未設定はこちら）
    $per_column         = $use_forward ? 'forward_per' : 'per';
    $currency_condition = $is_usd ? "currency = 'USD'" : "(currency = 'JPY' OR currency IS NULL OR currency = '')";

    $sql    = "SELECT AVG($per_column) FROM {$wpdb->prefix}stocks WHERE sector = %s AND $currency_condition AND $per_column > 0";
    $params = [$sector];
    if ($exclude_stock_id !== null) {
        $sql     .= " AND id != %d";
        $params[] = $exclude_stock_id;
    }
    $avg = $wpdb->get_var($wpdb->prepare($sql, ...$params));
    return $avg !== null ? floatval($avg) : null;
}

// --------------------------------------------------
// DB保存系関数
// --------------------------------------------------
function wp_stocks_save_company_info($stock_id, $symbol) {
    global $wpdb;
    $info = wp_stocks_get_company_info($symbol);
    if (!$info) {
        wp_stocks_log('error', 'company_info', $symbol, '企業情報の取得に失敗');
        return false;
    }
    $code = str_replace('.T', '', $symbol);
    // Yahoo!ファイナンスJapanスクレイピング無効化（IPブロック対策）
    $info['info_updated_at'] = current_time('mysql');

    // 四季報や手動編集で既にセクターが設定済みの場合はYahooの値で上書きしない
    // （週次Cron等で英語セクターに戻ってしまう問題への対処）
    $existing_sector = $wpdb->get_var($wpdb->prepare(
        "SELECT sector FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id
    ));
    if (!empty($existing_sector)) {
        unset($info['sector']);
    }

    $result = $wpdb->update($wpdb->prefix . 'stocks', $info, ['id' => $stock_id]) !== false;
    if ($result) wp_stocks_log('info', 'company_info', $symbol, '企業情報を更新しました');
    return $result;
}

function wp_stocks_save_price($stock_id, $symbol) {
    global $wpdb;
    $data = wp_stocks_get_price($symbol);
    if (!$data || !isset($data['c'])) {
        wp_stocks_log('error', 'get_price', $symbol, '株価保存失敗');
        return false;
    }
    $today  = current_time('Y-m-d');
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d AND DATE(datetime) = %s", $stock_id, $today
    ));

    // previous_close はAPIの値ではなくDBの直前レコードのpriceを使用
    // （APIのchartPreviousCloseは数日前の値が入ることがあるため）
    $prev_record = $wpdb->get_row($wpdb->prepare(
        "SELECT price FROM {$wpdb->prefix}stock_prices
         WHERE stock_id = %d AND DATE(datetime) < %s
         ORDER BY datetime DESC LIMIT 1",
        $stock_id, $today
    ));
    $previous_close = $prev_record ? floatval($prev_record->price) : $data['previous_close'];

    $row = [
        'price'          => $data['c'],
        'previous_close' => $previous_close,
        'volume'         => $data['volume'],
        'datetime'       => current_time('mysql'),
    ];
    if ($exists) {
        $result = $wpdb->update($wpdb->prefix . 'stock_prices', $row, ['id' => $exists]) !== false;
    } else {
        $row['stock_id'] = $stock_id;
        $result = $wpdb->insert($wpdb->prefix . 'stock_prices', $row) !== false;
    }
    if ($result) wp_stocks_log('info', 'get_price', $symbol, '株価を保存しました: ' . number_format($data['c']) . '円');
    return $result;
}

function wp_stocks_log($level, $action, $symbol, $message) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'stock_logs', [
        'level'      => $level,
        'action'     => $action,
        'symbol'     => $symbol,
        'message'    => $message,
        'created_at' => current_time('mysql'),
    ]);
}

function wp_stocks_get_holidays() {
    $cached = get_transient('wp_stocks_holidays');
    if ($cached !== false) return $cached;
    $url      = 'https://www8.cao.go.jp/chosei/shukujitsu/syukujitsu.csv';
    $response = wp_remote_get($url, ['timeout' => 15, 'headers' => ['User-Agent' => 'Mozilla/5.0']]);
    if (is_wp_error($response)) return [];
    $body     = mb_convert_encoding(wp_remote_retrieve_body($response), 'UTF-8', 'Shift-JIS');
    $holidays = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $cols = explode(',', $line);
        $date = trim($cols[0]);
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $date, $m)) {
            $holidays[] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
    }
    set_transient('wp_stocks_holidays', $holidays, 24 * HOUR_IN_SECONDS);
    return $holidays;
}

// --------------------------------------------------
// USD/JPY 為替レート取得（キャッシュ1時間）
// --------------------------------------------------

// --------------------------------------------------
// Yahoo Finance 四半期財務データ取得
// --------------------------------------------------
function wp_stocks_fetch_quarterly_financials($stock_id, $symbol) {
    global $wpdb;
    $script     = __DIR__ . '/scripts/fetch_quarterly.py';
    $pythonpath = __DIR__ . '/scripts/vendor';
    $cmd = 'PYTHONPATH=' . escapeshellarg($pythonpath) . ' python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($symbol) . ' 2>&1';
    $output = shell_exec($cmd);
    if ($output === null) {
        wp_stocks_log('error', 'fetch_quarterly', $symbol, 'shell_execが実行できませんでした（サーバー設定でshell_execが無効化されている可能性があります）');
        return false;
    }
    $rows = json_decode(trim($output), true);
    if (!is_array($rows)) {
        wp_stocks_log('error', 'fetch_quarterly', $symbol, 'Pythonスクリプトの出力をJSONとして解釈できませんでした: ' . substr($output, 0, 300));
        return false;
    }
    if (isset($rows['error'])) {
        wp_stocks_log('error', 'fetch_quarterly', $symbol, 'Pythonスクリプトエラー: ' . $rows['error']);
        return false;
    }
    if (empty($rows)) {
        wp_stocks_log('error', 'fetch_quarterly', $symbol, '四半期データが取得できませんでした（yfinance側にデータが無い可能性があります）');
        return false;
    }
    $saved = 0;
    foreach ($rows as $row) {
        $period_end = $row['period_end'] ?? null;
        if (!$period_end) continue;
        $revenue    = $row['revenue']    ?? null;
        $net_income = $row['net_income'] ?? null;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND period_end = %s AND source = 'yahoo'",
            $stock_id, $period_end
        ));
        $data = ['stock_id' => $stock_id, 'period_end' => $period_end, 'revenue' => $revenue, 'net_income' => $net_income, 'source' => 'yahoo'];
        if ($existing) {
            $wpdb->update($wpdb->prefix . 'stock_quarterly_financials', $data, ['id' => $existing]);
        } else {
            $wpdb->insert($wpdb->prefix . 'stock_quarterly_financials', $data);
        }
        $saved++;
    }
    wp_stocks_log('info', 'fetch_quarterly', $symbol, $saved . '四半期分のデータを保存しました');
    return $saved > 0;
}



function wp_stocks_get_usd_jpy() {
    $manual = floatval(get_option('wp_stocks_usd_jpy_manual', 0));
    if ($manual > 0) return $manual;

    $cached = get_transient('wp_stocks_usd_jpy');
    if ($cached !== false) return floatval($cached);

    // ExchangeRate-API Open Access（APIキー不要・日次更新）
    // 利用規約により出典表示が必要: https://www.exchangerate-api.com/docs/free
    $url = 'https://open.er-api.com/v6/latest/USD';
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'wp-stocks-manager/1.0 (' . home_url() . ')'],
        'timeout' => 10,
    ]);
    if (is_wp_error($response)) return 150.0;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $rate = $body['rates']['JPY'] ?? 0;
    if ($rate > 0) {
        // Open Accessは日次更新のため、頻繁な再取得を避けてキャッシュを24時間に延長
        set_transient('wp_stocks_usd_jpy', $rate, DAY_IN_SECONDS);
        return floatval($rate);
    }
    return 150.0;
}

function wp_stocks_change_html($price, $previous_close, $is_usd = false) {
    if (!$previous_close || $previous_close <= 0) return '<span style="color:#888;">-</span>';
    $change     = $price - $previous_close;
    $change_pct = $previous_close > 0 ? ($change / $previous_close * 100) : 0;
    $color      = $change >= 0 ? '#e74c3c' : '#3498db';
    $arrow      = $change >= 0 ? '▲' : '▼';
    if ($is_usd) {
        // USD: ▲+$1.23
        $fmt = number_format(abs($change), 2);
        $change_str = $arrow . ($change >= 0 ? '+' : '-') . '$' . $fmt;
    } else {
        // JPY: ▲+813円
        $fmt = number_format(abs($change));
        $change_str = $arrow . ($change >= 0 ? '+' : '-') . $fmt . '円';
    }
    return '<span style="color:' . $color . ';font-weight:bold;">'
        . $change_str
        . ' (' . ($change >= 0 ? '+' : '') . number_format($change_pct, 2) . '%)'
        . '</span>';
}


// --------------------------------------------------
// RSS取得・保存ヘルパー
// --------------------------------------------------
function wp_stocks_save_rss_items($stock_id, $rss_url, $default_publisher) {
    global $wpdb;
    $response = wp_remote_get($rss_url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'Accept' => 'application/rss+xml, application/xml, text/xml', 'Accept-Language' => 'ja'],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return 0;
    $xml_str = wp_remote_retrieve_body($response);
    if (empty($xml_str)) return 0;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_str);
    if (!$xml) return 0;
    $items = $xml->channel->item ?? [];
    if (empty($items)) return 0;

    $saved = 0;
    $exclude_patterns = [
        '/：株価チャート/', '/：掲示板/', '/：企業情報/', '/：配当情報/',
        '/：株価・株式情報/', '/：アナリストレポート/', '/：決算情報/',
        '/株価チャートをみ/', '/株価チャートを確認/', '/株価チャートも見/',
        '/ここ1年の株価/', '/1年間の株価チャート/', '/の掲示板\s+\d{4}/',
        '/^No\.\d+/', '/終値は\d+円/', '/前日比[▲+\-]?\d/',
        '/配当利回りは\d/', '/株価は前日比/', '/今の株価の理由/',
    ];

    foreach ($items as $item) {
        $title = trim((string)($item->title ?? ''));
        $link  = trim((string)($item->link  ?? ''));
        $desc  = (string)($item->description ?? '');
        $pub   = (string)($item->pubDate ?? '');
        if (empty($title) || empty($link)) continue;
        $skip = false;
        foreach ($exclude_patterns as $pattern) {
            if (preg_match($pattern, $title)) { $skip = true; break; }
        }
        if ($skip) continue;
        $uuid   = md5($link);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}stock_news WHERE uuid = %s", $uuid));
        if ($exists) continue;
        $published_at = !empty($pub) ? date('Y-m-d H:i:s', strtotime($pub)) : null;
        if ($published_at) {
            if ($published_at < date('Y-m-d H:i:s', strtotime('-3 months'))) continue;
        }
        $thumbnail = '';
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $desc, $m)) $thumbnail = $m[1];
        $wpdb->insert($wpdb->prefix . 'stock_news', [
            'stock_id'     => $stock_id,
            'uuid'         => $uuid,
            'title'        => $title,
            'publisher'    => $default_publisher,
            'link'         => $link,
            'thumbnail'    => $thumbnail,
            'published_at' => $published_at,
        ]);
        $saved++;
    }
    return $saved;
}

function wp_stocks_fetch_news($stock_id, $symbol, $company_name) {
    $code  = str_replace('.T', '', $symbol);
    $saved = wp_stocks_save_rss_items($stock_id, 'https://webapi.yanoshin.jp/webapi/tdnet/list/' . $code . '.rss', 'TDnet適時開示');
    if ($saved > 0) wp_stocks_log('info', 'fetch_news', $symbol, $saved . '件の適時開示を保存しました');
    return $saved;
}


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
// マーケット情報ページ本体（ニュース／指数／適時開示タブ）
// --------------------------------------------------
// --------------------------------------------------
// マーケット情報「ニュース」タブ用：汎用RSS取得（15分キャッシュ）
// --------------------------------------------------
function wp_stocks_fetch_generic_rss($url, $limit = 20) {
    $cache_key = 'wp_stocks_news_rss_' . md5($url);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $response = wp_remote_get($url, [
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept'     => 'application/rss+xml, application/xml, text/xml',
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), '通信エラー: ' . $response->get_error_message() . ' / URL: ' . $url);
        return [];
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $xml_str   = wp_remote_retrieve_body($response);

    if ($http_code !== 200) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'HTTPエラー: ステータスコード ' . $http_code . ' / URL: ' . $url . ' / レスポンス先頭200文字: ' . substr((string)$xml_str, 0, 200));
        return [];
    }

    if (empty($xml_str)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'レスポンスが空でした（HTTP ' . $http_code . '） / URL: ' . $url);
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_str);
    if (!$xml) {
        $xml_errors = libxml_get_errors();
        $err_msg    = !empty($xml_errors) ? trim($xml_errors[0]->message) : '不明なXMLパースエラー';
        libxml_clear_errors();
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'XMLパース失敗: ' . $err_msg . ' / URL: ' . $url . ' / レスポンス先頭200文字: ' . substr($xml_str, 0, 200));
        return [];
    }
    if (!isset($xml->channel->item)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'RSS形式として解釈できましたが channel/item が見つかりません / URL: ' . $url . ' / レスポンス先頭200文字: ' . substr($xml_str, 0, 200));
        return [];
    }

    $result = [];
    $count  = 0;
    foreach ($xml->channel->item as $item) {
        if ($count >= $limit) break;
        $title = trim((string)($item->title ?? ''));
        $link  = trim((string)($item->link  ?? ''));
        $pub   = (string)($item->pubDate ?? '');
        $desc  = trim(strip_tags((string)($item->description ?? '')));
        if (empty($title) || empty($link)) continue;
        $result[] = [
            'title'       => $title,
            'link'        => $link,
            'pub_date'    => $pub ? date('Y/m/d H:i', strtotime($pub)) : '',
            'description' => $desc,
        ];
        $count++;
    }

    if (empty($result)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'XMLは解析できましたが有効な記事が0件でした（title/linkが空の項目のみ、または0件のitem） / URL: ' . $url);
    }

    set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
    return $result;
}

function wp_stocks_render_news_feed_list($url, $limit = 20) {
    $items = wp_stocks_fetch_generic_rss($url, $limit);
    if (empty($items)) {
        echo '<p style="color:#888;padding:20px 0;">ニュースを取得できませんでした（フィードが空、または更新が止まっている可能性があります）。</p>';
        return;
    }
    echo '<div style="display:flex;flex-direction:column;gap:12px;max-width:900px;">';
    foreach ($items as $it) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 15px;">';
        if (!empty($it['pub_date'])) {
            echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . esc_html($it['pub_date']) . '</div>';
        }
        echo '<a href="' . esc_url($it['link']) . '" target="_blank" rel="noopener" style="font-size:14px;font-weight:bold;color:#0073aa;text-decoration:none;">' . esc_html($it['title']) . '</a>';
        if (!empty($it['description'])) {
            echo '<div style="font-size:12px;color:#666;margin-top:4px;">' . esc_html($it['description']) . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function wp_stocks_market_info_page() {
    $active_tab = sanitize_text_field($_GET['mtab'] ?? 'news');
    if (!in_array($active_tab, ['news', 'index', 'heatmap', 'disclosure'], true)) $active_tab = 'news';

    echo '<div class="wrap"><h1>&#x1F30D; マーケット情報</h1>';

    $tab_defs = [
        'news'       => '&#x1F4F0; ニュース',
        'index'      => '&#x1F4CA; 指数',
        'heatmap'    => '&#x1F321; ヒートマップ',
        'disclosure' => '&#x1F4CB; 適時開示',
    ];
    echo '<ul style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0 0 20px 0;padding:0;list-style:none;flex-wrap:wrap;">';
    foreach ($tab_defs as $key => $label) {
        $is_active = $active_tab === $key;
        $tab_url   = admin_url('admin.php?page=wp-stocks-market&mtab=' . $key);
        $style = $is_active
            ? 'display:block;padding:10px 18px;background:#0073aa;color:#fff;text-decoration:none;font-size:13px;font-weight:bold;border-radius:4px 4px 0 0;'
            : 'display:block;padding:10px 18px;background:#f1f1f1;color:#555;text-decoration:none;font-size:13px;border-radius:4px 4px 0 0;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="' . esc_url($tab_url) . '" style="' . $style . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    if ($active_tab === 'news') {
        $news_sources = [];
        for ($news_i = 1; $news_i <= 10; $news_i++) {
            $news_label = get_option('wp_stocks_news_rss_label_' . $news_i, '');
            $news_url   = get_option('wp_stocks_news_rss_url_' . $news_i, '');
            if (!empty($news_label) && !empty($news_url)) {
                $news_sources['slot' . $news_i] = ['label' => $news_label, 'url' => $news_url];
            }
        }

        if (empty($news_sources)) {
            $settings_url = admin_url('admin.php?page=wp-stocks-settings');
            echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:40px;text-align:center;color:#888;">';
            echo '<p style="font-size:14px;">&#x1F4F0; まだニュースRSSが登録されていません。<a href="' . esc_url($settings_url) . '">設定ページ</a>から登録してください（最大10件）。</p>';
            echo '</div>';
        } else {
            $ntab = sanitize_text_field($_GET['ntab'] ?? '');
            if (!array_key_exists($ntab, $news_sources)) {
                $news_keys = array_keys($news_sources);
                $ntab = $news_keys[0];
            }

            echo '<div style="margin-bottom:15px;display:flex;gap:8px;flex-wrap:wrap;">';
            foreach ($news_sources as $key => $src) {
                $is_ntab_active = $ntab === $key;
                $ntab_url = admin_url('admin.php?page=wp-stocks-market&mtab=news&ntab=' . $key);
                echo '<a href="' . esc_url($ntab_url) . '" style="padding:6px 16px;border-radius:4px;text-decoration:none;font-size:13px;font-weight:bold;'
                    . ($is_ntab_active ? 'background:#0073aa;color:#fff;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;') . '">'
                    . esc_html($src['label']) . '</a>';
            }
            echo '</div>';

            wp_stocks_render_news_feed_list($news_sources[$ntab]['url'], 20);
        }
    } elseif ($active_tab === 'index') {
        wp_stocks_render_market_indices();
    } elseif ($active_tab === 'heatmap') {
        wp_stocks_sector_page(true, admin_url('admin.php?page=wp-stocks-market&mtab=heatmap'));
    } elseif ($active_tab === 'disclosure') {
        wp_stocks_render_recent_news_widget(30);
    }

    echo '</div>';
}

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


// --------------------------------------------------
// admin-post アクション群
// --------------------------------------------------

// --------------------------------------------------
// admin_post_* ハンドラ共通: 権限チェック＋nonce検証
// --------------------------------------------------
function wp_stocks_require_admin_action($nonce_action) {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer($nonce_action);
}

add_action('admin_post_get_stock_price', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id    = intval($_GET['id'] ?? 0);
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $symbol = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $result = wp_stocks_save_price($id, $symbol);
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=' . ($result ? 'price_saved' : 'price_error')));
    exit;
});


add_action('admin_post_wp_stocks_webull_test_quotes_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test');

    $host     = 'api.webull.co.jp';
    $symbol   = sanitize_text_field($_GET['symbol'] ?? '7203');
    $category = sanitize_text_field($_GET['category'] ?? 'JP_STOCK');

    header('Content-Type: text/plain; charset=utf-8');

    // --------------------------------------------------
    // Step1: Get Instruments (/instrument/list) で instrument_id を取得
    // --------------------------------------------------
    $path1  = '/instrument/list';
    $query1 = array(
        'symbols'  => $symbol,
        'category' => $category,
    );
    $signed1  = wp_stocks_webull_sign_request('GET', $path1, $query1, '', $host);
    $headers1 = $signed1['headers'];
    $headers1['Accept'] = 'application/json';

    $url1 = 'https://' . $host . $path1 . '?' . http_build_query($query1);
    $res1 = wp_remote_get($url1, array('headers' => $headers1, 'timeout' => 30));

    if (is_wp_error($res1)) {
        echo "Step1(Get Instruments) 通信エラー: " . $res1->get_error_message();
        exit;
    }

    $status1 = wp_remote_retrieve_response_code($res1);
    $body1   = wp_remote_retrieve_body($res1);

    echo "=== Step1: Get Instruments ({$symbol} / {$category}) ===\n";
    echo "HTTP Status: {$status1}\n";
    echo $body1 . "\n\n";

    $instruments   = json_decode($body1, true);
    $instrument_id = '';
    if (is_array($instruments) && isset($instruments[0]['instrument_id'])) {
        $instrument_id = $instruments[0]['instrument_id'];
    }

    if (empty($instrument_id)) {
        echo "instrument_idが取得できなかったため、Step2(End-of-day Market)はスキップします。";
        exit;
    }

    // --------------------------------------------------
    // Step2: End-of-day Market (/market-data/eod-bars) で日足を取得
    // --------------------------------------------------
    $path2  = '/market-data/eod-bars';
    $query2 = array(
        'instrument_ids' => $instrument_id,
        'count'          => '10',
    );
    $signed2  = wp_stocks_webull_sign_request('GET', $path2, $query2, '', $host);
    $headers2 = $signed2['headers'];
    $headers2['Accept'] = 'application/json';

    $url2 = 'https://' . $host . $path2 . '?' . http_build_query($query2);
    $res2 = wp_remote_get($url2, array('headers' => $headers2, 'timeout' => 30));

    if (is_wp_error($res2)) {
        echo "Step2(End-of-day Market) 通信エラー: " . $res2->get_error_message();
        exit;
    }

    $status2 = wp_remote_retrieve_response_code($res2);
    $body2   = wp_remote_retrieve_body($res2);

    echo "=== Step2: End-of-day Market (instrument_id={$instrument_id}) ===\n";
    echo "HTTP Status: {$status2}\n";
    echo $body2;
    exit;
});


add_action('admin_post_wp_stocks_webull_test_single_bar_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test');

    $host     = 'api.webull.co.jp';
    $path     = '/openapi/market-data/stock/bars';
    $symbol   = sanitize_text_field($_GET['symbol'] ?? '7203');
    $category = sanitize_text_field($_GET['category'] ?? 'JP_STOCK');

    $query = array(
        'symbol'   => $symbol,
        'category' => $category,
        'timespan' => 'D',
        'count'    => '10',
    );

    $signed  = wp_stocks_webull_sign_request('GET', $path, $query, '', $host);
    $headers = $signed['headers'];
    $headers['Accept'] = 'application/json';

    $url = 'https://' . $host . $path . '?' . http_build_query($query);
    $response = wp_remote_get($url, array('headers' => $headers, 'timeout' => 30));

    header('Content-Type: text/plain; charset=utf-8');

    if (is_wp_error($response)) {
        echo "通信エラー: " . $response->get_error_message();
        exit;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);

    echo "URL: {$url}\n\n";
    echo "HTTP Status: {$status_code}\n\n";
    echo $raw_body;
    exit;
});

add_action('admin_post_wp_stocks_webull_test_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test');

    // 本番環境
    $host   = 'api.webull.co.jp';
    $path   = '/market-data/stocks/bars/list';
    $symbol = sanitize_text_field($_GET['symbol'] ?? '7203');
    $category = sanitize_text_field($_GET['category'] ?? 'JP_STOCK');
    $body = wp_json_encode(array(
        'symbols'            => array($symbol),
        'category'           => $category,
        'timespan'           => 'D',
        'count'              => 10,
        'real_time_required' => false,
    ));

    $signed  = wp_stocks_webull_sign_request('POST', $path, array(), $body, $host, 'v3', 'HMAC-SHA1');
    $headers = $signed['headers'];
    $headers['Content-Type'] = 'application/json';
    $headers['Accept']       = 'application/json';

    $response = wp_remote_post('https://' . $host . $path, array(
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 30,
    ));

    header('Content-Type: text/plain; charset=utf-8');

    if (is_wp_error($response)) {
        echo "通信エラー: " . $response->get_error_message();
        exit;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);

    echo "HTTP Status: {$status_code}\n\n";
    echo $raw_body;
    exit;
});

add_action('admin_post_wp_stocks_webull_yahoo_compare', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_yahoo_compare');

    $symbol   = sanitize_text_field($_GET['symbol']   ?? 'AAPL');
    $category = sanitize_text_field($_GET['category'] ?? 'US_STOCK');
    $count    = max(5, min(200, intval($_GET['count'] ?? 15)));

    // Yahoo Finance用シンボル：日本株ならcodeに.Tを付与、米国株はそのまま
    $yahoo_symbol = ($category === 'JP_STOCK') ? $symbol . '.T' : $symbol;

    $webull_bars = wp_stocks_webull_fetch_daily_bars($symbol, $category, $count);
    $yahoo_bars  = wp_stocks_get_ohlcv($yahoo_symbol); // 6ヶ月分、6時間キャッシュ

    header('Content-Type: text/html; charset=utf-8');

    echo '<html><head><meta charset="utf-8"><title>Webull vs Yahoo 突き合わせ: ' . esc_html($symbol) . '</title>';
    echo '<style>
        body { font-family: sans-serif; padding: 20px; }
        table { border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: right; }
        th { background: #f0f0f0; }
        td.date { text-align: left; font-weight: bold; }
        td.diff { color: #e74c3c; font-weight: bold; background: #fff0f0; }
        td.match { color: #27ae60; }
        td.missing { color: #999; background: #f5f5f5; }
        h2 { margin-top: 30px; }
    </style></head><body>';

    echo '<h1>Webull vs Yahoo Finance 日足突き合わせ</h1>';
    echo '<p>Symbol: <strong>' . esc_html($symbol) . '</strong> / Category: <strong>' . esc_html($category) . '</strong> / Yahoo Symbol: <strong>' . esc_html($yahoo_symbol) . '</strong> / 件数: ' . intval($count) . '</p>';

    if ($webull_bars === false) {
        echo '<p style="color:red;">Webullからのデータ取得に失敗しました。ログをご確認ください。</p></body></html>';
        exit;
    }
    if (!$yahoo_bars) {
        echo '<p style="color:red;">Yahoo Financeからのデータ取得に失敗しました。</p></body></html>';
        exit;
    }

    // 日付をキーにしたマップを作成
    $webull_map = array();
    foreach ($webull_bars as $b) $webull_map[$b['date']] = $b;

    $yahoo_map = array();
    foreach ($yahoo_bars as $y) $yahoo_map[$y['date']] = $y;

    // Webull側の日付範囲に絞って比較（Webullの取得件数を基準にする）
    $target_dates = array_keys($webull_map);
    sort($target_dates);

    echo '<table>';
    echo '<tr>
        <th rowspan="2">日付</th>
        <th colspan="5">Webull</th>
        <th colspan="5">Yahoo Finance</th>
        <th rowspan="2">判定</th>
    </tr>';
    echo '<tr>
        <th>始値</th><th>高値</th><th>安値</th><th>終値</th><th>出来高</th>
        <th>始値</th><th>高値</th><th>安値</th><th>終値</th><th>出来高</th>
    </tr>';

    $tolerance = 0.05; // 価格の許容誤差（±5銭・仕様差異吸収用）

    foreach ($target_dates as $date) {
        $w = $webull_map[$date] ?? null;
        $y = $yahoo_map[$date]  ?? null;

        echo '<tr>';
        echo '<td class="date">' . esc_html($date) . '</td>';

        if ($w) {
            echo '<td>' . number_format($w['open'], 2) . '</td>';
            echo '<td>' . number_format($w['high'], 2) . '</td>';
            echo '<td>' . number_format($w['low'], 2) . '</td>';
            echo '<td>' . number_format($w['close'], 2) . '</td>';
            echo '<td>' . number_format($w['volume']) . '</td>';
        } else {
            echo '<td colspan="5" class="missing">Webullデータなし</td>';
        }

        if ($y) {
            echo '<td>' . number_format($y['open'], 2) . '</td>';
            echo '<td>' . number_format($y['high'], 2) . '</td>';
            echo '<td>' . number_format($y['low'], 2) . '</td>';
            echo '<td>' . number_format($y['close'], 2) . '</td>';
            echo '<td>' . number_format($y['volume']) . '</td>';
        } else {
            echo '<td colspan="5" class="missing">Yahooデータなし</td>';
        }

        if ($w && $y) {
            $diffs = array();
            foreach (array('open', 'high', 'low', 'close') as $field) {
                if (abs($w[$field] - $y[$field]) > $tolerance) {
                    $diffs[] = $field . ':' . number_format($w[$field] - $y[$field], 2);
                }
            }
            $vol_diff_pct = $y['volume'] > 0 ? abs($w['volume'] - $y['volume']) / $y['volume'] * 100 : 0;
            if ($vol_diff_pct > 5) {
                $diffs[] = 'volume差' . number_format($vol_diff_pct, 1) . '%';
            }
            if (empty($diffs)) {
                echo '<td class="match">一致</td>';
            } else {
                echo '<td class="diff">差異: ' . esc_html(implode(', ', $diffs)) . '</td>';
            }
        } else {
            echo '<td class="missing">比較不可</td>';
        }

        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
    exit;
});

add_action('admin_post_wp_stocks_webull_test_batch_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test_batch');

    $host        = 'api.webull.co.jp';
    $path        = '/market-data/stocks/bars/list';
    $symbols_raw = sanitize_text_field($_GET['symbols'] ?? 'AAPL');
    $symbols     = array_values(array_filter(array_map('trim', explode(',', $symbols_raw))));
    $category    = sanitize_text_field($_GET['category'] ?? 'US_STOCK');
    $count       = max(1, min(1200, intval($_GET['count'] ?? 10)));

    header('Content-Type: text/plain; charset=utf-8');

    if (empty($symbols)) {
        echo "銘柄が指定されていません。";
        exit;
    }

    $body = wp_json_encode(array(
        'symbols'            => $symbols,
        'category'           => $category,
        'timespan'           => 'D',
        'count'              => $count,
        'real_time_required' => false,
    ));

    $signed  = wp_stocks_webull_sign_request('POST', $path, array(), $body, $host, 'v3', 'HMAC-SHA1');
    $headers = $signed['headers'];
    $headers['Content-Type'] = 'application/json';
    $headers['Accept']       = 'application/json';

    echo "=== リクエスト ===\n";
    echo "銘柄数: " . count($symbols) . "\n";
    echo "銘柄一覧: " . implode(', ', $symbols) . "\n";
    echo "category: {$category} / count: {$count}\n";
    echo "Body:\n{$body}\n\n";

    $response = wp_remote_post('https://' . $host . $path, array(
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 30,
    ));

    if (is_wp_error($response)) {
        echo "=== 通信エラー ===\n" . $response->get_error_message();
        exit;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);

    echo "=== レスポンス ===\n";
    echo "HTTP Status: {$status_code}\n\n";
    echo $raw_body;

    // --- 診断：リクエストした銘柄のうち、レスポンスに含まれていなかったものを一覧表示 ---
    $decoded          = json_decode($raw_body, true);
    $returned_symbols = array();
    foreach (($decoded['result'] ?? array()) as $item) {
        if (!empty($item['symbol'])) $returned_symbols[] = $item['symbol'];
    }
    $missing = array_diff($symbols, $returned_symbols);

    echo "\n\n=== 診断 ===\n";
    echo "リクエストした銘柄数: " . count($symbols) . "\n";
    echo "レスポンスに含まれていた銘柄数: " . count($returned_symbols) . "\n";
    if (!empty($missing)) {
        echo "レスポンスに含まれていなかった銘柄: " . implode(', ', $missing) . "\n";
    } else {
        echo "リクエストした銘柄は全てレスポンスに含まれています。\n";
    }
    exit;
});

add_action('admin_post_update_company_info', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id    = intval($_GET['id'] ?? 0);
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $symbol   = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $result   = wp_stocks_save_company_info($id, $symbol);
    $redirect = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : 'wp-stocks-manager';
    wp_redirect(admin_url('admin.php?page=' . $redirect . '&message=' . ($result ? 'info_saved' : 'info_error') . '&stock_id=' . $id));
    exit;
});

add_action('admin_post_fetch_stock_news', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id    = intval($_GET['id'] ?? 0);
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) wp_die('銘柄が見つかりません');
    if (isset($_GET['reset']) && $_GET['reset'] === '1')
        $wpdb->delete($wpdb->prefix . 'stock_news', ['stock_id' => $id]);
    $sym_news = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $saved = wp_stocks_fetch_news($id, $sym_news, $stock->name);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&ctab=news&message=' . ($saved !== false ? 'news_saved' : 'news_error')));
    exit;
});

add_action('admin_post_clear_stock_news', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'stock_news', ['stock_id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&ctab=news&message=news_cleared'));
    exit;
});

add_action('admin_post_update_theme_tags', function() {
    wp_stocks_require_admin_action('wp_stocks_theme_tags_nonce');
    global $wpdb;
    $id   = intval($_POST['stock_id']);
    $tags = sanitize_text_field($_POST['theme_tags']);
    $wpdb->update($wpdb->prefix . 'stocks', ['theme_tags' => $tags], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=tags_saved'));
    exit;
});

add_action('admin_post_update_sector', function() {
    wp_stocks_require_admin_action('wp_stocks_sector_nonce');
    global $wpdb;
    $id     = intval($_POST['stock_id']);
    $sector = sanitize_text_field($_POST['stock_sector']);
    if ($id) {
        $wpdb->update($wpdb->prefix . 'stocks', ['sector' => $sector], ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=sector_saved'));
    exit;
});
add_action('admin_post_update_screening_flags', function() {
    wp_stocks_require_admin_action('wp_stocks_screening_nonce');
    global $wpdb;
    $id = intval($_POST['stock_id']);
    $wpdb->update($wpdb->prefix . 'stocks', [
        'screen_op_profit'  => isset($_POST['screen_op_profit'])  ? 1 : 0,
        'screen_net_income' => isset($_POST['screen_net_income']) ? 1 : 0,
        'screen_shikiho'    => isset($_POST['screen_shikiho'])    ? 1 : 0,
    ], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=screening_saved'));
    exit;
});
add_action('admin_post_update_market_segment', function() {
    wp_stocks_require_admin_action('wp_stocks_market_segment_nonce');
    global $wpdb;
    $id      = intval($_POST['stock_id']);
    $segment = sanitize_text_field($_POST['market_segment']);
    if (!in_array($segment, ['', 'プライム', 'スタンダード', 'グロース'], true)) $segment = '';
    if ($id) {
        $wpdb->update($wpdb->prefix . 'stocks', ['market' => $segment], ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=market_segment_saved'));
    exit;
});

add_action('admin_post_update_sector_override', function() {
    wp_stocks_require_admin_action('wp_stocks_sector_override_nonce');
    global $wpdb;
    $id       = intval($_POST['stock_id']);
    $override = sanitize_text_field($_POST['sector_override'] ?? '');
    $valid_sectors = [];
    foreach (wp_stocks_get_topix17_sector_map() as $topix17_info) {
        foreach ($topix17_info['sectors'] as $s33) $valid_sectors[] = $s33;
    }
    if ($override !== '' && !in_array($override, $valid_sectors, true)) $override = '';
    if ($id) {
        $wpdb->update($wpdb->prefix . 'stocks', ['sector_override' => $override], ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=sector_override_saved'));
    exit;
});

add_action('admin_post_update_stock_name', function() {
    wp_stocks_require_admin_action('wp_stocks_name_nonce');
    global $wpdb;
    $id   = intval($_POST['stock_id']);
    $name = sanitize_text_field($_POST['stock_name']);
    if ($id && !empty($name)) {
        $wpdb->update($wpdb->prefix . 'stocks', ['name' => $name], ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=name_saved'));
    exit;
});

add_action('admin_post_update_stock_code', function() {
    wp_stocks_require_admin_action('wp_stocks_code_nonce');
    global $wpdb;
    $id       = intval($_POST['stock_id']);
    $new_code = strtoupper(sanitize_text_field($_POST['stock_code'] ?? ''));

    if (!$id || empty($new_code)) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=code_error'));
        exit;
    }

    $duplicate = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}stocks WHERE code = %s AND id != %d", $new_code, $id
    ));
    if ($duplicate) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=code_duplicate'));
        exit;
    }

    $wpdb->update($wpdb->prefix . 'stocks', ['code' => $new_code], ['id' => $id]);
    wp_stocks_log('info', 'update_stock_code', $new_code, '銘柄コードを更新しました（stock_id=' . $id . '）');
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=code_saved'));
    exit;
});


add_action('admin_post_download_edinet_codelist', function() {
    wp_stocks_require_admin_action('wp_stocks_edinet_codelist_download_nonce');

    $content = wp_stocks_download_edinet_codelist();
    if (empty($content)) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }
    $n = wp_stocks_save_edinet_codelist_content($content, '自動ダウンロード');
    if ($n === false) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_saved&n=' . $n));
    exit;
});

add_action('admin_post_upload_edinet_codelist', function() {
    wp_stocks_require_admin_action('wp_stocks_edinet_codelist_nonce');

    if (empty($_FILES['edinet_codelist_file']['tmp_name'])) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }

    $tmp_path  = $_FILES['edinet_codelist_file']['tmp_name'];
    $orig_name = $_FILES['edinet_codelist_file']['name'] ?? '';
    $content   = '';

    if (preg_match('/\.zip$/i', $orig_name)) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp_path) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (stripos($name, '.csv') !== false) {
                        $content = $zip->getFromIndex($i);
                        break;
                    }
                }
                $zip->close();
            }
        }
        if (empty($content) && class_exists('PharData')) {
            try {
                $phar = new PharData($tmp_path);
                foreach (new RecursiveIteratorIterator($phar) as $file) {
                    if (stripos($file->getPathname(), '.csv') !== false) {
                        $content = file_get_contents($file->getPathname());
                        break;
                    }
                }
            } catch (Throwable $e) {
                wp_stocks_log('error', 'edinet_codelist', 'ALL', 'ZIP展開失敗: ' . $e->getMessage());
            }
        }
    } else {
        $content = file_get_contents($tmp_path);
    }

    if (empty($content)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'アップロードされたファイルからCSVを読み取れませんでした');
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }

    $parsed = wp_stocks_parse_edinet_codelist_csv($content);
    $map    = $parsed['edinet']  ?? [];
    $decmap = $parsed['decdate'] ?? [];
    if (empty($map)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'CSVのパースに失敗、または0件でした（フォーマットを確認してください）');
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }

    update_option('wp_stocks_edinet_codelist', $map, false);
    update_option('wp_stocks_edinet_decdate_map', $decmap, false);
    update_option('wp_stocks_edinet_codelist_updated_at', current_time('mysql'));
    wp_stocks_log('info', 'edinet_codelist', 'ALL', 'EDINETコードリストを更新しました（' . count($map) . '件、決算日情報' . count($decmap) . '件）');

    wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_saved&n=' . count($map)));
    exit;
});

add_action('admin_post_fetch_financials', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_fin_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $result = wp_stocks_fetch_financials($stock_id, $stock->code);
    $from   = sanitize_text_field($_GET['from'] ?? 'company');
    if ($from === 'finance') {
        $redirect = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&message=' . ($result ? 'fin_saved' : 'fin_error'));
    } else {
        $redirect = admin_url('admin.php?page=wp-stocks-manager&message=' . ($result ? 'fin_saved' : 'fin_error'));
    }
    wp_redirect($redirect);
    exit;
});
add_action('admin_post_fetch_quarterly_financials', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_qfin_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $symbol = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $result = wp_stocks_fetch_quarterly_financials($stock_id, $symbol);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=quarterly&message=' . ($result ? 'fin_saved' : 'fin_error')));
    exit;
});
add_action('admin_post_fetch_quarterly_financials_edgar', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_qfin_edgar_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $result = wp_stocks_fetch_quarterly_financials_edgar($stock_id, $stock->code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=quarterly&message=' . ($result ? 'fin_saved' : 'fin_error')));
    exit;
});
add_action('admin_post_wp_stocks_edgar_test_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_edgar_test');
    $symbol   = sanitize_text_field($_GET['symbol'] ?? 'AAPL');
    $stock_id = intval($_GET['stock_id'] ?? 0);

    header('Content-Type: text/plain; charset=utf-8');

    $cik = wp_stocks_sec_get_cik_for_symbol($symbol);
    if ($cik === false) {
        echo "CIKが見つかりませんでした: {$symbol}\n";
        exit;
    }
    echo "Symbol: {$symbol}\nCIK: {$cik}\n\n";

    $facts = wp_stocks_sec_get_companyfacts($cik);
    if ($facts === false) {
        echo "companyfactsの取得に失敗しました（詳細はログを確認してください）\n";
        exit;
    }

    static $revenue_tags = array(
        'Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax',
        'RevenueFromContractWithCustomerIncludingAssessedTax', 'SalesRevenueNet',
        'SalesRevenueGoodsNet', 'SalesRevenueServicesNet',
    );
    static $net_income_tags = array('NetIncomeLoss', 'ProfitLoss');

    $revenue    = wp_stocks_sec_extract_quarterly($facts, $revenue_tags);
    $net_income = wp_stocks_sec_extract_quarterly($facts, $net_income_tags);

    $q4_rev_count = wp_stocks_sec_derive_q4($revenue['facts'], wp_stocks_sec_extract_annual($facts, $revenue_tags));
    $q4_ni_count  = wp_stocks_sec_derive_q4($net_income['facts'], wp_stocks_sec_extract_annual($facts, $net_income_tags));

    // companyfactsの生JSONはここで解放（KOのような報告履歴が長い企業はサイズが大きく、
    // 保持したままDB保存処理に入るとメモリを圧迫して致命的エラーの原因になるため）
    unset($facts);

    if ($stock_id > 0) {
        $result = wp_stocks_sec_save_quarterly_facts($stock_id, $symbol, $revenue, $net_income);
        echo "DB保存結果: " . ($result ? '成功' : '失敗') . " (stock_id={$stock_id}, source=edgar)\n\n";

        // 実際にDBへ保存された内容を読み戻して表示（書き込みそのものの実証確認）
        global $wpdb;
        $saved_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT period_end, revenue, net_income, created_at FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'edgar' ORDER BY period_end DESC",
            $stock_id
        ));
        echo "DB実読み戻し（stock_quarterly_financials, source=edgar, stock_id={$stock_id}）: " . count($saved_rows) . "件\n";
        printf("%-12s %18s %18s %s\n", '期末日', 'revenue', 'net_income', '保存日時');
        foreach ($saved_rows as $row) {
            printf("%-12s %17s  %17s  %s\n",
                $row->period_end,
                $row->revenue !== null ? number_format($row->revenue) : '-',
                $row->net_income !== null ? number_format($row->net_income) : '-',
                $row->created_at ?? ''
            );
        }
        echo "\n";
    } else {
        echo "(stock_id未指定のためDB保存はスキップ。保存するにはURLに &stock_id=XX を追加してください)\n\n";
    }

    echo "採用タグ: revenue=" . implode(',', $revenue['tags_used']) . " / net_income=" . implode(',', $net_income['tags_used']) . "\n";
    echo "Q4逆算件数: revenue={$q4_rev_count} / net_income={$q4_ni_count}\n\n";

    $period_ends = array_unique(array_merge(array_keys($revenue['facts']), array_keys($net_income['facts'])));
    rsort($period_ends);
    $period_ends = array_slice($period_ends, 0, 8);

    printf("%-12s %18s %18s\n", '期末日', 'revenue', 'net_income');
    foreach ($period_ends as $end) {
        $rev = $revenue['facts'][$end]['val'] ?? null;
        $ni  = $net_income['facts'][$end]['val'] ?? null;
        $rev_mark = !empty($revenue['facts'][$end]['derived']) ? '*' : ' ';
        $ni_mark  = !empty($net_income['facts'][$end]['derived']) ? '*' : ' ';
        printf("%-12s %17s%s %17s%s\n", $end, $rev !== null ? number_format($rev) : '-', $rev_mark, $ni !== null ? number_format($ni) : '-', $ni_mark);
    }
    echo "\n(* は年次-（Q1+Q2+Q3）による逆算値)\n";
    exit;
});

add_action('admin_post_wp_stocks_fmp_test_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_fmp_test');
    $symbol = sanitize_text_field($_GET['symbol'] ?? 'AAPL');

    header('Content-Type: text/plain; charset=utf-8');

    echo "Symbol: {$symbol}\n\n";

    $ratios = wp_stocks_fmp_get_ratios($symbol);
    if ($ratios === false) {
        echo "FMPからの比率取得に失敗しました（APIキー未設定、または通信エラー。詳細はログを確認してください）\n";
    } else {
        echo "--- FMP比率（ratios-ttm / key-metrics-ttm）---\n";
        foreach ($ratios as $k => $v) {
            echo str_pad($k, 20) . ": {$v}\n";
        }
    }

    echo "\n--- 次回決算予定日（earnings-calendar、全銘柄まとめてtransientキャッシュ）---\n";
    $earnings_dates = wp_stocks_fmp_get_earnings_dates();
    echo isset($earnings_dates[$symbol]) ? "{$symbol}: {$earnings_dates[$symbol]}\n" : "{$symbol}: 見つかりませんでした（90日以内に予定なし、またはキャッシュ未更新の可能性）\n";
    echo "（取得件数：全" . count($earnings_dates) . "銘柄）\n";

    echo "\n--- wp_stocks_get_company_info()統合後の結果（実際に保存される値）---\n";
    $info = wp_stocks_get_company_info($symbol);
    if ($info === false) {
        echo "wp_stocks_get_company_info()が失敗しました\n";
    } else {
        foreach ($info as $k => $v) {
            echo str_pad($k, 20) . ": " . (is_null($v) ? 'null' : $v) . "\n";
        }
    }
    exit;
});

add_action('admin_post_diagnose_half_year', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_diag_half_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    wp_stocks_diagnose_half_year_report($stock_id, $stock->code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=half&message=diag_done'));
    exit;
});
add_action('admin_post_fetch_half_year_financials', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_half_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $result = wp_stocks_fetch_half_year_financials($stock_id, $stock->code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=half&message=' . ($result ? 'fin_saved' : 'fin_error')));
    exit;
});

add_action('admin_post_toggle_watchlist', function() {
    wp_stocks_require_admin_action('wp_stocks_watchlist_nonce');
    global $wpdb;
    $id      = intval($_POST['stock_id']);
    $current = intval($wpdb->get_var($wpdb->prepare(
        "SELECT is_watchlist FROM {$wpdb->prefix}stocks WHERE id = %d", $id
    )));
    $wpdb->update($wpdb->prefix . 'stocks', ['is_watchlist' => $current ? 0 : 1], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=watchlist_saved'));
    exit;
});

add_action('admin_post_update_memo', function() {
    wp_stocks_require_admin_action('wp_stocks_memo_nonce');
    global $wpdb;
    $id   = intval($_POST['stock_id']);
    $memo = sanitize_textarea_field($_POST['memo']);
    $wpdb->update($wpdb->prefix . 'stocks', ['memo' => $memo], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=memo_saved'));
    exit;
});

add_action('admin_post_update_shikiho', function() {
    wp_stocks_require_admin_action('wp_stocks_shikiho_nonce');
    global $wpdb;
    $id      = intval($_POST['stock_id']);
    $shikiho = wp_stocks_sanitize_shikiho_text($_POST['shikiho']);
    $update_data = [
        'shikiho'            => $shikiho,
        'shikiho_updated_at' => current_time('mysql'),
    ];
    $sector_from_shikiho = wp_stocks_extract_sector_from_shikiho($shikiho);
    if (!empty($sector_from_shikiho)) {
        $update_data['sector'] = $sector_from_shikiho;
    }
    $wpdb->update($wpdb->prefix . 'stocks', $update_data, ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=shikiho_saved'));
    exit;
});

add_action('admin_post_update_ai_analysis', function() {
    wp_stocks_require_admin_action('wp_stocks_ai_analysis_nonce');
    global $wpdb;
    $id          = intval($_POST['stock_id']);
    $ai_analysis = sanitize_textarea_field($_POST['ai_analysis']);
    $wpdb->update($wpdb->prefix . 'stocks', [
        'ai_analysis'            => $ai_analysis,
        'ai_analysis_updated_at' => current_time('mysql'),
    ], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=ai_analysis_saved'));
    exit;
});

add_action('admin_post_save_ai_history', function() {
    wp_stocks_require_admin_action('wp_stocks_ai_history_nonce');
    global $wpdb;
    $id       = intval($_POST['stock_id']);
    $raw_data = sanitize_textarea_field($_POST['pipe_data']);
    if (empty($raw_data)) wp_die('データが空です');
    error_log('AI HISTORY SAVE: stock_id=' . $id . ' data=' . substr($raw_data, 0, 50));

    // パイプ区切りをパース
    $parts = explode('|', $raw_data);
    $insert_result = $wpdb->insert($wpdb->prefix . 'stock_ai_history', [
        'stock_id'       => $id,
        'analysis_date'  => current_time('Y-m-d'),
        'raw_data'       => $raw_data,
        'valuation'      => $parts[3]  ?? '',
        'financial_health'=> $parts[4] ?? '',
        'growth'         => $parts[5]  ?? '',
        'market_status'  => $parts[6]  ?? '',
        'risk_factor'    => $parts[7]  ?? '',
        'catalyst'       => $parts[8]  ?? '',
        'dividend_eval'  => $parts[9]  ?? '',
        'fair_price'     => $parts[10] ?? '',
        'short_outlook'  => $parts[11] ?? '',
        'mid_outlook'    => $parts[12] ?? '',
        'total_score'    => $parts[13] ?? '',
        'comment'        => $parts[14] ?? '',
        'created_at'     => current_time('mysql'),
    ]);
    error_log('AI HISTORY INSERT result=' . var_export($insert_result, true) . ' error=' . $wpdb->last_error);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=ai_saved'));
    exit;
});

add_action('admin_post_move_to_portfolio', function() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    $id = intval($_GET['id'] ?? 0);
    wp_redirect(admin_url('admin.php?page=wp-stocks-portfolio&action=edit_purchase&stock_id=' . $id));
    exit;
});

add_action('admin_post_fetch_fund_price', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id   = intval($_GET['id'] ?? 0);
    $fund = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stock_funds WHERE id = %d", $id));
    if (!$fund) wp_die('ファンドが見つかりません');
    $result = wp_stocks_save_fund_price($id, $fund->fund_code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-funds&message=' . ($result ? 'price_saved' : 'price_error')));
    exit;
});

add_action('admin_post_move_to_watch', function() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    if ($id) $wpdb->update($wpdb->prefix . 'stocks', ['status' => 'watch', 'purchase_price' => 0, 'purchase_qty' => 0], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-portfolio'));
    exit;
});

add_action('admin_post_backfill_all_prices', function() {
    wp_stocks_require_admin_action('wp_stocks_backfill_nonce');
    set_time_limit(60);
    global $wpdb;

    $batch_size     = 15;
    $offset         = intval($_GET['offset'] ?? 0);
    $total_inserted = intval($_GET['total'] ?? 0);

    $stocks = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stocks ORDER BY id ASC LIMIT %d OFFSET %d",
        $batch_size, $offset
    ));

    foreach ($stocks as $s) {
        $sym = ($s->currency ?? 'JPY') === 'USD' ? $s->code : $s->code . '.T';
        $total_inserted += wp_stocks_backfill_price_history($s->id, $sym, 30);
        usleep(300000); // 0.3秒
    }

    $done = count($stocks) < $batch_size;

    if ($done) {
        wp_stocks_log('info', 'backfill_all', 'ALL', "過去30日分の株価バックフィル完了：{$total_inserted}件挿入");
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=backfill_done&n=' . $total_inserted));
        exit;
    }

    // まだ続きがある → 手動で次に進むボタンを表示
    $next_offset = $offset + $batch_size;
    $next_url = wp_nonce_url(
        admin_url('admin-post.php?action=backfill_all_prices&offset=' . $next_offset . '&total=' . $total_inserted),
        'wp_stocks_backfill_nonce'
    );
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;">';
    echo '<h2>バックフィル処理中…</h2>';
    echo '<p>' . $next_offset . '件目まで処理しました（累計挿入：' . $total_inserted . '件）。</p>';
    echo '<a href="' . esc_url($next_url) . '" style="display:inline-block;padding:10px 24px;background:#0073aa;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;">次の' . $batch_size . '件を処理 →</a>';
    echo '</div>';
    exit;
});

// --------------------------------------------------
// 銘柄選択セレクトを「セクター別アコーディオン式ドロップダウン」に
// 拡張するための共通CSS/JS。class="wp-stocks-sector-select" が
// 付与された<select>を自動的に検出して適用する。
// 1ページに複数呼び出しても2回目以降は何もしない。
// --------------------------------------------------
function wp_stocks_render_sector_dropdown_assets() {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    ?>
    <style>
    .wp-stocks-sector-dd { position: relative; display: inline-block; vertical-align: middle; }
    .wp-stocks-sector-dd-trigger {
        width: 100%; text-align: left; padding: 6px 10px; border: 1px solid #ccc;
        border-radius: 4px; background: #fff; cursor: pointer; font-size: 13px;
    }
    .wp-stocks-sector-dd-panel {
        display: none; position: absolute; z-index: 1000; top: 100%; left: 0;
        min-width: 100%; width: 280px; max-height: 400px; overflow-y: auto;
        background: #fff; border: 1px solid #ccc; border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-top: 2px;
    }
    .wp-stocks-sector-dd-search {
        width: 100%; box-sizing: border-box; padding: 8px 10px; border: none;
        border-bottom: 1px solid #eee; font-size: 13px; position: sticky; top: 0; background: #fff;
    }
    .wp-stocks-sector-dd-header {
        padding: 8px 10px; background: #f5f5f5; font-weight: bold; font-size: 12px;
        color: #555; cursor: pointer; border-bottom: 1px solid #eee;
    }
    .wp-stocks-sector-dd-header:hover { background: #eee; }
    .wp-stocks-sector-dd-item { padding: 6px 14px; cursor: pointer; font-size: 13px; }
    .wp-stocks-sector-dd-item:hover { background: #f0f7ff; }
    </style>
    <script>
    (function() {
        function initOne(select) {
            if (select.dataset.sectorDdInit) return;
            select.dataset.sectorDdInit = '1';
            select.style.display = 'none';

            var placeholder = select.getAttribute('data-placeholder') || '-- 選択 --';

            var wrap = document.createElement('div');
            wrap.className = 'wp-stocks-sector-dd';
            wrap.style.width = select.getAttribute('data-dd-width') || (select.offsetWidth ? select.offsetWidth + 'px' : '220px');

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'wp-stocks-sector-dd-trigger';

            function currentLabel() {
                var opt = select.options[select.selectedIndex];
                return (opt && opt.value !== '') ? opt.textContent : placeholder;
            }
            trigger.textContent = currentLabel() + ' \u25be';

            var panel = document.createElement('div');
            panel.className = 'wp-stocks-sector-dd-panel';

            var search = document.createElement('input');
            search.type = 'text';
            search.className = 'wp-stocks-sector-dd-search';
            search.placeholder = 'コード・銘柄名で検索';
            panel.appendChild(search);

            var groupBlocks = [];

            Array.prototype.forEach.call(select.children, function(child) {
                var label, optionEls;
                if (child.tagName === 'OPTGROUP') {
                    label = child.label;
                    optionEls = Array.prototype.slice.call(child.children);
                } else if (child.tagName === 'OPTION' && child.value !== '') {
                    label = '';
                    optionEls = [child];
                } else {
                    return;
                }

                var groupBlock = document.createElement('div');
                var header = document.createElement('div');
                header.className = 'wp-stocks-sector-dd-header';
                header.textContent = label;
                var body = document.createElement('div');
                body.style.display = 'none';

                var itemEls = [];
                optionEls.forEach(function(opt) {
                    var item = document.createElement('div');
                    item.className = 'wp-stocks-sector-dd-item';
                    item.textContent = opt.textContent;
                    item.addEventListener('click', function() {
                        select.value = opt.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                        trigger.textContent = currentLabel() + ' \u25be';
                        closePanel();
                    });
                    body.appendChild(item);
                    itemEls.push(item);
                });

                header.addEventListener('click', function() {
                    var isOpen = body.style.display !== 'none';
                    // アコーディオン: 他のセクターは閉じる
                    groupBlocks.forEach(function(g) { g.body.style.display = 'none'; });
                    body.style.display = isOpen ? 'none' : 'block';
                });

                groupBlock.appendChild(header);
                groupBlock.appendChild(body);
                panel.appendChild(groupBlock);
                groupBlocks.push({ block: groupBlock, header: header, body: body, items: itemEls });
            });

            function resetView() {
                search.value = '';
                groupBlocks.forEach(function(g) {
                    g.block.style.display = '';
                    g.body.style.display = 'none';
                    g.items.forEach(function(it) { it.style.display = ''; });
                });
            }

            search.addEventListener('input', function() {
                var q = search.value.trim().toLowerCase();
                if (q === '') { resetView(); return; }
                groupBlocks.forEach(function(g) {
                    var anyMatch = false;
                    g.items.forEach(function(it) {
                        var match = it.textContent.toLowerCase().indexOf(q) !== -1;
                        it.style.display = match ? '' : 'none';
                        if (match) anyMatch = true;
                    });
                    g.block.style.display = anyMatch ? '' : 'none';
                    g.body.style.display = anyMatch ? 'block' : 'none';
                });
            });

            function openPanel() { panel.style.display = 'block'; search.focus(); }
            function closePanel() { panel.style.display = 'none'; resetView(); }

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                if (panel.style.display === 'block') { closePanel(); } else { openPanel(); }
            });

            document.addEventListener('click', function(e) {
                if (!wrap.contains(e.target)) { panel.style.display = 'none'; }
            });

            wrap.appendChild(trigger);
            wrap.appendChild(panel);
            select.insertAdjacentElement('afterend', wrap);
        }

        function initAll() {
            var nodes = document.querySelectorAll('.wp-stocks-sector-select');
            Array.prototype.forEach.call(nodes, initOne);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAll);
        } else {
            initAll();
        }
    })();
    </script>
    <?php
}

// --------------------------------------------------
// ダッシュボード（ウォッチリスト）
// ソート・ページネーション付き
// --------------------------------------------------
// --------------------------------------------------
// 最近の適時開示ウィジェット（全銘柄横断）
// ダッシュボードやマーケット情報ページなど、複数箇所から
// 呼び出せるよう独立関数化。
// --------------------------------------------------
function wp_stocks_render_recent_news_widget($limit = 15) {
    global $wpdb;
    $limit = intval($limit);
    $recent_news = $wpdb->get_results($wpdb->prepare(
        "SELECT sn.*, s.code, s.name
         FROM {$wpdb->prefix}stock_news sn
         INNER JOIN {$wpdb->prefix}stocks s ON sn.stock_id = s.id
         ORDER BY sn.published_at DESC
         LIMIT %d",
        $limit
    ));
    if (empty($recent_news)) return;

    $news_new_cutoff = date('Y-m-d H:i:s', strtotime('-3 days'));
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">';
    echo '<h3 style="margin:0 0 12px 0;font-size:14px;">&#x1F4F0; 最近の適時開示</h3>';
    echo '<div style="display:flex;flex-direction:column;">';
    foreach ($recent_news as $n) {
        $news_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $n->stock_id . '&ctab=news');
        $pub_date = $n->published_at ? date('Y/m/d H:i', strtotime($n->published_at)) : '';
        $is_new   = $n->published_at && $n->published_at >= $news_new_cutoff;
        echo '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:13px;">';
        if ($is_new) {
            echo '<span style="background:#e74c3c;color:#fff;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:bold;flex-shrink:0;">NEW</span>';
        } else {
            echo '<span style="width:32px;flex-shrink:0;"></span>';
        }
        echo '<span style="color:#888;flex-shrink:0;white-space:nowrap;">' . esc_html($pub_date) . '</span>';
        echo '<a href="' . esc_url($news_url) . '" style="color:#0073aa;text-decoration:none;font-weight:bold;flex-shrink:0;white-space:nowrap;">' . esc_html($n->code) . ' ' . esc_html($n->name) . '</a>';
        echo '<a href="' . esc_url($n->link) . '" target="_blank" style="color:#333;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html($n->title) . '</a>';
        echo '</div>';
    }
    echo '</div></div>';
}


// --------------------------------------------------
// ポートフォリオページ
// --------------------------------------------------
function wp_stocks_portfolio_page() {
    global $wpdb;

    // 購入情報保存
    if (isset($_POST['wp_stocks_save_purchase'])) {
        check_admin_referer('wp_stocks_purchase_nonce');
        $id    = intval($_POST['stock_id']);
        $price = floatval($_POST['purchase_price']);
        $qty   = intval($_POST['purchase_qty']);
        $wpdb->update($wpdb->prefix . 'stocks', ['purchase_price' => $price, 'purchase_qty' => $qty, 'status' => 'portfolio'], ['id' => $id]);
        echo '<div class="updated"><p>購入情報を保存しました。</p></div>';
    }

    // 購入情報編集フォーム
    if (isset($_GET['action']) && $_GET['action'] === 'edit_purchase' && isset($_GET['stock_id'])) {
        $id    = intval($_GET['stock_id']);
        $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
        if ($stock) {
            echo '<div class="wrap"><h1>📁 ポートフォリオに追加</h1>';
            echo '<h2>' . esc_html($stock->code . ' ' . $stock->name) . '</h2>';
            echo '<form method="post">';
            wp_nonce_field('wp_stocks_purchase_nonce');
            echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>平均取得単価（円）</th><td><input type="number" name="purchase_price" value="' . esc_attr($stock->purchase_price ?? '') . '" step="0.01" style="width:150px;" required> 円</td></tr>';
            echo '<tr><th>保有株数</th><td><input type="number" name="purchase_qty" value="' . esc_attr($stock->purchase_qty ?? '') . '" style="width:150px;" required> 株</td></tr>';
            echo '</tbody></table>';
            echo '<p><button type="submit" name="wp_stocks_save_purchase" class="button button-primary">保存してポートフォリオに追加</button> <a href="' . admin_url('admin.php?page=wp-stocks-dashboard') . '" class="button">キャンセル</a></p>';
            echo '</form></div>';
            return;
        }
    }

    // 株式データ取得（TOPIX-17セクターETFは除外）
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE status = 'portfolio' AND is_sector_etf = 0 ORDER BY sector, id");
    echo '<div class="wrap"><h1>📁 ポートフォリオ</h1>';

    // テクニカルデータ
    $tech_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_technicals");
    $tech_map  = [];
    foreach ($tech_rows as $t) $tech_map[$t->stock_id] = $t;

    // 株式データ集計
    $usd_jpy_rate = wp_stocks_get_usd_jpy(); // 為替レート取得
    $rows = [];
    $total_value = $total_cost = $total_dividend = 0;
    if ($stocks) {
        foreach ($stocks as $s) {
            $is_usd        = ($s->currency ?? 'JPY') === 'USD';
            $fx            = $is_usd ? $usd_jpy_rate : 1;
            $latest        = $wpdb->get_row($wpdb->prepare("SELECT price, previous_close FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $s->id));
            $current_price = $latest ? floatval($latest->price) : 0;       // ドルまたは円
            $prev_close    = $latest ? floatval($latest->previous_close ?? 0) : 0;
            $qty           = intval($s->purchase_qty ?? 0);
            $buy_price     = floatval($s->purchase_price ?? 0);             // ドルまたは円
            $eval_value    = $current_price * $qty * $fx;                   // 常に円換算
            $cost_value    = $buy_price * $qty * $fx;                       // 常に円換算
            $gain          = $eval_value - $cost_value;
            $gain_pct      = $cost_value > 0 ? ($gain / $cost_value * 100) : 0;
            $annual_div    = ($s->dividend_yield ?? 0) > 0 ? ($eval_value * $s->dividend_yield / 100) : 0;
            $total_value  += $eval_value;
            $total_cost   += $cost_value;
            $total_dividend += $annual_div;
            $rows[] = compact('s', 'current_price', 'prev_close', 'qty', 'buy_price',
                              'eval_value', 'cost_value', 'gain', 'gain_pct', 'annual_div',
                              'is_usd', 'fx');
        }
    }
    $total_gain     = $total_value - $total_cost;
    $total_gain_pct = $total_cost > 0 ? ($total_gain / $total_cost * 100) : 0;

    // 日本株・外国株に分類
    $jp_rows      = [];
    $foreign_rows = [];
    foreach ($rows as $row) {
        $market = strtoupper($row['s']->market ?? '');
        if (($row['s']->currency ?? 'JPY') === 'USD') {
            $foreign_rows[] = $row;
        } else {
            $jp_rows[] = $row;
        }
    }

    // 小計計算クロージャ
    $calc_subtotal = function($row_list) {
        $eval = $cost = $gain = $div = 0;
        foreach ($row_list as $row) {
            $eval += $row['eval_value'];
            $cost += $row['cost_value'];
            $gain += $row['gain'];
            $div  += $row['annual_div'];
        }
        return ['eval' => $eval, 'cost' => $cost, 'gain' => $gain, 'div' => $div,
                'pct'  => $cost > 0 ? ($gain / $cost * 100) : 0];
    };
    $jp_sub      = $calc_subtotal($jp_rows);
    $foreign_sub = $calc_subtotal($foreign_rows);

    // 投資信託データ取得
    $funds           = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_funds ORDER BY fund_type, id");
    $fund_total_eval = $fund_total_cost = $fund_total_gain = 0;
    $fund_rows       = [];
    foreach ($funds as $f) {
        $prices      = $wpdb->get_results($wpdb->prepare(
            "SELECT price, price_date FROM {$wpdb->prefix}stock_fund_prices WHERE fund_id = %d ORDER BY price_date DESC LIMIT 2", $f->id
        ));
        $today_price  = $prices[0]->price ?? null;
        $eval_value   = $today_price ? ($f->fund_units * $today_price / 10000) : 0;
        $cost_value   = $f->fund_units * $f->cost_per_unit / 10000;
        $gain         = $eval_value - $cost_value;
        $gain_pct     = $cost_value > 0 ? ($gain / $cost_value * 100) : 0;
        $fund_total_eval += $eval_value;
        $fund_total_cost += $cost_value;
        $fund_total_gain += $gain;
        $fund_rows[]  = compact('f', 'today_price', 'eval_value', 'cost_value', 'gain', 'gain_pct');
    }
    $fund_gain_pct = $fund_total_cost > 0 ? ($fund_total_gain / $fund_total_cost * 100) : 0;

    // 全体合計
    $grand_eval  = $total_value + $fund_total_eval;
    $grand_cost  = $total_cost  + $fund_total_cost;
    $grand_gain  = $total_gain  + $fund_total_gain;
    $grand_pct   = $grand_cost > 0 ? ($grand_gain / $grand_cost * 100) : 0;
    $grand_color = $grand_gain >= 0 ? '#e74c3c' : '#3498db';

    // =============================================
    // 表示① 全体合計カード
    // =============================================
    echo '<div style="background:#fff;border:2px solid ' . $grand_color . ';border-radius:8px;padding:16px;margin-bottom:25px;">';
    echo '<div style="font-size:13px;font-weight:bold;color:#555;margin-bottom:10px;">📊 ポートフォリオ全体合計（株式＋投資信託）</div>';
    echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;">';
    foreach ([
        ['評価額合計', number_format($grand_eval) . '円', '#333'],
        ['取得額合計', number_format($grand_cost) . '円', '#333'],
        ['含み損益',   ($grand_gain >= 0 ? '+' : '') . number_format($grand_gain) . '円 (' . ($grand_pct >= 0 ? '+' : '') . number_format($grand_pct, 2) . '%)', $grand_color],
    ] as [$label, $val, $color]) {
        echo '<div style="text-align:center;">';
        echo '<div style="font-size:12px;color:#888;margin-bottom:4px;">' . $label . '</div>';
        echo '<div style="font-size:18px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
        echo '</div>';
    }
    echo '</div></div>';

    if (!$stocks && empty($funds)) { echo '<p>ポートフォリオに銘柄がありません。</p></div>'; return; }

    // =============================================
    // 表示② 日本株セクション
    // =============================================
    if (!empty($jp_rows)) {
        $gain_color_jp = $jp_sub['gain'] >= 0 ? '#e74c3c' : '#3498db';

        echo '<h2 style="margin:20px 0 15px 0;font-size:16px;border-left:4px solid #e74c3c;padding-left:10px;">🇯🇵 日本株</h2>';

        // 日本株サマリーカード
        echo '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px;">';
        foreach ([
            ['評価額合計',   number_format($jp_sub['eval']) . '円',  '#333'],
            ['取得額合計',   number_format($jp_sub['cost']) . '円',   '#333'],
            ['含み損益',     ($jp_sub['gain'] >= 0 ? '+' : '') . number_format($jp_sub['gain']) . '円 (' . ($jp_sub['pct'] >= 0 ? '+' : '') . number_format($jp_sub['pct'], 2) . '%)', $gain_color_jp],
            ['年間配当予想', number_format($jp_sub['div']) . '円', '#27ae60'],
        ] as [$label, $val, $color]) {
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;text-align:center;">';
            echo '<div style="font-size:12px;color:#888;margin-bottom:6px;">' . $label . '</div>';
            echo '<div style="font-size:16px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // セクター配分
        $sector_values = [];
        foreach ($jp_rows as $row) {
            extract($row);
            $sector = !empty($s->sector) ? wp_stocks_sector_ja($s->sector) : 'その他';
            if (!isset($sector_values[$sector])) $sector_values[$sector] = 0;
            $sector_values[$sector] += $eval_value;
        }
        arsort($sector_values);
        $sector_labels = json_encode(array_keys($sector_values), JSON_UNESCAPED_UNICODE);
        $sector_data   = json_encode(array_values($sector_values));
        $sector_colors = json_encode(['#e74c3c','#3498db','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#34495e','#e91e63','#00bcd4','#8bc34a','#ff5722']);
        $jp_eval_total = $jp_sub['eval'];
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:25px;align-items:center;">
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;">
                <h3 style="margin:0 0 10px 0;font-size:14px;">セクター配分</h3>
                <div id="sectorChart"></div>
            </div>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;">
                <h3 style="margin:0 0 10px 0;font-size:14px;">セクター別評価額</h3>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <thead><tr style="background:#f8f9fa;">
                        <th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">セクター</th>
                        <th style="padding:6px 10px;text-align:right;border:1px solid #ddd;">評価額</th>
                        <th style="padding:6px 10px;text-align:right;border:1px solid #ddd;">比率</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sector_values as $sec => $val): ?>
                    <tr>
                        <td style="padding:5px 10px;border:1px solid #ddd;"><?php echo esc_html($sec); ?></td>
                        <td style="padding:5px 10px;text-align:right;border:1px solid #ddd;"><?php echo number_format($val); ?>円</td>
                        <td style="padding:5px 10px;text-align:right;border:1px solid #ddd;"><?php echo $jp_eval_total > 0 ? number_format($val / $jp_eval_total * 100, 1) : 0; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.2/dist/apexcharts.min.js"></script>
        <script>
        var sectorChart = new ApexCharts(document.getElementById('sectorChart'), {
            series: <?php echo $sector_data; ?>,
            chart: { type: 'donut', height: 280 },
            labels: <?php echo $sector_labels; ?>,
            colors: <?php echo $sector_colors; ?>,
            legend: { position: 'bottom', fontSize: '12px' },
            dataLabels: { formatter: function(val) { return val.toFixed(1) + '%'; } },
            tooltip: { y: { formatter: function(val) { return Number(val).toLocaleString() + '円'; } } },
            plotOptions: { pie: { donut: { size: '60%', labels: { show: true, total: { show: true, label: '合計', formatter: function(w) { var total = w.globals.seriesTotals.reduce((a,b)=>a+b,0); return Number(total).toLocaleString()+'円'; } } } } } },
        });
        sectorChart.render();
        </script>
        <?php

        // 含み損益推移グラフ
        $portfolio_ids = array_map(fn($r) => $r['s']->id, $jp_rows);
        if (!empty($portfolio_ids)) {
            $placeholders  = implode(',', array_fill(0, count($portfolio_ids), '%d'));
            $price_history = $wpdb->get_results($wpdb->prepare(
                "SELECT stock_id, price, DATE(datetime) as date FROM {$wpdb->prefix}stock_prices WHERE stock_id IN ($placeholders) AND datetime >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY date ASC",
                ...$portfolio_ids
            ));

            // 銘柄ごとに「日付 => 価格」のマップを作成
            $by_stock = [];
            foreach ($price_history as $ph) {
                $by_stock[$ph->stock_id][$ph->date] = floatval($ph->price);
            }

            // データが存在する日付を全て収集（開始日のクリップは行わない）
            $all_dates = [];
            foreach ($by_stock as $dates) {
                foreach (array_keys($dates) as $d) $all_dates[$d] = true;
            }
            $all_dates = array_keys($all_dates);
            sort($all_dates);

            $cost_total = 0;
            foreach ($jp_rows as $row) $cost_total += floatval($row['buy_price']) * intval($row['qty']);

            // 前方補完（forward fill）しながら日別評価額を合計
            // ※まだ一度もデータを持たない銘柄は、データが出てくるまで合計に含めない
            $last_known = [];
            $daily_data = [];
            foreach ($all_dates as $date) {
                $total = 0;
                foreach ($jp_rows as $row) {
                    $sid = $row['s']->id;
                    $qty = intval($row['qty']);
                    if (isset($by_stock[$sid][$date])) {
                        $last_known[$sid] = $by_stock[$sid][$date];
                    }
                    if (isset($last_known[$sid])) {
                        $total += $last_known[$sid] * $qty;
                    }
                }
                $daily_data[$date] = $total;
            }
            ksort($daily_data);
            $graph_labels = $graph_gains = $graph_values = [];
            foreach ($daily_data as $date => $eval_val) {
                $graph_labels[] = $date;
                $graph_gains[]  = round($eval_val - $cost_total);
                $graph_values[] = round($eval_val);
            }
            if (count($graph_labels) >= 2) {
                $labels_json = json_encode($graph_labels);
                $gains_json  = json_encode($graph_gains);
                $values_json = json_encode($graph_values);
                ?>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:25px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <h3 style="margin:0;font-size:14px;">含み損益・評価額推移（過去30日）</h3>
                        <div style="display:flex;gap:8px;">
                            <button onclick="setPfChart('gain')" class="button button-small" id="btn_gain" style="background:#0073aa;color:#fff;">含み損益</button>
                            <button onclick="setPfChart('value')" class="button button-small" id="btn_value">評価額</button>
                        </div>
                    </div>
                    <div id="pfChart"></div>
                </div>
                <script>
                var pfLabels=<?php echo $labels_json; ?>, pfGains=<?php echo $gains_json; ?>, pfValues=<?php echo $values_json; ?>, pfChart=null;
                function setPfChart(type){
                    document.getElementById('btn_gain').style.background=type==='gain'?'#0073aa':'';
                    document.getElementById('btn_gain').style.color=type==='gain'?'#fff':'';
                    document.getElementById('btn_value').style.background=type==='value'?'#0073aa':'';
                    document.getElementById('btn_value').style.color=type==='value'?'#fff':'';
                    if(pfChart){pfChart.destroy();pfChart=null;}
                    var data=type==='gain'?pfGains:pfValues;
                    var lineColor=data[data.length-1]>=data[0]?'#e74c3c':'#3498db';
                    pfChart=new ApexCharts(document.getElementById('pfChart'),{
                        series:[{name:type==='gain'?'含み損益':'評価額',data:data}],
                        chart:{type:'area',height:220,toolbar:{show:false},zoom:{enabled:false}},
                        xaxis:{categories:pfLabels,tickAmount:8,labels:{style:{fontSize:'11px'}}},
                        yaxis:{labels:{style:{fontSize:'11px'},formatter:function(v){return Number(v).toLocaleString()+'円';}}},
                        colors:[lineColor],fill:{type:'gradient',gradient:{shadeIntensity:1,opacityFrom:0.4,opacityTo:0.0}},
                        stroke:{width:2},dataLabels:{enabled:false},
                        tooltip:{y:{formatter:function(v){return(v>=0?'+':'')+Number(v).toLocaleString()+'円';}}},
                        annotations:{yaxis:type==='gain'?[{y:0,borderColor:'#888',borderWidth:1,strokeDashArray:4}]:[]},
                    });
                    pfChart.render();
                }
                setPfChart('gain');
                </script>
                <?php
            }
        }

        // 日本株テーブル
        echo '<table class="widefat fixed striped" style="font-size:12px;margin-bottom:8px;"><thead><tr>';
        foreach (['コード','銘柄名','トレンド','現在値','前日比','取得単価','株数','評価額','含み損益','含み率','配当予想','操作'] as $h) echo '<th>'.$h.'</th>';
        echo '</tr></thead><tbody>';
        foreach ($jp_rows as $row) {
            extract($row);
            $tech        = $tech_map[$s->id] ?? null;
            $trend_html  = wp_stocks_trend_icon_html($tech);
            $gc2         = $gain >= 0 ? '#e74c3c' : '#3498db';
            $row_is_usd  = ($s->currency ?? 'JPY') === 'USD';
            $change_html = $prev_close > 0 ? wp_stocks_change_html($current_price, $prev_close, $row_is_usd) : '-';
            $price_str2  = $current_price > 0 ? ($row_is_usd ? '$' . number_format($current_price, 2) : number_format($current_price) . '円') : '未取得';
            $edit_url    = admin_url('admin.php?page=wp-stocks-portfolio&action=edit_purchase&stock_id=' . $s->id);
            $watch_url   = admin_url('admin-post.php?action=move_to_watch&id=' . $s->id);
            echo '<tr>';
            echo '<td>'.esc_html($s->code).'</td>';
            echo '<td><a href="'.admin_url('admin.php?page=wp-stocks-company&stock_id='.$s->id).'">'.esc_html($s->name).'</a>'.esc_html(wp_stocks_market_segment_suffix($s->market ?? '')).'</td>';
            echo '<td>'.$trend_html.'</td>';
            echo '<td>'.$price_str2.'</td>';
            echo '<td>'.$change_html.'</td>';
            echo '<td>'.($buy_price>0?number_format($buy_price).'円':'-').'</td>';
            echo '<td>'.number_format($qty).'株</td>';
            echo '<td>'.($eval_value>0?number_format($eval_value).'円':'-').'</td>';
            echo '<td style="color:'.$gc2.';font-weight:bold;">'.($gain>=0?'+':'').number_format($gain).'円</td>';
            echo '<td style="color:'.$gc2.';font-weight:bold;">'.($gain_pct>=0?'+':'').number_format($gain_pct,2).'%</td>';
            echo '<td>'.($annual_div>0?number_format($annual_div).'円':'-').'</td>';
            echo '<td><a href="'.esc_url($edit_url).'" class="button button-small">編集</a> <a href="'.esc_url($watch_url).'" class="button button-small">ウォッチへ</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        // 日本株小計
        $gc = $jp_sub['gain'] >= 0 ? '#e74c3c' : '#3498db';
        echo '<div style="text-align:right;padding:8px 12px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;margin-bottom:30px;font-size:13px;">';
        echo '日本株小計　評価額：<strong>'.number_format($jp_sub['eval']).'円</strong>　';
        echo '含み損益：<strong style="color:'.$gc.';">'.($jp_sub['gain']>=0?'+':'').number_format($jp_sub['gain']).'円 ('.($jp_sub['pct']>=0?'+':'').number_format($jp_sub['pct'],2).'%)</strong>';
        echo '</div>';
    }

    // =============================================
    // 表示③ 外国株セクション
    // =============================================
    if (!empty($foreign_rows)) {
        echo '<h2 style="margin:20px 0 15px 0;font-size:16px;border-left:4px solid #3498db;padding-left:10px;">🌍 外国株</h2>';
        echo '<table class="widefat fixed striped" style="font-size:12px;margin-bottom:8px;"><thead><tr>';
        foreach (['コード','銘柄名','トレンド','現在値','前日比','取得単価','株数','評価額','含み損益','含み率','配当予想','操作'] as $h) echo '<th>'.$h.'</th>';
        echo '</tr></thead><tbody>';
        foreach ($foreign_rows as $row) {
            extract($row);
            $tech        = $tech_map[$s->id] ?? null;
            $trend_html  = wp_stocks_trend_icon_html($tech);
            $gc2         = $gain >= 0 ? '#e74c3c' : '#3498db';
            $change_html = $prev_close > 0 ? wp_stocks_change_html($current_price, $prev_close, $is_usd) : '-';
            $edit_url    = admin_url('admin.php?page=wp-stocks-portfolio&action=edit_purchase&stock_id=' . $s->id);
            $watch_url   = admin_url('admin-post.php?action=move_to_watch&id=' . $s->id);

            // $xxx (≈xxx円) 形式ヘルパー
            $fmt_usd = function($usd, $fx, $decimals = 2) {
                $jpy = round($usd * $fx);
                return '$' . number_format($usd, $decimals) . ' <span style="color:#aaa;font-size:10px;">(≈' . number_format($jpy) . '円)</span>';
            };

            $price_str2   = $current_price > 0 ? $fmt_usd($current_price, $fx) : '未取得';
            $buy_str      = $buy_price > 0     ? $fmt_usd($buy_price, $fx)     : '-';
            $eval_str     = $eval_value > 0    ? '$' . number_format($current_price * $qty, 2)
                            . ' <span style="color:#aaa;font-size:10px;">(≈' . number_format($eval_value) . '円)</span>' : '-';
            $gain_usd     = $current_price * $qty - $buy_price * $qty;
            $gain_str     = '<span style="color:'.$gc2.';font-weight:bold;">'
                            . ($gain_usd >= 0 ? '+' : '') . '$' . number_format(abs($gain_usd), 2)
                            . ' <span style="font-size:10px;font-weight:normal;">(≈' . ($gain >= 0 ? '+' : '') . number_format($gain) . '円)</span>'
                            . '</span>';
            $div_str      = $annual_div > 0
                            ? '$' . number_format($annual_div / $fx, 2) . ' <span style="color:#aaa;font-size:10px;">(≈' . number_format($annual_div) . '円)</span>'
                            : '-';

            echo '<tr>';
            echo '<td>'.esc_html($s->code).'</td>';
            echo '<td><a href="'.admin_url('admin.php?page=wp-stocks-company&stock_id='.$s->id).'">'.esc_html($s->name).'</a>'.esc_html(wp_stocks_market_segment_suffix($s->market ?? '')).'</td>';
            echo '<td>'.$trend_html.'</td>';
            echo '<td>'.$price_str2.'</td>';
            echo '<td>'.$change_html.'</td>';
            echo '<td>'.$buy_str.'</td>';
            echo '<td>'.number_format($qty).'株</td>';
            echo '<td>'.$eval_str.'</td>';
            echo '<td>'.$gain_str.'</td>';
            echo '<td style="color:'.$gc2.';font-weight:bold;">'.($gain_pct>=0?'+':'').number_format($gain_pct,2).'%</td>';
            echo '<td>'.$div_str.'</td>';
            echo '<td><a href="'.esc_url($edit_url).'" class="button button-small">編集</a> <a href="'.esc_url($watch_url).'" class="button button-small">ウォッチへ</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        $gc = $foreign_sub['gain'] >= 0 ? '#e74c3c' : '#3498db';
        echo '<div style="text-align:right;padding:8px 12px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;margin-bottom:30px;font-size:13px;">';
        echo '外国株小計　評価額：<strong>'.number_format($foreign_sub['eval']).'円</strong>　';
        echo '含み損益：<strong style="color:'.$gc.';">'.($foreign_sub['gain']>=0?'+':'').number_format($foreign_sub['gain']).'円 ('.($foreign_sub['pct']>=0?'+':'').number_format($foreign_sub['pct'],2).'%)</strong>';
        echo '</div>';
    }

    // =============================================
    // 表示④ 投資信託セクション
    // =============================================
    if (!empty($fund_rows)) {
        $type_labels = ['growth' => 'NISA成長投資枠', 'tsumitate' => 'NISAつみたて投資枠', 'tokutei' => '特定口座'];
        echo '<h2 style="margin:20px 0 15px 0;font-size:16px;border-left:4px solid #27ae60;padding-left:10px;">📊 投資信託</h2>';
        echo '<table class="widefat striped" style="font-size:13px;margin-bottom:8px;"><thead><tr>';
        foreach (['ファンド名','区分','基準価額','口数','評価額','含み損益','含み率','チャート'] as $h) echo '<th>'.$h.'</th>';
        echo '</tr></thead><tbody>';
        foreach ($fund_rows as $row) {
            extract($row);
            $gc         = $gain >= 0 ? '#e74c3c' : '#3498db';
            $chart_url  = 'https://finance.yahoo.co.jp/quote/' . $f->fund_code . '/chart';
            echo '<tr>';
            echo '<td><strong>'.esc_html($f->fund_name).'</strong><br><small style="color:#888;">'.esc_html($f->fund_code).'</small></td>';
            echo '<td><span style="font-size:11px;background:#ddeeff;padding:2px 6px;border-radius:3px;">'.esc_html($type_labels[$f->fund_type]??$f->fund_type).'</span></td>';
            echo '<td>'.($today_price?number_format($today_price).'円':'未取得').'</td>';
            echo '<td>'.number_format($f->fund_units).'口</td>';
            echo '<td>'.($eval_value>0?number_format($eval_value).'円':'-').'</td>';
            echo '<td style="color:'.$gc.';font-weight:bold;">'.($gain>=0?'+':'').number_format($gain).'円</td>';
            echo '<td style="color:'.$gc.';font-weight:bold;">'.($gain_pct>=0?'+':'').number_format($gain_pct,2).'%</td>';
            echo '<td><a href="'.esc_url($chart_url).'" target="_blank" class="button button-small">📈 チャート</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        $gc = $fund_total_gain >= 0 ? '#e74c3c' : '#3498db';
        echo '<div style="text-align:right;padding:8px 12px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;margin-bottom:30px;font-size:13px;">';
        echo '投資信託小計　評価額：<strong>'.number_format($fund_total_eval).'円</strong>　';
        echo '含み損益：<strong style="color:'.$gc.';">'.($fund_total_gain>=0?'+':'').number_format($fund_total_gain).'円 ('.($fund_gain_pct>=0?'+':'').number_format($fund_gain_pct,2).'%)</strong>';
        echo '</div>';
    }

    echo '</div>';
}




// --------------------------------------------------
// 企業情報詳細ページ
// --------------------------------------------------
function wp_stocks_company_page() {
    global $wpdb;
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks ORDER BY code ASC");
    if (!$stocks) { echo '<div class="wrap"><h1>企業情報</h1><p>銘柄が登録されていません。</p></div>'; return; }

    $valid_ids = array_map(function($s) { return (int) $s->id; }, $stocks);
    $id = intval($_GET['stock_id'] ?? 0);
    if (!$id || !in_array($id, $valid_ids, true)) {
        $id = (int) $stocks[0]->id;
    }
    $current_ctab = sanitize_text_field($_GET['ctab'] ?? '');

    echo '<div class="wrap">';
    echo '<div style="display:flex;align-items:center;gap:14px;margin-bottom:15px;flex-wrap:wrap;">';
    echo '<h1 style="margin:0;">企業情報</h1>';
    // セクターごとにグループ化（プルダウンが長くなりすぎないように）
    $company_stocks_by_sector = [];
    foreach ($stocks as $s) {
        $sector_label = !empty($s->sector) ? wp_stocks_sector_ja($s->sector) : '未分類';
        $company_stocks_by_sector[$sector_label][] = $s;
    }
    ksort($company_stocks_by_sector);

    echo '<select id="wp_stocks_company_switcher" class="wp-stocks-sector-select" data-placeholder="銘柄を検索..." style="min-width:280px;">';
    foreach ($company_stocks_by_sector as $sector_label => $sector_stocks) {
        echo '<optgroup label="' . esc_attr($sector_label) . '">';
        foreach ($sector_stocks as $s) {
            $sel = ((int) $s->id === $id) ? 'selected' : '';
            echo '<option value="' . esc_attr($s->id) . '" ' . $sel . '>' . esc_html($s->code . ' - ' . $s->name) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
    echo '</div>';
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('wp_stocks_company_switcher');
        if (!el) return;
        el.addEventListener('change', function() {
            var newId = this.value;
            var ctab  = <?php echo json_encode($current_ctab); ?>;
            var url = 'admin.php?page=wp-stocks-company&stock_id=' + encodeURIComponent(newId) + (ctab ? '&ctab=' + encodeURIComponent(ctab) : '');
            window.location.href = url;
        });
    });
    </script>
    <?php
    wp_stocks_render_sector_dropdown_assets();
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) { echo '<p>銘柄が見つかりません。</p></div>'; return; }

    if (isset($_GET['message'])) {
        $m = $_GET['message'];
        if ($m === 'watchlist_saved') echo '<div class="updated"><p>注目株の設定を変更しました。</p></div>';
        if ($m === 'tags_saved') echo '<div class="updated"><p>テーマタグを保存しました。</p></div>';
        if ($m === 'memo_saved') echo '<div class="updated"><p>メモを保存しました。</p></div>';
        if ($m === 'ai_saved')   echo '<div class="updated"><p>AI分析結果を履歴に保存しました。</p></div>';
        if ($m === 'ai_deleted') echo '<div class="updated"><p>AI分析履歴を削除しました。</p></div>';
        if ($m === 'info_saved') echo '<div class="updated"><p>企業情報を更新しました。</p></div>';
        if ($m === 'info_error') echo '<div class="error"><p>企業情報の取得に失敗しました。</p></div>';
        if ($m === 'news_saved') echo '<div class="updated"><p>適時開示を取得・保存しました。</p></div>';
        if ($m === 'news_error') echo '<div class="error"><p>適時開示の取得に失敗しました。</p></div>';
        if ($m === 'news_cleared') echo '<div class="updated"><p>適時開示情報を全削除しました。</p></div>';
    }

    $latest = $wpdb->get_row($wpdb->prepare(
        "SELECT price, previous_close, volume, datetime FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $id
    ));
    $tech = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stock_technicals WHERE stock_id = %d", $id));

    $rg_color  = ($stock->revenue_growth  ?? 0) >= 0 ? '#e74c3c' : '#3498db';
    $eg_color  = ($stock->earnings_growth ?? 0) >= 0 ? '#e74c3c' : '#3498db';
    $pm_color  = ($stock->profit_margin   ?? 0) >= 10 ? '#27ae60' : '#e67e22';
    $per_color = ($stock->per ?? 0) > 0 && ($stock->per ?? 0) < 15 ? '#27ae60' : (($stock->per ?? 0) > 30 ? '#e74c3c' : '#555');
    $pbr_color = ($stock->pbr ?? 0) > 0 && ($stock->pbr ?? 0) < 1 ? '#27ae60' : (($stock->pbr ?? 0) > 3 ? '#e74c3c' : '#555');
    $roe_color = ($stock->roe ?? 0) >= 15 ? '#27ae60' : (($stock->roe ?? 0) < 5 ? '#e74c3c' : '#555');
    $peg_color = ($stock->peg ?? 0) > 0 && ($stock->peg ?? 0) < 1 ? '#27ae60' : (($stock->peg ?? 0) > 2 ? '#e74c3c' : '#555');
    $eq_color  = ($stock->equity_ratio ?? 0) >= 50 ? '#27ae60' : (($stock->equity_ratio ?? 0) >= 30 ? '#555' : '#e74c3c');

    // ★注目株ボタン
    $is_watchlist = intval($stock->is_watchlist ?? 0);
    $star_label   = $is_watchlist ? '★ 注目株に登録中' : '☆ 注目株に追加';
    $star_style   = $is_watchlist
        ? 'background:#f39c12;color:#fff;border-color:#e67e22;'
        : 'background:#fff;color:#888;border-color:#ddd;';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">';
    echo '<h2 style="margin:0;">' . esc_html($stock->code . ' ' . $stock->name . wp_stocks_market_segment_suffix($stock->market ?? '')) . '</h2>';
    if (!empty($stock->market)) {
        $market_badge_colors = ['プライム' => '#0073aa', 'スタンダード' => '#16a085', 'グロース' => '#e67e22'];
        $mb_color = $market_badge_colors[$stock->market] ?? '#888';
        echo '<span style="background:' . $mb_color . ';color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:bold;">' . esc_html($stock->market) . '</span>';
    }
    echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="margin:0;">';
    echo '<input type="hidden" name="action" value="toggle_watchlist">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($stock->id) . '">';
    wp_nonce_field('wp_stocks_watchlist_nonce');
    echo '<button type="submit" class="button" style="' . $star_style . 'font-size:13px;padding:4px 12px;">' . $star_label . '</button>';
    echo '</form>';
    echo '</div>';

    // 次回決算日表示
    if (!empty($stock->earnings_date)) {
        $today_dt = date('Y-m-d');
        $days     = (int)((strtotime($stock->earnings_date) - strtotime($today_dt)) / 86400);
        if ($days >= 0) {
            $ec = $days <= 7 ? '#e74c3c' : ($days <= 30 ? '#f39c12' : '#27ae60');
            echo '<div style="margin-bottom:12px;">'
                . '<span style="background:' . $ec . ';color:#fff;padding:4px 12px;border-radius:4px;font-size:13px;font-weight:bold;">'
                . '&#x1F4C5; 次回決算：' . esc_html($stock->earnings_date) . '（' . $days . '日後）'
                . '</span></div>';
        } else {
            echo '<div style="margin-bottom:12px;">'
                . '<span style="background:#aaa;color:#fff;padding:4px 12px;border-radius:4px;font-size:13px;">'
                . '&#x1F4C5; 前回決算：' . esc_html($stock->earnings_date)
                . '</span></div>';
        }
    }

    // 株価バナー
    $is_usd = ($stock->currency ?? 'JPY') === 'USD';
    $usd_jpy = $is_usd ? wp_stocks_get_usd_jpy() : 1;
    if ($latest) {
        $change_html  = $latest->previous_close ? wp_stocks_change_html($latest->price, $latest->previous_close, $is_usd) : '';
        $volume_str   = $latest->volume ? number_format(intval($latest->volume) / 10000, 1) . '万株' : '-';
        if ($is_usd) {
            $price_display = '$' . number_format($latest->price, 2) . ' <span style="font-size:16px;color:#888;">（≈' . number_format($latest->price * $usd_jpy) . '円）</span>';
        } else {
            $price_display = number_format($latest->price) . '円';
        }
        echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">';
        echo '<div style="font-size:28px;font-weight:bold;">' . $price_display . '</div>';
        echo '<div style="font-size:16px;">' . $change_html . '</div>';
        echo '<div style="font-size:13px;color:#888;">出来高：' . esc_html($volume_str) . '</div>';
        echo '<div style="font-size:13px;">トレンド：' . ($tech ? wp_stocks_trend_icon_html($tech) : '<span style="color:#aaa;">-</span>') . '</div>';
        echo '<div style="font-size:12px;color:#aaa;">' . esc_html($latest->datetime) . '</div>';
        // 適正株価をバナーに表示（セクター平均PER基準・循環参照なし）
        $usd_jpy_banner = $is_usd ? wp_stocks_get_usd_jpy() : 1;
        $fp_data  = wp_stocks_calc_fair_price($stock);
        $fp_parts = [];
        if ($fp_data['actual'] !== null) {
            $fp_val = $fp_data['actual'];
            $fp_parts[] = '実績：' . ($is_usd
                ? '$' . number_format($fp_val, 2) . '(≈' . number_format($fp_val * $usd_jpy_banner) . '円)'
                : number_format($fp_val, 0) . '円') . '（セクター平均PER' . number_format($fp_data['sector_avg_per'], 1) . '倍基準）';
        }
        if ($fp_data['forward'] !== null) {
            $fp_fwd = $fp_data['forward'];
            $fp_parts[] = '予想：' . ($is_usd
                ? '$' . number_format($fp_fwd, 2) . '(≈' . number_format($fp_fwd * $usd_jpy_banner) . '円)'
                : number_format($fp_fwd, 0) . '円') . '（セクター平均PER' . number_format($fp_data['sector_avg_per_forward'], 1) . '倍基準）';
        }
        if (!empty($fp_parts)) {
            echo '<div style="font-size:12px;color:#e67e22;border-left:2px solid #e67e22;padding-left:8px;">'
                . '📐 適正株価　' . implode('　', $fp_parts)
                . '</div>';
        }
        echo '</div>';
    }

    // 市場区分（日本株のみ・株価の下・タブの上に表示）
    if (isset($_GET['message']) && $_GET['message'] === 'market_segment_saved') {
        echo '<div class="updated"><p>市場区分を保存しました。</p></div>';
    }
    if (!$is_usd) {
        echo '<div style="margin-bottom:15px;">';
        echo '<h3>&#x1F3E2; 市場区分</h3>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="update_market_segment">';
        echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('wp_stocks_market_segment_nonce');
        echo '<select name="market_segment" style="margin-right:10px;">';
        foreach (['' => '未設定', 'プライム' => 'プライム', 'スタンダード' => 'スタンダード', 'グロース' => 'グロース'] as $mval => $mlabel) {
            $msel = ($stock->market ?? '') === $mval ? 'selected' : '';
            echo '<option value="' . esc_attr($mval) . '" ' . $msel . '>' . esc_html($mlabel) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="button button-primary">保存</button></form>';
        echo '</div>';
    }

    // ===================== タブUI =====================
    $active_tab = sanitize_text_field($_GET['ctab'] ?? 'info');
    if (!in_array($active_tab, ['info','chart','finance','oscillator','news','ai','edit'])) $active_tab = 'info';
    $tab_defs = [
        'info'       => '&#x1F4CB; 基本情報',
        'chart'      => '&#x1F4CA; チャート',
        'finance'    => '&#x1F4B0; 財務',
        'oscillator' => '&#x1F4C8; テクニカル分析', // ★変更：「オシレータ分析」から改称（内部のctabキーはoscillatorのまま）
        'news'       => '&#x1F4F0; 適時開示',
        'ai'         => '&#x1F916; AI分析',
        'edit'       => '&#x270F;&#xFE0F; 編集',
    ];
    echo '<div class="company-tabs-wrapper" style="margin-top:15px;">';
    echo '<ul style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0 0 20px 0;padding:0;list-style:none;flex-wrap:wrap;">';
    foreach ($tab_defs as $key => $label) {
        $is_active = $active_tab === $key;
        $tab_url   = add_query_arg(['ctab' => $key], admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id));
        $style = $is_active
            ? 'display:block;padding:10px 18px;background:#0073aa;color:#fff;text-decoration:none;font-size:13px;font-weight:bold;border-radius:4px 4px 0 0;'
            : 'display:block;padding:10px 18px;background:#f1f1f1;color:#555;text-decoration:none;font-size:13px;border-radius:4px 4px 0 0;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="' . esc_url($tab_url) . '" style="' . $style . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    // ===== テクニカル分析タブ（旧オシレータ分析：ミニチャート→複合判定→テクニカル総合→テクニカル指標一覧→オシレータ分析） =====
    if ($active_tab === 'oscillator') {
        echo '<div id="tab-oscillator">';
        if (!$tech) {
            echo '<p style="color:#888;">テクニカル指標がまだ計算されていません。日次のテクニカル計算Cron実行後に反映されます。</p>';
        } else {
            $osc_price   = $latest->price ?? null;
            $mini_symbol = $is_usd ? $stock->code : $stock->code . '.T';

            // ★変更：ミニチャートより先にOHLCVを取得し、今日の株価テーブル／株価トレンドでも再利用する
            $mini_ohlcv = wp_stocks_get_ohlcv($mini_symbol);

            // ① 今日の株価テーブル（始値・高値・安値・終値・出来高）＋株価トレンド（5日線／25日線／75日線）
            if ($mini_ohlcv && count($mini_ohlcv) > 0) {
                $today_ohlcv      = end($mini_ohlcv);
                $today_date_disp  = date('n月j日', strtotime($today_ohlcv['date']));

                echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px;margin-bottom:15px;width:80%;box-sizing:border-box;">';
                echo '<h4 style="margin:0 0 10px 0;font-size:13px;color:#555;">' . esc_html($today_date_disp) . 'の株価</h4>';
                echo '<table style="width:100%;border-collapse:collapse;font-size:13px;text-align:center;margin-bottom:10px;">';
                echo '<thead><tr style="background:#f8f9fa;">';
                echo '<th style="padding:6px;border:1px solid #eee;">始値</th><th style="padding:6px;border:1px solid #eee;">高値</th><th style="padding:6px;border:1px solid #eee;">安値</th><th style="padding:6px;border:1px solid #eee;">終値</th>';
                echo '</tr></thead><tbody><tr>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['open'], 1) . '</td>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['high'], 1) . '</td>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['low'], 1) . '</td>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['close'], 1) . '</td>';
                echo '</tr></tbody></table>';
                echo '<div style="font-size:12px;color:#888;margin-bottom:15px;">出来高　' . number_format($today_ohlcv['volume'] ?? 0) . '株</div>';

                // ★追加：株価・MA5・MA25・MA75の「継続／転換」状態（直近3日分の値から判定）
                $closes_for_trend  = array_column($mini_ohlcv, 'close');
                $price_trend_state = wp_stocks_calc_trend_state(array_slice($closes_for_trend, -3));
                $ma5_state  = wp_stocks_calc_trend_state([
                    wp_stocks_ma_n_days_ago($closes_for_trend, 5, 2),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 5, 1),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 5, 0),
                ]);
                $ma25_state = wp_stocks_calc_trend_state([
                    wp_stocks_ma_n_days_ago($closes_for_trend, 25, 2),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 25, 1),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 25, 0),
                ]);
                $ma75_state = wp_stocks_calc_trend_state([
                    wp_stocks_ma_n_days_ago($closes_for_trend, 75, 2),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 75, 1),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 75, 0),
                ]);

                echo '<table style="width:100%;border-collapse:collapse;font-size:12px;text-align:center;">';
                echo '<thead><tr style="background:#f8f9fa;">';
                echo '<th style="padding:6px;border:1px solid #eee;">株価トレンド</th><th style="padding:6px;border:1px solid #eee;">5日線</th><th style="padding:6px;border:1px solid #eee;">25日線</th><th style="padding:6px;border:1px solid #eee;">75日線</th>';
                echo '</tr></thead><tbody><tr>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($price_trend_state) . '</td>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($ma5_state) . '</td>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($ma25_state) . '</td>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($ma75_state) . '</td>';
                echo '</tr></tbody></table>';
                echo '</div>';
            }

            // ① ミニチャート（直近90日のローソク足）
            if ($mini_ohlcv && count($mini_ohlcv) > 5) {
                $mini_recent = array_slice($mini_ohlcv, -90);
                $mini_candle_data = array_map(function($d) {
                    return [
                        'x' => $d['date'],
                        'y' => [round($d['open'], 1), round($d['high'], 1), round($d['low'], 1), round($d['close'], 1)],
                    ];
                }, $mini_recent);
                $mini_candle_json = json_encode($mini_candle_data);
                echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin-bottom:20px;width:80%;box-sizing:border-box;">';
                echo '<h4 style="margin:0 0 8px 0;font-size:12px;color:#888;">直近90日の株価推移</h4>';
                echo '<div id="miniChart"></div>';
                echo '</div>';
                echo '<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>';
                echo '<script>
                new ApexCharts(document.getElementById("miniChart"), {
                    chart: { type: "candlestick", height: 250, toolbar: { show: false } },
                    series: [{ data: ' . $mini_candle_json . ' }],
                    xaxis: { type: "category", labels: { show: false } },
                    yaxis: { labels: { formatter: function(v){ return Number(v).toLocaleString(); } } },
                    plotOptions: { candlestick: { colors: { upward: "#e74c3c", downward: "#3498db" } } },
                    tooltip: { x: { show: true } },
                }).render();
                </script>';
            }

            $judge_badge = function($judge) {
                if ($judge === 'buy')  return '<span style="background:#3498db;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold;">買い</span>';
                if ($judge === 'sell') return '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold;">売り</span>';
                return '<span style="background:#ddd;color:#888;padding:2px 8px;border-radius:3px;font-size:11px;">中立</span>';
            };

            // ★変更：テクニカル指標の一覧テーブルより先に、各判定要素だけを計算しておく
            // （②「テクニカル総合」を③「テクニカル指標の一覧」の上に出すため）
            $ma_judge = null; $ma_reason = '並びが揃っておらずレンジ気味';
            if ($tech->ma5 !== null && $tech->ma25 !== null && $tech->ma75 !== null) {
                if ($tech->ma5 > $tech->ma25 && $tech->ma25 > $tech->ma75)     { $ma_judge = 'buy';  $ma_reason = 'MA5＞MA25＞MA75の並びで上昇トレンド'; }
                elseif ($tech->ma5 < $tech->ma25 && $tech->ma25 < $tech->ma75) { $ma_judge = 'sell'; $ma_reason = 'MA75＞MA25＞MA5の並びで下降トレンド'; }
            }

            $cross_judge = null; $cross_reason = '直近のクロスは発生していません';
            if (($tech->cross_signal ?? null) === 'golden') { $cross_judge = 'buy';  $cross_reason = 'MA5がMA25/MA75を下から上に突き抜けました（打診買い）'; }
            if (($tech->cross_signal ?? null) === 'dead')   { $cross_judge = 'sell'; $cross_reason = 'MA5がMA25/MA75を上から下に突き抜けました（打診売り）'; }

            $macd_judge = null; $macd_reason = 'ゼロライン付近で方向感に乏しい';
            if ($tech->macd !== null && $tech->macd_signal !== null) {
                if ($tech->macd > $tech->macd_signal && $tech->macd < 0)      { $macd_judge = 'buy';  $macd_reason = 'ゼロライン以下の低い位置でMACDがシグナルを上抜け'; }
                elseif ($tech->macd < $tech->macd_signal && $tech->macd > 0) { $macd_judge = 'sell'; $macd_reason = 'ゼロライン以上の高い位置でMACDがシグナルを下抜け'; }
                elseif ($tech->macd > 0 && $tech->macd_signal > 0)           { $macd_judge = 'buy';  $macd_reason = 'MACD・シグナルともにゼロラインより上で推移'; }
                elseif ($tech->macd < 0 && $tech->macd_signal < 0)           { $macd_judge = 'sell'; $macd_reason = 'MACD・シグナルともにゼロラインより下で推移'; }
            }

            $dmi_judge = null; $dmi_reason = 'ADXが25未満のためトレンド不明瞭';
            if ($tech->plus_di !== null && $tech->minus_di !== null && $tech->adx !== null && $tech->adx >= 25) {
                if ($tech->plus_di > $tech->minus_di) { $dmi_judge = 'buy';  $dmi_reason = '+DIが-DIを上回りADX' . $tech->adx . 'で明確な上昇トレンド'; }
                else                                   { $dmi_judge = 'sell'; $dmi_reason = '-DIが+DIを上回りADX' . $tech->adx . 'で明確な下降トレンド'; }
            }

            $sar_judge = null; $sar_reason = '直近の転換はありません';
            if (intval($tech->sar_reversal ?? 0) === 1) {
                if (($tech->sar_trend ?? '') === 'up')   { $sar_judge = 'buy';  $sar_reason = 'パラボリックSARが下から上へ転換'; }
                if (($tech->sar_trend ?? '') === 'down') { $sar_judge = 'sell'; $sar_reason = 'パラボリックSARが上から下へ転換'; }
            }

            // ★追加：テクニカル総合（MA・GC/DC・MACD・DMI・SARの多数決）
            $trend_judges     = array_filter([$ma_judge, $cross_judge, $macd_judge, $dmi_judge, $sar_judge], function($j) { return $j !== null; });
            $trend_buy_count  = count(array_filter($trend_judges, function($j) { return $j === 'buy'; }));
            $trend_sell_count = count(array_filter($trend_judges, function($j) { return $j === 'sell'; }));
            if (!empty($trend_judges) && $trend_buy_count > $trend_sell_count)      $trend_overall = 'buy';
            elseif (!empty($trend_judges) && $trend_sell_count > $trend_buy_count)  $trend_overall = 'sell';
            else                                                                     $trend_overall = 'neutral';

            // ② 複合判定（論点3〜5：固定重み・5段階・フィボナッチ加味）
            echo '<h3 style="margin-top:0;">&#x1F3AF; 複合判定</h3>';
            $composite_score  = intval($tech->composite_score ?? 0);
            $composite_detail = json_decode($tech->composite_detail ?? '[]', true);
            if (!is_array($composite_detail)) $composite_detail = [];
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;max-width:780px;margin-bottom:25px;">';
            echo '<div style="margin-bottom:10px;">' . wp_stocks_composite_badge_html($composite_score) . '</div>';
            if (!empty($composite_detail)) {
                echo '<ul style="margin:0;padding-left:20px;font-size:12px;color:#555;line-height:1.8;">';
                foreach ($composite_detail as $d) echo '<li>' . esc_html($d) . '</li>';
                echo '</ul>';
            }
            echo '</div>';

            // ③ テクニカル指標の一覧（トレンド系：MA・GC/DC・MACD・DMI・SAR）
            echo '<h3>&#x1F4C8; テクニカル指標の一覧</h3>';
            echo '<div style="margin-bottom:15px;">テクニカル総合：' . $judge_badge($trend_overall) . '</div>';
            echo '<table class="widefat fixed striped" style="max-width:780px;margin-bottom:25px;"><thead><tr><th style="width:190px;">指標</th><th style="width:170px;">値</th><th style="width:90px;">判定</th><th>判定理由</th></tr></thead><tbody>';

            echo '<tr><th>移動平均線（MA5/25/75）</th><td>'
                . ($tech->ma5  !== null ? number_format($tech->ma5, 1)  . '円 / ' : '- / ')
                . ($tech->ma25 !== null ? number_format($tech->ma25, 1) . '円 / ' : '- / ')
                . ($tech->ma75 !== null ? number_format($tech->ma75, 1) . '円' : '-')
                . '</td><td>' . ($ma_judge !== null ? $judge_badge($ma_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($ma_reason) . '</td></tr>';

            echo '<tr><th>ゴールデンクロス／デッドクロス</th><td>' . esc_html($tech->cross_signal ?? '-') . '</td><td>' . ($cross_judge !== null ? $judge_badge($cross_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($cross_reason) . '</td></tr>';

            echo '<tr><th>MACD / Signal</th><td>' . ($tech->macd !== null ? number_format($tech->macd, 2) : '-') . ' / ' . ($tech->macd_signal !== null ? number_format($tech->macd_signal, 2) : '-') . '</td><td>' . ($macd_judge !== null ? $judge_badge($macd_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($macd_reason) . '</td></tr>';

            echo '<tr><th>DMI（+DI / -DI / ADX）</th><td>' . ($tech->plus_di ?? '-') . ' / ' . ($tech->minus_di ?? '-') . ' / ' . ($tech->adx ?? '-') . '</td><td>' . ($dmi_judge !== null ? $judge_badge($dmi_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($dmi_reason) . '</td></tr>';

            echo '<tr><th>パラボリックSAR</th><td>' . ($tech->sar !== null ? number_format($tech->sar, 2) : '-') . '</td><td>' . ($sar_judge !== null ? $judge_badge($sar_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($sar_reason) . '</td></tr>';

            echo '</tbody></table>';

            // ④ オシレータ分析（既存の判定ロジックを維持し、判定理由列を明示）
            $rsi_judge = null; $rsi_reason = '-';
            if ($tech->rsi !== null) {
                if ($tech->rsi >= 70)      { $rsi_judge = 'sell'; $rsi_reason = 'RSI' . $tech->rsi . '（70以上・買われ過ぎ）'; }
                elseif ($tech->rsi <= 30)  { $rsi_judge = 'buy';  $rsi_reason = 'RSI' . $tech->rsi . '（30以下・売られ過ぎ）'; }
                else                        { $rsi_judge = 'neutral'; $rsi_reason = 'RSI' . $tech->rsi . '（中立圏）'; }
            }
            $rci_judge = null; $rci_reason = '-';
            if ($tech->rci !== null) {
                if ($tech->rci >= 80)      { $rci_judge = 'sell'; $rci_reason = 'RCI' . $tech->rci . '（+80以上・買われ過ぎ）'; }
                elseif ($tech->rci <= -80) { $rci_judge = 'buy';  $rci_reason = 'RCI' . $tech->rci . '（-80以下・売られ過ぎ）'; }
                else                        { $rci_judge = 'neutral'; $rci_reason = 'RCI' . $tech->rci . '（中立圏）'; }
            }
            // ★変更：バンドウォーク（強いトレンド中に±2σへ張り付いたまま推移する状態）では
            // バンド突破を逆張りシグナルとして扱わず、トレンド継続の裏付けとして中立扱いにする
            $bb_judge = null; $bb_reason = '-';
            if ($tech->bb_upper !== null && $tech->bb_lower !== null && $osc_price !== null) {
                $bb_is_trending = ($tech->adx !== null && $tech->adx >= 25);
                if ($osc_price >= $tech->bb_upper) {
                    if ($bb_is_trending && ($tech->plus_di ?? 0) > ($tech->minus_di ?? 0)) {
                        $bb_judge  = 'neutral';
                        $bb_reason = 'バンドウォーク中（ADX' . $tech->adx . '・+DI優勢）のため逆張りシグナルとしては扱いません';
                    } else {
                        $bb_judge  = 'sell';
                        $bb_reason = '価格が+2&sigma;ラインに到達／突破';
                    }
                } elseif ($osc_price <= $tech->bb_lower) {
                    if ($bb_is_trending && ($tech->minus_di ?? 0) > ($tech->plus_di ?? 0)) {
                        $bb_judge  = 'neutral';
                        $bb_reason = 'バンドウォーク中（ADX' . $tech->adx . '・-DI優勢）のため逆張りシグナルとしては扱いません';
                    } else {
                        $bb_judge  = 'buy';
                        $bb_reason = '価格が-2&sigma;ラインに到達／突破';
                    }
                } else {
                    $bb_judge  = 'neutral';
                    $bb_reason = 'バンド内で推移中';
                }
            }
            $stoch_judge = null; $stoch_reason = '-';
            if ($tech->stoch_k !== null) {
                if ($tech->stoch_k >= 80)      { $stoch_judge = 'sell'; $stoch_reason = '%K' . $tech->stoch_k . '（80以上・買われ過ぎゾーン）'; }
                elseif ($tech->stoch_k <= 20)  { $stoch_judge = 'buy';  $stoch_reason = '%K' . $tech->stoch_k . '（20以下・売られ過ぎゾーン）'; }
                else                            { $stoch_judge = 'neutral'; $stoch_reason = '%K' . $tech->stoch_k . '（中立圏）'; }
            }

            $judges = array_filter([$rsi_judge, $rci_judge, $bb_judge, $stoch_judge], function($j) { return $j !== null; });
            $buy_count  = count(array_filter($judges, function($j) { return $j === 'buy'; }));
            $sell_count = count(array_filter($judges, function($j) { return $j === 'sell'; }));
            if (!empty($judges) && $buy_count > $sell_count)      $overall = 'buy';
            elseif (!empty($judges) && $sell_count > $buy_count)  $overall = 'sell';
            else                                                  $overall = 'neutral';

            echo '<h3>&#x1F4C9; オシレータ分析</h3>';
            echo '<p style="color:#666;font-size:13px;">株価の水準とは無関係に「買われ過ぎ」「売られ過ぎ」を判定する指標です。判定日時：'
                . ($tech->calculated_at ? esc_html(date('Y/m/d H:i', strtotime($tech->calculated_at))) : '-') . '</p>';
            echo '<div style="margin-bottom:15px;">オシレータ総合：' . $judge_badge($overall) . '</div>';

            echo '<table class="widefat fixed striped" style="max-width:780px;margin-bottom:25px;"><thead><tr><th style="width:190px;">指標</th><th style="width:170px;">値</th><th style="width:90px;">判定</th><th>判定理由</th></tr></thead><tbody>';
            echo '<tr><th>RSI（14日）</th><td>' . ($tech->rsi !== null ? esc_html($tech->rsi) : '-') . '</td><td>' . $judge_badge($rsi_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($rsi_reason) . '</td></tr>';
            echo '<tr><th>RCI（9日）</th><td>' . ($tech->rci !== null ? esc_html($tech->rci) : '-') . '</td><td>' . $judge_badge($rci_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($rci_reason) . '</td></tr>';
            echo '<tr><th>ボリンジャーバンド（20日, &plusmn;2&sigma;）</th><td>'
                . ($tech->bb_upper !== null ? number_format($tech->bb_upper, 1) : '-') . ' / '
                . ($tech->bb_mid   !== null ? number_format($tech->bb_mid, 1)   : '-') . ' / '
                . ($tech->bb_lower !== null ? number_format($tech->bb_lower, 1) : '-')
                . '</td><td>' . $judge_badge($bb_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($bb_reason) . '</td></tr>';
            echo '<tr><th>ストキャス %K/%D（14日）</th><td>' . ($tech->stoch_k !== null ? esc_html($tech->stoch_k) . ' / ' . ($tech->stoch_d !== null ? esc_html($tech->stoch_d) : '-') : '-') . '</td><td>' . $judge_badge($stoch_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($stoch_reason) . '</td></tr>';
            $fib_reason = intval($tech->fib_near ?? 0) === 1
                ? 'フィボナッチ' . ($tech->fib_level ?? '-') . '水準に接近'
                : '主要水準から離れています';
            echo '<tr><th>フィボナッチ（直近60日）</th><td>' . ($tech->fib_level !== null ? esc_html($tech->fib_level) . ' 水準' : '-') . '</td><td>'
                . (intval($tech->fib_near ?? 0) === 1
                    ? '<span style="background:#e67e22;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold;">接近</span>'
                    : '<span style="background:#ddd;color:#888;padding:2px 8px;border-radius:3px;font-size:11px;">-</span>')
                . '</td><td style="font-size:12px;color:#666;">' . esc_html($fib_reason) . '</td></tr>';
            echo '</tbody></table>';
        }

        // ★追加：チャートタブと同じ「外部チャートで確認する」リンク集をテクニカル分析タブの末尾にも表示
        echo wp_stocks_tradingview_widget($stock->code, 450, $is_usd);

        echo '</div>';
    }

    // ===== 基本情報タブ =====
    if ($active_tab === 'info') {
    echo '<div id="tab-info">';

    // ① 詳細テーブル（業種・産業・ウェブサイト・時価総額・売上高・決算予定日・情報更新日）
    echo '<table class="widefat fixed" style="max-width:700px;margin-bottom:25px;"><tbody>';
    foreach ([
        ['業種',       $stock->sector   ?? ''],
        ['産業',       $stock->industry ?? ''],
        ['ウェブサイト', $stock->website ?? ''],
        ['時価総額',   ($stock->market_cap ?? 0) ? number_format(intval($stock->market_cap) / 100000000) . '億円' : ''],
        ['売上高',     ($stock->revenue   ?? 0) ? number_format(intval($stock->revenue)    / 1000000) . '百万円' : ''],
        ['決算予定日', !empty($stock->earnings_date) ? $stock->earnings_date : ''],
        ['情報更新日', $stock->info_updated_at ? date('Y/m/d H:i', strtotime($stock->info_updated_at)) : '未取得'],
    ] as [$label, $val]) {
        if (empty($val)) continue;
        echo '<tr><th style="width:150px;">' . esc_html($label) . '</th>';
        if ($label === 'ウェブサイト') echo '<td><a href="' . esc_url($val) . '" target="_blank">' . esc_html($val) . '</a></td>';
        else echo '<td>' . esc_html($val) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    // 四季報情報（表示のみ・入力は編集タブへ）
    if (!$is_usd && !empty($stock->shikiho)) {
        echo '<h3>&#x1F4D6; 四季報情報</h3>';
        $updated = $stock->shikiho_updated_at ? date('Y/m/d H:i', strtotime($stock->shikiho_updated_at)) : '';
        echo '<div style="background:#fffef0;border:1px solid #e8d44d;border-radius:8px;padding:16px;margin-bottom:25px;">';
        if ($updated) echo '<div style="font-size:11px;color:#888;margin-bottom:8px;">更新日：' . esc_html($updated) . '</div>';
        echo '<pre style="white-space:pre-wrap;word-wrap:break-word;font-family:inherit;font-size:13px;line-height:1.8;margin:0;">' . esc_html($stock->shikiho) . '</pre>';
        echo '</div>';
    }
    
    // ② 財務スコアカード（指標・値・点数・評価の4列）
    echo '<h3>財務スコアカード</h3>';
    $score_result = wp_stocks_calc_score($stock);
    $sc_score     = $score_result['score'];
    $sc_judgment  = $score_result['judgment'];
    $sc_detail    = $score_result['detail'];

    // 指標ごとの実際の値
    // 適正株価計算（EPS × セクター平均PER。自社PERは使わないため循環参照なし）
    $is_usd_stock = ($stock->currency ?? 'JPY') === 'USD';
    $fp_data                            = wp_stocks_calc_fair_price($stock);
    $fair_price_actual                  = $fp_data['actual'];
    $fair_price_forward                 = $fp_data['forward'];
    $fair_price_sector_avg_per          = $fp_data['sector_avg_per'];
    $fair_price_sector_avg_per_forward  = $fp_data['sector_avg_per_forward'];

    $sc_values = [
        'PER'       => ($stock->per ?? 0) > 0 ? number_format($stock->per, 1) . '倍' : 'N/A',
        'PBR'       => ($stock->pbr ?? 0) > 0 ? number_format($stock->pbr, 2) . '倍' : 'N/A',
        'ROE'       => ($stock->roe ?? 0) != 0 ? number_format($stock->roe, 1) . '%' : 'N/A',
        '自己資本比率' => ($stock->equity_ratio ?? 0) > 0 ? number_format($stock->equity_ratio, 1) . '%' : 'N/A',
        '配当利回り' => ($stock->dividend_yield ?? 0) > 0 ? number_format($stock->dividend_yield, 2) . '%' : 'N/A',
        'PEG'       => ($stock->peg ?? 0) > 0 ? number_format($stock->peg, 2) : 'N/A',
        '利益率'    => ($stock->profit_margin ?? 0) != 0 ? number_format($stock->profit_margin, 1) . '%' : 'N/A',
    ];

    echo '<div style="background:#fff;border:2px solid ' . $sc_judgment['color'] . ';border-radius:8px;padding:20px;margin-bottom:25px;">';
    echo '<div style="display:flex;align-items:center;gap:20px;margin-bottom:15px;">';
    echo '<div style="text-align:center;"><div style="font-size:48px;font-weight:bold;color:' . $sc_judgment['color'] . ';">' . $sc_score . '</div><div style="font-size:12px;color:#888;">/ 100点</div></div>';
    echo '<div><div style="font-size:22px;font-weight:bold;color:' . $sc_judgment['color'] . ';">' . $sc_judgment['label'] . '</div><div style="font-size:12px;color:#888;margin-top:4px;">財務スコアカード</div></div>';
    echo '</div>';
    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="background:#f8f9fa;">';
    echo '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">指標</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">値</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">点数</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">評価</th>';
    echo '</tr></thead><tbody>';
    foreach ($sc_detail as $name => $d) {
        $c = $d['点数'] >= 10 ? '#27ae60' : ($d['点数'] >= 5 ? '#f39c12' : '#e74c3c');
        if ($d['評価'] === 'N/A') $c = '#888';
        echo '<tr>';
        echo '<td style="padding:5px 10px;border:1px solid #ddd;">' . esc_html($name) . '</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;">' . esc_html($sc_values[$name] ?? '-') . '</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;color:' . $c . ';">' . $d['点数'] . '点</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;color:' . $c . ';">' . esc_html($d['評価']) . '</td>';
        echo '</tr>';
    }
    // 適正株価行を追加（セクター平均PER基準・循環参照なし）
    if ($fair_price_actual !== null || $fair_price_forward !== null) {
        $usd_jpy_fp = $is_usd_stock ? wp_stocks_get_usd_jpy() : 1;
        if ($fair_price_actual !== null) {
            $fp_str = $is_usd_stock
                ? '$' . number_format($fair_price_actual, 2) . ' <span style="color:#aaa;font-size:11px;">(≈' . number_format($fair_price_actual * $usd_jpy_fp) . '円)</span>'
                : number_format($fair_price_actual, 0) . '円';
            echo '<tr style="background:#fff8e1;">';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;font-weight:bold;">適正株価（実績）</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;color:#e67e22;">' . $fp_str . '</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;color:#888;">-</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-size:11px;color:#888;">EPS×セクター平均PER(' . number_format($fair_price_sector_avg_per, 1) . '倍)</td>';
            echo '</tr>';
        }
        if ($fair_price_forward !== null) {
            $fp_fwd_str = $is_usd_stock
                ? '$' . number_format($fair_price_forward, 2) . ' <span style="color:#aaa;font-size:11px;">(≈' . number_format($fair_price_forward * $usd_jpy_fp) . '円)</span>'
                : number_format($fair_price_forward, 0) . '円';
            echo '<tr style="background:#e8f8f5;">';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;font-weight:bold;">適正株価（予想）</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;color:#27ae60;">' . $fp_fwd_str . '</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;color:#888;">-</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-size:11px;color:#888;">予想EPS×セクター平均PER(' . number_format($fair_price_sector_avg_per_forward, 1) . '倍)</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';

    // ③ 株価指標カード
    echo '<h3>株価指標</h3>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
    $cards = [
        ['PER（実績）', ($stock->per ?? 0) > 0 ? number_format($stock->per, 1) . '倍' : 'N/A', $per_color, 'PER<15で割安'],
        ['PER（予想）', ($stock->forward_per ?? 0) > 0 ? number_format($stock->forward_per, 1) . '倍' : 'N/A', $per_color, '来期予想PER'],
        ['PBR',         ($stock->pbr ?? 0) > 0 ? number_format($stock->pbr, 2) . '倍' : 'N/A', $pbr_color, 'PBR<1で割安'],
        ['EPS（実績）', ($stock->eps ?? 0) != 0 ? number_format($stock->eps, 1) . '円' : 'N/A', '#555', '1株当たり利益'],
        ['EPS（予想）', ($stock->forward_eps ?? 0) != 0 ? number_format($stock->forward_eps, 1) . '円' : 'N/A', '#555', '来期予想EPS'],
        ['ROE',         ($stock->roe ?? 0) != 0 ? number_format($stock->roe, 1) . '%' : 'N/A', $roe_color, 'ROE>=15%が優良'],
        ['ROA',         ($stock->roa ?? 0) != 0 ? number_format($stock->roa, 1) . '%' : 'N/A', '#555', '総資産利益率'],
        ['PEG',         ($stock->peg ?? 0) > 0 ? number_format($stock->peg, 2) : 'N/A', $peg_color, 'PEG<1で割安成長（Yahoo・予想ベース寄り）'],
        ['PEGトレーリング', ($stock->peg_trailing ?? 0) > 0 ? number_format($stock->peg_trailing, 2) : 'N/A', '#555', '実績PER÷実績利益成長率'],
        ['配当利回り',  ($stock->dividend_yield ?? 0) > 0 ? number_format($stock->dividend_yield, 2) . '%' : 'N/A', '#27ae60', '年間配当÷株価'],
        ['自己資本比率',($stock->equity_ratio ?? 0) > 0 ? number_format($stock->equity_ratio, 1) . '%' : 'N/A', $eq_color, '>=50%が安全の目安'],
    ];
    foreach ($cards as [$label, $val, $color, $tip]) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;text-align:center;min-width:110px;" title="' . esc_attr($tip) . '">';
        echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . $label . '</div>';
        echo '<div style="font-size:18px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // ④ 業績指標カード
    echo '<h3>業績指標</h3>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:25px;">';
    foreach ([
        ['売上成長率', number_format($stock->revenue_growth ?? 0, 1) . '%', $rg_color],
        ['利益成長率', number_format($stock->earnings_growth ?? 0, 1) . '%', $eg_color],
        ['利益率',     number_format($stock->profit_margin ?? 0, 1) . '%', $pm_color],
        ['従業員数',   number_format($stock->employees ?? 0) . '人', '#555'],
        ['時価総額',   ($stock->market_cap ?? 0) > 0 ? number_format(intval($stock->market_cap) / 100000000) . '億円' : 'N/A', '#555'],
    ] as [$label, $val, $color]) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;text-align:center;min-width:110px;">';
        echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . $label . '</div>';
        echo '<div style="font-size:18px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '</div>'; // tab-info
    } // end info tab

    // ===== チャートタブ =====
    if ($active_tab === 'chart') {
    echo '<div id="tab-chart">';

    // ⑤ チャート
    echo '<h3>チャート</h3>';
    $is_usd_chart = ($stock->currency ?? 'JPY') === 'USD';
    $chart_symbol = $is_usd_chart ? $stock->code : $stock->code . '.T';
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$chart_symbol}?interval=1d&range=6mo";
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
        'timeout' => 20,
    ]);
    $body   = json_decode(wp_remote_retrieve_body($response), true);
    $result = $body['chart']['result'][0] ?? null;

    if ($result) {
        $timestamps = $result['timestamp'] ?? [];
        $opens      = $result['indicators']['quote'][0]['open']   ?? [];
        $highs      = $result['indicators']['quote'][0]['high']   ?? [];
        $lows       = $result['indicators']['quote'][0]['low']    ?? [];
        $closes     = $result['indicators']['quote'][0]['close']  ?? [];
        $volumes    = $result['indicators']['quote'][0]['volume'] ?? [];

        $candle_data = [];
        $line_data   = [];
        $volume_data = [];

        foreach ($timestamps as $i => $ts) {
            if (!isset($closes[$i]) || $closes[$i] === null) continue;
            $candle_data[] = [
                'time'  => date('Y-m-d', $ts),
                'open'  => round(floatval($opens[$i]  ?? $closes[$i]), 1),
                'high'  => round(floatval($highs[$i]  ?? $closes[$i]), 1),
                'low'   => round(floatval($lows[$i]   ?? $closes[$i]), 1),
                'close' => round(floatval($closes[$i]), 1),
            ];
            $line_data[] = [
                'time'  => date('Y-m-d', $ts),
                'value' => round(floatval($closes[$i]), 1),
            ];
            $volume_data[] = [
                'time'  => date('Y-m-d', $ts),
                'value' => intval($volumes[$i] ?? 0),
                'color' => floatval($closes[$i]) >= floatval($opens[$i] ?? $closes[$i]) ? '#e74c3c88' : '#3498db88',
            ];
        }

        $candle_json = json_encode($candle_data);
        $line_json   = json_encode($line_data);
        $volume_json = json_encode($volume_data);
        ?>
        <div style="margin-bottom:20px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;max-width:90%;margin-left:auto;margin-right:auto;">
            <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:13px;color:#888;">表示：</span>
                <button onclick="setChartType('candlestick')" class="button button-small" id="btn_candle" style="background:#0073aa;color:#fff;">ローソク足</button>
                <button onclick="setChartType('line')" class="button button-small" id="btn_line">ライン</button>
                <span style="font-size:13px;color:#888;margin-left:10px;">サブ：</span>
                <button onclick="setSubChart('volume')" class="button button-small" id="btn_vol" style="background:#0073aa;color:#fff;">出来高</button>
                <button onclick="setSubChart('macd')"   class="button button-small" id="btn_macd">MACD</button>
                <button onclick="setSubChart('rsi')"    class="button button-small" id="btn_rsi">RSI</button>
                <button onclick="setSubChart('rci')"    class="button button-small" id="btn_rci">RCI</button>
                <button onclick="setSubChart('dmi')"    class="button button-small" id="btn_dmi">DMI</button>
                <span style="font-size:13px;color:#888;margin-left:10px;">表示：</span>
                <button onclick="toggleMA()"  class="button button-small" id="btn_ma"  style="background:#0073aa;color:#fff;">MA</button>
                <button onclick="toggleBB()"  class="button button-small" id="btn_bb"  style="background:#0073aa;color:#fff;">BB</button>
                <button onclick="toggleIchimoku()" class="button button-small" id="btn_ichimoku" style="background:#0073aa;color:#fff;">一目</button>
            </div>
            <div id="lwChart" style="width:100%;height:700px;"></div>
            <div id="lwChartHandle" style="width:100%;height:8px;background:#f0f0f0;cursor:ns-resize;display:flex;align-items:center;justify-content:center;border-top:1px solid #ddd;border-bottom:1px solid #ddd;margin:2px 0;">
                <div style="width:40px;height:3px;background:#bbb;border-radius:2px;"></div>
            </div>
            <div id="lwSubChart" style="width:100%;height:150px;margin-top:0;"></div>
        </div>

        <script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
        <script>
        var candleRaw  = <?php echo $candle_json; ?>;
        var lineRaw    = <?php echo $line_json; ?>;
        var volumeRaw  = <?php echo $volume_json; ?>;

        // ボリンジャーバンド計算
        function calcBB(data, period, sigma) {
            var result = { upper: [], mid: [], lower: [] };
            for (var i = period - 1; i < data.length; i++) {
                var slice = data.slice(i - period + 1, i + 1);
                var mean  = slice.reduce(function(s, d) { return s + d.value; }, 0) / period;
                var variance = slice.reduce(function(s, d) { return s + Math.pow(d.value - mean, 2); }, 0) / period;
                var std   = Math.sqrt(variance);
                result.upper.push({ time: data[i].time, value: Math.round((mean + sigma * std) * 10) / 10 });
                result.mid.push(  { time: data[i].time, value: Math.round(mean * 10) / 10 });
                result.lower.push({ time: data[i].time, value: Math.round((mean - sigma * std) * 10) / 10 });
            }
            return result;
        }

        // MA計算
        function calcMA(data, period) {
            return data.map(function(d, i) {
                if (i < period - 1) return null;
                var sum = 0;
                for (var j = i - period + 1; j <= i; j++) sum += data[j].value;
                return { time: d.time, value: Math.round(sum / period * 10) / 10 };
            }).filter(function(d) { return d !== null; });
        }

        // EMA計算
        function calcEMA(data, period) {
            var k = 2 / (period + 1);
            var result = [];
            var ema = null;
            for (var i = 0; i < data.length; i++) {
                if (i < period - 1) continue;
                if (ema === null) {
                    var sum = 0;
                    for (var j = 0; j < period; j++) sum += data[j].value;
                    ema = sum / period;
                } else {
                    ema = data[i].value * k + ema * (1 - k);
                }
                result.push({ time: data[i].time, value: Math.round(ema * 100) / 100 });
            }
            return result;
        }

        // MACD計算
        function calcMACD() {
            var ema12 = calcEMA(lineRaw, 12);
            var ema26 = calcEMA(lineRaw, 26);
            var macdMap = {};
            ema12.forEach(function(d) { macdMap[d.time] = { time: d.time, ema12: d.value }; });
            ema26.forEach(function(d) { if (macdMap[d.time]) macdMap[d.time].ema26 = d.value; });

            var macdLine = [];
            Object.keys(macdMap).sort().forEach(function(t) {
                var d = macdMap[t];
                if (d.ema12 !== undefined && d.ema26 !== undefined) {
                    macdLine.push({ time: t, value: Math.round((d.ema12 - d.ema26) * 100) / 100 });
                }
            });

            var signalLine = calcEMA(macdLine, 9);
            var signalMap  = {};
            signalLine.forEach(function(d) { signalMap[d.time] = d.value; });

            var hist = macdLine.filter(function(d) { return signalMap[d.time] !== undefined; })
                .map(function(d) {
                    var h = Math.round((d.value - signalMap[d.time]) * 100) / 100;
                    return { time: d.time, value: h, color: h >= 0 ? '#e74c3c88' : '#3498db88' };
                });

            return { macd: macdLine, signal: signalLine, hist: hist };
        }

        // RSI計算
        function calcRSI(period) {
            var result = [];
            for (var i = period; i < lineRaw.length; i++) {
                var gains = 0, losses = 0;
                for (var j = i - period + 1; j <= i; j++) {
                    var diff = lineRaw[j].value - lineRaw[j-1].value;
                    if (diff > 0) gains += diff;
                    else losses += Math.abs(diff);
                }
                var rs  = losses > 0 ? gains / losses : 999;
                var rsi = Math.round((100 - 100 / (1 + rs)) * 10) / 10;
                result.push({ time: lineRaw[i].time, value: rsi });
            }
            return result;
        }

        // RCI計算
        function calcRCI(data, period) {
            var result = [];
            for (var i = period - 1; i < data.length; i++) {
                var slice = data.slice(i - period + 1, i + 1);
                var priceRank = slice.map(function(d, idx) { return { idx: idx, value: d.value }; });
                priceRank.sort(function(a, b) { return b.value - a.value; });
                var rankOfIdx = {};
                priceRank.forEach(function(pr, rank) { rankOfIdx[pr.idx] = rank + 1; });
                var sumD2 = 0;
                for (var j = 0; j < period; j++) {
                    var dateRank = period - j;
                    var priceR   = rankOfIdx[j];
                    var d = dateRank - priceR;
                    sumD2 += d * d;
                }
                var rci = (1 - 6 * sumD2 / (period * (period * period - 1))) * 100;
                result.push({ time: data[i].time, value: Math.round(rci * 10) / 10 });
            }
            return result;
        }

        // DMI/ADX計算（Wilderスムージング簡易版）
        function calcDMI(candles, period) {
            var plusDM = [], minusDM = [], tr = [];
            for (var i = 1; i < candles.length; i++) {
                var upMove   = candles[i].high - candles[i - 1].high;
                var downMove = candles[i - 1].low - candles[i].low;
                var pdm = (upMove > downMove && upMove > 0) ? upMove : 0;
                var mdm = (downMove > upMove && downMove > 0) ? downMove : 0;
                var trueRange = Math.max(
                    candles[i].high - candles[i].low,
                    Math.abs(candles[i].high - candles[i - 1].close),
                    Math.abs(candles[i].low  - candles[i - 1].close)
                );
                plusDM.push(pdm);
                minusDM.push(mdm);
                tr.push(trueRange);
            }
            function wilderSmooth(values, p) {
                var result = [];
                if (values.length < p) return result;
                var sum = 0;
                for (var k = 0; k < p; k++) sum += values[k];
                result.push(sum);
                for (var k = p; k < values.length; k++) {
                    sum = sum - (sum / p) + values[k];
                    result.push(sum);
                }
                return result;
            }
            var smPlus  = wilderSmooth(plusDM, period);
            var smMinus = wilderSmooth(minusDM, period);
            var smTR    = wilderSmooth(tr, period);
            var plusDI = [], minusDI = [], adx = [];
            for (var k = 0; k < smTR.length; k++) {
                var candleIdx = k + period;
                if (!candles[candleIdx]) continue;
                if (smTR[k] > 0) {
                    var pdi = 100 * smPlus[k]  / smTR[k];
                    var mdi = 100 * smMinus[k] / smTR[k];
                    var dx  = (pdi + mdi) > 0 ? 100 * Math.abs(pdi - mdi) / (pdi + mdi) : 0;
                    plusDI.push({  time: candles[candleIdx].time, value: Math.round(pdi * 10) / 10 });
                    minusDI.push({ time: candles[candleIdx].time, value: Math.round(mdi * 10) / 10 });
                    adx.push({     time: candles[candleIdx].time, value: Math.round(dx  * 10) / 10 });
                }
            }
            return { plusDI: plusDI, minusDI: minusDI, adx: adx };
        }

        // 一目均衡表計算
        function calcIchimoku(candles) {
            function periodHL(idx, period) {
                if (idx < period - 1) return null;
                var hi = -Infinity, lo = Infinity;
                for (var k = idx - period + 1; k <= idx; k++) {
                    if (candles[k].high > hi) hi = candles[k].high;
                    if (candles[k].low  < lo) lo = candles[k].low;
                }
                return { high: hi, low: lo };
            }
            var tenkan = [], kijun = [], senkouARaw = [], senkouBRaw = [], chikou = [];
            for (var i = 0; i < candles.length; i++) {
                var hl9  = periodHL(i, 9);
                var hl26 = periodHL(i, 26);
                var hl52 = periodHL(i, 52);
                var tVal = hl9  ? (hl9.high + hl9.low) / 2   : null;
                var kVal = hl26 ? (hl26.high + hl26.low) / 2 : null;
                if (tVal !== null) tenkan.push({ time: candles[i].time, value: Math.round(tVal * 10) / 10 });
                if (kVal !== null) kijun.push({ time: candles[i].time, value: Math.round(kVal * 10) / 10 });
                if (tVal !== null && kVal !== null) {
                    senkouARaw.push({ index: i, value: Math.round((tVal + kVal) / 2 * 10) / 10 });
                }
                if (hl52) {
                    senkouBRaw.push({ index: i, value: Math.round((hl52.high + hl52.low) / 2 * 10) / 10 });
                }
            }
            function addBusinessDays(dateStr, days) {
                var d = new Date(dateStr + 'T00:00:00');
                var added = 0;
                while (added < days) {
                    d.setDate(d.getDate() + 1);
                    var day = d.getDay();
                    if (day !== 0 && day !== 6) added++;
                }
                return d.toISOString().slice(0, 10);
            }
            var lastDate = candles[candles.length - 1].time;
            var futureDates = [];
            for (var f = 1; f <= 26; f++) futureDates.push(addBusinessDays(lastDate, f));
            function shiftForward(rawArr) {
                var out = [];
                rawArr.forEach(function(item) {
                    var targetIdx = item.index + 26;
                    var time = targetIdx < candles.length ? candles[targetIdx].time : futureDates[targetIdx - candles.length];
                    if (time) out.push({ time: time, value: item.value });
                });
                return out;
            }
            var senkouA = shiftForward(senkouARaw);
            var senkouB = shiftForward(senkouBRaw);
            for (var i2 = 26; i2 < candles.length; i2++) {
                chikou.push({ time: candles[i2 - 26].time, value: candles[i2].close });
            }
            return { tenkan: tenkan, kijun: kijun, senkouA: senkouA, senkouB: senkouB, chikou: chikou };
        }

        // 一目均衡表の雲（先行スパンA/B間）塗りつぶし用プリミティブ
        function IchimokuCloudPaneView(source) {
            this._source = source;
            this._items = [];
        }
        IchimokuCloudPaneView.prototype.update = function() {
            var chart  = this._source._chart;
            var series = this._source._series;
            if (!chart || !series) { this._items = []; return; }
            var timeScale = chart.timeScale();
            var bMap = {};
            this._source._spanB.forEach(function(d) { bMap[d.time] = d.value; });
            var items = [];
            this._source._spanA.forEach(function(d) {
                var bVal = bMap[d.time];
                if (bVal === undefined) return;
                var x  = timeScale.timeToCoordinate(d.time);
                var yA = series.priceToCoordinate(d.value);
                var yB = series.priceToCoordinate(bVal);
                if (x === null || yA === null || yB === null) return;
                items.push({ x: x, yA: yA, yB: yB, bullish: d.value >= bVal });
            });
            this._items = items;
        };
        IchimokuCloudPaneView.prototype.renderer = function() {
            var items = this._items;
            return {
                draw: function(target) {
                    target.useBitmapCoordinateSpace(function(scope) {
                        var ctx = scope.context;
                        var hr  = scope.horizontalPixelRatio;
                        var vr  = scope.verticalPixelRatio;
                        for (var i = 0; i < items.length - 1; i++) {
                            var p0 = items[i], p1 = items[i + 1];
                            ctx.beginPath();
                            ctx.moveTo(p0.x * hr, p0.yA * vr);
                            ctx.lineTo(p1.x * hr, p1.yA * vr);
                            ctx.lineTo(p1.x * hr, p1.yB * vr);
                            ctx.lineTo(p0.x * hr, p0.yB * vr);
                            ctx.closePath();
                            ctx.fillStyle = p0.bullish ? 'rgba(76,175,80,0.15)' : 'rgba(231,76,60,0.15)';
                            ctx.fill();
                        }
                    });
                }
            };
        };
        function IchimokuCloudPrimitive(spanAData, spanBData) {
            this._spanA = spanAData;
            this._spanB = spanBData;
            this._chart = null;
            this._series = null;
            this._paneView = new IchimokuCloudPaneView(this);
        }
        IchimokuCloudPrimitive.prototype.attached = function(param) {
            this._chart = param.chart;
            this._series = param.series;
        };
        IchimokuCloudPrimitive.prototype.updateAllViews = function() {
            this._paneView.update();
        };
        IchimokuCloudPrimitive.prototype.paneViews = function() {
            return [this._paneView];
        };

        var ma5Data   = calcMA(lineRaw, 5);
        var ma25Data  = calcMA(lineRaw, 25);
        var ma75Data  = calcMA(lineRaw, 75);
        var macdData  = calcMACD();
        var rsiData   = calcRSI(14);
        var rciData   = calcRCI(lineRaw, 9);
        var dmiData   = calcDMI(candleRaw, 14);
        var ichimokuData = calcIchimoku(candleRaw);

        var mainChart = null;
        var subChart  = null;
        var currentType = 'candlestick';
        var currentSub  = 'volume';

        var chartOpts = {
            width:  document.getElementById('lwChart').clientWidth,
            height: 700,
            layout: { background: { color: '#fff' }, textColor: '#333' },
            grid: { vertLines: { color: '#f0f0f0' }, horzLines: { color: '#f0f0f0' } },
            crosshair: { mode: 1 },
            rightPriceScale: { borderColor: '#ddd' },
            timeScale: { borderColor: '#ddd', timeVisible: true },
        };

        var subOpts = {
            width:  document.getElementById('lwSubChart').clientWidth,
            height: 150,
            layout: { background: { color: '#fff' }, textColor: '#333' },
            grid: { vertLines: { color: '#f0f0f0' }, horzLines: { color: '#f0f0f0' } },
            rightPriceScale: { borderColor: '#ddd' },
            timeScale: { borderColor: '#ddd', timeVisible: false, visible: false, fixLeftEdge: true, fixRightEdge: true },
        };

        function setChartType(type) {
            currentType = type;
            document.getElementById('btn_candle').style.background = type === 'candlestick' ? '#0073aa' : '';
            document.getElementById('btn_candle').style.color      = type === 'candlestick' ? '#fff'    : '';
            document.getElementById('btn_line').style.background   = type === 'line'        ? '#0073aa' : '';
            document.getElementById('btn_line').style.color        = type === 'line'        ? '#fff'    : '';
            renderMain();
            syncTimeScale();
        }

        function setSubChart(type) {
            currentSub = type;
            ['vol','macd','rsi','rci','dmi'].forEach(function(b) {
                document.getElementById('btn_' + b).style.background = '';
                document.getElementById('btn_' + b).style.color = '';
            });
            var btnMap = { volume: 'vol', macd: 'macd', rsi: 'rsi', rci: 'rci', dmi: 'dmi' };
            document.getElementById('btn_' + btnMap[type]).style.background = '#0073aa';
            document.getElementById('btn_' + btnMap[type]).style.color = '#fff';
            renderSub();
            // メインの表示範囲をサブに適用してから同期
            try {
                var range = mainChart.timeScale().getVisibleLogicalRange();
                if (range) subChart.timeScale().setVisibleLogicalRange(range);
            } catch(e) {}
            syncTimeScale();
        }

        var showMA = true;
        var showBB = true;

        function toggleMA() {
            showMA = !showMA;
            document.getElementById('btn_ma').style.background = showMA ? '#0073aa' : '#ccc';
            document.getElementById('btn_ma').style.color      = showMA ? '#fff'    : '#333';
            renderMain();
        }

        function toggleBB() {
            showBB = !showBB;
            document.getElementById('btn_bb').style.background = showBB ? '#0073aa' : '#ccc';
            document.getElementById('btn_bb').style.color      = showBB ? '#fff'    : '#333';
            renderMain();
        }

        var showIchimoku = true;
        function toggleIchimoku() {
            showIchimoku = !showIchimoku;
            document.getElementById('btn_ichimoku').style.background = showIchimoku ? '#0073aa' : '#ccc';
            document.getElementById('btn_ichimoku').style.color      = showIchimoku ? '#fff'    : '#333';
            renderMain();
        }

        function renderMain() {
            if (mainChart) { mainChart.remove(); mainChart = null; }
            document.getElementById('lwChart').innerHTML = '';
            mainChart = LightweightCharts.createChart(document.getElementById('lwChart'), chartOpts);

            if (currentType === 'candlestick') {
                var cs = mainChart.addCandlestickSeries({
                    upColor: '#e74c3c', downColor: '#3498db',
                    borderUpColor: '#e74c3c', borderDownColor: '#3498db',
                    wickUpColor: '#e74c3c', wickDownColor: '#3498db',
                });
                cs.setData(candleRaw);
            } else {
                var ls = mainChart.addLineSeries({ color: '#333', lineWidth: 2 });
                ls.setData(lineRaw);
            }

            // MA線
            if (showMA) {
                if (ma5Data.length > 0) {
                    var ma5s = mainChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: 'MA5' });
                    ma5s.setData(ma5Data);
                }
                if (ma25Data.length > 0) {
                    var ma25s = mainChart.addLineSeries({ color: '#3498db', lineWidth: 1, lineStyle: 1, priceLineVisible: false, lastValueVisible: false, title: 'MA25' });
                    ma25s.setData(ma25Data);
                }
                if (ma75Data.length > 0) {
                    var ma75s = mainChart.addLineSeries({ color: '#27ae60', lineWidth: 1, lineStyle: 2, priceLineVisible: false, lastValueVisible: false, title: 'MA75' });
                    ma75s.setData(ma75Data);
                }
            }

            // ボリンジャーバンド（±1σ・±2σ の4本）
            if (showBB) {
                var bbData2 = calcBB(lineRaw, 20, 2);
                var bbData1 = calcBB(lineRaw, 20, 1);
                if (bbData2.upper.length > 0) {
                    var bbUpper2 = mainChart.addLineSeries({ color: '#9b59b688', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: 'BB+2σ' });
                    bbUpper2.setData(bbData2.upper);
                    var bbUpper1 = mainChart.addLineSeries({ color: '#9b59b666', lineWidth: 1, lineStyle: 2, priceLineVisible: false, lastValueVisible: false, title: 'BB+1σ' });
                    bbUpper1.setData(bbData1.upper);
                    var bbMid = mainChart.addLineSeries({ color: '#9b59b6', lineWidth: 1, lineStyle: 1, priceLineVisible: false, lastValueVisible: false, title: 'BB中心' });
                    bbMid.setData(bbData2.mid);
                    var bbLower1 = mainChart.addLineSeries({ color: '#9b59b666', lineWidth: 1, lineStyle: 2, priceLineVisible: false, lastValueVisible: false, title: 'BB-1σ' });
                    bbLower1.setData(bbData1.lower);
                    var bbLower2 = mainChart.addLineSeries({ color: '#9b59b688', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: 'BB-2σ' });
                    bbLower2.setData(bbData2.lower);
                }
            }

            // 一目均衡表
            if (showIchimoku) {
                if (ichimokuData.tenkan.length > 0) {
                    var tenkanLine = mainChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '転換線' });
                    tenkanLine.setData(ichimokuData.tenkan);
                }
                if (ichimokuData.kijun.length > 0) {
                    var kijunLine = mainChart.addLineSeries({ color: '#3498db', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '基準線' });
                    kijunLine.setData(ichimokuData.kijun);
                }
                if (ichimokuData.chikou.length > 0) {
                    var chikouLine = mainChart.addLineSeries({ color: '#27ae60', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '遅行スパン' });
                    chikouLine.setData(ichimokuData.chikou);
                }
                if (ichimokuData.senkouA.length > 0 && ichimokuData.senkouB.length > 0) {
                    var senkouALine = mainChart.addLineSeries({ color: 'rgba(76,175,80,0.5)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '先行スパンA' });
                    senkouALine.setData(ichimokuData.senkouA);
                    var senkouBLine = mainChart.addLineSeries({ color: 'rgba(231,76,60,0.5)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '先行スパンB' });
                    senkouBLine.setData(ichimokuData.senkouB);
                    if (typeof senkouBLine.attachPrimitive === 'function') {
                        senkouBLine.attachPrimitive(new IchimokuCloudPrimitive(ichimokuData.senkouA, ichimokuData.senkouB));
                    }
                }
            }

            mainChart.timeScale().fitContent();
            // メインチャートが作り直されたので同期リスナーを再登録
            syncMainToSub();
        }

        function renderSub() {
            if (subChart) { subChart.remove(); subChart = null; }
            document.getElementById('lwSubChart').innerHTML = '';
            // 出来高表示時のみ fixLeftEdge/fixRightEdge を外し、メインと同じだけズームできるようにする
            // （MACD/RSI/RCI/DMIは先頭データが欠けているため、既存のsubOptsのまま維持する）
            var subOptsForRender = subOpts;
            if (currentSub === 'volume') {
                subOptsForRender = Object.assign({}, subOpts, {
                    timeScale: Object.assign({}, subOpts.timeScale, {
                        fixLeftEdge: false,
                        fixRightEdge: false,
                        minBarSpacing: 0.1,
                    }),
                });
            }
            subChart = LightweightCharts.createChart(document.getElementById('lwSubChart'), subOptsForRender);

            if (currentSub === 'volume') {
                var vs = subChart.addHistogramSeries({ priceFormat: { type: 'volume' }, priceScaleId: '' });
                vs.setData(volumeRaw);
                subChart.priceScale('').applyOptions({ scaleMargins: { top: 0.1, bottom: 0 } });

            } else if (currentSub === 'macd') {
                var hist = subChart.addHistogramSeries({ priceScaleId: 'macd', lastValueVisible: false, priceLineVisible: false });
                hist.setData(macdData.hist);
                var macdLine = subChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceScaleId: 'macd', lastValueVisible: false, priceLineVisible: false });
                macdLine.setData(macdData.macd);
                var sigLine = subChart.addLineSeries({ color: '#3498db', lineWidth: 1, priceScaleId: 'macd', lastValueVisible: false, priceLineVisible: false });
                sigLine.setData(macdData.signal);

            } else if (currentSub === 'rsi') {
                var rsiLine = subChart.addLineSeries({ color: '#9b59b6', lineWidth: 1, priceScaleId: 'rsi', lastValueVisible: true, priceLineVisible: false });
                rsiLine.setData(rsiData);
                subChart.priceScale('rsi').applyOptions({ scaleMargins: { top: 0.1, bottom: 0.1 }, autoScale: true, minimum: 0, maximum: 100 });

            } else if (currentSub === 'rci') {
                var rciLine = subChart.addLineSeries({ color: '#16a085', lineWidth: 1, priceScaleId: 'rci', lastValueVisible: true, priceLineVisible: false });
                rciLine.setData(rciData);
                subChart.priceScale('rci').applyOptions({ scaleMargins: { top: 0.1, bottom: 0.1 }, autoScale: true, minimum: -100, maximum: 100 });

            } else if (currentSub === 'dmi') {
                var plusDILine = subChart.addLineSeries({ color: '#27ae60', lineWidth: 1, priceScaleId: 'dmi', lastValueVisible: true, priceLineVisible: false, title: '+DI' });
                plusDILine.setData(dmiData.plusDI);
                var minusDILine = subChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceScaleId: 'dmi', lastValueVisible: true, priceLineVisible: false, title: '-DI' });
                minusDILine.setData(dmiData.minusDI);
                var adxLine = subChart.addLineSeries({ color: '#888', lineWidth: 1, lineStyle: 2, priceScaleId: 'dmi', lastValueVisible: true, priceLineVisible: false, title: 'ADX' });
                adxLine.setData(dmiData.adx);
                subChart.priceScale('dmi').applyOptions({ scaleMargins: { top: 0.1, bottom: 0.1 }, autoScale: true });
            }

            // メインチャートの表示範囲に合わせる
            try {
                var range = mainChart.timeScale().getVisibleLogicalRange();
                if (range) {
                    subChart.timeScale().setVisibleLogicalRange(range);
                } else {
                    subChart.timeScale().fitContent();
                }
            } catch(e) {
                subChart.timeScale().fitContent();
            }

            // サブチャートが作り直されたので同期リスナーを再登録
            syncSubToMain();
        }

        // 時間軸同期（メイン→サブ方向は一度だけ張れば良い。
        // サブ側は renderSub() でインスタンスが作り直されるたびに再登録する）
        // isSyncingRange: プログラムによる setVisibleLogicalRange 実行中は
        // 相手側からのエコーバック（無限ループ）を無視するためのフラグ
        var isSyncingRange = false;

        function syncMainToSub() {
            if (!mainChart) return;
            mainChart.timeScale().subscribeVisibleLogicalRangeChange(function(range) {
                if (isSyncingRange) return;
                if (range && subChart) {
                    isSyncingRange = true;
                    subChart.timeScale().setVisibleLogicalRange(range);
                    requestAnimationFrame(function() { isSyncingRange = false; });
                }
            });
        }

        function syncSubToMain() {
            if (!subChart || !mainChart) return;
            subChart.timeScale().subscribeVisibleLogicalRangeChange(function(range) {
                if (isSyncingRange) return;
                if (range && mainChart) {
                    isSyncingRange = true;
                    mainChart.timeScale().setVisibleLogicalRange(range);
                    requestAnimationFrame(function() { isSyncingRange = false; });
                }
            });
        }

        // 初期表示（syncMainToSub/syncSubToMainはrenderMain/renderSubの中で自動登録される）
        renderMain();
        renderSub();

        // ドラッグリサイズ対応
        (function() {
            var handle    = document.getElementById('lwChartHandle');
            var mainDiv   = document.getElementById('lwChart');
            var subDiv    = document.getElementById('lwSubChart');
            var dragging  = false;
            var startY    = 0;
            var startMainH = 0;
            var startSubH  = 0;

            handle.addEventListener('mousedown', function(e) {
                dragging   = true;
                startY     = e.clientY;
                startMainH = mainDiv.clientHeight;
                startSubH  = subDiv.clientHeight;
                document.body.style.cursor = 'ns-resize';
                document.body.style.userSelect = 'none';
                e.preventDefault();
            });

            document.addEventListener('mousemove', function(e) {
                if (!dragging) return;
                var diff    = e.clientY - startY;
                var newMainH = Math.max(100, startMainH + diff);
                var newSubH  = Math.max(60,  startSubH  - diff);
                mainDiv.style.height = newMainH + 'px';
                subDiv.style.height  = newSubH  + 'px';
                if (mainChart) mainChart.applyOptions({ height: newMainH });
                if (subChart)  subChart.applyOptions({ height: newSubH });
            });

            document.addEventListener('mouseup', function() {
                if (!dragging) return;
                dragging = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            });

            // タッチ対応
            handle.addEventListener('touchstart', function(e) {
                dragging   = true;
                startY     = e.touches[0].clientY;
                startMainH = mainDiv.clientHeight;
                startSubH  = subDiv.clientHeight;
                e.preventDefault();
            }, { passive: false });

            document.addEventListener('touchmove', function(e) {
                if (!dragging) return;
                var diff    = e.touches[0].clientY - startY;
                var newMainH = Math.max(100, startMainH + diff);
                var newSubH  = Math.max(60,  startSubH  - diff);
                mainDiv.style.height = newMainH + 'px';
                subDiv.style.height  = newSubH  + 'px';
                if (mainChart) mainChart.applyOptions({ height: newMainH });
                if (subChart)  subChart.applyOptions({ height: newSubH });
            }, { passive: false });

            document.addEventListener('touchend', function() {
                dragging = false;
            });
        })();

        // ウィンドウリサイズ対応
        window.addEventListener('resize', function() {
            var w = document.getElementById('lwChart').clientWidth;
            if (mainChart) mainChart.applyOptions({ width: w });
            if (subChart)  subChart.applyOptions({ width: document.getElementById('lwSubChart').clientWidth });
        });
        </script>
        <?php
    } else {
        echo '<p style="color:#888;">チャートデータを取得できませんでした。</p>';
    }

    // ★削除：チャート下の簡易テクニカル指標ボックスは「テクニカル分析」タブに統合したため撤去

    // メモ表示（表示のみ・入力は編集タブへ）
    if (!empty($stock->memo)) {
        echo '<h3>&#x1F4DD; 銘柄メモ</h3>';
        echo '<div style="background:#f0f8ff;border:1px solid #a8d4f5;border-radius:8px;padding:16px;margin-bottom:20px;">';
        echo '<pre style="white-space:pre-wrap;word-wrap:break-word;font-family:inherit;font-size:13px;line-height:1.8;margin:0;">' . esc_html($stock->memo) . '</pre>';
        echo '</div>';
    }

    // 企業情報再取得ボタン
    $info_url = wp_nonce_url(admin_url('admin-post.php?action=update_company_info&id=' . $id . '&from=wp-stocks-company'), 'wp_stocks_action_' . $id);
    echo '<p><a href="' . esc_url($info_url) . '" class="button">&#x1F504; Yahoo!ファイナンスから企業情報を再取得</a></p>';


    // 外部チャートリンク
    echo wp_stocks_tradingview_widget($stock->code, 450, $is_usd);

    echo '</div>'; // tab-chart
    } // end chart tab

    // ===== 財務タブ =====
    if ($active_tab === 'finance') {
    echo '<div id="tab-finance">';

    if (!$is_usd) {
        $shikiho_url = 'https://shikiho.toyokeizai.net/stocks/' . rawurlencode($stock->code) . '/forecast';
        echo '<p style="margin-bottom:20px;"><a href="' . esc_url($shikiho_url) . '" target="_blank" rel="noopener" class="button button-primary">&#x1F4D6; 四季報オンラインで財務情報を見る</a></p>';
    }

    echo '<h2 style="border-left:4px solid #0073aa;padding-left:10px;">年間</h2>';

    if (!$is_usd) {
    $financials = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_financials WHERE stock_id = %d ORDER BY fiscal_year ASC", $id
    ));

    if (empty($financials)) {
        echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;margin-bottom:20px;">';
        echo '<p style="font-size:16px;font-weight:bold;margin-bottom:10px;">&#x1F4B0; 財務データがまだ取得されていません</p>';
        echo '<p style="font-size:13px;">設定ページから「財務データ取得」を実行するか、下のボタンで取得してください。</p>';
        $fin_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_financials&stock_id=' . $id . '&from=finance'), 'wp_stocks_fetch_fin_' . $id);
        echo '<p style="margin-top:15px;"><a href="' . esc_url($fin_url) . '" class="button button-primary">&#x1F4CA; EDINETから財務データを取得</a></p>';
        echo '</div>';
    } else {
        // グラフ用データ準備
        $fin_labels   = [];
        $fin_revenue  = [];
        $fin_op_profit = [];
        $fin_net_income = [];
        $fin_eq_ratio = [];
        $fin_op_margin = [];
        foreach ($financials as $f) {
            $fin_labels[]    = esc_js($f->period_label ?: $f->fiscal_year);
            $fin_revenue[]   = $f->revenue     ? round($f->revenue / 1000000)     : 0;
            $fin_op_profit[] = $f->operating_profit ? round($f->operating_profit / 1000000) : 0;
            $fin_net_income[] = $f->net_income  ? round($f->net_income / 1000000)  : 0;
            $fin_eq_ratio[]  = $f->equity_ratio ?? 0;
            // 営業利益率（売上高が無い期間はグラフ上プロットしない）
            $calc_op_margin = ($f->revenue && $f->revenue > 0 && $f->operating_profit !== null)
                ? round($f->operating_profit / $f->revenue * 100, 1)
                : null;
            // ±1000%を超える場合は明らかなデータ抽出異常とみなし、グラフには出さない
            // （実際の業績悪化による大きなマイナスまでは消さない。表示範囲の固定はJS側で対応）
            $fin_op_margin[] = ($calc_op_margin !== null && abs($calc_op_margin) <= 1000) ? $calc_op_margin : null;
        }
        $labels_json    = json_encode($fin_labels);
        $revenue_json   = json_encode($fin_revenue);
        $op_profit_json = json_encode($fin_op_profit);
        $net_income_json = json_encode($fin_net_income);
        $eq_ratio_json  = json_encode($fin_eq_ratio);
        $op_margin_json = json_encode($fin_op_margin);

        // 売上高・営業利益・純利益の3系列を同一スケールで描画するための共通min/maxを算出
        $fin_amount_values = array_merge($fin_revenue, $fin_op_profit, $fin_net_income);
        $fin_amount_max = !empty($fin_amount_values) ? max($fin_amount_values) : 0;
        $fin_amount_min = !empty($fin_amount_values) ? min(0, min($fin_amount_values)) : 0;
        // 上端に少し余白を持たせる
        $fin_amount_max = $fin_amount_max > 0 ? ceil(($fin_amount_max * 1.1) / 1000) * 1000 : 1000;
        $fin_amount_max_json = json_encode($fin_amount_max);
        $fin_amount_min_json = json_encode($fin_amount_min);

        // 財務データ取得ボタン
        $fin_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_financials&stock_id=' . $id . '&from=finance'), 'wp_stocks_fetch_fin_' . $id);
        echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($fin_url) . '" class="button">&#x1F504; EDINETから財務データを再取得</a></p>';

        ?>
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

        <!-- 業績グラフ（売上高・営業利益・純利益＋営業利益率） -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
            <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 業績グラフ（百万円／営業利益率%）</h3>
            <div id="finChart1"></div>
        </div>

        <!-- 自己資本比率 折れ線グラフ -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
            <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4CA; 自己資本比率（%）</h3>
            <div id="finChart2"></div>
        </div>

        <!-- 財務データ一覧テーブル -->
        <div style="overflow-x:auto;margin-bottom:20px;">
            <table class="widefat" style="font-size:13px;">
                <thead><tr>
                    <th>期</th>
                    <th>売上高（百万円）</th>
                    <th>営業利益（百万円）</th>
                    <th>純利益（百万円）</th>
                    <th>自己資本比率</th>
                </tr></thead>
                <tbody>
                <?php foreach ($financials as $f): ?>
                <tr>
                    <td><?php echo esc_html($f->period_label ?: $f->fiscal_year); ?></td>
                    <td><?php echo $f->revenue ? number_format($f->revenue / 1000000) : '-'; ?></td>
                    <td><?php echo $f->operating_profit ? number_format($f->operating_profit / 1000000) : '-'; ?></td>
                    <td><?php echo $f->net_income ? number_format($f->net_income / 1000000) : '-'; ?></td>
                    <td><?php echo $f->equity_ratio ? number_format($f->equity_ratio, 1) . '%' : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        // 業績グラフ（棒：売上高・営業利益・純利益／折れ線：営業利益率）
        var chart1 = new ApexCharts(document.getElementById('finChart1'), {
            chart: { height: 360, toolbar: { show: false } },
            series: [
                { name: '売上高',     type: 'column', data: <?php echo $revenue_json; ?> },
                { name: '営業利益',   type: 'column', data: <?php echo $op_profit_json; ?> },
                { name: '純利益',     type: 'column', data: <?php echo $net_income_json; ?> },
                { name: '営業利益率', type: 'line',   data: <?php echo $op_margin_json; ?> },
            ],
            xaxis: { categories: <?php echo $labels_json; ?> },
            colors: ['#2ecc71', '#2c3e50', '#3498db', '#f39c12'],
            stroke: { width: [0, 0, 0, 3], curve: 'smooth' },
            markers: { size: [0, 0, 0, 4], colors: ['#f39c12'] },
            dataLabels: { enabled: false },
            legend: { position: 'top' },
            plotOptions: { bar: { columnWidth: '65%' } },
            yaxis: [
                {
                    seriesName: '売上高',
                    min: <?php echo $fin_amount_min_json; ?>,
                    max: <?php echo $fin_amount_max_json; ?>,
                    title: { text: '百万円', style: { fontSize: '11px' } },
                    labels: { formatter: function(v) { return v.toLocaleString(); } },
                },
                {
                    seriesName: '営業利益',
                    min: <?php echo $fin_amount_min_json; ?>,
                    max: <?php echo $fin_amount_max_json; ?>,
                    show: false,
                },
                {
                    seriesName: '純利益',
                    min: <?php echo $fin_amount_min_json; ?>,
                    max: <?php echo $fin_amount_max_json; ?>,
                    show: false,
                },
                {
                    seriesName: '営業利益率',
                    opposite: true,
                    min: -100,
                    max: 100,
                    tickAmount: 4,
                    title: { text: '営業利益率(%)', style: { fontSize: '11px' } },
                    labels: { formatter: function(v) { return v === null ? '' : v + '%'; } },
                },
            ],
            tooltip: {
                y: {
                    formatter: function(v, opts) {
                        if (v === null || v === undefined) return '-';
                        var seriesIndex = opts.seriesIndex;
                        return seriesIndex === 3 ? v + '%' : Number(v).toLocaleString() + '百万円';
                    }
                }
            },
        });
        chart1.render();

        // 折れ線グラフ（自己資本比率）
        var chart2 = new ApexCharts(document.getElementById('finChart2'), {
            chart: { type: 'line', height: 200, toolbar: { show: false } },
            series: [{ name: '自己資本比率', data: <?php echo $eq_ratio_json; ?> }],
            xaxis: { categories: <?php echo $labels_json; ?> },
            colors: ['#9b59b6'],
            dataLabels: { enabled: true, formatter: function(v) { return v + '%'; } },
            stroke: { width: 3, curve: 'smooth' },
            yaxis: { labels: { formatter: function(v) { return v + '%'; } }, min: 0, max: 100 },
            markers: { size: 5 },
        });
        chart2.render();
        </script>
        <?php
    }

    } else {
        // ===== 米国株: SEC EDGAR由来の年間業績 =====
        $edgar_qf = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'edgar' AND fiscal_year IS NOT NULL AND fiscal_quarter IS NOT NULL ORDER BY fiscal_year ASC, fiscal_quarter ASC", $id
        ));
        $edgar_fetch_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_quarterly_financials_edgar&stock_id=' . $id), 'wp_stocks_fetch_qfin_edgar_' . $id);

        if (empty($edgar_qf)) {
            echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;margin-bottom:20px;">';
            echo '<p style="font-size:16px;font-weight:bold;margin-bottom:10px;">&#x1F4B0; 財務データがまだ取得されていません</p>';
            echo '<p style="font-size:13px;">下のボタンでSEC EDGARから取得してください。</p>';
            echo '<p style="margin-top:15px;"><a href="' . esc_url($edgar_fetch_url) . '" class="button button-primary">&#x1F4CA; SEC EDGARから財務データを取得</a></p>';
            echo '</div>';
        } else {
            $by_fy = array();
            foreach ($edgar_qf as $row) {
                $by_fy[$row->fiscal_year][$row->fiscal_quarter] = $row;
            }
            ksort($by_fy);

            $annual_labels = array(); $annual_revenue = array(); $annual_net_income = array(); $annual_margin = array();
            foreach ($by_fy as $fy => $quarters) {
                if (count($quarters) < 4) continue; // 4四半期揃っている年度のみ年間集計に採用
                $rev_sum = 0; $ni_sum = 0; $rev_ok = true; $ni_ok = true;
                foreach ($quarters as $q) {
                    if ($q->revenue === null) $rev_ok = false; else $rev_sum += $q->revenue;
                    if ($q->net_income === null) $ni_ok = false; else $ni_sum += $q->net_income;
                }
                $annual_labels[]     = 'FY' . $fy;
                $annual_revenue[]    = $rev_ok ? round($rev_sum / 1000000) : null;
                $annual_net_income[] = $ni_ok ? round($ni_sum / 1000000) : null;
                $annual_margin[]     = ($rev_ok && $ni_ok && $rev_sum != 0) ? round($ni_sum / $rev_sum * 100, 1) : null;
            }

            echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($edgar_fetch_url) . '" class="button">&#x1F504; SEC EDGARから財務データを再取得</a></p>';
            ?>
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
                <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 業績グラフ（百万ドル／純利益率%）</h3>
                <div id="finChartUsAnnual"></div>
            </div>
            <div style="overflow-x:auto;margin-bottom:20px;">
                <table class="widefat" style="font-size:13px;">
                    <thead><tr><th>会計年度</th><th>売上高（百万ドル）</th><th>純利益（百万ドル）</th><th>純利益率</th></tr></thead>
                    <tbody>
                    <?php foreach ($annual_labels as $i => $label): ?>
                    <tr>
                        <td><?php echo esc_html($label); ?></td>
                        <td><?php echo $annual_revenue[$i] !== null ? number_format($annual_revenue[$i]) : '-'; ?></td>
                        <td><?php echo $annual_net_income[$i] !== null ? number_format($annual_net_income[$i]) : '-'; ?></td>
                        <td><?php echo $annual_margin[$i] !== null ? number_format($annual_margin[$i], 1) . '%' : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
            new ApexCharts(document.getElementById('finChartUsAnnual'), {
                chart: { height: 360, toolbar: { show: false } },
                series: [
                    { name: '売上高',   type: 'column', data: <?php echo json_encode($annual_revenue); ?> },
                    { name: '純利益',   type: 'column', data: <?php echo json_encode($annual_net_income); ?> },
                    { name: '純利益率', type: 'line',   data: <?php echo json_encode($annual_margin); ?> },
                ],
                xaxis: { categories: <?php echo json_encode($annual_labels); ?> },
                colors: ['#2ecc71', '#3498db', '#f39c12'],
                stroke: { width: [0, 0, 3], curve: 'smooth' },
                markers: { size: [0, 0, 4], colors: ['#f39c12'] },
                dataLabels: { enabled: false },
                legend: { position: 'top' },
                plotOptions: { bar: { columnWidth: '55%' } },
                yaxis: [
                    { seriesName: '売上高', title: { text: '百万ドル', style: { fontSize: '11px' } }, labels: { formatter: function(v) { return v === null ? '' : v.toLocaleString(); } } },
                    { seriesName: '純利益', show: false },
                    { seriesName: '純利益率', opposite: true, min: -50, max: 50, title: { text: '純利益率(%)', style: { fontSize: '11px' } }, labels: { formatter: function(v) { return v === null ? '' : v + '%'; } } },
                ],
                tooltip: { y: { formatter: function(v, opts) { if (v === null || v === undefined) return '-'; return opts.seriesIndex === 2 ? v + '%' : Number(v).toLocaleString() + '百万ドル'; } } },
            }).render();
            </script>
            <?php
        }
    }
    echo '<h2 style="border-left:4px solid #0073aa;padding-left:10px;margin-top:30px;">四半期</h2>';

    if (!$is_usd) {
        $qf = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'yahoo' ORDER BY period_end DESC", $id
        ));
        $qf_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_quarterly_financials&stock_id=' . $id), 'wp_stocks_fetch_qfin_' . $id);
        echo '<p style="color:#e67e22;font-size:12px;background:#fff8e1;border:1px solid #ffe08a;border-radius:4px;padding:8px 12px;margin-bottom:15px;">&#x26A0;&#xFE0F; Yahoo Finance由来のデータです。日本株は欠損や精度のばらつきがある場合があります。参考情報としてご利用ください。</p>';
        echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($qf_url) . '" class="button">&#x1F504; Yahoo Financeから四半期データを取得</a></p>';
        if (empty($qf)) {
            echo '<p style="color:#888;">四半期データがまだ取得されていません。上のボタンから取得してください。</p>';
        } else {
            echo '<table class="widefat" style="font-size:13px;"><thead><tr><th>期末日</th><th>売上高（百万円）</th><th>純利益（百万円）</th></tr></thead><tbody>';
            foreach ($qf as $q) {
                echo '<tr><td>' . esc_html($q->period_end) . '</td><td>' . ($q->revenue ? number_format($q->revenue / 1000000) : '-') . '</td><td>' . ($q->net_income ? number_format($q->net_income / 1000000) : '-') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        // ===== 米国株: SEC EDGAR由来の四半期（累計比較＋進捗率ゲージ） =====
        $edgar_qf = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'edgar' AND fiscal_year IS NOT NULL AND fiscal_quarter IS NOT NULL ORDER BY fiscal_year ASC, fiscal_quarter ASC", $id
        ));
        $edgar_fetch_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_quarterly_financials_edgar&stock_id=' . $id), 'wp_stocks_fetch_qfin_edgar_' . $id);
        echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($edgar_fetch_url) . '" class="button">&#x1F504; SEC EDGARから四半期データを取得</a></p>';

        if (empty($edgar_qf)) {
            echo '<p style="color:#888;">四半期データがまだ取得されていません。上のボタンから取得してください。</p>';
        } else {
            $by_fy = array();
            foreach ($edgar_qf as $row) {
                $by_fy[$row->fiscal_year][$row->fiscal_quarter] = $row;
            }
            krsort($by_fy);
            $fys     = array_keys($by_fy);
            $cur_fy  = $fys[0] ?? null;
            $prev_fy = $fys[1] ?? null;

            $cur_quarters  = $cur_fy  !== null ? $by_fy[$cur_fy]  : array();
            $prev_quarters = $prev_fy !== null ? $by_fy[$prev_fy] : array();

            $cur_revenue_cum  = wp_stocks_sec_cumulative_quarters($cur_quarters, 'revenue');
            $cur_ni_cum       = wp_stocks_sec_cumulative_quarters($cur_quarters, 'net_income');
            $prev_revenue_cum = wp_stocks_sec_cumulative_quarters($prev_quarters, 'revenue');
            $prev_ni_cum      = wp_stocks_sec_cumulative_quarters($prev_quarters, 'net_income');

            $categories = array('1Q', '2Q累計', '3Q累計', '通期');

            // 進捗率ゲージ：当期の最新累計 ÷ 前期通期実績
            $prev_revenue_full = $prev_revenue_cum[3];
            $prev_ni_full      = $prev_ni_cum[3];
            $cur_revenue_latest = null;
            foreach (array_reverse($cur_revenue_cum) as $v) { if ($v !== null) { $cur_revenue_latest = $v; break; } }
            $cur_ni_latest = null;
            foreach (array_reverse($cur_ni_cum) as $v) { if ($v !== null) { $cur_ni_latest = $v; break; } }
            $revenue_progress = (!empty($prev_revenue_full) && $cur_revenue_latest !== null) ? round($cur_revenue_latest / $prev_revenue_full * 100, 1) : null;
            $ni_progress      = (!empty($prev_ni_full) && $cur_ni_latest !== null) ? round($cur_ni_latest / $prev_ni_full * 100, 1) : null;
            ?>
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;flex:1;min-width:200px;text-align:center;">
                    <h3 style="margin:0 0 5px 0;font-size:14px;">売上高 進捗率</h3>
                    <div id="gaugeUsRevenue"></div>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;flex:1;min-width:200px;text-align:center;">
                    <h3 style="margin:0 0 5px 0;font-size:14px;">純利益 進捗率</h3>
                    <div id="gaugeUsNetIncome"></div>
                </div>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
                <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 売上高（前期比・百万ドル）</h3>
                <div id="finChartUsQuarterlyRevenue"></div>
            </div>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
                <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 純利益（前期比・百万ドル）</h3>
                <div id="finChartUsQuarterlyNetIncome"></div>
            </div>

            <script>
            (function() {
                function wpStocksUsGauge(elId, value, label) {
                    if (value === null) {
                        document.getElementById(elId).innerHTML = '<p style="color:#888;padding:30px 0;">データ不足</p>';
                        return;
                    }
                    new ApexCharts(document.getElementById(elId), {
                        chart: { type: 'radialBar', height: 220 },
                        series: [Math.max(0, Math.min(value, 150))],
                        plotOptions: {
                            radialBar: {
                                startAngle: -90, endAngle: 90,
                                hollow: { size: '60%' },
                                dataLabels: {
                                    name: { show: false },
                                    value: { fontSize: '24px', formatter: function() { return value + '%'; }, offsetY: -10 },
                                },
                            },
                        },
                        fill: { colors: ['#0073aa'] },
                        labels: [label],
                    }).render();
                }
                wpStocksUsGauge('gaugeUsRevenue', <?php echo json_encode($revenue_progress); ?>, '売上高進捗率');
                wpStocksUsGauge('gaugeUsNetIncome', <?php echo json_encode($ni_progress); ?>, '純利益進捗率');

                function wpStocksUsQuarterlyChart(elId, prevData, curData) {
                    new ApexCharts(document.getElementById(elId), {
                        chart: { type: 'bar', height: 300, toolbar: { show: false } },
                        series: [
                            { name: '前期', data: prevData },
                            { name: '当期', data: curData },
                        ],
                        xaxis: { categories: <?php echo json_encode($categories); ?> },
                        colors: ['#aed6f1', '#2980b9'],
                        dataLabels: { enabled: false },
                        legend: { position: 'top' },
                        plotOptions: { bar: { columnWidth: '55%' } },
                        yaxis: { labels: { formatter: function(v) { return v === null ? '' : v.toLocaleString(); } } },
                        tooltip: { y: { formatter: function(v) { return v === null ? '-' : Number(v).toLocaleString() + '百万ドル'; } } },
                    }).render();
                }
                wpStocksUsQuarterlyChart('finChartUsQuarterlyRevenue', <?php echo json_encode($prev_revenue_cum); ?>, <?php echo json_encode($cur_revenue_cum); ?>);
                wpStocksUsQuarterlyChart('finChartUsQuarterlyNetIncome', <?php echo json_encode($prev_ni_cum); ?>, <?php echo json_encode($cur_ni_cum); ?>);
            })();
            </script>
            <?php
        }
    }

    echo '</div>'; // tab-finance
    } // end finance tab

    // ===== 適時開示タブ =====
    if ($active_tab === 'news') {
    echo '<div id="tab-news">';

    $fetch_news_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_stock_news&id=' . $id), 'wp_stocks_action_' . $id);
    $reset_news_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_stock_news&id=' . $id . '&reset=1'), 'wp_stocks_action_' . $id);
    $clear_news_url = wp_nonce_url(admin_url('admin-post.php?action=clear_stock_news&id=' . $id), 'wp_stocks_action_' . $id);

    echo '<p>';
    echo '<a href="' . esc_url($fetch_news_url) . '" class="button button-primary">最新情報を取得</a> ';
    echo '<a href="' . esc_url($reset_news_url) . '" class="button" onclick="return confirm(\'既存情報を削除して再取得しますか？\')">リセットして再取得</a> ';
    echo '<a href="' . esc_url($clear_news_url) . '" class="button" style="color:red;" onclick="return confirm(\'全削除しますか？\')">全削除</a>';
    echo '</p>';

    $news_list = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_news WHERE stock_id = %d ORDER BY published_at DESC LIMIT 30", $id
    ));

    if (!$news_list) {
        echo '<p style="color:#888;">情報がありません。「最新情報を取得」を実行してください。</p>';
    } else {
        echo '<div style="display:flex;flex-direction:column;gap:15px;max-width:900px;">';
        foreach ($news_list as $n) {
            $pub_date = $n->published_at ? date('Y/m/d H:i', strtotime($n->published_at)) : '';
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px;display:flex;gap:15px;align-items:flex-start;">';
            echo '<div style="flex:1;">';
            echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . esc_html($n->publisher) . ($pub_date ? ' ・ ' . $pub_date : '') . '</div>';
            echo '<a href="' . esc_url($n->link) . '" target="_blank" style="font-size:15px;font-weight:bold;color:#0073aa;text-decoration:none;">' . esc_html($n->title) . '</a>';
            echo '</div></div>';
        }
        echo '</div>';
    }

    echo '</div>'; // tab-news
    } // end news tab

    // ===== AI分析タブ =====
    if ($active_tab === 'ai') {
    echo '<div id="tab-ai">';

    // AI詳細分析結果
    echo '<h3>🤖 AI詳細分析結果</h3>';
    if (isset($_GET['message']) && $_GET['message'] === 'ai_analysis_saved') {
        echo '<div class="updated"><p>AI詳細分析結果を保存しました。</p></div>';
    }
    if (!empty($stock->ai_analysis)) {
        $ai_updated = $stock->ai_analysis_updated_at ? date('Y/m/d H:i', strtotime($stock->ai_analysis_updated_at)) : '';
        echo '<div style="background:#f0f7ff;border:1px solid #3498db;border-radius:8px;padding:16px;margin-bottom:15px;">';
        if ($ai_updated) echo '<div style="font-size:11px;color:#888;margin-bottom:8px;">更新日：' . esc_html($ai_updated) . '</div>';
        echo '<pre style="white-space:pre-wrap;word-wrap:break-word;font-family:inherit;font-size:13px;line-height:1.8;margin:0;">' . esc_html($stock->ai_analysis) . '</pre>';
        echo '</div>';
    }
    echo '<details style="margin-bottom:25px;">';
    echo '<summary style="cursor:pointer;font-size:13px;color:#0073aa;padding:8px 0;">✏️ AI詳細分析結果を' . (!empty($stock->ai_analysis) ? '編集する' : '入力する') . '</summary>';
    echo '<div style="margin-top:10px;">';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_ai_analysis">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_ai_analysis_nonce');
    echo '<textarea name="ai_analysis" style="width:100%;height:300px;font-family:monospace;font-size:12px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="AIの詳細分析結果をそのまま貼り付けてください...">' . esc_textarea($stock->ai_analysis ?? '') . '</textarea>';
    echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">AI詳細分析結果を保存</button></p>';
    echo '</form>';
    echo '</div></details>';

    // パイプ→AI履歴保存
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px;margin-bottom:20px;">';
    echo '<h4 style="margin:0 0 10px 0;font-size:13px;">📊 AIの出力データを保存</h4>';
    echo '<textarea id="pipe_input" style="width:100%;height:60px;font-family:monospace;font-size:12px;padding:8px;border:1px solid #ddd;border-radius:4px;" placeholder="例: ' . esc_attr($stock->code) . '|' . esc_attr($stock->name) . '|2026/05/19|B|A|A|買い|..."></textarea>';
    echo '<div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
    echo '<button onclick="saveAiHistory()" class="button button-primary">💾 AI分析結果を履歴に保存</button>';
    echo '</div></div>';


    // AI分析履歴
    $ai_history = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_ai_history WHERE stock_id = %d ORDER BY analysis_date DESC, created_at DESC LIMIT 20", $id
    ));
    if ($ai_history) {
        echo '<h3>📂 AI分析履歴</h3>';
        foreach ($ai_history as $ah) {
            $del_url = wp_nonce_url(admin_url('admin-post.php?action=delete_ai_history&id=' . $ah->id . '&stock_id=' . $id), 'wp_stocks_delete_ai_' . $ah->id);
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:15px;">';

            // ヘッダー行（分析日・総合スコア・削除）
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #eee;padding-bottom:8px;">';
            echo '<div style="font-size:13px;font-weight:bold;color:#555;">📅 ' . esc_html($ah->analysis_date) . '</div>';
            echo '<div style="font-size:16px;font-weight:bold;color:#0073aa;">総合スコア：' . esc_html($ah->total_score) . '</div>';
            echo '<a href="' . esc_url($del_url) . '" class="button button-small" style="color:red;" onclick="return confirm(\'削除しますか？\');">削除</a>';
            echo '</div>';

            // 指標を2列グリッド表示
            echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">';
            foreach ([
                ['バリュエーション', $ah->valuation],
                ['財務健全性',       $ah->financial_health],
                ['成長性',           $ah->growth],
                ['相場状態',         $ah->market_status],
                ['配当評価',         $ah->dividend_eval],
                ['適正株価',         $ah->fair_price],
            ] as [$label, $val]) {
                if (empty($val)) continue;
                echo '<div style="background:#f8f9fa;border-radius:4px;padding:8px 10px;">';
                echo '<div style="font-size:11px;color:#888;margin-bottom:2px;">' . $label . '</div>';
                echo '<div style="font-size:13px;font-weight:bold;">' . esc_html($val) . '</div>';
                echo '</div>';
            }
            echo '</div>';

            // 見通し・リスク・カタリスト（全幅）
            foreach ([
                ['短期見通し', $ah->short_outlook],
                ['中期見通し', $ah->mid_outlook],
                ['リスク要因', $ah->risk_factor],
                ['カタリスト', $ah->catalyst],
            ] as [$label, $val]) {
                if (empty($val)) continue;
                echo '<div style="background:#f8f9fa;border-radius:4px;padding:8px 10px;margin-bottom:6px;">';
                echo '<div style="font-size:11px;color:#888;margin-bottom:2px;">' . $label . '</div>';
                echo '<div style="font-size:13px;">' . esc_html($val) . '</div>';
                echo '</div>';
            }

            // 一言コメント
            if (!empty($ah->comment)) {
                echo '<div style="background:#fff8e1;border:1px solid #f39c12;border-radius:4px;padding:10px;margin-top:8px;">';
                echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">💬 一言コメント</div>';
                echo '<div style="font-size:13px;line-height:1.6;">' . esc_html($ah->comment) . '</div>';
                echo '</div>';
            }

            echo '</div>';
        }
    }


    // AI分析用テキスト生成
    echo '<h3>🤖 AI分析用テキスト生成</h3>';
    $analysis_text  = "【" . $stock->name . "（" . $stock->code . "）AI分析依頼】\n\n";
    $analysis_text .= "■ 基本情報\n市場：" . ($stock->market ?: '東証') . "\n業種：" . ($stock->sector ?: '-') . " / " . ($stock->industry ?: '-') . "\n";
    if ($stock->market_cap > 0) $analysis_text .= "時価総額：" . number_format($stock->market_cap / 100000000) . "億円\n";
    if ($latest) {
        $analysis_text .= "現在株価：" . number_format($latest->price) . "円（" . $latest->datetime . "）\n";
        if ($latest->previous_close) {
            $ch = $latest->price - $latest->previous_close;
            $chp = $latest->previous_close > 0 ? ($ch / $latest->previous_close * 100) : 0;
            $analysis_text .= "前日比：" . ($ch >= 0 ? '+' : '') . number_format($ch) . "円（" . ($chp >= 0 ? '+' : '') . number_format($chp, 2) . "%）\n";
        }
    }
    if ($tech) {
        $analysis_text .= "\n■ テクニカル指標\n";
        $analysis_text .= "トレンド：" . $tech->trend . "（強さ：" . $tech->trend_strength . "）\n";
        if ($tech->ma5)  $analysis_text .= "MA5：" . number_format($tech->ma5, 1) . "円\n";
        if ($tech->ma25) $analysis_text .= "MA25：" . number_format($tech->ma25, 1) . "円\n";
        if ($tech->macd) $analysis_text .= "MACD：" . $tech->macd . " / Signal：" . ($tech->macd_signal ?? '-') . "\n";
        if ($tech->rsi)  $analysis_text .= "RSI：" . $tech->rsi . "\n";
        if ($tech->cross_signal) $analysis_text .= "シグナル：" . ($tech->cross_signal === 'golden' ? 'ゴールデンクロス' : 'デッドクロス') . "\n";
    }
    $analysis_text .= "\n■ 株価指標\n";
    if ($stock->per > 0)            $analysis_text .= "PER（実績）：" . number_format($stock->per, 1) . "倍\n";
    if ($stock->forward_per > 0)    $analysis_text .= "PER（予想）：" . number_format($stock->forward_per, 1) . "倍\n";
    if ($stock->pbr > 0)            $analysis_text .= "PBR：" . number_format($stock->pbr, 2) . "倍\n";
    if ($stock->eps != 0)           $analysis_text .= "EPS（実績）：" . number_format($stock->eps, 1) . "円\n";
    if ($stock->forward_eps != 0)   $analysis_text .= "EPS（予想）：" . number_format($stock->forward_eps, 1) . "円\n";
    if ($stock->roe != 0)           $analysis_text .= "ROE：" . number_format($stock->roe, 1) . "%\n";
    if ($stock->roa != 0)           $analysis_text .= "ROA：" . number_format($stock->roa, 1) . "%\n";
    if ($stock->peg > 0)            $analysis_text .= "PEG：" . number_format($stock->peg, 2) . "\n";
    if ($stock->dividend_yield > 0) $analysis_text .= "配当利回り：" . number_format($stock->dividend_yield, 2) . "%\n";
    if ($stock->equity_ratio > 0)   $analysis_text .= "自己資本比率：" . number_format($stock->equity_ratio, 1) . "%\n";
    $analysis_text .= "\n■ 業績指標\n";
    $analysis_text .= "売上成長率：" . ($stock->revenue_growth >= 0 ? '+' : '') . number_format($stock->revenue_growth, 1) . "%\n";
    $analysis_text .= "利益成長率：" . ($stock->earnings_growth >= 0 ? '+' : '') . number_format($stock->earnings_growth, 1) . "%\n";
    $analysis_text .= "利益率：" . number_format($stock->profit_margin, 1) . "%\n";
    if ($stock->revenue > 0)    $analysis_text .= "売上高：" . number_format($stock->revenue / 1000000) . "百万円\n";
    if ($stock->net_income > 0) $analysis_text .= "純利益：" . number_format($stock->net_income / 1000000) . "百万円\n";
    if (!empty($stock->theme_tags)) {
        $analysis_text .= "\n■ テーマタグ\n";
        foreach (explode(',', $stock->theme_tags) as $tag) $analysis_text .= "#" . trim($tag) . " ";
        $analysis_text .= "\n";
    }
    $news_list = $wpdb->get_results($wpdb->prepare("SELECT title, publisher, published_at FROM {$wpdb->prefix}stock_news WHERE stock_id = %d ORDER BY published_at DESC LIMIT 3", $id));
    if ($news_list) {
        $analysis_text .= "\n■ 最新ニュース\n";
        foreach ($news_list as $n) $analysis_text .= "・" . $n->title . "（" . $n->publisher . "）\n";
    }
    $analysis_text .= "\n■ 分析依頼\n以上のデータをもとに以下11項目をすべて漏れなく分析してください：\n";
    $analysis_text .= "1.バリュエーション評価　2.財務健全性　3.成長性　4.総合投資判断　5.同業他社比較\n";
    $analysis_text .= "6.相場状態　7.リスク要因　8.カタリスト　9.配当評価　10.適正株価試算　11.短期・中期見通し\n";
    $analysis_text .= "\n■ スプレッドシート貼り付け用データ出力\n";
    $analysis_text .= "分析後、以下の形式で「|」区切りで1行出力してください：\n";
    $analysis_text .= $stock->code . "|" . $stock->name . "|" . date('Y/m/d') . "|バリュエーション|財務健全性|成長性|相場状態|リスク要因|カタリスト|配当評価|適正株価|短期見通し|中期見通し|総合スコア|一言コメント\n";

    echo '<div style="margin-bottom:20px;">';
    echo '<button onclick="copyAnalysisText()" class="button button-primary" style="margin-bottom:10px;">📋 AI分析用テキストをコピー</button>';
    echo '<textarea id="analysis_text_area" style="width:100%;height:250px;font-family:monospace;font-size:12px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;">' . esc_textarea($analysis_text) . '</textarea>';
    echo '</div>';

    // 隠しフォーム（AI履歴保存用）
    echo '<form id="ai_history_form" method="post" action="' . admin_url('admin-post.php') . '" style="display:none;">';
    echo '<input type="hidden" name="action" value="save_ai_history">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    echo '<input type="hidden" name="pipe_data" id="ai_pipe_data" value="">';
    wp_nonce_field('wp_stocks_ai_history_nonce');
    echo '</form>';

    echo '<script>
    function copyAnalysisText() {
        var ta = document.getElementById("analysis_text_area");
        ta.select(); ta.setSelectionRange(0,99999);
        navigator.clipboard.writeText(ta.value).then(function(){alert("コピーしました！");}).catch(function(){document.execCommand("copy");alert("コピーしました！");});
    }
    function saveAiHistory() {
        var input = document.getElementById("pipe_input").value.trim();
        if (!input) { alert("AIの出力データを貼り付けてください"); return; }
        if (input.split("|").length < 5) { alert("パイプ区切り（|）のデータを貼り付けてください"); return; }
        document.getElementById("ai_pipe_data").value = input;
        if (confirm("この分析結果を履歴に保存しますか？")) {
            document.getElementById("ai_history_form").submit();
        }
    }
    </script>';
    echo '</div>'; // tab-ai
    } // end ai tab

    // ===== 編集タブ =====
    if ($active_tab === 'edit') {
    echo '<div id="tab-edit">';
    if (isset($_GET['message']) && $_GET['message'] === 'shikiho_saved') {
        echo '<div class="updated"><p>四季報情報を保存しました。</p></div>';
    }
    if (isset($_GET['message']) && $_GET['message'] === 'sector_override_saved') {
        echo '<div class="updated"><p>業種（手動設定）を保存しました。</p></div>';
    }
    // 業種（手動設定・日本株のみ）：四季報の自動判定を上書きする。四季報一括再抽出でも上書きされない。
    if (!$is_usd) {
        echo '<h3 style="margin-top:25px;">&#x1F3F7;&#xFE0F; 業種（手動設定）</h3>';
        echo '<p style="color:#666;font-size:13px;">四季報からの自動判定が実態と合わない場合（「その他」等）に、ここで33業種のいずれかを手動指定できます。指定するとこちらが優先され、「四季報からセクターを一括再抽出」を実行しても上書きされません。</p>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="update_sector_override">';
        echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('wp_stocks_sector_override_nonce');
        echo '<select name="sector_override" style="margin-right:10px;">';
        echo '<option value="">自動（四季報から取得：' . esc_html($stock->sector ?: '未設定') . '）</option>';
        foreach (wp_stocks_get_topix17_sector_map() as $topix17_code => $topix17_info) {
            echo '<optgroup label="' . esc_attr($topix17_code . ' ' . $topix17_info['name']) . '">';
            foreach ($topix17_info['sectors'] as $s33) {
                $osel = ($stock->sector_override ?? '') === $s33 ? 'selected' : '';
                echo '<option value="' . esc_attr($s33) . '" ' . $osel . '>' . esc_html($s33) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';
        echo '<button type="submit" class="button button-primary">保存</button></form>';
    }
    if (isset($_GET['message']) && $_GET['message'] === 'screening_saved') {
        echo '<div class="updated"><p>スクリーニングフラグを保存しました。</p></div>';
    }
    // スクリーニングフラグ（手動）
    echo '<h3>&#x1F3AF; スクリーニングフラグ</h3>';
    echo '<p style="color:#666;font-size:13px;">外部のスクリーニングツールや四季報を見て判断した結果を手動で記録し、ダッシュボードで絞り込めます。</p>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_screening_flags">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_screening_nonce');
    echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="screen_op_profit" ' . (intval($stock->screen_op_profit ?? 0) ? 'checked' : '') . '> 営業利益が高い</label>';
    echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="screen_net_income" ' . (intval($stock->screen_net_income ?? 0) ? 'checked' : '') . '> 純利益が高い</label>';
    echo '<label style="display:block;margin-bottom:10px;"><input type="checkbox" name="screen_shikiho" ' . (intval($stock->screen_shikiho ?? 0) ? 'checked' : '') . '> 四季報の見出しが良い</label>';
    echo '<button type="submit" class="button button-primary">保存</button></form>';
    // テーマタグ
    echo '<h3>&#x1F3F7;&#xFE0F; テーマタグ</h3>';
    $tags = $stock->theme_tags ?? '';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_theme_tags">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_theme_tags_nonce');
    echo '<input type="text" name="theme_tags" value="' . esc_attr($tags) . '" style="width:400px;margin-right:10px;" placeholder="例：ゲーム, ファブレス, 円安メリット">';
    echo '<button type="submit" class="button button-primary">保存</button></form>';
    // 銘柄メモ
    echo '<h3 style="margin-top:25px;">&#x1F4DD; 銘柄メモ</h3>';
    echo '<p style="color:#666;font-size:13px;">注目理由・分析メモ・決算後の所感など自由に記録できます。</p>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_memo">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_memo_nonce');
    echo '<textarea name="memo" style="width:100%;height:150px;font-size:13px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="例：PER10倍以下で割安。次の決算で増収増益なら買い増し検討。">' . esc_textarea($stock->memo ?? '') . '</textarea>';
    echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">メモを保存</button></p>';
    echo '</form>';
    // 四季報入力（日本株のみ）
    if (!$is_usd) {
        echo '<h3 style="margin-top:25px;">&#x1F4D6; 四季報情報</h3>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="update_shikiho">';
        echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('wp_stocks_shikiho_nonce');
        echo '<textarea name="shikiho" style="width:100%;height:200px;font-family:monospace;font-size:12px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="四季報の情報をそのまま貼り付けてください...">' . esc_textarea($stock->shikiho ?? '') . '</textarea>';
        echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">四季報情報を保存</button></p>';
        echo '</form>';
    }
    // 企業情報再取得
    $info_url = wp_nonce_url(admin_url('admin-post.php?action=update_company_info&id=' . $id . '&from=wp-stocks-company'), 'wp_stocks_action_' . $id);
    echo '<p style="margin-top:25px;"><a href="' . esc_url($info_url) . '" class="button">&#x1F504; Yahoo!ファイナンスから企業情報を再取得</a></p>';
    echo '</div>'; // tab-edit
    } // end edit tab
    echo '</div>'; // tabs wrapper
}

// --------------------------------------------------
// 投資信託 基準価額取得
// --------------------------------------------------
function wp_stocks_get_fund_price($fund_code) {
    $url = 'https://finance.yahoo.co.jp/quote/' . $fund_code;
    $response = wp_remote_get($url, [
        'headers' => [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
            'Accept-Language' => 'ja,en;q=0.9',
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) return false;
    if (wp_remote_retrieve_response_code($response) !== 200) return false;

    $html = wp_remote_retrieve_body($response);
    preg_match_all('/StyledNumber__value_[^"]*"[^>]*>([\d,\.]+)/', $html, $matches);
    if (empty($matches[1])) return false;

    $price = floatval(str_replace(',', '', $matches[1][0]));
    return $price > 0 ? $price : false;
}

function wp_stocks_save_fund_price($fund_id, $fund_code) {
    global $wpdb;
    $price = wp_stocks_get_fund_price($fund_code);
    if (!$price) {
        wp_stocks_log('error', 'fund_price', $fund_code, '基準価額の取得に失敗');
        return false;
    }
    $today = current_time('Y-m-d');
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}stock_fund_prices WHERE fund_id = %d AND price_date = %s",
        $fund_id, $today
    ));
    if ($exists) {
        $wpdb->update($wpdb->prefix . 'stock_fund_prices', ['price' => $price], ['id' => $exists]);
    } else {
        $wpdb->insert($wpdb->prefix . 'stock_fund_prices', [
            'fund_id'    => $fund_id,
            'price'      => $price,
            'price_date' => $today,
        ]);
    }
    // 2日より古いデータを削除
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}stock_fund_prices WHERE fund_id = %d AND price_date < DATE_SUB(%s, INTERVAL 1 DAY)",
        $fund_id, $today
    ));
    wp_stocks_log('info', 'fund_price', $fund_code, '基準価額を保存しました: ' . number_format($price) . '円');
    return true;
}


// --------------------------------------------------
// 決算カレンダーページ
// --------------------------------------------------
// --------------------------------------------------
// 銘柄比較ページ
// --------------------------------------------------
function wp_stocks_compare_page() {
    global $wpdb;
    echo '<div class="wrap"><h1>📊 銘柄比較</h1>';

    $all_stocks = $wpdb->get_results("SELECT id, code, name, sector FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 0 ORDER BY sector ASC, code ASC");
    if (!$all_stocks) { echo '<p>銘柄が登録されていません。</p></div>'; return; }

    // セクターごとにグループ化（5つの選択欄で共通利用）
    $compare_stocks_by_sector = [];
    foreach ($all_stocks as $s) {
        $sector_label = !empty($s->sector) ? wp_stocks_sector_ja($s->sector) : '未分類';
        $compare_stocks_by_sector[$sector_label][] = $s;
    }
    ksort($compare_stocks_by_sector);

    // 選択された銘柄ID（最大5件）
    $selected_ids = [];
    if (!empty($_GET['ids'])) {
        foreach (explode(',', sanitize_text_field($_GET['ids'])) as $sid) {
            $sid = intval($sid);
            if ($sid > 0) $selected_ids[] = $sid;
        }
        $selected_ids = array_slice(array_unique($selected_ids), 0, 5);
    }

    // 選択フォーム
    echo '<form method="get" style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:15px;margin-bottom:20px;">';
    echo '<input type="hidden" name="page" value="wp-stocks-compare">';
    echo '<p style="margin:0 0 10px 0;font-size:13px;color:#555;">比較する銘柄を2〜5件選択してください</p>';
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
    for ($i = 0; $i < 5; $i++) {
        echo '<select name="stock_ids[]" class="wp-stocks-sector-select" data-placeholder="-- 選択 --" style="width:220px;">';
        echo '<option value="">-- 選択 --</option>';
        foreach ($compare_stocks_by_sector as $sector_label => $sector_stocks) {
            echo '<optgroup label="' . esc_attr($sector_label) . '">';
            foreach ($sector_stocks as $s) {
                $sel = in_array($s->id, $selected_ids) && ($selected_ids[$i] ?? 0) == $s->id ? 'selected' : '';
                echo '<option value="' . esc_attr($s->id) . '" ' . $sel . '>' . esc_html($s->code . ' ' . $s->name) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';
    }
    echo '</div>';
    echo '<button type="submit" class="button button-primary" onclick="this.form.ids.value=Array.from(this.form.querySelectorAll(\'select\')).map(s=>s.value).filter(v=>v).join(\',\')">比較する</button>';
    echo '<input type="hidden" name="ids" value="">';
    echo '</form>';

    // アコーディオン式ドロップダウン化（企業情報ページと共通のアセット）
    wp_stocks_render_sector_dropdown_assets();

    if (count($selected_ids) < 2) { echo '<p style="color:#888;">銘柄を2件以上選択してください。</p></div>'; return; }

    // 比較データ取得
    $stocks = [];
    $techs  = [];
    $prices = [];
    foreach ($selected_ids as $sid) {
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $sid));
        if ($s) {
            $stocks[$sid] = $s;
            $techs[$sid]  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stock_technicals WHERE stock_id = %d", $sid));
            $prices[$sid] = $wpdb->get_row($wpdb->prepare("SELECT price, previous_close FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $sid));
        }
    }

    if (empty($stocks)) { echo '<p>銘柄データが見つかりません。</p></div>'; return; }

    // 比較テーブル
    $rows = [
        ['セクター',     fn($s) => wp_stocks_sector_ja($s->sector ?? '-')],
        ['現在株価',     fn($s) => ($prices[$s->id] ?? null) ? number_format($prices[$s->id]->price) . '円' : '-'],
        ['前日比',       fn($s) => ($prices[$s->id]->previous_close ?? 0) > 0 ? wp_stocks_change_html($prices[$s->id]->price, $prices[$s->id]->previous_close, ($s->currency ?? 'JPY') === 'USD') : '-'],
        ['トレンド',     fn($s) => wp_stocks_trend_icon_html($techs[$s->id] ?? null)],
        ['スコア',       fn($s) => (function($sc) { return '<span style="font-weight:bold;color:' . $sc['judgment']['color'] . ';">' . $sc['score'] . '点 ' . $sc['judgment']['label'] . '</span>'; })(wp_stocks_calc_score($s))],
        ['PER（実績）',  fn($s) => ($s->per ?? 0) > 0 ? number_format($s->per, 1) . '倍' : '-'],
        ['PER（予想）',  fn($s) => ($s->forward_per ?? 0) > 0 ? number_format($s->forward_per, 1) . '倍' : '-'],
        ['PBR',          fn($s) => ($s->pbr ?? 0) > 0 ? number_format($s->pbr, 2) . '倍' : '-'],
        ['ROE',          fn($s) => ($s->roe ?? 0) != 0 ? number_format($s->roe, 1) . '%' : '-'],
        ['ROA',          fn($s) => ($s->roa ?? 0) != 0 ? number_format($s->roa, 1) . '%' : '-'],
        ['PEG',          fn($s) => ($s->peg ?? 0) > 0 ? number_format($s->peg, 2) : '-'],
        ['PEG(実績)',    fn($s) => ($s->peg_trailing ?? 0) > 0 ? number_format($s->peg_trailing, 2) : '-'],
        ['配当利回り',   fn($s) => ($s->dividend_yield ?? 0) > 0 ? number_format($s->dividend_yield, 2) . '%' : '-'],
        ['自己資本比率', fn($s) => ($s->equity_ratio ?? 0) > 0 ? number_format($s->equity_ratio, 1) . '%' : '-'],
        ['EPS（実績）',  fn($s) => ($s->eps ?? 0) != 0 ? number_format($s->eps, 1) . '円' : '-'],
        ['EPS（予想）',  fn($s) => ($s->forward_eps ?? 0) != 0 ? number_format($s->forward_eps, 1) . '円' : '-'],
        ['売上成長率',   fn($s) => ($s->revenue_growth ?? 0) != 0 ? ($s->revenue_growth >= 0 ? '+' : '') . number_format($s->revenue_growth, 1) . '%' : '-'],
        ['利益成長率',   fn($s) => ($s->earnings_growth ?? 0) != 0 ? ($s->earnings_growth >= 0 ? '+' : '') . number_format($s->earnings_growth, 1) . '%' : '-'],
        ['利益率',       fn($s) => ($s->profit_margin ?? 0) != 0 ? number_format($s->profit_margin, 1) . '%' : '-'],
        ['時価総額',     fn($s) => ($s->market_cap ?? 0) > 0 ? number_format(intval($s->market_cap) / 100000000) . '億円' : '-'],
        ['決算予定日',   fn($s) => !empty($s->earnings_date) ? $s->earnings_date : '-'],
    ];

    echo '<div style="overflow-x:auto;">';
    echo '<table class="widefat" style="font-size:13px;min-width:600px;">';
    echo '<thead><tr><th style="width:130px;background:#f8f9fa;">指標</th>';
    foreach ($stocks as $sid => $s) {
        $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $sid);
        echo '<th style="text-align:center;background:#f8f9fa;"><a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:#0073aa;">' . esc_html($s->code . '<br>' . $s->name) . '</a></th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $i => [$label, $fn]) {
        $bg = $i % 2 === 0 ? '#fff' : '#f9f9f9';
        echo '<tr style="background:' . $bg . '"><td style="font-weight:bold;padding:8px 10px;border:1px solid #eee;">' . $label . '</td>';
        foreach ($stocks as $sid => $s) {
            echo '<td style="text-align:center;padding:8px 10px;border:1px solid #eee;">' . $fn($s) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // =============================================
    // 重ねラインチャート（基準化）
    // =============================================
    echo '<h3 style="margin-top:25px;">パフォーマンス比較チャート</h3>';
    echo '<p style="color:#888;font-size:12px;margin-top:-10px;margin-bottom:15px;">最初の日の終値を100として基準化した相対パフォーマンスを比較します。</p>';

    // 各銘柄の株価履歴を取得
    $chart_colors = ['#e74c3c', '#3498db', '#27ae60', '#f39c12', '#9b59b6'];
    $chart_data   = [];
    foreach ($stocks as $sid => $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        $sym    = $is_usd ? $s->code : $s->code . '.T';
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(datetime) as date, price FROM {$wpdb->prefix}stock_prices
             WHERE stock_id = %d AND datetime >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             ORDER BY datetime ASC",
            $sid
        ));
        // 日付ごとに最新の価格を使用
        $daily = [];
        foreach ($history as $h) {
            $daily[$h->date] = floatval($h->price);
        }
        ksort($daily);
        $chart_data[$sid] = [
            'name'   => $s->code . ' ' . $s->name,
            'daily'  => $daily,
            'is_usd' => $is_usd,
        ];
    }

    $chart_data_json = json_encode($chart_data, JSON_UNESCAPED_UNICODE);
    $colors_json     = json_encode($chart_colors);
    $stock_ids_json  = json_encode(array_keys($stocks));
    ?>
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:25px;">
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
            <span style="font-size:13px;color:#888;">期間：</span>
            <button onclick="setCompareRange('1m')" class="button button-small" id="btn_1m">1ヶ月</button>
            <button onclick="setCompareRange('3m')" class="button button-small" id="btn_3m" style="background:#0073aa;color:#fff;">3ヶ月</button>
            <button onclick="setCompareRange('6m')" class="button button-small" id="btn_6m">6ヶ月</button>
            <div style="margin-left:15px;display:flex;gap:12px;flex-wrap:wrap;" id="chart_legend"></div>
        </div>
        <div id="compareChart" style="width:100%;height:350px;"></div>
    </div>

    <script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
    <script>
    var rawData   = <?php echo $chart_data_json; ?>;
    var colors    = <?php echo $colors_json; ?>;
    var stockIds  = <?php echo $stock_ids_json; ?>;
    var compareChart = null;
    var currentRange = '3m';

    function getDateBefore(months) {
        var d = new Date();
        d.setMonth(d.getMonth() - months);
        return d.toISOString().split('T')[0];
    }

    function buildLegend() {
        var legend = document.getElementById('chart_legend');
        legend.innerHTML = '';
        stockIds.forEach(function(sid, i) {
            var d    = rawData[sid];
            var color = colors[i % colors.length];
            var el   = document.createElement('span');
            el.style.cssText = 'display:inline-flex;align-items:center;gap:4px;font-size:12px;';
            el.innerHTML = '<span style="display:inline-block;width:20px;height:3px;background:' + color + ';border-radius:2px;"></span>'
                + '<span>' + d.name + '</span>';
            legend.appendChild(el);
        });
    }

    function setCompareRange(range) {
        currentRange = range;
        ['1m','3m','6m'].forEach(function(r) {
            var btn = document.getElementById('btn_' + r);
            btn.style.background = r === range ? '#0073aa' : '';
            btn.style.color      = r === range ? '#fff' : '';
        });
        renderCompareChart();
    }

    function renderCompareChart() {
        if (compareChart) { compareChart.remove(); compareChart = null; }
        document.getElementById('compareChart').innerHTML = '';

        compareChart = LightweightCharts.createChart(document.getElementById('compareChart'), {
            width:  document.getElementById('compareChart').clientWidth,
            height: 350,
            layout: { background: { color: '#fff' }, textColor: '#333' },
            grid:   { vertLines: { color: '#f0f0f0' }, horzLines: { color: '#f0f0f0' } },
            rightPriceScale: { borderColor: '#ddd' },
            timeScale: { borderColor: '#ddd', timeVisible: true },
            crosshair: { mode: 1 },
        });

        var months = currentRange === '1m' ? 1 : (currentRange === '3m' ? 3 : 6);
        var cutoff = getDateBefore(months);

        stockIds.forEach(function(sid, i) {
            var d     = rawData[sid];
            var color = colors[i % colors.length];
            var daily = d.daily;
            var dates = Object.keys(daily).filter(function(dt) { return dt >= cutoff; }).sort();
            if (dates.length === 0) return;

            // 基準化（最初の日=100）
            var base = daily[dates[0]];
            if (!base || base === 0) return;

            var series_data = dates.map(function(dt) {
                return { time: dt, value: Math.round(daily[dt] / base * 1000) / 10 };
            });

            var line = compareChart.addLineSeries({
                color:              color,
                lineWidth:          2,
                title:              d.name,
                priceLineVisible:   false,
                lastValueVisible:   true,
                crosshairMarkerVisible: true,
            });
            line.setData(series_data);
        });

        // 100の基準線
        compareChart.addLineSeries({
            color:            '#ccc',
            lineWidth:        1,
            lineStyle:        2,
            priceLineVisible: false,
            lastValueVisible: false,
            title:            '基準(100)',
        }).setData(
            Object.keys(rawData[stockIds[0]].daily)
                .filter(function(dt) { return dt >= cutoff; })
                .sort()
                .map(function(dt) { return { time: dt, value: 100 }; })
        );

        compareChart.timeScale().fitContent();
    }

    buildLegend();
    renderCompareChart();

    window.addEventListener('resize', function() {
        if (compareChart) compareChart.applyOptions({ width: document.getElementById('compareChart').clientWidth });
    });
    </script>
    <?php

    // チャートリンク
    echo '<h3 style="margin-top:10px;">外部チャートリンク</h3>';
    echo '<div style="display:flex;gap:15px;flex-wrap:wrap;">';
    foreach ($stocks as $sid => $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;min-width:200px;">';
        echo '<div style="font-weight:bold;margin-bottom:8px;">' . esc_html($s->code . ' ' . $s->name) . '</div>';
        echo wp_stocks_tradingview_widget($s->code, 450, $is_usd);
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
}

// --------------------------------------------------
// セクター別分析サマリーページ
// --------------------------------------------------
// --------------------------------------------------
// 前日比(%)からヒートマップ調の色を計算する共通関数
// （ヒートマップページと同じ計算式。セクター分析ページでも使用）
// --------------------------------------------------
function wp_stocks_change_heat_color($pct) {
    $pct = floatval($pct);
    $abs = min(abs($pct), 5);
    $intensity = intval($abs / 5 * 180) + 40;
    if ($pct > 0) {
        return "rgb(" . intval($intensity * 0.2) . ", {$intensity}, " . intval($intensity * 0.2) . ")";
    } elseif ($pct < 0) {
        return "rgb({$intensity}, " . intval($intensity * 0.2) . ", " . intval($intensity * 0.2) . ")";
    }
    return '#888';
}

// --------------------------------------------------
// セクター分析ページ：セクターバケットへの集計を行う共通ヘルパー
// （日本株/米国株バケットとポートフォリオバケット、両方で使い回す）
// --------------------------------------------------
function wp_stocks_sector_accumulate(&$bucket, $sector_ja, $s, $score_data, $tech) {
    if (!isset($bucket[$sector_ja])) {
        $bucket[$sector_ja] = ['stocks' => [], 'per_sum' => 0, 'per_cnt' => 0,
            'roe_sum' => 0, 'roe_cnt' => 0, 'div_sum' => 0, 'div_cnt' => 0,
            'score_sum' => 0, 'up' => 0, 'down' => 0, 'flat' => 0];
    }
    $bucket[$sector_ja]['stocks'][]   = $s;
    $bucket[$sector_ja]['score_sum'] += $score_data['score'];
    if (($s->per ?? 0) > 0)            { $bucket[$sector_ja]['per_sum'] += $s->per; $bucket[$sector_ja]['per_cnt']++; }
    if (($s->roe ?? 0) != 0)           { $bucket[$sector_ja]['roe_sum'] += $s->roe; $bucket[$sector_ja]['roe_cnt']++; }
    if (($s->dividend_yield ?? 0) > 0) { $bucket[$sector_ja]['div_sum'] += $s->dividend_yield; $bucket[$sector_ja]['div_cnt']++; }
    if ($tech) {
        if ($tech->trend === 'up')       $bucket[$sector_ja]['up']++;
        elseif ($tech->trend === 'down') $bucket[$sector_ja]['down']++;
        else                             $bucket[$sector_ja]['flat']++;
    }
}

function wp_stocks_sector_page($skip_wrap = false, $custom_base_url = null) {
    global $wpdb;

    $tab      = in_array($_GET['tab'] ?? '', ['jp', 'us', 'portfolio', 'etf', 'calendar', 'period']) ? $_GET['tab'] : 'jp';
    $base_url = $custom_base_url ?: admin_url('admin.php?page=wp-stocks-sector');

    $stocks    = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 0 ORDER BY sector, id");
    $tech_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_technicals");
    $tech_map  = [];
    foreach ($tech_rows as $t) $tech_map[$t->stock_id] = $t;

    // 最新株価を一括取得
    $price_map  = [];
    $price_rows = $wpdb->get_results("SELECT sp.stock_id, sp.price, sp.previous_close FROM {$wpdb->prefix}stock_prices sp INNER JOIN (SELECT stock_id, MAX(datetime) as md FROM {$wpdb->prefix}stock_prices GROUP BY stock_id) latest ON sp.stock_id = latest.stock_id AND sp.datetime = latest.md");
    foreach ($price_rows as $p) $price_map[$p->stock_id] = $p;

    // TOPIX-17セクターETF銘柄取得（旧wp_stocks_render_heatmap_tab()から移植）
    $etf_stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 1 ORDER BY id");

    // 日本株・米国株・ポートフォリオのセクター別に集計
    $jp_sectors = [];
    $us_sectors = [];
    $pf_sectors = [];
    foreach ($stocks as $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        if ($is_usd) {
            $sector_key = !empty($s->sector) ? $s->sector : 'その他';
        } else {
            // 日本株：手動上書き（sector_override）があれば優先し、なければ四季報抽出値を使う
            $effective_sector = !empty($s->sector_override) ? $s->sector_override : ($s->sector ?? '');
            $sector_key = !empty($effective_sector) ? $effective_sector : 'その他';
        }
        $sector_ja  = wp_stocks_sector_ja($sector_key);
        $score_data = wp_stocks_calc_score($s);
        $tech       = $tech_map[$s->id] ?? null;

        if ($is_usd) {
            wp_stocks_sector_accumulate($us_sectors, $sector_ja, $s, $score_data, $tech);
        } else {
            wp_stocks_sector_accumulate($jp_sectors, $sector_ja, $s, $score_data, $tech);
        }
        if (($s->status ?? '') === 'portfolio') {
            wp_stocks_sector_accumulate($pf_sectors, $sector_ja, $s, $score_data, $tech);
        }
    }
    ksort($jp_sectors);
    ksort($us_sectors);
    ksort($pf_sectors);

    // セクター描画クロージャ
    $render_sectors = function($sectors, $tab_color) use ($tech_map, $price_map) {
        if (empty($sectors)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
            return;
        }
        foreach ($sectors as $sector_name => $data) {
            $cnt       = count($data['stocks']);
            $avg_per   = $data['per_cnt'] > 0 ? round($data['per_sum'] / $data['per_cnt'], 1) : null;
            $avg_roe   = $data['roe_cnt'] > 0 ? round($data['roe_sum'] / $data['roe_cnt'], 1) : null;
            $avg_div   = $data['div_cnt'] > 0 ? round($data['div_sum'] / $data['div_cnt'], 2) : null;
            $avg_score = $cnt > 0 ? round($data['score_sum'] / $cnt) : 0;
            $score_color = $avg_score >= 70 ? '#27ae60' : ($avg_score >= 50 ? '#f39c12' : '#e74c3c');

            echo '<div style="background:#fff;border:1px solid #ddd;border-left:4px solid ' . $tab_color . ';border-radius:8px;padding:16px;margin-bottom:20px;">';
            echo '<div style="display:flex;align-items:center;gap:15px;margin-bottom:12px;flex-wrap:wrap;">';
            echo '<h3 style="margin:0;font-size:16px;">' . esc_html($sector_name) . '</h3>';
            echo '<span style="color:#888;font-size:13px;">' . $cnt . '銘柄</span>';
            echo '<span style="font-weight:bold;color:' . $score_color . ';">平均スコア：' . $avg_score . '点</span>';
            if ($avg_per) echo '<span style="font-size:13px;">平均PER：' . $avg_per . '倍</span>';
            if ($avg_roe) echo '<span style="font-size:13px;">平均ROE：' . $avg_roe . '%</span>';
            if ($avg_div) echo '<span style="font-size:13px;">平均配当：' . $avg_div . '%</span>';

            $total_tech = $data['up'] + $data['down'] + $data['flat'];
            if ($total_tech > 0) {
                echo '<span style="font-size:13px;">トレンド：'
                    . '<span style="color:#e74c3c;">↑' . $data['up'] . '</span> '
                    . '<span style="color:#888;">→' . $data['flat'] . '</span> '
                    . '<span style="color:#3498db;">↓' . $data['down'] . '</span></span>';
            }
            echo '</div>';

            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            foreach ($data['stocks'] as $s) {
                $score_data  = wp_stocks_calc_score($s);
                $sc          = $score_data['score'];
                $jc          = $score_data['judgment']['color'];
                $tech        = $tech_map[$s->id] ?? null;
                $detail_url  = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
                $price       = $price_map[$s->id] ?? null;
                $s_is_usd    = ($s->currency ?? 'JPY') === 'USD';

                $pct_change = null;
                if ($price && ($price->previous_close ?? 0) > 0) {
                    $pct_change = ($price->price - $price->previous_close) / $price->previous_close * 100;
                }
                $heat_bg = ($pct_change !== null) ? wp_stocks_change_heat_color($pct_change) : '#aaa';

                echo '<div style="background:' . $heat_bg . ';border-radius:6px;padding:8px 12px;min-width:140px;color:#fff;">';
                echo '<div style="font-size:11px;opacity:0.85;">' . esc_html($s->code) . '</div>';
                echo '<div style="font-weight:bold;font-size:13px;"><a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:#fff;">' . esc_html($s->name) . '</a></div>';
                if ($price) {
                    $price_disp = $s_is_usd ? '$' . number_format($price->price, 2) : number_format($price->price) . '円';
                    $pct_disp   = ($pct_change !== null) ? (($pct_change >= 0 ? '+' : '') . number_format($pct_change, 2) . '%') : '-';
                    echo '<div style="font-size:12px;margin-top:2px;">' . $price_disp . ' <strong>' . $pct_disp . '</strong></div>';
                } else {
                    echo '<div style="font-size:12px;margin-top:2px;opacity:0.7;">未取得</div>';
                }
                echo '<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">';
                echo '<span style="background:rgba(255,255,255,0.85);color:' . $jc . ';font-weight:bold;font-size:11px;padding:1px 6px;border-radius:3px;">' . $sc . '点</span>';
                if ($tech) {
                    echo '<span style="background:rgba(255,255,255,0.85);border-radius:3px;padding:1px 6px;">' . wp_stocks_trend_icon_html($tech) . '</span>';
                }
                echo '</div>';
                echo '</div>';
            }
            echo '</div></div>';
        }
    };

    // --------------------------------------------------
    // 日本株タブ専用：TOPIX-17（1617〜1633）→33業種→個別銘柄の階層マップ描画
    // 対応表に一致しない（＝分類不能な）銘柄は末尾の「未分類」枠にまとめる
    // --------------------------------------------------
    $render_jp_hierarchy = function($jp_sectors) use ($tech_map, $price_map, $etf_stocks) {
        if (empty($jp_sectors)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
            return;
        }

        $topix17_map = wp_stocks_get_topix17_sector_map();

        // TOPIX-17セクターETF自体の価格・騰落率（ベンチマークとして見出しに併記する）
        $etf_price_map = [];
        foreach ($etf_stocks as $es) {
            $p = $price_map[$es->id] ?? null;
            if ($p && ($p->previous_close ?? 0) > 0) {
                $etf_price_map[$es->code] = [
                    'price' => $p->price,
                    'pct'   => ($p->price - $p->previous_close) / $p->previous_close * 100,
                ];
            } else {
                $etf_price_map[$es->code] = ['price' => null, 'pct' => null];
            }
        }

        $tile_html = function($s) use ($tech_map, $price_map) {
            $score_data = wp_stocks_calc_score($s);
            $sc         = $score_data['score'];
            $jc         = $score_data['judgment']['color'];
            $tech       = $tech_map[$s->id] ?? null;
            $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
            $price      = $price_map[$s->id] ?? null;

            $pct_change = null;
            if ($price && ($price->previous_close ?? 0) > 0) {
                $pct_change = ($price->price - $price->previous_close) / $price->previous_close * 100;
            }
            $heat_bg = ($pct_change !== null) ? wp_stocks_change_heat_color($pct_change) : '#aaa';

            echo '<div style="background:' . $heat_bg . ';border-radius:6px;padding:8px 12px;min-width:140px;color:#fff;">';
            echo '<div style="font-size:11px;opacity:0.85;">' . esc_html($s->code) . '</div>';
            echo '<div style="font-weight:bold;font-size:13px;"><a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:#fff;">' . esc_html($s->name) . '</a></div>';
            if ($price) {
                $price_disp = number_format($price->price) . '円';
                $pct_disp   = ($pct_change !== null) ? (($pct_change >= 0 ? '+' : '') . number_format($pct_change, 2) . '%') : '-';
                echo '<div style="font-size:12px;margin-top:2px;">' . $price_disp . ' <strong>' . $pct_disp . '</strong></div>';
            } else {
                echo '<div style="font-size:12px;margin-top:2px;opacity:0.7;">未取得</div>';
            }
            echo '<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">';
            echo '<span style="background:rgba(255,255,255,0.85);color:' . $jc . ';font-weight:bold;font-size:11px;padding:1px 6px;border-radius:3px;">' . $sc . '点</span>';
            if ($tech) {
                echo '<span style="background:rgba(255,255,255,0.85);border-radius:3px;padding:1px 6px;">' . wp_stocks_trend_icon_html($tech) . '</span>';
            }
            echo '</div></div>';
        };

        $avg_pct_of = function($stock_list) use ($price_map) {
            $list = [];
            foreach ($stock_list as $s) {
                $p = $price_map[$s->id] ?? null;
                if ($p && ($p->previous_close ?? 0) > 0) {
                    $list[] = ($p->price - $p->previous_close) / $p->previous_close * 100;
                }
            }
            return !empty($list) ? array_sum($list) / count($list) : null;
        };

        // 33業種バケットをTOPIX-17コードごとにグルーピング。対応表に無いものは未分類へ。
        $grouped       = [];
        $unclassified  = [];
        foreach ($jp_sectors as $sector33 => $data) {
            $topix17_code = wp_stocks_sector33_to_topix17($sector33);
            if ($topix17_code === null) {
                foreach ($data['stocks'] as $s) $unclassified[] = $s;
                continue;
            }
            $grouped[$topix17_code][$sector33] = $data;
        }
        ksort($grouped);

        foreach ($grouped as $topix17_code => $sub_sectors) {
            ksort($sub_sectors);
            $group_stocks = [];
            foreach ($sub_sectors as $data) $group_stocks = array_merge($group_stocks, $data['stocks']);

            $group_avg_pct = $avg_pct_of($group_stocks);
            $group_color   = $group_avg_pct !== null ? wp_stocks_change_heat_color($group_avg_pct) : '#aaa';
            $topix17_name  = $topix17_map[$topix17_code]['name'] ?? $topix17_code;
            $etf_info      = $etf_price_map[$topix17_code] ?? null;
            $panel_id      = 'wss-topix17-' . $topix17_code;

            echo '<div style="border:1px solid #ddd;border-radius:8px;margin-bottom:16px;overflow:hidden;">';
            echo '<div class="wp-stocks-topix17-header" data-target="' . esc_attr($panel_id) . '" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;cursor:pointer;background:' . $group_color . ';color:#fff;">';
            echo '<div style="display:flex;align-items:center;gap:8px;">';
            echo '<span class="wss-topix17-caret" style="display:inline-block;transition:transform 0.2s;">&#x25BC;</span>';
            echo '<strong style="font-size:15px;">' . esc_html($topix17_code) . ' ' . esc_html($topix17_name) . '</strong>';
            echo '<span style="font-size:12px;opacity:0.85;">（' . count($group_stocks) . '銘柄）</span>';
            echo '</div>';
            echo '<div style="display:flex;align-items:center;gap:14px;">';
            if ($etf_info && $etf_info['price'] !== null) {
                $etf_pct_str = ($etf_info['pct'] >= 0 ? '+' : '') . number_format($etf_info['pct'], 2) . '%';
                echo '<span style="font-size:12px;opacity:0.9;">ETF ' . number_format($etf_info['price']) . '円　' . esc_html($etf_pct_str) . '</span>';
            }
            echo '<span style="font-size:14px;font-weight:bold;">' . ($group_avg_pct !== null ? (($group_avg_pct >= 0 ? '+' : '') . number_format($group_avg_pct, 2) . '%') : '-') . '</span>';
            echo '</div></div>';

            echo '<div id="' . esc_attr($panel_id) . '" style="padding:12px 16px;">';
            foreach ($sub_sectors as $sector33 => $data) {
                $sub_avg_pct = $avg_pct_of($data['stocks']);
                $sub_color   = $sub_avg_pct !== null ? wp_stocks_change_heat_color($sub_avg_pct) : '#888';

                echo '<div style="display:flex;align-items:center;justify-content:space-between;margin:10px 0 6px;">';
                echo '<span style="font-size:13px;color:#555;">' . esc_html($sector33) . ' <span style="color:#999;">（' . count($data['stocks']) . '銘柄）</span></span>';
                echo '<span style="font-size:12px;font-weight:bold;color:' . $sub_color . ';">' . ($sub_avg_pct !== null ? (($sub_avg_pct >= 0 ? '+' : '') . number_format($sub_avg_pct, 2) . '%') : '-') . '</span>';
                echo '</div>';

                echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">';
                foreach ($data['stocks'] as $s) $tile_html($s);
                echo '</div>';
            }
            echo '</div></div>';
        }

        if (!empty($unclassified)) {
            echo '<div style="border:1px dashed #ccc;border-radius:8px;padding:12px 16px;margin-top:8px;">';
            echo '<div style="font-size:13px;color:#888;margin-bottom:8px;">未分類（' . count($unclassified) . '銘柄）　'
                . '<span style="font-size:12px;">四季報から業種を判定できなかった銘柄です。各銘柄の編集タブ「業種（手動設定）」から分類してください。</span></div>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            foreach ($unclassified as $s) {
                $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id . '&tab=edit');
                echo '<a href="' . esc_url($detail_url) . '" style="text-decoration:none;">';
                echo '<div style="background:#eee;border-radius:6px;padding:6px 10px;font-size:12px;color:#555;">' . esc_html($s->code) . ' ' . esc_html($s->name) . '</div>';
                echo '</a>';
            }
            echo '</div></div>';
        }

        echo '<script>
        (function(){
            var headers = document.querySelectorAll(".wp-stocks-topix17-header");
            headers.forEach(function(h){
                h.addEventListener("click", function(){
                    var body = document.getElementById(this.dataset.target);
                    var caret = this.querySelector(".wss-topix17-caret");
                    if (!body) return;
                    if (body.style.display === "none") {
                        body.style.display = "block";
                        if (caret) caret.style.transform = "rotate(0deg)";
                    } else {
                        body.style.display = "none";
                        if (caret) caret.style.transform = "rotate(-90deg)";
                    }
                });
            });
        })();
        </script>';
    };

    // ============================================================
    // 描画
    // ============================================================
    if (!$skip_wrap) echo '<div class="wrap"><h1>&#x1F3ED; セクター別分析サマリー</h1>';

    // タブ
    echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
    foreach ([
        'jp'        => '&#x1F1EF;&#x1F1F5; 日本株（' . array_sum(array_map(fn($d) => count($d['stocks']), $jp_sectors)) . '銘柄）',
        'us'        => '&#x1F1FA;&#x1F1F8; 米国株（' . array_sum(array_map(fn($d) => count($d['stocks']), $us_sectors)) . '銘柄）',
        'portfolio' => '&#x1F4C1; ポートフォリオ（' . array_sum(array_map(fn($d) => count($d['stocks']), $pf_sectors)) . '銘柄）',
        'etf'       => '&#x1F3C6; ランキング',
        'calendar'  => '&#x1F4C5; カレンダー',
        'period'    => '&#x23F1;&#xFE0F; 期間別',
    ] as $key => $label) {
        $active = $tab === $key;
        $url    = $base_url . '&tab=' . $key;
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;margin-bottom:20px;">';

    // 凡例（前日比の色分け）を上部に表示（日本株/米国株/ポートフォリオ/ランキングタブのみ）
    if (in_array($tab, ['jp', 'us', 'portfolio', 'etf'], true)) {
    echo '<div style="margin-bottom:20px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;color:#555;">';
    echo '<strong>凡例：</strong> ';
    echo '<span style="display:inline-block;background:rgb(40,220,40);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25B2;+5%以上</span>';
    echo '<span style="display:inline-block;background:rgb(30,150,30);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25B2;+1〜5%</span>';
    echo '<span style="display:inline-block;background:#888;color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">0%</span>';
    echo '<span style="display:inline-block;background:rgb(150,30,30);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25BC;-1〜5%</span>';
    echo '<span style="display:inline-block;background:rgb(220,40,40);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25BC;-5%以上</span>';
    echo '<span style="display:inline-block;background:#aaa;color:#fff;padding:2px 10px;border-radius:3px;">未取得</span>';
    echo '</div>';
    }

    if ($tab === 'jp') {
        $render_jp_hierarchy($jp_sectors);
    } elseif ($tab === 'us') {
        $render_sectors($us_sectors, '#3498db');
    } elseif ($tab === 'etf') {
        // TOPIX-17セクター指数ランキング（実際のETF価格ベース、騰落率順に全17件を1列表示）
        if (empty($etf_stocks)) {
            echo '<p style="color:#888;padding:20px;">TOPIX-17セクターETFが登録されていません。</p>';
        } else {
            $topix17_map = wp_stocks_get_topix17_sector_map();
            $ranking = [];
            foreach ($etf_stocks as $s) {
                $p        = $price_map[$s->id] ?? null;
                $map_info = $topix17_map[$s->code] ?? null;
                $label    = $map_info['name'] ?? $s->name;
                if ($p && ($p->previous_close ?? 0) > 0) {
                    $pct   = ($p->price - $p->previous_close) / $p->previous_close * 100;
                    $price = $p->price;
                } else {
                    $pct   = null;
                    $price = null;
                }
                $ranking[] = [
                    'id'    => $s->id,
                    'code'  => $s->code,
                    'label' => $label,
                    'pct'   => $pct,
                    'price' => $price,
                ];
            }
            // 騰落率降順（未取得はnullとして最後尾）
            usort($ranking, function($a, $b) {
                if ($a['pct'] === null && $b['pct'] === null) return 0;
                if ($a['pct'] === null) return 1;
                if ($b['pct'] === null) return -1;
                return $b['pct'] <=> $a['pct'];
            });

            echo '<div style="max-width:520px;">';
            echo '<div style="background:#222;color:#fff;padding:8px 14px;font-size:13px;font-weight:bold;border-radius:6px 6px 0 0;">'
                . '&#x1F3C6; TOPIX-17業種別指数 ランキング（更新日時：' . esc_html(date('Y/m/d H:i')) . '）</div>';
            echo '<div style="border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;overflow:hidden;">';
            foreach ($ranking as $i => $row) {
                $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $row['id']);
                if ($row['pct'] === null) {
                    $row_bg   = '#f5f5f5';
                    $pct_bg   = '#999';
                    $pct_str  = '未取得';
                } else {
                    $up      = $row['pct'] >= 0;
                    $row_bg  = $up ? '#eaf7ee' : '#fdecec';
                    $pct_bg  = $up ? '#2ecc71' : '#e74c3c';
                    $pct_str = ($up ? '&#x25B2;' : '&#x25BC;') . number_format(abs($row['pct']), 2) . '%';
                }
                $price_str = $row['price'] !== null ? number_format($row['price']) : '-';

                echo '<a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:inherit;">';
                echo '<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;background:' . $row_bg . ';'
                    . ($i > 0 ? 'border-top:1px solid #eee;' : '') . '">';
                echo '<span style="background:' . $pct_bg . ';color:#fff;font-size:12px;font-weight:bold;padding:2px 8px;border-radius:4px;min-width:64px;text-align:center;">' . $pct_str . '</span>';
                echo '<span style="flex:1;font-size:13px;color:#333;">' . esc_html($row['label']) . '</span>';
                echo '<span style="font-size:12px;color:#666;">' . $price_str . '</span>';
                echo '</div></a>';
            }
            echo '</div></div>';
        }
    } elseif ($tab === 'calendar') {
        // TOPIX-17業種別 日次騰落率カレンダー（2ヶ月分の枠、データが無い日は空欄）
        $etf_history = wp_stocks_get_etf_price_history($etf_stocks, 65);
        if (empty($etf_stocks) || empty($etf_history)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
        } else {
            $topix17_map = wp_stocks_get_topix17_sector_map();
            $cols = [];
            foreach ($etf_stocks as $s) {
                $name  = $topix17_map[$s->code]['name'] ?? $s->name;
                $cols[$s->code] = ['id' => $s->id, 'label' => str_replace('NF・', '', $name)];
            }
            ksort($cols);

            // 日付ごと・コードごとの騰落率
            $pct_by_date = [];
            $date_set    = [];
            foreach ($cols as $code => $c) {
                foreach ($etf_history[$c['id']] ?? [] as $row) {
                    if ($row['previous_close'] > 0) {
                        $pct_by_date[$row['date']][$code] = ($row['price'] - $row['previous_close']) / $row['previous_close'] * 100;
                        $date_set[$row['date']] = true;
                    }
                }
            }
            krsort($date_set);
            $dates = array_slice(array_keys($date_set), 0, 44); // 2ヶ月分の枠（営業日ベース上限）

            // 連続日数（直近日を起点に同方向が何日続いているか。営業日ベースで判定）
            $dates_asc = array_reverse($dates);
            $streaks   = [];
            foreach ($cols as $code => $c) {
                $streak = 0;
                $prev_sign = null;
                foreach ($dates_asc as $d) {
                    $pct = $pct_by_date[$d][$code] ?? null;
                    if ($pct === null) { $streak = 0; $prev_sign = null; continue; }
                    $sign = $pct >= 0 ? 1 : -1;
                    $streak = ($sign === $prev_sign) ? $streak + 1 : 1;
                    $prev_sign = $sign;
                }
                $streaks[$code] = $streak;
            }

            $cell_style = function($pct) {
                if ($pct === null) return ['bg' => '#f7f7f7', 'fg' => '#ccc'];
                $mag = min(abs($pct), 3) / 3; // 3%で最大濃度に正規化
                if ($pct >= 0) {
                    return ['bg' => 'rgba(46,204,113,' . round(0.15 + 0.55 * $mag, 2) . ')', 'fg' => '#1e7e34'];
                }
                return ['bg' => 'rgba(231,76,60,' . round(0.15 + 0.55 * $mag, 2) . ')', 'fg' => '#b02a1e'];
            };

            echo '<div style="overflow-x:auto;">';
            echo '<table style="border-collapse:collapse;font-size:11px;white-space:nowrap;">';
            echo '<tr>';
            echo '<th style="padding:4px 8px;background:#fafafa;border:1px solid #eee;position:sticky;left:0;z-index:1;">業種</th>';
            foreach ($cols as $c) {
                echo '<th style="padding:4px 4px;background:#fafafa;border:1px solid #eee;writing-mode:vertical-rl;text-orientation:upright;font-weight:normal;height:92px;">' . esc_html($c['label']) . '</th>';
            }
            echo '</tr>';
            echo '<tr>';
            echo '<th style="padding:4px 8px;background:#fafafa;border:1px solid #eee;position:sticky;left:0;z-index:1;">連続</th>';
            foreach ($cols as $code => $c) {
                $st = $streaks[$code] ?? 0;
                echo '<td style="padding:4px;text-align:center;border:1px solid #eee;color:#1e7e34;font-weight:bold;">' . ($st >= 2 ? $st : '') . '</td>';
            }
            echo '</tr>';
            foreach ($dates as $d) {
                echo '<tr>';
                echo '<th style="padding:4px 8px;background:#fafafa;border:1px solid #eee;position:sticky;left:0;z-index:1;font-weight:normal;">' . esc_html(date('m/d', strtotime($d))) . '</th>';
                foreach ($cols as $code => $c) {
                    $pct   = $pct_by_date[$d][$code] ?? null;
                    $style = $cell_style($pct);
                    $disp  = $pct !== null ? number_format($pct, 2) . '%' : '';
                    echo '<td style="padding:4px;text-align:center;border:1px solid #eee;background:' . $style['bg'] . ';color:' . $style['fg'] . ';">' . esc_html($disp) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table></div>';
        }
    } elseif ($tab === 'period') {
        // TOPIX-17業種別 期間別騰落率（1日/5日/1ヶ月/2ヶ月/1年）。データ不足の期間は「-」表示
        $etf_history = wp_stocks_get_etf_price_history($etf_stocks, 400);
        if (empty($etf_stocks)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
        } else {
            $topix17_map = wp_stocks_get_topix17_sector_map();
            $periods = ['1日' => 1, '5日' => 5, '1ヶ月' => 21, '2ヶ月' => 42, '6ヶ月' => 126];

            echo '<div style="overflow-x:auto;">';
            echo '<table style="border-collapse:collapse;width:100%;font-size:13px;">';
            echo '<tr style="background:#222;color:#fff;">';
            echo '<th style="padding:8px 12px;text-align:left;">業種</th>';
            foreach (array_keys($periods) as $label) {
                echo '<th style="padding:8px 12px;text-align:right;">' . esc_html($label) . '</th>';
            }
            echo '</tr>';

            foreach ($etf_stocks as $i => $s) {
                $name   = $topix17_map[$s->code]['name'] ?? $s->name;
                $short  = str_replace('NF・', '', $name);
                $series = $etf_history[$s->id] ?? [];
                $n      = count($series);
                $row_bg = $i % 2 === 0 ? '#fff' : '#f9f9f9';

                echo '<tr style="background:' . $row_bg . ';">';
                echo '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html($short) . '</td>';

                foreach ($periods as $back) {
                    $pct = null;
                    if ($n > 0) {
                        $latest = $series[$n - 1];
                        $idx    = $n - 1 - $back;
                        if ($idx >= 0 && $series[$idx]['price'] > 0) {
                            $pct = ($latest['price'] - $series[$idx]['price']) / $series[$idx]['price'] * 100;
                        }
                    }
                    if ($pct === null) {
                        echo '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;color:#ccc;">-</td>';
                    } else {
                        $color = $pct >= 0 ? '#1e7e34' : '#c0392b';
                        $sign  = $pct >= 0 ? '+' : '';
                        echo '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;color:' . $color . ';font-weight:bold;">' . $sign . number_format($pct, 2) . '%</td>';
                    }
                }
                echo '</tr>';
            }
            echo '</table></div>';
        }
    } else {
        $render_sectors($pf_sectors, '#27ae60');
    }
    echo '</div>';


    if (!$skip_wrap) echo '</div>';
}

// --------------------------------------------------
// 決算カレンダー：月間カレンダー表示
// --------------------------------------------------
function wp_stocks_render_earnings_month_calendar($stocks, $today) {
    $ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') ? $_GET['ym'] : date('Y-m');
    $ym_ts = strtotime($ym . '-01');
    $prev_ym = date('Y-m', strtotime('-1 month', $ym_ts));
    $next_ym = date('Y-m', strtotime('+1 month', $ym_ts));
    $first_dow = intval(date('w', $ym_ts));
    $days_in_month = intval(date('t', $ym_ts));

    $by_date = [];
    if ($stocks) {
        foreach ($stocks as $s) {
            if (empty($s->earnings_date)) continue;
            $by_date[$s->earnings_date][] = $s;
        }
    }

    echo '<div style="background:#fff;border:1px solid #ddd;padding:20px;">';
    echo '<div style="display:flex;align-items:center;gap:15px;margin-bottom:15px;">';
    echo '<a href="' . esc_url(add_query_arg(['view' => 'month', 'ym' => $prev_ym], admin_url('admin.php?page=wp-stocks-calendar'))) . '" class="button">&laquo; 前月</a>';
    echo '<h2 style="margin:0;">' . esc_html(date('Y年n月', $ym_ts)) . '</h2>';
    echo '<a href="' . esc_url(add_query_arg(['view' => 'month', 'ym' => $next_ym], admin_url('admin.php?page=wp-stocks-calendar'))) . '" class="button">翌月 &raquo;</a>';
    echo '</div>';

    echo '<table class="widefat fixed" style="table-layout:fixed;"><thead><tr>';
    foreach (['日','月','火','水','木','金','土'] as $wd) {
        echo '<th style="text-align:center;width:14.28%;">' . $wd . '</th>';
    }
    echo '</tr></thead><tbody><tr>';

    for ($i = 0; $i < $first_dow; $i++) echo '<td style="background:#fafafa;"></td>';

    $col = $first_dow;
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date_str = $ym . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
        $is_today = $date_str === $today;
        echo '<td style="vertical-align:top;height:90px;padding:4px;' . ($is_today ? 'background:#fffbe6;' : '') . '">';
        echo '<div style="font-size:12px;font-weight:bold;color:' . ($is_today ? '#e67e22' : '#555') . ';">' . $d . '</div>';
        if (!empty($by_date[$date_str])) {
            foreach ($by_date[$date_str] as $s) {
                $durl = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
                echo '<div style="font-size:10px;background:#eef4ff;border-radius:3px;padding:1px 3px;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><a href="' . esc_url($durl) . '" style="text-decoration:none;color:#0073aa;" title="' . esc_attr($s->name) . '">' . esc_html($s->code) . '</a></div>';
            }
        }
        echo '</td>';
        $col++;
        if ($col % 7 === 0 && $d < $days_in_month) echo '</tr><tr>';
    }
    $remaining = (7 - ($col % 7)) % 7;
    for ($i = 0; $i < $remaining; $i++) echo '<td style="background:#fafafa;"></td>';
    echo '</tr></tbody></table>';
    echo '</div>';
}

// --------------------------------------------------
// 運用メモ本文中の銘柄コードを自動リンク化
// --------------------------------------------------
// --------------------------------------------------
// 四季報テキスト用サニタイズ
// sanitize_textarea_field()は<...>をHTMLタグとみなして中身ごと除去してしまうため、
// 四季報原文にある山括弧表記（例：<新製品>）が消える問題があった。
// 表示側は esc_html()/esc_textarea() で安全にエスケープ済みのため、
// 保存時はタグ除去をせず、改行コードの正規化とスラッシュ解除のみ行う。
// --------------------------------------------------
function wp_stocks_sanitize_shikiho_text($text) {
    $text = wp_unslash($text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    // 制御文字（タブ・改行以外）だけ除去し、山括弧等はそのまま保持する
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    return trim($text);
}

function wp_stocks_linkify_memo($text) {
    global $wpdb;
    static $code_map = null;
    if ($code_map === null) {
        $code_map = [];
        $rows = $wpdb->get_results("SELECT id, code FROM {$wpdb->prefix}stocks");
        foreach ($rows as $r) $code_map[$r->code] = $r->id;
    }
    $escaped = nl2br(esc_html($text));
    // ★修正：証券コード協議会の正式仕様（1桁目・3桁目は数字固定、2桁目・4桁目は英字も入りうる）に対応
    // 例：336A・593A（4桁目のみ英字）、9A99（2桁目のみ英字）、9A9A（両方英字）
    return preg_replace_callback('/\b[0-9][0-9A-Z][0-9][0-9A-Z]\b|\b[A-Z]{2,5}\b/', function($m) use ($code_map) {
        $code = $m[0];
        if (isset($code_map[$code])) {
            $url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $code_map[$code]);
            return '<a href="' . esc_url($url) . '" style="font-weight:bold;">' . esc_html($code) . '</a>';
        }
        return $code;
    }, $escaped);
}

// --------------------------------------------------
// 設定ページ
// --------------------------------------------------
function wp_stocks_settings_page() {
    global $wpdb;

    // JPX業種別PER 手動取り込み
    if (isset($_POST['wp_stocks_jpx_sync'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $jpx_saved = wp_stocks_jpx_sync_sector_per();
        if ($jpx_saved !== false) {
            echo '<div class="updated"><p>JPX業種別PERを取り込みました（' . intval($jpx_saved) . '件）。</p></div>';
        } else {
            echo '<div class="error"><p>JPX業種別PERの取り込みに失敗しました。ログをご確認ください。</p></div>';
        }
    }

    // 企業情報一括更新
    if (isset($_POST['wp_stocks_bulk_update'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $stocks  = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks ORDER BY id ASC");
        $ok = $ng = 0;
        foreach ($stocks as $s) {
            $sym = ($s->currency ?? 'JPY') === 'USD' ? $s->code : $s->code . '.T';
            $result = wp_stocks_save_company_info($s->id, $sym);
            if ($result) $ok++; else $ng++;
            sleep(10);
        }
        echo '<div class="updated"><p>一括更新完了：成功' . $ok . '件 / 失敗' . $ng . '件</p></div>';
    }

    // 日本株 手動株価取得
    if (isset($_POST['wp_stocks_fetch_jp'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE (currency = 'JPY' OR currency IS NULL OR currency = '')");
        $ok = $ng = 0;
        foreach ($stocks as $s) {
            $result = wp_stocks_save_price($s->id, $s->code . '.T');
            if ($result) $ok++; else $ng++;
            sleep(rand(3, 8));
        }
        wp_stocks_log('info', 'manual_fetch', 'JP', "手動取得完了：成功{$ok}件 / 失敗{$ng}件");
        echo '<div class="updated"><p>日本株 株価取得完了：成功' . $ok . '件 / 失敗' . $ng . '件</p></div>';
    }

    // 米国株 手動株価取得
    if (isset($_POST['wp_stocks_fetch_us'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE currency = 'USD'");
        $ok = $ng = 0;
        foreach ($stocks as $s) {
            $result = wp_stocks_save_price($s->id, $s->code);
            if ($result) $ok++; else $ng++;
            sleep(rand(3, 8));
        }
        wp_stocks_log('info', 'manual_fetch', 'US', "手動取得完了：成功{$ok}件 / 失敗{$ng}件");
        echo '<div class="updated"><p>米国株 株価取得完了：成功' . $ok . '件 / 失敗' . $ng . '件</p></div>';
    }

    // 米国株 Webullバッチ一括取得（手動）
    if (isset($_POST['wp_stocks_webull_batch_us'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $batch_result = wp_stocks_webull_batch_update_us_prices();
        echo '<div class="updated"><p>Webullバッチ取得完了：成功' . $batch_result['ok'] . '件 / 失敗' . $batch_result['ng'] . '件（全' . $batch_result['total'] . '銘柄）</p></div>';
    }

    // 日本株 手動テクニカル計算
    if (isset($_POST['wp_stocks_technical_jp'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE (currency = 'JPY' OR currency IS NULL OR currency = '')");
        $ok = $ng = 0;
        foreach ($stocks as $s) {
            $result = wp_stocks_save_technicals($s->id, $s->code . '.T');
            if ($result) $ok++; else $ng++;
            sleep(rand(1, 2));
        }
        wp_stocks_log('info', 'manual_technical', 'JP', "手動テクニカル計算完了：成功{$ok}件 / 失敗{$ng}件");
        echo '<div class="updated"><p>日本株 テクニカル計算完了：成功' . $ok . '件 / 失敗' . $ng . '件</p></div>';
    }

    // 米国株 手動テクニカル計算
    if (isset($_POST['wp_stocks_technical_us'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE currency = 'USD'");
        $ok = $ng = 0;
        foreach ($stocks as $s) {
            $result = wp_stocks_save_technicals($s->id, $s->code);
            if ($result) $ok++; else $ng++;
            sleep(rand(1, 2));
        }
        wp_stocks_log('info', 'manual_technical', 'US', "手動テクニカル計算完了：成功{$ok}件 / 失敗{$ng}件");
        echo '<div class="updated"><p>米国株 テクニカル計算完了：成功' . $ok . '件 / 失敗' . $ng . '件</p></div>';
    }

    if (isset($_POST['wp_stocks_save_settings'])) {
        check_admin_referer('wp_stocks_settings_nonce');

        // 株価取得時刻
        $fetch_time = sanitize_text_field($_POST['fetch_time'] ?? '15:30');
        if (!preg_match('/^\d{2}:\d{2}$/', $fetch_time)) $fetch_time = '15:30';
        update_option('wp_stocks_fetch_time', $fetch_time);
        wp_clear_scheduled_hook('wp_stocks_cron_event');
        list($h, $m) = explode(':', $fetch_time);
        $now  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next = new DateTime("today {$h}:{$m}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now >= $next) $next->modify('+1 day');
        wp_schedule_event($next->getTimestamp(), 'daily', 'wp_stocks_cron_event');

        // テクニカル計算時刻
        $technical_time = sanitize_text_field($_POST['technical_time'] ?? '16:30');
        if (!preg_match('/^\d{2}:\d{2}$/', $technical_time)) $technical_time = '16:30';
        update_option('wp_stocks_technical_time', $technical_time);
        wp_clear_scheduled_hook('wp_stocks_technical_cron');
        list($th, $tm) = explode(':', $technical_time);
        $now2  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next2 = new DateTime("today {$th}:{$tm}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now2 >= $next2) $next2->modify('+1 day');
        wp_schedule_event($next2->getTimestamp(), 'daily', 'wp_stocks_technical_cron');

        // 米国株テクニカル計算時刻
        $us_technical_time = sanitize_text_field($_POST['us_technical_time'] ?? '07:30');

        // EDINET APIキー保存
        $edinet_api_key = sanitize_text_field($_POST['edinet_api_key'] ?? '');
        update_option('wp_stocks_edinet_api_key', $edinet_api_key);

        // FMP APIキー保存（米国株 財務スコアカード）
        $fmp_api_key = sanitize_text_field($_POST['fmp_api_key'] ?? '');
        update_option('wp_stocks_fmp_api_key', $fmp_api_key);

        // Webull App Key / App Secret保存
        $webull_app_key = sanitize_text_field($_POST['webull_app_key'] ?? '');
        update_option('wp_stocks_webull_app_key', $webull_app_key);
        $webull_app_secret = sanitize_text_field($_POST['webull_app_secret'] ?? '');
        update_option('wp_stocks_webull_app_secret', $webull_app_secret);
        $webull_secret_updated_at = sanitize_text_field($_POST['webull_secret_updated_at'] ?? '');
        update_option('wp_stocks_webull_secret_updated_at', $webull_secret_updated_at);

        if (!preg_match('/^\d{2}:\d{2}$/', $us_technical_time)) $us_technical_time = '07:30';
        update_option('wp_stocks_us_technical_time', $us_technical_time);
        wp_clear_scheduled_hook('wp_stocks_us_technical_cron');
        list($uth, $utm) = explode(':', $us_technical_time);
        $now_ut  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next_ut = new DateTime("today {$uth}:{$utm}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now_ut >= $next_ut) $next_ut->modify('+1 day');
        wp_schedule_event($next_ut->getTimestamp(), 'daily', 'wp_stocks_us_technical_cron');

        // 米国株取得時刻
        $us_fetch_time = sanitize_text_field($_POST['us_fetch_time'] ?? '06:30');
        if (!preg_match('/^\d{2}:\d{2}$/', $us_fetch_time)) $us_fetch_time = '06:30';
        update_option('wp_stocks_us_fetch_time', $us_fetch_time);
        wp_clear_scheduled_hook('wp_stocks_us_cron_event');
        list($uh, $um) = explode(':', $us_fetch_time);
        $now_u  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        $next_u = new DateTime("today {$uh}:{$um}:00", new DateTimeZone('Asia/Tokyo'));
        if ($now_u >= $next_u) $next_u->modify('+1 day');
        wp_schedule_event($next_u->getTimestamp(), 'daily', 'wp_stocks_us_cron_event');

        // 為替レート手動設定
        $usd_jpy_manual = floatval($_POST['usd_jpy_manual'] ?? 0);
        update_option('wp_stocks_usd_jpy_manual', $usd_jpy_manual);
        delete_transient('wp_stocks_usd_jpy'); // キャッシュクリア

        // ★追加：複合シグナル判定の重み・閾値・打診買い/売り条件を保存
        $composite_weight_keys = [
            'golden_cross', 'dead_cross', 'macd_cross_bottom', 'macd_cross_top',
            'rci_reversal_bottom', 'rci_reversal_top', 'stoch_cross_oversold', 'stoch_cross_overbought',
            'dmi_bullish', 'dmi_bearish', 'rsi_oversold', 'rsi_overbought', 'fib_near_bonus',
            'oscillator_reversal_buy', 'oscillator_reversal_sell',
        ];
        $new_composite_weights = [];
        foreach ($composite_weight_keys as $wkey) {
            if (isset($_POST['composite_weight_' . $wkey])) {
                $new_composite_weights[$wkey] = intval($_POST['composite_weight_' . $wkey]);
            }
        }
        if (!empty($new_composite_weights)) update_option('wp_stocks_composite_weights', $new_composite_weights);

        $new_composite_thresholds = [
            'strong_buy'  => intval($_POST['composite_threshold_strong_buy']  ?? 5),
            'buy'         => intval($_POST['composite_threshold_buy']         ?? 2),
            'sell'        => intval($_POST['composite_threshold_sell']        ?? -2),
            'strong_sell' => intval($_POST['composite_threshold_strong_sell'] ?? -5),
        ];
        update_option('wp_stocks_composite_thresholds', $new_composite_thresholds);

        // ★追加：打診買い/売り（バンドウォーク判定）の条件設定
        if (isset($_POST['bandwalk_majority_threshold'])) {
            $bw_threshold = intval($_POST['bandwalk_majority_threshold']);
            $bw_threshold = max(1, min(3, $bw_threshold));
            update_option('wp_stocks_bandwalk_majority_threshold', $bw_threshold);
        }

        echo '<div class="updated"><p>設定を保存しました。</p></div>';
    }

    // 手動クリーンアップ
    if (isset($_POST['wp_stocks_manual_cleanup'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        global $wpdb;
        $cutoff  = date('Y-m-d H:i:s', strtotime('-1 year'));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}stock_prices WHERE datetime < %s", $cutoff));
        echo '<div class="updated"><p>株価データのクリーンアップ完了：' . intval($deleted) . '件削除しました。</p></div>';
    }
    // 孤立データ（削除済み銘柄に紐づく残骸）の一括クリーンアップ
    if (isset($_POST['wp_stocks_cleanup_orphans'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        global $wpdb;
        $valid_ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}stocks");
        $id_list   = !empty($valid_ids) ? implode(',', array_map('intval', $valid_ids)) : '0';

        $deleted_prices     = $wpdb->query("DELETE FROM {$wpdb->prefix}stock_prices WHERE stock_id NOT IN ($id_list)");
        $deleted_technicals = $wpdb->query("DELETE FROM {$wpdb->prefix}stock_technicals WHERE stock_id NOT IN ($id_list)");
        $deleted_news       = $wpdb->query("DELETE FROM {$wpdb->prefix}stock_news WHERE stock_id NOT IN ($id_list)");
        $deleted_ai         = $wpdb->query("DELETE FROM {$wpdb->prefix}stock_ai_history WHERE stock_id NOT IN ($id_list)");

        $total = intval($deleted_prices) + intval($deleted_technicals) + intval($deleted_news) + intval($deleted_ai);
        wp_stocks_log('info', 'cleanup_orphans', 'ALL', "孤立データ削除：株価{$deleted_prices}件・テクニカル{$deleted_technicals}件・ニュース{$deleted_news}件・AI履歴{$deleted_ai}件");
        echo '<div class="updated"><p>孤立データを削除しました（合計' . $total . '件）。</p></div>';
    }
    // 四季報からセクターを一括再抽出
    if (isset($_POST['wp_stocks_bulk_resector_shikiho'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $shikiho_stocks = $wpdb->get_results(
            "SELECT id, code, name, shikiho, sector FROM {$wpdb->prefix}stocks WHERE shikiho IS NOT NULL AND shikiho != ''"
        );
        $resector_updated = 0;
        $resector_skipped = 0;
        $resector_samples = [];
        foreach ($shikiho_stocks as $rs) {
            $new_sector = wp_stocks_extract_sector_from_shikiho($rs->shikiho);
            if (!empty($new_sector)) {
                $wpdb->update($wpdb->prefix . 'stocks', ['sector' => $new_sector], ['id' => $rs->id]);
                if ($new_sector !== $rs->sector) {
                    $resector_samples[] = $rs->code . '(' . ($rs->sector ?: '未設定') . '→' . $new_sector . ')';
                }
                $resector_updated++;
            } else {
                $resector_skipped++;
            }
        }
        wp_stocks_log('info', 'bulk_resector_shikiho', 'ALL',
            "四季報からセクター一括再抽出：更新{$resector_updated}件 / 抽出失敗{$resector_skipped}件"
            . (!empty($resector_samples) ? '　変更例：' . implode(', ', array_slice($resector_samples, 0, 10)) : '')
        );
        echo '<div class="updated"><p>四季報からセクターを一括更新しました：更新' . $resector_updated . '件 / 抽出失敗' . $resector_skipped . '件</p></div>';
    }
    // ニュースRSS設定の保存
    if (isset($_POST['wp_stocks_save_news_rss'])) {
        check_admin_referer('wp_stocks_news_rss_nonce');
        for ($news_rss_i = 1; $news_rss_i <= 10; $news_rss_i++) {
            $news_rss_label = sanitize_text_field($_POST['news_rss_label_' . $news_rss_i] ?? '');
            $news_rss_url   = esc_url_raw($_POST['news_rss_url_' . $news_rss_i] ?? '');
            update_option('wp_stocks_news_rss_label_' . $news_rss_i, $news_rss_label);
            update_option('wp_stocks_news_rss_url_' . $news_rss_i, $news_rss_url);
        }
        echo '<div class="updated"><p>ニュースRSS設定を保存しました。</p></div>';
    }
    // バックフィル完了メッセージ
    if (isset($_GET['message']) && $_GET['message'] === 'backfill_done') {
        echo '<div class="updated"><p>過去株価のバックフィルが完了しました（' . intval($_GET['n'] ?? 0) . '件挿入）。</p></div>';
    }
    

    $fetch_time   = get_option('wp_stocks_fetch_time', '15:30');
    $next_cron    = wp_next_scheduled('wp_stocks_cron_event');
    $next_str     = $next_cron ? (new DateTime('@' . $next_cron))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s') : '未設定';
    $next_tech    = wp_next_scheduled('wp_stocks_technical_cron');
    $next_tech_str= $next_tech ? (new DateTime('@' . $next_tech))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s') : '未設定';
    $next_cleanup = wp_next_scheduled('wp_stocks_price_cleanup');
    $next_cleanup_str = $next_cleanup ? (new DateTime('@' . $next_cleanup))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s') : '未設定';


    // 現在の株価データ件数
    $price_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_prices");
    $oldest_price = $wpdb->get_var("SELECT MIN(datetime) FROM {$wpdb->prefix}stock_prices");
    
    // 孤立データ件数（削除済み銘柄に紐づく残骸）
    $valid_id_list = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}stocks");
    $valid_id_csv  = !empty($valid_id_list) ? implode(',', array_map('intval', $valid_id_list)) : '0';
    $orphan_prices     = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_prices WHERE stock_id NOT IN ($valid_id_csv)"));
    $orphan_technicals = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_technicals WHERE stock_id NOT IN ($valid_id_csv)"));
    $orphan_news       = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_news WHERE stock_id NOT IN ($valid_id_csv)"));
    $orphan_ai         = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_ai_history WHERE stock_id NOT IN ($valid_id_csv)"));
    $orphan_total      = $orphan_prices + $orphan_technicals + $orphan_news + $orphan_ai;


    echo '<div class="wrap"><h1>⚙️ 設定</h1>';

    // ------------------------------------------------------------------
    // タブナビゲーション（スケジュール／手動実行／メンテナンス／外部API連携／複合シグナル判定）
    // ------------------------------------------------------------------
    $wss_tabs = [
        'schedule'    => '⏰ スケジュール設定',
        'manual'      => '▶️ 手動実行',
        'maintenance' => '🧹 データメンテナンス',
        'api'         => '🔑 外部API連携',
        'news'        => '📰 ニュースRSS',
        'signal'      => '🎯 複合シグナル判定',
    ];
    echo '<ul class="wp-stocks-settings-tabs" style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0 0 20px 0;padding:0;list-style:none;flex-wrap:wrap;">';
    $wss_first = true;
    foreach ($wss_tabs as $wss_key => $wss_label) {
        $wss_style = $wss_first
            ? 'background:#0073aa;color:#fff;border:none;'
            : 'background:#f1f1f1;color:#555;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="#" class="wp-stocks-settings-tab" data-panel="' . esc_attr($wss_key) . '" style="display:block;padding:10px 18px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:4px 4px 0 0;' . $wss_style . '">' . esc_html($wss_label) . '</a></li>';
        $wss_first = false;
    }
    echo '</ul>';

    // ------------------------------------------------------------------
    // 【手動実行タブ・前半】EDINETコードリスト（独立フォームのためform外に設置）
    // ------------------------------------------------------------------
    echo '<div class="wp-stocks-settings-panel" data-panel="manual" style="display:none;">';

    // EDINETコードリスト アップロード（証券コード⇔EDINETコード 高速解決用キャッシュ）
    if (isset($_GET['message']) && $_GET['message'] === 'edinet_list_saved') {
        echo '<div class="updated"><p>EDINETコードリストを更新しました（' . intval($_GET['n'] ?? 0) . '件登録）。</p></div>';
    }
    if (isset($_GET['message']) && $_GET['message'] === 'edinet_list_error') {
        echo '<div class="error"><p>EDINETコードリストの読み込みに失敗しました。ZIP/CSVファイルの形式をご確認ください。</p></div>';
    }
    $edinet_codelist          = get_option('wp_stocks_edinet_codelist', []);
    $edinet_codelist_count    = is_array($edinet_codelist) ? count($edinet_codelist) : 0;
    $edinet_codelist_updated  = get_option('wp_stocks_edinet_codelist_updated_at', '');
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;max-width:700px;">';
    echo '<h2 style="margin-top:0;">📇 EDINETコードリスト（証券コード⇔EDINETコード対応表）</h2>';
    echo '<p class="description">EDINETは証券コードから直接検索できないため、通常は書類の提出日を1日ずつ総当たりでEDINETコードを探しますが、提出日がずれていると見つからず失敗します。<br>';
    echo 'EDINET公式サイトの<a href="https://disclosure2.edinet-fsa.go.jp/weee0010.aspx" target="_blank">「EDINETタクソノミ及びコードリストダウンロード」</a>ページから取得できる「EDINETコードリスト」（ZIPまたはCSV）を一度アップロードしておくと、以後はこの対応表から即座に正確なEDINETコードを解決できるようになります。</p>';
    echo '<p style="font-weight:bold;">現在の登録件数：' . number_format($edinet_codelist_count) . '件';
    if ($edinet_codelist_updated) echo '　（最終更新：' . esc_html($edinet_codelist_updated) . '）';
    echo '</p>';
    echo '<p style="background:#e8f8e8;border:1px solid #27ae60;border-radius:4px;padding:10px;font-size:13px;">✅ 2026-07-14時点で、EDINET公式配布ZIPを自動ダウンロードできることを確認済みです。下のボタンでワンクリック更新できます。</p>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="action" value="download_edinet_codelist">';
    wp_nonce_field('wp_stocks_edinet_codelist_download_nonce');
    echo '<button type="submit" class="button button-primary">🔄 今すぐ自動ダウンロードして更新</button>';
    echo '</form>';
    echo '<p class="description" style="margin:12px 0 4px 0;">うまくいかない場合は、以下から手動でアップロードすることもできます。</p>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="upload_edinet_codelist">';
    wp_nonce_field('wp_stocks_edinet_codelist_nonce');
    echo '<input type="file" name="edinet_codelist_file" accept=".zip,.csv" required style="margin-bottom:8px;display:block;">';
    echo '<button type="submit" class="button">アップロードして登録</button>';
    echo '</form>';
    echo '</div>';

    echo '</div>'; // end panel: manual (前半)

    // ------------------------------------------------------------------
    // 【ニュースRSSタブ】マーケット情報ページ用フィード設定（独立フォームのためform外に設置）
    // ------------------------------------------------------------------
    echo '<div class="wp-stocks-settings-panel" data-panel="news" style="display:none;">';

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;max-width:750px;">';
    echo '<h2 style="margin-top:0;">&#x1F4F0; ニュースRSS設定（マーケット情報ページ用）</h2>';
    echo '<p class="description">マーケット情報ページの「ニュース」タブに表示するRSSフィードを最大10件まで登録できます。タブ名・URLのどちらかが空欄のスロットは表示されません。</p>';
    echo '<form method="post">';
    wp_nonce_field('wp_stocks_news_rss_nonce');
    echo '<table class="form-table"><tbody>';
    for ($news_rss_i = 1; $news_rss_i <= 10; $news_rss_i++) {
        $news_rss_label_val = get_option('wp_stocks_news_rss_label_' . $news_rss_i, '');
        $news_rss_url_val   = get_option('wp_stocks_news_rss_url_' . $news_rss_i, '');
        echo '<tr><th style="width:80px;">タブ' . $news_rss_i . '</th><td>';
        echo '<input type="text" name="news_rss_label_' . $news_rss_i . '" value="' . esc_attr($news_rss_label_val) . '" placeholder="タブ名（例：財経新聞）" style="width:180px;margin-right:8px;">';
        echo '<input type="text" name="news_rss_url_' . $news_rss_i . '" value="' . esc_attr($news_rss_url_val) . '" placeholder="RSSのURL" style="width:420px;">';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p><button type="submit" name="wp_stocks_save_news_rss" class="button button-primary">ニュースRSS設定を保存</button></p>';
    echo '</form>';
    echo '</div>';

    echo '</div>'; // end panel: news

    // ------------------------------------------------------------------
    // メイン設定フォーム（スケジュール／手動実行／メンテナンス／外部API連携後半／複合シグナル判定）
    // ------------------------------------------------------------------
    echo '<form method="post">';
    wp_nonce_field('wp_stocks_settings_nonce');

    // ==== パネル：スケジュール設定 ====
    echo '<div class="wp-stocks-settings-panel" data-panel="schedule" style="display:block;">';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th>株価取得時刻</th><td>';
    echo '<input type="time" name="fetch_time" value="' . esc_attr($fetch_time) . '" style="width:120px;">';
    echo '<p class="description">毎営業日この時刻に株価を自動取得します（日本時間）。<br>次回実行予定：' . esc_html($next_str) . '</p>';
    echo '</td></tr>';

    $technical_time = get_option('wp_stocks_technical_time', '16:30');
    echo '<tr><th>テクニカル指標計算時刻</th><td>';
    echo '<input type="time" name="technical_time" value="' . esc_attr($technical_time) . '" style="width:120px;">';
    echo '<p class="description">毎日この時刻に日足データを取得してMA5/MA25/MACD/RSI/トレンドを計算します（株価取得の30分後推奨）。<br>次回実行予定：' . esc_html($next_tech_str) . '</p>';
    echo '</td></tr>';

    // 米国株取得時刻
    $us_fetch_time    = get_option('wp_stocks_us_fetch_time', '06:30');
    $next_us_cron     = wp_next_scheduled('wp_stocks_us_cron_event');
    $next_us_str      = $next_us_cron ? (new DateTime('@' . $next_us_cron))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s') : '未設定';
    echo '<tr><th>米国株取得時刻</th><td>';
    echo '<input type="time" name="us_fetch_time" value="' . esc_attr($us_fetch_time) . '" style="width:120px;">';
    echo '<p class="description">米国市場終了後（日本時間6:30頃推奨）に株価を取得します。<br>次回実行予定：' . esc_html($next_us_str) . '</p>';
    echo '</td></tr>';

    // 米国株テクニカル計算時刻
    $us_technical_time = get_option('wp_stocks_us_technical_time', '07:30');
    $next_us_tech      = wp_next_scheduled('wp_stocks_us_technical_cron');
    $next_us_tech_str  = $next_us_tech ? (new DateTime('@' . $next_us_tech))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s') : '未設定';
    echo '<tr><th>米国株テクニカル計算時刻</th><td>';
    echo '<input type="time" name="us_technical_time" value="' . esc_attr($us_technical_time) . '" style="width:120px;">';
    echo '<p class="description">米国株取得の1時間後推奨（例：7:30）。<br>次回実行予定：' . esc_html($next_us_tech_str) . '</p>';
    echo '</td></tr>';

    // 株価取得sleepを設定から取得
    $fetch_sleep_min = intval(get_option('wp_stocks_fetch_sleep_min', 3));
    $fetch_sleep_max = intval(get_option('wp_stocks_fetch_sleep_max', 8));
    echo '<tr><th>株価取得Sleep</th><td>';
    echo '最小：<input type="number" name="fetch_sleep_min" value="' . esc_attr($fetch_sleep_min) . '" min="1" max="30" style="width:60px;"> 秒　';
    echo '最大：<input type="number" name="fetch_sleep_max" value="' . esc_attr($fetch_sleep_max) . '" min="1" max="60" style="width:60px;"> 秒';
    echo '<p class="description">銘柄間のランダムsleep時間（ブロック対策）。</p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    echo '</div>'; // end panel: schedule

    // ==== パネル：手動実行 ====
    echo '<div class="wp-stocks-settings-panel" data-panel="manual" style="display:none;">';
    echo '<table class="form-table"><tbody>';

    // 手動株価取得・テクニカル計算
    $jp_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stocks WHERE (currency = 'JPY' OR currency IS NULL OR currency = '')"));
    $us_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stocks WHERE currency = 'USD'"));
    echo '<tr><th>手動株価取得</th><td>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
    echo '<button type="submit" name="wp_stocks_fetch_jp" class="button"'
        . ' style="background:#e74c3c;color:#fff;border-color:#c0392b;"'
        . ' onclick="return confirm(\'日本株(' . $jp_count . '銘柄)の株価を今すぐ取得しますか？\\n完了まで数分かかります。\')">'
        . '&#x1F1EF;&#x1F1F5; 日本株を今すぐ取得（' . $jp_count . '銘柄）</button>';
    echo '<button type="submit" name="wp_stocks_fetch_us" class="button"'
        . ' style="background:#3498db;color:#fff;border-color:#2980b9;"'
        . ' onclick="return confirm(\'米国株(' . $us_count . '銘柄)の株価を今すぐ取得しますか？\\n完了まで数分かかります。\')">'
        . '&#x1F1FA;&#x1F1F8; 米国株を今すぐ取得（Yahoo・' . $us_count . '銘柄）</button>';
    echo '<button type="submit" name="wp_stocks_webull_batch_us" class="button"'
        . ' style="background:#16a085;color:#fff;border-color:#0e6655;"'
        . ' onclick="return confirm(\'米国株(' . $us_count . '銘柄)をWebullバッチで今すぐ取得しますか？\')">'
        . '&#x26A1; 米国株を今すぐ取得（Webullバッチ・' . $us_count . '銘柄）</button>';
    echo '</div>';
    echo '<p class="description" style="margin-top:8px;">Yahoo版は5銘柄ごとに2秒、それ以外は0.8秒のsleepを挟みます。Webullバッチ版は複数銘柄をまとめてcurl_multiで並列取得するため高速です（本番Cronは現在Webullバッチに統一済み）。</p>';
    echo '</td></tr>';

    echo '<tr><th>手動テクニカル計算</th><td>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
    echo '<button type="submit" name="wp_stocks_technical_jp" class="button"'
        . ' style="background:#e67e22;color:#fff;border-color:#d35400;"'
        . ' onclick="return confirm(\'日本株(' . $jp_count . '銘柄)のテクニカル指標を計算しますか？\\n完了まで数分かかります。\')">'
        . '&#x1F1EF;&#x1F1F5; 日本株テクニカル計算（' . $jp_count . '銘柄）</button>';
    echo '<button type="submit" name="wp_stocks_technical_us" class="button"'
        . ' style="background:#9b59b6;color:#fff;border-color:#8e44ad;"'
        . ' onclick="return confirm(\'米国株(' . $us_count . '銘柄)のテクニカル指標を計算しますか？\\n完了まで数分かかります。\')">'
        . '&#x1F1FA;&#x1F1F8; 米国株テクニカル計算（' . $us_count . '銘柄）</button>';
    echo '</div>';
    echo '<p class="description" style="margin-top:8px;">6ヶ月分の日足データを取得してMA5/MA25/MA75/MACD/RSI/トレンドを計算します。銘柄間に1〜2秒のsleepを挟みます。</p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    echo '</div>'; // end panel: manual

    // ==== パネル：データメンテナンス（前半・フォーム内） ====
    echo '<div class="wp-stocks-settings-panel" data-panel="maintenance" style="display:none;">';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th>株価データクリーンアップ</th><td>';
    echo '<p class="description">毎日03:00に1年超の株価データを自動削除します。<br>';
    echo '次回実行予定：' . esc_html($next_cleanup_str) . '<br>';
    echo '現在の株価データ：' . number_format(intval($price_count)) . '件';
    if ($oldest_price) echo '（最古：' . esc_html($oldest_price) . '）';
    echo '</p>';
    echo '<button type="submit" name="wp_stocks_manual_cleanup" class="button" style="margin-top:5px;" onclick="return confirm(\'1年超の株価データを今すぐ削除しますか？\');">今すぐクリーンアップ実行</button>';
    echo '</td></tr>';
    
    echo '<tr><th>孤立データクリーンアップ</th><td>';
    echo '<p class="description">削除済み銘柄に紐づく残骸データ（株価・テクニカル・適時開示・AI分析履歴）を削除します。<br>';
    echo '現在の孤立データ：<strong' . ($orphan_total > 0 ? ' style="color:#e74c3c;"' : '') . '>' . number_format($orphan_total) . '件</strong>';
    echo '（株価' . $orphan_prices . '・テクニカル' . $orphan_technicals . '・適時開示' . $orphan_news . '・AI履歴' . $orphan_ai . '）';
    echo '</p>';
    echo '<button type="submit" name="wp_stocks_cleanup_orphans" class="button"'
        . ($orphan_total > 0 ? ' style="background:#e74c3c;color:#fff;border-color:#c0392b;"' : '')
        . ' onclick="return confirm(\'削除済み銘柄に紐づく残骸データを削除しますか？\\nこの操作は取り消せません。\');">'
        . '孤立データを今すぐ削除</button>';
    echo '</td></tr>';
    
    echo '<tr><th>過去株価のバックフィル</th><td>';
    echo '<p class="description">全銘柄の過去30日分の株価を日足データから補完します。<br>';
    echo '再登録した銘柄でグラフが乱れている場合に実行してください（既存のレコードは上書きしません）。</p>';
    $backfill_url = wp_nonce_url(admin_url('admin-post.php?action=backfill_all_prices'), 'wp_stocks_backfill_nonce');
    echo '<a href="' . esc_url($backfill_url) . '" class="button button-primary" onclick="return confirm(\'全銘柄の過去30日分の株価を補完しますか？\\n銘柄数が多い場合は数分かかります。\');">過去30日分を今すぐ補完</a>';
    echo '</td></tr>';

    // 四季報からセクターを一括再抽出
    $shikiho_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stocks WHERE shikiho IS NOT NULL AND shikiho != ''"));
    echo '<tr><th>セクター一括再抽出（四季報）</th><td>';
    echo '<p class="description">四季報情報が登録済みの全銘柄（' . number_format($shikiho_count) . '件）について、';
    echo '四季報1行目の「［ ］」内の文字列からセクターを再抽出し、上書き更新します。<br>';
    echo '英語セクターのまま残っている既存銘柄の一括修正に使用してください。</p>';
    echo '<button type="submit" name="wp_stocks_bulk_resector_shikiho" class="button button-primary" onclick="return confirm(\'四季報登録済みの' . $shikiho_count . '件について、セクターを再抽出して上書きしますか？\');">四季報からセクターを一括再抽出</button>';
    echo '</td></tr>';

    $next_company     = wp_next_scheduled('wp_stocks_company_cron');
    $next_company_str = $next_company ? (new DateTime('@' . $next_company))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y/m/d H:i:s') : '未設定';

    echo '<tr><th>企業情報週次更新</th><td>';
    echo '<p class="description">毎週日曜22:00にPER・PBR等の企業情報を自動更新します。<br>次回実行予定：' . esc_html($next_company_str) . '</p>';
    echo '<button type="submit" name="wp_stocks_bulk_update" class="button" style="margin-top:5px;" onclick="return confirm(\'全銘柄の企業情報を今すぐ再取得しますか？\n銘柄数が多い場合は時間がかかります。\');">今すぐ全銘柄を一括更新</button>';
    echo '</td></tr>';

    echo '</tbody></table>';
    echo '</div>'; // end panel: maintenance（前半）

    // ==== パネル：外部API連携（後半・フォーム内） ====
    echo '<div class="wp-stocks-settings-panel" data-panel="api" style="display:none;">';
    echo '<table class="form-table"><tbody>';

    // EDINET APIキー設定
    $edinet_api_key = get_option('wp_stocks_edinet_api_key', '');
    echo '<tr><th>EDINET APIキー</th><td>';
    echo '<input type="text" name="edinet_api_key" value="' . esc_attr($edinet_api_key) . '" style="width:350px;" placeholder="例：115c8e8db7654e1bbbda5de21c2f5a8a">';
    echo '<p class="description">EDINETから財務データを取得するためのAPIキーです。<a href="https://disclosure2.edinet-fsa.go.jp/" target="_blank">EDINETサイト</a>で取得できます。</p>';
    echo '</td></tr>';

    // Financial Modeling Prep (FMP) APIキー設定（米国株 財務スコアカード：PER/PBR/ROE等）
    $fmp_api_key = get_option('wp_stocks_fmp_api_key', '');
    echo '<tr><th>FMP APIキー</th><td>';
    echo '<input type="text" name="fmp_api_key" value="' . esc_attr($fmp_api_key) . '" style="width:350px;" placeholder="Financial Modeling PrepのAPIキー">';
    echo '<p class="description">米国株の財務スコアカード（PER・PBR・ROE・自己資本比率・配当利回り・PEG・利益率・次回決算日）取得に使用します（無料Basicプラン：250コール/日）。<a href="https://site.financialmodelingprep.com/developer/docs" target="_blank">FMPサイト</a>で取得できます。</p>';
    echo '</td></tr>';
    // Webull App Key / App Secret設定
    $webull_app_key = get_option('wp_stocks_webull_app_key', '');
    $webull_app_secret = get_option('wp_stocks_webull_app_secret', '');
    echo '<tr><th>Webull App Key</th><td>';
    echo '<input type="text" name="webull_app_key" value="' . esc_attr($webull_app_key) . '" style="width:350px;" placeholder="Webull Developer PortalのAPP ID">';
    echo '<p class="description">Webull OpenAPIの認証に使うApp Key（APP ID）です。<a href="https://developer.webull.com/" target="_blank">Webull Developer Portal</a>で取得できます。</p>';
    echo '</td></tr>';
    echo '<tr><th>Webull App Secret</th><td>';
    echo '<input type="text" name="webull_app_secret" value="' . esc_attr($webull_app_secret) . '" style="width:350px;" placeholder="Webull Developer PortalのApp Secret">';
    echo '<p class="description">Webull OpenAPIの署名生成に使うApp Secretです。オペレーション画面から確認・再発行できます。</p>';
    echo '</td></tr>';
    echo '<tr><th>Webull App Secret 最終更新日</th><td>';
    $webull_secret_updated_at = get_option('wp_stocks_webull_secret_updated_at', '');
    echo '<input type="date" name="webull_secret_updated_at" value="' . esc_attr($webull_secret_updated_at) . '" style="width:180px;">';
    echo '<p class="description">Key生成・リセットを行った日を手動で記録してください（Webull側に有効期限の自動表示がないための代替管理です）。</p>';
    echo '</td></tr>';
    $webull_last_auth_error_at     = get_option('wp_stocks_webull_last_auth_error_at', '');
    $webull_last_auth_error_detail = get_option('wp_stocks_webull_last_auth_error_detail', '');
    if (!empty($webull_last_auth_error_at)) {
        echo '<tr><th>&#x26A0;&#xFE0F; Webull認証エラー</th><td>';
        echo '<p style="color:#b32d2e;">直近の認証エラー検知日時：' . esc_html($webull_last_auth_error_at) . '<br>詳細：' . esc_html($webull_last_auth_error_detail) . '</p>';
        echo '<p class="description">App Secretの期限切れが原因の可能性があります。Webull管理画面でKeyをリセットし、上記App Secretとこのページの「最終更新日」を更新してください。<br>Webullの疎通確認・診断ツールは「APIデバッグ」ページに移動しました。</p>';
        echo '</td></tr>';
    }

    // SEC EDGAR 動作確認リンク（yfinance代替の検証用）
    $edgar_test_aapl_url = wp_nonce_url(admin_url('admin-post.php?action=wp_stocks_edgar_test_fetch&symbol=AAPL'), 'wp_stocks_edgar_test');
    $edgar_test_ko_url   = wp_nonce_url(admin_url('admin-post.php?action=wp_stocks_edgar_test_fetch&symbol=KO'), 'wp_stocks_edgar_test');
    echo '<tr><th>SEC EDGAR 動作確認</th><td>';
    echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">';
    echo '<input type="number" id="wp_stocks_edgar_test_stock_id" style="width:100px;" placeholder="stock_id（任意）">';
    echo '<span class="description" style="margin:0;">入力するとDB保存も実行し、実際に保存された内容を読み戻して表示します（未入力ならDB保存なし）</span>';
    echo '</div>';
    echo '<a href="' . esc_url($edgar_test_aapl_url) . '" class="button" id="wp_stocks_edgar_test_aapl" target="_blank">AAPLで取得テスト</a> ';
    echo '<a href="' . esc_url($edgar_test_ko_url) . '" class="button" id="wp_stocks_edgar_test_ko" target="_blank">KOで取得テスト</a>';
    echo '<p class="description">SEC EDGARから四半期revenue/net_incomeを取得し、プレーンテキストで表示します（yfinance代替の検証用）。</p>';
    echo '</td></tr>';

    // FMP 動作確認リンク（財務スコアカード：PER/PBR/ROE等のyfinance代替検証用）
    $fmp_test_aapl_url = wp_nonce_url(admin_url('admin-post.php?action=wp_stocks_fmp_test_fetch&symbol=AAPL'), 'wp_stocks_fmp_test');
    $fmp_test_ko_url   = wp_nonce_url(admin_url('admin-post.php?action=wp_stocks_fmp_test_fetch&symbol=KO'), 'wp_stocks_fmp_test');
    echo '<tr><th>FMP 動作確認</th><td>';
    echo '<a href="' . esc_url($fmp_test_aapl_url) . '" class="button" target="_blank">AAPLで取得テスト</a> ';
    echo '<a href="' . esc_url($fmp_test_ko_url) . '" class="button" target="_blank">KOで取得テスト</a>';
    echo '<p class="description">FMPから財務スコアカード指標（PER/PBR/ROE/自己資本比率/配当利回り/PEG/利益率/次回決算日）を取得し、プレーンテキストで表示します。</p>';
    echo '</td></tr>';
    echo '<script>
    (function(){
        function wpStocksEdgarBindLink(linkId, baseUrl) {
            var link = document.getElementById(linkId);
            if (!link) return;
            link.addEventListener("click", function() {
                var sid = document.getElementById("wp_stocks_edgar_test_stock_id").value;
                this.href = sid ? (baseUrl + "&stock_id=" + encodeURIComponent(sid)) : baseUrl;
            });
        }
        wpStocksEdgarBindLink("wp_stocks_edgar_test_aapl", ' . wp_json_encode(html_entity_decode($edgar_test_aapl_url)) . ');
        wpStocksEdgarBindLink("wp_stocks_edgar_test_ko", ' . wp_json_encode(html_entity_decode($edgar_test_ko_url)) . ');
    })();
    </script>';

    // 為替レート
    $usd_jpy_manual = get_option('wp_stocks_usd_jpy_manual', 0);
    $usd_jpy_current = wp_stocks_get_usd_jpy();
    echo '<tr><th>USD/JPY為替レート</th><td>';
    echo '<input type="number" name="usd_jpy_manual" value="' . esc_attr($usd_jpy_manual > 0 ? $usd_jpy_manual : '') . '" step="0.01" style="width:120px;" placeholder="自動取得"> 円';
    echo '<p class="description">空欄の場合はExchangeRate-API（open.er-api.com）から自動取得します。現在のレート：<strong>' . number_format($usd_jpy_current, 2) . '円</strong><br>手動設定する場合は数値を入力（例：150.50）。クリアするには空欄で保存。<br><span style="font-size:11px;color:#888;">為替レート提供: <a href="https://www.exchangerate-api.com" target="_blank" rel="noopener">ExchangeRate-API</a></span></p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    echo '</div>'; // end panel: api（後半）

    // ==== パネル：複合シグナル判定 ====
    echo '<div class="wp-stocks-settings-panel" data-panel="signal" style="display:none;">';

    // ★変更：複合シグナル判定まわりの設定をタブ化（重み／打診買い・売り条件／閾値）
    // オシレーター・ローソク足個別の閾値は対象外（要望により調整不要のため据え置き）
    $composite_weights    = wp_stocks_get_composite_weights();
    $composite_thresholds = wp_stocks_get_composite_thresholds();
    $bandwalk_threshold   = intval(get_option('wp_stocks_bandwalk_majority_threshold', 2));
    $weight_labels = [
        'golden_cross'             => '打診買い（ボリンジャー-2σタッチ反転）',
        'dead_cross'               => '打診売り（ボリンジャー+2σタッチ反転）',
        'macd_cross_bottom'        => 'MACD底値圏でのGC',
        'macd_cross_top'           => 'MACD天井圏でのDC',
        'rci_reversal_bottom'      => 'RCI -80以下からの反転上昇',
        'rci_reversal_top'         => 'RCI +80以上からの反転下落',
        'stoch_cross_oversold'     => 'ストキャス売られ過ぎ圏でのGC',
        'stoch_cross_overbought'   => 'ストキャス買われ過ぎ圏でのDC',
        'dmi_bullish'              => 'DMI +DI優勢（ADX&ge;25）',
        'dmi_bearish'              => 'DMI -DI優勢（ADX&ge;25）',
        'rsi_oversold'             => 'RSI売られ過ぎ（&le;30）',
        'rsi_overbought'           => 'RSI買われ過ぎ（&ge;70）',
        'fib_near_bonus'           => 'フィボナッチ主要水準接近（補助点・符号は既存スコアに追従）',
        'oscillator_reversal_buy'  => 'オシレーター反転確認（RSI売られ過ぎ＋陽線反転）',
        'oscillator_reversal_sell' => 'オシレーター反転確認（RSI買われ過ぎ＋陰線反転）',
    ];

    echo '<div style="margin-top:0;">';
    echo '<h2 style="margin:0 0 10px 0;font-size:16px;">&#x1F3AF; 複合シグナル判定の設定</h2>';
    echo '<ul style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0;padding:0;list-style:none;">';
    $csettings_tabs = ['weights' => '重み設定', 'tasin' => '打診買い/売り条件', 'thresholds' => '閾値設定'];
    $cfirst = true;
    foreach ($csettings_tabs as $ckey => $clabel) {
        $tstyle = $cfirst
            ? 'background:#0073aa;color:#fff;border:none;'
            : 'background:#f1f1f1;color:#555;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="#" class="wp-stocks-csettings-tab" data-target="csettings-' . esc_attr($ckey) . '" style="display:block;padding:8px 16px;font-size:13px;font-weight:bold;text-decoration:none;border-radius:4px 4px 0 0;' . $tstyle . '">' . esc_html($clabel) . '</a></li>';
        $cfirst = false;
    }
    echo '</ul>';
    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:16px;">';

    // --- 重み設定タブ ---
    echo '<table class="form-table wp-stocks-csettings-panel" id="csettings-weights" style="display:table;margin:0;"><tbody>';
    foreach ($weight_labels as $wkey => $wlabel) {
        $wval = $composite_weights[$wkey] ?? 0;
        echo '<tr><th>' . $wlabel . '</th><td><input type="number" name="composite_weight_' . esc_attr($wkey) . '" value="' . esc_attr($wval) . '" step="1" style="width:100px;"> 点</td></tr>';
    }
    echo '</tbody></table>';

    // --- 打診買い/売り条件タブ ---
    echo '<table class="form-table wp-stocks-csettings-panel" id="csettings-tasin" style="display:none;margin:0;"><tbody>';
    echo '<tr><th>バンドウォーク判定の必要条件数</th><td>'
        . '<input type="number" name="bandwalk_majority_threshold" value="' . esc_attr($bandwalk_threshold) . '" min="1" max="3" step="1" style="width:80px;"> / 3条件中'
        . '<p class="description">3条件（①終値の-2σ張り付き ②バンド幅の急拡大 ③ADX&ge;25かつDMIの方向一致）のうち、何個以上該当したら下落バンドウォークと判定するかです。既定値は2。バンドウォーク判定時は打診買いが自動的に無効化されます。</p>'
        . '</td></tr>';
    echo '<tr><td colspan="2"><p class="description">打診買い・打診売りの判定は「前日にボリンジャー±2σへタッチ／突破し、当日の終値がバンド内に戻ってきたか」で行っています（±2σ固定）。</p></td></tr>';
    echo '</tbody></table>';

    // --- 閾値設定タブ ---
    echo '<table class="form-table wp-stocks-csettings-panel" id="csettings-thresholds" style="display:none;margin:0;"><tbody>';
    echo '<tr><th>強い買いの下限スコア</th><td><input type="number" name="composite_threshold_strong_buy" value="' . esc_attr($composite_thresholds['strong_buy']) . '" step="1" style="width:100px;"> 点以上</td></tr>';
    echo '<tr><th>買い優勢の下限スコア</th><td><input type="number" name="composite_threshold_buy" value="' . esc_attr($composite_thresholds['buy']) . '" step="1" style="width:100px;"> 点以上</td></tr>';
    echo '<tr><th>売り優勢の上限スコア</th><td><input type="number" name="composite_threshold_sell" value="' . esc_attr($composite_thresholds['sell']) . '" step="1" style="width:100px;"> 点以下</td></tr>';
    echo '<tr><th>強い売りの上限スコア</th><td><input type="number" name="composite_threshold_strong_sell" value="' . esc_attr($composite_thresholds['strong_sell']) . '" step="1" style="width:100px;"> 点以下</td></tr>';
    echo '</tbody></table>';

    echo '</div></div>';

    echo '<script>
    document.querySelectorAll(".wp-stocks-csettings-tab").forEach(function(tab) {
        tab.addEventListener("click", function(e) {
            e.preventDefault();
            document.querySelectorAll(".wp-stocks-csettings-tab").forEach(function(t) {
                t.style.background = "#f1f1f1"; t.style.color = "#555"; t.style.border = "1px solid #ddd"; t.style.borderBottom = "none";
            });
            this.style.background = "#0073aa"; this.style.color = "#fff"; this.style.border = "none";
            document.querySelectorAll(".wp-stocks-csettings-panel").forEach(function(p) { p.style.display = "none"; });
            var target = document.getElementById(this.dataset.target);
            if (target) target.style.display = "table";
        });
    });
    </script>';

    echo '</div>'; // end panel: signal

    echo '<p style="margin-top:20px;"><button type="submit" name="wp_stocks_save_settings" class="button button-primary">設定を保存</button></p>';
    echo '</form>';

    // ==== パネル：データメンテナンス（後半・JPX業種別PERは独立フォームのためform外に設置） ====
    echo '<div class="wp-stocks-settings-panel" data-panel="maintenance" style="display:none;">';
    $jpx_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_jpx_sector_per"));
    $jpx_latest = $wpdb->get_var("SELECT MAX(year_month) FROM {$wpdb->prefix}stock_jpx_sector_per");
    echo '<div style="padding:16px;background:#fff;border:1px solid #ddd;border-radius:8px;max-width:700px;">';
    echo '<h2 style="margin-top:0;">&#x1F4CA; JPX業種別PER（適正株価の算出に使用）</h2>';
    echo '<p class="description">東京証券取引所が公表する「規模別・業種別PER・PBR」を取り込み、適正株価計算時のセクター平均PERとして優先的に使用します（市場区分・業種が一致する日本株のみ対象）。現在の登録件数：' . number_format($jpx_count) . '件';
    if ($jpx_latest) echo '（最新：' . esc_html($jpx_latest) . '分）';
    echo '</p>';
    echo '<form method="post">';
    wp_nonce_field('wp_stocks_settings_nonce');
    echo '<button type="submit" name="wp_stocks_jpx_sync" class="button button-primary">&#x1F504; JPX業種別PERを今すぐ取り込む</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>'; // end panel: maintenance（後半）

    // ------------------------------------------------------------------
    // タブ切り替えJS（トップレベルの5タブ）
    // ------------------------------------------------------------------
    echo '<script>
    (function() {
        var wssTabs   = document.querySelectorAll(".wp-stocks-settings-tab");
        var wssPanels = document.querySelectorAll(".wp-stocks-settings-panel");
        wssTabs.forEach(function(tab) {
            tab.addEventListener("click", function(e) {
                e.preventDefault();
                wssTabs.forEach(function(t) {
                    t.style.background = "#f1f1f1"; t.style.color = "#555"; t.style.border = "1px solid #ddd"; t.style.borderBottom = "none";
                });
                this.style.background = "#0073aa"; this.style.color = "#fff"; this.style.border = "none";
                var target = this.dataset.panel;
                wssPanels.forEach(function(p) {
                    p.style.display = (p.dataset.panel === target) ? "block" : "none";
                });
            });
        });
    })();
    </script>';

    echo '</div>';
}

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

// --------------------------------------------------
// 複合シグナル判定ロジック
// 既存の stock_technicals テーブル（MA5/MA25/MA75・MACD・RSI・
// ゴールデンクロス/デッドクロス）だけを使い、追加のAPI取得は不要。
// --------------------------------------------------
function wp_stocks_classify_signal($tech, $latest_price = null) {
    $result = [
        'tentative_buy'  => false, // 打診買い（トレンド転換の初動：ゴールデンクロス）
        'tentative_sell' => false, // 打診売り（トレンド転換の初動：デッドクロス）
        'chase_buy'      => false, // 追撃買い（上昇トレンド継続・モメンタム）
        'chase_sell'     => false, // 追撃売り（下降トレンド継続・モメンタム）
        'oversold'       => false, // 売られ過ぎ（逆張り買い候補）
        'overbought'     => false, // 買われ過ぎ（逆張り売り候補）
        'dmi_buy'        => false, // DMI買い（+DIが-DIを上回りADX>=25）
        'dmi_sell'       => false, // DMI売り（-DIが+DIを上回りADX>=25）
        'rci_oversold'   => false, // RCI売られ過ぎ（RCI<=-80）
        'rci_overbought' => false, // RCI買われ過ぎ（RCI>=80）
        'bb_oversold'    => false, // ボリンジャーバンド-2σ突破（逆張り買い）
        'bb_overbought'  => false, // ボリンジャーバンド+2σ突破（逆張り売り）
        'stoch_oversold'      => false, // ストキャス売られ過ぎ（%K<=20）
        'stoch_overbought'    => false, // ストキャス買われ過ぎ（%K>=80）
        'sar_bullish_reversal'=> false, // パラボリックSAR 下→上への転換
        'sar_bearish_reversal'=> false, // パラボリックSAR 上→下への転換
        'fib_near'            => false, // 主要フィボナッチ水準に接近
        'oscillator_reversal_buy'  => false, // RSI売られ過ぎ＋厳格な陽線反転
        'oscillator_reversal_sell' => false, // RSI買われ過ぎ＋厳格な陰線反転
    ];
    if (!$tech) return $result;

    $ma5      = $tech->ma5           ?? null;
    $ma25     = $tech->ma25          ?? null;
    $macd     = $tech->macd          ?? null;
    $macd_sig = $tech->macd_signal   ?? null;
    $rsi      = $tech->rsi           ?? null;
    $cross    = $tech->cross_signal  ?? null;
    $trend    = $tech->trend         ?? 'flat';
    $strength = intval($tech->trend_strength ?? 1);

    // ★変更：①打診買い・打診売りは、単純なGC/DCではなく
    // ボリンジャーσタッチ＋終値反転（下落/上昇トレンド終盤・底値/天井圏向け）を使う
    if (intval($tech->tasin_buy  ?? 0) === 1) $result['tentative_buy']  = true;
    if (intval($tech->tasin_sell ?? 0) === 1) $result['tentative_sell'] = true;

    // ★追加：オシレーター反転確認（RSI売られ過ぎ/買われ過ぎ ＋ 厳格な陽線/陰線反転）
    if (intval($tech->oscillator_reversal_buy  ?? 0) === 1) $result['oscillator_reversal_buy']  = true;
    if (intval($tech->oscillator_reversal_sell ?? 0) === 1) $result['oscillator_reversal_sell'] = true;

    // ② 追撃買い・追撃売り：MA位置関係＋MACD＋トレンド強度が揃った継続モメンタム
    if ($ma5 !== null && $ma25 !== null && $macd !== null && $macd_sig !== null) {
        if ($trend === 'up' && $strength >= 2 && $ma5 > $ma25 && $macd > $macd_sig) {
            $result['chase_buy'] = true;
        }
        if ($trend === 'down' && $strength >= 2 && $ma5 < $ma25 && $macd < $macd_sig) {
            $result['chase_sell'] = true;
        }
    }

    // ③ 売られ過ぎ・買われ過ぎ：RSI基準の逆張りシグナル
    if ($rsi !== null) {
        if ($rsi <= 30) $result['oversold']   = true;
        if ($rsi >= 70) $result['overbought'] = true;
    }

    // ④ DMI：+DI/-DIの優劣（ADX>=25でトレンドが明確な時のみ判定）
    $plus_di  = $tech->plus_di  ?? null;
    $minus_di = $tech->minus_di ?? null;
    $adx      = $tech->adx      ?? null;
    if ($plus_di !== null && $minus_di !== null && $adx !== null && $adx >= 25) {
        if ($plus_di > $minus_di) $result['dmi_buy']  = true;
        if ($minus_di > $plus_di) $result['dmi_sell'] = true;
    }

    // ⑤ RCI：±80基準の逆張りシグナル
    $rci = $tech->rci ?? null;
    if ($rci !== null) {
        if ($rci <= -80) $result['rci_oversold']   = true;
        if ($rci >= 80)  $result['rci_overbought'] = true;
    }

    // ⑥ ボリンジャーバンド：±2σ突破による逆張りシグナル
    $bb_upper = $tech->bb_upper ?? null;
    $bb_lower = $tech->bb_lower ?? null;
    if ($latest_price !== null) {
        if ($bb_lower !== null && $latest_price <= $bb_lower) $result['bb_oversold']   = true;
        if ($bb_upper !== null && $latest_price >= $bb_upper) $result['bb_overbought'] = true;
    }
    // ⑦ ストキャスティクス：Slow %K 20/80基準の逆張りシグナル
    $stoch_k = $tech->stoch_k ?? null;
    if ($stoch_k !== null) {
        if ($stoch_k <= 20) $result['stoch_oversold']   = true;
        if ($stoch_k >= 80) $result['stoch_overbought'] = true;
    }
    // ⑧ パラボリックSAR：直近バーでのトレンド転換
    if (intval($tech->sar_reversal ?? 0) === 1) {
        if (($tech->sar_trend ?? '') === 'up')   $result['sar_bullish_reversal'] = true;
        if (($tech->sar_trend ?? '') === 'down') $result['sar_bearish_reversal'] = true;
    }
    // ⑨ フィボナッチ：主要水準への接近
    if (intval($tech->fib_near ?? 0) === 1) $result['fib_near'] = true;

    return $result;
}

// --------------------------------------------------
// 個別シグナルの方向マップ（買い方向/売り方向）。
// 「テクニカル」タブで複合スコアとの整合性（裏付け/騙しの可能性）を判定するために使用。
// --------------------------------------------------
function wp_stocks_signal_direction_map() {
    return [
        'tentative_buy'        => 'buy',  'tentative_sell'       => 'sell',
        'chase_buy'            => 'buy',  'chase_sell'           => 'sell',
        'oversold'             => 'buy',  'overbought'           => 'sell',
        'dmi_buy'              => 'buy',  'dmi_sell'             => 'sell',
        'rci_oversold'         => 'buy',  'rci_overbought'       => 'sell',
        'bb_oversold'          => 'buy',  'bb_overbought'        => 'sell',
        'stoch_oversold'       => 'buy',  'stoch_overbought'     => 'sell',
        'sar_bullish_reversal' => 'buy',  'sar_bearish_reversal' => 'sell',
        // ローソク足パターン（「ローソク足」タブでも複合判定列を出せるように）
        'bullish_engulfing'    => 'buy',  'bearish_engulfing'    => 'sell',
        'bullish'              => 'buy',  'bearish'              => 'sell',
        'aka_sanpei'           => 'buy',  'strong_aka_sanpei'    => 'buy',
        'narabi_aka'           => 'buy',
        // ★追加：オシレーター反転確認
        'oscillator_reversal_buy' => 'buy', 'oscillator_reversal_sell' => 'sell',
    ];
}

// --------------------------------------------------
// 個別シグナルと複合スコアの整合性バッジ（✅裏付けあり／⚠️騙しの可能性）
// --------------------------------------------------
function wp_stocks_signal_backing_badge_html($bucket_key, $composite_score) {
    $dir = wp_stocks_signal_direction_map()[$bucket_key] ?? null;
    if ($dir === null) return '';
    $is_backed = ($dir === 'buy') ? ($composite_score > 0) : ($composite_score < 0);
    return $is_backed
        ? '<span style="display:block;margin-top:3px;font-size:10px;color:#27ae60;font-weight:bold;">&#x2705; 裏付けあり</span>'
        : '<span style="display:block;margin-top:3px;font-size:10px;color:#e67e22;font-weight:bold;">&#x26A0;&#xFE0F; 騙しの可能性</span>';
}

// --------------------------------------------------
// ★追加：シグナル一覧の各バケットをアコーディオン（折りたたみ）表示にするためのヘルパー
// 銘柄数が多いバケットが常時展開されて縦に長くなる問題を解消する
// --------------------------------------------------
function wp_stocks_render_accordion_assets() {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    ?>
    <script>
    (function() {
        function initAccordions() {
            document.querySelectorAll('.wp-stocks-accordion-header').forEach(function(header) {
                if (header.dataset.accInit) return;
                header.dataset.accInit = '1';
                header.addEventListener('click', function() {
                    var body = document.getElementById(this.dataset.target);
                    if (!body) return;
                    var isOpen = body.style.display !== 'none';
                    body.style.display = isOpen ? 'none' : 'block';
                    var arrow = this.querySelector('.wp-stocks-accordion-arrow');
                    if (arrow) arrow.innerHTML = isOpen ? '&#9660;' : '&#9650;';
                });
            });
            if (window.location.hash) {
                try {
                    var target = document.querySelector(window.location.hash);
                    if (target && target.classList.contains('wp-stocks-accordion-item')) {
                        var body   = target.querySelector('.wp-stocks-accordion-body');
                        var header = target.querySelector('.wp-stocks-accordion-header');
                        if (body) body.style.display = 'block';
                        if (header) {
                            var arrow = header.querySelector('.wp-stocks-accordion-arrow');
                            if (arrow) arrow.innerHTML = '&#9650;';
                        }
                        setTimeout(function() { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                    }
                } catch (e) {}
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAccordions);
        } else {
            initAccordions();
        }
    })();
    </script>
    <?php
}

function wp_stocks_accordion_open($id, $heading_html) {
    echo '<div class="wp-stocks-accordion-item" id="' . esc_attr($id) . '" style="margin-bottom:10px;border:1px solid #eee;border-radius:6px;overflow:hidden;">';
    echo '<div class="wp-stocks-accordion-header" data-target="' . esc_attr($id) . '-body" style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fafafa;">';
    echo '<div style="flex:1;">' . $heading_html . '</div>';
    echo '<span class="wp-stocks-accordion-arrow" style="font-size:12px;color:#888;margin-left:10px;">&#9660;</span>';
    echo '</div>';
    echo '<div class="wp-stocks-accordion-body" id="' . esc_attr($id) . '-body" style="display:none;padding:12px 14px;">';
}

function wp_stocks_accordion_close() {
    echo '</div></div>';
}

// --------------------------------------------------
// ★追加：打診買い/打診売り専用の信頼性バッジ（4段階）
// バンドウォーク中の危険域・逆方向の過熱警戒・裏付けあり・要観察、を判定する
// --------------------------------------------------
function wp_stocks_reliability_badge_html($tech, $direction) {
    if (!$tech) return '';
    $score       = intval($tech->composite_score ?? 0);
    $rsi         = $tech->rsi ?? null;
    $macd_hist   = $tech->macd_hist ?? null;
    $bandwalk_dir = $tech->bandwalk_direction ?? null;

    // ★変更：下落バンドウォーク中は買いシグナルを、上昇バンドウォーク中は売りシグナルを警告扱いにする
    $is_bandwalk_danger   = ($direction === 'buy' && $bandwalk_dir === 'down')
                          || ($direction === 'sell' && $bandwalk_dir === 'up');
    $is_high_price_danger = ($direction === 'buy'  && $rsi !== null && $rsi >= 70)
                          || ($direction === 'sell' && $rsi !== null && $rsi <= 30);
    $has_backing = ($direction === 'buy')
        ? ($score > 0 || ($macd_hist !== null && $macd_hist > 0))
        : ($score < 0 || ($macd_hist !== null && $macd_hist < 0));

    if ($is_bandwalk_danger) {
        return '<span style="background:#e74c3c;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;" title="下落トレンドが強すぎます。即死ゾーンにつき静観推奨。">&#x1F6A8; 騙しの可能性（警告）</span>';
    } elseif ($is_high_price_danger) {
        return '<span style="background:#e67e22;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;" title="逆方向の過熱シグナルです。飛び乗りに注意。">&#x26A0;&#xFE0F; 騙しの可能性（過熱警戒）</span>';
    } elseif ($has_backing) {
        return '<span style="background:#27ae60;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;" title="複合スコアまたはMACDの裏付けがあります。">&#x1F7E2; 裏付けあり（本物候補）</span>';
    }
    return '<span style="background:#95a5a6;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;">&#x26AA; 要観察（シグナルのみ）</span>';
}

// --------------------------------------------------
// 複合シグナルページ本体
// --------------------------------------------------
function wp_stocks_composite_signal_page() {
    global $wpdb;

    $tab      = in_array($_GET['tab'] ?? '', ['jp', 'us']) ? $_GET['tab'] : 'jp';
    $filter   = in_array($_GET['filter'] ?? '', ['watchlist', 'portfolio'], true) ? $_GET['filter'] : 'all';
    // ★変更：3タブ構成（シグナル／テクニカル／ローソク足）に変更し、既定タブを「シグナル」に
    $mtab     = in_array($_GET['mtab'] ?? '', ['signal', 'technical', 'candle'], true) ? $_GET['mtab'] : 'signal';
    $base_url = admin_url('admin.php?page=wp-stocks-signal');

    $signal_labels = [
        'tentative_buy'        => '打診買い',
        'tentative_sell'       => '打診売り',
        'chase_buy'            => '追撃買い',
        'chase_sell'           => '追撃売り',
        'oversold'             => '売られ過ぎ',
        'overbought'           => '買われ過ぎ',
        'dmi_buy'              => 'DMI買い',
        'dmi_sell'             => 'DMI売り',
        'rci_oversold'         => 'RCI売られ過ぎ',
        'rci_overbought'       => 'RCI買われ過ぎ',
        'bb_oversold'          => 'BB下限突破',
        'bb_overbought'        => 'BB上限突破',
        // ★追加：ラベル未定義だったため「🔥複合:」表示に「・・」が入るバグを修正
        'stoch_oversold'       => 'ストキャス売られ過ぎ',
        'stoch_overbought'     => 'ストキャス買われ過ぎ',
        'sar_bullish_reversal' => 'SAR強気転換',
        'sar_bearish_reversal' => 'SAR弱気転換',
        'fib_near'             => 'フィボナッチ接近',
    ];

    // 全銘柄＋最新テクニカル＋最新株価を一括取得
    $stocks = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio') AND is_sector_etf = 0 ORDER BY code ASC"
    );
    $tech_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_technicals");
    $tech_map  = [];
    foreach ($tech_rows as $t) $tech_map[$t->stock_id] = $t;

    $price_rows = $wpdb->get_results(
        "SELECT sp.stock_id, sp.price, sp.previous_close
         FROM {$wpdb->prefix}stock_prices sp
         INNER JOIN (
             SELECT stock_id, MAX(datetime) as md
             FROM {$wpdb->prefix}stock_prices GROUP BY stock_id
         ) latest ON sp.stock_id = latest.stock_id AND sp.datetime = latest.md"
    );
    $price_map = [];
    foreach ($price_rows as $p) $price_map[$p->stock_id] = $p;

    // 日本株／米国株に分けつつ、各カテゴリへ仕分け
    $buckets = [
        'tentative_buy'  => [], 'tentative_sell' => [],
        'chase_buy'      => [], 'chase_sell'     => [],
        'oversold'       => [], 'overbought'     => [],
        'dmi_buy'        => [], 'dmi_sell'       => [],
        'rci_oversold'   => [], 'rci_overbought' => [],
        'bb_oversold'    => [], 'bb_overbought'  => [],
    ];
    // ★追加：複合判定（5段階）のバケット。「シグナル」タブで使用
    $composite_buckets = [
        'strong_buy' => [], 'buy' => [], 'hold' => [], 'sell' => [], 'strong_sell' => [],
    ];
    $jp_count   = 0;
    $us_count   = 0;
    $signal_map = []; // stock_id => 発生中のシグナルキー一覧（複合シグナル判定用）

    foreach ($stocks as $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        if ($is_usd) $us_count++; else $jp_count++;
        if (($is_usd && $tab !== 'us') || (!$is_usd && $tab !== 'jp')) continue;

        // 絞り込みフィルター
        if ($filter === 'watchlist' && intval($s->is_watchlist ?? 0) !== 1) continue;
        if ($filter === 'portfolio' && ($s->status ?? '') !== 'portfolio') continue;

        $tech   = $tech_map[$s->id] ?? null;
        $latest = $price_map[$s->id] ?? null;
        $flags  = wp_stocks_classify_signal($tech, $latest ? $latest->price : null);
        $active_keys = [];
        foreach ($flags as $key => $on) {
            if ($on) {
                $buckets[$key][] = $s;
                $active_keys[] = $key;
            }
        }
        if (!empty($active_keys)) $signal_map[$s->id] = $active_keys;

        // ★追加：複合判定バケットへの仕分け（stock_technicals.composite_labelを使用）
        $composite_label = $tech->composite_label ?? 'hold';
        if (!isset($composite_buckets[$composite_label])) $composite_label = 'hold';
        $composite_buckets[$composite_label][] = $s;
    }

    // ★変更：銘柄コード順ではなく、複合判定スコア順に並び替える。
    // 買い系バケットはスコアの高い順（降順）、売り系バケットはマイナスが大きい順（昇順）にする。
    $wp_stocks_sort_by_composite = function(array &$list, $ascending) use ($tech_map) {
        usort($list, function($a, $b) use ($tech_map, $ascending) {
            $score_a = intval($tech_map[$a->id]->composite_score ?? 0);
            $score_b = intval($tech_map[$b->id]->composite_score ?? 0);
            return $ascending ? ($score_a <=> $score_b) : ($score_b <=> $score_a);
        });
    };
    // $buckets のうち「売り方向」のバケットは昇順（マイナスが大きい順）にする
    $wp_stocks_sell_direction_buckets = ['tentative_sell', 'chase_sell', 'overbought', 'dmi_sell', 'rci_overbought', 'bb_overbought'];
    foreach ($buckets as $wp_stocks_bkey => &$wp_stocks_bucket_ref) {
        $wp_stocks_sort_by_composite($wp_stocks_bucket_ref, in_array($wp_stocks_bkey, $wp_stocks_sell_direction_buckets, true));
    }
    unset($wp_stocks_bucket_ref);
    // $composite_buckets のうち sell/strong_sell は昇順（マイナスが大きい順）にする
    $wp_stocks_sell_composite_labels = ['sell', 'strong_sell'];
    foreach ($composite_buckets as $wp_stocks_ckey => &$wp_stocks_bucket_ref) {
        $wp_stocks_sort_by_composite($wp_stocks_bucket_ref, in_array($wp_stocks_ckey, $wp_stocks_sell_composite_labels, true));
    }
    unset($wp_stocks_bucket_ref);

    // 1銘柄行描画クロージャ（既存ダッシュボードと似た体裁に統一）
    // ★変更：$bucket_key を追加。個別シグナルバケットの「方向」との整合性バッジ（✅/⚠️）を出すために使用
    $render_stock_row = function($s, $bucket_key = null) use ($tech_map, $price_map, $signal_map, $signal_labels) {
        $is_usd     = ($s->currency ?? 'JPY') === 'USD';
        $latest     = $price_map[$s->id] ?? null;
        $tech       = $tech_map[$s->id]  ?? null;
        $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);

        $price_str = '未取得';
        if ($latest) {
            $price_str = $is_usd ? '$' . number_format($latest->price, 2) : number_format($latest->price) . '円';
        }
        $change_html = ($latest && ($latest->previous_close ?? 0) > 0)
            ? wp_stocks_change_html($latest->price, $latest->previous_close, $is_usd) : '';

        $composite_detail_arr = [];
        if ($tech && !empty($tech->composite_detail)) {
            $decoded_detail = json_decode($tech->composite_detail, true);
            if (is_array($decoded_detail)) $composite_detail_arr = $decoded_detail;
        }
        $is_multi    = count($composite_detail_arr) >= 2;
        $row_style   = $is_multi ? 'background:#fff8e1;' : '';
        $judged_at   = ($tech && !empty($tech->calculated_at)) ? date('n/j', strtotime($tech->calculated_at)) : '-';

        // ★追加：複合判定バッジ＋（バケット指定時のみ）裏付け/騙しバッジ
        $composite_score = intval($tech->composite_score ?? 0);
        if ($tech) {
            $composite_html = wp_stocks_composite_badge_html($composite_score);
            if ($bucket_key === 'tentative_buy') {
                $composite_html .= wp_stocks_reliability_badge_html($tech, 'buy');
            } elseif ($bucket_key === 'tentative_sell') {
                $composite_html .= wp_stocks_reliability_badge_html($tech, 'sell');
            } elseif ($bucket_key !== null) {
                $composite_html .= wp_stocks_signal_backing_badge_html($bucket_key, $composite_score);
            }
        } else {
            $composite_html = '<span style="color:#aaa;">-</span>';
        }

        echo '<tr style="' . $row_style . '">';
        echo '<td>' . esc_html($s->code) . '</td>';
        echo '<td><a href="' . esc_url($detail_url) . '">' . esc_html($s->name) . '</a>' . esc_html(wp_stocks_market_segment_suffix($s->market ?? ''));
        if ($is_multi) {
            echo '<br><span style="font-size:10px;color:#e67e22;font-weight:bold;">&#x1F525; 根拠: ' . esc_html(implode('・', $composite_detail_arr)) . '</span>';
        }
        echo '</td>';
        echo '<td>' . esc_html(!empty($s->sector) ? wp_stocks_sector_ja($s->sector) : '-') . '</td>';
        echo '<td>' . $price_str . '</td>';
        echo '<td>' . $change_html . '</td>';
        echo '<td>' . ($tech ? wp_stocks_trend_icon_html($tech) : '<span style="color:#aaa;">-</span>') . '</td>';
        echo '<td>' . $composite_html . '</td>';
        echo '<td style="font-size:11px;color:#888;">' . esc_html($judged_at) . '</td>';
        echo '</tr>';
    };

    // ★変更：$bucket_key を受け取り $render_stock_row に橋渡しし、ヘッダーに「複合判定」列を追加
    $render_bucket_table = function($stocks_list, $bucket_key = null) use ($render_stock_row) {
        if (empty($stocks_list)) {
            echo '<p style="color:#888;padding:10px 0;">該当銘柄はありません。</p>';
            return;
        }
        echo '<table class="widefat fixed striped" style="font-size:12px;margin-bottom:20px;">';
        echo '<thead><tr><th>コード</th><th>銘柄名</th><th>セクター</th><th>現在値</th><th>前日比</th><th>トレンド</th><th>複合判定</th><th>判定日時</th></tr></thead><tbody>';
        foreach ($stocks_list as $s) $render_stock_row($s, $bucket_key);
        echo '</tbody></table>';
    };

    echo '<div class="wrap"><h1>&#x1F3AF; 今日のシグナル一覧</h1>';
    wp_stocks_render_accordion_assets();
    echo '<p style="color:#666;font-size:13px;">登録済み銘柄（ウォッチ＋ポートフォリオ）のテクニカル指標から、'
        . 'ゴールデンクロス/デッドクロス（打診）・トレンド継続モメンタム（追撃）・RSI逆張り（売られ過ぎ/買われ過ぎ）の3種類のシグナルを自動判定します。'
        . '毎日のテクニカル計算Cron実行後に反映されます。&#x1F525;マークが付いた銘柄は複数のシグナルが同時に発生しています。クリックで開閉できます。</p>';

    // ★変更：大分類タブを「シグナル／テクニカル／ローソク足」の3つに
    echo '<ul style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0 0 20px 0;padding:0;list-style:none;flex-wrap:wrap;">';
    foreach (['signal' => '&#x1F3AF; シグナル', 'technical' => '&#x1F4C8; テクニカル', 'candle' => '&#x1F56F;&#xFE0F; ローソク足'] as $mkey => $mlabel) {
        $mis_active = $mtab === $mkey;
        $murl = $base_url . '&mtab=' . $mkey;
        $mstyle = $mis_active
            ? 'display:block;padding:10px 18px;background:#0073aa;color:#fff;text-decoration:none;font-size:13px;font-weight:bold;border-radius:4px 4px 0 0;'
            : 'display:block;padding:10px 18px;background:#f1f1f1;color:#555;text-decoration:none;font-size:13px;border-radius:4px 4px 0 0;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="' . esc_url($murl) . '" style="' . $mstyle . '">' . $mlabel . '</a></li>';
    }
    echo '</ul>';

    // ★追加：「シグナル」タブ本体。複合スコア5段階（強い買い/買い優勢/HOLD/売り優勢/強い売り）で銘柄を分類する
    if ($mtab === 'signal') {

    echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
    foreach (['jp' => '&#x1F1EF;&#x1F1F5; 日本株（' . $jp_count . '）', 'us' => '&#x1F1FA;&#x1F1F8; 米国株（' . $us_count . '）'] as $key => $label) {
        $active = $tab === $key;
        $url    = $base_url . '&tab=' . $key . '&filter=' . $filter . '&mtab=signal';
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;">';

    // 打診買い・打診売りの実データをシグナルタブの上部に直接表示
    // （テクニカルタブと同じ $buckets / $render_bucket_table を再利用）
    echo '<h3 style="margin-top:0;border-left:4px solid #27ae60;padding-left:10px;">&#x1F7E2; 打診買い（ゴールデンクロス）（' . count($buckets['tentative_buy']) . '銘柄）</h3>';
    $render_bucket_table($buckets['tentative_buy'], 'tentative_buy');

    echo '<h3 style="border-left:4px solid #e74c3c;padding-left:10px;">&#x1F534; 打診売り（デッドクロス）（' . count($buckets['tentative_sell']) . '銘柄）</h3>';
    $render_bucket_table($buckets['tentative_sell'], 'tentative_sell');

    echo '<div style="margin-bottom:15px;display:flex;gap:8px;">';
    foreach (['all' => '全て', 'watchlist' => '&#x2605; 注目株のみ', 'portfolio' => '&#x1F4C1; ポートフォリオのみ'] as $fkey => $flabel) {
        $is_factive = $filter === $fkey;
        $furl = $base_url . '&tab=' . $tab . '&filter=' . $fkey . '&mtab=signal';
        echo '<a href="' . esc_url($furl) . '" class="button" style="'
            . ($is_factive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '')
            . 'font-weight:bold;">' . $flabel . '</a>';
    }
    echo '</div>';

    $composite_sections = [
        'strong_buy'  => ['&#x1F7E2; 強い買い',  '#27ae60'],
        'buy'         => ['&#x1F535; 買い優勢',  '#3498db'],
        'sell'        => ['&#x1F7E0; 売り優勢',  '#e67e22'],
        'strong_sell' => ['&#x1F534; 強い売り',  '#e74c3c'],
    ];
    foreach ($composite_sections as $ckey => [$clabel, $ccolor]) {
        $ccount = count($composite_buckets[$ckey]);
        echo '<h2 style="border-left:4px solid ' . $ccolor . ';padding-left:10px;">' . $clabel . '（' . $ccount . '銘柄）</h2>';
        // シグナルタブでは「バケットの方向との整合性」バッジは対象外（bucket_key=null）
        $render_bucket_table($composite_buckets[$ckey], null);
    }

    echo '</div>';

    } elseif ($mtab === 'technical') {

    // 日本株/米国株タブ
    echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
    foreach (['jp' => '&#x1F1EF;&#x1F1F5; 日本株（' . $jp_count . '）', 'us' => '&#x1F1FA;&#x1F1F8; 米国株（' . $us_count . '）'] as $key => $label) {
        $active = $tab === $key;
        // ★修正：mtab=technicalを引き継がないと日本株/米国株切替時に既定タブ（シグナル）へ戻ってしまうため追加
        $url    = $base_url . '&tab=' . $key . '&filter=' . $filter . '&mtab=technical';
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;">';

    // 絞り込みフィルター
    echo '<div style="margin-bottom:15px;display:flex;gap:8px;">';
    foreach (['all' => '全て', 'watchlist' => '&#x2605; 注目株のみ', 'portfolio' => '&#x1F4C1; ポートフォリオのみ'] as $fkey => $flabel) {
        $is_factive = $filter === $fkey;
        $furl = $base_url . '&tab=' . $tab . '&filter=' . $fkey . '&mtab=technical';
        echo '<a href="' . esc_url($furl) . '" class="button" style="'
            . ($is_factive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '')
            . 'font-weight:bold;">' . $flabel . '</a>';
    }
    echo '</div>';

    // ★追加：RSI由来とRCI由来の「売られ過ぎ／買われ過ぎ」を1つのバケットに統合する
    // （銘柄数が多いバケットが乱立する問題を緩和。両方同時発生時は既存の🔥複合タグで分かる）
    $wp_stocks_merge_unique_buckets = function(array $keys) use ($buckets) {
        $seen = [];
        $merged = [];
        foreach ($keys as $k) {
            foreach ($buckets[$k] as $s) {
                if (!isset($seen[$s->id])) {
                    $seen[$s->id] = true;
                    $merged[] = $s;
                }
            }
        }
        return $merged;
    };
    $buckets['oscillator_oversold']   = $wp_stocks_merge_unique_buckets(['oversold', 'rci_oversold']);
    $buckets['oscillator_overbought'] = $wp_stocks_merge_unique_buckets(['overbought', 'rci_overbought']);
    $wp_stocks_sort_by_composite($buckets['oscillator_oversold'], false);
    $wp_stocks_sort_by_composite($buckets['oscillator_overbought'], true);

    // シグナル一覧サマリーテーブル（件数＋アンカーリンク）
    $summary_rows = [
        ['tentative_buy',        '&#x1F7E2; 打診買い（ゴールデンクロス）'],
        ['tentative_sell',       '&#x1F534; 打診売り（デッドクロス）'],
        ['chase_buy',            '&#x1F7E2; 追撃買い（上昇モメンタム継続）'],
        ['chase_sell',           '&#x1F534; 追撃売り（下降モメンタム継続）'],
        ['oscillator_oversold',   '&#x1F535; 売られ過ぎ（RSI&le;30 または RCI&le;-80）'],
        ['oscillator_overbought', '&#x1F7E0; 買われ過ぎ（RSI&ge;70 または RCI&ge;80）'],
        ['dmi_buy',              '&#x1F7E2; DMI買い（+DI優勢・ADX&ge;25）'],
        ['dmi_sell',             '&#x1F534; DMI売り（-DI優勢・ADX&ge;25）'],
        ['bb_oversold',          '&#x1F535; BB下限突破（-2&sigma;・逆張り買い）'],
        ['bb_overbought',        '&#x1F7E0; BB上限突破（+2&sigma;・逆張り売り）'],
    ];
    echo '<table class="widefat fixed striped" style="font-size:13px;max-width:480px;margin-bottom:25px;">';
    echo '<thead><tr><th>シグナル</th><th style="text-align:right;">該当銘柄数</th></tr></thead><tbody>';
    foreach ($summary_rows as $row) {
        list($key, $label) = $row;
        $cnt = count($buckets[$key]);
        echo '<tr><td><a href="#sig_' . esc_attr($key) . '">' . $label . '</a></td><td style="text-align:right;">' . intval($cnt) . ' 銘柄</td></tr>';
    }
    echo '</tbody></table>';

    // ★変更：各バケットをアコーディオン化（クリックで開閉）。見出しにも件数を表示する。
    wp_stocks_accordion_open('sig_tentative_buy', '<span style="border-left:4px solid #27ae60;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E2; 打診買い（ゴールデンクロス）（' . count($buckets['tentative_buy']) . '銘柄）</span>');
    $render_bucket_table($buckets['tentative_buy'], 'tentative_buy');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_tentative_sell', '<span style="border-left:4px solid #e74c3c;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F534; 打診売り（デッドクロス）（' . count($buckets['tentative_sell']) . '銘柄）</span>');
    $render_bucket_table($buckets['tentative_sell'], 'tentative_sell');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_chase_buy', '<span style="border-left:4px solid #27ae60;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E2; 追撃買い（上昇モメンタム継続）（' . count($buckets['chase_buy']) . '銘柄）</span>');
    $render_bucket_table($buckets['chase_buy'], 'chase_buy');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_chase_sell', '<span style="border-left:4px solid #e74c3c;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F534; 追撃売り（下降モメンタム継続）（' . count($buckets['chase_sell']) . '銘柄）</span>');
    $render_bucket_table($buckets['chase_sell'], 'chase_sell');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_oscillator_oversold', '<span style="border-left:4px solid #3498db;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F535; 売られ過ぎ（RSI&le;30 または RCI&le;-80）（' . count($buckets['oscillator_oversold']) . '銘柄）</span>');
    $render_bucket_table($buckets['oscillator_oversold'], 'oversold');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_oscillator_overbought', '<span style="border-left:4px solid #f39c12;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E0; 買われ過ぎ（RSI&ge;70 または RCI&ge;80）（' . count($buckets['oscillator_overbought']) . '銘柄）</span>');
    $render_bucket_table($buckets['oscillator_overbought'], 'overbought');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_dmi_buy', '<span style="border-left:4px solid #27ae60;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E2; DMI買い（+DI優勢・ADX&ge;25）（' . count($buckets['dmi_buy']) . '銘柄）</span>');
    $render_bucket_table($buckets['dmi_buy'], 'dmi_buy');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_dmi_sell', '<span style="border-left:4px solid #e74c3c;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F534; DMI売り（-DI優勢・ADX&ge;25）（' . count($buckets['dmi_sell']) . '銘柄）</span>');
    $render_bucket_table($buckets['dmi_sell'], 'dmi_sell');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_bb_oversold', '<span style="border-left:4px solid #3498db;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F535; BB下限突破（-2&sigma;・逆張り買い）（' . count($buckets['bb_oversold']) . '銘柄）</span>');
    $render_bucket_table($buckets['bb_oversold'], 'bb_oversold');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_bb_overbought', '<span style="border-left:4px solid #f39c12;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E0; BB上限突破（+2&sigma;・逆張り売り）（' . count($buckets['bb_overbought']) . '銘柄）</span>');
    $render_bucket_table($buckets['bb_overbought'], 'bb_overbought');
    wp_stocks_accordion_close();

    echo '</div>';

    } elseif ($mtab === 'candle') {

        $candle_labels = [
            'bullish_engulfing' => '&#x1F7E2; 陽線包み足（強気転換）',
            'bearish_engulfing' => '&#x1F534; 陰線包み足（弱気転換）',
            'doji'              => '&#x26AA; 十字線（迷い）',
            'bullish'           => '&#x1F7E2; 陽線',
            'bearish'           => '&#x1F534; 陰線',
        ];
        $candle_buckets = [
            'bullish_engulfing' => [], 'bearish_engulfing' => [],
            'doji' => [], 'bullish' => [], 'bearish' => [],
        ];
        foreach ($stocks as $s) {
            $is_usd = ($s->currency ?? 'JPY') === 'USD';
            if (($is_usd && $tab !== 'us') || (!$is_usd && $tab !== 'jp')) continue;
            if ($filter === 'watchlist' && intval($s->is_watchlist ?? 0) !== 1) continue;
            if ($filter === 'portfolio' && ($s->status ?? '') !== 'portfolio') continue;

            $tech    = $tech_map[$s->id] ?? null;
            $pattern = $tech->candle_pattern ?? null;
            if ($pattern && isset($candle_buckets[$pattern])) {
                $candle_buckets[$pattern][] = $s;
            }
        }

        // 日本株/米国株タブ
        echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
        foreach (['jp' => '&#x1F1EF;&#x1F1F5; 日本株（' . $jp_count . '）', 'us' => '&#x1F1FA;&#x1F1F8; 米国株（' . $us_count . '）'] as $key => $label) {
            $active = $tab === $key;
            $url    = $base_url . '&tab=' . $key . '&filter=' . $filter . '&mtab=candle';
            echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
                . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
                . '">' . $label . '</a>';
        }
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;">';

        // 絞り込みフィルター
        echo '<div style="margin-bottom:15px;display:flex;gap:8px;">';
        foreach (['all' => '全て', 'watchlist' => '&#x2605; 注目株のみ', 'portfolio' => '&#x1F4C1; ポートフォリオのみ'] as $fkey => $flabel) {
            $is_factive = $filter === $fkey;
            $furl = $base_url . '&tab=' . $tab . '&filter=' . $fkey . '&mtab=candle';
            echo '<a href="' . esc_url($furl) . '" class="button" style="'
                . ($is_factive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '')
                . 'font-weight:bold;">' . $flabel . '</a>';
        }
        echo '</div>';

        echo '<p style="color:#888;font-size:12px;">直近の日足終値をもとに判定した単純なローソク足パターンです。まだテクニカル計算が実行されていない銘柄は表示されません。</p>';

        foreach ($candle_labels as $ckey => $clabel) {
            wp_stocks_accordion_open('sig_candle_' . $ckey, '<span style="border-left:4px solid #0073aa;padding-left:10px;font-size:15px;font-weight:bold;">' . $clabel . '（' . count($candle_buckets[$ckey]) . '銘柄）</span>');
            $render_bucket_table($candle_buckets[$ckey], $ckey);
            wp_stocks_accordion_close();
        }

        echo '</div>';

    }

    echo '</div>';
}

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

    foreach ($stocks as $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        $sym = $is_usd ? $s->code : $s->code . '.T';
        $result = wp_stocks_save_company_info($s->id, $sym);
        if ($result) $ok++; else $ng++;

        // 米国株はついでにSEC EDGARの四半期財務データも週次で取得する
        if ($is_usd) {
            $edgar_result = wp_stocks_fetch_quarterly_financials_edgar($s->id, $s->code);
            if ($edgar_result) $edgar_ok++; else $edgar_ng++;
        }

        // 負荷対策：3件ごとに5秒sleep、それ以外は2秒
        if (($ok + $ng) % 3 === 0) sleep(5);
        else sleep(2);
    }

    wp_stocks_log('info', 'company_cron', 'ALL', "企業情報週次更新完了：成功{$ok}件 / 失敗{$ng}件（うち米国株EDGAR財務：成功{$edgar_ok}件 / 失敗{$edgar_ng}件）");
});

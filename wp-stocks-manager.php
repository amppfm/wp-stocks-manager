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
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/compare.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/sector.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/debug.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/composite-signal.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/market-info.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/portfolio.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/company.php';

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

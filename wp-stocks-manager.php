<?php
/*
Plugin Name: WP Stocks Manager
Description: 銘柄管理・株価取得・企業情報・ニュース・株価履歴・祝日スキップ・ダッシュボード・ポートフォリオ・財務スコアカード・決算カレンダー・テクニカル指標・銘柄比較・AI分析履歴・銘柄メモ・インポート/エクスポート・ログ
Version: 5.0
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

define('WP_STOCKS_VERSION', '5.1');
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
require_once WP_STOCKS_PLUGIN_DIR . 'admin/pages/settings.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/handlers/menu.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/handlers/admin-post-handlers.php';
require_once WP_STOCKS_PLUGIN_DIR . 'admin/handlers/cron-handlers.php';
require_once WP_STOCKS_PLUGIN_DIR . 'includes/data-sources/jquants.php';

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
    } else {
        // 日本株：J-Quantsの予想EPS等でPEG・EPS関連指標を算出（yfinance予想PEG不備の代替）
        global $wpdb;
        $jquants_code = str_replace('.T', '', $symbol);

        // 手動入力値を確認（決算直後などJ-Quantsの遅延を待てない場合、自動取得より優先）
        $manual = $wpdb->get_row($wpdb->prepare(
            "SELECT manual_forecast_eps, manual_next_fy_forecast_eps FROM {$wpdb->prefix}stocks WHERE code = %s",
            $jquants_code
        ));
        $manual_feps   = ($manual && $manual->manual_forecast_eps > 0)         ? floatval($manual->manual_forecast_eps)         : null;
        $manual_nxfeps = ($manual && $manual->manual_next_fy_forecast_eps > 0) ? floatval($manual->manual_next_fy_forecast_eps) : null;

        $jquants = wp_stocks_jquants_get_fins_summary($jquants_code);
        if ($jquants !== false) {
            if ($jquants['forecast_eps'] !== null) $result['forward_eps'] = $jquants['forecast_eps'];
            if ($jquants['equity_ratio'] !== null) $result['equity_ratio'] = round($jquants['equity_ratio'] * 100, 1);
            if ($jquants['roe'] !== null)           $result['roe']          = round($jquants['roe'] * 100, 1);
        } else {
            wp_stocks_log('error', 'jquants_ratios', $symbol, 'J-Quantsからの財務指標取得に失敗、Yahoo由来の値にフォールバック');
        }

        // PEG算出用のEPS：手動入力があればそちらを優先、なければJ-Quants値
        $feps   = $manual_feps   ?? ($jquants !== false ? $jquants['forecast_eps'] : null);
        $nxfeps = $manual_nxfeps ?? ($jquants !== false ? $jquants['next_fy_forecast_eps'] : null);

        // 手動の当期予想EPSがあれば画面表示（forward_eps）にも反映
        if ($manual_feps !== null) $result['forward_eps'] = $manual_feps;

        // 予想EPS成長率(%) = (来期予想EPS - 当期予想EPS) / 当期予想EPS × 100
        if (!empty($feps) && !empty($nxfeps) && $feps > 0) {
            $growth_rate = ($nxfeps - $feps) / $feps * 100;

            // PEG専用の予想PER：Yahoo予想PER(forward_per)がN/Aの銘柄でも算出できるよう、
            // 現在株価÷予想EPSでその場で計算する（画面の「PER（予想）」表示自体はYahoo値のまま変更しない）
            $peg_forward_per = $result['forward_per'];
            if ($peg_forward_per <= 0) {
                $price_data = wp_stocks_get_price($symbol);
                if ($price_data && !empty($price_data['c']) && $price_data['c'] > 0) {
                    $peg_forward_per = $price_data['c'] / $feps;
                }
            }

            if ($growth_rate > 0 && $peg_forward_per > 0) {
                $result['peg'] = round($peg_forward_per / $growth_rate, 2);
            } else {
                $result['peg'] = 0; // 成長率がマイナス/ゼロはPEGとして意味を持たないため非表示扱い
            }
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





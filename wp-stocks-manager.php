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

define('WP_STOCKS_VERSION', '4.7');
define('WP_STOCKS_LOG_DAYS', 30);

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
        '1617' => ['name' => 'NF・食品',           'sectors' => ['食料品']],
        '1618' => ['name' => 'NF・エネルギー資源', 'sectors' => ['鉱業', '石油・石炭製品']],
        '1619' => ['name' => 'NF・建設・資材',     'sectors' => ['建設業', 'ガラス・土石製品']],
        '1620' => ['name' => 'NF・素材・化学',     'sectors' => ['化学', '繊維製品', 'パルプ・紙', 'ゴム製品']],
        '1621' => ['name' => 'NF・医薬品',         'sectors' => ['医薬品']],
        '1622' => ['name' => 'NF・自動車・輸送機', 'sectors' => ['輸送用機器']],
        '1623' => ['name' => 'NF・鉄鋼・非鉄',     'sectors' => ['鉄鋼', '非鉄金属', '金属製品']],
        '1624' => ['name' => 'NF・機械',           'sectors' => ['機械']],
        '1625' => ['name' => 'NF・電機・精密',     'sectors' => ['電気機器', '精密機器']],
        '1626' => ['name' => 'NF・情報通信・サービス', 'sectors' => ['情報・通信業', 'サービス業', 'その他製品']],
        '1627' => ['name' => 'NF・電力・ガス',     'sectors' => ['電気・ガス業']],
        '1628' => ['name' => 'NF・運輸・物流',     'sectors' => ['陸運業', '海運業']],
        '1629' => ['name' => 'NF・商社・卸売',     'sectors' => ['卸売業']],
        '1630' => ['name' => 'NF・小売',           'sectors' => ['小売業']],
        '1631' => ['name' => 'NF・銀行',           'sectors' => ['銀行業']],
        '1632' => ['name' => 'NF・金融（除く銀行）', 'sectors' => ['保険業', '証券・商品先物取引業', 'その他金融業']],
        '1633' => ['name' => 'NF・不動産',         'sectors' => ['不動産業']],
    ];
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
// テクニカル指標計算（EMA・MACD・RSI・トレンド）
// --------------------------------------------------
function wp_stocks_calc_technicals($closes, $highs = null, $lows = null, $opens = null) {
    $n = count($closes);
    if ($n < 5) return null;

    // --- 単純移動平均 ---
    $ma5  = $n >= 5  ? array_sum(array_slice($closes, -5))  / 5  : null;
    $ma25 = $n >= 25 ? array_sum(array_slice($closes, -25)) / 25 : null;
    $ma75 = $n >= 75 ? array_sum(array_slice($closes, -75)) / 75 : null;

    // --- EMA計算ヘルパー ---
    $calc_ema = function(array $data, int $period) {
        if (count($data) < $period) return null;
        $k   = 2 / ($period + 1);
        $ema = array_sum(array_slice($data, 0, $period)) / $period;
        foreach (array_slice($data, $period) as $price) {
            $ema = $price * $k + $ema * (1 - $k);
        }
        return $ema;
    };

    // --- MACD (12,26,9) ---
    $macd_val    = null;
    $macd_signal = null;
    $macd_hist   = null;
    if ($n >= 26) {
        $ema12 = $calc_ema($closes, 12);
        $ema26 = $calc_ema($closes, 26);
        if ($ema12 !== null && $ema26 !== null) {
            $macd_val = $ema12 - $ema26;

            // シグナル計算用にMACD系列を作る（簡易版：最後の9本分のMACD平均をシグナルとする）
            $macd_series = [];
            for ($i = 26; $i <= $n; $i++) {
                $slice_ema12 = $calc_ema(array_slice($closes, 0, $i), 12);
                $slice_ema26 = $calc_ema(array_slice($closes, 0, $i), 26);
                if ($slice_ema12 !== null && $slice_ema26 !== null) {
                    $macd_series[] = $slice_ema12 - $slice_ema26;
                }
            }
            $macd_signal = count($macd_series) >= 9 ? $calc_ema($macd_series, 9) : null;
            $macd_hist   = ($macd_val !== null && $macd_signal !== null) ? $macd_val - $macd_signal : null;
        }
    }

    // --- RSI (14) ---
    $rsi = null;
    if ($n >= 15) {
        $gains = $losses = [];
        for ($i = $n - 14; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gains[]  = $diff > 0 ? $diff : 0;
            $losses[] = $diff < 0 ? abs($diff) : 0;
        }
        $avg_gain = array_sum($gains)  / 14;
        $avg_loss = array_sum($losses) / 14;
        $rs  = $avg_loss > 0 ? $avg_gain / $avg_loss : 999;
        $rsi = round(100 - (100 / (1 + $rs)), 1);
    }

    // --- トレンド判定 ---
    $trend          = 'flat';
    $trend_strength = 1;
    $cross_signal   = null;

    if ($ma5 !== null) {
        $last_close = end($closes);
        $diff_pct   = ($last_close - $ma5) / $ma5 * 100;
        if ($diff_pct > 3)       { $trend = 'up';   $trend_strength = 3; }
        elseif ($diff_pct > 1)   { $trend = 'up';   $trend_strength = 2; }
        elseif ($diff_pct > 0)   { $trend = 'up';   $trend_strength = 1; }
        elseif ($diff_pct < -3)  { $trend = 'down'; $trend_strength = 3; }
        elseif ($diff_pct < -1)  { $trend = 'down'; $trend_strength = 2; }
        else                     { $trend = 'flat';  $trend_strength = 1; }
    }

    // ゴールデンクロス/デッドクロス判定（MA5とMA25）
    if ($ma5 !== null && $ma25 !== null && $n >= 26) {
        $prev_closes  = array_slice($closes, 0, -1);
        $prev_ma5     = count($prev_closes) >= 5  ? array_sum(array_slice($prev_closes, -5))  / 5  : null;
        $prev_ma25    = count($prev_closes) >= 25 ? array_sum(array_slice($prev_closes, -25)) / 25 : null;
        if ($prev_ma5 !== null && $prev_ma25 !== null) {
            if ($prev_ma5 < $prev_ma25 && $ma5 >= $ma25) $cross_signal = 'golden';
            elseif ($prev_ma5 > $prev_ma25 && $ma5 <= $ma25) $cross_signal = 'dead';
        }
    }

    // --- RCI (9日) ---
    $rci = null;
    $rci_period = 9;
    if ($n >= $rci_period) {
        $window = array_slice($closes, -$rci_period);
        $rows = [];
        foreach (array_values($window) as $i => $price) {
            $rows[$i] = ['price' => $price, 'day_rank' => $rci_period - $i];
        }
        $keys = array_keys($rows);
        usort($keys, function($a, $b) use ($rows) { return $rows[$b]['price'] <=> $rows[$a]['price']; });
        $price_rank = [];
        foreach ($keys as $rank => $k) { $price_rank[$k] = $rank + 1; }
        $sum_d2 = 0;
        foreach ($rows as $k => $row) {
            $d = $row['day_rank'] - $price_rank[$k];
            $sum_d2 += $d * $d;
        }
        $rci = round((1 - (6 * $sum_d2) / ($rci_period * ($rci_period * $rci_period - 1))) * 100, 1);
    }

    // --- ボリンジャーバンド (20日, ±2σ) ---
    $bb_upper = $bb_mid = $bb_lower = null;
    $bb_period = 20;
    if ($n >= $bb_period) {
        $window = array_slice($closes, -$bb_period);
        $mean = array_sum($window) / $bb_period;
        $variance = array_sum(array_map(function($v) use ($mean) { return ($v - $mean) ** 2; }, $window)) / $bb_period;
        $sd = sqrt($variance);
        $bb_mid   = round($mean, 1);
        $bb_upper = round($mean + 2 * $sd, 1);
        $bb_lower = round($mean - 2 * $sd, 1);
    }

    // --- DMI / ADX (14日, Wilderスムージング) ---
    $plus_di = $minus_di = $adx = null;
    $dmi_period = 14;
    if (is_array($highs) && is_array($lows) && count($highs) === $n && count($lows) === $n && $n >= $dmi_period + 1) {
        $plus_dm_arr = $minus_dm_arr = $tr_arr = [];
        for ($i = 1; $i < $n; $i++) {
            $up_move   = $highs[$i] - $highs[$i - 1];
            $down_move = $lows[$i - 1] - $lows[$i];
            $plus_dm_arr[]  = ($up_move > $down_move && $up_move > 0) ? $up_move : 0;
            $minus_dm_arr[] = ($down_move > $up_move && $down_move > 0) ? $down_move : 0;
            $tr_arr[] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1])
            );
        }
        $wilder_smooth = function(array $values, int $period) {
            $count = count($values);
            if ($count < $period) return null;
            $smoothed = array_sum(array_slice($values, 0, $period));
            for ($i = $period; $i < $count; $i++) {
                $smoothed = $smoothed - ($smoothed / $period) + $values[$i];
            }
            return $smoothed;
        };
        $smoothed_plus_dm  = $wilder_smooth($plus_dm_arr, $dmi_period);
        $smoothed_minus_dm = $wilder_smooth($minus_dm_arr, $dmi_period);
        $smoothed_tr       = $wilder_smooth($tr_arr, $dmi_period);
        if ($smoothed_tr !== null && $smoothed_tr > 0) {
            $plus_di  = round(100 * $smoothed_plus_dm  / $smoothed_tr, 1);
            $minus_di = round(100 * $smoothed_minus_dm / $smoothed_tr, 1);
            $di_sum = $plus_di + $minus_di;
            if ($di_sum > 0) {
                $dx = 100 * abs($plus_di - $minus_di) / $di_sum;
                $adx = round($dx, 1);
            }
        }
    }

    // =========================================================================
    // ★追加：打診買い・打診売り（ボリンジャーσタッチ＋終値反転、下落トレンド終盤・底値圏向け）
    // =========================================================================
    $tasin_buy  = false;
    $tasin_sell = false;
    $prev_idx   = $n - 2;
    $today_close = end($closes);
    $today_open  = ($opens !== null && count($opens) === $n) ? end($opens) : null;

    if ($bb_mid !== null && $bb_upper !== null && $bb_lower !== null && $prev_idx >= 20 && is_array($highs) && is_array($lows) && count($highs) === $n && count($lows) === $n) {
        // 前日時点のボリンジャーバンドを計算
        $window_prev   = array_slice($closes, $prev_idx - 19, 20);
        $mean_prev     = array_sum($window_prev) / 20;
        $variance_prev = array_sum(array_map(function($v) use ($mean_prev) { return ($v - $mean_prev) ** 2; }, $window_prev)) / 20;
        $sd_prev       = sqrt($variance_prev);
        $bb_upper_prev = $mean_prev + 2 * $sd_prev;
        $bb_lower_prev = $mean_prev - 2 * $sd_prev;

        $prev_low  = $lows[$prev_idx];
        $prev_high = $highs[$prev_idx];

        // 1. 打診買い：前日安値 ≦ 前日-2σ 且つ 当日終値 ＞ 当日-2σ
        if ($prev_low <= $bb_lower_prev && $today_close > $bb_lower) {
            $tasin_buy = true;
        }
        // 2. 打診売り：前日高値 ≧ 前日+2σ 且つ 当日終値 ＜ 当日+2σ
        if ($prev_high >= $bb_upper_prev && $today_close < $bb_upper) {
            $tasin_sell = true;
        }
    }

    // =========================================================================
    // ★変更：バンドウォーク（強トレンド中の張り付き）識別を双方向対応に拡張。
    // 下落バンドウォーク中は打診買いを、上昇バンドウォーク中は打診売りを強制ブロックする。
    // （3条件中N個以上で発火。Nは設定ページの「バンドウォーク判定の必要条件数」）
    // =========================================================================
    $bandwalk_detected  = false;
    $bandwalk_direction = null;
    if ($n >= 25 && $bb_lower !== null && $bb_upper !== null) {
        $bw_threshold = intval(get_option('wp_stocks_bandwalk_majority_threshold', 2));

        // 条件A（下落）：直近3日の終値が、それぞれの前日-2σを連続して下回っている
        $bw_down_score = 0;
        $a_down_match  = true;
        for ($i = 0; $i < 3; $i++) {
            $curr_t = $n - 1 - $i;
            $prev_t = $curr_t - 1;
            if ($prev_t - 19 < 0) { $a_down_match = false; break; }
            $win_t = array_slice($closes, $prev_t - 19, 20);
            $m_t   = array_sum($win_t) / 20;
            $v_t   = array_sum(array_map(function($v) use ($m_t) { return ($v - $m_t) ** 2; }, $win_t)) / 20;
            $sd_t  = sqrt($v_t);
            $bb_lower_t = $m_t - 2 * $sd_t;
            if ($closes[$curr_t] >= $bb_lower_t) { $a_down_match = false; break; }
        }
        if ($a_down_match) $bw_down_score++;

        // 条件A（上昇）：直近3日の終値が、それぞれの前日+2σを連続して上回っている
        $bw_up_score = 0;
        $a_up_match  = true;
        for ($i = 0; $i < 3; $i++) {
            $curr_t = $n - 1 - $i;
            $prev_t = $curr_t - 1;
            if ($prev_t - 19 < 0) { $a_up_match = false; break; }
            $win_t = array_slice($closes, $prev_t - 19, 20);
            $m_t   = array_sum($win_t) / 20;
            $v_t   = array_sum(array_map(function($v) use ($m_t) { return ($v - $m_t) ** 2; }, $win_t)) / 20;
            $sd_t  = sqrt($v_t);
            $bb_upper_t = $m_t + 2 * $sd_t;
            if ($closes[$curr_t] <= $bb_upper_t) { $a_up_match = false; break; }
        }
        if ($a_up_match) $bw_up_score++;

        // 条件B：バンド幅（Width）が過去20日で最大＝スクイーズからエクスパンションへの移行
        // （方向を問わない条件のため、下落・上昇どちらのスコアにも加点する）
        $widths = [];
        for ($i = 0; $i < 20; $i++) {
            $t = $n - 1 - $i;
            if ($t - 19 < 0) { $widths = []; break; }
            $win_w = array_slice($closes, $t - 19, 20);
            $m_w   = array_sum($win_w) / 20;
            $v_w   = array_sum(array_map(function($v) use ($m_w) { return ($v - $m_w) ** 2; }, $win_w)) / 20;
            $sd_w  = sqrt($v_w);
            $widths[] = ($m_w + 2 * $sd_w) - ($m_w - 2 * $sd_w);
        }
        if (!empty($widths) && $widths[0] == max($widths)) {
            $bw_down_score++;
            $bw_up_score++;
        }

        // 条件C（代替）：ADX≧25 且つ DMIの方向が一致（既存計算済みのDMIを流用）
        if ($adx !== null && $plus_di !== null && $minus_di !== null && $adx >= 25) {
            if ($minus_di > $plus_di) $bw_down_score++;
            if ($plus_di  > $minus_di) $bw_up_score++;
        }

        if ($bw_down_score >= $bw_threshold) {
            $bandwalk_detected  = true;
            $bandwalk_direction = 'down';
            $tasin_buy = false; // 下落バンドウォーク中は打診買いを強制ブロック（騙し防止）
        } elseif ($bw_up_score >= $bw_threshold) {
            $bandwalk_detected  = true;
            $bandwalk_direction = 'up';
            $tasin_sell = false; // 上昇バンドウォーク中は打診売りを強制ブロック（騙し防止）
        }
    }

    // ★追加：3条件の多数決に届かない場合でも、ADX/DMIだけで明確なトレンド方向が
    // 出ていれば逆方向の打診シグナルを直接ブロックする（3日連続σ超え条件が厳しすぎて
    // 多数決が成立しない強トレンド銘柄への対策）
    if ($adx !== null && $plus_di !== null && $minus_di !== null && $adx >= 25) {
        if ($plus_di > $minus_di && $tasin_sell) {
            $tasin_sell = false;
            if (!$bandwalk_detected) { $bandwalk_detected = true; $bandwalk_direction = 'up'; }
        }
        if ($minus_di > $plus_di && $tasin_buy) {
            $tasin_buy = false;
            if (!$bandwalk_detected) { $bandwalk_detected = true; $bandwalk_direction = 'down'; }
        }
    }

    // =========================================================================
    // ★追加：オシレーター反転確認（RSI売られ過ぎ／買われ過ぎ ＋ 厳格な陽線・陰線反転）
    // =========================================================================
    $strict_bullish_turn = false;
    $strict_bearish_turn = false;
    if ($today_open !== null && $prev_idx >= 0) {
        $prev_close_for_turn = $closes[$prev_idx];
        if ($today_close > $today_open && $today_close > $prev_close_for_turn) $strict_bullish_turn = true;
        if ($today_close < $today_open && $today_close < $prev_close_for_turn) $strict_bearish_turn = true;
    }

    $oscillator_reversal_buy  = ($rsi !== null && $rsi <= 30 && $strict_bullish_turn);
    $oscillator_reversal_sell = ($rsi !== null && $rsi >= 70 && $strict_bearish_turn);

      return [
        'trend'          => $trend,
        'trend_strength' => $trend_strength,
        'ma5'            => $ma5  !== null ? round($ma5,  1) : null,
        'ma25'           => $ma25 !== null ? round($ma25, 1) : null,
        'ma75'           => $ma75 !== null ? round($ma75, 1) : null,
        'macd'           => $macd_val    !== null ? round($macd_val,    2) : null,
        'macd_signal'    => $macd_signal !== null ? round($macd_signal, 2) : null,
        'macd_hist'      => $macd_hist   !== null ? round($macd_hist,   2) : null,
        'rsi'            => $rsi,
        'cross_signal'   => $cross_signal,
        'rci'            => $rci,
        'bb_upper'       => $bb_upper,
        'bb_mid'         => $bb_mid,
        'bb_lower'       => $bb_lower,
        'plus_di'        => $plus_di,
        'minus_di'       => $minus_di,
        'adx'            => $adx,
        'tasin_buy'                => $tasin_buy ? 1 : 0,
        'tasin_sell'               => $tasin_sell ? 1 : 0,
        'bandwalk_detected'        => $bandwalk_detected ? 1 : 0,
        'bandwalk_direction'       => $bandwalk_direction,
        'oscillator_reversal_buy'  => $oscillator_reversal_buy ? 1 : 0,
        'oscillator_reversal_sell' => $oscillator_reversal_sell ? 1 : 0,
    ];
}

// --------------------------------------------------
// ローソク足パターン判定（陽線/陰線・十字線・包み足）
// --------------------------------------------------
function wp_stocks_detect_candle_pattern($data) {
    $n = count($data);
    if ($n < 2) return null;
    $today = $data[$n - 1];
    $prev  = $data[$n - 2];

    $o = floatval($today['open']  ?? $today['close']);
    $c = floatval($today['close']);
    $h = floatval($today['high']  ?? max($o, $c));
    $l = floatval($today['low']   ?? min($o, $c));
    $body  = abs($c - $o);
    $range = $h - $l;

    $po = floatval($prev['open']  ?? $prev['close']);
    $pc = floatval($prev['close']);

    // =========================================================================
    // ★追加：赤三兵（3日連続陽線・終値切り上げ＋実体の強さ確認、必須＋加点2段階）
    // ★追加：並び赤（上放れ窓開け後の2日連続陽線、始値・終値のズレが許容範囲内）
    // 3日分のデータが揃っている場合のみ判定する
    // =========================================================================
    if ($n >= 3) {
        $p_prev = $data[$n - 3];
        $o2 = floatval($p_prev['open']  ?? $p_prev['close']);
        $c2 = floatval($p_prev['close']);
        $h2 = floatval($p_prev['high']  ?? max($o2, $c2));

        $is_today_bullish = $c > $o;
        $is_prev_bullish   = $pc > $po;
        $is_pprev_bullish  = $c2 > $o2;

        // --- 赤三兵 ---
        if ($is_today_bullish && $is_prev_bullish && $is_pprev_bullish && $c > $pc && $pc > $c2) {
            // 【必須】当日の始値が前日の「始値〜終値」レンジ内に収まっている（窓を開けて下落スタートしていない）
            $min_range = min($po, $pc);
            $max_range = max($po, $pc);
            if ($o >= $min_range && $o <= $max_range) {
                // 【加点】前日実体の中心より上からスタートしているか
                $prev_mid_point = ($po + $pc) / 2;
                if ($o > $prev_mid_point) {
                    return 'strong_aka_sanpei';
                }
                return 'aka_sanpei';
            }
        }

        // --- 並び赤（上放れ窓開け後の横並び陽線） ---
        if ($pc > $po && $c > $o && $po > $h2) {
            $open_diff_pct  = $po > 0 ? abs($o - $po) / $po : 1;
            $close_diff_pct = $pc > 0 ? abs($c - $pc) / $pc : 1;
            if ($open_diff_pct <= 0.02 && $close_diff_pct <= 0.02) {
                return 'narabi_aka';
            }
        }
    }

    // 包み足：前日の実体を完全に包む陽線／陰線
    if ($po > $pc && $c > $o && $o <= $pc && $c >= $po) {
        return 'bullish_engulfing';
    }
    if ($po < $pc && $c < $o && $o >= $pc && $c <= $po) {
        return 'bearish_engulfing';
    }

    // 十字線：実体が値幅のごく一部（10%以下）
    if ($range > 0 && ($body / $range) <= 0.1) {
        return 'doji';
    }

    // 陽線／陰線
    if ($c > $o) return 'bullish';
    if ($c < $o) return 'bearish';

    return null;
}

// --------------------------------------------------
// テクニカル指標をDBに保存
// --------------------------------------------------
// --------------------------------------------------
// ストキャスティクス（Slow %K・%D）
// --------------------------------------------------
function wp_stocks_calc_stochastic($closes, $highs, $lows, $period = 14, $smooth = 3) {
    $n = count($closes);
    if ($n < $period + $smooth) return [null, null];
    $raw_k = [];
    for ($i = $period - 1; $i < $n; $i++) {
        $window_high = max(array_slice($highs, $i - $period + 1, $period));
        $window_low  = min(array_slice($lows, $i - $period + 1, $period));
        $range = $window_high - $window_low;
        $raw_k[] = $range > 0 ? (($closes[$i] - $window_low) / $range) * 100 : 50;
    }
    if (count($raw_k) < $smooth) return [null, null];
    $slow_k = [];
    for ($i = $smooth - 1; $i < count($raw_k); $i++) {
        $slow_k[] = array_sum(array_slice($raw_k, $i - $smooth + 1, $smooth)) / $smooth;
    }
    if (count($slow_k) < $smooth) return [round(end($slow_k), 1), null];
    $d = array_sum(array_slice($slow_k, -$smooth)) / $smooth;
    return [round(end($slow_k), 1), round($d, 1)];
}

// --------------------------------------------------
// パラボリックSAR
// --------------------------------------------------
function wp_stocks_calc_parabolic_sar($highs, $lows, $af_step = 0.02, $af_max = 0.2) {
    $n = count($highs);
    if ($n < 5) return [null, null, false];
    $trend = $highs[1] > $highs[0] ? 'up' : 'down';
    $af = $af_step;
    $ep = $trend === 'up' ? $highs[0] : $lows[0];
    $sar = $trend === 'up' ? $lows[0] : $highs[0];
    $reversed_last = false;
    for ($i = 1; $i < $n; $i++) {
        $reversed_last = false;
        $sar = $sar + $af * ($ep - $sar);
        if ($trend === 'up') {
            $bound1 = $lows[$i - 1];
            $bound2 = $i >= 2 ? $lows[$i - 2] : $lows[$i - 1];
            $sar = min($sar, $bound1, $bound2);
            if ($lows[$i] < $sar) {
                $trend = 'down';
                $sar = $ep;
                $ep = $lows[$i];
                $af = $af_step;
                $reversed_last = true;
            } else {
                if ($highs[$i] > $ep) { $ep = $highs[$i]; $af = min($af + $af_step, $af_max); }
            }
        } else {
            $bound1 = $highs[$i - 1];
            $bound2 = $i >= 2 ? $highs[$i - 2] : $highs[$i - 1];
            $sar = max($sar, $bound1, $bound2);
            if ($highs[$i] > $sar) {
                $trend = 'up';
                $sar = $ep;
                $ep = $highs[$i];
                $af = $af_step;
                $reversed_last = true;
            } else {
                if ($lows[$i] < $ep) { $ep = $lows[$i]; $af = min($af + $af_step, $af_max); }
            }
        }
    }
    return [round($sar, 2), $trend, $reversed_last];
}

// --------------------------------------------------
// フィボナッチリトレースメント（直近安値高値からの主要水準への接近判定）
// --------------------------------------------------
function wp_stocks_calc_fibonacci($highs, $lows, $current_close, $lookback = 60) {
    $n = count($highs);
    $start = max(0, $n - $lookback);
    $window_high = max(array_slice($highs, $start));
    $window_low  = min(array_slice($lows, $start));
    $range = $window_high - $window_low;
    if ($range <= 0 || !$current_close) return [null, false];
    $levels = [
        '23.6%' => $window_high - $range * 0.236,
        '38.2%' => $window_high - $range * 0.382,
        '50.0%' => $window_high - $range * 0.5,
        '61.8%' => $window_high - $range * 0.618,
    ];
    $nearest_label = null;
    $nearest_dist  = null;
    foreach ($levels as $label => $price) {
        $dist_pct = abs($current_close - $price) / $current_close * 100;
        if ($nearest_dist === null || $dist_pct < $nearest_dist) {
            $nearest_dist = $dist_pct;
            $nearest_label = $label;
        }
    }
    $is_near = $nearest_dist !== null && $nearest_dist <= 1.5;
    return [$nearest_label, $is_near];
}

function wp_stocks_save_technicals($stock_id, $symbol) {
    global $wpdb;

    $data = wp_stocks_get_ohlcv($symbol);
    if (!$data || count($data) < 5) {
        wp_stocks_log('error', 'save_technicals', $symbol, 'OHLCVデータ取得失敗: ' . ($data ? count($data) . '件' : 'false'));
        return false;
    }

    $closes = array_column($data, 'close');
    $highs  = array_column($data, 'high');
    $lows   = array_column($data, 'low');
    $opens  = array_column($data, 'open');
    $result = wp_stocks_calc_technicals($closes, $highs, $lows, $opens);
    if (!$result) {
        wp_stocks_log('error', 'save_technicals', $symbol, 'テクニカル計算失敗: ' . count($closes) . '件');
        return false;
    }
    $result['candle_pattern'] = wp_stocks_detect_candle_pattern($data);
    list($stoch_k, $stoch_d) = wp_stocks_calc_stochastic($closes, $highs, $lows);
    list($sar, $sar_trend, $sar_reversal) = wp_stocks_calc_parabolic_sar($highs, $lows);
    list($fib_level, $fib_near) = wp_stocks_calc_fibonacci($highs, $lows, end($closes));
    $result['stoch_k']      = $stoch_k;
    $result['stoch_d']      = $stoch_d;
    $result['sar']          = $sar;
    $result['sar_trend']    = $sar_trend;
    $result['sar_reversal'] = $sar_reversal ? 1 : 0;
    $result['fib_level']    = $fib_level;
    $result['fib_near']     = $fib_near ? 1 : 0;

    // ★変更：複合判定（論点2）のため、上書きされる前の行を丸ごと取得しておく
    $existing_row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_technicals WHERE stock_id = %d", $stock_id
    ));
    $existing = $existing_row->id ?? null;

    // ★追加：前日値を新しい行にも保持する（次回計算時の比較用）
    $result['prev_macd']        = $existing_row->macd        ?? null;
    $result['prev_macd_signal'] = $existing_row->macd_signal ?? null;
    $result['prev_rci']         = $existing_row->rci         ?? null;
    $result['prev_stoch_k']     = $existing_row->stoch_k     ?? null;
    $result['prev_stoch_d']     = $existing_row->stoch_d     ?? null;

    // ★変更：複合シグナル判定に時価総額（下地）も渡す。階層別の重み設定が無い間は結果は変わらない
    $stock_row_for_tier = $wpdb->get_row($wpdb->prepare(
        "SELECT market_cap FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id
    ));
    $market_cap_for_tier = $stock_row_for_tier->market_cap ?? null;
    $composite = wp_stocks_calc_composite_score($result, $existing_row, $market_cap_for_tier);
    $result['composite_score']  = $composite['score'];
    $result['composite_label']  = $composite['label'];
    $result['composite_detail'] = wp_json_encode($composite['detail'], JSON_UNESCAPED_UNICODE);

    $row = array_merge(['stock_id' => $stock_id, 'calculated_at' => current_time('mysql')], $result);

    if ($existing) {
        unset($row['stock_id']);
        $db_result = $wpdb->update($wpdb->prefix . 'stock_technicals', $row, ['stock_id' => $stock_id]);
        if ($db_result === false) wp_stocks_log('error', 'save_technicals', $symbol, 'DB更新失敗: ' . $wpdb->last_error);
    } else {
        $db_result = $wpdb->insert($wpdb->prefix . 'stock_technicals', $row);
        if ($db_result === false) wp_stocks_log('error', 'save_technicals', $symbol, 'DB挿入失敗: ' . $wpdb->last_error);
    }

    // ★追加：シグナル履歴テーブルへの記録（論点1：B）
    if ($db_result !== false) {
        $flags = wp_stocks_classify_signal((object) $result, end($closes));
        $active_flags = array_keys(array_filter($flags));
        $wpdb->replace($wpdb->prefix . 'stock_signal_history', [
            'stock_id'         => $stock_id,
            'signal_date'      => current_time('Y-m-d'),
            'composite_score'  => $composite['score'],
            'composite_label'  => $composite['label'],
            'composite_detail' => wp_json_encode($composite['detail'], JSON_UNESCAPED_UNICODE),
            'flags'            => implode(',', $active_flags),
        ]);
    }

    return $db_result !== false;
}

// --------------------------------------------------
// 過去の日足データをstock_pricesに補完保存
// （再登録直後の銘柄など、履歴が欠けている場合のグラフ欠落対策）
// --------------------------------------------------
function wp_stocks_backfill_price_history($stock_id, $symbol, $days = 30) {
    global $wpdb;
    $ohlcv = wp_stocks_get_ohlcv($symbol); // 6ヶ月分の日足（6時間キャッシュ付き）
    if (!$ohlcv || count($ohlcv) < 2) return 0;

    $cutoff = date('Y-m-d', strtotime("-{$days} days"));
    $recent = array_values(array_filter($ohlcv, function($d) use ($cutoff) {
        return $d['date'] >= $cutoff;
    }));
    if (count($recent) < 2) return 0;

    $inserted = 0;
    foreach ($recent as $i => $row) {
        $date = $row['date'];
        // 既にその日のレコードがあれば上書きしない（実際の取得値を優先）
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d AND DATE(datetime) = %s",
            $stock_id, $date
        ));
        if ($exists) continue;

        $prev_close = $i > 0 ? floatval($recent[$i - 1]['close']) : null;

        $wpdb->insert($wpdb->prefix . 'stock_prices', [
            'stock_id'       => $stock_id,
            'price'          => floatval($row['close']),
            'previous_close' => $prev_close,
            'volume'         => isset($row['volume']) ? intval($row['volume']) : null,
            'datetime'       => $date . ' 15:30:00',
        ]);
        $inserted++;
    }
    return $inserted;
}

// --------------------------------------------------
// テクニカル総合判定（ダッシュボード・ポートフォリオ一覧用）
// 優先度: MACD > MA5/MA25位置関係 > GC/DC > RSI
// --------------------------------------------------
function wp_stocks_trend_icon_html($tech) {
    if (!$tech) return '<span style="color:#aaa;">-</span>';

    // ★変更：独自の簡易スコアリングをやめ、複合判定エンジン（composite_score/composite_label）を
    // そのまま使う。これにより「トレンド」列と「複合判定」列の食い違いが起きなくなる。
    $score     = intval($tech->composite_score ?? 0);
    $label_key = $tech->composite_label ?? null;

    switch ($label_key) {
        case 'strong_buy':  $label = '強い買い'; $emoji = '🟢'; $color = '#27ae60'; break;
        case 'buy':         $label = '買い';     $emoji = '🔵'; $color = '#3498db'; break;
        case 'sell':        $label = '売り';     $emoji = '🟠'; $color = '#e67e22'; break;
        case 'strong_sell': $label = '強い売り'; $emoji = '🔴'; $color = '#e74c3c'; break;
        default:            $label = '中立';     $emoji = '⚪'; $color = '#888';
    }

    $detail     = json_decode($tech->composite_detail ?? '[]', true);
    $detail_str = (is_array($detail) && !empty($detail)) ? implode(' / ', $detail) : '該当条件なし';
    $tooltip    = "複合スコア:{$score} / " . $detail_str;

    return '<span title="' . esc_attr($tooltip) . '" style="font-size:13px;font-weight:bold;color:' . $color . ';">'
        . $emoji . ' ' . $label
        . '</span>';
}

// --------------------------------------------------
// ★追加：株価・移動平均線の「継続／転換」5状態アイコン
// （テクニカル分析タブ上部の「今日の株価」テーブル用）
// 画像は assets/trend-icons/ 配下に以下のファイル名で配置する:
//   trend-up.jpg（上昇継続）／trend-down.jpg（下降継続）／trend-flat.jpg（横ばい）
//   trend-turn-up.jpg（下降→上昇に転換）／trend-turn-down.jpg（上昇→下降に転換）
// --------------------------------------------------

// ★変更：ファイル配信（assets/trend-icons/）だとNAS環境のACL/パーミッションの影響を受けやすいため、
// Base64データURIとしてコード内に直接埋め込む方式に変更（静的ファイル配信に一切依存しない）
function wp_stocks_trend_icon_data_uri($state) {
    static $icons = null;
    if ($icons === null) {
        $icons = [
            'up' => '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',
            'down' => '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',
            'flat' => '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',
            'turn_up' => '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',
            'turn_down' => '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',
        ];
    }
    if (!isset($icons[$state])) return '';
    return 'data:image/jpeg;base64,' . $icons[$state];
}

function wp_stocks_trend_state_label($state) {
    $labels = [
        'up'        => '上昇継続',
        'down'      => '下降継続',
        'flat'      => '横ばい',
        'turn_up'   => '上昇に転換',
        'turn_down' => '下降に転換',
    ];
    return $labels[$state] ?? '-';
}

// $values3 は古い順に3値（例：[前々日, 前日, 当日]）
function wp_stocks_calc_trend_state($values3) {
    if (!is_array($values3) || count($values3) < 3) return null;
    foreach ($values3 as $v) { if ($v === null) return null; }
    $diff1 = $values3[1] - $values3[0]; // 前々日→前日
    $diff2 = $values3[2] - $values3[1]; // 前日→当日
    if ($diff2 > 0 && $diff1 > 0)  return 'up';
    if ($diff2 < 0 && $diff1 < 0)  return 'down';
    if ($diff2 > 0 && $diff1 <= 0) return 'turn_up';
    if ($diff2 < 0 && $diff1 >= 0) return 'turn_down';
    return 'flat';
}

// 指定した終値配列($closes)・期間($period)・末尾からのオフセット($offset_from_end)でMAを計算
// $offset_from_end=0が最新、1が前日、2が前々日
function wp_stocks_ma_n_days_ago($closes, $period, $offset_from_end) {
    $n = count($closes);
    $end_index = $n - 1 - $offset_from_end; // 0-based
    if ($end_index - $period + 1 < 0) return null;
    $slice = array_slice($closes, $end_index - $period + 1, $period);
    return array_sum($slice) / $period;
}

function wp_stocks_ma_trend_icon_html($state) {
    if ($state === null) return '<span style="color:#aaa;">-</span>';
    $data_uri = wp_stocks_trend_icon_data_uri($state);
    if (empty($data_uri)) return '<span style="color:#aaa;">-</span>';
    $label = wp_stocks_trend_state_label($state);
    return '<div style="display:flex;flex-direction:column;align-items:center;gap:3px;">'
        . '<img src="' . esc_attr($data_uri) . '" alt="' . esc_attr($label) . '" style="width:28px;height:28px;">'
        . '<span style="font-size:10px;color:#666;">' . esc_html($label) . '</span>'
        . '</div>';
}

// --------------------------------------------------
// テクニカル指標詳細HTML（企業情報ページ用）
// --------------------------------------------------
function wp_stocks_trend_detail_html($tech) {
    if (!$tech) return '<span style="color:#aaa;">データなし</span>';

    $trend    = $tech->trend ?? 'flat';
    $strength = intval($tech->trend_strength ?? 1);
    $cross    = $tech->cross_signal ?? null;
    $macd     = $tech->macd ?? null;
    $macd_sig = $tech->macd_signal ?? null;
    $rsi      = $tech->rsi ?? null;

    // トレンドアイコン
    if ($trend === 'up')        { $arrows = str_repeat('↑', $strength); $color = '#e74c3c'; }
    elseif ($trend === 'down')  { $arrows = str_repeat('↓', $strength); $color = '#3498db'; }
    else                        { $arrows = '→'; $color = '#888'; }

    $html = '<span style="font-size:16px;font-weight:bold;color:' . $color . ';" title="MA5乖離率によるトレンド">' . $arrows . '</span>';

    // GC/DC
    if ($cross === 'golden') {
        $html .= ' <span style="background:#27ae60;color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:bold;" title="ゴールデンクロス（MA5がMA25を上抜け）">GC</span>';
    } elseif ($cross === 'dead') {
        $html .= ' <span style="background:#e74c3c;color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:bold;" title="デッドクロス（MA5がMA25を下抜け）">DC</span>';
    }

    // MACD
    if ($macd !== null && $macd_sig !== null) {
        $macd_color = $macd > $macd_sig ? '#e74c3c' : '#3498db';
        $macd_label = $macd > $macd_sig ? '▲' : '▼';
        $html .= ' <span style="color:' . $macd_color . ';font-size:11px;" title="MACD: ' . $macd . ' / Signal: ' . $macd_sig . '">M' . $macd_label . '</span>';
    }

    // RSI
    if ($rsi !== null) {
        $rsi_color = $rsi >= 70 ? '#e74c3c' : ($rsi <= 30 ? '#3498db' : '#888');
        $html .= ' <span style="color:' . $rsi_color . ';font-size:11px;" title="RSI（14日）: 70以上=過熱, 30以下=売られすぎ">RSI:' . $rsi . '</span>';
    }

    // 総合判定も併記
    $html .= '<br>' . wp_stocks_trend_icon_html($tech);

    return $html;
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

    return [
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
// 適正株価計算: EPS × セクター平均PER（自社PERは使わないため循環参照なし）
// 戻り値: ['actual' => 実績ベース適正株価 or null, 'forward' => 予想ベース適正株価 or null,
//          'sector_avg_per' => 実績ベースの基準PER or null, 'sector_avg_per_forward' => 予想ベースの基準PER or null]
// --------------------------------------------------
function wp_stocks_calc_fair_price($stock) {
    $is_usd   = ($stock->currency ?? 'JPY') === 'USD';
    $sector   = $stock->sector ?? '';
    $market   = $stock->market ?? '';
    $stock_id = $stock->id ?? null;

    $result = ['actual' => null, 'forward' => null, 'sector_avg_per' => null, 'sector_avg_per_forward' => null];

    if (($stock->eps ?? 0) > 0) {
        $avg_per = wp_stocks_get_sector_avg_per($sector, $is_usd, $stock_id, false, $market);
        if ($avg_per !== null) {
            $result['actual']         = $stock->eps * $avg_per;
            $result['sector_avg_per'] = $avg_per;
        }
    }
    if (($stock->forward_eps ?? 0) > 0) {
        $avg_per_fwd = wp_stocks_get_sector_avg_per($sector, $is_usd, $stock_id, true, $market);
        if ($avg_per_fwd !== null) {
            $result['forward']                = $stock->forward_eps * $avg_per_fwd;
            $result['sector_avg_per_forward']  = $avg_per_fwd;
        }
    }
    return $result;
}

// --------------------------------------------------
// JPX「規模別・業種別PER・PBR」xlsx 取り込み・解析・保存
// --------------------------------------------------
function wp_stocks_jpx_find_latest_xlsx_url() {
    $response = wp_remote_get('https://www.jpx.co.jp/markets/statistics-equities/misc/04.html', [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
        'timeout' => 20,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'jpx_sector_per', 'ALL', '一覧ページの取得に失敗: ' . $response->get_error_message());
        return false;
    }
    $html = wp_remote_retrieve_body($response);

    if (!preg_match_all('/href="([^"]*perpbr(\\d{6})\\.xlsx)"/', $html, $matches, PREG_SET_ORDER)) {
        wp_stocks_log('error', 'jpx_sector_per', 'ALL', '一覧ページからxlsxリンクを1件も抽出できませんでした');
        return false;
    }

    $best_url = null;
    $best_ym  = '';
    foreach ($matches as $m) {
        $url = $m[1];
        $ym  = $m[2];
        if (strpos($url, 'http') !== 0) {
            $url = 'https://www.jpx.co.jp' . $url;
        }
        if ($ym > $best_ym) {
            $best_ym  = $ym;
            $best_url = $url;
        }
    }

    if (!$best_url) {
        wp_stocks_log('error', 'jpx_sector_per', 'ALL', '最新月のxlsxリンクを特定できませんでした');
        return false;
    }

    return ['url' => $best_url, 'year_month' => substr($best_ym, 0, 4) . '-' . substr($best_ym, 4, 2)];
}

function wp_stocks_jpx_download_xlsx($url) {
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
        'timeout' => 60,
    ]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        wp_stocks_log('error', 'jpx_sector_per', 'ALL', 'xlsxのダウンロードに失敗: ' . $url);
        return false;
    }
    $tmp_file = sys_get_temp_dir() . '/jpx_perpbr_' . uniqid() . '.xlsx';
    file_put_contents($tmp_file, wp_remote_retrieve_body($response));
    return $tmp_file;
}

function wp_stocks_jpx_read_shared_strings(ZipArchive $zip) {
    $xml_str = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml_str === false) return [];

    $dom = new DOMDocument();
    $dom->loadXML($xml_str, LIBXML_NOCDATA);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $strings = [];
    $si_nodes = $xpath->query('//ns:sst/ns:si');
    foreach ($si_nodes as $si) {
        // <si>直下の<t>、または<si>直下の<r>直下の<t> のみ対象（<rPh>内のふりがなは除外）
        $t_nodes = $xpath->query('./ns:t | ./ns:r/ns:t', $si);
        $text = '';
        foreach ($t_nodes as $t) {
            $text .= $t->textContent;
        }
        $strings[] = $text;
    }
    return $strings;
}

function wp_stocks_jpx_col_letter_to_index($col_letters) {
    $col_letters = strtoupper($col_letters);
    $index = 0;
    for ($i = 0; $i < strlen($col_letters); $i++) {
        $index = $index * 26 + (ord($col_letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

function wp_stocks_jpx_parse_sheet_by_name($file_path, $target_sheet_name) {
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== true) {
        wp_stocks_log('error', 'jpx_sector_per', 'ALL', 'xlsxを開けませんでした（ZipArchive）: ' . $file_path);
        return false;
    }

    $workbook_xml = $zip->getFromName('xl/workbook.xml');
    if ($workbook_xml === false) { $zip->close(); return false; }

    $dom = new DOMDocument();
    $dom->loadXML($workbook_xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $sheet_nodes = $xpath->query('//ns:sheets/ns:sheet[@name="' . $target_sheet_name . '"]');
    if ($sheet_nodes->length === 0) {
        wp_stocks_log('error', 'jpx_sector_per', 'ALL', '指定シートが見つかりません: ' . $target_sheet_name);
        $zip->close();
        return false;
    }
    $r_id = $sheet_nodes->item(0)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');

    $rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $rels_dom = new DOMDocument();
    $rels_dom->loadXML($rels_xml);
    $rels_xpath = new DOMXPath($rels_dom);
    $rels_xpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $rel_nodes = $rels_xpath->query('//rel:Relationship[@Id="' . $r_id . '"]');
    if ($rel_nodes->length === 0) { $zip->close(); return false; }
    $sheet_target = 'xl/' . $rel_nodes->item(0)->getAttribute('Target');

    $shared_strings = wp_stocks_jpx_read_shared_strings($zip);

    $sheet_xml = $zip->getFromName($sheet_target);
    $zip->close();
    if ($sheet_xml === false) return false;

    $sheet_dom = new DOMDocument();
    $sheet_dom->loadXML($sheet_xml);
    $sheet_xpath = new DOMXPath($sheet_dom);
    $sheet_xpath->registerNamespace('ns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $result = [];
    $row_nodes = $sheet_xpath->query('//ns:sheetData/ns:row');
    foreach ($row_nodes as $row_node) {
        $row_num = intval($row_node->getAttribute('r'));
        $cells   = [];
        $c_nodes = $sheet_xpath->query('./ns:c', $row_node);
        foreach ($c_nodes as $c_node) {
            $ref = $c_node->getAttribute('r');
            preg_match('/^([A-Z]+)(\\d+)$/', $ref, $m);
            if (empty($m)) continue;
            $col_index = wp_stocks_jpx_col_letter_to_index($m[1]);

            $type   = $c_node->getAttribute('t');
            $v_node = $sheet_xpath->query('./ns:v', $c_node)->item(0);
            $raw_v  = $v_node ? $v_node->textContent : null;

            if ($raw_v === null) {
                $value = null;
            } elseif ($type === 's') {
                $value = $shared_strings[intval($raw_v)] ?? null;
            } elseif ($type === 'str') {
                $value = $raw_v;
            } else {
                $value = is_numeric($raw_v) ? floatval($raw_v) : $raw_v;
            }
            $cells[$col_index] = $value;
        }
        $result[$row_num] = $cells;
    }

    return $result;
}

// --------------------------------------------------
// JPXのExcel日付セルは文字列("2026/8")の場合と、シリアル値(数値)の場合があるため両対応
// シリアル値の基準日は1899-12-30（Excelの1900年日付システムのうるう年バグ込みで補正済みの起点）
// --------------------------------------------------
function wp_stocks_jpx_cell_to_year_month($value) {
    if (is_string($value)) {
        if (preg_match('/^(\\d{4})[\\/\\-](\\d{1,2})$/', trim($value), $m)) {
            return $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
        }
        return null;
    }
    if (is_numeric($value)) {
        $date = new DateTime('1899-12-30');
        $date->modify('+' . intval($value) . ' days');
        return $date->format('Y-m');
    }
    return null;
}

function wp_stocks_jpx_extract_sector_per_rows($file_path, $year_month_override = null) {
    $rows = wp_stocks_jpx_parse_sheet_by_name($file_path, '規模別・業種別（連結）');
    if ($rows === false) return false;

    $target_markets = ['プライム市場', 'スタンダード市場', 'グロース市場'];
    $sector_name_fixes = [
        '証券、商品先物取引業' => '証券・商品先物取引業',
    ];

    $extracted = [];
    foreach ($rows as $row_num => $cells) {
        if ($row_num <= 4) continue;

        $market     = $cells[1] ?? null;
        $sector_raw = $cells[3] ?? null;
        $year_month = $year_month_override ?? wp_stocks_jpx_cell_to_year_month($cells[0] ?? null);

        if (!in_array($market, $target_markets, true)) continue;
        if (!is_string($sector_raw) || !preg_match('/^\\d+\\s*(.+)$/u', trim($sector_raw), $m)) continue;

        $sector = trim($m[1]);
        $sector = $sector_name_fixes[$sector] ?? $sector;

        $extracted[] = [
            'year_month'    => $year_month,
            'market'        => $market,
            'sector'        => $sector,
            'company_count' => is_numeric($cells[5] ?? null) ? intval($cells[5]) : null,
            'simple_per'    => is_numeric($cells[6] ?? null) ? floatval($cells[6]) : null,
            'simple_pbr'    => is_numeric($cells[7] ?? null) ? floatval($cells[7]) : null,
            'weighted_per'  => is_numeric($cells[10] ?? null) ? floatval($cells[10]) : null,
            'weighted_pbr'  => is_numeric($cells[11] ?? null) ? floatval($cells[11]) : null,
        ];
    }

    return $extracted;
}

function wp_stocks_jpx_import_sector_per($rows) {
    global $wpdb;
    if (empty($rows)) return 0;
    $saved = 0;
    foreach ($rows as $r) {
        if (empty($r['year_month']) || empty($r['market']) || empty($r['sector'])) continue;
        $market_normalized = str_replace('市場', '', $r['market']);
        $result = $wpdb->replace($wpdb->prefix . 'stock_jpx_sector_per', [
            'report_month'   => $r['year_month'],
            'market_segment' => $market_normalized,
            'sector'         => $r['sector'],
            'company_count'  => $r['company_count'],
            'simple_per'     => $r['simple_per'],
            'simple_pbr'     => $r['simple_pbr'],
            'weighted_per'   => $r['weighted_per'],
            'weighted_pbr'   => $r['weighted_pbr'],
        ]);
        if ($result !== false) $saved++;
    }
    return $saved;
}

function wp_stocks_jpx_sync_sector_per() {
    $found = wp_stocks_jpx_find_latest_xlsx_url();
    if (!$found) return false;

    $tmp_file = wp_stocks_jpx_download_xlsx($found['url']);
    if (!$tmp_file) return false;

    $rows = wp_stocks_jpx_extract_sector_per_rows($tmp_file, $found['year_month']);
    @unlink($tmp_file);

    if ($rows === false || empty($rows)) {
        wp_stocks_log('error', 'jpx_sector_per', 'ALL', 'xlsxの解析結果が空でした: ' . $found['url']);
        return false;
    }

    $saved = wp_stocks_jpx_import_sector_per($rows);
    wp_stocks_log('info', 'jpx_sector_per', 'ALL', 'JPX業種別PERを取り込みました（' . $found['year_month'] . '分、' . $saved . '件、抽出' . count($rows) . '件）');
    return $saved;
}

function wp_stocks_get_jpx_sector_per($sector, $market) {
    global $wpdb;
    if (empty($sector) || empty($market)) return null;
    $market_normalized = str_replace('市場', '', $market);
    $per = $wpdb->get_var($wpdb->prepare(
        "SELECT simple_per FROM {$wpdb->prefix}stock_jpx_sector_per
         WHERE sector = %s AND market_segment = %s AND simple_per IS NOT NULL
         ORDER BY report_month DESC LIMIT 1",
        $sector, $market_normalized
    ));
    return $per !== null ? floatval($per) : null;
}

function wp_stocks_calc_score($stock) {
    $score  = 0;
    $detail = [];

    $per = floatval($stock->per ?? 0);
    if ($per > 0 && $per < 15)       { $score += 20; $detail['PER'] = ['点数' => 20, '評価' => '割安']; }
    elseif ($per >= 15 && $per < 25) { $score += 10; $detail['PER'] = ['点数' => 10, '評価' => '適正']; }
    elseif ($per >= 25)              { $score +=  0; $detail['PER'] = ['点数' =>  0, '評価' => '割高']; }
    else                             { $detail['PER'] = ['点数' => 0, '評価' => 'N/A']; }

    $pbr = floatval($stock->pbr ?? 0);
    if ($pbr > 0 && $pbr < 1)      { $score += 15; $detail['PBR'] = ['点数' => 15, '評価' => '割安']; }
    elseif ($pbr >= 1 && $pbr < 3) { $score += 8;  $detail['PBR'] = ['点数' =>  8, '評価' => '適正']; }
    elseif ($pbr >= 3)             { $score += 0;  $detail['PBR'] = ['点数' =>  0, '評価' => '割高']; }
    else                           { $detail['PBR'] = ['点数' => 0, '評価' => 'N/A']; }

    $roe = floatval($stock->roe ?? 0);
    if ($roe >= 15)      { $score += 20; $detail['ROE'] = ['点数' => 20, '評価' => '優良']; }
    elseif ($roe >= 10) { $score += 12; $detail['ROE'] = ['点数' => 12, '評価' => '良好']; }
    elseif ($roe >= 5)  { $score +=  5; $detail['ROE'] = ['点数' =>  5, '評価' => '普通']; }
    else                { $score +=  0; $detail['ROE'] = ['点数' =>  0, '評価' => '低い']; }

    $eq = floatval($stock->equity_ratio ?? 0);
    if ($eq >= 50)      { $score += 15; $detail['自己資本比率'] = ['点数' => 15, '評価' => '安全']; }
    elseif ($eq >= 30) { $score +=  8; $detail['自己資本比率'] = ['点数' =>  8, '評価' => '普通']; }
    elseif ($eq > 0)   { $score +=  2; $detail['自己資本比率'] = ['点数' =>  2, '評価' => '注意']; }
    else               { $detail['自己資本比率'] = ['点数' => 0, '評価' => 'N/A']; }

    $div = floatval($stock->dividend_yield ?? 0);
    if ($div >= 3)      { $score += 10; $detail['配当利回り'] = ['点数' => 10, '評価' => '高配当']; }
    elseif ($div >= 1) { $score +=  5; $detail['配当利回り'] = ['点数' =>  5, '評価' => '普通']; }
    else               { $score +=  0; $detail['配当利回り'] = ['点数' =>  0, '評価' => '低い/なし']; }

    $peg = floatval($stock->peg ?? 0);
    if ($peg > 0 && $peg < 1)      { $score += 10; $detail['PEG'] = ['点数' => 10, '評価' => '割安成長']; }
    elseif ($peg >= 1 && $peg < 2) { $score +=  5; $detail['PEG'] = ['点数' =>  5, '評価' => '適正']; }
    elseif ($peg >= 2)             { $score +=  0; $detail['PEG'] = ['点数' =>  0, '評価' => '割高']; }
    else                           { $detail['PEG'] = ['点数' => 0, '評価' => 'N/A']; }

    $pm = floatval($stock->profit_margin ?? 0);
    if ($pm >= 15)      { $score += 10; $detail['利益率'] = ['点数' => 10, '評価' => '優良']; }
    elseif ($pm >= 10) { $score +=  7; $detail['利益率'] = ['点数' =>  7, '評価' => '良好']; }
    elseif ($pm >= 5)  { $score +=  3; $detail['利益率'] = ['点数' =>  3, '評価' => '普通']; }
    else               { $score +=  0; $detail['利益率'] = ['点数' =>  0, '評価' => '低い']; }

    if ($score >= 70)     $judgment = ['label' => '買い候補', 'color' => '#27ae60'];
    elseif ($score >= 50) $judgment = ['label' => '中立',     'color' => '#f39c12'];
    else                  $judgment = ['label' => '要注意',   'color' => '#e74c3c'];

    return ['score' => $score, 'judgment' => $judgment, 'detail' => $detail];
}

function wp_stocks_score_card_html($stock) {
    $result   = wp_stocks_calc_score($stock);
    $score    = $result['score'];
    $judgment = $result['judgment'];
    $detail   = $result['detail'];

    ob_start();
    echo '<div style="background:#fff;border:2px solid ' . $judgment['color'] . ';border-radius:8px;padding:20px;margin-bottom:25px;">';
    echo '<div style="display:flex;align-items:center;gap:20px;margin-bottom:15px;">';
    echo '<div style="text-align:center;"><div style="font-size:48px;font-weight:bold;color:' . $judgment['color'] . ';">' . $score . '</div><div style="font-size:12px;color:#888;">/ 100点</div></div>';
    echo '<div><div style="font-size:22px;font-weight:bold;color:' . $judgment['color'] . ';">' . $judgment['label'] . '</div><div style="font-size:12px;color:#888;margin-top:4px;">財務スコアカード</div></div>';
    echo '</div>';
    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="background:#f8f9fa;">';
    echo '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">指標</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">点数</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">評価</th>';
    echo '</tr></thead><tbody>';
    foreach ($detail as $name => $d) {
        $c = $d['点数'] >= 10 ? '#27ae60' : ($d['点数'] >= 5 ? '#f39c12' : '#e74c3c');
        if ($d['評価'] === 'N/A') $c = '#888';
        echo '<tr><td style="padding:5px 10px;border:1px solid #ddd;">' . esc_html($name) . '</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;color:' . $c . ';">' . $d['点数'] . '点</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;color:' . $c . ';">' . esc_html($d['評価']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    return ob_get_clean();
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
// EDINET 財務データ取得
// --------------------------------------------------
// --------------------------------------------------
// EDINETコードリストCSV(EdinetcodeDlInfo.csv)をパースし、
// ['edinet' => [証券コード => EDINETコード], 'decdate' => [証券コード => 決算日文字列]]
// を返す。決算日は有価証券報告書の提出期限(決算日の3ヶ月後)を逆算し、
// docID検索の日付範囲を大幅に絞り込むために使用する。
// --------------------------------------------------
function wp_stocks_parse_edinet_codelist_csv($csv_content) {
    // ファイルはCP932(Shift-JIS)エンコードで配布されている
    $text  = @mb_convert_encoding($csv_content, 'UTF-8', 'SJIS-win');
    if (empty($text)) $text = $csv_content;
    $lines = preg_split('/\r\n|\r|\n/', $text);

    $edinet_map   = [];
    $decdate_map  = [];
    $header_found = false;
    $dec_date_col_index = null;
    $sec_code_col_index = null;
    $sample_logged = false;
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cols = str_getcsv($line);
        if (!$header_found) {
            // 1行目はダウンロード実行日等のメタ情報、2行目が本来のヘッダー行
            if (isset($cols[0]) && mb_strpos($cols[0], 'ＥＤＩＮＥＴコード') !== false) {
                $header_found = true;
                // ヘッダー内から「決算日」「証券コード」を含む列を自動検出
                // （決め打ちの列番号がズレていても対応できるように）
                foreach ($cols as $idx => $col_name) {
                    if ($dec_date_col_index === null && mb_strpos($col_name, '決算日') !== false) {
                        $dec_date_col_index = $idx;
                    }
                    if ($sec_code_col_index === null && mb_strpos($col_name, '証券コード') !== false) {
                        $sec_code_col_index = $idx;
                    }
                }
                wp_stocks_log('info', 'edinet_codelist', 'ALL', 'CSVヘッダー(' . count($cols) . '列): ' . implode(' | ', $cols) . ' / 決算日列インデックス=' . ($dec_date_col_index !== null ? $dec_date_col_index : '見つからず(5番目にフォールバック)') . ' / 証券コード列インデックス=' . ($sec_code_col_index !== null ? $sec_code_col_index : '見つからず(11番目にフォールバック)'));
            }
            continue;
        }
        $dec_col  = $dec_date_col_index !== null ? $dec_date_col_index : 5;
        $sec_col  = $sec_code_col_index !== null ? $sec_code_col_index : 11;
        $edinet_code = trim($cols[0] ?? '');
        $dec_date    = trim($cols[$dec_col] ?? '');
        $sec_code    = trim($cols[$sec_col] ?? '');
        if (!$sample_logged) {
            wp_stocks_log('info', 'edinet_codelist', 'ALL', 'サンプル行: edinetCode=' . $edinet_code . ', decDate(idx=' . $dec_col . ')=' . $dec_date . ', secCode(idx=' . $sec_col . ')=' . $sec_code);
            $sample_logged = true;
        }
        if ($edinet_code === '' || $sec_code === '') continue;
        $edinet_map[$sec_code] = $edinet_code;
        if ($dec_date !== '') $decdate_map[$sec_code] = $dec_date;
    }
    return ['edinet' => $edinet_map, 'decdate' => $decdate_map];
}

function wp_stocks_get_edinet_code($code) {
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare(
        "SELECT id, edinet_code FROM {$wpdb->prefix}stocks WHERE code = %s", $code
    ));
    if (!$stock) return false;
    if (!empty($stock->edinet_code)) return ['id' => $stock->id, 'edinet_code' => $stock->edinet_code];

    $api_key = get_option('wp_stocks_edinet_api_key', '');
    if (empty($api_key)) {
        wp_stocks_log('error', 'get_edinet_code', $code, 'EDINET APIキーが設定されていません');
        return false;
    }

    $sec_code = $code . '0';

    // 事前にアップロードされたEDINETコードリスト(CSV)があれば最優先で参照する。
    // これが見つかれば、以下の総当たり日付検索(APIを何十回も叩く処理)を
    // 完全にスキップでき、高速かつ確実にEDINETコードを解決できる。
    $edinet_codelist = get_option('wp_stocks_edinet_codelist', []);
    if (is_array($edinet_codelist) && isset($edinet_codelist[$sec_code])) {
        $found_from_list = $edinet_codelist[$sec_code];
        $wpdb->update($wpdb->prefix . 'stocks', ['edinet_code' => $found_from_list], ['id' => $stock->id]);
        wp_stocks_log('info', 'get_edinet_code', $code, 'EDINETコードリストのキャッシュから解決しました: ' . $found_from_list);
        return ['id' => $stock->id, 'edinet_code' => $found_from_list];
    }

    $found = '';
    $search_dates2 = [];
    for ($m = 0; $m <= 18; $m++) {
        $ts = mktime(0, 0, 0, date('n') - $m, 15, date('Y'));
        foreach ([25, 20, 15] as $day) {
            $search_dates2[] = date('Y-m', $ts) . '-' . sprintf('%02d', $day);
        }
    }
    $search_dates2 = array_unique($search_dates2);
    foreach ($search_dates2 as $date) {
        $url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents.json?date={$date}&type=2&Subscription-Key=" . urlencode($api_key);
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        foreach (($body['results'] ?? []) as $doc) {
            if (($doc['secCode'] ?? '') === $sec_code && ($doc['docTypeCode'] ?? '') === '120') {
                $found = $doc['edinetCode'] ?? '';
                break 2;
            }
        }
    }
    if ($found) {
        $wpdb->update($wpdb->prefix . 'stocks', ['edinet_code' => $found], ['id' => $stock->id]);
        return ['id' => $stock->id, 'edinet_code' => $found];
    }
    wp_stocks_log('error', 'get_edinet_code', $code, 'EDINETコードが見つかりませんでした（secCode=' . $sec_code . ', 検索日数=' . count($search_dates2) . '件, 検索範囲=過去18ヶ月の15/20/25日のみ）');
    return false;
}

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

// --------------------------------------------------
// EDINET半期報告書の診断取得（ZIP内ファイル一覧とCSV内容をログ出力）
// --------------------------------------------------
function wp_stocks_diagnose_half_year_report($stock_id, $code) {
    global $wpdb;
    $api_key = get_option('wp_stocks_edinet_api_key', '');
    if (empty($api_key)) {
        wp_stocks_log('error', 'diag_half_year', $code, 'EDINET APIキーが設定されていません');
        return false;
    }

    $edinet_info = wp_stocks_get_edinet_code($code);
    if (!$edinet_info) {
        wp_stocks_log('error', 'diag_half_year', $code, 'EDINETコードの取得に失敗');
        return false;
    }
    $edinet_code = $edinet_info['edinet_code'];

    $doc_id      = '';
    $sec_code    = $code . '0';
    $decdate_map = get_option('wp_stocks_edinet_decdate_map', []);
    $search_dates = [];

    if (is_array($decdate_map) && isset($decdate_map[$sec_code])
        && preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $decdate_map[$sec_code], $dm)) {
        // 本決算日から6ヶ月引いた「中間決算日」を基準に、その1.5ヶ月後（約45日後）
        // 前後を1日単位で探索する（本決算の提出期限探索ロジックを半期用に転用）
        $fy_month = intval($dm[1]);
        $fy_day   = intval($dm[2]);
        $today_ts = strtotime('today');
        for ($y = 0; $y <= 2; $y++) {
            $fy_year        = intval(date('Y')) - $y;
            $fy_end_ts      = mktime(0, 0, 0, $fy_month, $fy_day, $fy_year);
            $half_end_ts    = strtotime('-6 months', $fy_end_ts);
            $deadline_ts    = strtotime('+45 days', $half_end_ts);
            // 提出期限の30日前〜30日後を1日ずつ（早期提出・遅延両対応）
            for ($offset = -30; $offset <= 30; $offset++) {
                $d_ts = strtotime("{$offset} days", $deadline_ts);
                if ($d_ts > $today_ts) continue;
                $search_dates[] = date('Y-m-d', $d_ts);
            }
        }
        $search_dates = array_unique($search_dates);
        rsort($search_dates);
    } else {
        // 決算日が不明な場合は過去24ヶ月を1日単位で総当たり（時間はかかるが確実）
        $start_ts = strtotime('-24 months');
        $end_ts   = strtotime('today');
        for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 day', $ts)) {
            $search_dates[] = date('Y-m-d', $ts);
        }
        rsort($search_dates);
    }

    foreach ($search_dates as $date) {
        if (!empty($doc_id)) break;
        $url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents.json?date={$date}&type=2&Subscription-Key=" . urlencode($api_key);
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        foreach (($body['results'] ?? []) as $doc) {
            if (($doc['edinetCode'] ?? '') === $edinet_code && ($doc['docTypeCode'] ?? '') === '160') {
                $doc_id = $doc['docID'];
                break;
            }
        }
    }
    if (empty($doc_id)) {
        wp_stocks_log('error', 'diag_half_year', $code, '半期報告書のdocIDが見つかりませんでした（edinetCode=' . $edinet_code . ', 検索日数=' . count($search_dates) . '件）');
        return false;
    }
    wp_stocks_log('info', 'diag_half_year', $code, '半期報告書のdocIDを発見しました: docID=' . $doc_id);

    $zip_url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents/{$doc_id}?type=5&Subscription-Key=" . urlencode($api_key);
    $zip_res = wp_remote_get($zip_url, ['timeout' => 60]);
    if (is_wp_error($zip_res)) {
        wp_stocks_log('error', 'diag_half_year', $code, 'ZIPダウンロード失敗(通信エラー): ' . $zip_res->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($zip_res) !== 200) {
        wp_stocks_log('error', 'diag_half_year', $code, 'ZIPダウンロード失敗: HTTP ' . wp_remote_retrieve_response_code($zip_res));
        return false;
    }
    $zip_data = wp_remote_retrieve_body($zip_res);
    $tmp_file = sys_get_temp_dir() . '/edinet_half_' . $doc_id . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $file_names  = [];
    $csv_content = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $file_names[] = $name;
                if (empty($csv_content) && strpos($name, '.csv') !== false && strpos($name, 'jpcrp040300-ssr') !== false) {
                    $csv_content = $zip->getFromIndex($i);
                }
            }
            $zip->close();
        }
    }
    if (empty($file_names) && class_exists('PharData')) {
        try {
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $path = $file->getPathname();
                $file_names[] = $path;
                if (empty($csv_content) && strpos($path, '.csv') !== false && strpos($path, 'jpcrp040300-ssr') !== false) {
                    $csv_content = file_get_contents($path);
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', 'diag_half_year', $code, 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }
    unlink($tmp_file);

    wp_stocks_log('info', 'diag_half_year', $code, 'ZIP内ファイル一覧（' . count($file_names) . '件）: ' . implode(' | ', array_slice($file_names, 0, 30)));

    if (empty($csv_content)) {
        wp_stocks_log('error', 'diag_half_year', $code, 'CSVファイルが見つかりませんでした（PublicDoc配下の.csvが無い可能性）');
        return false;
    }

    $text = mb_convert_encoding($csv_content, 'UTF-8', 'UTF-16LE');
    wp_stocks_log('info', 'diag_half_year', $code, 'CSV先頭2000文字: ' . substr($text, 0, 2000));

    $lines = explode("\n", $text);
    $sample_lines = [];
    foreach ($lines as $line) {
        if (strpos($line, 'SummaryOfBusinessResults') !== false || strpos($line, 'NetSales') !== false || strpos($line, 'OrdinaryIncome') !== false) {
            $sample_lines[] = $line;
            if (count($sample_lines) >= 15) break;
        }
    }
    wp_stocks_log('info', 'diag_half_year', $code, 'SummaryOfBusinessResults関連行サンプル（' . count($sample_lines) . '件）: ' . implode(' || ', $sample_lines));

    return true;
}

// --------------------------------------------------
// EDINET半期報告書から当中間期の売上高・純利益を解析してDB保存
// --------------------------------------------------
function wp_stocks_fetch_half_year_financials($stock_id, $code) {
    global $wpdb;
    $api_key = get_option('wp_stocks_edinet_api_key', '');
    if (empty($api_key)) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'EDINET APIキーが設定されていません');
        return false;
    }

    $edinet_info = wp_stocks_get_edinet_code($code);
    if (!$edinet_info) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'EDINETコードの取得に失敗');
        return false;
    }
    $edinet_code = $edinet_info['edinet_code'];

    $doc_id      = '';
    $sec_code    = $code . '0';
    $decdate_map = get_option('wp_stocks_edinet_decdate_map', []);
    $search_dates = [];

    if (is_array($decdate_map) && isset($decdate_map[$sec_code])
        && preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $decdate_map[$sec_code], $dm)) {
        $fy_month = intval($dm[1]);
        $fy_day   = intval($dm[2]);
        $today_ts = strtotime('today');
        for ($y = 0; $y <= 2; $y++) {
            $fy_year     = intval(date('Y')) - $y;
            $fy_end_ts   = mktime(0, 0, 0, $fy_month, $fy_day, $fy_year);
            $half_end_ts = strtotime('-6 months', $fy_end_ts);
            $deadline_ts = strtotime('+45 days', $half_end_ts);
            for ($offset = -30; $offset <= 30; $offset++) {
                $d_ts = strtotime("{$offset} days", $deadline_ts);
                if ($d_ts > $today_ts) continue;
                $search_dates[] = date('Y-m-d', $d_ts);
            }
        }
        $search_dates = array_unique($search_dates);
        rsort($search_dates);
    } else {
        $start_ts = strtotime('-24 months');
        $end_ts   = strtotime('today');
        for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 day', $ts)) {
            $search_dates[] = date('Y-m-d', $ts);
        }
        rsort($search_dates);
    }

    foreach ($search_dates as $date) {
        if (!empty($doc_id)) break;
        $url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents.json?date={$date}&type=2&Subscription-Key=" . urlencode($api_key);
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        foreach (($body['results'] ?? []) as $doc) {
            if (($doc['edinetCode'] ?? '') === $edinet_code && ($doc['docTypeCode'] ?? '') === '160') {
                $doc_id = $doc['docID'];
                break;
            }
        }
    }
    if (empty($doc_id)) {
        wp_stocks_log('error', 'fetch_half_year', $code, '半期報告書のdocIDが見つかりませんでした（edinetCode=' . $edinet_code . '）');
        return false;
    }

    $zip_url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents/{$doc_id}?type=5&Subscription-Key=" . urlencode($api_key);
    $zip_res = wp_remote_get($zip_url, ['timeout' => 60]);
    if (is_wp_error($zip_res) || wp_remote_retrieve_response_code($zip_res) !== 200) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'ZIPダウンロード失敗');
        return false;
    }
    $zip_data = wp_remote_retrieve_body($zip_res);
    $tmp_file = sys_get_temp_dir() . '/edinet_half_' . $doc_id . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $csv_content   = '';
    $matched_name  = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, '.csv') !== false && strpos($name, 'jpcrp040300-ssr') !== false) {
                    $csv_content  = $zip->getFromIndex($i);
                    $matched_name = $name;
                    break;
                }
            }
            $zip->close();
        }
    }
    if (empty($csv_content) && class_exists('PharData')) {
        try {
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $path = $file->getPathname();
                if (strpos($path, '.csv') !== false && strpos($path, 'jpcrp040300-ssr') !== false) {
                    $csv_content  = file_get_contents($path);
                    $matched_name = $path;
                    break;
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', 'fetch_half_year', $code, 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }
    unlink($tmp_file);

    if (empty($csv_content)) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'CSVファイルが見つかりませんでした');
        return false;
    }

    // ファイル名末尾の日付（期末日）を抽出。例: ..._2025-09-30_01_2025-11-13.csv
    $period_end = null;
    if (preg_match('/_(\d{4}-\d{2}-\d{2})_\d+_\d{4}-\d{2}-\d{2}\.csv$/', $matched_name, $pm)) {
        $period_end = $pm[1];
    }
    if (!$period_end) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'ファイル名から期末日を抽出できませんでした: ' . $matched_name);
        return false;
    }

    $text  = mb_convert_encoding($csv_content, 'UTF-8', 'UTF-16LE');
    $lines = explode("\n", $text);

    $revenue    = null;
    $net_income = null;
    foreach ($lines as $line) {
        if (strpos($line, 'InterimDuration') === false) continue;
        $cols = str_getcsv($line, "\t", '"');
        if (count($cols) < 9) continue;
        $element_id = $cols[0];
        $context_id = $cols[2];
        $value      = $cols[8];
        if ($context_id !== 'InterimDuration') continue;
        if ($revenue === null
            && (strpos($element_id, 'NetSales') !== false || strpos($element_id, 'OperatingRevenues') !== false)
            && (strpos($element_id, 'SummaryOfBusinessResults') !== false || strpos($element_id, 'KeyFinancialData') !== false)) {
            $revenue = intval($value);
        }
        if ($net_income === null
            && (strpos($element_id, 'ProfitLossAttributableToOwnersOfParent') !== false || strpos($element_id, 'NetIncome') !== false)
            && strpos($element_id, 'SummaryOfBusinessResults') !== false) {
            $net_income = intval($value);
        }
    }

    if ($revenue === null && $net_income === null) {
        wp_stocks_log('error', 'fetch_half_year', $code, '売上高・純利益のタグが見つかりませんでした（会計基準の違いでタグ名が異なる可能性）。docID=' . $doc_id);
        return false;
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND period_end = %s AND source = 'edinet_half'",
        $stock_id, $period_end
    ));
    $data = ['stock_id' => $stock_id, 'period_end' => $period_end, 'revenue' => $revenue, 'net_income' => $net_income, 'source' => 'edinet_half'];
    if ($existing) {
        $wpdb->update($wpdb->prefix . 'stock_quarterly_financials', $data, ['id' => $existing]);
    } else {
        $wpdb->insert($wpdb->prefix . 'stock_quarterly_financials', $data);
    }

    wp_stocks_log('info', 'fetch_half_year', $code, '半期データを保存しました（期末日=' . $period_end . ', 売上高=' . ($revenue ?? '-') . ', 純利益=' . ($net_income ?? '-') . '）');
    return true;
}

// --------------------------------------------------
// EDINET: ZIPをダウンロードし、ファイル名にfilename_must_containを含む
// CSVを抽出する共通ヘルパー（ZipArchive優先、失敗時はPharDataにフォールバック）
// 戻り値: ['content' => CSV文字列, 'matched_name' => ZIP内のファイルパス] または false
// --------------------------------------------------
function wp_stocks_edinet_extract_csv_from_zip($doc_id, $api_key, $filename_must_contain, $log_action = 'edinet_extract_zip', $log_symbol = '') {
    $zip_url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents/{$doc_id}?type=5&Subscription-Key=" . urlencode($api_key);
    $zip_res = wp_remote_get($zip_url, ['timeout' => 60]);
    if (is_wp_error($zip_res)) {
        wp_stocks_log('error', $log_action, $log_symbol, 'ZIPダウンロード失敗(通信エラー): ' . $zip_res->get_error_message() . ' docID=' . $doc_id);
        return false;
    }
    if (wp_remote_retrieve_response_code($zip_res) !== 200) {
        wp_stocks_log('error', $log_action, $log_symbol, 'ZIPダウンロード失敗: HTTP ' . wp_remote_retrieve_response_code($zip_res) . ' docID=' . $doc_id);
        return false;
    }

    $zip_data = wp_remote_retrieve_body($zip_res);
    $tmp_file = sys_get_temp_dir() . '/edinet_' . $doc_id . '_' . uniqid() . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $csv_content  = '';
    $matched_name = '';

    // ext-zip (ZipArchive) が有効な環境ではこちらを優先して使用
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, '.csv') !== false && strpos($name, $filename_must_contain) !== false) {
                    $csv_content  = $zip->getFromIndex($i);
                    $matched_name = $name;
                    break;
                }
            }
            $zip->close();
        }
    }

    // ext-zipが無効な環境(Synology DSM等)向けフォールバック:
    // Phar拡張(多くの環境で標準有効)のPharDataクラスはext-zip無しでもZIPを読み込める
    if (empty($csv_content) && class_exists('PharData')) {
        try {
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $path = $file->getPathname();
                if (strpos($path, '.csv') !== false && strpos($path, $filename_must_contain) !== false) {
                    $csv_content  = file_get_contents($path);
                    $matched_name = $path;
                    break;
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', $log_action, $log_symbol, 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }

    unlink($tmp_file);
    if (empty($csv_content)) {
        wp_stocks_log('error', $log_action, $log_symbol, 'ZIPからCSVを抽出できませんでした（ext-zip, PharDataいずれも利用不可か、対象ファイルが見つかりません）docID=' . $doc_id);
        return false;
    }
    wp_stocks_log('info', $log_action, $log_symbol, 'ZIPからCSVを抽出しました（' . strlen($csv_content) . 'バイト）docID=' . $doc_id);

    return ['content' => $csv_content, 'matched_name' => $matched_name];
}

/**
 * Webull OpenAPI 署名生成ヘルパー
 * 公式ドキュメント(developer.webull.co.jp/apis/docs/authentication/signature)の
 * 検証用サンプルと一致することを確認済みのロジック。
 *
 * @param string $method       'GET' | 'POST' など(署名自体には未使用だが呼び出し側の整理用)
 * @param string $path         リクエストパス(クエリを含まない)
 * @param array  $query_params クエリパラメータの連想配列(urlencodeしない生の値)
 * @param string $body         POSTボディの生JSON文字列。GETや空ボディなら ''
 * @param string $host         例: 'api.webull.co.jp'
 * @return array ['headers' => [...送信すべき全ヘッダー...]]
 */
function wp_stocks_webull_sign_request($method, $path, $query_params, $body, $host, $api_version = 'v2', $algorithm = 'HMAC-SHA1') {
    $app_key    = get_option('wp_stocks_webull_app_key', '');
    $app_secret = get_option('wp_stocks_webull_app_secret', '');

    $timestamp = gmdate('Y-m-d\TH:i:s\Z'); // UTC, RFC3339
    $nonce     = bin2hex(random_bytes(16));
    $version   = '1.0';

    $sign_headers = array(
        'host'                  => $host,
        'x-app-key'             => $app_key,
        'x-signature-algorithm' => $algorithm,
        'x-signature-nonce'     => $nonce,
        'x-signature-version'   => $version,
        'x-timestamp'           => $timestamp,
    );

    $map = array_merge($query_params, $sign_headers);
    ksort($map, SORT_STRING);

    $pairs = array();
    foreach ($map as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $s1 = implode('&', $pairs);

    if ($body === '' || $body === null) {
        $s3 = $path . '&' . $s1;
    } else {
        $s2 = strtoupper(md5($body));
        $s3 = $path . '&' . $s1 . '&' . $s2;
    }

    $sign_key  = $app_secret . '&';
    // 署名対象文字列(s3)はRFC3986準拠でURLエンコードしてからHMACに渡す必要がある
    // (公式ドキュメントの確定仕様。ここが抜けていたのが401エラーの根本原因だった)
    $encoded_sign_string = rawurlencode($s3);
    $signature = base64_encode(hash_hmac(strtolower(str_replace('HMAC-', '', $algorithm)), $encoded_sign_string, $sign_key, true));

    return array(
        'headers' => array(
            'x-app-key'             => $app_key,
            'x-timestamp'           => $timestamp,
            'x-signature-algorithm' => $algorithm,
            'x-signature-version'   => $version,
            'x-signature-nonce'     => $nonce,
            'x-version'             => $api_version,
            'x-signature'           => $signature,
            'host'                  => $host,
        ),
    );
}

/**
 * Webullの認証エラー（App Secret期限切れ等）を検知した際に、
 * 最終発生日時と詳細を記録するヘルパー。
 * HTTP 401/403、またはレスポンス本文に認証エラーを示すキーワードが
 * 含まれる場合に「認証エラーの可能性あり」として記録する。
 *
 * @param int    $status_code
 * @param string $raw_body
 * @param string $context ログ・保存用の識別子（例: 'webull_daily_bars:AAPL'）
 * @return bool 認証エラーと判定したかどうか
 */
function wp_stocks_webull_maybe_flag_auth_error($status_code, $raw_body, $context) {
    $status_code   = intval($status_code);
    $is_auth_error = ($status_code === 401 || $status_code === 403);
    if (!$is_auth_error && !empty($raw_body)) {
        if (preg_match('/(invalid[_ ]?signature|unauthoriz|expired|invalid[_ ]?app[_ ]?secret|invalid[_ ]?key)/i', (string) $raw_body)) {
            $is_auth_error = true;
        }
    }
    if ($is_auth_error) {
        update_option('wp_stocks_webull_last_auth_error_at', current_time('mysql'));
        update_option('wp_stocks_webull_last_auth_error_detail', $context . ' / HTTP ' . $status_code . ' / ' . substr((string) $raw_body, 0, 200));
        wp_stocks_log('error', 'webull_auth_error', $context, 'Webull認証エラーの可能性を検知しました（App Secret期限切れ等）：HTTP ' . $status_code);
    }
    return $is_auth_error;
}

/**
 * Webullへのリクエストが成功した際に、認証エラーの記録をクリアする。
 */
function wp_stocks_webull_clear_auth_error() {
    if (get_option('wp_stocks_webull_last_auth_error_at', '') !== '') {
        delete_option('wp_stocks_webull_last_auth_error_at');
        delete_option('wp_stocks_webull_last_auth_error_detail');
    }
}

/**
 * Webullの日足バッチ取得APIから指定銘柄の日足を取得し、
 * Yahoo側 wp_stocks_get_ohlcv() と同じ形式（date/open/high/low/close/volume）の配列で返す。
 * 失敗時は false を返す。
 *
 * @param string $symbol   例: 'AAPL', '7203'
 * @param string $category 例: 'US_STOCK', 'JP_STOCK'
 * @param int    $count    取得件数（デフォルト30、最大1200）
 * @return array|false
 */
function wp_stocks_webull_fetch_daily_bars($symbol, $category, $count = 30) {
    $host = 'api.webull.co.jp';
    $path = '/market-data/stocks/bars/list';
    $body = wp_json_encode(array(
        'symbols'            => array($symbol),
        'category'           => $category,
        'timespan'           => 'D',
        'count'              => intval($count),
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

    if (is_wp_error($response)) {
        wp_stocks_log('error', 'webull_daily_bars', $symbol, '通信エラー: ' . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);
    if ($status_code !== 200) {
        wp_stocks_webull_maybe_flag_auth_error($status_code, $raw_body, 'webull_daily_bars:' . $symbol);
        wp_stocks_log('error', 'webull_daily_bars', $symbol, 'HTTPエラー: ' . $status_code . ' / ' . substr($raw_body, 0, 300));
        return false;
    }
    wp_stocks_webull_clear_auth_error();

    $decoded = json_decode($raw_body, true);
    $bars    = $decoded['result'][0]['result'] ?? null;
    if (!is_array($bars) || empty($bars)) {
        wp_stocks_log('error', 'webull_daily_bars', $symbol, 'レスポンスに日足データが含まれていません: ' . substr($raw_body, 0, 300));
        return false;
    }

    $data = array();
    foreach ($bars as $bar) {
        // 'time' は例: "2026-09-11T04:00:00.000+0000" 形式。
        // 日付部分（先頭10文字）がそのまま取引日ラベルに対応しているため、そのまま使用する。
        $date = substr($bar['time'] ?? '', 0, 10);
        if (empty($date)) continue;
        $data[] = array(
            'date'   => $date,
            'open'   => floatval($bar['open']   ?? 0),
            'high'   => floatval($bar['high']   ?? 0),
            'low'    => floatval($bar['low']    ?? 0),
            'close'  => floatval($bar['close']  ?? 0),
            'volume' => intval($bar['volume']   ?? 0),
        );
    }

    // Webullは新しい順で返ってくるため、Yahoo側（古い順）に合わせて昇順に並び替える
    usort($data, function($a, $b) { return strcmp($a['date'], $b['date']); });

    return $data;
}

/**
 * 複数銘柄の日足をWebullバッチAPIからcurl_multiで並列取得する。
 * 1リクエストにつき$chunk_size銘柄まで詰め込み、$concurrency件ずつ並行送信、
 * ラウンド間に$wait_between_rounds秒のウェイトを挟む。
 *
 * @param array  $symbols             取得したい銘柄コードの配列（同一categoryのみ対応）
 * @param string $category            例: 'US_STOCK'
 * @param int    $count               1銘柄あたりの取得件数
 * @param int    $chunk_size          1リクエストに詰める銘柄数（Webull上限は100だが安全のため既定20）
 * @param int    $concurrency         curl_multiで同時に投げるリクエスト数
 * @param int    $wait_between_rounds ラウンド間のウェイト秒数
 * @return array [$symbol => [['date'=>..,'open'=>..,'high'=>..,'low'=>..,'close'=>..,'volume'=>..], ...]]
 *               取得できなかった銘柄はキーごと含まれない。
 */
function wp_stocks_webull_daily_bars_parse_response($raw_body) {
    $decoded = json_decode($raw_body, true);
    $entries = $decoded['result'] ?? array();
    $parsed  = array();
    foreach ($entries as $item) {
        $sym  = $item['symbol'] ?? null;
        $bars = $item['result'] ?? null;
        if (!$sym || !is_array($bars)) continue;

        $data = array();
        foreach ($bars as $bar) {
            $date = substr($bar['time'] ?? '', 0, 10);
            if (empty($date)) continue;
            $data[] = array(
                'date'   => $date,
                'open'   => floatval($bar['open']   ?? 0),
                'high'   => floatval($bar['high']   ?? 0),
                'low'    => floatval($bar['low']    ?? 0),
                'close'  => floatval($bar['close']  ?? 0),
                'volume' => intval($bar['volume']   ?? 0),
            );
        }
        usort($data, function($a, $b) { return strcmp($a['date'], $b['date']); });
        if (!empty($data)) $parsed[$sym] = $data;
    }
    return $parsed;
}

/**
 * Webullのバッチ日足取得APIから、INVALID_SYMBOLエラーで指摘された銘柄を検出する。
 * 例: {"error_code":"INVALID_SYMBOL","message":"The symbols does not exist in the category. [ko].","status":417}
 *
 * @return array 除外すべき銘柄コードの配列（見つからなければ空配列）
 */
function wp_stocks_webull_extract_invalid_symbols($raw_body) {
    $decoded = json_decode($raw_body, true);
    if (($decoded['error_code'] ?? '') !== 'INVALID_SYMBOL' || empty($decoded['message'])) {
        return array();
    }
    if (preg_match('/\[(.*?)\]/', $decoded['message'], $m)) {
        return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }
    return array();
}

function wp_stocks_webull_fetch_daily_bars_multi($symbols, $category, $count = 130, $chunk_size = 20, $concurrency = 4, $wait_between_rounds = 1) {
    if (!function_exists('curl_multi_init')) {
        wp_stocks_log('error', 'webull_batch_fetch', 'ALL', 'curl_multi_initが利用できない環境のため、バッチ取得を実行できません');
        return array();
    }

    $host    = 'api.webull.co.jp';
    $path    = '/market-data/stocks/bars/list';
    $symbols = array_values(array_unique(array_filter($symbols)));
    if (empty($symbols)) return array();

    $chunks       = array_chunk($symbols, max(1, intval($chunk_size)));
    $results      = array();
    $retry_chunks = array(); // INVALID_SYMBOLで弾かれた銘柄を除いた再試行対象チャンク

    foreach (array_chunk($chunks, max(1, intval($concurrency))) as $round) {
        $mh           = curl_multi_init();
        $curl_entries = array(); // (int) $ch => array('symbols' => [...])

        foreach ($round as $chunk_symbols) {
            $body = wp_json_encode(array(
                'symbols'            => array_values($chunk_symbols),
                'category'           => $category,
                'timespan'           => 'D',
                'count'              => intval($count),
                'real_time_required' => false,
            ));

            $signed        = wp_stocks_webull_sign_request('POST', $path, array(), $body, $host, 'v3', 'HMAC-SHA1');
            $headers_assoc = $signed['headers'];
            $headers_assoc['Content-Type'] = 'application/json';
            $headers_assoc['Accept']       = 'application/json';
            $headers_list = array();
            foreach ($headers_assoc as $hk => $hv) $headers_list[] = $hk . ': ' . $hv;

            $ch = curl_init('https://' . $host . $path);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_list);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            curl_multi_add_handle($mh, $ch);
            $curl_entries[(int) $ch] = array('handle' => $ch, 'symbols' => $chunk_symbols);
        }

        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh);
        } while ($running > 0);

        foreach ($curl_entries as $entry) {
            $ch          = $entry['handle'];
            $raw_body    = curl_multi_getcontent($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error  = curl_error($ch);

            if ($curl_error) {
                wp_stocks_log('error', 'webull_batch_fetch', implode(',', $entry['symbols']), 'curlエラー: ' . $curl_error);
            } elseif ($status_code !== 200) {
                $invalid_symbols = wp_stocks_webull_extract_invalid_symbols($raw_body);
                if (!empty($invalid_symbols)) {
                    $remaining = array_values(array_diff($entry['symbols'], $invalid_symbols));
                    wp_stocks_log('error', 'webull_batch_fetch', implode(',', $invalid_symbols), 'INVALID_SYMBOLのため除外し、同一チャンクの残り銘柄で再試行します：' . implode(',', $remaining));
                    if (!empty($remaining)) $retry_chunks[] = $remaining;
                } else {
                    wp_stocks_webull_maybe_flag_auth_error($status_code, $raw_body, 'webull_batch_fetch:' . implode(',', $entry['symbols']));
                    wp_stocks_log('error', 'webull_batch_fetch', implode(',', $entry['symbols']), 'HTTPエラー: ' . $status_code . ' / ' . substr((string) $raw_body, 0, 300));
                }
            } else {
                wp_stocks_webull_clear_auth_error();
                $results = array_merge($results, wp_stocks_webull_daily_bars_parse_response($raw_body));
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        if ($wait_between_rounds > 0) sleep($wait_between_rounds);
    }

    // --- INVALID_SYMBOL除外リトライ：問題の銘柄を除いた残りだけで再送信する ---
    foreach ($retry_chunks as $retry_symbols) {
        if (empty($retry_symbols)) continue;

        $retry_body = wp_json_encode(array(
            'symbols'            => array_values($retry_symbols),
            'category'           => $category,
            'timespan'           => 'D',
            'count'              => intval($count),
            'real_time_required' => false,
        ));
        $retry_signed  = wp_stocks_webull_sign_request('POST', $path, array(), $retry_body, $host, 'v3', 'HMAC-SHA1');
        $retry_headers = $retry_signed['headers'];
        $retry_headers['Content-Type'] = 'application/json';
        $retry_headers['Accept']       = 'application/json';

        $response = wp_remote_post('https://' . $host . $path, array(
            'headers' => $retry_headers,
            'body'    => $retry_body,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $retry_symbols), '再試行時の通信エラー: ' . $response->get_error_message());
            continue;
        }

        $retry_status   = wp_remote_retrieve_response_code($response);
        $retry_raw_body = wp_remote_retrieve_body($response);

        if ($retry_status !== 200) {
            // 再試行でも別のINVALID_SYMBOLに当たった場合は、さらに除外して1回だけ追加リトライする
            $second_invalid = wp_stocks_webull_extract_invalid_symbols($retry_raw_body);
            if (!empty($second_invalid)) {
                $second_remaining = array_values(array_diff($retry_symbols, $second_invalid));
                wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $second_invalid), '再試行でも別のINVALID_SYMBOLを検出、さらに除外して再々試行します：' . implode(',', $second_remaining));
                if (!empty($second_remaining)) {
                    $second_body = wp_json_encode(array(
                        'symbols'            => array_values($second_remaining),
                        'category'           => $category,
                        'timespan'           => 'D',
                        'count'              => intval($count),
                        'real_time_required' => false,
                    ));
                    $second_signed  = wp_stocks_webull_sign_request('POST', $path, array(), $second_body, $host, 'v3', 'HMAC-SHA1');
                    $second_headers = $second_signed['headers'];
                    $second_headers['Content-Type'] = 'application/json';
                    $second_headers['Accept']       = 'application/json';
                    $second_response = wp_remote_post('https://' . $host . $path, array(
                        'headers' => $second_headers,
                        'body'    => $second_body,
                        'timeout' => 30,
                    ));
                    if (!is_wp_error($second_response) && wp_remote_retrieve_response_code($second_response) === 200) {
                        $results = array_merge($results, wp_stocks_webull_daily_bars_parse_response(wp_remote_retrieve_body($second_response)));
                        wp_stocks_log('info', 'webull_batch_fetch_retry', implode(',', $second_remaining), '再々試行に成功しました');
                    } else {
                        wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $second_remaining), '再々試行も失敗しました。手動確認が必要です');
                    }
                }
            } else {
                wp_stocks_webull_maybe_flag_auth_error($retry_status, $retry_raw_body, 'webull_batch_fetch_retry:' . implode(',', $retry_symbols));
                wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $retry_symbols), '再試行も失敗: HTTP ' . $retry_status . ' / ' . substr($retry_raw_body, 0, 300));
            }
            continue;
        }

        wp_stocks_webull_clear_auth_error();
        $results = array_merge($results, wp_stocks_webull_daily_bars_parse_response($retry_raw_body));
        wp_stocks_log('info', 'webull_batch_fetch_retry', implode(',', $retry_symbols), 'INVALID_SYMBOL除外後の再試行に成功しました');
    }

    return $results;
}

/**
 * 米国株全銘柄の日足をWebullからバッチ取得し、
 *   1) stock_prices テーブルへ最新日の価格・前日終値・出来高を保存
 *   2) Yahoo側 wp_stocks_get_ohlcv() が参照するtransientキャッシュへ同じデータを書き込む
 *      （wp_stocks_us_technical_cron が変更なしでこのキャッシュを再利用できる。
 *        Webullで取得できなかった銘柄は次回のget_ohlcv呼び出し時に自動でYahooへフォールバックする）
 *
 * @return array ['ok' => int, 'ng' => int, 'total' => int]
 */
function wp_stocks_webull_batch_update_us_prices() {
    global $wpdb;
    $stocks = $wpdb->get_results("SELECT id, code FROM {$wpdb->prefix}stocks WHERE currency = 'USD'");
    if (!$stocks) return array('ok' => 0, 'ng' => 0, 'total' => 0);

    $symbols = array();
    foreach ($stocks as $s) $symbols[] = $s->code;

    $bars_map = wp_stocks_webull_fetch_daily_bars_multi($symbols, 'US_STOCK', 130, 20, 4, 1);

    $ok = 0;
    $ng = 0;

    foreach ($stocks as $s) {
        $bars = $bars_map[$s->code] ?? null;
        if (!$bars || count($bars) < 2) {
            wp_stocks_log('error', 'webull_batch_update', $s->code, 'バッチ結果に十分な日足データがありませんでした（Yahooへのフォールバックは次回のテクニカル計算時に自動実行されます）');
            $ng++;
            continue;
        }

        // --- stock_prices: 最新日の価格・前日終値・出来高を保存（重複日は上書き） ---
        $latest_bar = end($bars);
        $prev_bar   = ($bars[count($bars) - 2] ?? null);

        $existing_price_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d AND DATE(datetime) = %s",
            $s->id, $latest_bar['date']
        ));
        $price_row = array(
            'price'          => $latest_bar['close'],
            'previous_close' => $prev_bar ? $prev_bar['close'] : null,
            'volume'         => $latest_bar['volume'],
            'datetime'       => $latest_bar['date'] . ' 06:00:00',
        );
        if ($existing_price_id) {
            $wpdb->update($wpdb->prefix . 'stock_prices', $price_row, array('id' => $existing_price_id));
        } else {
            $price_row['stock_id'] = $s->id;
            $wpdb->insert($wpdb->prefix . 'stock_prices', $price_row);
        }

        // --- Yahoo互換のOHLCVキャッシュへ書き込み（wp_stocks_get_ohlcv()と同じtransientキー） ---
        set_transient('wp_stocks_ohlcv_' . md5($s->code), $bars, 6 * HOUR_IN_SECONDS);

        $ok++;
    }

    wp_stocks_log('info', 'webull_batch_update', 'US', "Webullバッチ更新完了：成功{$ok}件 / 失敗{$ng}件（全" . count($stocks) . "銘柄）");

    return array('ok' => $ok, 'ng' => $ng, 'total' => count($stocks));
}

function wp_stocks_fetch_financials($stock_id, $code) {
    global $wpdb;
    $api_key = get_option('wp_stocks_edinet_api_key', '');
    if (empty($api_key)) {
        wp_stocks_log('error', 'fetch_financials', $code, 'EDINET APIキーが設定されていません');
        return false;
    }

    $edinet_info = wp_stocks_get_edinet_code($code);
    if (!$edinet_info) {
        wp_stocks_log('error', 'fetch_financials', $code, 'EDINETコードの取得に失敗（詳細はget_edinet_codeのログを参照）');
        return false;
    }
    $edinet_code = $edinet_info['edinet_code'];

    // 有価証券報告書のdocIDを取得
    $doc_id      = '';
    $sec_code    = $code . '0';
    $decdate_map = get_option('wp_stocks_edinet_decdate_map', []);
    $search_dates = [];

    if (is_array($decdate_map) && isset($decdate_map[$sec_code])
        && preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $decdate_map[$sec_code], $dm)) {
        // 決算日が判明している場合: 提出期限(決算日の3ヶ月後)付近を1日単位で検索する。
        // 総当たりで毎月15/20/25日だけを探す従来方式より的中率が高く、
        // 検索対象日数も抑えられる。
        $fy_month = intval($dm[1]);
        $fy_day   = intval($dm[2]);
        $today_ts = strtotime('today');
        for ($y = 0; $y <= 2; $y++) {
            $fy_year     = intval(date('Y')) - $y;
            $fy_end_ts   = mktime(0, 0, 0, $fy_month, $fy_day, $fy_year);
            $deadline_ts = strtotime('+3 months', $fy_end_ts);
            // 提出期限の45日前〜10日後を1日ずつ（前倒し提出・休日ずれ両対応）
            for ($offset = -45; $offset <= 10; $offset++) {
                $d_ts = strtotime("{$offset} days", $deadline_ts);
                if ($d_ts > $today_ts) continue; // 未来日は除外
                $search_dates[] = date('Y-m-d', $d_ts);
            }
        }
        $search_dates = array_unique($search_dates);
        rsort($search_dates); // 新しい日付から検索した方が早くヒットしやすい
    } else {
        // 決算日が不明な場合は従来の総当たり方式にフォールバック
        for ($m = 0; $m <= 24; $m++) {
            $ts = mktime(0, 0, 0, date('n') - $m, 15, date('Y'));
            foreach ([25, 20, 15] as $day) {
                $search_dates[] = date('Y-m', $ts) . '-' . sprintf('%02d', $day);
            }
        }
        $search_dates = array_unique($search_dates);
    }

    foreach ($search_dates as $date) {
        if (!empty($doc_id)) break;
        $url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents.json?date={$date}&type=2&Subscription-Key=" . urlencode($api_key);
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        foreach (($body['results'] ?? []) as $doc) {
            if (($doc['edinetCode'] ?? '') === $edinet_code && ($doc['docTypeCode'] ?? '') === '120') {
                $doc_id = $doc['docID'];
                break;
            }
        }
    }
    if (empty($doc_id)) {
        if (is_array($decdate_map) && isset($decdate_map[$sec_code])) {
            $range_desc = '決算日ベースの絞り込み（決算日=' . $decdate_map[$sec_code] . ', 提出期限前後45日〜10日を1日単位で検索）';
        } else {
            $range_desc = '決算日情報なし→従来の総当たり方式（毎月15/20/25日、過去24ヶ月）にフォールバック';
        }
        wp_stocks_log('error', 'fetch_financials', $code, '有価証券報告書のdocIDが見つかりませんでした（edinetCode=' . $edinet_code . ', 検索日数=' . count($search_dates) . '件, 検索方式=' . $range_desc . '）');
        return false;
    }
    wp_stocks_log('info', 'fetch_financials', $code, '有価証券報告書のdocIDを発見しました: docID=' . $doc_id);

    // ZIPダウンロード＆CSV抽出（共通ヘルパー）
    $zip_result = wp_stocks_edinet_extract_csv_from_zip($doc_id, $api_key, 'jpcrp030000-asr', 'fetch_financials', $code);
    if ($zip_result === false) {
        return false;
    }
    $csv_content = $zip_result['content'];

    // UTF-16 LE → UTF-8
    $text  = mb_convert_encoding($csv_content, 'UTF-8', 'UTF-16LE');
    $lines = explode("
", $text);

    // 【訂正】上記コメントの判断は誤りでした。2026-07-09の実データ検証
    // (銘柄コード6838, docID=S100XHR9)で、実際には英語のcontext IDではなく
    // 日本語の期間ラベル(五期前・四期前・三期前・前々期・前期・当期)が
    // $parts[3] にそのまま入っていることが確認されました。6期分に対応します。
    $periods = ['五期前', '四期前', '三期前', '前々期', '前期', '当期'];
    $data = array_fill_keys($periods, []);

    // 【訂正】実データ検証(2026-07-09)の結果、EDINETの「経営指標等の推移」表
    // (SummaryOfBusinessResults)には多くの場合「営業利益」ではなく
    // 「経常利益」(OrdinaryIncomeLoss)が記載されていることが判明。
    // これは会計基準・業種によって決算ハイライトに使われる利益指標が
    // 異なるためで、EDINET全般に共通する仕様上の制約。
    // 本プラグインのDBスキーマは operating_profit 列しか持たないため、
    // 次善策として経常利益をoperating_profitとして代用する
    // (正確には「経常利益」であり「営業利益」そのものではない点に注意)。
    $key_map = [
        'RevenueIFRSSummaryOfBusinessResults'                                       => 'revenue',
        'NetSalesSummaryOfBusinessResults'                                          => 'revenue',
        'OperatingProfitLossIFRSSummaryOfBusinessResults'                          => 'operating_profit',
        'OperatingIncomeSummaryOfBusinessResults'                                   => 'operating_profit',
        'OrdinaryIncomeLossSummaryOfBusinessResults'                                => 'operating_profit',
        'ProfitLossAttributableToOwnersOfParentIFRSSummaryOfBusinessResults'       => 'net_income',
        'ProfitLossAttributableToOwnersOfParentSummaryOfBusinessResults'           => 'net_income',
        'NetIncomeSummaryOfBusinessResults'                                         => 'net_income',
        'ProfitLossSummaryOfBusinessResults'                                        => 'net_income',
        'EquityAttributableToOwnersOfParentIFRSSummaryOfBusinessResults'           => 'equity',
        'NetAssetsSummaryOfBusinessResults'                                         => 'equity',
        'TotalAssetsIFRSSummaryOfBusinessResults'                                   => 'total_assets',
        'TotalAssetsSummaryOfBusinessResults'                                       => 'total_assets',
    ];

    foreach ($lines as $line) {
        $parts = explode("	", $line);
        if (count($parts) < 9) continue;
        $element = trim($parts[0], '"');
        $period  = trim($parts[3], '"');
        $value   = trim($parts[8], " \"\r\n");
        // 純資産・総資産等の時点情報(Instant)は「◯期末」「◯期前時点」のように
        // 接尾辞が付くため(2026-07-12の実データ検証で判明)、期間キーと比較する前に正規化する
        $period_norm = $period;
        if (mb_substr($period_norm, -1) === '末') {
            $period_norm = mb_substr($period_norm, 0, -1);
        } elseif (mb_substr($period_norm, -2) === '時点') {
            $period_norm = mb_substr($period_norm, 0, -2);
        }
        foreach ($key_map as $pattern => $field) {
            if (strpos($element, $pattern) !== false) {
                if (isset($data[$period_norm]) && preg_match('/^-?\d+$/', $value)) {
                    if (!isset($data[$period_norm][$field])) $data[$period_norm][$field] = intval($value);
                }
                break;
            }
        }
    }

    // 診断: 財務項目が1件も抽出できなかった場合、実際のCSVに含まれる
    // 勘定科目タグ名をログに出し、想定タグ名とのズレを特定できるようにする
    if (empty(array_filter($data))) {
        $diag_summary_count = 0;
        $diag_samples = [];
        foreach ($lines as $diag_line) {
            $diag_parts = explode("\t", $diag_line);
            if (count($diag_parts) < 9) continue;
            $diag_element = trim($diag_parts[0], '"');
            if (strpos($diag_element, 'SummaryOfBusinessResults') === false) continue;
            $diag_summary_count++;
            if (count($diag_samples) < 8) {
                $diag_period = trim($diag_parts[3], '"');
                $diag_value  = trim($diag_parts[8], " \"\r\n");
                $diag_samples[] = $diag_element . '(period=' . $diag_period . ',value=' . $diag_value . ')';
            }
        }
        if ($diag_summary_count > 0) {
            wp_stocks_log('info', 'fetch_financials', $code, '診断: SummaryOfBusinessResultsを含む行=' . $diag_summary_count . '件見つかりましたが、想定タグ/期間/数値形式に一致しませんでした。サンプル: ' . implode(' || ', $diag_samples));
        } else {
            $diag_first = [];
            foreach ($lines as $diag_line) {
                $diag_parts = explode("\t", $diag_line);
                if (count($diag_parts) < 9) continue;
                $diag_element = trim($diag_parts[0], '"');
                if ($diag_element === '' || in_array($diag_element, $diag_first, true)) continue;
                $diag_first[] = $diag_element;
                if (count($diag_first) >= 15) break;
            }
            wp_stocks_log('info', 'fetch_financials', $code, '診断: SummaryOfBusinessResultsを含む行が1件もありませんでした。CSV先頭付近の勘定科目名サンプル: ' . implode(' || ', $diag_first));
        }
    }

    // 診断: equity(純資産)またはtotal_assets(総資産)が1件も取得できて
    // いない場合、実際のCSVに含まれる関連タグ名をログに出す
    // (自己資本比率が常に0%になる問題の原因調査用)
    $has_equity_or_assets = false;
    foreach ($data as $diag2_row) {
        if (!empty($diag2_row['equity']) || !empty($diag2_row['total_assets'])) { $has_equity_or_assets = true; break; }
    }
    if (!$has_equity_or_assets) {
        $diag2_samples = [];
        foreach ($lines as $diag2_line) {
            $diag2_parts = explode("\t", $diag2_line);
            if (count($diag2_parts) < 9) continue;
            $diag2_element = trim($diag2_parts[0], '"');
            if (strpos($diag2_element, 'SummaryOfBusinessResults') === false) continue;
            if (strpos($diag2_element, 'NetAssets') === false
                && strpos($diag2_element, 'TotalAssets') === false
                && strpos($diag2_element, 'Equity') === false
                && strpos($diag2_element, 'CapitalAdequacy') === false
                && strpos($diag2_element, 'AssetRatio') === false) continue;
            if (count($diag2_samples) >= 10) break;
            $diag2_period = trim($diag2_parts[3], '"');
            $diag2_value  = trim($diag2_parts[8], " \"\r\n");
            $diag2_samples[] = $diag2_element . '(period=' . $diag2_period . ',value=' . $diag2_value . ')';
        }
        if (!empty($diag2_samples)) {
            wp_stocks_log('info', 'fetch_financials', $code, '診断(自己資本比率): NetAssets/TotalAssets/Equity関連タグのサンプル: ' . implode(' || ', $diag2_samples));
        } else {
            wp_stocks_log('info', 'fetch_financials', $code, '診断(自己資本比率): NetAssets/TotalAssets/Equity/AssetRatio等を含むSummaryOfBusinessResultsタグが1件も見つかりませんでした');
        }
    }

    // 【訂正】2026-07-09の実データ検証により、期間キーは日本語ラベル
    // (五期前・四期前・三期前・前々期・前期・当期)であることが判明したため、
    // 対応表もそれに合わせて修正(5期前を追加し全6期分に対応)。
    $period_labels = [
        '五期前' => '5期前',
        '四期前' => '4期前',
        '三期前' => '3期前',
        '前々期' => '2期前',
        '前期'   => '前期',
        '当期'   => '当期',
    ];

    $saved = 0;
    $year_base = intval(date('Y'));
    $offsets = ['五期前'=>-5,'四期前'=>-4,'三期前'=>-3,'前々期'=>-2,'前期'=>-1,'当期'=>0];
    foreach ($data as $period_key => $row) {
        if (empty($row)) continue;
        $fiscal_year  = ($year_base + $offsets[$period_key]) . '年度';
        $equity_ratio = null;
        if (!empty($row['equity']) && !empty($row['total_assets']) && $row['total_assets'] > 0) {
            $equity_ratio = round($row['equity'] / $row['total_assets'] * 100, 1);
        }
        $wpdb->replace($wpdb->prefix . 'stock_financials', [
            'stock_id'         => $stock_id,
            'fiscal_year'      => $fiscal_year,
            'period_label'     => $period_labels[$period_key],
            'revenue'          => $row['revenue'] ?? null,
            'operating_profit' => $row['operating_profit'] ?? null,
            'net_income'       => $row['net_income'] ?? null,
            'equity'           => $row['equity'] ?? null,
            'total_assets'     => $row['total_assets'] ?? null,
            'equity_ratio'     => $equity_ratio,
            'doc_id'           => $doc_id,
        ]);
        $saved++;
    }
    if ($saved > 0) {
        wp_stocks_log('info', 'fetch_financials', $code, '財務データを' . $saved . '期分保存しました（doc_id=' . $doc_id . '）');
    } else {
        wp_stocks_log('error', 'fetch_financials', $code, 'CSVは正常に取得できましたが、対象の財務項目が1件も抽出できませんでした（doc_id=' . $doc_id . ', CSV行数=' . count($lines) . '）。タグ名の想定と実際のCSVの勘定科目名が一致していない可能性があります');
    }
    return $saved > 0 ? $saved : false;
}

function wp_stocks_get_usd_jpy() {
    $manual = floatval(get_option('wp_stocks_usd_jpy_manual', 0));
    if ($manual > 0) return $manual;

    $cached = get_transient('wp_stocks_usd_jpy');
    if ($cached !== false) return floatval($cached);

    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/USDJPY=X?interval=1d&range=1d';
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
        'timeout' => 10,
    ]);
    if (is_wp_error($response)) return 150.0;

    $body  = json_decode(wp_remote_retrieve_body($response), true);
    $price = $body['chart']['result'][0]['meta']['regularMarketPrice'] ?? 0;
    if ($price > 0) {
        set_transient('wp_stocks_usd_jpy', $price, HOUR_IN_SECONDS);
        return floatval($price);
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

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1); dbDelta($sql2); dbDelta($sql3); dbDelta($sql4); dbDelta($sql5); dbDelta($sql6); dbDelta($sql_fin); dbDelta($sql7); dbDelta($sql8); dbDelta($sql9); dbDelta($sql10); dbDelta($sql_signal_history); dbDelta($sql_jpx_sector_per);

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
register_activation_hook(__FILE__, 'wp_stocks_manager_activate');
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

function wp_stocks_render_market_indices() {
    $groups = wp_stocks_get_market_index_groups();
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

// --------------------------------------------------
// EDINETコードリストを直接ダウンロードする(2026-07-14 動作確認済み)
// https://disclosure2dl.edinet-fsa.go.jp/searchdocument/codelist/Edinetcode.zip
// は認証・ブラウザのJS不要で直接取得可能なため、手動アップロードなしで
// 自動更新できる。
// --------------------------------------------------
if (!defined('WP_STOCKS_EDINET_CODELIST_URL')) {
    define('WP_STOCKS_EDINET_CODELIST_URL', 'https://disclosure2dl.edinet-fsa.go.jp/searchdocument/codelist/Edinetcode.zip');
}

function wp_stocks_download_edinet_codelist() {
    $response = wp_remote_get(WP_STOCKS_EDINET_CODELIST_URL, [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
        'timeout' => 30,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'EDINETコードリストの自動ダウンロードに失敗(通信エラー): ' . $response->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'EDINETコードリストの自動ダウンロードに失敗: HTTP ' . wp_remote_retrieve_response_code($response));
        return false;
    }
    $zip_data = wp_remote_retrieve_body($response);
    $tmp_file = sys_get_temp_dir() . '/edinetcode_' . uniqid() . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $content = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
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
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                if (stripos($file->getPathname(), '.csv') !== false) {
                    $content = file_get_contents($file->getPathname());
                    break;
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', 'edinet_codelist', 'ALL', 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }
    unlink($tmp_file);
    if (empty($content)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'ダウンロードしたZIPからCSVを抽出できませんでした');
        return false;
    }
    return $content;
}

function wp_stocks_save_edinet_codelist_content($content, $source_label = '自動ダウンロード') {
    $parsed = wp_stocks_parse_edinet_codelist_csv($content);
    $map    = $parsed['edinet']  ?? [];
    $decmap = $parsed['decdate'] ?? [];
    if (empty($map)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'CSVのパースに失敗、または0件でした（' . $source_label . '）');
        return false;
    }
    update_option('wp_stocks_edinet_codelist', $map, false);
    update_option('wp_stocks_edinet_decdate_map', $decmap, false);
    update_option('wp_stocks_edinet_codelist_updated_at', current_time('mysql'));
    wp_stocks_log('info', 'edinet_codelist', 'ALL', 'EDINETコードリストを更新しました（' . count($map) . '件、決算日情報' . count($decmap) . '件、' . $source_label . '）');
    return count($map);
}

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

function wp_stocks_dashboard_page() {
    global $wpdb;

    $sort_by  = sanitize_text_field($_GET['sort'] ?? 'id');
    $sort_dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $allowed_sorts = ['id', 'code', 'name', 'per', 'pbr', 'roe', 'peg', 'dividend_yield', 'market_cap', 'score'];
    if (!in_array($sort_by, $allowed_sorts)) $sort_by = 'id';

    $sector_filter = sanitize_text_field($_GET['sector'] ?? 'all');
    $keyword       = sanitize_text_field($_GET['keyword'] ?? '');
    $tab           = in_array($_GET['tab'] ?? '', ['jp', 'us']) ? $_GET['tab'] : 'jp';
    $watchlist_only = intval($_GET['watchlist'] ?? 0);
    $smallcap_only  = intval($_GET['smallcap'] ?? 0);
    $screen_op      = intval($_GET['screen_op'] ?? 0);
    $screen_ni      = intval($_GET['screen_ni'] ?? 0);
    $screen_sk      = intval($_GET['screen_sk'] ?? 0);
    $today         = date('Y-m-d');
    $base_url      = admin_url('admin.php?page=wp-stocks-dashboard');

    // 全銘柄取得（TOPIX-17セクターETFは除外）
    $all_stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE status = 'watch' AND is_sector_etf = 0 ORDER BY id ASC");

    // テクニカルデータを一括取得
    $tech_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_technicals");
    $tech_map  = [];
    foreach ($tech_rows as $t) $tech_map[$t->stock_id] = $t;

    // 最新株価を一括取得
    $price_rows_dash = $wpdb->get_results(
        "SELECT sp.stock_id, sp.price, sp.previous_close, sp.datetime
         FROM {$wpdb->prefix}stock_prices sp
         INNER JOIN (
             SELECT stock_id, MAX(datetime) as md
             FROM {$wpdb->prefix}stock_prices GROUP BY stock_id
         ) latest ON sp.stock_id = latest.stock_id AND sp.datetime = latest.md"
    );
    $price_map_dash = [];
    foreach ($price_rows_dash as $pr) $price_map_dash[$pr->stock_id] = $pr;

    // 日本株・米国株に分類してスコア付与・セクター収集
    $jp_stocks   = [];
    $us_stocks   = [];
    $sectors     = ['all' => '全て'];
    $us_sectors  = ['all' => '全て'];
    $per_sum     = 0; $per_cnt = 0;
    $nearest_earnings = null; $nearest_stock = null;

    foreach ($all_stocks as $s) {
        $score_data   = wp_stocks_calc_score($s);
        $s->_score    = $score_data['score'];
        $s->_judgment = $score_data['judgment'];
        $s->_is_usd   = ($s->currency ?? 'JPY') === 'USD';

        if ($s->_is_usd) {
            $us_stocks[] = $s;
            if (!empty($s->sector) && !isset($us_sectors[$s->sector])) {
                $us_sectors[$s->sector] = wp_stocks_sector_ja($s->sector);
            }
        } else {
            $jp_stocks[] = $s;
            if (!empty($s->sector) && !isset($sectors[$s->sector])) {
                $sectors[$s->sector] = wp_stocks_sector_ja($s->sector);
            }
        }
        if (($s->per ?? 0) > 0 && !$s->_is_usd) { $per_sum += $s->per; $per_cnt++; }
        if (!empty($s->earnings_date) && $s->earnings_date >= $today && !$s->_is_usd) {
            if (!$nearest_earnings || $s->earnings_date < $nearest_earnings) {
                $nearest_earnings = $s->earnings_date; $nearest_stock = $s;
            }
        }
    }
    $avg_per    = $per_cnt > 0 ? round($per_sum / $per_cnt, 1) : 0;
    $tech_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stock_technicals");

    // ---- ソート関数 ----
    $do_sort = function($list) use ($sort_by, $sort_dir) {
        usort($list, function($a, $b) use ($sort_by, $sort_dir) {
            $va = $sort_by === 'score' ? ($a->_score ?? 0) : floatval($a->$sort_by ?? 0);
            $vb = $sort_by === 'score' ? ($b->_score ?? 0) : floatval($b->$sort_by ?? 0);
            if ($sort_by === 'name' || $sort_by === 'code') { $va = $a->$sort_by; $vb = $b->$sort_by; }
            $cmp = $va <=> $vb;
            return $sort_dir === 'desc' ? -$cmp : $cmp;
        });
        return $list;
    };

    // ---- キーワード・セクターフィルター（日本株のみ） ----
    $jp_filtered = $jp_stocks;
    if ($watchlist_only) {
        $jp_filtered = array_values(array_filter($jp_filtered, fn($s) => intval($s->is_watchlist ?? 0) === 1));
    }
    if ($smallcap_only) {
        $jp_filtered = array_values(array_filter($jp_filtered, fn($s) => ($s->market_cap ?? 0) > 0 && $s->market_cap < 30000000000));
    }
    if ($screen_op) {
        $jp_filtered = array_values(array_filter($jp_filtered, fn($s) => intval($s->screen_op_profit ?? 0) === 1));
    }
    if ($screen_ni) {
        $jp_filtered = array_values(array_filter($jp_filtered, fn($s) => intval($s->screen_net_income ?? 0) === 1));
    }
    if ($screen_sk) {
        $jp_filtered = array_values(array_filter($jp_filtered, fn($s) => intval($s->screen_shikiho ?? 0) === 1));
    }
    if ($sector_filter !== 'all') {
        $jp_filtered = array_values(array_filter($jp_filtered, fn($s) => $s->sector === $sector_filter));
    }
    if ($keyword !== '') {
        $jp_filtered = array_values(array_filter($jp_filtered, function($s) use ($keyword) {
            $kw = mb_strtolower($keyword);
            return mb_strpos(mb_strtolower($s->theme_tags ?? ''), $kw) !== false
                || mb_strpos(mb_strtolower($s->memo ?? ''), $kw) !== false
                || mb_strpos(mb_strtolower($s->shikiho ?? ''), $kw) !== false;
        }));
    }
    $jp_filtered = $do_sort($jp_filtered);

    // ---- 米国株セクター・キーワードフィルター ----
    $us_sector_filter = sanitize_text_field($_GET['us_sector'] ?? 'all');
    $us_filtered = $us_stocks;
    if ($watchlist_only) {
        $us_filtered = array_values(array_filter($us_filtered, fn($s) => intval($s->is_watchlist ?? 0) === 1));
    }
    if ($smallcap_only) {
        $us_filtered = array_values(array_filter($us_filtered, fn($s) => ($s->market_cap ?? 0) > 0 && $s->market_cap < 200000000));
    }
    if ($screen_op) {
        $us_filtered = array_values(array_filter($us_filtered, fn($s) => intval($s->screen_op_profit ?? 0) === 1));
    }
    if ($screen_ni) {
        $us_filtered = array_values(array_filter($us_filtered, fn($s) => intval($s->screen_net_income ?? 0) === 1));
    }
    if ($screen_sk) {
        $us_filtered = array_values(array_filter($us_filtered, fn($s) => intval($s->screen_shikiho ?? 0) === 1));
    }
    if ($us_sector_filter !== 'all') {
        $us_filtered = array_values(array_filter($us_filtered, fn($s) => $s->sector === $us_sector_filter));
    }
    if ($keyword !== '') {
        $us_filtered = array_values(array_filter($us_filtered, function($s) use ($keyword) {
            $kw = mb_strtolower($keyword);
            return mb_strpos(mb_strtolower($s->theme_tags ?? ''), $kw) !== false
                || mb_strpos(mb_strtolower($s->memo ?? ''), $kw) !== false
                || mb_strpos(mb_strtolower($s->shikiho ?? ''), $kw) !== false;
        }));
    }
    $us_filtered = $do_sort($us_filtered);

    // ---- ページネーション ----
    $per_page = 30;
    $jp_page  = max(1, intval($_GET['jp_paged'] ?? 1));
    $us_page  = max(1, intval($_GET['us_paged'] ?? 1));
    $jp_total = count($jp_filtered);
    $us_total = count($us_filtered);
    $jp_offset = ($jp_page - 1) * $per_page;
    $us_offset = ($us_page - 1) * $per_page;
    $jp_paged  = array_slice($jp_filtered, $jp_offset, $per_page);
    $us_paged  = array_slice($us_filtered, $us_offset, $per_page);

    // ---- ソートリンク ----
    $sort_url = function($col) use ($sort_by, $sort_dir, $sector_filter, $us_sector_filter, $keyword, $tab, $base_url, $watchlist_only, $smallcap_only, $screen_op, $screen_ni, $screen_sk) {
        $dir = ($sort_by === $col && $sort_dir === 'asc') ? 'desc' : 'asc';
        return $base_url . '&tab=' . $tab . '&sector=' . urlencode($sector_filter)
            . '&us_sector=' . urlencode($us_sector_filter)
            . '&keyword=' . urlencode($keyword) . '&sort=' . $col . '&dir=' . $dir
            . '&watchlist=' . ($watchlist_only ? '1' : '0') . '&smallcap=' . ($smallcap_only ? '1' : '0')
            . '&screen_op=' . ($screen_op ? '1' : '0') . '&screen_ni=' . ($screen_ni ? '1' : '0') . '&screen_sk=' . ($screen_sk ? '1' : '0');
    };
    $sort_icon = function($col) use ($sort_by, $sort_dir) {
        if ($sort_by !== $col) return ' ↕';
        return $sort_dir === 'asc' ? ' ↑' : ' ↓';
    };

    // ---- テーブル描画クロージャ ----
    $render_table = function($stocks_list, $is_usd_section, $total, $page, $paged_key, $offset)
        use ($tech_map, $price_map_dash, $today, $sort_url, $sort_icon, $base_url,
             $tab, $sector_filter, $us_sector_filter, $keyword, $sort_by, $sort_dir, $per_page) {

        if (empty($stocks_list)) {
            echo '<p style="color:#888;padding:20px;">該当銘柄がありません。</p>';
            return;
        }

        $headers = $is_usd_section ? [
            ['code',          'コード'],
            ['name',          '銘柄名'],
            ['per',           'PER'],
            ['pbr',           'PBR'],
            ['roe',           'ROE'],
            ['peg',           'PEG'],
            ['peg_trailing',  'PEG(実績)'],
            ['dividend_yield','配当'],
            ['',              'トレンド'],
            ['score',         'スコア'],
            ['',              '操作'],
        ] : [
            ['code',          'コード'],
            ['name',          '銘柄名'],
            ['per',           'PER'],
            ['pbr',           'PBR'],
            ['roe',           'ROE'],
            ['peg',           'PEG'],
            ['peg_trailing',  'PEG(実績)'],
            ['dividend_yield','配当'],
            ['',              'トレンド'],
            ['score',         'スコア'],
            ['',              '決算'],
            ['',              '操作'],
        ];

        echo '<table class="widefat fixed striped" style="font-size:12px;">';
        echo '<thead><tr>';
        foreach ($headers as [$col, $label]) {
            if ($col) echo '<th><a href="' . esc_url($sort_url($col)) . '" style="text-decoration:none;color:#333;">' . $label . $sort_icon($col) . '</a></th>';
            else      echo '<th>' . $label . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($stocks_list as $s) {
            $latest      = $price_map_dash[$s->id] ?? null;
            $is_usd      = $s->_is_usd;
            $usd_jpy     = $is_usd ? wp_stocks_get_usd_jpy() : 1;

            if ($is_usd) {
                $price_str = $latest ? '$' . number_format($latest->price, 2)
                    . ' <span style="color:#aaa;font-size:10px;">≈' . number_format($latest->price * $usd_jpy) . '円</span>'
                    : '未取得';
            } else {
                $price_str = $latest ? number_format($latest->price) . '円' : '未取得';
            }

            $change_html = ($latest && $latest->previous_close)
                ? wp_stocks_change_html($latest->price, $latest->previous_close, $is_usd) : '-';
            $tech        = $tech_map[$s->id] ?? null;
            $trend_html  = wp_stocks_trend_icon_html($tech);
            $score       = $s->_score;
            $judgment    = $s->_judgment;
            $detail_url  = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
            $pf_url      = admin_url('admin-post.php?action=move_to_portfolio&id=' . $s->id);

            echo '<tr>';
            echo '<td>' . esc_html($s->code) . '</td>';
            $star_icon = intval($s->is_watchlist ?? 0) ? '<span style="color:#f39c12;">★</span> ' : '';
            echo '<td>' . $star_icon . '<a href="' . esc_url($detail_url) . '">' . esc_html($s->name) . '</a>' . esc_html(wp_stocks_market_segment_suffix($s->market ?? ''))
                . '<br><small style="color:#888;">' . $price_str . ' ' . $change_html . '</small></td>';
            echo '<td>' . (($s->per ?? 0) > 0 ? number_format($s->per, 1) . '倍' : '-') . '</td>';
            echo '<td>' . (($s->pbr ?? 0) > 0 ? number_format($s->pbr, 2) . '倍' : '-') . '</td>';
            echo '<td>' . (($s->roe ?? 0) != 0 ? number_format($s->roe, 1) . '%' : '-') . '</td>';
            echo '<td>' . (($s->peg ?? 0) > 0 ? number_format($s->peg, 2) : '-') . '</td>';
            echo '<td>' . (($s->peg_trailing ?? 0) > 0 ? number_format($s->peg_trailing, 2) : '-') . '</td>';
            echo '<td>' . (($s->dividend_yield ?? 0) > 0 ? number_format($s->dividend_yield, 2) . '%' : '-') . '</td>';
            echo '<td>' . $trend_html . '</td>';
            echo '<td><span style="font-weight:bold;color:' . $judgment['color'] . ';">' . $score . '点</span>'
                . '<br><small style="color:' . $judgment['color'] . ';">' . $judgment['label'] . '</small></td>';

            // 決算列は日本株のみ
            if (!$is_usd_section) {
                $earnings_str = '-';
                if (!empty($s->earnings_date) && $s->earnings_date >= $today) {
                    $days = (int)((strtotime($s->earnings_date) - strtotime($today)) / 86400);
                    $ec   = $days <= 7 ? '#e74c3c' : ($days <= 30 ? '#f39c12' : '#555');
                    $earnings_str = '<span style="color:' . $ec . ';font-weight:bold;">' . $days . '日後</span>';
                }
                echo '<td>' . $earnings_str . '</td>';
            }

            echo '<td><a href="' . esc_url($detail_url) . '" class="button button-small">詳細</a> '
                . '<a href="' . esc_url($pf_url) . '" class="button button-small">📁</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // ページネーション
        $total_pages = ceil($total / $per_page);
        if ($total_pages > 1) {
            echo '<div style="margin-top:10px;display:flex;gap:6px;align-items:center;">';
            echo '<span style="color:#888;font-size:12px;">' . $total . '件中 ' . ($offset + 1) . '〜' . min($offset + $per_page, $total) . '件</span>';
            for ($p = 1; $p <= $total_pages; $p++) {
                $purl   = $base_url . '&tab=' . $tab . '&sector=' . urlencode($sector_filter)
                    . '&us_sector=' . urlencode($us_sector_filter)
                    . '&keyword=' . urlencode($keyword) . '&sort=' . $sort_by . '&dir=' . $sort_dir
                    . '&' . $paged_key . '=' . $p;
                $active = $p === $page;
                echo '<a href="' . esc_url($purl) . '" style="padding:3px 9px;border:1px solid #ddd;border-radius:3px;text-decoration:none;'
                    . ($active ? 'background:#0073aa;color:#fff;' : 'background:#fff;color:#333;') . '">' . $p . '</a>';
            }
            echo '</div>';
        }
    };

    // ============================================================
    // 描画開始
    // ============================================================
    echo '<div class="wrap"><h1>📋 ウォッチリスト</h1>';

    // サマリーバー
    echo '<div style="display:flex;gap:15px;flex-wrap:wrap;margin-bottom:20px;padding:15px;background:#f8f9fa;border-radius:8px;border:1px solid #ddd;">';
    echo '<div><span style="color:#888;font-size:12px;">🇯🇵 日本株</span><br><strong style="font-size:20px;">' . count($jp_stocks) . '銘柄</strong></div>';
    echo '<div style="border-left:1px solid #ddd;margin:0 5px;"></div>';
    echo '<div><span style="color:#888;font-size:12px;">🇺🇸 米国株</span><br><strong style="font-size:20px;">' . count($us_stocks) . '銘柄</strong></div>';
    echo '<div style="border-left:1px solid #ddd;margin:0 5px;"></div>';
    echo '<div><span style="color:#888;font-size:12px;">日本株 平均PER</span><br><strong style="font-size:20px;">' . ($avg_per > 0 ? $avg_per . '倍' : 'N/A') . '</strong></div>';
    if ($nearest_stock) {
        $days_left = (int)((strtotime($nearest_earnings) - strtotime($today)) / 86400);
        echo '<div style="border-left:1px solid #ddd;margin:0 5px;"></div>';
        echo '<div><span style="color:#888;font-size:12px;">直近決算</span><br><strong style="font-size:16px;color:#e74c3c;">' . esc_html($nearest_stock->name) . '（' . $days_left . '日後）</strong></div>';
    }
    echo '<div style="border-left:1px solid #ddd;margin:0 5px;"></div>';
    echo '<div><span style="color:#888;font-size:12px;">テクニカル計算済</span><br><strong style="font-size:16px;">' . $tech_count . '/' . count($all_stocks) . '銘柄</strong></div>';
    echo '</div>';

// 最近の適時開示は wp_stocks_render_recent_news_widget() に統合済み。
    // マーケット情報ページの「適時開示」タブに移動したため、ダッシュボードからは削除。

    // キーワード検索
    echo '<div style="margin-bottom:15px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
    echo '<form method="get" style="display:flex;gap:8px;align-items:center;">';
    echo '<input type="hidden" name="page" value="wp-stocks-dashboard">';
    echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
    echo '<input type="hidden" name="sector" value="' . esc_attr($sector_filter) . '">';
    echo '<input type="hidden" name="watchlist" value="' . esc_attr($watchlist_only) . '">';
    echo '<input type="hidden" name="smallcap" value="' . esc_attr($smallcap_only) . '">';
    echo '<input type="hidden" name="screen_op" value="' . esc_attr($screen_op) . '">';
    echo '<input type="hidden" name="screen_ni" value="' . esc_attr($screen_ni) . '">';
    echo '<input type="hidden" name="screen_sk" value="' . esc_attr($screen_sk) . '">';
    echo '<input type="text" name="keyword" value="' . esc_attr($keyword) . '" placeholder="テーマタグ・メモ・四季報を検索..." style="width:250px;">';
    echo '<button type="submit" class="button">🔍 検索</button>';
    if ($keyword) echo '<a href="' . esc_url($base_url . '&tab=' . $tab) . '" class="button">クリア</a>';
    echo '</form>';
    // 注目株フィルターボタン
    $wl_url    = $base_url . '&tab=' . $tab . '&watchlist=' . ($watchlist_only ? '0' : '1') . '&smallcap=' . $smallcap_only . '&screen_op=' . $screen_op . '&screen_ni=' . $screen_ni . '&screen_sk=' . $screen_sk;
    $wl_active = $watchlist_only ? 'background:#f39c12;color:#fff;border-color:#e67e22;' : 'background:#fff;color:#555;border-color:#ddd;';
    echo '<a href="' . esc_url($wl_url) . '" class="button" style="' . $wl_active . 'font-weight:bold;">&#x2605; 注目株のみ</a>';
    // 小型株フィルターボタン
    $sc_url    = $base_url . '&tab=' . $tab . '&smallcap=' . ($smallcap_only ? '0' : '1') . '&watchlist=' . $watchlist_only . '&screen_op=' . $screen_op . '&screen_ni=' . $screen_ni . '&screen_sk=' . $screen_sk;
    $sc_active = $smallcap_only ? 'background:#16a085;color:#fff;border-color:#0e6655;' : 'background:#fff;color:#555;border-color:#ddd;';
    echo '<a href="' . esc_url($sc_url) . '" class="button" style="' . $sc_active . 'font-weight:bold;">&#x1F331; 小型株のみ</a>';
    // 営業利益が高いフィルターボタン
    $op_url    = $base_url . '&tab=' . $tab . '&screen_op=' . ($screen_op ? '0' : '1') . '&watchlist=' . $watchlist_only . '&smallcap=' . $smallcap_only . '&screen_ni=' . $screen_ni . '&screen_sk=' . $screen_sk;
    $op_active = $screen_op ? 'background:#2c3e50;color:#fff;border-color:#1a252f;' : 'background:#fff;color:#555;border-color:#ddd;';
    echo '<a href="' . esc_url($op_url) . '" class="button" style="' . $op_active . 'font-weight:bold;">💰 営業利益が高い</a>';
    // 純利益が高いフィルターボタン
    $ni_url    = $base_url . '&tab=' . $tab . '&screen_ni=' . ($screen_ni ? '0' : '1') . '&watchlist=' . $watchlist_only . '&smallcap=' . $smallcap_only . '&screen_op=' . $screen_op . '&screen_sk=' . $screen_sk;
    $ni_active = $screen_ni ? 'background:#8e44ad;color:#fff;border-color:#6c3483;' : 'background:#fff;color:#555;border-color:#ddd;';
    echo '<a href="' . esc_url($ni_url) . '" class="button" style="' . $ni_active . 'font-weight:bold;">💵 純利益が高い</a>';
    // 四季報見出しが良いフィルターボタン
    $sk_url    = $base_url . '&tab=' . $tab . '&screen_sk=' . ($screen_sk ? '0' : '1') . '&watchlist=' . $watchlist_only . '&smallcap=' . $smallcap_only . '&screen_op=' . $screen_op . '&screen_ni=' . $screen_ni;
    $sk_active = $screen_sk ? 'background:#c0392b;color:#fff;border-color:#922b21;' : 'background:#fff;color:#555;border-color:#ddd;';
    echo '<a href="' . esc_url($sk_url) . '" class="button" style="' . $sk_active . 'font-weight:bold;">📖 四季報見出しが良い</a>';
    echo '</div>';

    // 日本株/米国株 切り替えタブ
    echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
    foreach (['jp' => '🇯🇵 日本株（' . count($jp_stocks) . '）', 'us' => '🇺🇸 米国株（' . count($us_stocks) . '）'] as $key => $label) {
        $active = $tab === $key;
        $url    = $base_url . '&tab=' . $key . '&sort=' . $sort_by . '&dir=' . $sort_dir;
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    // 日本株タブ
    if ($tab === 'jp') {
        echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:15px;margin-bottom:20px;">';

        // セクタータブ（日本株のみ）
        echo '<div style="margin-bottom:12px;border-bottom:1px solid #eee;padding-bottom:10px;">';
        foreach ($sectors as $key => $label) {
            $active = $sector_filter === $key;
            $url    = $base_url . '&tab=jp&sector=' . urlencode($key) . '&sort=' . $sort_by . '&dir=' . $sort_dir;
            echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:4px 12px;margin-right:3px;margin-bottom:4px;border-radius:3px;text-decoration:none;font-size:12px;'
                . ($active ? 'background:#e74c3c;color:#fff;' : 'background:#f0f0f0;color:#333;') . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';

        $render_table($jp_paged, false, $jp_total, $jp_page, 'jp_paged', $jp_offset);
        echo '</div>';

    // 米国株タブ
    } else {
        echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:15px;margin-bottom:20px;">';

        // セクタータブ（米国株）
        echo '<div style="margin-bottom:12px;border-bottom:1px solid #eee;padding-bottom:10px;">';
        foreach ($us_sectors as $key => $label) {
            $active = $us_sector_filter === $key;
            $url    = $base_url . '&tab=us&us_sector=' . urlencode($key) . '&sort=' . $sort_by . '&dir=' . $sort_dir;
            echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:4px 12px;margin-right:3px;margin-bottom:4px;border-radius:3px;text-decoration:none;font-size:12px;'
                . ($active ? 'background:#3498db;color:#fff;' : 'background:#f0f0f0;color:#333;') . '">' . esc_html($label) . '</a>';
        }
        echo '</div>';

        $render_table($us_paged, true, $us_total, $us_page, 'us_paged', $us_offset);
        echo '</div>';
    }

    echo '</div>';
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
// 銘柄管理ページ
// --------------------------------------------------
function wp_stocks_manager_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'stocks';

if (isset($_POST['wp_stocks_add'])) {
    check_admin_referer('wp_stocks_add_nonce');
    $code          = sanitize_text_field($_POST['code']);
    $code_original = $code;
    $code          = strtoupper($code);
    if ($code !== $code_original) {
        echo '<div class="notice notice-warning"><p>&#x26A0;&#xFE0F; 銘柄コードを自動的に大文字に変換しました：' . esc_html($code_original) . ' → ' . esc_html($code) . '（Webull等のAPIは大文字・小文字を区別するため）</p></div>';
    }
    $name    = sanitize_text_field($_POST['name'] ?? '');
    $shikiho = wp_stocks_sanitize_shikiho_text($_POST['shikiho'] ?? ''); // ★追加：四季報入力
    $is_sector_etf_input = isset($_POST['is_sector_etf']) ? 1 : 0; // ★追加：TOPIX-17等セクターETFフラグ
    if (empty($name)) {
        $auth = wp_stocks_get_crumb();
        if ($auth) {
            $res  = wp_remote_get('https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . $code . '.T?modules=assetProfile&crumb=' . urlencode($auth['crumb']), ['headers' => ['User-Agent' => 'Mozilla/5.0', 'Accept' => 'application/json', 'Cookie' => $auth['cookie']], 'timeout' => 15]);
            $body = json_decode(wp_remote_retrieve_body($res), true);
            $name = $body['quoteSummary']['result'][0]['assetProfile']['longName'] ?? '';
        }
        if (empty($name)) {
            $res2  = wp_remote_get('https://query1.finance.yahoo.com/v8/finance/chart/' . $code . '.T?interval=1d&range=1d', ['headers' => ['User-Agent' => 'Mozilla/5.0'], 'timeout' => 10]);
            $body2 = json_decode(wp_remote_retrieve_body($res2), true);
            $name  = $body2['chart']['result'][0]['meta']['longName'] ?? $body2['chart']['result'][0]['meta']['shortName'] ?? '';
        }
        if (empty($name)) $name = $code;
    }
    $currency = sanitize_text_field($_POST['currency'] ?? 'JPY');
    if (!in_array($currency, ['JPY', 'USD'])) $currency = 'JPY';
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE code = %s", $code));
    if ($existing) {
        echo '<div class="error"><p>銘柄コード「' . esc_html($code) . '」はすでに登録されています。</p></div>';
    } else {
        $wpdb->insert($table, ['code' => $code, 'name' => $name, 'currency' => $currency, 'status' => 'watch', 'is_sector_etf' => $is_sector_etf_input, 'created_at' => current_time('mysql')]);
        $new_id = $wpdb->insert_id;

        // ★追加：日本株のみ四季報・テーマタグを処理
        $theme_fetched     = '';
        $sector_from_shikiho = '';
        if ($currency === 'JPY') {
            $extra = [];
            if (!empty($shikiho)) {
                $extra['shikiho']            = $shikiho;
                $extra['shikiho_updated_at'] = current_time('mysql');
                $sector_from_shikiho = wp_stocks_extract_sector_from_shikiho($shikiho);
                if (!empty($sector_from_shikiho)) {
                    $extra['sector'] = $sector_from_shikiho;
                }
            }
            $theme_fetched = wp_stocks_scrape_kabutan_theme($code);
            if (!empty($theme_fetched)) {
                $extra['theme_tags'] = $theme_fetched;
            }
            if (!empty($extra)) {
                $wpdb->update($table, $extra, ['id' => $new_id]);
            }
        }

        
        $symbol_api = $currency === 'USD' ? $code : $code . '.T';
        wp_stocks_save_company_info($new_id, $symbol_api);
        wp_stocks_backfill_price_history($new_id, $symbol_api, 30);

        $msg = '「' . esc_html($name) . '」を登録し、企業情報を自動取得しました。';
        if (!empty($shikiho))       $msg .= ' 四季報情報を保存しました。';
        if (!empty($theme_fetched)) $msg .= ' テーマ（' . substr_count($theme_fetched, ',') + 1 . '件）を自動取得しました。';
        echo '<div class="updated"><p>' . $msg . '</p></div>';
    }
}

     if (isset($_GET['delete']) && check_admin_referer('wp_stocks_delete_' . intval($_GET['delete']))) {
        $del_id = intval($_GET['delete']);
        $wpdb->delete($table, ['id' => $del_id]);
        $wpdb->delete($wpdb->prefix . 'stock_prices',     ['stock_id' => $del_id]);
        $wpdb->delete($wpdb->prefix . 'stock_technicals', ['stock_id' => $del_id]);
        $wpdb->delete($wpdb->prefix . 'stock_news',       ['stock_id' => $del_id]);
        $wpdb->delete($wpdb->prefix . 'stock_ai_history', ['stock_id' => $del_id]);
        echo '<div class="updated"><p>銘柄を削除しました（関連データも削除済み）。</p></div>';
    }

    if (isset($_GET['message'])) {
        $msgs = ['price_saved' => ['updated','株価を取得・保存しました。'], 'price_error' => ['error','株価の取得に失敗しました。'], 'info_saved' => ['updated','企業情報を更新しました。'], 'info_error' => ['error','企業情報の取得に失敗しました。'], 'name_saved' => ['updated','銘柄名を更新しました。'], 'sector_saved' => ['updated','セクターを更新しました。'], 'fin_saved' => ['updated','財務データを取得しました。'], 'fin_error' => ['error','財務データの取得に失敗しました。EDINETコードが見つからないか、APIキーを確認してください。'], 'code_saved' => ['updated','銘柄コードを更新しました。'], 'code_duplicate' => ['error','そのコードは既に別の銘柄で使用されています。'], 'code_error' => ['error','銘柄コードの更新に失敗しました。コードを入力してください。']];
        if (isset($msgs[$_GET['message']])) { [$cls,$txt] = $msgs[$_GET['message']]; echo '<div class="' . $cls . '"><p>' . $txt . '</p></div>'; }
    }

    $stocks = $wpdb->get_results("SELECT * FROM $table ORDER BY status, id DESC");

    echo '<div class="wrap"><h1>銘柄管理</h1>';
echo '<h2>銘柄を追加</h2><form method="post">';
wp_nonce_field('wp_stocks_add_nonce');
echo '<div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">';
echo '<input type="text" name="code" id="add_code" placeholder="銘柄コード（例：9432 または AAPL）" required style="width:180px;">';
echo '<select name="currency" id="add_currency" onchange="toggleShikihoField()" style="margin-right:5px;"><option value="JPY">🇯🇵 日本株（JPY）</option><option value="USD">🇺🇸 米国株（USD）</option></select>';
echo '<input type="text" name="name" placeholder="銘柄名（省略可・自動取得）" style="width:200px;">';
echo '<label style="font-size:12px;color:#555;white-space:nowrap;"><input type="checkbox" name="is_sector_etf" value="1"> TOPIX-17等セクターETF</label>';
echo '<button type="submit" name="wp_stocks_add" class="button button-primary">追加（企業情報も自動取得）</button>';
echo '</div>';

// ★追加：四季報入力欄（日本株選択時のみ表示）
echo '<div id="shikiho_field_wrap">';
echo '<label style="display:block;font-size:12px;color:#555;margin-bottom:4px;">四季報情報（任意・日本株のみ）</label>';
echo '<textarea name="shikiho" style="width:100%;max-width:600px;height:120px;font-family:monospace;font-size:12px;padding:8px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="四季報の情報をそのまま貼り付けてください（任意）..."></textarea>';
echo '</div>';
echo '</form>';
echo '<p style="color:#888;font-size:12px;margin-top:5px;">※ 銘柄名を省略するとYahoo Financeから自動取得します。<br>※ 日本株の場合、株探からテーマ情報を自動取得し、テーマタグに自動入力します（取得できない場合は空欄になります）。</p>';

echo '<script>
function toggleShikihoField() {
    var wrap = document.getElementById("shikiho_field_wrap");
    var cur  = document.getElementById("add_currency").value;
    wrap.style.display = (cur === "JPY") ? "block" : "none";
}
toggleShikihoField();
</script>';
    echo '<h2>登録済み銘柄</h2>';
    if (!$stocks) { echo '<p>銘柄が登録されていません。</p></div>'; return; }

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>コード</th><th>銘柄名</th><th>区分</th><th>市場/業種</th><th>市場区分</th><th>最新株価</th><th>前日比</th><th>企業情報更新日</th><th>操作</th></tr></thead><tbody>';
    foreach ($stocks as $s) {
        $latest      = $wpdb->get_row($wpdb->prepare("SELECT price, previous_close, datetime FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $s->id));
        $price_str   = $latest ? number_format($latest->price) . '円 <small>(' . $latest->datetime . ')</small>' : '未取得';
        $change_html = ($latest && $latest->previous_close) ? wp_stocks_change_html($latest->price, $latest->previous_close, ($s->currency ?? 'JPY') === 'USD') : '-';
        $market_info = trim(($s->market ?? '') . ' ' . ($s->sector ?? ''));
        $info_date   = $s->info_updated_at ? date('Y/m/d', strtotime($s->info_updated_at)) : '未取得';
        $status_label = $s->status === 'portfolio' ? '<span style="background:#27ae60;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">保有中</span>' : '<span style="background:#3498db;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">ウォッチ</span>';
        $delete_url  = wp_nonce_url(admin_url('admin.php?page=wp-stocks-manager&delete=' . $s->id), 'wp_stocks_delete_' . $s->id);
        $fetch_url   = wp_nonce_url(admin_url('admin-post.php?action=get_stock_price&id=' . $s->id), 'wp_stocks_action_' . $s->id);
        $info_url    = wp_nonce_url(admin_url('admin-post.php?action=update_company_info&id=' . $s->id . '&from=wp-stocks-manager'), 'wp_stocks_action_' . $s->id);
        $detail_url  = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
        echo '<tr>';
        // 銘柄コードインライン編集
        $code_form = '<form method="post" action="' . admin_url('admin-post.php') . '" style="display:inline-flex;gap:4px;align-items:center;">';
        $code_form .= '<input type="hidden" name="action" value="update_stock_code">';
        $code_form .= '<input type="hidden" name="stock_id" value="' . esc_attr($s->id) . '">';
        $code_form .= wp_nonce_field('wp_stocks_code_nonce', '_wpnonce', true, false);
        $code_form .= '<span id="code-text-' . $s->id . '">' . esc_html($s->code) . '</span>';
        $code_form .= '<input type="text" name="stock_code" value="' . esc_attr($s->code) . '" style="width:90px;font-size:12px;padding:2px 6px;display:none;" class="code-input-' . $s->id . '">';
        $code_form .= '<button type="button" class="button button-small" onclick="toggleCodeEdit(' . $s->id . ')" id="code-edit-btn-' . $s->id . '">✏️</button>';
        $code_form .= '<button type="submit" class="button button-small button-primary" style="display:none;" id="code-save-btn-' . $s->id . '">保存</button>';
        $code_form .= '<button type="button" class="button button-small" style="display:none;" id="code-cancel-btn-' . $s->id . '" onclick="cancelCodeEdit(' . $s->id . ')">✕</button>';
        $code_form .= '</form>';
        echo '<td>' . $code_form . '</td>';
        // 銘柄名インライン編集
        $name_form = '<form method="post" action="' . admin_url('admin-post.php') . '" style="display:inline-flex;gap:4px;align-items:center;">';
        $name_form .= '<input type="hidden" name="action" value="update_stock_name">';
        $name_form .= '<input type="hidden" name="stock_id" value="' . esc_attr($s->id) . '">';
        $name_form .= wp_nonce_field('wp_stocks_name_nonce', '_wpnonce', true, false);
        $name_form .= '<a href="' . esc_url($detail_url) . '" style="margin-right:4px;">' . esc_html($s->name) . '</a>';
        $name_form .= '<input type="text" name="stock_name" value="' . esc_attr($s->name) . '" style="width:160px;font-size:12px;padding:2px 6px;display:none;" class="name-input-' . $s->id . '">';
        $name_form .= '<button type="button" class="button button-small" onclick="toggleNameEdit(' . $s->id . ')" id="edit-btn-' . $s->id . '">✏️</button>';
        $name_form .= '<button type="submit" class="button button-small button-primary" style="display:none;" id="save-btn-' . $s->id . '">保存</button>';
        $name_form .= '<button type="button" class="button button-small" style="display:none;" id="cancel-btn-' . $s->id . '" onclick="cancelNameEdit(' . $s->id . ')">✕</button>';
        $name_form .= '</form>';
        echo '<td>' . $name_form . '</td>';
        echo '<td>' . $status_label . '</td>';
        // セクターインライン編集
        $sector_form = '<form method="post" action="' . admin_url('admin-post.php') . '" style="display:inline-flex;gap:4px;align-items:center;">';
        $sector_form .= '<input type="hidden" name="action" value="update_sector">';
        $sector_form .= '<input type="hidden" name="stock_id" value="' . esc_attr($s->id) . '">';
        $sector_form .= wp_nonce_field('wp_stocks_sector_nonce', '_wpnonce', true, false);
        $sector_form .= '<span id="sector-text-' . $s->id . '">' . esc_html($s->sector ?? '-') . '</span>';
        $sector_form .= '<input type="text" name="stock_sector" value="' . esc_attr($s->sector ?? '') . '" style="width:120px;font-size:12px;padding:2px 6px;display:none;" class="sector-input-' . $s->id . '">';
        $sector_form .= '<button type="button" class="button button-small" onclick="toggleSectorEdit(' . $s->id . ')" id="sector-edit-btn-' . $s->id . '">✏️</button>';
        $sector_form .= '<button type="submit" class="button button-small button-primary" style="display:none;" id="sector-save-btn-' . $s->id . '">保存</button>';
        $sector_form .= '<button type="button" class="button button-small" style="display:none;" id="sector-cancel-btn-' . $s->id . '" onclick="cancelSectorEdit(' . $s->id . ')">✕</button>';
        $sector_form .= '</form>';
        echo '<td>' . $sector_form . '</td>';
        // 市場区分インライン編集
        $market_form = '<form method="post" action="' . admin_url('admin-post.php') . '" style="display:inline-flex;gap:4px;align-items:center;">';
        $market_form .= '<input type="hidden" name="action" value="update_market_segment">';
        $market_form .= '<input type="hidden" name="stock_id" value="' . esc_attr($s->id) . '">';
        $market_form .= wp_nonce_field('wp_stocks_market_segment_nonce', '_wpnonce', true, false);
        $market_form .= '<span id="market-text-' . $s->id . '">' . esc_html($s->market ?: '-') . '</span>';
        $market_form .= '<select name="market_segment" style="width:110px;font-size:12px;padding:2px 4px;display:none;" class="market-input-' . $s->id . '">';
        foreach (['' => '未設定', 'プライム' => 'プライム', 'スタンダード' => 'スタンダード', 'グロース' => 'グロース'] as $mval => $mlabel) {
            $msel = ($s->market ?? '') === $mval ? 'selected' : '';
            $market_form .= '<option value="' . esc_attr($mval) . '" ' . $msel . '>' . esc_html($mlabel) . '</option>';
        }
        $market_form .= '</select>';
        $market_form .= '<button type="button" class="button button-small" onclick="toggleMarketEdit(' . $s->id . ')" id="market-edit-btn-' . $s->id . '">✏️</button>';
        $market_form .= '<button type="submit" class="button button-small button-primary" style="display:none;" id="market-save-btn-' . $s->id . '">保存</button>';
        $market_form .= '<button type="button" class="button button-small" style="display:none;" id="market-cancel-btn-' . $s->id . '" onclick="cancelMarketEdit(' . $s->id . ')">✕</button>';
        $market_form .= '</form>';
        echo '<td>' . $market_form . '</td>';
        echo '<td>' . $price_str . '</td>';
        echo '<td>' . $change_html . '</td>';
        echo '<td>' . esc_html($info_date) . '</td>';
        echo '<td><a href="' . esc_url($fetch_url) . '" class="button button-small">株価取得</a> <a href="' . esc_url($info_url) . '" class="button button-small">企業情報更新</a> <a href="' . esc_url($delete_url) . '" class="button button-small" onclick="return confirm(\'削除しますか？\');" style="color:red;">削除</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    ?>
    <script>
    function toggleNameEdit(id) {
        document.querySelector('.name-input-' + id).style.display = 'inline-block';
        document.querySelector('.name-input-' + id).previousElementSibling.style.display = 'none';
        document.getElementById('edit-btn-' + id).style.display = 'none';
        document.getElementById('save-btn-' + id).style.display = 'inline-block';
        document.getElementById('cancel-btn-' + id).style.display = 'inline-block';
        document.querySelector('.name-input-' + id).focus();
        document.querySelector('.name-input-' + id).select();
    }
    function toggleSectorEdit(id) {
        document.querySelector('.sector-input-' + id).style.display = 'inline-block';
        document.getElementById('sector-text-' + id).style.display = 'none';
        document.getElementById('sector-edit-btn-' + id).style.display = 'none';
        document.getElementById('sector-save-btn-' + id).style.display = 'inline-block';
        document.getElementById('sector-cancel-btn-' + id).style.display = 'inline-block';
        document.querySelector('.sector-input-' + id).focus();
        document.querySelector('.sector-input-' + id).select();
    }
    function cancelSectorEdit(id) {
        document.querySelector('.sector-input-' + id).style.display = 'none';
        document.getElementById('sector-text-' + id).style.display = 'inline';
        document.getElementById('sector-edit-btn-' + id).style.display = 'inline-block';
        document.getElementById('sector-save-btn-' + id).style.display = 'none';
        document.getElementById('sector-cancel-btn-' + id).style.display = 'none';
    }
    function toggleMarketEdit(id) {
        document.querySelector('.market-input-' + id).style.display = 'inline-block';
        document.getElementById('market-text-' + id).style.display = 'none';
        document.getElementById('market-edit-btn-' + id).style.display = 'none';
        document.getElementById('market-save-btn-' + id).style.display = 'inline-block';
        document.getElementById('market-cancel-btn-' + id).style.display = 'inline-block';
        document.querySelector('.market-input-' + id).focus();
    }
    function cancelMarketEdit(id) {
        document.querySelector('.market-input-' + id).style.display = 'none';
        document.getElementById('market-text-' + id).style.display = 'inline';
        document.getElementById('market-edit-btn-' + id).style.display = 'inline-block';
        document.getElementById('market-save-btn-' + id).style.display = 'none';
        document.getElementById('market-cancel-btn-' + id).style.display = 'none';
    }
    function cancelNameEdit(id) {
        document.querySelector('.name-input-' + id).style.display = 'none';
        document.querySelector('.name-input-' + id).previousElementSibling.style.display = 'inline';
        document.getElementById('edit-btn-' + id).style.display = 'inline-block';
        document.getElementById('save-btn-' + id).style.display = 'none';
        document.getElementById('cancel-btn-' + id).style.display = 'none';
    }
    function toggleCodeEdit(id) {
        document.querySelector('.code-input-' + id).style.display = 'inline-block';
        document.getElementById('code-text-' + id).style.display = 'none';
        document.getElementById('code-edit-btn-' + id).style.display = 'none';
        document.getElementById('code-save-btn-' + id).style.display = 'inline-block';
        document.getElementById('code-cancel-btn-' + id).style.display = 'inline-block';
        document.querySelector('.code-input-' + id).focus();
        document.querySelector('.code-input-' + id).select();
    }
    function cancelCodeEdit(id) {
        document.querySelector('.code-input-' + id).style.display = 'none';
        document.getElementById('code-text-' + id).style.display = 'inline';
        document.getElementById('code-edit-btn-' + id).style.display = 'inline-block';
        document.getElementById('code-save-btn-' + id).style.display = 'none';
        document.getElementById('code-cancel-btn-' + id).style.display = 'none';
    }
    </script>
    <?php
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

    {
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

    }
    echo '<h2 style="border-left:4px solid #0073aa;padding-left:10px;margin-top:30px;">四半期</h2>';

    {
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
    if (isset($_GET['message']) && $_GET['message'] === 'market_segment_saved') {
        echo '<div class="updated"><p>市場区分を保存しました。</p></div>';
    }
    // 市場区分（日本株のみ）
    if (!$is_usd) {
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
// 投資信託管理ページ
// --------------------------------------------------
function wp_stocks_funds_page() {
    global $wpdb;

    // 登録処理
    if (isset($_POST['wp_stocks_add_fund'])) {
        check_admin_referer('wp_stocks_fund_nonce');
        $fund_code     = sanitize_text_field($_POST['fund_code']);
        $fund_name     = sanitize_text_field($_POST['fund_name']);
        $fund_units    = floatval($_POST['fund_units']);
        $cost_per_unit = floatval($_POST['cost_per_unit']);
        $fund_type     = sanitize_text_field($_POST['fund_type']);
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}stock_funds WHERE fund_code = %s AND fund_type = %s",
            $fund_code, $fund_type
        ));
        if ($existing) {
            echo '<div class="error"><p>このファンドコードはすでに登録されています。</p></div>';
        } else {
            $wpdb->insert($wpdb->prefix . 'stock_funds', [
                'fund_code'     => $fund_code,
                'fund_name'     => $fund_name,
                'fund_units'    => $fund_units,
                'cost_per_unit' => $cost_per_unit,
                'fund_type'     => $fund_type,
                'created_at'    => current_time('mysql'),
            ]);
            $new_id = $wpdb->insert_id;
            wp_stocks_save_fund_price($new_id, $fund_code);
            echo '<div class="updated"><p>「' . esc_html($fund_name) . '」を登録し、基準価額を取得しました。</p></div>';
        }
    }

    // 削除処理
    if (isset($_GET['delete_fund']) && check_admin_referer('wp_stocks_delete_fund_' . intval($_GET['delete_fund']))) {
        $fid = intval($_GET['delete_fund']);
        $wpdb->delete($wpdb->prefix . 'stock_fund_prices', ['fund_id' => $fid]);
        $wpdb->delete($wpdb->prefix . 'stock_funds', ['id' => $fid]);
        echo '<div class="updated"><p>ファンドを削除しました。</p></div>';
    }

    // 口数・取得単価更新
    if (isset($_POST['wp_stocks_update_fund'])) {
        check_admin_referer('wp_stocks_fund_update_nonce');
        $fid           = intval($_POST['fund_id']);
        $fund_units    = floatval($_POST['fund_units']);
        $cost_per_unit = floatval($_POST['cost_per_unit']);
        $fund_type     = sanitize_text_field($_POST['fund_type']);
        $wpdb->update($wpdb->prefix . 'stock_funds',
            ['fund_units' => $fund_units, 'cost_per_unit' => $cost_per_unit, 'fund_type' => $fund_type],
            ['id' => $fid]
        );
        echo '<div class="updated"><p>ファンド情報を更新しました。</p></div>';
    }

    $funds = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_funds ORDER BY fund_type, id");

    echo '<div class="wrap"><h1>📊 投資信託管理</h1>';

    // 登録フォーム
    echo '<h2>ファンドを追加</h2>';
    echo '<form method="post" style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:800px;margin-bottom:25px;">';
    wp_nonce_field('wp_stocks_fund_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th style="width:160px;">ファンドコード</th><td><input type="text" name="fund_code" placeholder="例：03316183" required style="width:180px;"> <small style="color:#888;"><a href="https://finance.yahoo.co.jp/fund/search" target="_blank">Yahoo!ファイナンスで検索</a></small></td></tr>';
    echo '<tr><th>ファンド名</th><td><input type="text" name="fund_name" placeholder="例：eMAXIS Slim 全世界株式（除く日本）" required style="width:400px;"></td></tr>';
    echo '<tr><th>保有口数</th><td><input type="number" name="fund_units" placeholder="例：140878" step="1" required style="width:150px;"> 口</td></tr>';
    echo '<tr><th>取得単価</th><td><input type="number" name="cost_per_unit" placeholder="例：34073" step="0.01" required style="width:150px;"> 円（1万口あたり）</td></tr>';
    echo '<tr><th>区分</th><td><select name="fund_type"><option value="growth">NISA成長投資枠</option><option value="tsumitate">NISAつみたて投資枠</option><option value="tokutei">特定口座</option></select></td></tr>';
    echo '</tbody></table>';
    echo '<p><button type="submit" name="wp_stocks_add_fund" class="button button-primary">登録（基準価額も自動取得）</button></p>';
    echo '</form>';

    if (!$funds) { echo '<p>ファンドが登録されていません。</p></div>'; return; }

    // サマリー計算
    $total_eval = $total_cost = $total_gain = 0;
    $fund_data = [];
    foreach ($funds as $f) {
        $prices = $wpdb->get_results($wpdb->prepare(
            "SELECT price, price_date FROM {$wpdb->prefix}stock_fund_prices WHERE fund_id = %d ORDER BY price_date DESC LIMIT 2",
            $f->id
        ));
        $today_price = $prices[0]->price ?? null;
        $prev_price  = $prices[1]->price ?? null;
        $eval_value  = $today_price ? ($f->fund_units * $today_price / 10000) : 0;
        $cost_value  = $f->fund_units * $f->cost_per_unit / 10000;
        $gain        = $eval_value - $cost_value;
        $gain_pct    = $cost_value > 0 ? ($gain / $cost_value * 100) : 0;
        $day_change  = ($today_price && $prev_price) ? $today_price - $prev_price : null;
        $day_change_pct = ($prev_price && $prev_price > 0 && $day_change !== null) ? ($day_change / $prev_price * 100) : null;
        $total_eval += $eval_value;
        $total_cost += $cost_value;
        $total_gain += $gain;
        $fund_data[] = compact('f', 'today_price', 'prev_price', 'eval_value', 'cost_value', 'gain', 'gain_pct', 'day_change', 'day_change_pct');
    }

    // サマリーカード
    $gain_color = $total_gain >= 0 ? '#e74c3c' : '#3498db';
    echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:25px;">';
    foreach ([
        ['評価額合計', number_format($total_eval, 0) . '円', '#333'],
        ['取得額合計', number_format($total_cost, 0) . '円', '#333'],
        ['含み損益',   ($total_gain >= 0 ? '+' : '') . number_format($total_gain, 0) . '円', $gain_color],
    ] as [$label, $val, $color]) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;text-align:center;">';
        echo '<div style="font-size:12px;color:#888;margin-bottom:6px;">' . $label . '</div>';
        echo '<div style="font-size:18px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // ファンド一覧
    $type_labels  = ['growth' => 'NISA成長投資枠', 'tsumitate' => 'NISAつみたて投資枠', 'tokutei' => '特定口座'];
    $current_type = null;

    echo '<table class="widefat striped" style="font-size:13px;">';
    echo '<thead><tr>';
    foreach (['ファンド名', '区分', '基準価額', '前日比', '口数', '評価額', '含み損益', '含み率', 'チャート', '操作'] as $h) {
        echo '<th>' . $h . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($fund_data as $row) {
        extract($row);

        if ($f->fund_type !== $current_type) {
            $current_type = $f->fund_type;
            echo '<tr style="background:#e8f4f8;"><td colspan="10" style="font-weight:bold;padding:8px 12px;">'
                . esc_html($type_labels[$f->fund_type] ?? $f->fund_type) . '</td></tr>';
        }

        $gain_color2 = $gain >= 0 ? '#e74c3c' : '#3498db';

        if ($day_change !== null) {
            $dc_color = $day_change >= 0 ? '#e74c3c' : '#3498db';
            $dc_arrow = $day_change >= 0 ? '▲' : '▼';
            $day_change_html = '<span style="color:' . $dc_color . ';font-weight:bold;">'
                . $dc_arrow . ($day_change >= 0 ? '+' : '') . number_format($day_change, 0) . '円'
                . ' (' . ($day_change_pct >= 0 ? '+' : '') . number_format($day_change_pct, 2) . '%)'
                . '</span>';
        } else {
            $day_change_html = '<span style="color:#888;">-</span>';
        }

        $chart_url  = 'https://finance.yahoo.co.jp/quote/' . $f->fund_code . '/chart';
        $delete_url = wp_nonce_url(admin_url('admin.php?page=wp-stocks-funds&delete_fund=' . $f->id), 'wp_stocks_delete_fund_' . $f->id);
        $fetch_url  = wp_nonce_url(admin_url('admin-post.php?action=fetch_fund_price&id=' . $f->id), 'wp_stocks_action_' . $f->id);

        echo '<tr>';
        echo '<td><strong>' . esc_html($f->fund_name) . '</strong><br><small style="color:#888;">' . esc_html($f->fund_code) . '</small></td>';
        echo '<td><span style="font-size:11px;background:#ddeeff;padding:2px 6px;border-radius:3px;">' . esc_html($type_labels[$f->fund_type] ?? $f->fund_type) . '</span></td>';
        echo '<td>' . ($today_price ? number_format($today_price, 0) . '円' : '未取得') . '</td>';
        echo '<td>' . $day_change_html . '</td>';
        echo '<td>' . number_format($f->fund_units, 0) . '口</td>';
        echo '<td>' . ($eval_value > 0 ? number_format($eval_value, 0) . '円' : '-') . '</td>';
        echo '<td style="color:' . $gain_color2 . ';font-weight:bold;">' . ($gain >= 0 ? '+' : '') . number_format($gain, 0) . '円</td>';
        echo '<td style="color:' . $gain_color2 . ';font-weight:bold;">' . ($gain_pct >= 0 ? '+' : '') . number_format($gain_pct, 2) . '%</td>';
        echo '<td><a href="' . esc_url($chart_url) . '" target="_blank" class="button button-small">📈 チャート</a></td>';
        echo '<td><a href="' . esc_url($fetch_url) . '" class="button button-small">更新</a> <a href="' . esc_url($delete_url) . '" class="button button-small" style="color:red;" onclick="return confirm(\'このファンドを削除しますか？\');">削除</a></td>';
        echo '</tr>';

        // 編集行
        echo '<tr style="background:#fafafa;"><td colspan="10" style="padding:6px 12px;">';
        echo '<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
        wp_nonce_field('wp_stocks_fund_update_nonce');
        echo '<input type="hidden" name="fund_id" value="' . esc_attr($f->id) . '">';
        echo '<span style="font-size:12px;color:#888;">口数：</span>';
        echo '<input type="number" name="fund_units" value="' . esc_attr($f->fund_units) . '" step="1" style="width:120px;font-size:12px;">';
        echo '<span style="font-size:12px;color:#888;">取得単価（1万口）：</span>';
        echo '<input type="number" name="cost_per_unit" value="' . esc_attr($f->cost_per_unit) . '" step="0.01" style="width:100px;font-size:12px;">';
        echo '<span style="font-size:12px;color:#888;">区分：</span>';
        echo '<select name="fund_type" style="font-size:12px;">';
        foreach ($type_labels as $val => $label) {
            $sel = $f->fund_type === $val ? 'selected' : '';
            echo '<option value="' . esc_attr($val) . '" ' . $sel . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" name="wp_stocks_update_fund" class="button button-small">保存</button>';
        echo '</form></td></tr>';
    }
    echo '</tbody></table></div>';
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

    $tab      = in_array($_GET['tab'] ?? '', ['jp', 'us', 'portfolio', 'etf']) ? $_GET['tab'] : 'jp';
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
        $is_usd     = ($s->currency ?? 'JPY') === 'USD';
        $sector_key = !empty($s->sector) ? $s->sector : 'その他';
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
        'etf'       => '&#x1F3ED; TOPIX-17（' . count($etf_stocks) . '銘柄）',
    ] as $key => $label) {
        $active = $tab === $key;
        $url    = $base_url . '&tab=' . $key;
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;margin-bottom:20px;">';

    // 凡例（前日比の色分け）を上部に表示
    echo '<div style="margin-bottom:20px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;color:#555;">';
    echo '<strong>凡例：</strong> ';
    echo '<span style="display:inline-block;background:rgb(40,220,40);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25B2;+5%以上</span>';
    echo '<span style="display:inline-block;background:rgb(30,150,30);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25B2;+1〜5%</span>';
    echo '<span style="display:inline-block;background:#888;color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">0%</span>';
    echo '<span style="display:inline-block;background:rgb(150,30,30);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25BC;-1〜5%</span>';
    echo '<span style="display:inline-block;background:rgb(220,40,40);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25BC;-5%以上</span>';
    echo '<span style="display:inline-block;background:#aaa;color:#fff;padding:2px 10px;border-radius:3px;">未取得</span>';
    echo '</div>';

    if ($tab === 'jp') {
        $render_sectors($jp_sectors, '#e74c3c');
    } elseif ($tab === 'us') {
        $render_sectors($us_sectors, '#3498db');
    } elseif ($tab === 'etf') {
        // ★移植：TOPIX-17セクターETFタブ（旧wp_stocks_render_heatmap_tab()の該当ロジックを移植）
        if (empty($etf_stocks)) {
            echo '<p style="color:#888;padding:20px;">TOPIX-17セクターETFが登録されていません。</p>';
        } else {
            $topix17_map = wp_stocks_get_topix17_sector_map();
            echo '<div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:6px;">';
            foreach ($etf_stocks as $s) {
                $p           = $price_map[$s->id] ?? null;
                $detail_url  = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
                $map_info    = $topix17_map[$s->code] ?? null;
                $sector_note = $map_info ? implode('・', $map_info['sectors']) : '';
                if ($p && $p->previous_close > 0) {
                    $pct      = ($p->price - $p->previous_close) / $p->previous_close * 100;
                    $bg_color = wp_stocks_change_heat_color($pct);
                    $sub      = number_format($p->price) . '円' . ($sector_note ? '（' . $sector_note . '）' : '');
                } else {
                    $pct      = 0;
                    $bg_color = '#aaa';
                    $sub      = '未取得' . ($sector_note ? '（' . $sector_note . '）' : '');
                }
                $pct_str = ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%';
                echo '<a href="' . esc_url($detail_url) . '" style="text-decoration:none;">';
                echo '<div style="background:' . $bg_color . ';border-radius:6px;padding:10px 8px;text-align:center;'
                    . 'color:#fff;min-height:80px;display:flex;flex-direction:column;'
                    . 'justify-content:center;align-items:center;gap:3px;cursor:pointer;transition:opacity 0.2s;"'
                    . ' onmouseover="this.style.opacity=\'0.8\'" onmouseout="this.style.opacity=\'1\'">';
                echo '<div style="font-size:11px;opacity:0.85;">' . esc_html($s->code) . '</div>';
                echo '<div style="font-size:12px;font-weight:bold;line-height:1.3;">' . esc_html(mb_substr($s->name, 0, 8)) . '</div>';
                echo '<div style="font-size:14px;font-weight:bold;">' . esc_html($pct_str) . '</div>';
                if ($sub) echo '<div style="font-size:10px;opacity:0.85;">' . esc_html($sub) . '</div>';
                echo '</div></a>';
            }
            echo '</div>';
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
// 運用メモページ
// --------------------------------------------------
function wp_stocks_daily_memos_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'stock_daily_memos';

    if (isset($_POST['wp_stocks_add_memo'])) {
        check_admin_referer('wp_stocks_memo_nonce');
        $memo_date    = sanitize_text_field($_POST['memo_date'] ?? '');
        $memo_content = sanitize_textarea_field($_POST['memo_content'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $memo_date) && $memo_content !== '') {
            $wpdb->insert($table, ['memo_date' => $memo_date, 'content' => $memo_content]);
            echo '<div class="notice notice-success"><p>メモを追加しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>日付または内容が正しくありません。</p></div>';
        }
    }

    if (isset($_GET['delete']) && check_admin_referer('wp_stocks_memo_delete_' . intval($_GET['delete']))) {
        $wpdb->delete($table, ['id' => intval($_GET['delete'])]);
        echo '<div class="notice notice-success"><p>メモを削除しました。</p></div>';
    }

    echo '<div class="wrap"><h1>&#x1F4D3; 運用メモ</h1>';
    echo '<p style="color:#666;font-size:13px;">相場全体の気づきや決算結果などを日付ごとに記録できます。本文中に銘柄コード（例：285A、9432）を書くと自動でリンクになります。</p>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:25px;max-width:700px;">';
    echo '<form method="post">';
    wp_nonce_field('wp_stocks_memo_nonce');
    echo '<div style="margin-bottom:10px;"><input type="date" name="memo_date" value="' . esc_attr(date('Y-m-d')) . '" required></div>';
    echo '<textarea name="memo_content" style="width:100%;height:100px;font-size:13px;padding:8px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="例：285A QPS研究所 決算発表。売上は予想上振れ、株価急伸。" required></textarea>';
    echo '<p style="margin-top:8px;"><button type="submit" name="wp_stocks_add_memo" class="button button-primary">メモを追加</button></p>';
    echo '</form>';
    echo '</div>';

    $memos = $wpdb->get_results("SELECT * FROM $table ORDER BY memo_date DESC, id DESC");
    if (!$memos) {
        echo '<p style="color:#888;">まだメモがありません。</p>';
    } else {
        $current_date = null;
        foreach ($memos as $m) {
            if ($m->memo_date !== $current_date) {
                if ($current_date !== null) echo '</div>';
                $current_date = $m->memo_date;
                echo '<h3 style="margin-top:20px;border-left:4px solid #0073aa;padding-left:10px;">' . esc_html(date('Y年n月j日', strtotime($current_date))) . '</h3>';
                echo '<div style="display:flex;flex-direction:column;gap:10px;max-width:700px;margin-bottom:10px;">';
            }
            $del_url = wp_nonce_url(admin_url('admin.php?page=wp-stocks-memos&delete=' . $m->id), 'wp_stocks_memo_delete_' . $m->id);
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 15px;">';
            echo '<div style="font-size:13px;line-height:1.7;">' . wp_stocks_linkify_memo($m->content) . '</div>';
            echo '<div style="text-align:right;margin-top:6px;"><a href="' . esc_url($del_url) . '" onclick="return confirm(\'削除しますか？\');" style="font-size:11px;color:#e74c3c;text-decoration:none;">削除</a></div>';
            echo '</div>';
        }
        echo '</div>';
    }

    echo '</div>';
}

function wp_stocks_calendar_page() {
    global $wpdb;

    // 決算予定日の手動登録
    if (isset($_POST['wp_stocks_set_earnings'])) {
        check_admin_referer('wp_stocks_set_earnings_nonce');
        $stock_id = intval($_POST['stock_id'] ?? 0);
        $new_date = sanitize_text_field($_POST['earnings_date'] ?? '');
        if ($stock_id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
            $wpdb->update($wpdb->prefix . 'stocks', ['earnings_date' => $new_date], ['id' => $stock_id]);
            echo '<div class="notice notice-success"><p>決算予定日を登録しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>日付の形式が正しくありません。</p></div>';
        }
    }

    $view  = in_array($_GET['view'] ?? '', ['list','month'], true) ? $_GET['view'] : 'list';
    $today = date('Y-m-d');
    echo '<div class="wrap"><h1>📅 決算カレンダー</h1>';

    // 表示切り替え
    echo '<div style="margin-bottom:15px;">';
    foreach (['list' => '&#x1F4CB; 一覧', 'month' => '&#x1F5D3;&#xFE0F; カレンダー'] as $vkey => $vlabel) {
        $vactive = $view === $vkey;
        $vurl = add_query_arg(['view' => $vkey], admin_url('admin.php?page=wp-stocks-calendar'));
        echo '<a href="' . esc_url($vurl) . '" class="button" style="' . ($vactive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '') . 'font-weight:bold;margin-right:6px;">' . $vlabel . '</a>';
    }
    echo '</div>';

    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE earnings_date IS NOT NULL ORDER BY earnings_date ASC");

    if ($view === 'month') {
        wp_stocks_render_earnings_month_calendar($stocks, $today);
    } else {

    if (!$stocks) {
        echo '<p>決算予定日のデータがありません。各銘柄の「企業情報更新」を実行してください。</p>';
    } else {
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th style="width:80px;">コード</th><th>銘柄名</th><th style="width:130px;">決算予定日</th><th style="width:100px;">残り日数</th><th style="width:120px;">ステータス</th></tr></thead><tbody>';
    foreach ($stocks as $s) {
        if (empty($s->earnings_date)) continue;
        $days = (int)((strtotime($s->earnings_date) - strtotime($today)) / 86400);
        if ($days < 0)        { $days_str = abs($days) . '日前'; $row_style = 'color:#aaa;'; $badge = '<span style="background:#ddd;color:#888;padding:2px 8px;border-radius:3px;font-size:11px;">終了</span>'; }
        elseif ($days <= 7)   { $days_str = $days . '日後'; $row_style = 'color:#e74c3c;font-weight:bold;'; $badge = '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">直近</span>'; }
        elseif ($days <= 30)  { $days_str = $days . '日後'; $row_style = 'color:#f39c12;font-weight:bold;'; $badge = '<span style="background:#f39c12;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">今月</span>'; }
        else                  { $days_str = $days . '日後'; $row_style = ''; $badge = ''; }
        $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
        echo '<tr style="' . $row_style . '"><td>' . esc_html($s->code) . '</td><td><a href="' . esc_url($detail_url) . '">' . esc_html($s->name) . '</a></td><td>' . esc_html($s->earnings_date) . '</td><td style="' . $row_style . '">' . esc_html($days_str) . '</td><td>' . $badge . '</td></tr>';
    }
    echo '</tbody></table>';
    }

    // 決算予定日が未取得の銘柄一覧＋手動登録フォーム
    $missing_stocks = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio') AND earnings_date IS NULL AND is_sector_etf = 0 ORDER BY code ASC"
    );
    if ($missing_stocks) {
        echo '<h2 style="margin-top:30px;">⚠️ 決算予定日が未取得の銘柄（' . count($missing_stocks) . '件）</h2>';
        echo '<p class="description">Yahoo Financeがまだ決算予定日を公開していない銘柄です。判明していれば手動で登録できます。</p>';
        echo '<table class="widefat fixed striped" style="max-width:600px;">';
        echo '<thead><tr><th style="width:80px;">コード</th><th>銘柄名</th><th style="width:260px;">決算予定日を登録</th></tr></thead><tbody>';
        foreach ($missing_stocks as $ms) {
            echo '<tr><td>' . esc_html($ms->code) . '</td><td>' . esc_html($ms->name) . '</td><td>';
            echo '<form method="post" style="display:flex;gap:6px;align-items:center;">';
            wp_nonce_field('wp_stocks_set_earnings_nonce');
            echo '<input type="hidden" name="stock_id" value="' . intval($ms->id) . '">';
            echo '<input type="date" name="earnings_date" required style="font-size:12px;">';
            echo '<button type="submit" name="wp_stocks_set_earnings" class="button button-small">登録</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    } // end view=list

    echo '</div>';
}

// --------------------------------------------------
// 適時開示ページ（旧・独立ページ）は企業情報ページの
// 「適時開示」タブに統合されたため廃止。
// --------------------------------------------------

// --------------------------------------------------
// インポート/エクスポートページ
// --------------------------------------------------
function wp_stocks_importexport_page() {
    global $wpdb;
    echo '<div class="wrap"><h1>インポート / エクスポート</h1>';

    if (isset($_POST['wp_stocks_export'])) {
        check_admin_referer('wp_stocks_export_nonce');
        $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks ORDER BY id ASC");
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wp_stocks_export_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache'); header('Expires: 0');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['コード','銘柄名','ステータス','セクター','業種','市場','PER','PBR','ROE','ROA','PEG','EPS','EPS予想','配当利回り','自己資本比率','利益率','売上成長率','利益成長率','時価総額','売上高','純利益','従業員数','平均取得単価','保有株数','テーマタグ','決算予定日','情報更新日']);
        foreach ($stocks as $s) {
            fputcsv($out, [$s->code,$s->name,$s->status,$s->sector,$s->industry,$s->market,$s->per,$s->pbr,$s->roe,$s->roa,$s->peg,$s->eps,$s->forward_eps,$s->dividend_yield,$s->equity_ratio,$s->profit_margin,$s->revenue_growth,$s->earnings_growth,$s->market_cap,$s->revenue,$s->net_income,$s->employees,$s->purchase_price,$s->purchase_qty,$s->theme_tags,$s->earnings_date,$s->info_updated_at]);
        }
        fclose($out); exit;
    }

    if (isset($_POST['wp_stocks_import']) && isset($_FILES['import_file'])) {
        check_admin_referer('wp_stocks_import_nonce');
        $file = $_FILES['import_file']['tmp_name'];
        if (empty($file)) {
            echo '<div class="error"><p>ファイルを選択してください。</p></div>';
        } else {
            $handle = fopen($file, 'r'); $imported = $skipped = $row_num = 0;
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $row_num++;
                if ($row_num === 1) continue;
                if (empty($row[0])) continue;
                $code = sanitize_text_field($row[0]);
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stocks WHERE code = %s", $code))) { $skipped++; continue; }
                $wpdb->insert($wpdb->prefix . 'stocks', ['code' => $code, 'name' => sanitize_text_field($row[1] ?? $code), 'status' => in_array($row[2] ?? '', ['watch','portfolio']) ? $row[2] : 'watch', 'sector' => sanitize_text_field($row[3] ?? ''), 'industry' => sanitize_text_field($row[4] ?? ''), 'market' => sanitize_text_field($row[5] ?? ''), 'per' => floatval($row[6] ?? 0), 'pbr' => floatval($row[7] ?? 0), 'roe' => floatval($row[8] ?? 0), 'roa' => floatval($row[9] ?? 0), 'peg' => floatval($row[10] ?? 0), 'eps' => floatval($row[11] ?? 0), 'forward_eps' => floatval($row[12] ?? 0), 'dividend_yield' => floatval($row[13] ?? 0), 'equity_ratio' => floatval($row[14] ?? 0), 'profit_margin' => floatval($row[15] ?? 0), 'revenue_growth' => floatval($row[16] ?? 0), 'earnings_growth' => floatval($row[17] ?? 0), 'market_cap' => intval($row[18] ?? 0), 'revenue' => intval($row[19] ?? 0), 'net_income' => intval($row[20] ?? 0), 'employees' => intval($row[21] ?? 0), 'purchase_price' => floatval($row[22] ?? 0), 'purchase_qty' => intval($row[23] ?? 0), 'theme_tags' => sanitize_text_field($row[24] ?? ''), 'earnings_date' => !empty($row[25]) ? $row[25] : null, 'created_at' => current_time('mysql')]);
                $imported++;
            }
            fclose($handle);
            echo '<div class="updated"><p>' . $imported . '件インポートしました。' . ($skipped > 0 ? $skipped . '件スキップ。' : '') . '</p></div>';
        }
    }

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;max-width:900px;">';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;"><h2>📤 エクスポート</h2><p style="color:#666;font-size:13px;">全銘柄をCSVでダウンロードします。</p><form method="post">';
    wp_nonce_field('wp_stocks_export_nonce');
    echo '<button type="submit" name="wp_stocks_export" class="button button-primary">📥 CSVをダウンロード</button></form></div>';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;"><h2>📥 インポート</h2><p style="color:#666;font-size:13px;">CSVから銘柄を一括登録します。重複コードはスキップされます。</p><form method="post" enctype="multipart/form-data">';
    wp_nonce_field('wp_stocks_import_nonce');
    echo '<input type="file" name="import_file" accept=".csv" style="margin-bottom:10px;display:block;">';
    echo '<button type="submit" name="wp_stocks_import" class="button button-primary">📤 インポート実行</button></form></div>';
    echo '</div></div>';
}

// --------------------------------------------------
// ログページ
// --------------------------------------------------
function wp_stocks_logs_page() {
    global $wpdb;
    echo '<div class="wrap"><h1>📋 取得ログ</h1>';
    $level_filter = sanitize_text_field($_GET['level'] ?? '');
    echo '<form method="get" style="margin-bottom:15px;"><input type="hidden" name="page" value="wp-stocks-logs">';
    echo '<select name="level"><option value="">全レベル</option>';
    foreach (['info','error'] as $l) echo '<option value="' . $l . '" ' . ($level_filter === $l ? 'selected' : '') . '>' . strtoupper($l) . '</option>';
    echo '</select> <button type="submit" class="button">絞り込み</button> <a href="' . admin_url('admin.php?page=wp-stocks-logs') . '" class="button">リセット</a></form>';
    $where = $level_filter ? $wpdb->prepare(" WHERE level = %s", $level_filter) : '';
    $logs  = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_logs{$where} ORDER BY created_at DESC LIMIT 200");
    if (!$logs) { echo '<p>ログがありません。</p></div>'; return; }
    echo '<table class="widefat fixed striped"><thead><tr><th style="width:140px;">日時</th><th style="width:60px;">レベル</th><th style="width:100px;">処理</th><th style="width:80px;">銘柄</th><th>メッセージ</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $lc = $log->level === 'error' ? '#e74c3c' : '#27ae60';
        $lb = $log->level === 'error' ? '#fde8e8' : '#e8f8e8';
        echo '<tr><td style="font-size:12px;">' . esc_html($log->created_at) . '</td>';
        echo '<td><span style="background:' . $lb . ';color:' . $lc . ';padding:2px 6px;border-radius:3px;font-size:11px;font-weight:bold;">' . esc_html(strtoupper($log->level)) . '</span></td>';
        echo '<td style="font-size:12px;">' . esc_html($log->action) . '</td>';
        echo '<td style="font-size:12px;">' . esc_html($log->symbol) . '</td>';
        echo '<td style="font-size:12px;">' . esc_html($log->message) . '</td></tr>';
    }
    echo '</tbody></table></div>';
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
    // 【外部API連携タブ・前半】EDINETコードリスト／ニュースRSS（独立フォームのためform外に設置）
    // ------------------------------------------------------------------
    echo '<div class="wp-stocks-settings-panel" data-panel="api" style="display:none;">';

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

    echo '</div>'; // end panel: api (前半)

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

    // 為替レート
    $usd_jpy_manual = get_option('wp_stocks_usd_jpy_manual', 0);
    $usd_jpy_current = wp_stocks_get_usd_jpy();
    echo '<tr><th>USD/JPY為替レート</th><td>';
    echo '<input type="number" name="usd_jpy_manual" value="' . esc_attr($usd_jpy_manual > 0 ? $usd_jpy_manual : '') . '" step="0.01" style="width:120px;" placeholder="自動取得"> 円';
    echo '<p class="description">空欄の場合はYahoo Financeから自動取得します。現在のレート：<strong>' . number_format($usd_jpy_current, 2) . '円</strong><br>手動設定する場合は数値を入力（例：150.50）。クリアするには空欄で保存。</p>';
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
    echo '<div class="wrap"><h1>Yahoo Finance APIデバッグ</h1>';
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
// 複合シグナル判定エンジン（トレンド系＋オシレーター系＋騙し防止フィルター）
// 論点3：固定重み（wp_optionsで外出し）のみ。地合い連動の動的重みは第二弾。
// --------------------------------------------------
function wp_stocks_get_composite_weights($market_cap = null) {
    $defaults = [
        'golden_cross'           => 3,
        'dead_cross'             => -3,
        'macd_cross_bottom'      => 2,
        'macd_cross_top'         => -2,
        'rci_reversal_bottom'    => 2,
        'rci_reversal_top'       => -2,
        'stoch_cross_oversold'   => 2,
        'stoch_cross_overbought' => -2,
        'dmi_bullish'            => 2,
        'dmi_bearish'            => -2,
        'rsi_oversold'           => 1,
        'rsi_overbought'         => -1,
        'fib_near_bonus'         => 1,
        'oscillator_reversal_buy'  => 2,
        'oscillator_reversal_sell' => -2,
    ];
    $saved   = get_option('wp_stocks_composite_weights', []);
    $weights = is_array($saved) ? array_merge($defaults, $saved) : $defaults;

    // ★追加（下地）：時価総額の階層（小型/中型/大型）ごとに重みを上書きできるようにする。
    // 階層別オプション（wp_stocks_composite_weights_small 等）が未設定の間は、
    // これまで通り一律の重みがそのまま使われるため既存の挙動には影響しない。
    if ($market_cap !== null) {
        $tier       = wp_stocks_get_market_cap_tier($market_cap);
        $tier_saved = get_option('wp_stocks_composite_weights_' . $tier, []);
        if (is_array($tier_saved) && !empty($tier_saved)) {
            $weights = array_merge($weights, $tier_saved);
        }
    }

    return $weights;
}

// ★追加（下地）：時価総額の階層分けの閾値（円）。設定ページのUIはまだ無いが、
// 将来オプション経由で調整できるよう get_option 経由にしておく。
function wp_stocks_get_market_cap_tier_thresholds() {
    $defaults = [
        'small_max' => 30000000000,  // 300億円未満＝小型株
        'large_min' => 100000000000, // 1000億円以上＝大型株
    ];
    $saved = get_option('wp_stocks_market_cap_tier_thresholds', []);
    return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
}

// ★追加（下地）：時価総額から 'small' / 'mid' / 'large' の階層を判定
function wp_stocks_get_market_cap_tier($market_cap) {
    $th         = wp_stocks_get_market_cap_tier_thresholds();
    $market_cap = floatval($market_cap);
    if ($market_cap <= 0)                return 'mid'; // 時価総額が不明な場合は中型扱い
    if ($market_cap < $th['small_max'])  return 'small';
    if ($market_cap >= $th['large_min']) return 'large';
    return 'mid';
}

// ★追加：複合判定の5段階ラベルを決める閾値。設定ページから調整可能にするため外出し
function wp_stocks_get_composite_thresholds() {
    $defaults = ['strong_buy' => 5, 'buy' => 2, 'sell' => -2, 'strong_sell' => -5];
    $saved = get_option('wp_stocks_composite_thresholds', []);
    return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
}

// $result: wp_stocks_save_technicals() が計算した最新のテクニカル値（連想配列）
// $prev_row: 更新前にDBに保存されていた前回のテクニカル値（stdClass or null）
// $market_cap: ★追加（下地）時価総額（円）。指定すると小型/中型/大型株ごとの重み上書きが有効になる
function wp_stocks_calc_composite_score($result, $prev_row, $market_cap = null) {
    $w = wp_stocks_get_composite_weights($market_cap);
    $score  = 0;
    $detail = [];

    // ★変更：①打診買い/売りは、単純なGC/DCではなくボリンジャーσタッチ＋終値反転を使う
    // （バンドウォーク検出時はtasin_buyが自動的にfalseになるため、ここでの二重チェックは不要）
    if (intval($result['tasin_buy']  ?? 0) === 1) { $score += $w['golden_cross']; $detail[] = '打診買い発生：ボリンジャー-2σタッチからの反転（' . $w['golden_cross'] . '点）'; }
    if (intval($result['tasin_sell'] ?? 0) === 1) { $score += $w['dead_cross'];   $detail[] = '打診売り発生：ボリンジャー+2σタッチからの反転（' . $w['dead_cross'] . '点）'; }
    if (intval($result['bandwalk_detected'] ?? 0) === 1) {
        $bw_dir_ja = ($result['bandwalk_direction'] ?? '') === 'up' ? '上昇' : '下落';
        $detail[] = '注意：' . $bw_dir_ja . 'バンドウォーク検出中（強トレンド継続の可能性）';
    }

    // ② MACD：ゼロラインより下側でのGC／上側でのDC（ドキュメントの「深い位置でのクロスを重視」に対応）
    $macd          = $result['macd']        ?? null;
    $macd_sig      = $result['macd_signal'] ?? null;
    $prev_macd     = $prev_row->macd        ?? null;
    $prev_macd_sig = $prev_row->macd_signal ?? null;
    if ($macd !== null && $macd_sig !== null && $prev_macd !== null && $prev_macd_sig !== null) {
        $cross_up   = $prev_macd <= $prev_macd_sig && $macd > $macd_sig;
        $cross_down = $prev_macd >= $prev_macd_sig && $macd < $macd_sig;
        if ($cross_up && $macd < 0)   { $score += $w['macd_cross_bottom']; $detail[] = 'MACD底値圏でGC（' . $w['macd_cross_bottom'] . '点）'; }
        if ($cross_down && $macd > 0) { $score += $w['macd_cross_top'];    $detail[] = 'MACD天井圏でDC（' . $w['macd_cross_top'] . '点）'; }
    }

    // ③ RCI：±80からの反転
    $rci      = $result['rci'] ?? null;
    $prev_rci = $prev_row->rci ?? null;
    if ($rci !== null && $prev_rci !== null) {
        if ($prev_rci <= -80 && $rci > $prev_rci) { $score += $w['rci_reversal_bottom']; $detail[] = 'RCIが-80以下から反転上昇（' . $w['rci_reversal_bottom'] . '点）'; }
        if ($prev_rci >=  80 && $rci < $prev_rci) { $score += $w['rci_reversal_top'];    $detail[] = 'RCIが+80以上から反転下落（' . $w['rci_reversal_top'] . '点）'; }
    }

    // ④ ストキャスティクス：ゾーン内でのGC/DC＋張り付き時の騙し防止
    $stoch_k = $result['stoch_k'] ?? null;
    $stoch_d = $result['stoch_d'] ?? null;
    $prev_k  = $prev_row->stoch_k ?? null;
    $prev_d  = $prev_row->stoch_d ?? null;
    if ($stoch_k !== null && $stoch_d !== null && $prev_k !== null && $prev_d !== null) {
        $stoch_gc = $prev_k <= $prev_d && $stoch_k > $stoch_d;
        $stoch_dc = $prev_k >= $prev_d && $stoch_k < $stoch_d;
        if ($stoch_gc && $stoch_k <= 20) { $score += $w['stoch_cross_oversold'];   $detail[] = 'ストキャス売られ過ぎ圏でGC（' . $w['stoch_cross_oversold'] . '点）'; }
        if ($stoch_dc && $stoch_k >= 80) { $score += $w['stoch_cross_overbought']; $detail[] = 'ストキャス買われ過ぎ圏でDC（' . $w['stoch_cross_overbought'] . '点）'; }
    }
    if ($stoch_k !== null && $stoch_d !== null) {
        if ($stoch_k >= 80 && $stoch_d >= 80 && $score > 0) { $score = 0; $detail[] = '騙し防止：ストキャス高止まりのため買いシグナルを無効化'; }
        if ($stoch_k <= 20 && $stoch_d <= 20 && $score < 0) { $score = 0; $detail[] = '騙し防止：ストキャス低止まりのため売りシグナルを無効化'; }
    }

    // ⑤ DMI/ADX：明確なトレンド時のみ加点
    $plus_di  = $result['plus_di']  ?? null;
    $minus_di = $result['minus_di'] ?? null;
    $adx      = $result['adx']      ?? null;
    if ($plus_di !== null && $minus_di !== null && $adx !== null && $adx >= 25) {
        if ($plus_di > $minus_di) { $score += $w['dmi_bullish']; $detail[] = 'DMI:+DI優勢・ADX' . $adx . '（' . $w['dmi_bullish'] . '点）'; }
        if ($minus_di > $plus_di) { $score += $w['dmi_bearish']; $detail[] = 'DMI:-DI優勢・ADX' . $adx . '（' . $w['dmi_bearish'] . '点）'; }
    }

    // ⑥ RSI：逆張り加点＋騙し防止（過熱時の新規買い／売られ過ぎ時の新規売りを減点）
    $rsi = $result['rsi'] ?? null;
    if ($rsi !== null) {
        if ($rsi <= 30) { $score += $w['rsi_oversold'];   $detail[] = 'RSI売られ過ぎ（' . $w['rsi_oversold'] . '点）'; }
        if ($rsi >= 70) { $score += $w['rsi_overbought']; $detail[] = 'RSI買われ過ぎ（' . $w['rsi_overbought'] . '点）'; }
        if ($rsi >= 70 && $score > 0) { $score = max(0, $score - 2); $detail[] = '騙し防止：RSI過熱のため買いシグナルを減点'; }
        if ($rsi <= 30 && $score < 0) { $score = min(0, $score + 2); $detail[] = '騙し防止：RSI売られ過ぎのため売りシグナルを減点'; }
    }

    // ★追加：⑥.5 オシレーター反転確認（RSI売られ過ぎ/買われ過ぎ ＋ 厳格な陽線/陰線反転）
    if (intval($result['oscillator_reversal_buy']  ?? 0) === 1) { $score += $w['oscillator_reversal_buy'];  $detail[] = 'オシレーター反転確認：RSI売られ過ぎ＋陽線反転（' . $w['oscillator_reversal_buy'] . '点）'; }
    if (intval($result['oscillator_reversal_sell'] ?? 0) === 1) { $score += $w['oscillator_reversal_sell']; $detail[] = 'オシレーター反転確認：RSI買われ過ぎ＋陰線反転（' . $w['oscillator_reversal_sell'] . '点）'; }

    // ⑦ フィボナッチ：主要水準への接近を、既存スコアの方向に補助加点
    if (intval($result['fib_near'] ?? 0) === 1 && $score !== 0) {
        $bonus  = $score > 0 ? $w['fib_near_bonus'] : -$w['fib_near_bonus'];
        $score += $bonus;
        $detail[] = 'フィボナッチ主要水準（' . ($result['fib_level'] ?? '-') . '）に接近（' . $bonus . '点）';
    }

    // --- 判定ラベル（5段階・閾値は設定ページで調整可能） ---
    $th = wp_stocks_get_composite_thresholds();
    if ($score >= $th['strong_buy'])      { $label = 'strong_buy';  $label_ja = '強い買い'; $color = '#27ae60'; }
    elseif ($score >= $th['buy'])         { $label = 'buy';         $label_ja = '買い優勢'; $color = '#3498db'; }
    elseif ($score <= $th['strong_sell']) { $label = 'strong_sell'; $label_ja = '強い売り'; $color = '#e74c3c'; }
    elseif ($score <= $th['sell'])        { $label = 'sell';        $label_ja = '売り優勢'; $color = '#e67e22'; }
    else                                   { $label = 'hold';        $label_ja = 'HOLD';    $color = '#888'; }

    if (empty($detail)) $detail[] = '該当する複合シグナル条件はありません';

    return ['score' => $score, 'label' => $label, 'label_ja' => $label_ja, 'color' => $color, 'detail' => $detail];
}

// --------------------------------------------------
// 複合判定バッジHTML（5段階：強い買い/買い優勢/HOLD/売り優勢/強い売り）
// --------------------------------------------------
function wp_stocks_composite_badge_html($score, $label_ja = null, $color = null) {
    if ($label_ja === null || $color === null) {
        $th = wp_stocks_get_composite_thresholds();
        if ($score >= $th['strong_buy'])      { $label_ja = '強い買い'; $color = '#27ae60'; }
        elseif ($score >= $th['buy'])         { $label_ja = '買い優勢'; $color = '#3498db'; }
        elseif ($score <= $th['strong_sell']) { $label_ja = '強い売り'; $color = '#e74c3c'; }
        elseif ($score <= $th['sell'])        { $label_ja = '売り優勢'; $color = '#e67e22'; }
        else                                   { $label_ja = 'HOLD';    $color = '#888'; }
    }
    return '<span style="background:' . $color . ';color:#fff;font-weight:bold;font-size:11px;padding:2px 8px;border-radius:3px;">'
        . esc_html($label_ja) . '（' . ($score >= 0 ? '+' : '') . intval($score) . '点）</span>';
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

    foreach ($stocks as $s) {
        $sym = ($s->currency ?? 'JPY') === 'USD' ? $s->code : $s->code . '.T';
        $result = wp_stocks_save_company_info($s->id, $sym);
        if ($result) $ok++; else $ng++;

        // 負荷対策：3件ごとに5秒sleep、それ以外は2秒
        if (($ok + $ng) % 3 === 0) sleep(5);
        else sleep(2);
    }

    wp_stocks_log('info', 'company_cron', 'ALL', "企業情報週次更新完了：成功{$ok}件 / 失敗{$ng}件");
});

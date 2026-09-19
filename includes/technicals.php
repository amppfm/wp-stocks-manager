<?php
/**
 * WP Stocks Manager — テクニカル指標計算（EMA・MACD・RSI・ストキャスティクス・パラボリックSAR・フィボナッチ・ローソク足パターン・トレンド判定）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

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
            'up' => '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAAyADIDAREAAhEBAxEB/8QAHAAAAQUBAQEAAAAAAAAAAAAACQAGCAoLBwIF/8QANhAAAQQCAQIDBAcIAwAAAAAABAIDBQYBBwgACRESExQVOHcZIVZhlrfXFhgiJFiXttYjccH/xAAdAQACAgMBAQEAAAAAAAAAAAAICQAHBQYKBAED/8QAQREAAQQBAgQBAw4PAQAAAAAAAwECBAUGAAcIERITIQkUIhcYIzE3QVRhdneWtbbVFRkkMjY4QlFVVleVl9TWwf/aAAwDAQACEQMRAD8ALNxq4zXXu+XnZ/JnkrsS4VzU8Ncj6jR6VTpIH2kB9Aok05W4B2bBlY6ChKzDy9faLk818o20SBhDrjrJgxr/AEvXAsCtuJm3yDPs8u7SDjcW0NWVNTVnD1iegxylgw3ShSARIkCNJhNLI8yIWwMV7le0gyu09beve3GfJ4Ytg2yOyuHY7c5/ZY3EyHKMmyOFK7MoTjyKxlzbDrJUGZaWd3Y19uSPBS3BFookcI2DJHNGFqd6exHwwSnGP2k30rwxjHmVd6j45+/Pl12lPj/0nGPu6uH1n+1fw7MV+P8AC1b/AOUmhUXyqnEqqqqU21Dea/mpi+Qck+JOrMFXknxqq/Hr19BLwv8AtFvn8b1L9POp6z/av4dmP92rfuTXz8anxK/wfan6L3//AF+l9BLwv+0W+fxvUv086nrP9q/h2Y/3at+5NT8anxK/wfan6L3/AP1+l9BLwv8AtFvn8b1L9POp6z/av4dmP92rfuTU/Gp8Sv8AB9qfovf/APX6jDyp7REJxt1nP8iuIm09qwV+05Fm3s2OsNgiXiCoCvMLkZ0yvzkBC1gyNkYqJHLkVBF4lGJlgZ2PQlh51GHq/wBxOGeJglDMzjbPIcjh3OLxy3BQTZsZ7yQ4TFPLLClw4leQB40ZhT9oiSGSmMcFEY5yI68dh/KGWm9ObVOznELgmBWuJ7jzo2KxplRUWAwgt7grYdXHt6u1sryPNhz7A0eGkmOsEtaUzJblIMb1HyKs9/LasXW6/GT2nqjYpyOhIoCZsDslKBOTssIAwPIzDgYjqBRFyZjbxqxhkIHYU/lplKW0px1rUDjJyKPBhR5mM1k2WCJGDKmuPJE6XJGFjDylENyDGsgqOKoxojGK7paiNRNWFd+SbwSfdW86q3FyGnq5lpPlVtQyHBksq4EiUU0OubJONx5DYMd44zTmc4pUF1kcr3Kup9diT4MLJ8+rv/iOu+rl4P8A3K53yxtvq2k0JvlVP1lab5qMX+0GYaNF0VOlqaY2zbm9rrXd2vo1Znbo/TqxM2Nqp1hjBNhsKogF433TDj5+p0830fRHR4Z8Vqx4JVn+HOIv7R1HSWtwyBLtH1kCVObXQGdybNWMJxfNorP2jF6elifvX2l1tGEY2PMcwxnFDXdXjYsjvK2mff3ZVBUU7bGUON+ELEqeI4kbudwzvD0U9tvtoHWid2Tee74guV0ZwecswDRT8f76nOQtNhhQj2PL5x5CMNq8eR7S3hbbqwcSDD/ouNPJVlp1txQw0/Efl+WxSScR2kdPC0jwedS82q4oxGZ7bDRy14X9xEVHKLvMf0ua5F6XIqsZyrgB2t2wsAQN0uKFlJLeAUv8G1W0GR2R5MUvPpNEnRryWHsvVr2MlLEKLuMeNyIRj2o1Nyc8e5VqbWNl25Z9HcXqRU64uGafDlZ+1XCxlPTs5HQQI0axWr2OEWRgmSacfw4sdCBGSiceOGsIzjco3h33xugsMln4lt9U10HzVHBkTLGznEdMlgiBZHZAt2CI/rO1z0crEQbSP/Z6Vz+3HCnwVZ/nFJt7R7ob55Pf3LbJ45MCpocdpo46qrmWso80t3ippMcKghPYLoaZzpBABXl3OpCtcgSJk3h9u0uyBjx9hL417JIno8XKligzL+r5lyUDHU5lS1DjGqfYaytSlKbQnKs5z456IzM3yi7ZZYScJgJpMEvXzADVVGGU/H5TpAmK7mqsGVXsaqqqqiJz0BO0Ya2NxFbYx6WSWZTg3qwsNTLOjWnlVos5rWQZJkYjWtKeK0RSI1Eaj3KiIiaz8+kta65tW/uxJ8GFk+/fV38Pwjrv/wB8emb8H/uVzvljbfVtJrnZ8qp+srT/ADU4v9oMw0aLoqdLV0NTnTz+i+OCWNR6mjxNjcnLcKjFdpyF4eiqUGajPp2y9vNrSgMdhrPtsfDPvDPSTaUlFOhxWcFPUPu9vPGwZG41jgR3mfWQ08yq2r1x6oRU9Gxt3NVEExrfZQRXuY6QiI8jhR/ZHGrws8JU/eVS7hbgS5GG7IY8dy3GRub25+TSYzk66DFRPY50kxSJ5tMshDMKE9XAAOTPRQDHdxY1+vUkfaJaesKrHsHZdjJumw5tpkePiirCc+WU8mHiwxwxAQmnTiM5y2Mzkl1xbuGBR/ZgRaS27pVxoFhJmTVnXV9Oda3cprWBjEmleQj/ADWONgxhG1Sk8Wsb1q5V6Rs6BDMPfjLWbgy6OvqqhKXEcIpg41h9YQhpc8FPFFHANbGfILIkSZJGRQoiPMVAjY1ndkG70k/0OUVorm8V8TtKQ04DYIjbfKnX8XPEQEiHKhrrVZMU1bRVugvPs5eBzNAkPsqz/LuC/wDMlOU46/bcKwgZau2+KRJYZkXJNxKWNMJCMOSN0CvL0WQ3PE97VcHzsL3tVfQUfp8lTXj2Lorna5u/+5llWS6mw2+2Hy6dVAtociBIZd3cZH4+Zo5IhEQcpK2UEZGp7K0/saqiro2nKb4YuRvyH29+X1h6K/cL9AM4+R+TfUs3SydiPdw2a+dbbz7XU+s9fpJ+uvrVv/sS/BfYvnzd/wDEtedM34P/AHK53yxtvq2k1zs+VT/WVp/mpxf6/wAv06e4X3KY/j+4VozRDsda+Rcqy21ImK9I2v6hAMb82JqwYWyQFIWPDK23Y+tPeKRW3mZOabUzkKJmclvZvwHC1JiOHuBY5zJajTFXpLCxkJW8/O5vUx4jzulUcCA7mg0c08pqs7UaVgeEDgrl7uNBujuqOZQbOQCPJDjp1xrfcOVHf0rW1CsKGVEpu417Jl0Pkp3DJCrXtJ5zPrgl6pZagjJi2WCcMtN+tRT8tc75PlOFzM0aQ7kklZBhTjjrYiV48/k9X+PKEuu5ypCPTE/HGthllWMyUWxubEj5NrcTHuJKlFe5SPV5SK5zRovj09Xjy6ne0nSzfPSPtY1dQVFXGocToQCgY1ilUBketrIwWdkLRRgMYN53M5t6kZzb1OYJERXdcjdN0bb/AD0tJ+tNRyR1I0LAlpjtx70abxlZmMei+/R6Eh3LSzZc8fww+UOvIzYZGCZB1mLdHGsW84tUZNvFYGocaOWpw6GTsZRlzW+JPzXPqadHdClkmZy6iMVWIJ6PO5sdzBzaa3HynbvhSoombbgw4uUbr2sZZm3O1hH+jGX0xCyjK3M7jYtfELz7UczUM6QFQw2FnDManJVrHtP0PR/LPVG8dVWwwHU2vIyfNd1NZH5KdJRf5annVBVrhpch9TKEyDbkTMSLBTCXx5aHQoIjMe6FHxN8Y/w40+JbkY5luO2JQ43SR5hXY3OfImPS6k1haxbGLJI9WohkWNKOwjEeyTFRRPULhAjhXnHH3le6HD/n21+eUEeVuBmE6pjD3AphQaoLsSr8ii5ClDZV4RNI50N7J9dDKAqiNX2LkkhSWOTLsJ+8pvhi5G/Ifb35fWHq5twv0Azj5H5N9SzdCXsR7uGzXzrbefa6n1nr9JP119atu9lSHkbFwD2VX4eeMqstObX2lDxdojm23pCtyMnQKMEFPAMvZw06ZDkvNSArbucNrfHQlecJznplPClFPN2ZvoUWYWuky8jyGLHsANa40E8imqBCmBa70XFikc0w2u9FXMRF8Nc+PlMbGHT8WuF21jUxr6vq8BwSxn0Ux7xw7qHCyzKJMqplEGikHGsQCfDO9iK9ojOc1OaJoBe8NL7R4lbrtOuNytlEzxpxc9F7DJya9H7Lhiynlt2oKXkVOPmZKcy77xbfIcKBk0mBHZwYy6pwN8txTINt8rsKLKUI+YYxJse7IpXBv4pCKqWIpJlVxVevX3mvepAn7ojeyo5VbJthuXgvEDtlQ5ltu6OGqixY9TOxAKRhS8JsgAG19DJr4aMFGQDO35o8QmAlQljSYv5MRjWS24Y8O9oc6Zb1335jX3GiGNyNcL2hjI8tfCRHUZKqVHyQ2pt5avqalJRaHI+KaypRiTDPQhiLK2s2wyDd6V1vdJpcCim6LO4axWSLh419OtqOtOly+9IkORwY6LzK0hemK8feJPiMwbhar+2IVdl29llG72O4o8qGr8UBIG5AZDlCBejxtby7kGC1w5c96IkZY0fu2QbZGttaUTUFKgdda1rMbUaZWRMBQ0FFNqSOO35lOOuuuurdKNNLfW4SfInPkHnluulGEPkOrcUx6hoafGKmHR0MAFZVQBdqLEjtVGMbzVznOc5XEKUj1Uhjle8xiOcQr3vcrlQHmma5VuJk1tmOa3c3Icku5CybK0nva4xn8kYMYxjawEaNHE1gIsOKIMWJHYMEYIhMaxHz1l9avrg/Kb4YuRvyH29+X1h60/cL9AM4+R+TfUs3VrbEe7hs18623n2up9Z6/ST9dfWrMPaI5U6x42V/afEjkZPxmmb9A7WnbDGmXwxuvQRj5UTCQE5XzJuQ9CJiZKJOrHtQy5Q4VmYGlW0Ry3XRcpdPjhn3EoMEhZDtrnE2Pi1zDyOZNjkuCthQyuJGiQ5cIss3TGjHjmr+4NZBhslMkNQCucPk5JXlDdhs33pt8E4g9m6iduRiVtgNVUTY+KR33FrGGCws7art49XE7thPhT4t52DNgxTlrjQHumNGw/UMoXIVzt2cqK3FVfd22OPduj4OSTLQZTe7qfDTESXlOEP+wTUNbAZFoQ9nGGpAD2hQJqUMLIHW+KK6wQWbLsjuHBj1+WZHhVkGGdJMQiZZWRZMYvLk/syotkI7Rlb6Jg9ahKiNV7FcwbmgxtAzjE2Iup95tjgG72PTLSEtfaAfthkVlXWEdFVwvO62yx+VDJIikVSRJfZbKiq4rQmaM5xl7FVN+8OqLXIaoU3eXHGs1evAMRkJAwu1NbgRkYAMjyMjCisT6G204x9aleGVuuZW66pbq1rztFdmW2FRBi1lXl2DQK+EFkeJDi5FRBjxwjTk0YxsmIiInv8AvuVVc5Vcqrqub/abiNym5ssiyPa7eW7vLiWWdZ2tlgmZy502WZ3UQxzlqXPe5V8ETn0sYjWMRrGtajh/ep4wf1H6F/vBr3/Yevb6om3/APPOHfSal/3dYj1B98f6M7r/AOO8v+59L96njB/UfoX+8Gvf9h6nqibf/wA84d9JqX/d1PUH3x/ozuv/AI7y/wC59Qb7gPcD400TjXtaq1Xa9C2XsLZdDtev6tVtf2uGt5LBVuhiq+/NTZFeLkhYSOhhJN6Tx70eFclHRUgAIeddWtmo96N6MDp8DyOursjp767vqaypq6vpbGLZkYSyikhPly3wiHHEBFHIdI/KHDWQ4aBCjnOVWlFwj8I29WV71YFfX2A5XhWIYVldBlt7e5dQ2WOhKDH7IFuKsqxXEeEezmWR4I4K+YjOyCw6y5bhsYxpa1Na7a/Mu21yAtUHpmeKhbNCxVgiCVKGZURFzILEjHvqZeeQ61l0QlpzLbqEuN5V5VpSrGcdAZA2H3RsoMOxiYvMfFnxI82M9VG1Xx5QWHC5Wucjmq4b2r0uRFTnyVEXTrbrjU4b8fubahs9x6kNlSWc+osAp3SIKdWyiw5YkINjmPQZwkaj2Ocx3Lm1VRUXRJO/nVaxEbF0vYYquQMZP2WvT7ljnI+HjwpifWAaKMCubkxh2zZVYQ2MDiKOefyOxjDTOUN48vV8cZVdXxrrFp0eBDjzZ8KYs6WGKAUqYoSjGJZchjGlkKJnoDUz39DPRbyTw0F/kmr68sMO3Kp59zbTqmluKllNVzLGZJrqlsqKc8ptZCMZ8aA2SZVNISKISGL7ITqd46r39BPpvWl1NTS6mppdTU1Ojts1uu2zmnoyCtUDC2aEMtH83D2CLBmYor0RCHmvaI+RYJEf9J5tDrfqMq8jiELT4KTjOLg2HgQbLdTEoljDiz4hLBO5Fmxwyo5Okb3N6wnY8bulyI5Opq8nIip4poWONO5uMf4ad0rShtrKks49Evm9jUTpVbPB3JARk7MyGUMgXWNzhv6CJ1Mc5q82qqavbIQhtCW20pQ2hKUIQhOEoQhOPKlKUp8MJSnGMYSnGMYxjHhjpv6IjURERERE5IiJyRET2kRPeRP3a5V3Oc9znvcrnuVXOc5Vc5znLzVzlXxVVXxVV8VXxXX/2Q==',
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

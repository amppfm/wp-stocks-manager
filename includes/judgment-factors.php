<?php
/**
 * 判定の材料（すでにあるデータから計算する要素）
 *
 * 総合判定に取り込む候補の要素を、まず「生の値」として計算し、判定履歴に記録する。
 * 点数への換算や合算は、総合判定の形が決まってから行う（ここでは値だけを作る）。
 *
 *   決算サプライズ   : TDnetのQUICKコンセンサス比（純利益・営業利益）
 *   EPS修正トレンド  : 予想EPSの30日・90日前からの変化率（stock_valuation_history）
 *   決算接近         : 次の決算発表日までの日数（stocks.earnings_date）
 *   52週の位置       : 52週高値〜安値の中での位置(0〜100)、高値からの下落率
 *   資金流入         : OBVの20日変化（出来高に対する比）、MFI(14)、出来高比率
 *   値動きの大きさ   : ATR(14)÷終値(%)
 *
 * 取れない要素はNULL（0とは区別する）。
 */

if (!defined('ABSPATH')) exit;

// 日足の配列（date, open, high, low, close, volume）から、価格・出来高系の値を計算する
function wp_stocks_jf_bar_metrics($bars) {
    $out = ['pos_52w' => null, 'dist_high_pct' => null, 'obv_chg20' => null, 'mfi14' => null, 'vol_ratio' => null, 'atr_pct' => null];
    if (!is_array($bars) || count($bars) < 20) return $out;
    $bars = array_values($bars);
    $n = count($bars);
    $close = array_map('floatval', array_column($bars, 'close'));
    $high  = array_map('floatval', array_column($bars, 'high'));
    $low   = array_map('floatval', array_column($bars, 'low'));
    $vol   = array_map('floatval', array_column($bars, 'volume'));
    $last  = $close[$n - 1];
    if ($last <= 0) return $out;

    // 52週（約252営業日）の高値・安値の中での位置
    $w = min(252, $n);
    $hh = max(array_slice($high, -$w));
    $ll = min(array_slice($low, -$w));
    if ($w >= 120 && $hh > $ll) {
        $out['pos_52w'] = round(($last - $ll) / ($hh - $ll) * 100, 1);
        $out['dist_high_pct'] = round(($last / $hh - 1) * 100, 1);
    }

    // 出来高比率（当日 ÷ 直前20日平均）
    $avg20 = array_sum(array_slice($vol, -21, 20)) / 20;
    if ($avg20 > 0) $out['vol_ratio'] = round($vol[$n - 1] / $avg20, 2);

    // OBVの20日変化 ÷ 直近20日の出来高合計（-1〜+1に収まる比）
    if ($n >= 21) {
        $chg = 0.0; $sum = 0.0;
        for ($i = $n - 20; $i < $n; $i++) {
            if ($close[$i] > $close[$i - 1]) $chg += $vol[$i];
            elseif ($close[$i] < $close[$i - 1]) $chg -= $vol[$i];
            $sum += $vol[$i];
        }
        if ($sum > 0) $out['obv_chg20'] = round($chg / $sum, 3);
    }

    // MFI(14)
    if ($n >= 16) {
        $pos = 0.0; $neg = 0.0;
        for ($i = $n - 14; $i < $n; $i++) {
            $tp  = ($high[$i] + $low[$i] + $close[$i]) / 3;
            $tpp = ($high[$i - 1] + $low[$i - 1] + $close[$i - 1]) / 3;
            $mf  = $tp * $vol[$i];
            if ($tp > $tpp) $pos += $mf; elseif ($tp < $tpp) $neg += $mf;
        }
        if ($pos + $neg > 0) $out['mfi14'] = $neg > 0 ? round(100 - 100 / (1 + $pos / $neg), 1) : 100.0;
    }

    // ATR(14) ÷ 終値
    if ($n >= 15) {
        $tr = [];
        for ($i = $n - 14; $i < $n; $i++) {
            $tr[] = max($high[$i] - $low[$i], abs($high[$i] - $close[$i - 1]), abs($low[$i] - $close[$i - 1]));
        }
        $out['atr_pct'] = round(array_sum($tr) / 14 / $last * 100, 2);
    }
    return $out;
}

// 予想EPSの修正率(%)：最新と、約30日前・約90日前の記録との比較
function wp_stocks_jf_eps_revision($stock_id) {
    global $wpdb;
    $out = ['eps_rev_30d' => null, 'eps_rev_90d' => null];
    $t = $wpdb->prefix . 'stock_valuation_history';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return $out;
    $latest = $wpdb->get_row($wpdb->prepare("SELECT record_date, forecast_eps FROM {$t} WHERE stock_id = %d AND forecast_eps > 0 ORDER BY record_date DESC LIMIT 1", $stock_id));
    if (!$latest) return $out;
    foreach ([30 => 'eps_rev_30d', 90 => 'eps_rev_90d'] as $days => $key) {
        $cut = (new DateTime($latest->record_date))->modify("-{$days} days")->format('Y-m-d');
        $old = $wpdb->get_var($wpdb->prepare("SELECT forecast_eps FROM {$t} WHERE stock_id = %d AND forecast_eps > 0 AND record_date <= %s ORDER BY record_date DESC LIMIT 1", $stock_id, $cut));
        if ($old !== null && (float)$old > 0) $out[$key] = round(((float)$latest->forecast_eps / (float)$old - 1) * 100, 1);
    }
    return $out;
}

// 決算サプライズ：最新の決算短信（TDnet）の、QUICKコンセンサス比（純利益・営業利益）
function wp_stocks_jf_surprise($stock_id) {
    global $wpdb;
    $out = ['sur_ni_cons' => null, 'sur_op_cons' => null, 'sur_date' => null];
    if (!function_exists('wp_stocks_tde_results_table')) return $out;
    $t = wp_stocks_tde_results_table();
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return $out;
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT disclosed_at, period_end, fc_ni_vs_cons, fc_op_vs_cons FROM {$t} WHERE stock_id = %d AND (fc_ni_vs_cons IS NOT NULL OR fc_op_vs_cons IS NOT NULL) ORDER BY COALESCE(disclosed_at, period_end) DESC LIMIT 1", $stock_id));
    if (!$r) return $out;
    $out['sur_ni_cons'] = $r->fc_ni_vs_cons !== null ? round((float)$r->fc_ni_vs_cons, 1) : null;
    $out['sur_op_cons'] = $r->fc_op_vs_cons !== null ? round((float)$r->fc_op_vs_cons, 1) : null;
    $d = $r->disclosed_at ?: $r->period_end;
    $out['sur_date'] = $d ? substr($d, 0, 10) : null;
    return $out;
}

// 次の決算発表日までの日数（登録されていて、今日以降の場合）
function wp_stocks_jf_days_to_earnings($stock) {
    $d = $stock->earnings_date ?? null;
    if (empty($d) || $d === '0000-00-00') return null;
    $today = (new DateTime('today', new DateTimeZone('Asia/Tokyo')));
    $e = DateTime::createFromFormat('Y-m-d', substr($d, 0, 10), new DateTimeZone('Asia/Tokyo'));
    if (!$e) return null;
    $e->setTime(0, 0, 0);
    $days = (int)$today->diff($e)->format('%r%a');
    return $days >= 0 ? $days : null;
}

// 1銘柄ぶんの要素をまとめて計算する（stock_judgment_historyの列名で返す）
function wp_stocks_jf_collect($stock) {
    $is_usd = ($stock->currency ?? 'JPY') === 'USD';
    $symbol = $is_usd ? $stock->code : $stock->code . '.T';
    $bars = function_exists('wp_stocks_get_ohlcv') ? wp_stocks_get_ohlcv($symbol) : false;
    $out = wp_stocks_jf_bar_metrics($bars);
    $out['days_to_earn'] = wp_stocks_jf_days_to_earnings($stock);
    $out = array_merge($out, wp_stocks_jf_eps_revision((int)$stock->id));
    if (!$is_usd) $out = array_merge($out, wp_stocks_jf_surprise((int)$stock->id));
    else $out = array_merge($out, ['sur_ni_cons' => null, 'sur_op_cons' => null, 'sur_date' => null]);
    return $out;
}

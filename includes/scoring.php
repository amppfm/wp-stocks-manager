<?php
/**
 * WP Stocks Manager — スコアリング（適正株価・スコアカード・複合スコア）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// 適正株価計算: EPS × セクター平均PER（自社PERは使わないため循環参照なし）
// 戻り値: ['actual' => 実績ベース適正株価 or null, 'forward' => 予想ベース適正株価 or null,
//          'sector_avg_per' => 実績ベースの基準PER or null, 'sector_avg_per_forward' => 予想ベースの基準PER or null]
// --------------------------------------------------
function wp_stocks_calc_fair_price($stock) {
    $is_usd   = ($stock->currency ?? 'JPY') === 'USD';
    $sector   = wp_stocks_get_effective_sector($stock);
    $market   = $stock->market ?? '';
    $stock_id = $stock->id ?? null;

    // 実績EPSは、日本株はJ-Quants（jquants_eps）を優先し、無ければ従来のeps（Yahoo由来）にフォールバックする。
    // ただし、J-Quantsの実績EPSは開示当時の株数ベースの値であり、その後に株式分割を行った銘柄では
    // 遡及調整されない（Yahoo側は現在の株数換算に調整済み）ため、分割比率倍に見えてしまうことがある。
    // Yahoo実績EPSとの倍率が0.5〜2倍の範囲を外れる場合は、分割等の疑いありとみなしYahoo側を採用する
    // （分割後、次の本決算が新株数ベースで開示されれば、この乖離は自然に解消しJ-Quants側に戻る）。
    $yahoo_eps_actual  = $stock->eps ?? 0;
    $jquants_eps_actual = $stock->jquants_eps ?? 0;
    if ($is_usd) {
        $eps_actual        = $yahoo_eps_actual;
        $eps_actual_source = 'yahoo';
    } elseif ($jquants_eps_actual > 0) {
        $eps_ratio = $yahoo_eps_actual > 0 ? ($jquants_eps_actual / $yahoo_eps_actual) : null;
        if ($eps_ratio !== null && ($eps_ratio < 0.5 || $eps_ratio > 2.0)) {
            $eps_actual        = $yahoo_eps_actual;
            $eps_actual_source = 'yahoo_split_suspected';
        } else {
            $eps_actual        = $jquants_eps_actual;
            $eps_actual_source = 'jquants';
        }
    } else {
        $eps_actual        = $yahoo_eps_actual;
        $eps_actual_source = 'yahoo';
    }

    $result = [
        'actual' => null, 'forward' => null, 'eps_actual_source' => $eps_actual_source,
        'sector_avg_per' => null, 'sector_avg_per_source' => null, 'sector_avg_per_count' => 0,
        'sector_avg_per_forward' => null, 'sector_avg_per_forward_source' => null, 'sector_avg_per_forward_count' => 0,
    ];

    if ($eps_actual > 0) {
        $avg_per = wp_stocks_get_sector_avg_per($sector, $is_usd, $stock_id, false, $market);
        if ($avg_per['value'] !== null) {
            $result['actual']                = $eps_actual * $avg_per['value'];
            $result['sector_avg_per']        = $avg_per['value'];
            $result['sector_avg_per_source'] = $avg_per['source'];
            $result['sector_avg_per_count']  = $avg_per['count'];
        }
    }
    if (($stock->forward_eps ?? 0) > 0) {
        $avg_per_fwd = wp_stocks_get_sector_avg_per($sector, $is_usd, $stock_id, true, $market);
        if ($avg_per_fwd['value'] !== null) {
            $result['forward']                        = $stock->forward_eps * $avg_per_fwd['value'];
            $result['sector_avg_per_forward']         = $avg_per_fwd['value'];
            $result['sector_avg_per_forward_source']  = $avg_per_fwd['source'];
            $result['sector_avg_per_forward_count']   = $avg_per_fwd['count'];
        }
    }
    return $result;
}

// 直近の本決算(FY)レコードを新しい順に$limit件取得する（J-Quants由来のみ）
function wp_stocks_get_latest_annual_financials($stock_id, $limit = 2) {
    global $wpdb;
    if (empty($stock_id)) return [];
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_quarterly_financials
         WHERE stock_id = %d AND source = 'jquants' AND fiscal_quarter = 4
         ORDER BY period_end DESC LIMIT %d",
        $stock_id, $limit
    ));
}

// --------------------------------------------------
// 財務スコアカード v2（日本株専用）
// バリュー・クオリティ・成長・安全性・還元の5軸（各軸の配点は固定）。
// 軸の中でデータが取れた指標だけを使って軸内を正規化し、最後に軸の配点を掛けて合算する
// （N/Aの指標があっても、その指標ぶん単純に0点扱いにはしない）。
// 銀行・金融（TOPIX-17コード1631/1632）は、自己資本比率・利益率を評価対象から外し、
// その配点をROEに寄せる（レバレッジが前提の業態のため）。
// 減点フラグ（バリュートラップ懸念・利益の質懸念・営業CF赤字・配当性向過大・減収減益）は
// 軽度-3/中度-7/重度-15点で、合計の減点は-15点を上限とする。
// --------------------------------------------------
function wp_stocks_calc_score_jp($stock) {
    $sector       = wp_stocks_get_effective_sector($stock);
    $topix17      = wp_stocks_sector33_to_topix17($sector);
    $is_financial = in_array($topix17, ['1631', '1632'], true); // 1631=銀行、1632=金融（除く銀行）

    $fy  = wp_stocks_get_latest_annual_financials($stock->id ?? 0, 2);
    $fy0 = $fy[0] ?? null; // 直近本決算
    $fy1 = $fy[1] ?? null; // 前期本決算

    $detail      = [];
    $flags       = [];
    $axis_earned = [];
    $axis_max    = [];

    // $earnedがnullの指標は、軸の配点（分母）からも除外する（N/A除外の正規化）
    $add = function($axis, $label, $max, $earned, $eval, $value = null) use (&$detail, &$axis_earned, &$axis_max) {
        if ($earned === null) {
            $detail[$label] = ['軸' => $axis, '値' => $value ?? 'N/A', '点数' => null, '評価' => 'N/A', '満点' => $max];
            return;
        }
        $axis_max[$axis]    = ($axis_max[$axis] ?? 0) + $max;
        $axis_earned[$axis] = ($axis_earned[$axis] ?? 0) + $earned;
        $detail[$label] = ['軸' => $axis, '値' => $value, '点数' => $earned, '評価' => $eval, '満点' => $max];
    };

    // ---------- A. バリュー（20点） ----------
    $per = floatval($stock->per ?? 0);
    $avg_per_info   = wp_stocks_get_sector_avg_per($sector, false, $stock->id ?? null, false, $stock->market ?? '');
    $sector_avg_per = $avg_per_info['value'];
    if ($per > 0 && $sector_avg_per > 0) {
        $per_ratio = $per / $sector_avg_per;
        if     ($per_ratio < 0.7) { $pts = 10; $ev = '業種平均より割安'; }
        elseif ($per_ratio < 1.0) { $pts = 7;  $ev = '業種平均並み'; }
        elseif ($per_ratio < 1.3) { $pts = 3;  $ev = '業種平均より割高'; }
        else                      { $pts = 0;  $ev = '割高'; }
        $add('バリュー', 'PER業種比', 10, $pts, $ev, number_format($per_ratio, 2) . '倍（業種平均' . number_format($sector_avg_per, 1) . '倍）');
    } else {
        $add('バリュー', 'PER業種比', 10, null, 'N/A');
    }

    $pbr = floatval($stock->pbr ?? 0);
    $roe = floatval($stock->roe ?? 0);
    if ($pbr > 0) {
        if ($pbr < 1 && $roe > 0 && $roe < 8) {
            $pts = 3; $ev = 'バリュートラップ懸念';
            $flags[] = ['label' => 'バリュートラップ懸念（PBR' . number_format($pbr, 2) . '倍・ROE' . number_format($roe, 1) . '%）', 'penalty' => -3, '重さ' => '軽度'];
        } elseif ($pbr < 1 && $roe >= 8) { $pts = 10; $ev = '割安（ROE裏付けあり）'; }
        elseif ($pbr < 2)                { $pts = 6;  $ev = '適正'; }
        elseif ($pbr < 3)                { $pts = 3;  $ev = 'やや割高'; }
        else                              { $pts = 0;  $ev = '割高'; }
        $add('バリュー', 'PBR×ROE', 10, $pts, $ev, 'PBR' . number_format($pbr, 2) . '倍・ROE' . number_format($roe, 1) . '%');
    } else {
        $add('バリュー', 'PBR×ROE', 10, null, 'N/A');
    }

    // ---------- B. クオリティ（非金融20点／金融30点） ----------
    $roe_max = $is_financial ? 25 : 10;
    if ($roe > 0) {
        if     ($roe >= 15) { $frac = 1.0; $ev = '優良'; }
        elseif ($roe >= 10) { $frac = 0.7; $ev = '良好'; }
        elseif ($roe >= 5)  { $frac = 0.3; $ev = '普通'; }
        else                 { $frac = 0.0; $ev = '低い'; }
        $add('クオリティ', 'ROE', $roe_max, round($roe_max * $frac, 1), $ev, number_format($roe, 1) . '%');
    } else {
        $add('クオリティ', 'ROE', $roe_max, null, 'N/A');
    }

    if (!$is_financial) {
        $profit_margin = null; $pm_src = '';
        if ($fy0 && !empty($fy0->revenue) && $fy0->net_income !== null) {
            $profit_margin = $fy0->net_income / $fy0->revenue * 100;
            $pm_src = 'J-Quants';
        } elseif (!empty($stock->profit_margin)) {
            $profit_margin = floatval($stock->profit_margin);
            $pm_src = 'Yahoo';
        }
        if ($profit_margin !== null) {
            if     ($profit_margin >= 15) { $pts = 5; $ev = '優良'; }
            elseif ($profit_margin >= 10) { $pts = 3; $ev = '良好'; }
            elseif ($profit_margin >= 5)  { $pts = 1; $ev = '普通'; }
            else                           { $pts = 0; $ev = '低い'; }
            $add('クオリティ', '利益率', 5, $pts, $ev, number_format($profit_margin, 1) . '%（' . $pm_src . '）');
        } else {
            $add('クオリティ', '利益率', 5, null, 'N/A');
        }
    }

    if ($fy0 && !empty($fy0->net_income) && $fy0->net_income > 0 && $fy0->cf_operating !== null) {
        $cf_ratio = $fy0->cf_operating / $fy0->net_income;
        if     ($cf_ratio >= 1.2) { $pts = 5; $ev = '良好'; }
        elseif ($cf_ratio >= 0.8) { $pts = 3; $ev = '普通'; }
        else {
            $pts = 0; $ev = '利益の質に懸念';
            $flags[] = ['label' => '利益の質懸念（営業CF÷純利益' . number_format($cf_ratio, 2) . '倍）', 'penalty' => -3, '重さ' => '軽度'];
        }
        $add('クオリティ', '営業CF÷純利益', 5, $pts, $ev, number_format($cf_ratio, 2) . '倍');
    } else {
        $add('クオリティ', '営業CF÷純利益', 5, null, 'N/A');
    }

    // ---------- C. 成長（30点） ----------
    if ($fy0 && $fy1 && $fy0->revenue !== null && $fy1->revenue !== null && $fy0->operating_profit !== null && $fy1->operating_profit !== null) {
        $rev_up = $fy0->revenue > $fy1->revenue;
        $op_up  = $fy0->operating_profit > $fy1->operating_profit;
        if ($rev_up && $op_up) { $pts = 15; $ev = '増収増益'; }
        elseif ($rev_up || $op_up) { $pts = 8; $ev = $rev_up ? '増収減益' : '減収増益'; }
        else {
            $pts = 0; $ev = '減収減益';
            $flags[] = ['label' => '減収かつ減益（前年比）', 'penalty' => -15, '重さ' => '重度'];
        }
        $add('成長', '増収増益判定（前年比）', 15, $pts, $ev);
    } else {
        $add('成長', '増収増益判定（前年比）', 15, null, 'N/A');
    }

    if ($fy0 && $fy1 && !empty($fy1->revenue) && $fy1->revenue > 0 && $fy0->revenue !== null) {
        $rev_growth = ($fy0->revenue - $fy1->revenue) / $fy1->revenue * 100;
        if     ($rev_growth >= 10) { $pts = 5; $ev = '高成長'; }
        elseif ($rev_growth >= 5)  { $pts = 3; $ev = '成長'; }
        elseif ($rev_growth >= 0)  { $pts = 1; $ev = '横ばい'; }
        else                        { $pts = 0; $ev = '減収'; }
        $add('成長', '売上成長率', 5, $pts, $ev, number_format($rev_growth, 1) . '%');
    } else {
        $add('成長', '売上成長率', 5, null, 'N/A');
    }

    if ($fy0 && $fy1 && !empty($fy1->operating_profit) && $fy1->operating_profit > 0 && $fy0->operating_profit !== null) {
        $op_growth = ($fy0->operating_profit - $fy1->operating_profit) / $fy1->operating_profit * 100;
        if     ($op_growth >= 15) { $pts = 5; $ev = '高成長'; }
        elseif ($op_growth >= 5)  { $pts = 3; $ev = '成長'; }
        elseif ($op_growth >= 0)  { $pts = 1; $ev = '横ばい'; }
        else                        { $pts = 0; $ev = '減益'; }
        $add('成長', '営業利益成長率', 5, $pts, $ev, number_format($op_growth, 1) . '%');
    } else {
        $add('成長', '営業利益成長率', 5, null, 'N/A');
    }

    // 会社予想の増益率（予想EPS÷実績EPS）。実績EPSが株式分割の疑いで乖離している場合はN/A扱いにする
    $eps_fy_actual = floatval($stock->jquants_eps ?? 0);
    $yahoo_eps     = floatval($stock->eps ?? 0);
    $feps          = floatval($stock->jquants_forecast_eps ?? 0);
    $eps_ratio_ok  = ($yahoo_eps <= 0 || $eps_fy_actual <= 0) ? true : (($eps_fy_actual / $yahoo_eps) >= 0.5 && ($eps_fy_actual / $yahoo_eps) <= 2.0);
    if ($eps_fy_actual > 0 && $feps > 0 && $eps_ratio_ok) {
        $f_growth = ($feps - $eps_fy_actual) / $eps_fy_actual * 100;
        if     ($f_growth >= 10) { $pts = 5; $ev = '増益予想'; }
        elseif ($f_growth >= 0)  { $pts = 3; $ev = '横ばい予想'; }
        else                      { $pts = 0; $ev = '減益予想'; }
        $add('成長', '会社予想の増益率', 5, $pts, $ev, number_format($f_growth, 1) . '%');
    } else {
        $add('成長', '会社予想の増益率', 5, null, 'N/A');
    }

    // ---------- D. 安全性（非金融20点／金融10点） ----------
    if (!$is_financial) {
        $eq = floatval($stock->equity_ratio ?? 0);
        if ($eq > 0) {
            if     ($eq >= 50) { $pts = 10; $ev = '安全'; }
            elseif ($eq >= 30) { $pts = 6;  $ev = '普通'; }
            elseif ($eq >= 20) { $pts = 3;  $ev = 'やや低い'; }
            else                 { $pts = 0;  $ev = '注意'; }
            $add('安全性', '自己資本比率', 10, $pts, $ev, number_format($eq, 1) . '%');
        } else {
            $add('安全性', '自己資本比率', 10, null, 'N/A');
        }
    }

    if ($fy0 && $fy0->cf_operating !== null) {
        if ($fy0->cf_operating > 0) { $pts = 5; $ev = '黒字'; }
        else {
            $pts = 0; $ev = '赤字';
            $flags[] = ['label' => '営業CF赤字', 'penalty' => -7, '重さ' => '中度'];
        }
        $add('安全性', '営業CFの黒字継続', 5, $pts, $ev);
    } else {
        $add('安全性', '営業CFの黒字継続', 5, null, 'N/A');
    }

    if ($fy0 && $fy0->payout_ratio_annual !== null && $fy0->payout_ratio_annual > 0) {
        $payout = $fy0->payout_ratio_annual;
        if ($payout >= 30 && $payout <= 60) { $pts = 5; $ev = '健全'; }
        elseif ($payout > 80) {
            $pts = 0; $ev = '過大';
            $flags[] = ['label' => '配当性向過大（' . number_format($payout, 1) . '%）', 'penalty' => -7, '重さ' => '中度'];
        } else { $pts = 3; $ev = '許容範囲'; }
        $add('安全性', '配当性向', 5, $pts, $ev, number_format($payout, 1) . '%');
    } else {
        $add('安全性', '配当性向', 5, null, 'N/A');
    }

    // ---------- E. 還元（10点） ----------
    // ★自社株買い（発行済株式数の推移）は未取得のため今回は対象外。配点は配当利回りに寄せている
    $div = floatval($stock->dividend_yield ?? 0);
    if     ($div >= 3.5) { $pts = 10; $ev = '高配当'; }
    elseif ($div >= 2)   { $pts = 7;  $ev = '普通'; }
    elseif ($div >= 1)   { $pts = 3;  $ev = '低め'; }
    else                  { $pts = 0;  $ev = '低い/なし'; }
    $add('還元', '配当利回り', 10, $pts, $ev, number_format($div, 2) . '%');

    // ---------- 軸の集計 → 最終スコア ----------
    $axis_weight = [
        'バリュー'   => 20,
        'クオリティ' => $is_financial ? 30 : 20,
        '成長'       => 30,
        '安全性'     => $is_financial ? 10 : 20,
        '還元'       => 10,
    ];
    $total_weight = 0; $total_earned = 0;
    foreach ($axis_weight as $axis => $w) {
        if (!empty($axis_max[$axis])) {
            $total_weight += $w;
            $total_earned += ($axis_earned[$axis] / $axis_max[$axis]) * $w;
        }
    }
    $raw_score = $total_weight > 0 ? ($total_earned / $total_weight * 100) : 0;

    $penalty = 0;
    foreach ($flags as $f) $penalty += $f['penalty'];
    $penalty = max($penalty, -15); // 減点の合計は-15点を上限とする

    $score = (int) round(max(0, $raw_score + $penalty));

    $covered = 0; $total_items = 0;
    foreach ($detail as $d) { $total_items++; if ($d['点数'] !== null) $covered++; }

    if     ($score >= 70) $judgment = ['label' => '買い候補', 'color' => '#27ae60'];
    elseif ($score >= 50) $judgment = ['label' => '中立',     'color' => '#f39c12'];
    else                   $judgment = ['label' => '要注意',   'color' => '#e74c3c'];

    return [
        'score' => $score, 'judgment' => $judgment, 'detail' => $detail,
        'flags' => $flags, 'penalty' => $penalty, 'coverage' => $covered . '/' . $total_items,
        'is_financial' => $is_financial, 'version' => 'v2',
    ];
}

function wp_stocks_calc_score($stock) {
    $is_usd = ($stock->currency ?? 'JPY') === 'USD';
    if (!$is_usd) {
        return wp_stocks_calc_score_jp($stock);
    }
    return wp_stocks_calc_score_us($stock);
}

// --------------------------------------------------
// 財務スコアカード v1（米国株専用。従来ロジックのまま、名前のみ変更）
// --------------------------------------------------
function wp_stocks_calc_score_us($stock) {
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

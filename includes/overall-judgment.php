<?php
/**
 * WP Stocks Manager — 総合判定（5層の合算）
 * 2026-10-14 追加（第1版）／2026-10-15 第1.2版（強い売りの閾値を-30に、財務データ不足は強い買いを買い優勢までに）。第1.1版（日本・米国のタブ分け、タイミングの条件、欠けた層は0、財務はデータが足りない銘柄に使わない）。
 *
 * 5層：土台（財務）／タイミング（テクニカルの複合シグナル）／材料（ニュース加減点）／業種／地合い。
 *   - タイミング・材料・業種・地合いを、それぞれ -1〜+1 に直して重みづけ平均する。
 *     データの無い層（米国株の材料、業種が決まらない銘柄、更新が古い地合いなど）は0（中立）として扱う。
 *     業種の層には、株探タグのテーマの強さを15％だけ混ぜる（相場スコアと同じ。テーマの無い銘柄は業種だけ）。
 *     タイミング（テクニカル）が無い銘柄は、判定しない。
 *   - 買い側・売り側のラベルは、テクニカルの方向が合うときだけ（買い側＝テクニカルが買い優勢以上、売り側＝売り優勢以下）。
 *     合わないときは中立にとどめる（業種と地合いだけで買い側にならないように）。
 *   - 土台（財務スコア）は足し算に入れない。財務が「要注意」（50点未満）のとき、買い側のラベルを1段階下げる。
 *     財務スコアの取れた項目が半分に満たない銘柄（新規上場など）には、このフィルターを使わない。
 *     ただし、財務を確認できていない銘柄は、「強い買い」を「買い優勢」までにとどめる。
 *   - 判定の材料の6要素（決算サプライズ・EPS修正など）は、点数への換算が決まるまで、この判定には入れない。
 * 2026-10-17 第1.3版：財務の「ROE」「営業利益ROA（ROICに近い指標）」「売上・営業利益・会社予想の増益率」を、小さな層「質・成長」（重み0.10）として足し算に追加。
 *   各項目は、財務スコアの得点率（満点に対する割合）を-1〜+1に直して使う（営業利益ROAは営業利益÷総資産。ROICの近似）。財務スコアの基準そのものは変えない。
 * 重み・閾値は仮の値。判定履歴（stock_judgment_history）の実績を見て調整する。weights_ver に版を記録する。
 */

if (!defined('ABSPATH')) exit;

function wp_stocks_oj_version() { return 'v1.3-prov'; }

// 層の重み（仮）。合計は1.10（合計で割って平均するので、質・成長を足したぶん、他の層の効きが約9%ずつ下がる）
function wp_stocks_oj_weights() {
    return ['timing' => 0.40, 'catalyst' => 0.25, 'sector' => 0.20, 'regime' => 0.15, 'quality' => 0.10];
}

// 総合点（-1〜+1）→ラベルの閾値（仮）
function wp_stocks_oj_thresholds() {
    return ['strong_buy' => 0.50, 'buy' => 0.20, 'sell' => -0.20, 'strong_sell' => -0.30];   // 地合い良のとき全銘柄に+15点が乗るため、強い売りは-50だと届かない
}

// 財務スコアがこの点数未満なら「要注意」とみなし、買い判定を1段階下げる
function wp_stocks_oj_fin_gate_score() { return 50; }

// 財務スコアの取れた項目の割合がこれ未満なら、財務フィルターを使わない
function wp_stocks_oj_fin_min_coverage() { return 0.5; }

// ニュース加減点（±6で頭打ち）を±1に直す
function wp_stocks_oj_news_scale() { return 6.0; }

// タイミングの条件：テクニカルの「買い優勢」の閾値÷「強い買い」の閾値（既定は 2÷5 ＝ 0.4）
function wp_stocks_oj_timing_gate() {
    $th = wp_stocks_get_composite_thresholds();
    return abs($th['buy']) / max(1, abs($th['strong_buy']));
}

// ラベル：キー => [表示名, 色]（テクニカルの複合シグナルと同じ呼び方）
function wp_stocks_oj_label_defs() {
    return [
        'strong_buy'  => ['強い買い', '#27ae60'],
        'buy'         => ['買い優勢', '#3498db'],
        'neutral'     => ['中立',     '#f39c12'],
        'sell'        => ['売り優勢', '#e67e22'],
        'strong_sell' => ['強い売り', '#e74c3c'],
    ];
}

function wp_stocks_oj_clamp($v) { return max(-1.0, min(1.0, (float) $v)); }

function wp_stocks_oj_is_buy_side($key)  { return $key === 'strong_buy' || $key === 'buy'; }
function wp_stocks_oj_is_sell_side($key) { return $key === 'strong_sell' || $key === 'sell'; }

// 総合点からラベルのキーを決める
function wp_stocks_oj_label_for($total) {
    $th = wp_stocks_oj_thresholds();
    if ($total >= $th['strong_buy'])  return 'strong_buy';
    if ($total >= $th['buy'])         return 'buy';
    if ($total <= $th['strong_sell']) return 'strong_sell';
    if ($total <= $th['sell'])        return 'sell';
    return 'neutral';
}

// --------------------------------------------------
// 合算（純粋計算）
// $layers: ['timing' =>, 'catalyst' =>, 'sector' =>, 'regime' =>, 'quality' => ]（各 -1〜+1 または null）
// $fin   : 財務フィルターに使う財務スコア（0〜100）。使わないときは null
// $fin_ok: 財務を確認できているか（取れた項目が足りない・計算できないときは false → 強い買いは買い優勢までにとどめる）
// 戻り値: ['total' => -1〜+1|null, 'label' => キー|null, 'raw_label' => 条件を掛ける前のキー|null,
//          'gate' => ['applied' => bool, 'timing' => [...]|null, 'fin' => [...]|null], 'layers' => ..., 'weights' => ..., 'used' => [値のあった層]]
// --------------------------------------------------
function wp_stocks_oj_combine(array $layers, $fin = null, $fin_ok = true) {
    $weights = wp_stocks_oj_weights();
    $sum = 0; $used = [];
    foreach ($weights as $k => $wt) {
        $v = array_key_exists($k, $layers) ? $layers[$k] : null;
        if ($v !== null) $used[] = $k;
        $sum += $wt * ($v === null ? 0 : $v);       // データの無い層は0（中立）
    }

    $out = ['total' => null, 'label' => null, 'raw_label' => null,
            'gate' => ['applied' => false, 'timing' => null, 'fin' => null, 'data' => null],
            'layers' => $layers, 'weights' => $weights, 'used' => $used];

    // タイミング（テクニカル）が無い銘柄は判定しない
    if (!in_array('timing', $used, true)) return $out;

    $total = $sum / array_sum($weights);
    $raw   = wp_stocks_oj_label_for($total);
    $label = $raw;

    // タイミングの条件：買い側はテクニカルが買い優勢以上、売り側は売り優勢以下のときだけ
    $tg = wp_stocks_oj_timing_gate();
    if (wp_stocks_oj_is_buy_side($label) && $layers['timing'] < $tg) {
        $out['gate']['timing'] = ['from' => $label, 'to' => 'neutral', 'dir' => 'down'];
        $label = 'neutral';
    } elseif (wp_stocks_oj_is_sell_side($label) && $layers['timing'] > -$tg) {
        $out['gate']['timing'] = ['from' => $label, 'to' => 'neutral', 'dir' => 'up'];
        $label = 'neutral';
    }

    // 財務フィルター：要注意なら、買い側を1段階下げる
    if ($fin !== null && $fin < wp_stocks_oj_fin_gate_score() && wp_stocks_oj_is_buy_side($label)) {
        $to = $label === 'strong_buy' ? 'buy' : 'neutral';
        $out['gate']['fin'] = ['from' => $label, 'to' => $to, 'score' => $fin];
        $label = $to;
    }

    // 財務を確認できていない銘柄は、「強い買い」を「買い優勢」までにとどめる
    if (!$fin_ok && $label === 'strong_buy') {
        $out['gate']['data'] = ['from' => 'strong_buy', 'to' => 'buy'];
        $label = 'buy';
    }

    $out['gate']['applied'] = $out['gate']['timing'] !== null || $out['gate']['fin'] !== null || $out['gate']['data'] !== null;
    $out['total']     = $total;
    $out['label']     = $label;
    $out['raw_label'] = $raw;
    return $out;
}

// --------------------------------------------------
// 質・成長の層（-1〜+1）。財務スコア（calc_score の結果）の得点率を使う
//   資本効率：ROE（財務スコアの得点率）／営業利益ROA（営業利益÷総資産。ROICの近似。日本株の非金融のみ）
//   成長    ：売上成長率・営業利益成長率・会社予想の増益率（いずれも財務スコアの得点率）
//   各項目＝2×得点率−1（得点率50%で0）。グループ内は平均、層は取れたグループの平均。取れた項目が無ければ null
// 戻り値: ['value' => -1〜+1|null, 'groups' => [グループ名 => ['value' => , 'items' => [['name','reason','x'],...]]]]
// --------------------------------------------------
function wp_stocks_oj_quality($stock, $fin) {
    $out = ['value' => null, 'groups' => []];
    if (!is_array($fin) || empty($fin['detail']) || !is_array($fin['detail'])) return $out;
    $det          = $fin['detail'];
    $is_us        = ($stock->currency ?? 'JPY') === 'USD';
    $is_financial = !empty($fin['is_financial']);
    $sg           = function ($x) { return sprintf('%+.2f', $x); };

    // 財務スコアの項目 → 項目（得点率を-1〜+1に）
    $from_score = function ($name) use ($det, $is_us, $sg) {
        $d = $det[$name] ?? null;
        if (!is_array($d) || !array_key_exists('点数', $d) || $d['点数'] === null) return null;
        $max = isset($d['満点']) ? (float) $d['満点'] : (($is_us && $name === 'ROE') ? 20.0 : 0.0);
        if ($max <= 0) return null;
        $frac = max(0.0, min(1.0, (float) $d['点数'] / $max));
        $x    = max(-1.0, min(1.0, 2 * $frac - 1));
        $val  = isset($d['値']) && $d['値'] !== null && $d['値'] !== '' && $d['値'] !== 'N/A' ? $d['値'] . '：' : '';
        return ['name' => $name, 'x' => $x,
            'reason' => $name . '　' . $val . '財務スコア' . $d['点数'] . '点／' . $max . '点（得点率' . round($frac * 100) . '%）→ ' . $sg($x)];
    };

    $cap = [];
    $it = $from_score('ROE');
    if ($it) {
        $cap[] = $it;
    } elseif (!$is_us && isset($stock->roe) && $stock->roe !== null && $stock->roe !== '' && (float) $stock->roe < 0) {
        // 財務スコアではROEがマイナスだとN/A扱いになるが、ここではマイナスを弱みとして数える
        $cap[] = ['name' => 'ROE', 'x' => -1.0, 'reason' => 'ROE　' . number_format((float) $stock->roe, 1) . '%：マイナスのため得点率0%（財務スコアではN/A扱い）→ -1.00'];
    }

    // 営業利益ROA（ROICに近い指標）：日本株の非金融のみ
    if (!$is_us && !$is_financial && function_exists('wp_stocks_get_latest_annual_financials')) {
        $fy  = wp_stocks_get_latest_annual_financials($stock->id ?? 0, 1);
        $fy0 = $fy[0] ?? null;
        if ($fy0 && $fy0->operating_profit !== null && !empty($fy0->total_assets) && $fy0->total_assets > 0) {
            $roa = $fy0->operating_profit / $fy0->total_assets * 100;
            if     ($roa >= 8) { $frac = 1.0; $ev = '優良'; }
            elseif ($roa >= 5) { $frac = 0.7; $ev = '良好'; }
            elseif ($roa >= 3) { $frac = 0.3; $ev = '普通'; }
            else               { $frac = 0.0; $ev = '低い'; }
            $x = 2 * $frac - 1;
            $cap[] = ['name' => '営業利益ROA', 'x' => $x,
                'reason' => '営業利益ROA（ROICに近い指標）　' . number_format($roa, 1) . '%（営業利益' . number_format($fy0->operating_profit / 1e8, 1)
                    . '億円÷総資産' . number_format($fy0->total_assets / 1e8, 1) . '億円）：「' . $ev . '」で得点率' . round($frac * 100) . '% → ' . $sg($x)
                    . '。基準（仮）：8%以上=100%／5%以上=70%／3%以上=30%／3%未満=0%'];
        }
    }

    $growth = [];
    foreach (['売上成長率', '営業利益成長率', '会社予想の増益率'] as $nm) {
        $it = $from_score($nm);
        if ($it) $growth[] = $it;
    }

    $groups = ['資本効率（ROE・営業利益ROA）' => $cap, '成長（売上・営業利益・会社予想の増益率）' => $growth];
    $vals = [];
    foreach ($groups as $gname => $items) {
        $gv = null;
        if ($items) {
            $s = 0.0;
            foreach ($items as $i) $s += $i['x'];
            $gv = $s / count($items);
            $vals[] = $gv;
        }
        $out['groups'][$gname] = ['value' => $gv, 'items' => $items];
    }
    if ($vals) $out['value'] = array_sum($vals) / count($vals);
    return $out;
}

// --------------------------------------------------
// 1銘柄の入力を、いまのデータから集める
// 戻り値: ['raw' => [tech, fin, fin_cov, fin_ok, news, regime_level], 'layers' => [...], 'market' => wp_stocks_market_score の結果]
// --------------------------------------------------
function wp_stocks_oj_inputs($stock) {
    global $wpdb;
    $is_us = ($stock->currency ?? 'JPY') === 'USD';

    // タイミング：テクニカルの複合点（未計算＝ラベルが空は、判定に使わない）
    $tech_score = null;
    $tech = $wpdb->get_row($wpdb->prepare(
        "SELECT composite_score, composite_label FROM {$wpdb->prefix}stock_technicals WHERE stock_id = %d", $stock->id
    ));
    if ($tech && (string) $tech->composite_label !== '') $tech_score = (int) $tech->composite_score;
    $timing = null;
    if ($tech_score !== null) {
        $th = wp_stocks_get_composite_thresholds();
        $timing = wp_stocks_oj_clamp($tech_score / max(1, abs($th['strong_buy'])));   // 「強い買い」の閾値で±1
    }

    // 土台：財務スコアと、取れた項目の割合（足りない銘柄はフィルターに使わない）
    $fin = null; $fin_cov = null; $fin_ok = false;   // 財務スコアが計算できなければ、確認できていない扱い
    $fin_res = null;
    if (function_exists('wp_stocks_calc_score')) {
        $sc = wp_stocks_calc_score($stock);
        $fin_res = is_array($sc) ? $sc : null;
        if (is_array($sc) && isset($sc['score'])) {
            $fin = (int) $sc['score'];
            $fin_cov = isset($sc['coverage']) ? (string) $sc['coverage'] : null;
            $fin_ok = true;
            if ($fin_cov !== null && preg_match('#^(\d+)/(\d+)$#', $fin_cov, $mm) && (int) $mm[2] > 0) {
                $fin_ok = ((int) $mm[1] / (int) $mm[2]) >= wp_stocks_oj_fin_min_coverage();
            }
        }
    }

    // 材料：ニュース加減点（日本株のみ）
    $news = null;
    if (!$is_us && function_exists('wp_stocks_news_score_for_symbol')) {
        $code = function_exists('wp_stocks_nws_normalize_symbol') ? wp_stocks_nws_normalize_symbol($stock->code) : $stock->code;
        if (!function_exists('wp_stocks_nws_is_jp_code') || wp_stocks_nws_is_jp_code($code)) {
            $ns = wp_stocks_news_score_for_symbol($code);
            if (is_array($ns) && isset($ns['total'])) $news = (float) $ns['total'];
        }
    }
    $catalyst = $news === null ? null : wp_stocks_oj_clamp($news / wp_stocks_oj_news_scale());

    // 業種・地合い：相場スコアの部品。業種の層には、テーマ（株探タグ）の強さを15％だけ混ぜる
    $m         = wp_stocks_market_score($stock);
    $sector_sc = $m['sector'] !== null ? $m['sector']['score'] : null;
    $theme_sc  = $m['theme'] !== null ? $m['theme']['score'] : null;
    $tb        = wp_stocks_ms_blend()['theme'];
    if ($sector_sc === null && $theme_sc === null) $sector = null;
    elseif ($theme_sc === null)                    $sector = $sector_sc;
    else                                           $sector = (1 - $tb) * ($sector_sc === null ? 0 : $sector_sc) + $tb * $theme_sc;
    $regime = $m['regime']['level'] !== 'unknown' ? $m['regime']['score'] : null;

    // 質・成長：財務スコアの ROE・成長の得点率と、営業利益ROA（ROICの近似）
    $quality = wp_stocks_oj_quality($stock, $fin_res);

    return [
        'raw'    => ['tech' => $tech_score, 'fin' => $fin, 'fin_cov' => $fin_cov, 'fin_ok' => $fin_ok, 'news' => $news, 'regime_level' => $m['regime']['level'], 'theme' => $m['theme'], 'quality' => $quality],
        'layers' => ['timing' => $timing, 'catalyst' => $catalyst, 'sector' => $sector, 'regime' => $regime, 'quality' => $quality['value']],
        'market' => $m,
    ];
}

// 1銘柄の総合判定
function wp_stocks_overall_judgment($stock) {
    $in = wp_stocks_oj_inputs($stock);
    $r  = wp_stocks_oj_combine($in['layers'], $in['raw']['fin_ok'] ? $in['raw']['fin'] : null, $in['raw']['fin_ok']);
    $r['raw']    = $in['raw'];
    $r['market'] = $in['market'];
    return $r;
}

// --------------------------------------------------
// 判定履歴への書き込み：夜の記録（wp_stocks_judgment_cron）の後で、同じ日の行に総合判定を書き足す
//   sector_score=業種の層、market_adj=地合いの層、total_score=総合点(-100〜+100)、total_label=ラベル、weights_ver=版
//   market_environment_history.regime=日本株の地合い（good/neutral/bad/unknown）
// --------------------------------------------------
function wp_stocks_oj_fill_history() {
    global $wpdb;
    if (!function_exists('wp_stocks_jh_table') || !function_exists('wp_stocks_jh_today')) return 0;
    @set_time_limit(300);
    $t     = wp_stocks_jh_table();
    $date  = wp_stocks_jh_today();
    $now   = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $defs  = wp_stocks_oj_label_defs();

    $regime = wp_stocks_ms_regime(false);
    if (function_exists('wp_stocks_jh_market_table')) {
        $wpdb->update(wp_stocks_jh_market_table(), ['regime' => $regime['level']], ['trade_date' => $date]);
    }

    $n = 0;
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio') AND is_sector_etf = 0");
    foreach ((array) $stocks as $s) {
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE stock_id = %d AND judgment_date = %s", $s->id, $date));
        if (!$id) continue;   // 今日の材料がまだ記録されていない銘柄は飛ばす
        $r = wp_stocks_overall_judgment($s);
        $wpdb->update($t, [
            'sector_score' => $r['layers']['sector'],
            'market_adj'   => $r['layers']['regime'],
            'total_score'  => $r['total'] === null ? null : round($r['total'] * 100, 1),
            'total_label'  => $r['label'] === null ? null : $defs[$r['label']][0],
            'weights_ver'  => wp_stocks_oj_version(),
            'updated_at'   => $now,
        ], ['id' => $id]);
        $n++;
    }
    return $n;
}
add_action('wp_stocks_judgment_cron', 'wp_stocks_oj_fill_history', 20);   // 既定(10)の記録処理のあとに実行

// --------------------------------------------------
// 朝の再計算（日本時間 7:30 既定。夕方23:30の記録はそのまま残し、朝の結果は同じ行の am_* 列に別に書く）
//   朝の時点で変わるもの：米国の終値・VIX・米国株のテクニカル・朝のニュース回収。夜間の先物の動きも記録する（点数には未反映）
//   土日は行わない。今日の行がまだ無い銘柄は、朝の列だけの行を作る（夕方の記録が同じ行を更新する）
//   $only_stock : 1銘柄だけ実行する（銘柄ページの手動ボタン用）
// --------------------------------------------------
function wp_stocks_oj_morning_run($only_stock = null) {
    global $wpdb;
    if (!function_exists('wp_stocks_jh_table') || !function_exists('wp_stocks_jh_ensure_tables') || !wp_stocks_jh_ensure_tables()) return 0;
    $manual = $only_stock !== null;
    if (!$manual && (int) (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('N') >= 6) return 0;   // 土日は行わない
    @set_time_limit(300);
    $t    = wp_stocks_jh_table();
    $date = wp_stocks_jh_today();
    $now  = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $defs = wp_stocks_oj_label_defs();

    if (function_exists('wp_stocks_jh_record_overnight')) wp_stocks_jh_record_overnight();

    $stocks = $manual ? [$only_stock] : $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio') AND is_sector_etf = 0");
    $n = 0;
    foreach ((array) $stocks as $s) {
        $r  = wp_stocks_overall_judgment($s);
        $am = [
            'am_total_score' => $r['total'] === null ? null : round($r['total'] * 100, 1),
            'am_total_label' => $r['label'] === null ? null : $defs[$r['label']][0],
            'am_at'          => $now,
        ];
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE stock_id = %d AND judgment_date = %s", $s->id, $date));
        if ($id) $wpdb->update($t, $am, ['id' => $id]);
        else $wpdb->insert($t, array_merge($am, ['stock_id' => (int) $s->id, 'judgment_date' => $date, 'created_at' => $now, 'updated_at' => $now]));
        $n++;
    }
    return $n;
}
add_action('wp_stocks_judgment_am_cron', 'wp_stocks_oj_morning_run');

add_action('init', function () {
    if (wp_next_scheduled('wp_stocks_judgment_am_cron')) return;
    $hm = get_option('wp_stocks_judgment_am_time', '07:30');
    if (!preg_match('/^\d{2}:\d{2}$/', $hm)) $hm = '07:30';
    $next = new DateTime("today {$hm}:00", new DateTimeZone('Asia/Tokyo'));
    if ($next->getTimestamp() <= time()) $next->modify('+1 day');
    wp_schedule_event($next->getTimestamp(), 'daily', 'wp_stocks_judgment_am_cron');
});

// --------------------------------------------------
// マーケット情報「総合判定」タブ（検証用）。日本株と米国株は別のタブ
//   $market: 'jp'（日本株）／'us'（米国株）。総合点の高い順に並べる
// --------------------------------------------------
function wp_stocks_render_overall_judgment_panel($market = 'jp') {
    global $wpdb;
    $is_us  = $market === 'us';
    $mtab   = $is_us ? 'overall_us' : 'overall';
    $filter = sanitize_text_field($_GET['st'] ?? 'all');
    if (!in_array($filter, ['all', 'portfolio', 'watch'], true)) $filter = 'all';

    $where  = $filter === 'all' ? "status IN ('watch','portfolio')" : "status = '" . esc_sql($filter) . "'";
    $cur    = $is_us ? "currency = 'USD'" : "(currency IS NULL OR currency <> 'USD')";
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 0 AND {$cur} AND {$where} ORDER BY code ASC");
    @set_time_limit(120);

    $rows = [];
    foreach ((array) $stocks as $s) $rows[] = ['s' => $s, 'r' => wp_stocks_overall_judgment($s)];
    usort($rows, function ($a, $b) {
        $x = $a['r']['total']; $y = $b['r']['total'];
        if ($x === null && $y === null) return 0;
        if ($x === null) return 1;
        if ($y === null) return -1;
        return $y <=> $x;
    });

    $defs = wp_stocks_oj_label_defs();
    $cnt = array_fill_keys(array_keys($defs), 0); $none = 0; $g_timing = 0; $g_fin = 0; $g_data = 0;
    foreach ($rows as $r) {
        if ($r['r']['label'] === null) { $none++; continue; }
        $cnt[$r['r']['label']]++;
        if ($r['r']['gate']['timing'] !== null) $g_timing++;
        if ($r['r']['gate']['fin'] !== null)    $g_fin++;
        if ($r['r']['gate']['data'] !== null)   $g_data++;
    }

    $fmt = function ($v) {
        if ($v === null) return '<span style="color:#ccc;">-</span>';
        $c = $v >= 0 ? '#e74c3c' : '#3498db';
        return '<span style="color:' . $c . ';font-weight:bold;">' . ($v >= 0 ? '+' : '') . number_format($v, 2) . '</span>';
    };
    $small = function ($t) { return ' <span style="color:#888;font-size:11px;">' . esc_html($t) . '</span>'; };
    $lv = ['good' => '良', 'neutral' => '中立', 'bad' => '悪', 'unknown' => '不明'];
    $w  = wp_stocks_oj_weights(); $th = wp_stocks_oj_thresholds();

    echo '<div style="margin-top:10px;">';
    echo '<h3>&#x1F3AF; 総合判定（' . ($is_us ? '米国株' : '日本株') . '・検証用・第1.1版）</h3>';
    echo '<p style="color:#666;font-size:13px;">5層の合算：タイミング（テクニカルの複合点）' . ($w['timing'] * 100) . '％'
        . ($is_us ? '' : '・材料（ニュース加減点）' . ($w['catalyst'] * 100) . '％') . '・質・成長（ROE・営業利益ROA・成長率）' . ($w['quality'] * 100) . '％・業種' . ($w['sector'] * 100) . '％・地合い' . ($w['regime'] * 100)
        . '％の加重平均（業種には、株探タグのテーマの強さを15％だけ混ぜています）。各層は-1〜+1に換算し、データの無い層は0（中立）として扱います' . ($is_us ? '（米国株は材料の層がありません）' : '') . '。'
        . ' 総合点は-100〜+100。ラベルは、強い買い＝' . ($th['strong_buy'] * 100) . '以上／買い優勢＝' . ($th['buy'] * 100) . '以上／売り優勢＝' . ($th['sell'] * 100)
        . '以下／強い売り＝' . ($th['strong_sell'] * 100) . '以下（いずれも仮の値）。</p>'
        . '<p style="color:#666;font-size:13px;">条件：買い側のラベルはテクニカルが買い優勢以上のときだけ、売り側は売り優勢以下のときだけ（合わないと中立にとどめ、表に「↓タイミング」「↑タイミング」と出します）。'
        . ' 財務（土台）は足し算に入れず、財務スコアが' . wp_stocks_oj_fin_gate_score() . '点未満（要注意）の銘柄は、買い側を1段階下げます（「↓財務」）。'
        . ' 財務の取れた項目が半分に満たない銘柄には、この財務フィルターを使いません（財務の数字に「*」）が、確認できていないので「強い買い」は「買い優勢」までにとどめます（「↓財務データ」）。タイミングが無い銘柄は判定しません。</p>';

    // 絞り込みと件数
    $base = admin_url('admin.php?page=wp-stocks-market&mtab=' . $mtab);
    echo '<p style="margin:6px 0;">';
    foreach (['all' => 'すべて', 'portfolio' => '保有', 'watch' => 'ウォッチ'] as $k => $lab) {
        $style = $filter === $k ? 'font-weight:bold;text-decoration:none;color:#0073aa;' : 'text-decoration:none;';
        echo '<a href="' . esc_url(add_query_arg('st', $k, $base)) . '" style="' . $style . 'margin-right:14px;">' . esc_html($lab) . '</a>';
    }
    echo '</p><p style="margin:6px 0;font-size:13px;">';
    foreach ($defs as $k => $d) {
        echo '<span style="display:inline-block;margin-right:14px;"><strong style="color:' . $d[1] . ';">' . esc_html($d[0]) . '</strong> ' . intval($cnt[$k]) . '</span>';
    }
    echo '<span style="color:#888;">判定なし ' . intval($none) . ' ／ タイミングで中立にとどめ ' . intval($g_timing) . ' ／ 財務で1段階下げ ' . intval($g_fin) . ' ／ 財務データ不足で強い買いを下げ ' . intval($g_data) . '</span></p>';

    $heads = ['コード', '銘柄', '区分', '総合点', '判定', '財務', 'タイミング'];
    if (!$is_us) $heads[] = '材料';
    $heads[] = '質・成長'; $heads[] = '業種'; $heads[] = '地合い';
    echo '<div style="max-height:680px;overflow:auto;border:1px solid #ddd;"><table style="border-collapse:collapse;background:#fff;min-width:980px;width:100%;font-size:13px;"><thead><tr style="background:#f8f9fa;position:sticky;top:0;">';
    foreach ($heads as $h) {
        echo '<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #ddd;">' . esc_html($h) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $s = $row['s']; $r = $row['r']; $L = $r['layers']; $raw = $r['raw'];
        $total = $r['total'] === null
            ? '<span style="color:#ccc;">-</span>'
            : '<span style="font-size:14px;font-weight:bold;color:' . ($r['total'] >= 0 ? '#e74c3c' : '#3498db') . ';">' . ($r['total'] >= 0 ? '+' : '') . number_format($r['total'] * 100, 0) . '</span>';

        if ($r['label'] === null) {
            $lab = '<span style="color:#ccc;">判定なし</span>';
        } else {
            $d = $defs[$r['label']];
            $lab = '<strong style="color:' . $d[1] . ';">' . esc_html($d[0]) . '</strong>';
            if ($r['gate']['timing'] !== null) {
                $g = $r['gate']['timing'];
                $lab .= ' <span style="color:#8e44ad;font-size:11px;" title="テクニカルが' . ($g['dir'] === 'down' ? '買い優勢に届いていない' : '売り優勢に届いていない') . 'ため、' . esc_attr($defs[$g['from']][0]) . 'から中立にとどめました">'
                    . ($g['dir'] === 'down' ? '↓' : '↑') . 'タイミング</span>';
            }
            if ($r['gate']['data'] !== null) {
                $lab .= ' <span style="color:#999;font-size:11px;" title="財務を確認できていないため、強い買いを買い優勢にとどめました">↓財務データ</span>';
            }
            if ($r['gate']['fin'] !== null) {
                $g = $r['gate']['fin'];
                $lab .= ' <span style="color:#c0392b;font-size:11px;" title="財務スコア' . intval($g['score']) . '点（要注意）のため、' . esc_attr($defs[$g['from']][0]) . 'から1段階下げました">↓財務</span>';
            }
        }
        if ($raw['fin'] === null) {
            $fin_cell = '<span style="color:#ccc;">-</span>';
        } else {
            $col = $raw['fin_ok'] ? ($raw['fin'] >= 70 ? '#27ae60' : ($raw['fin'] >= 50 ? '#f39c12' : '#e74c3c')) : '#999';
            $fin_cell = '<span style="color:' . $col . ';font-weight:bold;"' . (!$raw['fin_ok'] ? ' title="取れた項目 ' . esc_attr((string) $raw['fin_cov']) . '：データが足りないため、財務フィルターは使いません"' : '') . '>'
                . intval($raw['fin']) . (!$raw['fin_ok'] ? '*' : '') . '</span>';
        }

        echo '<tr>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . esc_html($s->code) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;"><a href="' . esc_url(admin_url('admin.php?page=wp-stocks-company&stock_id=' . intval($s->id))) . '">' . esc_html($s->name) . '</a></td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;color:#666;">' . esc_html($s->status === 'portfolio' ? '保有' : 'ウォッチ') . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $total . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $lab . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fin_cell . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['timing']) . ($raw['tech'] !== null ? $small(($raw['tech'] >= 0 ? '+' : '') . $raw['tech'] . '点') : '') . '</td>';
        if (!$is_us) {
            echo '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['catalyst']) . ($raw['news'] !== null ? $small(($raw['news'] >= 0 ? '+' : '') . number_format($raw['news'], 1) . '点') : '') . '</td>';
        }
        echo '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['quality'] ?? null) . '</td>';
        echo '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['sector'])
            . ($raw['theme'] ? $small('テーマ' . ($raw['theme']['score'] >= 0 ? '+' : '') . number_format($raw['theme']['score'], 2)) : '') . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['regime']) . $small($lv[$raw['regime_level']] ?? '-') . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div></div>';
}

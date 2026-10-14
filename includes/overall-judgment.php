<?php
/**
 * WP Stocks Manager — 総合判定（5層の合算）
 * 2026-10-14 追加（第1版・仮の重みと閾値）。
 *
 * 5層：土台（財務）／タイミング（テクニカルの複合シグナル）／材料（ニュース加減点）／業種／地合い。
 *   - タイミング・材料・業種・地合いは、それぞれ -1〜+1 に直して重みづけ平均する（データの無い層は除いて按分）。
 *   - 土台（財務スコア）は足し算に混ぜない。財務が「要注意」（50点未満）のとき、買い側の判定を1段階下げる。
 *   - タイミング（テクニカル）が無い銘柄は、判定しない。
 *   - 判定の材料の6要素（決算サプライズ・EPS修正など）は、点数への換算が決まるまで、この判定には入れない。
 * 重み・閾値は仮の値。判定履歴（stock_judgment_history）の実績を見て調整する。weights_ver に版を記録する。
 */

if (!defined('ABSPATH')) exit;

function wp_stocks_oj_version() { return 'v1-prov'; }

// 層の重み（仮）
function wp_stocks_oj_weights() {
    return ['timing' => 0.40, 'catalyst' => 0.25, 'sector' => 0.20, 'regime' => 0.15];
}

// 総合点（-1〜+1）→ラベルの閾値（仮）
function wp_stocks_oj_thresholds() {
    return ['strong_buy' => 0.50, 'buy' => 0.20, 'sell' => -0.20, 'strong_sell' => -0.50];
}

// 財務スコアがこの点数未満なら「要注意」とみなし、買い判定を1段階下げる
function wp_stocks_oj_fin_gate_score() { return 50; }

// ニュース加減点（±6で頭打ち）を±1に直す
function wp_stocks_oj_news_scale() { return 6.0; }

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
// $layers: ['timing' =>, 'catalyst' =>, 'sector' =>, 'regime' => ]（各 -1〜+1 または null）
// $fin   : 財務スコア（0〜100）または null
// 戻り値: ['total' => -1〜+1|null, 'label' => キー|null, 'raw_label' => 財務フィルター前のキー|null,
//          'gate' => ['applied' => bool, ...], 'layers' => ..., 'weights' => ..., 'used' => [使った層]]
// --------------------------------------------------
function wp_stocks_oj_combine(array $layers, $fin = null) {
    $weights = wp_stocks_oj_weights();
    $sum = 0; $wsum = 0; $used = [];
    foreach ($weights as $k => $wt) {
        if (array_key_exists($k, $layers) && $layers[$k] !== null) {
            $sum  += $wt * $layers[$k];
            $wsum += $wt;
            $used[] = $k;
        }
    }

    $out = ['total' => null, 'label' => null, 'raw_label' => null, 'gate' => ['applied' => false],
            'layers' => $layers, 'weights' => $weights, 'used' => $used];

    // タイミング（テクニカル）が無い銘柄は判定しない
    if (!in_array('timing', $used, true) || $wsum <= 0) return $out;

    $total = $sum / $wsum;
    $raw   = wp_stocks_oj_label_for($total);
    $label = $raw;
    if ($fin !== null && $fin < wp_stocks_oj_fin_gate_score() && ($raw === 'strong_buy' || $raw === 'buy')) {
        $label = $raw === 'strong_buy' ? 'buy' : 'neutral';
        $out['gate'] = ['applied' => true, 'from' => $raw, 'to' => $label, 'fin' => $fin];
    }
    $out['total'] = $total;
    $out['label'] = $label;
    $out['raw_label'] = $raw;
    return $out;
}

// --------------------------------------------------
// 1銘柄の入力を、いまのデータから集める
// 戻り値: ['raw' => [tech, fin, news, regime_level], 'layers' => [...], 'market' => wp_stocks_market_score の結果]
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

    // 土台：財務スコア
    $fin = null;
    if (function_exists('wp_stocks_calc_score')) {
        $sc = wp_stocks_calc_score($stock);
        if (is_array($sc) && isset($sc['score'])) $fin = (int) $sc['score'];
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

    // 業種・地合い：相場スコアの部品
    $m      = wp_stocks_market_score($stock);
    $sector = $m['sector'] !== null ? $m['sector']['score'] : null;
    $regime = $m['regime']['level'] !== 'unknown' ? $m['regime']['score'] : null;

    return [
        'raw'    => ['tech' => $tech_score, 'fin' => $fin, 'news' => $news, 'regime_level' => $m['regime']['level']],
        'layers' => ['timing' => $timing, 'catalyst' => $catalyst, 'sector' => $sector, 'regime' => $regime],
        'market' => $m,
    ];
}

// 1銘柄の総合判定
function wp_stocks_overall_judgment($stock) {
    $in = wp_stocks_oj_inputs($stock);
    $r  = wp_stocks_oj_combine($in['layers'], $in['raw']['fin']);
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
// マーケット情報「総合判定」タブ（検証用）：ウォッチ・保有の全銘柄を、総合点の高い順に
// --------------------------------------------------
function wp_stocks_render_overall_judgment_panel() {
    global $wpdb;
    $filter = sanitize_text_field($_GET['st'] ?? 'all');
    if (!in_array($filter, ['all', 'portfolio', 'watch'], true)) $filter = 'all';

    $where = $filter === 'all' ? "status IN ('watch','portfolio')" : "status = '" . esc_sql($filter) . "'";
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 0 AND {$where} ORDER BY code ASC");
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
    $cnt = array_fill_keys(array_keys($defs), 0); $none = 0; $gated = 0;
    foreach ($rows as $r) {
        if ($r['r']['label'] === null) { $none++; continue; }
        $cnt[$r['r']['label']]++;
        if ($r['r']['gate']['applied']) $gated++;
    }

    $fmt = function ($v) {
        if ($v === null) return '<span style="color:#ccc;">-</span>';
        $c = $v >= 0 ? '#e74c3c' : '#3498db';
        return '<span style="color:' . $c . ';font-weight:bold;">' . ($v >= 0 ? '+' : '') . number_format($v, 2) . '</span>';
    };
    $small = function ($t) { return ' <span style="color:#888;font-size:11px;">' . esc_html($t) . '</span>'; };
    $lv = ['good' => '良', 'neutral' => '中立', 'bad' => '悪', 'unknown' => '-'];
    $w  = wp_stocks_oj_weights(); $th = wp_stocks_oj_thresholds();

    echo '<div style="margin-top:10px;">';
    echo '<h3>&#x1F3AF; 総合判定（検証用・第1版）</h3>';
    echo '<p style="color:#666;font-size:13px;">5層の合算：タイミング（テクニカルの複合点）' . ($w['timing'] * 100) . '％・材料（ニュース加減点）' . ($w['catalyst'] * 100)
        . '％・業種' . ($w['sector'] * 100) . '％・地合い' . ($w['regime'] * 100) . '％の加重平均（データの無い層は除いて按分）。各層は-1〜+1に換算しています。'
        . ' 総合点は-100〜+100。ラベルは、強い買い＝' . ($th['strong_buy'] * 100) . '以上／買い優勢＝' . ($th['buy'] * 100) . '以上／売り優勢＝' . ($th['sell'] * 100)
        . '以下／強い売り＝' . ($th['strong_sell'] * 100) . '以下（いずれも仮の値）。'
        . ' 土台（財務）は足し算に入れず、財務スコアが' . wp_stocks_oj_fin_gate_score() . '点未満（要注意）の銘柄は、買い側のラベルを1段階下げます（表の「↓財務」）。'
        . ' タイミングが無い銘柄は判定しません。</p>';

    // 絞り込みと件数
    $base = admin_url('admin.php?page=wp-stocks-market&mtab=overall');
    echo '<p style="margin:6px 0;">';
    foreach (['all' => 'すべて', 'portfolio' => '保有', 'watch' => 'ウォッチ'] as $k => $lab) {
        $style = $filter === $k ? 'font-weight:bold;text-decoration:none;color:#0073aa;' : 'text-decoration:none;';
        echo '<a href="' . esc_url(add_query_arg('st', $k, $base)) . '" style="' . $style . 'margin-right:14px;">' . esc_html($lab) . '</a>';
    }
    echo '</p><p style="margin:6px 0;font-size:13px;">';
    foreach ($defs as $k => $d) {
        echo '<span style="display:inline-block;margin-right:14px;"><strong style="color:' . $d[1] . ';">' . esc_html($d[0]) . '</strong> ' . intval($cnt[$k]) . '</span>';
    }
    echo '<span style="color:#888;">判定なし ' . intval($none) . ' ／ 財務で1段階下げ ' . intval($gated) . '</span></p>';

    echo '<div style="max-height:680px;overflow:auto;border:1px solid #ddd;"><table style="border-collapse:collapse;background:#fff;min-width:1000px;width:100%;font-size:13px;"><thead><tr style="background:#f8f9fa;position:sticky;top:0;">';
    foreach (['コード', '銘柄', '区分', '総合点', '判定', '財務', 'タイミング', '材料', '業種', '地合い'] as $h) {
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
            if ($r['gate']['applied']) {
                $lab .= ' <span style="color:#c0392b;font-size:11px;" title="財務スコア' . intval($r['gate']['fin']) . '点（要注意）のため、' . esc_attr($defs[$r['gate']['from']][0]) . 'から1段階下げました">↓財務</span>';
            }
        }
        $fin_cell = $raw['fin'] === null ? '<span style="color:#ccc;">-</span>'
            : '<span style="color:' . ($raw['fin'] >= 70 ? '#27ae60' : ($raw['fin'] >= 50 ? '#f39c12' : '#e74c3c')) . ';font-weight:bold;">' . intval($raw['fin']) . '</span>';

        echo '<tr>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . esc_html($s->code) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;"><a href="' . esc_url(admin_url('admin.php?page=wp-stocks-company&stock_id=' . intval($s->id))) . '">' . esc_html($s->name) . '</a></td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;color:#666;">' . esc_html($s->status === 'portfolio' ? '保有' : 'ウォッチ') . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $total . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $lab . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fin_cell . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['timing']) . ($raw['tech'] !== null ? $small(($raw['tech'] >= 0 ? '+' : '') . $raw['tech'] . '点') : '') . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['catalyst']) . ($raw['news'] !== null ? $small(($raw['news'] >= 0 ? '+' : '') . number_format($raw['news'], 1) . '点') : '') . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['sector']) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $fmt($L['regime']) . $small($lv[$raw['regime_level']] ?? '-') . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div></div>';
}

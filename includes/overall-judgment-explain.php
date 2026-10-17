<?php
/**
 * WP Stocks Manager — 銘柄ページ「判定の説明」タブ
 * 2026-10-16 追加
 *
 * 総合判定の結果を、理由つきで説明する画面。計算はしない（既存の関数の結果を並べるだけ）。
 *   1. 総合点とラベルと、一文の説明
 *   2. 層ごとの状況（テクニカル／ニュース／業種／地合い）と、財務
 *   3. ラベルが止められた理由
 *   4. 材料（生の値。点数には入れていない）
 *   5. 予想（短期・中期・長期。強気／中立／弱気の3段階と理由。点数にはしない）
 * 呼び出す関数：wp_stocks_overall_judgment（overall-judgment.php）、wp_stocks_jf_collect（judgment-factors.php）、
 *   wp_stocks_calc_score（scoring.php）、wp_stocks_news_score_for_symbol（news-scoring.php）
 * 予想の分け方・向きの判定の閾値は、この中の wp_stocks_oje_outlook() にまとめてある。
 */

if (!defined('ABSPATH')) exit;

// ---------- 表示の小物 ----------

function wp_stocks_oje_sign($v, $dec = 1) {
    return ((float) $v > 0 ? '+' : '') . number_format((float) $v, $dec);
}

// 色つきの数値（プラス赤・マイナス青。ほかの画面と同じ）
function wp_stocks_oje_val($v, $dec = 1, $suffix = '') {
    if ($v === null) return '<span style="color:#ccc;">-</span>';
    $c = (float) $v >= 0 ? '#e74c3c' : '#3498db';
    return '<span style="color:' . $c . ';font-weight:bold;">' . esc_html(wp_stocks_oje_sign($v, $dec) . $suffix) . '</span>';
}

function wp_stocks_oje_plain($v, $dec = 1, $suffix = '') {
    if ($v === null) return '-';
    return number_format((float) $v, $dec) . $suffix;
}

// 向きの判定：v が pos 以上なら +1、neg 以下なら -1、その間は 0。値が無ければ null
function wp_stocks_oje_dir($v, $pos, $neg) {
    if ($v === null) return null;
    if ($v >= $pos) return 1;
    if ($v <= $neg) return -1;
    return 0;
}

// 財務スコアの向き：70点以上が追い風、50点未満が逆風（財務フィルターの閾値と同じ）
function wp_stocks_oje_fin_dir($score) {
    if ($score === null) return null;
    if ($score >= 70) return 1;
    if ($score < 50)  return -1;
    return 0;
}

function wp_stocks_oje_dir_mark($d) {
    if ($d === 1)  return '<span style="color:#27ae60;font-weight:bold;">&#x25B2; 追い風</span>';
    if ($d === -1) return '<span style="color:#e74c3c;font-weight:bold;">&#x25BC; 逆風</span>';
    if ($d === 0)  return '<span style="color:#f39c12;font-weight:bold;">&#xFF0D; 中立</span>';
    return '<span style="color:#aaa;">データなし</span>';
}

function wp_stocks_oje_badge($stance) {
    $defs = ['bull' => ['強気', '#27ae60'], 'neutral' => ['中立', '#f39c12'], 'bear' => ['弱気', '#e74c3c']];
    if (!isset($defs[$stance])) {
        return '<span style="display:inline-block;padding:3px 14px;border-radius:14px;background:#bbb;color:#fff;font-weight:bold;">判断材料不足</span>';
    }
    return '<span style="display:inline-block;padding:3px 14px;border-radius:14px;background:' . $defs[$stance][1] . ';color:#fff;font-weight:bold;">' . esc_html($defs[$stance][0]) . '</span>';
}

// 向きの平均で3段階にする。向きの付いた項目が2つ未満なら判断しない
function wp_stocks_oje_stance(array $items) {
    $vals = [];
    foreach ($items as $it) if ($it['dir'] !== null) $vals[] = $it['dir'];
    if (count($vals) < 2) return null;
    $avg = array_sum($vals) / count($vals);
    if ($avg >= 0.34)  return 'bull';
    if ($avg <= -0.34) return 'bear';
    return 'neutral';
}

function wp_stocks_oje_tech_label_name($key) {
    $map = ['strong_buy' => '強い買い', 'buy' => '買い優勢', 'hold' => '中立', 'neutral' => '中立', 'sell' => '売り優勢', 'strong_sell' => '強い売り'];
    return $map[(string) $key] ?? (string) $key;
}

// 財務スコアの軸ごとの得点率（0〜1）。米国株など軸の無いものは空
function wp_stocks_oje_axis_ratios($fin) {
    $earned = []; $max = [];
    if (is_array($fin) && !empty($fin['detail']) && is_array($fin['detail'])) {
        foreach ($fin['detail'] as $d) {
            if (!is_array($d) || !isset($d['軸']) || !array_key_exists('点数', $d) || $d['点数'] === null) continue;
            $earned[$d['軸']] = ($earned[$d['軸']] ?? 0) + (float) $d['点数'];
            $max[$d['軸']]    = ($max[$d['軸']] ?? 0) + (float) ($d['満点'] ?? 0);
        }
    }
    $out = [];
    foreach ($max as $axis => $mx) if ($mx > 0) $out[$axis] = $earned[$axis] / $mx;
    return $out;
}

// 財務スコアの指標ごとの点数と理由（日本株 v2 のみ。理由が無いデータは出さない）
function wp_stocks_oje_render_fin_reasons($fin) {
    if (!is_array($fin) || empty($fin['detail']) || !is_array($fin['detail'])) return;
    $rows = '';
    foreach ($fin['detail'] as $name => $d) {
        if (!is_array($d) || !isset($d['理由'])) continue;
        $pts = $d['点数'] ?? null;
        $td  = 'padding:4px 8px;border-top:1px solid #f0f0f0;';
        $rows .= '<tr><td style="' . $td . 'color:#888;white-space:nowrap;">' . esc_html((string) ($d['軸'] ?? '')) . '</td>'
            . '<td style="' . $td . 'font-weight:bold;white-space:nowrap;">' . esc_html((string) $name) . '</td>'
            . '<td style="' . $td . 'white-space:nowrap;">' . esc_html((string) ($d['値'] ?? '-')) . '</td>'
            . '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . ($pts === null ? 'N/A' : esc_html($pts . '／' . ($d['満点'] ?? '') . '点')) . '</td>'
            . '<td style="' . $td . 'font-size:12px;line-height:1.6;">' . esc_html((string) $d['理由']) . '</td></tr>';
    }
    if ($rows === '') return;
    echo '<details style="margin-top:10px;"><summary style="cursor:pointer;font-weight:bold;">点数の理由（指標ごと）</summary>'
        . '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:6px;"><tbody>' . $rows . '</tbody></table></div></details>';
}

// ---------- 材料を集める ----------

function wp_stocks_oje_context($stock) {
    global $wpdb;
    $is_us = ($stock->currency ?? 'JPY') === 'USD';

    $oj = wp_stocks_overall_judgment($stock);

    $tech = $wpdb->get_row($wpdb->prepare(
        "SELECT composite_score, composite_label, composite_detail, calculated_at FROM {$wpdb->prefix}stock_technicals WHERE stock_id = %d", $stock->id
    ));
    $tech_detail = [];
    if ($tech && !empty($tech->composite_detail)) {
        $d = json_decode($tech->composite_detail, true);
        if (is_array($d)) $tech_detail = $d;
    }

    $fin = function_exists('wp_stocks_calc_score') ? wp_stocks_calc_score($stock) : null;

    $news = null;
    if (!$is_us && function_exists('wp_stocks_news_score_for_symbol')) {
        $code = function_exists('wp_stocks_nws_normalize_symbol') ? wp_stocks_nws_normalize_symbol($stock->code) : $stock->code;
        if (!function_exists('wp_stocks_nws_is_jp_code') || wp_stocks_nws_is_jp_code($code)) {
            $news = wp_stocks_news_score_for_symbol($code);
        }
    }

    $factors = function_exists('wp_stocks_jf_collect') ? wp_stocks_jf_collect($stock) : [];

    return [
        'is_us' => $is_us, 'oj' => $oj, 'tech' => $tech, 'tech_detail' => $tech_detail,
        'fin' => $fin, 'axes' => wp_stocks_oje_axis_ratios($fin), 'news' => $news, 'f' => $factors,
    ];
}

// ---------- 一文の説明 ----------

function wp_stocks_oje_summary($c) {
    $oj   = $c['oj'];
    $defs = wp_stocks_oj_label_defs();
    if ($oj['label'] === null) {
        return 'テクニカルがまだ計算されていないため、総合判定は出していません。';
    }
    $names = ['timing' => 'テクニカル', 'catalyst' => 'ニュース', 'quality' => '質・成長', 'sector' => '業種', 'regime' => '地合い'];
    $pos = []; $neg = [];
    foreach ($names as $k => $nm) {
        $v = $oj['layers'][$k] ?? null;
        if ($v === null) continue;
        $pt = ($oj['weights'][$k] ?? 0) * $v * 100;
        if ($pt >= 3)  $pos[] = $nm;
        if ($pt <= -3) $neg[] = $nm;
    }
    $s = '総合点は' . wp_stocks_oje_sign($oj['total'] * 100, 0) . '点で、判定は「' . $defs[$oj['label']][0] . '」です。';
    if ($pos) $s .= 'プラスに効いているのは' . implode('・', $pos) . '。';
    if ($neg) $s .= 'マイナスに効いているのは' . implode('・', $neg) . '。';
    if (!$pos && !$neg) $s .= 'どの層も、大きくは効いていません。';
    foreach (wp_stocks_oje_gate_sentences($c) as $g) $s .= $g;
    return $s;
}

// ラベルが止められた理由の文
function wp_stocks_oje_gate_sentences($c) {
    $oj = $c['oj'];
    $defs = wp_stocks_oj_label_defs();
    $out = [];
    if ($oj['label'] === null) return $out;
    $g = $oj['gate'];
    if (!empty($g['timing'])) {
        $from = $defs[$g['timing']['from']][0];
        if ($g['timing']['dir'] === 'down') {
            $out[] = '点数だけなら「' . $from . '」ですが、テクニカルが買い優勢に届いていないので、中立にとどめました（↓タイミング）。';
        } else {
            $out[] = '点数だけなら「' . $from . '」ですが、テクニカルが売り優勢まで下がっていないので、中立にとどめました（↑タイミング）。';
        }
    }
    if (!empty($g['fin'])) {
        $out[] = '財務スコアが' . intval($g['fin']['score']) . '点（' . wp_stocks_oj_fin_gate_score() . '点未満）なので、「' . $defs[$g['fin']['from']][0] . '」を「' . $defs[$g['fin']['to']][0] . '」に1段階下げました（↓財務）。';
    }
    if (!empty($g['data'])) {
        $out[] = '財務を確認できていない（取れた項目が半分に満たない、または計算できない）ので、「強い買い」を「買い優勢」までにとどめました（↓財務データ）。';
    }
    return $out;
}

// ---------- 予想（短期・中期・長期） ----------
// 戻り値: ['short'|'mid'|'long' => ['title', 'span', 'items' => [['name', 'dir', 'text']...]]]
// 向きの閾値はここにまとめてある。点数にはしない（強気／中立／弱気の3段階と理由の文だけ）。
function wp_stocks_oje_outlook($c) {
    $oj = $c['oj']; $L = $oj['layers']; $raw = $oj['raw']; $m = $oj['market'];
    $f  = $c['f']; $axes = $c['axes']; $tech = $c['tech'];
    $tg = function_exists('wp_stocks_oj_timing_gate') ? wp_stocks_oj_timing_gate() : 0.4;

    // ---- 短期（数日〜1か月）----
    $short = [];
    if ($L['timing'] !== null && $tech) {
        $short[] = ['name' => 'テクニカル', 'dir' => wp_stocks_oje_dir($L['timing'], $tg, -$tg),
            'text' => 'テクニカルの総合点は' . wp_stocks_oje_sign($tech->composite_score, 0) . '（' . wp_stocks_oje_tech_label_name($tech->composite_label) . '）です。'];
    } else {
        $short[] = ['name' => 'テクニカル', 'dir' => null, 'text' => 'まだ計算されていません。'];
    }
    if ($L['catalyst'] !== null) {
        $nt = $raw['news'];
        $short[] = ['name' => 'ニュース', 'dir' => wp_stocks_oje_dir($L['catalyst'], 0.2, -0.2),
            'text' => '直近30日のニュース加減点は' . wp_stocks_oje_sign($nt, 1) . '点です。'];
    } else {
        $short[] = ['name' => 'ニュース', 'dir' => null, 'text' => $c['is_us'] ? '米国株はニュースを点数にしていません。' : 'ニュースの点数が取れていません。'];
    }
    $lv = $m['regime']['level'] ?? 'unknown';
    $lvd = ['good' => 1, 'neutral' => 0, 'bad' => -1];
    $lvn = ['good' => '良', 'neutral' => '中立', 'bad' => '悪'];
    $short[] = ['name' => '地合い', 'dir' => $lvd[$lv] ?? null,
        'text' => isset($lvn[$lv]) ? ('地合いは「' . $lvn[$lv] . '」です（' . implode('、', (array) $m['regime']['reasons']) . '）。') : '地合いのデータが足りません。'];
    $de = $f['days_to_earn'] ?? null;
    if ($de !== null) {
        $short[] = ['name' => '決算までの日数', 'dir' => null, 'label' => '参考',
            'text' => '次の決算発表まであと' . intval($de) . '日です。' . ($de <= 14 ? '発表の前後は値動きが大きくなりやすい時期です（向きは付けず、注意として表示）。' : 'まだ先です（向きは付けません）。')];
    }
    $obv = $f['obv_chg20'] ?? null;
    if ($obv !== null) {
        $extra = [];
        if (($f['mfi14'] ?? null) !== null) $extra[] = 'MFI ' . wp_stocks_oje_plain($f['mfi14'], 0);
        if (($f['vol_ratio'] ?? null) !== null) $extra[] = '出来高は直前20日平均の' . wp_stocks_oje_plain($f['vol_ratio'], 2) . '倍';
        $short[] = ['name' => '出来高と資金の流れ', 'dir' => wp_stocks_oje_dir($obv, 0.1, -0.1),
            'text' => 'OBVの20日変化は' . wp_stocks_oje_sign($obv, 3) . ($extra ? '（' . implode('、', $extra) . '）' : '') . 'です。'];
    } else {
        $short[] = ['name' => '出来高と資金の流れ', 'dir' => null, 'text' => '日足が足りず計算できていません。'];
    }

    // ---- 中期（1〜3か月以上）----
    $mid = [];
    $fs = $raw['fin'];
    if ($fs !== null) {
        $mid[] = ['name' => '財務', 'dir' => wp_stocks_oje_fin_dir($fs),
            'text' => '財務スコアは' . intval($fs) . '点です（70点以上で追い風、50点未満で逆風）。' . ($raw['fin_ok'] ? '' : '取れた項目が少なく、参考程度です。')];
    } else {
        $mid[] = ['name' => '財務', 'dir' => null, 'text' => '財務スコアを計算できていません。'];
    }
    if ($L['sector'] !== null) {
        $nm = !empty($m['sector']['name']) ? $m['sector']['name'] : '業種';
        $mid[] = ['name' => '業種の強さ', 'dir' => wp_stocks_oje_dir($L['sector'], 0.2, -0.2),
            'text' => $nm . 'の市場に対する強さは' . wp_stocks_oje_sign($L['sector'], 2) . '（-1〜+1）です。'];
    } else {
        $mid[] = ['name' => '業種の強さ', 'dir' => null, 'text' => '業種が特定できていません。'];
    }
    $rev90 = $f['eps_rev_90d'] ?? null; $rev30 = $f['eps_rev_30d'] ?? null;
    $rev = $rev90 !== null ? $rev90 : $rev30;
    if ($rev !== null) {
        $mid[] = ['name' => '予想EPSの修正', 'dir' => wp_stocks_oje_dir($rev, 5, -5),
            'text' => '予想EPSは' . ($rev90 !== null ? '約90日前' : '約30日前') . 'から' . wp_stocks_oje_sign($rev, 1) . '%変わりました（+5%以上で追い風、-5%以下で逆風）。'];
    } else {
        $mid[] = ['name' => '予想EPSの修正', 'dir' => null, 'text' => '予想EPSの記録が足りません。'];
    }
    $sur = ($f['sur_ni_cons'] ?? null) !== null ? $f['sur_ni_cons'] : ($f['sur_op_cons'] ?? null);
    if ($sur !== null) {
        $mid[] = ['name' => '決算サプライズ', 'dir' => wp_stocks_oje_dir($sur, 5, -5),
            'text' => '直近の決算（' . (string) ($f['sur_date'] ?? '') . '）はコンセンサスより' . wp_stocks_oje_sign($sur, 1) . '%でした（+5%以上で追い風、-5%以下で逆風）。'];
    } else {
        $mid[] = ['name' => '決算サプライズ', 'dir' => null, 'text' => $c['is_us'] ? '米国株は取得していません。' : 'コンセンサス比のデータがありません。'];
    }
    $pos52 = $f['pos_52w'] ?? null;
    if ($pos52 !== null) {
        $mid[] = ['name' => '52週の位置', 'dir' => wp_stocks_oje_dir($pos52, 80, 20),
            'text' => '52週の値幅の中で' . wp_stocks_oje_plain($pos52, 0) . '%の位置です（高値から' . wp_stocks_oje_plain($f['dist_high_pct'] ?? null, 1) . '%）。高値圏は上昇基調の裏づけ、安値圏は逆風として見ています。'];
    } else {
        $mid[] = ['name' => '52週の位置', 'dir' => null, 'text' => '日足が足りず計算できていません。'];
    }

    // ---- 長期（数か月〜数年）----
    $long = [];
    $axis_item = function ($name, array $axis_keys, $note) use ($axes) {
        $vals = [];
        foreach ($axis_keys as $k) if (isset($axes[$k])) $vals[] = $axes[$k];
        if (!$vals) return ['name' => $name, 'dir' => null, 'text' => '軸ごとの評価がありません（米国株、またはデータ不足）。'];
        $r = array_sum($vals) / count($vals);
        return ['name' => $name, 'dir' => wp_stocks_oje_dir($r, 0.6, 0.3),
            'text' => $note . '：得点率' . number_format($r * 100, 0) . '%です（60%以上で追い風、30%以下で逆風）。'];
    };
    if ($axes) {
        $long[] = $axis_item('財務の質', ['クオリティ', '安全性'], '利益率・ROE・営業CF・自己資本比率など');
        $long[] = $axis_item('成長', ['成長'], '増収増益・売上成長率・会社予想の増益率など');
        $long[] = $axis_item('割安度', ['バリュー'], 'PERの業種比・PBR×ROE');
        $dv = $axis_item('配当・還元', ['還元'], '配当利回り・自社株買い');
        if ($dv['dir'] === 0 || $dv['dir'] === -1) $dv['dir'] = 0;   // 配当が低いことは逆風とはしない
        $long[] = $dv;
    } else {
        $long[] = ['name' => '財務スコア（総合）', 'dir' => $fs !== null ? wp_stocks_oje_fin_dir($fs) : null,
            'text' => $fs !== null ? ('財務スコアは' . intval($fs) . '点です。米国株は軸ごとの評価がないため、総合点だけで見ています。') : '財務スコアを計算できていません。'];
    }
    if ($rev90 !== null) {
        $long[] = ['name' => '予想EPSの90日修正', 'dir' => wp_stocks_oje_dir($rev90, 5, -5),
            'text' => '約90日で' . wp_stocks_oje_sign($rev90, 1) . '%変わりました。'];
    } else {
        $long[] = ['name' => '予想EPSの90日修正', 'dir' => null, 'text' => '90日前の記録がまだありません。'];
    }

    return [
        'short' => ['title' => '短期', 'span' => '数日〜1か月', 'items' => $short],
        'mid'   => ['title' => '中期', 'span' => '1〜3か月以上', 'items' => $mid],
        'long'  => ['title' => '長期', 'span' => '数か月〜数年', 'items' => $long],
    ];
}

// ---------- 画面 ----------

// 夜間の先物の動き：直近の記録（朝の再計算で入る）を表示する。点数には反映していない
function wp_stocks_oje_render_overnight($stock) {
    global $wpdb;
    if (!function_exists('wp_stocks_jh_market_table')) return;
    $m = wp_stocks_jh_market_table();
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $m)) !== $m) return;
    if (!in_array('fut_nky', (array) $wpdb->get_col("DESCRIBE {$m}", 0), true)) return;
    $row = $wpdb->get_row("SELECT trade_date, am_at, fut_nky, fut_sp, fut_nq FROM {$m} WHERE am_at IS NOT NULL ORDER BY trade_date DESC LIMIT 1");
    if (!$row) return;
    $is_us = ($stock->currency ?? 'JPY') === 'USD';
    $items = $is_us ? ['S&P500先物' => $row->fut_sp, 'ナスダック100先物' => $row->fut_nq, '日経先物' => $row->fut_nky]
                    : ['日経先物' => $row->fut_nky, 'S&P500先物' => $row->fut_sp, 'ナスダック100先物' => $row->fut_nq];
    echo '<div style="background:#f7f9fb;border:1px solid #dde4ea;border-radius:6px;padding:10px 14px;margin:0 0 16px;max-width:900px;font-size:13px;">';
    echo '<strong>&#x1F319; 夜間の先物の動き</strong>（' . esc_html(substr((string) $row->am_at, 0, 16)) . ' 時点）　';
    foreach ($items as $label => $v) {
        echo esc_html($label) . ' ';
        if ($v === null) { echo '<span style="color:#aaa;">取得なし</span>　'; continue; }
        $c = $v >= 0 ? '#e74c3c' : '#3498db';
        echo '<span style="color:' . $c . ';font-weight:bold;">' . ($v >= 0 ? '+' : '') . esc_html(number_format((float) $v, 2)) . '%</span>　';
    }
    echo '<div style="color:#888;font-size:11px;margin-top:4px;">日経先物は大阪の日中終値（15:45）から、米国先物は米国現物の終値（16:00 ET）からの変化です。参考表示で、総合点にはまだ反映していません。</div>';
    echo '</div>';
}

function wp_stocks_oje_render_tab($stock) {
    if (!function_exists('wp_stocks_overall_judgment') || !function_exists('wp_stocks_oj_label_defs')) {
        echo '<p style="color:#888;">総合判定の機能が読み込まれていません。</p>';
        return;
    }
    $c    = wp_stocks_oje_context($stock);
    $oj   = $c['oj'];
    $defs = wp_stocks_oj_label_defs();
    $L    = $oj['layers']; $W = $oj['weights']; $m = $oj['market']; $raw = $oj['raw'];
    $tech = $c['tech'];

    // ===== 1. 総合点・ラベル・一文の説明 =====
    $color = $oj['label'] !== null ? $defs[$oj['label']][1] : '#bbb';
    echo '<div style="background:#fff;border:2px solid ' . esc_attr($color) . ';border-radius:8px;padding:18px 20px;margin-bottom:20px;max-width:900px;">';
    echo '<div style="display:flex;align-items:center;gap:22px;flex-wrap:wrap;">';
    echo '<div style="text-align:center;min-width:90px;"><div style="font-size:42px;font-weight:bold;color:' . esc_attr($color) . ';">'
        . ($oj['total'] === null ? '-' : esc_html(wp_stocks_oje_sign($oj['total'] * 100, 0))) . '</div><div style="font-size:12px;color:#888;">総合点（-100〜+100）</div></div>';
    echo '<div><div style="font-size:22px;font-weight:bold;color:' . esc_attr($color) . ';">' . esc_html($oj['label'] !== null ? $defs[$oj['label']][0] : '判定なし') . '</div>'
        . '<div style="font-size:12px;color:#888;margin-top:4px;">' . esc_html(wp_stocks_oj_version()) . '（重み・閾値は仮の値）</div></div>';
    echo '</div>';
    echo '<p style="margin:14px 0 0;font-size:14px;line-height:1.8;">' . esc_html(wp_stocks_oje_summary($c)) . '</p>';
    echo '</div>';

    // ===== 1.5 夜間の先物の動き（朝の再計算で記録。点数には未反映） =====
    wp_stocks_oje_render_overnight($stock);

    // ===== 2. 層ごとの状況 =====
    echo '<h3 style="margin:24px 0 8px;">&#x1F9E9; 層ごとの状況</h3>';
    echo '<p class="description" style="max-width:900px;">総合点＝5つの層（-1〜+1）を重みづけして平均したものです。データの無い層は0（中立）として計算します。「総合点への効き」は 重み × 値 × 100 です。</p>';
    echo '<div style="overflow-x:auto;"><table class="widefat striped" style="font-size:13px;max-width:1000px;"><thead><tr>'
        . '<th>層</th><th style="text-align:right;">値（-1〜+1）</th><th style="text-align:right;">重み</th><th style="text-align:right;">総合点への効き</th><th>理由</th></tr></thead><tbody>';

    $layer_defs = ['timing' => 'タイミング（テクニカル）', 'catalyst' => '材料（ニュース）', 'quality' => '質・成長', 'sector' => '業種', 'regime' => '地合い'];
    foreach ($layer_defs as $k => $nm) {
        $v  = $L[$k] ?? null;
        $wt = $W[$k] ?? 0;
        echo '<tr><td style="font-weight:bold;white-space:nowrap;">' . esc_html($nm) . '</td>';
        echo '<td style="text-align:right;">' . ($v === null ? '<span style="color:#aaa;">なし（0として計算）</span>' : wp_stocks_oje_val($v, 2)) . '</td>';
        echo '<td style="text-align:right;">' . esc_html(number_format($wt, 2)) . '</td>';
        echo '<td style="text-align:right;">' . ($v === null ? '<span style="color:#ccc;">0.0</span>' : wp_stocks_oje_val($wt * $v * 100, 1)) . '</td>';
        echo '<td style="font-size:12px;line-height:1.7;">';

        if ($k === 'timing') {
            if ($v === null || !$tech) {
                echo 'テクニカルがまだ計算されていません。この層が無い銘柄は、総合判定を出しません。';
            } else {
                $th = function_exists('wp_stocks_get_composite_thresholds') ? wp_stocks_get_composite_thresholds() : ['strong_buy' => 5];
                echo 'テクニカルの総合点 ' . esc_html(wp_stocks_oje_sign($tech->composite_score, 0)) . '（' . esc_html(wp_stocks_oje_tech_label_name($tech->composite_label)) . '）。'
                    . '「強い買い」の閾値 ' . esc_html((string) abs($th['strong_buy'])) . ' を±1として換算しています。';
                if ($c['tech_detail']) {
                    echo '<br>根拠：' . esc_html(implode('、', array_map('strval', $c['tech_detail'])));
                }
                if (!empty($tech->calculated_at)) echo '<br><span style="color:#888;">計算時刻：' . esc_html((string) $tech->calculated_at) . '</span>';
            }
        } elseif ($k === 'catalyst') {
            if ($v === null) {
                echo $c['is_us'] ? '米国株は、ニュースを点数にしていません（0として計算）。' : 'ニュースの点数を取得できていません（0として計算）。';
            } else {
                $lines = [];
                foreach ((array) ($c['news']['items'] ?? []) as $it) {
                    if (empty($it['counted']) || (float) ($it['weight'] ?? 0) <= 0) continue;
                    $lines[] = esc_html(substr((string) ($it['event_date'] ?? ''), 5) . ' ' . ($it['reason'] ?? '')
                        . '（' . wp_stocks_oje_sign((float) ($it['points'] ?? 0), 1) . '点 × ' . round(((float) $it['weight']) * 100) . '% ＝ ' . wp_stocks_oje_sign((float) ($it['eff'] ?? 0), 2) . '）');
                }
                echo '直近30日のニュース加減点 ' . esc_html(wp_stocks_oje_sign((float) $raw['news'], 1)) . '点（±' . esc_html(number_format(wp_stocks_oj_news_scale(), 0)) . 'を±1として換算）。';
                echo $lines ? '<br>点に入っているニュース：<br>' . implode('<br>', $lines) : '<br>点が付いたニュースはありません。';
            }
        } elseif ($k === 'quality') {
            $q = $raw['quality'] ?? null;
            if ($v === null || !is_array($q) || empty($q['groups'])) {
                echo 'ROE・営業利益ROA・成長率のデータが取れていないため、この層はありません（0として計算）。';
            } else {
                echo '資本効率（ROE・営業利益ROA）と成長（売上・営業利益・会社予想の増益率）の2グループの平均です。各項目は、得点率（満点に対する割合）を-1〜+1に直しています（得点率50%が0）。';
                foreach ($q['groups'] as $gname => $g) {
                    if (empty($g['items'])) continue;
                    echo '<br><strong>' . esc_html($gname) . '</strong>：' . wp_stocks_oje_val($g['value'], 2);
                    foreach ($g['items'] as $qi) {
                        echo '<br>・' . esc_html($qi['reason']);
                    }
                }
                echo '<br><span style="color:#888;">営業利益ROAは「営業利益÷総資産」でROICの近似です（投下資本ではなく総資産で割るため、現金の多い会社は低めに出ます。銀行・金融と米国株には使いません）。</span>';
            }
        } elseif ($k === 'sector') {
            if ($v === null) {
                echo '業種が特定できないため、この層はありません（0として計算）。';
            } else {
                $parts = [];
                if (!empty($m['sector']['detail'])) {
                    foreach ($m['sector']['detail'] as $lab => $d) {
                        $parts[] = esc_html($lab . ' ' . wp_stocks_oje_sign($d['diff'], 1) . 'pt（' . $d['rank'] . '位）');
                    }
                }
                if (!empty($m['sector'])) {
                    echo esc_html(($m['sector']['name'] ?? '') . '（業種ETF）の、市場との騰落率の差：') . implode(' ／ ', $parts) . '。'
                        . ' 業種だけの点は ' . wp_stocks_oje_val($m['sector']['score'], 2) . '。';
                }
                if (!empty($m['theme'])) {
                    $tb = function_exists('wp_stocks_ms_blend') ? wp_stocks_ms_blend()['theme'] : 0.15;
                    echo '<br>テーマ（株探タグ）：' . esc_html(implode('・', (array) $m['theme']['themes'])) . '（点 ' . wp_stocks_oje_val($m['theme']['score'], 2) . '）を' . esc_html(round($tb * 100)) . '%だけ混ぜています。';
                }
            }
        } elseif ($k === 'regime') {
            $lvn = ['good' => '良', 'neutral' => '中立', 'bad' => '悪', 'unknown' => 'データなし'];
            $rg  = $m['regime'];
            $nn  = function ($x) { return $x === null ? '-' : number_format((float) $x, 1); };
            echo '<strong>' . esc_html($lvn[$rg['level']] ?? $rg['level']) . '</strong>：' . esc_html(implode('、', (array) $rg['reasons']));
            echo '<br><span style="color:#888;">' . esc_html($rg['symbol'] . ' 終値 ' . $nn($rg['close']) . ' / 25日線 ' . $nn($rg['ma25']) . ' / 75日線 ' . $nn($rg['ma75']) . ' / VIX ' . $nn($rg['vix']['close'] ?? null)) . '</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';

    // 財務（足し算には入れず、フィルターに使う）
    echo '<h3 style="margin:24px 0 8px;">&#x1F4B0; 財務（買いを止めるフィルターに使用。ROE・成長は「質・成長」の層で点に反映）</h3>';
    $fin = $c['fin'];
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 18px;max-width:900px;font-size:13px;line-height:1.8;">';
    if (!is_array($fin) || !isset($fin['score'])) {
        echo '財務スコアを計算できていません。そのため「強い買い」は「買い優勢」までにとどめます。';
    } else {
        $fj = $fin['judgment'] ?? ['label' => '', 'color' => '#888'];
        echo '財務スコア <strong style="font-size:18px;color:' . esc_attr($fj['color'] ?? '#888') . ';">' . intval($fin['score']) . '点</strong>（' . esc_html($fj['label'] ?? '') . '）';
        if (!empty($fin['coverage'])) echo '　取れた項目 ' . esc_html($fin['coverage']);
        echo '<br>' . intval(wp_stocks_oj_fin_gate_score()) . '点未満なら、買い側の判定を1段階下げます。';
        if (!$raw['fin_ok']) echo '<br><span style="color:#b32d2e;">取れた項目が半分に満たないため、このフィルターは使わず、「強い買い」を「買い優勢」までにとどめます。</span>';
        if (!empty($fin['flags'])) {
            $fl = [];
            foreach ($fin['flags'] as $fg) $fl[] = esc_html(($fg['label'] ?? '') . '（' . ($fg['penalty'] ?? '') . '点）');
            echo '<br>減点：' . implode('、', $fl) . '。';
        }
        if ($c['axes']) {
            $ax = [];
            foreach ($c['axes'] as $an => $r) $ax[] = esc_html($an . ' ' . number_format($r * 100, 0) . '%');
            echo '<br>軸ごとの得点率：' . implode(' ／ ', $ax) . '。';
        }
        wp_stocks_oje_render_fin_reasons($fin);
    }
    echo '</div>';

    // ===== 3. ラベルが止められた理由 =====
    $gs = wp_stocks_oje_gate_sentences($c);
    echo '<h3 style="margin:24px 0 8px;">&#x1F6A6; ラベルが止められた理由</h3>';
    if ($gs) {
        echo '<ul style="margin:0 0 0 18px;max-width:900px;list-style:disc;">';
        foreach ($gs as $g) echo '<li style="margin-bottom:4px;">' . esc_html($g) . '</li>';
        echo '</ul>';
    } else {
        echo '<p style="color:#888;">今回は、点数どおりのラベルです（止められていません）。</p>';
    }

    // ===== 4. 材料（生の値） =====
    $f = $c['f'];
    echo '<h3 style="margin:24px 0 8px;">&#x1F4CE; 材料（参考。点数には入れていません）</h3>';
    $rows = [
        ['決算サプライズ（純利益・コンセンサス比）', wp_stocks_oje_val($f['sur_ni_cons'] ?? null, 1, '%'), $c['is_us'] ? '米国株は取得していません' : esc_html((string) ($f['sur_date'] ?? ''))],
        ['決算サプライズ（営業利益・コンセンサス比）', wp_stocks_oje_val($f['sur_op_cons'] ?? null, 1, '%'), ''],
        ['予想EPSの修正（約30日）', wp_stocks_oje_val($f['eps_rev_30d'] ?? null, 1, '%'), ''],
        ['予想EPSの修正（約90日）', wp_stocks_oje_val($f['eps_rev_90d'] ?? null, 1, '%'), ''],
        ['決算発表までの日数', esc_html(wp_stocks_oje_plain($f['days_to_earn'] ?? null, 0, '日')), ''],
        ['52週レンジの位置（0〜100）', esc_html(wp_stocks_oje_plain($f['pos_52w'] ?? null, 0)), '高値から ' . esc_html(wp_stocks_oje_plain($f['dist_high_pct'] ?? null, 1, '%'))],
        ['OBVの20日変化（出来高比）', wp_stocks_oje_val($f['obv_chg20'] ?? null, 3), '-1〜+1'],
        ['MFI（14）', esc_html(wp_stocks_oje_plain($f['mfi14'] ?? null, 0)), '80以上で買われすぎ、20以下で売られすぎの目安'],
        ['出来高比（当日÷直前20日平均）', esc_html(wp_stocks_oje_plain($f['vol_ratio'] ?? null, 2, '倍')), ''],
        ['ATR（14）÷終値', esc_html(wp_stocks_oje_plain($f['atr_pct'] ?? null, 2, '%')), '値動きの大きさ'],
    ];
    echo '<table class="widefat striped" style="font-size:13px;max-width:700px;"><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . esc_html($r[0]) . '</td><td style="text-align:right;">' . $r[1] . '</td><td style="font-size:12px;color:#888;">' . $r[2] . '</td></tr>';
    }
    echo '</tbody></table>';

    // ===== 5. 予想（短期・中期・長期） =====
    $ol = wp_stocks_oje_outlook($c);
    echo '<h3 style="margin:28px 0 8px;">&#x1F52E; 予想（短期・中期・長期）</h3>';
    echo '<p class="description" style="max-width:900px;">項目ごとに「追い風／中立／逆風」を見て、その平均で強気・中立・弱気の3段階にしています。点数ではありません。向きの付いた項目が2つに満たないときは「判断材料不足」です。'
        . '<br>長期は、当たったかどうかの検証に数か月〜1年かかり、判定履歴にはまだ5日・20日・60日後の値動きしか無いため、いまは言葉での整理です。</p>';
    foreach ($ol as $o) {
        $stance = wp_stocks_oje_stance($o['items']);
        $up = 0; $dn = 0; $nu = 0; $na = 0;
        foreach ($o['items'] as $it) {
            if ($it['dir'] === 1) $up++; elseif ($it['dir'] === -1) $dn++; elseif ($it['dir'] === 0) $nu++; else $na++;
        }
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 18px;margin-bottom:14px;max-width:900px;">';
        echo '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:8px;">';
        echo '<span style="font-size:16px;font-weight:bold;">' . esc_html($o['title']) . '</span>'
            . '<span style="color:#888;font-size:12px;">' . esc_html($o['span']) . '</span>' . wp_stocks_oje_badge($stance)
            . '<span style="color:#666;font-size:12px;">追い風 ' . $up . ' ／ 逆風 ' . $dn . ' ／ 中立 ' . $nu . ' ／ 向きなし ' . $na . '</span>';
        echo '</div>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;"><tbody>';
        foreach ($o['items'] as $it) {
            echo '<tr><td style="padding:5px 8px;border-top:1px solid #f0f0f0;white-space:nowrap;font-weight:bold;width:150px;">' . esc_html($it['name']) . '</td>'
                . '<td style="padding:5px 8px;border-top:1px solid #f0f0f0;white-space:nowrap;width:90px;">' . (isset($it['label']) ? '<span style="color:#888;font-weight:bold;">' . esc_html($it['label']) . '</span>' : wp_stocks_oje_dir_mark($it['dir'])) . '</td>'
                . '<td style="padding:5px 8px;border-top:1px solid #f0f0f0;line-height:1.6;">' . esc_html($it['text']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<p class="description" style="max-width:900px;">J-Quants無料プランの財務データは約12週間遅れです（TDnet由来の分は新しい）。投資判断の材料の一つとして使い、最終判断はご自身で行ってください。</p>';
}

<?php
/**
 * WP Stocks Manager — 相場スコア（セクター・指数トレンド・テーマ＋VIXによる逆風判定）
 * 2026-10-12 追加。総合判定（テクニカル・財務・ニュース・相場）の「相場」の部分。
 *
 * 相場スコア = セクター(0.35) + 指数トレンド(0.35) + テーマ(0.30) の加重平均（-1〜+1）。
 *   - データが無い要素は除き、残りの重みを按分する（日本株のみセクター、タグのある銘柄のみテーマ）。
 *   - 逆風フラグは点数とは別に判定する（買い判定を1段階下げる用）。
 * 第1段階では、マーケット情報「指数」タブの確認用の表だけに出す。総合判定への統合は、確認してから。
 */

if (!defined('ABSPATH')) exit;

function wp_stocks_ms_weights() {
    return ['sector' => 0.35, 'index' => 0.35, 'theme' => 0.30];
}

// セクターの順位スコアに使う期間と重み
function wp_stocks_ms_sector_period_weights() {
    return ['1ヶ月' => 0.3, '2ヶ月' => 0.3, '6ヶ月' => 0.4];
}

// 順位（1=最上位）→ -1〜+1
function wp_stocks_ms_rank_score($rank, $n) {
    return $n > 1 ? 1 - 2 * ($rank - 1) / ($n - 1) : 0.0;
}

// --------------------------------------------------
// TOPIX-17の期間別騰落率と順位（純粋計算）
// $etfs_by_code: [コード => stocks行], $history_by_id: [stock_id => [['price' => ..], ...]]（日付昇順）
// 戻り値: ['returns' => [コード => [期間 => %|null]], 'ranks' => [コード => [期間 => 順位]], 'counts' => [期間 => 件数]]
// --------------------------------------------------
function wp_stocks_ms_build_sector_table(array $etfs_by_code, array $history_by_id) {
    $defs    = wp_stocks_index_period_defs();
    $returns = [];
    foreach ($etfs_by_code as $code => $s) {
        $series = $history_by_id[$s->id] ?? [];
        $n      = count($series);
        $row    = [];
        foreach ($defs as $label => $back) {
            $pct = null;
            $idx = $n - 1 - $back;
            if ($n > 0 && $idx >= 0 && $series[$idx]['price'] > 0) {
                $pct = ($series[$n - 1]['price'] - $series[$idx]['price']) / $series[$idx]['price'] * 100;
            }
            $row[$label] = $pct;
        }
        $returns[$code] = $row;
    }

    $ranks  = [];
    $counts = [];
    foreach (array_keys($defs) as $label) {
        $vals = [];
        foreach ($returns as $code => $row) {
            if ($row[$label] !== null) $vals[$code] = $row[$label];
        }
        arsort($vals);
        $counts[$label] = count($vals);
        $r = 0;
        foreach ($vals as $code => $v) {
            $r++;
            $ranks[$code][$label] = $r;
        }
    }
    return ['returns' => $returns, 'ranks' => $ranks, 'counts' => $counts];
}

// TOPIX-17のETF（セクターETF）の表。1リクエスト内ではキャッシュ
function wp_stocks_ms_sector_table() {
    static $cache = null;
    if ($cache !== null) return $cache;
    global $wpdb;

    $map  = wp_stocks_get_topix17_sector_map();
    $etfs = [];
    foreach ((array) $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 1") as $s) {
        if (isset($map[$s->code])) $etfs[$s->code] = $s;   // TOPIX-17以外のセクターETFは順位に混ぜない
    }
    $cache = $etfs
        ? wp_stocks_ms_build_sector_table($etfs, wp_stocks_get_etf_price_history(array_values($etfs), 400))
        : ['returns' => [], 'ranks' => [], 'counts' => []];
    return $cache;
}

// --------------------------------------------------
// 部品1：セクター（日本株のみ）。TOPIX-17の中での順位（1ヶ月・2ヶ月・6ヶ月）
// --------------------------------------------------
function wp_stocks_ms_sector_part($stock) {
    if (($stock->currency ?? 'JPY') === 'USD') return null;
    $sector = wp_stocks_get_effective_sector($stock);
    if ($sector === '') return null;
    $code = wp_stocks_sector33_to_topix17($sector);
    if (!$code) return null;

    $t = wp_stocks_ms_sector_table();
    if (empty($t['ranks'][$code])) return null;

    $sum = 0; $wsum = 0; $all_bottom = true; $detail = [];
    foreach (wp_stocks_ms_sector_period_weights() as $label => $w) {
        $rank = $t['ranks'][$code][$label] ?? null;
        $n    = $t['counts'][$label] ?? 0;
        if ($rank === null || $n < 2) continue;
        $sum  += $w * wp_stocks_ms_rank_score($rank, $n);
        $wsum += $w;
        $detail[$label] = $rank . '/' . $n;
        if ($rank <= $n - intdiv($n, 3)) $all_bottom = false;   // 下位3分の1に入っていない期間がある
    }
    if ($wsum <= 0) return null;

    $map = wp_stocks_get_topix17_sector_map();
    return [
        'score'      => $sum / $wsum,
        'detail'     => $detail,
        'all_bottom' => $all_bottom && count($detail) >= 2,
        'etf'        => $code,
        'name'       => str_replace('NF・', '', $map[$code]['name'] ?? $code),
    ];
}

// --------------------------------------------------
// 部品2：指数トレンド（1ヶ月・6ヶ月の騰落の符号＋75日線の上下）。各±1の平均
// --------------------------------------------------
function wp_stocks_ms_index_trend($symbol) {
    static $cache = [];
    if (isset($cache[$symbol])) return $cache[$symbol];

    $r    = wp_stocks_index_period_returns($symbol);
    $ret1 = $r['returns']['1ヶ月'] ?? null;
    $ret6 = $r['returns']['6ヶ月'] ?? null;

    $rows  = wp_stocks_index_history_get($symbol, 200);
    $n     = count($rows);
    $above = null;
    if ($n >= 75) {
        $sum = 0;
        for ($i = $n - 75; $i < $n; $i++) $sum += floatval($rows[$i]['close_price']);
        $above = floatval($rows[$n - 1]['close_price']) > $sum / 75;
    }

    $signs = [];
    foreach ([$ret1, $ret6] as $v) {
        if ($v !== null) $signs[] = $v > 0 ? 1 : ($v < 0 ? -1 : 0);
    }
    if ($above !== null) $signs[] = $above ? 1 : -1;

    return $cache[$symbol] = [
        'score'      => $signs ? array_sum($signs) / count($signs) : null,
        'ret_1m'     => $ret1,
        'ret_6m'     => $ret6,
        'above_ma75' => $above,
        'asof'       => $r['asof'],
    ];
}

// 銘柄が基準にする指数：米国株=S&P500、日本株=グロース→グロース250 / プライム・スタンダード→TOPIX連動ETF / それ以外→日経平均
function wp_stocks_ms_reference_index($stock) {
    if (($stock->currency ?? 'JPY') === 'USD') return '^GSPC';
    $m = (string) ($stock->market ?? '');
    if (mb_strpos($m, 'グロース') !== false) $sym = '2516.T';
    elseif (mb_strpos($m, 'プライム') !== false || mb_strpos($m, 'スタンダード') !== false) $sym = '1306.T';
    else $sym = '^N225';
    if ($sym !== '^N225' && wp_stocks_ms_index_trend($sym)['score'] === null) $sym = '^N225';   // 履歴が無ければ日経平均
    return $sym;
}

// --------------------------------------------------
// 部品3：テーマの強さ（基準系列の1ヶ月・6ヶ月の騰落。小さな変動は0として扱う）
// --------------------------------------------------
function wp_stocks_ms_theme_signal($key) {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];

    $defs = wp_stocks_theme_defs();
    if (!isset($defs[$key])) return $cache[$key] = null;
    $ret = wp_stocks_index_period_returns($defs[$key]['reference'])['returns'];

    $signs = [];
    foreach (['1ヶ月' => 2.0, '6ヶ月' => 5.0] as $label => $th) {
        $v = $ret[$label] ?? null;
        if ($v === null) continue;
        $signs[] = $v > $th ? 1 : ($v < -$th ? -1 : 0);
    }
    return $cache[$key] = ($signs ? array_sum($signs) / count($signs) : null);
}

// --------------------------------------------------
// VIX（地合いの目安）。25以上=注意、30以上=高い
// --------------------------------------------------
function wp_stocks_ms_vix_state() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows  = wp_stocks_index_history_get('^VIX', 15);
    $n     = count($rows);
    $close = $n ? floatval($rows[$n - 1]['close_price']) : null;
    $level = $close === null ? 'unknown' : ($close >= 30 ? 'high' : ($close >= 25 ? 'caution' : 'normal'));
    return $cache = ['close' => $close, 'level' => $level];
}

// --------------------------------------------------
// 相場スコア本体
// 戻り値: ['score' => -1〜+1 | null, 'parts' => [...], 'headwind' => bool, 'reasons' => [..], 'ref' => 基準指数, 'vix' => [...]]
// --------------------------------------------------
function wp_stocks_market_score($stock) {
    $weights = wp_stocks_ms_weights();
    $parts   = [];

    $parts['sector'] = wp_stocks_ms_sector_part($stock);

    $ref   = wp_stocks_ms_reference_index($stock);
    $trend = wp_stocks_ms_index_trend($ref);
    $trend['symbol'] = $ref;
    $parts['index']  = $trend['score'] !== null ? $trend : null;

    $defs = wp_stocks_theme_defs();
    $ts = []; $names = [];
    foreach (wp_stocks_get_stock_themes($stock) as $key) {
        $sig = wp_stocks_ms_theme_signal($key);
        if ($sig !== null) { $ts[] = $sig; $names[] = $defs[$key]['label']; }
    }
    $parts['theme'] = $ts ? ['score' => array_sum($ts) / count($ts), 'themes' => $names] : null;

    $sum = 0; $wsum = 0;
    foreach ($parts as $k => $p) {
        if ($p === null) continue;
        $sum  += $weights[$k] * $p['score'];
        $wsum += $weights[$k];
    }
    $score = $wsum > 0 ? max(-1, min(1, $sum / $wsum)) : null;

    $reasons = [];
    $sec = $parts['sector'];
    if ($sec && $sec['all_bottom'] && $parts['index'] && $parts['index']['score'] < 0) {
        $reasons[] = 'セクターが主要期間ですべて下位3分の1、かつ指数が弱い';
    }
    $vix = wp_stocks_ms_vix_state();
    if ($vix['level'] === 'high') $reasons[] = 'VIXが30以上';

    return ['score' => $score, 'parts' => $parts, 'headwind' => !empty($reasons), 'reasons' => $reasons, 'ref' => $ref, 'vix' => $vix];
}

// --------------------------------------------------
// マーケット情報「指数」タブ：相場スコアの確認用の表（ウォッチ・保有の全銘柄、スコアの高い順）
// --------------------------------------------------
function wp_stocks_render_market_score_panel() {
    global $wpdb;
    $stocks = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 0 AND status IN ('watch','portfolio') ORDER BY code ASC"
    );

    $rows = [];
    foreach ((array) $stocks as $s) $rows[] = ['s' => $s, 'm' => wp_stocks_market_score($s)];
    usort($rows, function ($a, $b) {
        $x = $a['m']['score']; $y = $b['m']['score'];
        if ($x === null && $y === null) return 0;
        if ($x === null) return 1;
        if ($y === null) return -1;
        return $y <=> $x;
    });

    $fmt = function ($v) {
        if ($v === null) return '<span style="color:#ccc;">-</span>';
        $c = $v >= 0 ? '#e74c3c' : '#3498db';
        return '<span style="color:' . $c . ';font-weight:bold;">' . ($v >= 0 ? '+' : '') . number_format($v, 2) . '</span>';
    };

    $vix = wp_stocks_ms_vix_state();
    $vix_label = ['normal' => '通常', 'caution' => '注意（25以上）', 'high' => '高い（30以上）', 'unknown' => 'データなし'][$vix['level']];
    $vix_color = ['normal' => '#27ae60', 'caution' => '#e67e22', 'high' => '#c0392b', 'unknown' => '#999'][$vix['level']];

    echo '<div style="margin-top:35px;">';
    echo '<h3>&#x1F9ED; 相場スコア（確認用）</h3>';
    echo '<p style="color:#666;font-size:13px;">セクター（TOPIX-17の順位）・指数トレンド（市場区分ごとの基準指数）・テーマ（株探タグ）を、'
        . '0.35 / 0.35 / 0.30 で加重平均した -1〜+1 の点数です（データの無い要素は除いて按分）。'
        . ' VIX：<strong style="color:' . $vix_color . ';">' . ($vix['close'] !== null ? number_format($vix['close'], 1) : '-') . '（' . $vix_label . '）</strong></p>';

    echo '<div style="max-height:640px;overflow:auto;border:1px solid #ddd;"><table style="border-collapse:collapse;background:#fff;min-width:980px;width:100%;font-size:13px;"><thead><tr style="background:#f8f9fa;position:sticky;top:0;">';
    foreach (['コード', '銘柄', '相場スコア', 'セクター（順位）', '指数（基準）', 'テーマ', '逆風'] as $h) {
        echo '<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #ddd;">' . esc_html($h) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $s = $r['s']; $m = $r['m']; $p = $m['parts'];

        $sec = $p['sector']
            ? $fmt($p['sector']['score']) . ' <span style="color:#888;font-size:11px;">' . esc_html($p['sector']['name']) . ' ' . esc_html(implode(' / ', $p['sector']['detail'])) . '</span>'
            : '<span style="color:#ccc;">-</span>';
        $idx = $p['index']
            ? $fmt($p['index']['score']) . ' <span style="color:#888;font-size:11px;">' . esc_html($p['index']['symbol']) . '</span>'
            : '<span style="color:#ccc;">-</span>';
        $thm = $p['theme']
            ? $fmt($p['theme']['score']) . ' <span style="color:#888;font-size:11px;">' . esc_html(implode('・', $p['theme']['themes'])) . '</span>'
            : '<span style="color:#ccc;">-</span>';
        $hw = $m['headwind']
            ? '<span style="color:#c0392b;font-weight:bold;" title="' . esc_attr(implode(' / ', $m['reasons'])) . '">&#x26A0;&#xFE0F; 逆風</span>'
            : '';

        echo '<tr>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . esc_html($s->code) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . esc_html($s->name) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:14px;">' . $fmt($m['score']) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $sec . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $idx . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $thm . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $hw . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div></div>';
}

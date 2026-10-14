<?php
/**
 * WP Stocks Manager — 相場スコア（総合判定の「業種」と「地合い」の層＋テーマのおまけ加点）
 * 2026-10-12 追加／2026-10-13 5層構成に合わせて作り直し。
 *
 *   業種スコア：業種ETFの騰落率 − 市場の騰落率（相対強度）。日本株はTOPIX-17のETF − TOPIX連動ETF(1306.T)、米国株はSPDRのセクターETF − S&P500。
 *   地合い    ：TOPIX(1306.T)の25日線・75日線とVIX。良(+1)／中立(0)／悪(-1)。米国株はS&P500。
 *   テーマ    ：株探タグで該当するテーマの基準系列の強さ。一致したタグの数で重みづけして平均する。
 *   相場スコア（参考）：業種と地合いを 0.5/0.5 で平均（データの無い層は除く）したものを85%、テーマを15%で混ぜる。
 *
 * 総合判定では、業種と地合いを別々の層として使う。ここでの合成点は確認用の目安。
 */

if (!defined('ABSPATH')) exit;

// 業種スコアに使う期間と重み
function wp_stocks_ms_sector_period_weights() {
    return ['1ヶ月' => 0.3, '2ヶ月' => 0.3, '6ヶ月' => 0.4];
}

// 相対強度（TOPIXとの差・ポイント）がこの値で±1になる
function wp_stocks_ms_sector_scale() {
    return ['1ヶ月' => 5.0, '2ヶ月' => 8.0, '6ヶ月' => 15.0];
}

// 相場スコア（参考）の合成：業種と地合いの重み、テーマの加点の大きさ
// テーマの平均で、1つのテーマに数えるタグの数の上限（広いタグが数で押し切らないように）
function wp_stocks_ms_theme_count_cap() {
    return 2;
}

function wp_stocks_ms_blend() {
    return ['sector' => 0.5, 'regime' => 0.5, 'theme' => 0.15];   // theme=テーマを混ぜる割合（残りが業種と地合い）
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
        if (isset($map[$s->code])) $etfs[$s->code] = $s;   // TOPIX-17以外のセクターETFは混ぜない
    }
    if (!$etfs) return $cache = ['returns' => [], 'ranks' => [], 'counts' => []];

    // 元データは指数履歴（1306と同じ取得元）を優先。まだ溜まっていない（126営業日未満）ETFだけ、従来の価格記録で代用する
    $hist     = [];
    $fallback = null;
    foreach ($etfs as $code => $s) {
        $rows = function_exists('wp_stocks_index_history_get') ? wp_stocks_index_history_get($code . '.T', 420) : [];
        if (count($rows) >= 130) {
            $series = [];
            foreach ($rows as $r) $series[] = ['date' => $r['trade_date'], 'price' => (float)$r['close_price'], 'previous_close' => 0.0];
            $hist[$s->id] = $series;
        } else {
            if ($fallback === null) $fallback = wp_stocks_get_etf_price_history(array_values($etfs), 400);
            if (isset($fallback[$s->id])) $hist[$s->id] = $fallback[$s->id];
        }
    }
    $cache = wp_stocks_ms_build_sector_table($etfs, $hist);
    return $cache;
}

// 相対強度の比較先：TOPIX連動ETF(1306.T)の期間別騰落率。履歴が無ければ17業種の単純平均で代用
function wp_stocks_ms_market_returns() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $r = wp_stocks_index_period_returns('1306.T')['returns'];
    if (array_filter($r, function ($v) { return $v !== null; })) {
        return $cache = ['returns' => $r, 'source' => 'TOPIX連動ETF(1306)'];
    }
    $avg = [];
    $t   = wp_stocks_ms_sector_table();
    foreach (array_keys(wp_stocks_index_period_defs()) as $label) {
        $vals = [];
        foreach ($t['returns'] as $row) {
            if ($row[$label] !== null) $vals[] = $row[$label];
        }
        $avg[$label] = $vals ? array_sum($vals) / count($vals) : null;
    }
    return $cache = ['returns' => $avg, 'source' => '17業種の平均'];
}

// --------------------------------------------------
// 業種スコア（日本株のみ）：業種ETFの騰落率 − TOPIXの騰落率（1ヶ月・2ヶ月・6ヶ月）を±1に直して加重平均
// --------------------------------------------------
// 米国のSPDRセクターETF11本の期間別騰落率と順位（1リクエスト内ではキャッシュ）
function wp_stocks_ms_us_sector_table() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $defs = wp_stocks_index_period_defs();
    $returns = [];
    foreach (array_keys(wp_stocks_us_sector_etfs()) as $sym) {
        $returns[$sym] = wp_stocks_index_period_returns($sym)['returns'];
    }
    $ranks = []; $counts = [];
    foreach (array_keys($defs) as $label) {
        $vals = [];
        foreach ($returns as $sym => $row) {
            if (($row[$label] ?? null) !== null) $vals[$sym] = $row[$label];
        }
        arsort($vals);
        $counts[$label] = count($vals);
        $r = 0;
        foreach ($vals as $sym => $v) { $r++; $ranks[$sym][$label] = $r; }
    }
    return $cache = ['returns' => $returns, 'ranks' => $ranks, 'counts' => $counts];
}

// 米国株の業種スコア：SPDRセクターETFの騰落率 − S&P500の騰落率（日本株と同じ期間・重み・換算）
function wp_stocks_ms_sector_part_us($stock) {
    $etf = wp_stocks_us_sector_etf_for(wp_stocks_get_effective_sector($stock));
    if ($etf === null) return null;

    $t     = wp_stocks_ms_us_sector_table();
    $mkt   = wp_stocks_index_period_returns('^GSPC')['returns'];
    $scale = wp_stocks_ms_sector_scale();

    $sum = 0; $wsum = 0; $detail = [];
    foreach (wp_stocks_ms_sector_period_weights() as $label => $w) {
        $ret = $t['returns'][$etf][$label] ?? null;
        $mk  = $mkt[$label] ?? null;
        if ($ret === null || $mk === null) continue;
        $diff = $ret - $mk;
        $sum  += $w * max(-1, min(1, $diff / $scale[$label]));
        $wsum += $w;
        $detail[$label] = ['diff' => $diff, 'rank' => isset($t['ranks'][$etf][$label]) ? $t['ranks'][$etf][$label] . '/' . ($t['counts'][$label] ?? '?') : '-'];
    }
    if ($wsum <= 0) return null;

    $defs = wp_stocks_us_sector_etfs();
    return ['score' => $sum / $wsum, 'detail' => $detail, 'etf' => $etf, 'name' => $defs[$etf]['label'] . '(' . $etf . ')', 'base' => 'S&P500'];
}

function wp_stocks_ms_sector_part($stock) {
    if (($stock->currency ?? 'JPY') === 'USD') return wp_stocks_ms_sector_part_us($stock);
    $sector = wp_stocks_get_effective_sector($stock);
    if ($sector === '') return null;
    $code = wp_stocks_sector33_to_topix17($sector);
    if (!$code) return null;

    $t = wp_stocks_ms_sector_table();
    if (empty($t['returns'][$code])) return null;
    $mkt   = wp_stocks_ms_market_returns();
    $scale = wp_stocks_ms_sector_scale();

    $sum = 0; $wsum = 0; $detail = [];
    foreach (wp_stocks_ms_sector_period_weights() as $label => $w) {
        $ret = $t['returns'][$code][$label] ?? null;
        $mk  = $mkt['returns'][$label] ?? null;
        if ($ret === null || $mk === null) continue;
        $diff = $ret - $mk;
        $sum  += $w * max(-1, min(1, $diff / $scale[$label]));
        $wsum += $w;
        $detail[$label] = ['diff' => $diff, 'rank' => isset($t['ranks'][$code][$label]) ? $t['ranks'][$code][$label] . '/' . ($t['counts'][$label] ?? '?') : '-'];
    }
    if ($wsum <= 0) return null;

    $map = wp_stocks_get_topix17_sector_map();
    return [
        'score'  => $sum / $wsum,
        'detail' => $detail,
        'etf'    => $code,
        'name'   => str_replace('NF・', '', $map[$code]['name'] ?? $code),
        'base'   => $mkt['source'],
    ];
}

// --------------------------------------------------
// VIX（25以上=注意、30以上=高い）
// --------------------------------------------------
function wp_stocks_ms_vix_state() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows  = wp_stocks_index_history_get('^VIX', 15);
    $n     = count($rows);
    $close = $n ? floatval($rows[$n - 1]['close_price']) : null;
    $asof  = $n ? $rows[$n - 1]['trade_date'] : null;
    // 最終日が古い（暦で7日超＝約5営業日以上更新なし）ときは、古い値で判定しない（不明として扱う）
    $stale = $asof !== null && (time() - strtotime($asof)) > 7 * DAY_IN_SECONDS;
    if ($stale) $close = null;
    $level = $close === null ? 'unknown' : ($close >= 30 ? 'high' : ($close >= 25 ? 'caution' : 'normal'));
    return $cache = ['close' => $close, 'level' => $level, 'asof' => $asof, 'stale' => $stale];
}

// 直近 $n 本の終値の単純平均。足りなければ null
function wp_stocks_ms_sma(array $rows, $n) {
    $cnt = count($rows);
    if ($cnt < $n) return null;
    $sum = 0;
    for ($i = $cnt - $n; $i < $cnt; $i++) $sum += floatval($rows[$i]['close_price']);
    return $sum / $n;
}

// --------------------------------------------------
// 地合い：TOPIX(米国株はS&P500)の終値と25日線・75日線、VIXから 良(+1)／中立(0)／悪(-1)
//   良：終値が25日線・75日線の両方より上、かつVIXが25未満（VIXのデータが無ければ無視）
//   悪：終値が25日線・75日線の両方より下、またはVIXが30以上
//   それ以外は中立
// --------------------------------------------------
function wp_stocks_ms_regime($is_us) {
    static $cache = [];
    $key = $is_us ? 'us' : 'jp';
    if (isset($cache[$key])) return $cache[$key];

    $symbol = $is_us ? '^GSPC' : '1306.T';
    $rows   = wp_stocks_index_history_get($symbol, 200);
    if (!$is_us && count($rows) < 75) {                 // TOPIX連動ETFの履歴が無ければ日経平均で代用
        $symbol = '^N225';
        $rows   = wp_stocks_index_history_get($symbol, 200);
    }
    $n     = count($rows);
    $close = $n ? floatval($rows[$n - 1]['close_price']) : null;
    $idx_stale = $n && (time() - strtotime($rows[$n - 1]['trade_date'])) > 7 * DAY_IN_SECONDS;   // 指数自体の更新が止まっている
    $ma25  = wp_stocks_ms_sma($rows, 25);
    $ma75  = wp_stocks_ms_sma($rows, 75);
    $vix   = wp_stocks_ms_vix_state();

    $level = 'neutral'; $score = 0; $reasons = [];
    if ($close === null || $ma25 === null || $ma75 === null) {
        $level = 'unknown'; $reasons[] = '指数の履歴が足りません';
    } elseif ($idx_stale) {
        $level = 'unknown'; $reasons[] = '指数の最終更新が古い（' . $rows[$n - 1]['trade_date'] . '）ため判定しません';
    } else {
        if (!empty($vix['stale'])) $reasons[] = 'VIXの更新が古い（' . $vix['asof'] . '）ため無視';
        $above = $close > $ma25 && $close > $ma75;
        $below = $close < $ma25 && $close < $ma75;
        if ($below)                      $reasons[] = '終値が25日線・75日線の両方を下回る';
        if ($vix['level'] === 'high')    $reasons[] = 'VIXが30以上';
        if ($below || $vix['level'] === 'high') {
            $level = 'bad'; $score = -1;
        } elseif ($above && $vix['level'] !== 'caution') {
            $level = 'good'; $score = 1;
            $reasons[] = '終値が25日線・75日線の両方より上' . ($vix['level'] === 'normal' ? '、VIXは25未満' : '');
        } else {
            if ($vix['level'] === 'caution') $reasons[] = 'VIXが25以上30未満';
            if (!$above && !$below)          $reasons[] = '終値が25日線と75日線の間';
        }
    }
    return $cache[$key] = [
        'level' => $level, 'score' => $score, 'reasons' => $reasons,
        'symbol' => $symbol, 'close' => $close, 'ma25' => $ma25, 'ma75' => $ma75, 'vix' => $vix,
    ];
}

// --------------------------------------------------
// テーマの強さ（基準系列の1ヶ月・6ヶ月の騰落。小さな変動は0として扱う）
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
// 相場スコア本体
// 戻り値: ['score' => 合成点(参考) | null, 'sector' => 業種, 'regime' => 地合い, 'theme' => テーマ]
// --------------------------------------------------
function wp_stocks_market_score($stock) {
    $blend  = wp_stocks_ms_blend();
    $sector = wp_stocks_ms_sector_part($stock);
    $regime = wp_stocks_ms_regime(($stock->currency ?? 'JPY') === 'USD');

    // テーマ：一致したタグの数（上限あり）で重みづけした平均（関連の薄い1タグだけのテーマは軽くなる）
    $defs = wp_stocks_theme_defs();
    $tsum = 0; $tw = 0; $names = [];
    foreach (wp_stocks_get_stock_theme_hits($stock) as $key => $cnt) {
        $sig = wp_stocks_ms_theme_signal($key);
        if ($sig === null) continue;
        $wt    = min($cnt, wp_stocks_ms_theme_count_cap());   // 重みには上限を掛ける（表示の×nは実際に当たった数のまま）
        $tsum += $sig * $wt;
        $tw   += $wt;
        $names[] = $defs[$key]['label'] . ($cnt > 1 ? '×' . $cnt : '');
    }
    $theme = $tw > 0 ? ['score' => $tsum / $tw, 'themes' => $names] : null;

    $sum = 0; $wsum = 0;
    if ($sector !== null)               { $sum += $blend['sector'] * $sector['score'];  $wsum += $blend['sector']; }
    if ($regime['level'] !== 'unknown') { $sum += $blend['regime'] * $regime['score'];  $wsum += $blend['regime']; }
    $score = $wsum > 0 ? $sum / $wsum : null;
    if ($score !== null && $theme !== null) $score = (1 - $blend['theme']) * $score + $blend['theme'] * $theme['score'];   // 混ぜ合わせなので頭打ちは起きない

    return ['score' => $score, 'sector' => $sector, 'regime' => $regime, 'theme' => $theme];
}

// --------------------------------------------------
// マーケット情報「指数」タブ：相場スコアの確認用の表（ウォッチ・保有の全銘柄、合成点の高い順）
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
    $lv_label = ['good' => '良', 'neutral' => '中立', 'bad' => '悪', 'unknown' => 'データなし'];
    $lv_color = ['good' => '#e74c3c', 'neutral' => '#888', 'bad' => '#3498db', 'unknown' => '#bbb'];
    $regime_line = function ($label, $g) use ($lv_label, $lv_color) {
        $num = function ($v) { return $v === null ? '-' : number_format($v, 1); };
        return '<div style="margin:2px 0;"><strong>' . esc_html($label) . '：<span style="color:' . $lv_color[$g['level']] . ';">' . $lv_label[$g['level']] . '</span></strong>'
            . ' <span style="color:#666;font-size:12px;">' . esc_html($g['symbol']) . ' 終値 ' . $num($g['close']) . ' / 25日線 ' . $num($g['ma25']) . ' / 75日線 ' . $num($g['ma75'])
            . ' / VIX ' . $num($g['vix']['close']) . ' ― ' . esc_html(implode('、', $g['reasons'])) . '</span></div>';
    };

    echo '<div style="margin-top:35px;">';
    echo '<h3>&#x1F9ED; 相場スコア（確認用）</h3>';
    echo '<div style="background:#f8f9fa;border:1px solid #e3e3e3;padding:8px 12px;margin-bottom:8px;">'
        . $regime_line('地合い（日本株）', wp_stocks_ms_regime(false))
        . $regime_line('地合い（米国株）', wp_stocks_ms_regime(true)) . '</div>';
    echo '<p style="color:#666;font-size:13px;">業種＝業種ETFの騰落率 − 市場の騰落率（日本株：TOPIX-17のETF − TOPIX連動ETF、米国株：SPDRセクターETF − S&amp;P500。ポイント差を±1に換算、1ヶ月0.3・2ヶ月0.3・6ヶ月0.4）。'
        . ' 地合い＝TOPIX（米国株はS&amp;P500）の25日線・75日線とVIXで 良＋1／中立0／悪−1。'
        . ' 相場スコア（参考）＝業種と地合いを0.5ずつで平均したものを85%、テーマ（株探タグ。一致したタグの数で重みづけ）を15%で混ぜた値。</p>';

    echo '<div style="max-height:640px;overflow:auto;border:1px solid #ddd;"><table style="border-collapse:collapse;background:#fff;min-width:980px;width:100%;font-size:13px;"><thead><tr style="background:#f8f9fa;position:sticky;top:0;">';
    foreach (['コード', '銘柄', '相場スコア（参考）', '業種（相対強度）', '地合い', 'テーマ'] as $h) {
        echo '<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #ddd;">' . esc_html($h) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $s = $r['s']; $m = $r['m'];

        if ($m['sector']) {
            $parts = [];
            foreach ($m['sector']['detail'] as $label => $d) {
                $parts[] = $label . ' ' . ($d['diff'] >= 0 ? '+' : '') . number_format($d['diff'], 1) . 'pt(' . $d['rank'] . ')';
            }
            $sec = $fmt($m['sector']['score']) . ' <span style="color:#888;font-size:11px;">' . esc_html($m['sector']['name'] . '：' . implode(' / ', $parts)) . '</span>';
        } else {
            $sec = '<span style="color:#ccc;">-</span>';
        }
        $rg  = '<span style="color:' . $lv_color[$m['regime']['level']] . ';font-weight:bold;">' . $lv_label[$m['regime']['level']] . '</span>';
        $thm = $m['theme']
            ? $fmt($m['theme']['score']) . ' <span style="color:#888;font-size:11px;">' . esc_html(implode('・', $m['theme']['themes'])) . '</span>'
            : '<span style="color:#ccc;">-</span>';

        echo '<tr>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . esc_html($s->code) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;"><a href="' . esc_url(admin_url('admin.php?page=wp-stocks-company&stock_id=' . intval($s->id))) . '">' . esc_html($s->name) . '</a></td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:14px;">' . $fmt($m['score']) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $sec . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $rg . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $thm . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div></div>';
}

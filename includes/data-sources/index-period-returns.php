<?php
/**
 * WP Stocks Manager — マーケット指数の期間別騰落率（履歴テーブルから計算）
 * 2026-10-11 追加。履歴は index-history.php / index-history-fetch.php が保存する。
 * 計算関数 wp_stocks_index_period_returns() は、のちの総合判定（相場スコア）からも使えるよう表示と分けてある。
 */

if (!defined('ABSPATH')) exit;

// 期間の定義（営業日の本数。セクター分析の「期間別」タブと同じ 1/5/21/42/126 に 1年=252 を追加）
function wp_stocks_index_period_defs() {
    return ['1日' => 1, '5日' => 5, '1ヶ月' => 21, '2ヶ月' => 42, '6ヶ月' => 126, '1年' => 252];
}

// --------------------------------------------------
// 指数1本の期間別騰落率(%)。足りない期間は null
// 戻り値: ['asof' => 最新の日付 or null, 'returns' => ['1日' => 1.23, '5日' => null, ...]]
// --------------------------------------------------
function wp_stocks_index_period_returns($symbol) {
    $rows = wp_stocks_index_history_get($symbol, 420);   // 252営業日は約370暦日。余裕を持たせる
    $n    = count($rows);
    $out  = ['asof' => $n ? $rows[$n - 1]['trade_date'] : null, 'returns' => []];

    foreach (wp_stocks_index_period_defs() as $label => $back) {
        $pct = null;
        $idx = $n - 1 - $back;
        if ($n > 0 && $idx >= 0) {
            $base = floatval($rows[$idx]['close_price']);
            $last = floatval($rows[$n - 1]['close_price']);
            if ($base > 0) $pct = ($last - $base) / $base * 100;
        }
        $out['returns'][$label] = $pct;
    }
    return $out;
}

// --------------------------------------------------
// マーケット情報「指数」タブ：期間別騰落率の表（グループ別）
// 色は同じタブの指数カードに合わせる（上昇=赤・下落=青）
// --------------------------------------------------
function wp_stocks_render_index_period_table($groups = null, $title = null) {
    $periods = array_keys(wp_stocks_index_period_defs());

    echo '<div style="margin-top:35px;">';
    echo '<h3>' . ($title !== null ? $title : '&#x1F4CA; 期間別騰落率') . '</h3>';

    $any = false;
    $body = '';
    foreach (($groups !== null ? $groups : wp_stocks_get_market_index_groups()) as $group_label => $items) {
        $body .= '<tr><td colspan="' . (count($periods) + 2) . '" style="padding:6px 12px;background:#f0f0f0;font-weight:bold;font-size:12px;color:#555;">'
            . esc_html($group_label) . '</td></tr>';
        foreach ($items as $item) {
            $r = wp_stocks_index_period_returns($item['symbol']);
            if ($r['asof'] !== null) $any = true;
            $stale = $r['asof'] !== null && (time() - strtotime($r['asof'])) > 5 * DAY_IN_SECONDS;

            $body .= '<tr>';
            $body .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html($item['label']) . '</td>';
            $body .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-size:12px;color:' . ($stale ? '#c0392b' : '#888') . ';">'
                . ($r['asof'] !== null ? esc_html($r['asof']) : '-') . '</td>';
            foreach ($periods as $label) {
                $pct = $r['returns'][$label] ?? null;
                if ($pct === null) {
                    $body .= '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;color:#ccc;">-</td>';
                } else {
                    $color = $pct >= 0 ? '#e74c3c' : '#3498db';
                    $sign  = $pct >= 0 ? '+' : '';
                    $body .= '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;color:' . $color . ';font-weight:bold;">'
                        . $sign . number_format($pct, 2) . '%</td>';
                }
            }
            $body .= '</tr>';
        }
    }

    if (!$any) {
        echo '<p style="color:#888;">履歴がまだありません。下の「指数の履歴を今すぐ取得」を実行してください。</p></div>';
        return;
    }

    echo '<div style="overflow-x:auto;"><table style="border-collapse:collapse;background:#fff;border:1px solid #ddd;min-width:720px;"><thead><tr style="background:#f8f9fa;">'
        . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">指数</th>'
        . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">基準日</th>';
    foreach ($periods as $label) {
        echo '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ddd;">' . esc_html($label) . '</th>';
    }
    echo '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    echo '<p style="color:#888;font-size:12px;margin-top:6px;">日次履歴の終値で計算（期間は営業日数：1日=1、5日=5、1ヶ月=21、2ヶ月=42、6ヶ月=126、1年=252）。'
        . ($groups === null
            ? '為替・先物は当日分が途中の値です。VIXと米国債10年利回りは値そのものの変化率です。'
            : '上場から日が浅いETFは、履歴が足りない期間が「-」になります。')
        . '</p>';
    echo '</div>';
}

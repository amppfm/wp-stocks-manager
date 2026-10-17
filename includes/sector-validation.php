<?php
/**
 * セクター層の検証
 *
 * 業種ETF17本（1617.T〜1633.T）と TOPIX連動ETF（1306.T）の日足履歴
 * （wp_stock_index_history）だけを使い、
 *   「相対強度スコア」→「その後の超過リターン（業種ETF − 1306.T）」
 * のずれを確認する。管理画面 Stocks > 設定 の「セクター検証」タブに表示する
 * （admin/pages/settings.php から wp_stocks_sv_render_page(true) を呼ぶ）。
 *
 * 目的は成績の最大化ではなく、スコアと実際の動きが大きくずれていないかの確認。
 * スコアの計算は market-score.php の考え方を再現したもの（下の設定を参照）。
 * 実装が違う場合は wp_stocks_sv_config() の値を合わせること。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 設定。market-score.php と合わせる。
 * lookbacks: 営業日数 => [しきい値(pt), 重み]
 */
function wp_stocks_sv_config() {
    return array(
        'bench'     => '1306.T',
        'lookbacks' => array(
            21  => array(5, 0.3),   // 約1ヶ月
            42  => array(8, 0.3),   // 約2ヶ月
            126 => array(15, 0.4),  // 約6ヶ月
        ),
        'horizons'  => array(5, 20), // 検証する先の営業日数
        'bucket'    => 0.3,          // スコアがこの値以上=上位、以下の負値=下位
        'min_ic_n'  => 8,            // 日ごとの順位相関に必要な最少業種数
        'jump'      => 25,           // 日次変動(%)がこれを超えたら異常値（分割・データ不良）として除外
    );
}

function wp_stocks_sv_sector_names() {
    return array(
        '1617.T' => '食品',
        '1618.T' => 'エネルギー資源',
        '1619.T' => '建設・資材',
        '1620.T' => '素材・化学',
        '1621.T' => '医薬品',
        '1622.T' => '自動車・輸送機',
        '1623.T' => '鉄鋼・非鉄',
        '1624.T' => '機械',
        '1625.T' => '電機・精密',
        '1626.T' => '情報通信・サービス他',
        '1627.T' => '電力・ガス',
        '1628.T' => '運輸・物流',
        '1629.T' => '商社・卸売',
        '1630.T' => '小売',
        '1631.T' => '銀行',
        '1632.T' => '金融（除く銀行）',
        '1633.T' => '不動産',
    );
}

/** 1銘柄の終値を 日付 => 終値 で返す（古い順） */
function wp_stocks_sv_load_series($symbol) {
    global $wpdb;
    $table = $wpdb->prefix . 'stock_index_history';
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT trade_date, close_price FROM {$table}
             WHERE symbol = %s AND close_price IS NOT NULL AND close_price > 0
             ORDER BY trade_date ASC",
            $symbol
        )
    );
    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $out[(string) $r->trade_date] = (float) $r->close_price;
        }
    }
    return $out;
}

/**
 * 異常値の除去。ある日の変動が±jump%を超え、5日以内に元の水準へ戻る場合は
 * データ不良（一時的な誤値）とみなして、その間の日を取り除く。
 * 戻らない変動（分割など）はそのまま残す（build() 側で窓ごと除外する）。
 */
function wp_stocks_sv_clean_series(array $series, $sym, array &$removed) {
    $cfg   = wp_stocks_sv_config();
    $thr   = $cfg['jump'] / 100;
    $dates = array_keys($series);
    $vals  = array_values($series);
    $n     = count($vals);
    $out   = array();
    if ($n === 0) {
        return $out;
    }
    $out[$dates[0]] = $vals[0];
    $prev = $vals[0];
    $k    = 1;
    while ($k < $n) {
        if (abs($vals[$k] / $prev - 1) > $thr) {
            $back = null;
            for ($j = $k + 1; $j < $n && $j <= $k + 5; $j++) {
                if (abs($vals[$j] / $prev - 1) <= $thr) {
                    $back = $j;
                    break;
                }
            }
            if ($back !== null) {
                for ($m = $k; $m < $back; $m++) {
                    $removed[] = array($sym, $dates[$m], ($vals[$m] / $prev - 1) * 100);
                }
                $k = $back;
                continue;
            }
        }
        $out[$dates[$k]] = $vals[$k];
        $prev = $vals[$k];
        $k++;
    }
    return $out;
}

function wp_stocks_sv_sign($rel, $thr) {
    if ($rel >= $thr) {
        return 1;
    }
    if ($rel <= -$thr) {
        return -1;
    }
    return 0;
}

/** 平均順位（同順位は平均）を返す */
function wp_stocks_sv_ranks(array $values) {
    $idx = array_keys($values);
    usort($idx, function ($a, $b) use ($values) {
        if ($values[$a] == $values[$b]) {
            return 0;
        }
        return ($values[$a] < $values[$b]) ? -1 : 1;
    });
    $ranks = array();
    $n = count($idx);
    $i = 0;
    while ($i < $n) {
        $j = $i;
        while ($j + 1 < $n && $values[$idx[$j + 1]] == $values[$idx[$i]]) {
            $j++;
        }
        $avg = ($i + $j) / 2 + 1;
        for ($k = $i; $k <= $j; $k++) {
            $ranks[$idx[$k]] = $avg;
        }
        $i = $j + 1;
    }
    ksort($ranks);
    return $ranks;
}

/** スピアマン順位相関。計算できないときは null */
function wp_stocks_sv_spearman(array $x, array $y) {
    $n = count($x);
    if ($n < 3 || $n !== count($y)) {
        return null;
    }
    $rx = array_values(wp_stocks_sv_ranks($x));
    $ry = array_values(wp_stocks_sv_ranks($y));
    $mx = array_sum($rx) / $n;
    $my = array_sum($ry) / $n;
    $sxy = 0.0;
    $sxx = 0.0;
    $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $rx[$i] - $mx;
        $dy = $ry[$i] - $my;
        $sxy += $dx * $dy;
        $sxx += $dx * $dx;
        $syy += $dy * $dy;
    }
    if ($sxx <= 0 || $syy <= 0) {
        return null;
    }
    return $sxy / sqrt($sxx * $syy);
}

/**
 * 全業種のスコアとその後の超過リターンを作る。
 * 戻り値: array(
 *   'records' => [symbol => [i => ['date','score','sig'=>[営業日数=>±1/0],'fwd'=>[H=>%|null]]]],
 *   'dates'   => 記録のある日付の昇順リスト,
 *   'missing' => データの無い業種ETF
 * )
 */
function wp_stocks_sv_build() {
    global $wpdb;
    $cfg   = wp_stocks_sv_config();
    $table = $wpdb->prefix . 'stock_index_history';

    $removed = array();
    $bench   = wp_stocks_sv_clean_series(wp_stocks_sv_load_series($cfg['bench']), $cfg['bench'], $removed);
    $syms  = $wpdb->get_col(
        "SELECT DISTINCT symbol FROM {$table}
         WHERE symbol REGEXP '^16(1[7-9]|2[0-9]|3[0-3])[.]T$' ORDER BY symbol ASC"
    );
    $names   = wp_stocks_sv_sector_names();
    $missing = array();
    $records = array();
    $jumps   = array();
    $dateSet = array();
    $maxLb   = max(array_keys($cfg['lookbacks']));

    if (empty($bench)) {
        return array('records' => array(), 'dates' => array(), 'missing' => array_keys($names), 'jumps' => array(), 'removed' => array(), 'no_bench' => true);
    }

    foreach (array_keys($names) as $sym) {
        if (!is_array($syms) || !in_array($sym, $syms, true)) {
            $missing[] = $sym;
        }
    }

    foreach ((array) $syms as $sym) {
        $s = wp_stocks_sv_clean_series(wp_stocks_sv_load_series($sym), $sym, $removed);
        // 1306.T と日付が揃うものだけ使う
        $d  = array();
        $sc = array();
        $bc = array();
        foreach ($bench as $date => $bv) {
            if (isset($s[$date])) {
                $d[]  = $date;
                $sc[] = $s[$date];
                $bc[] = $bv;
            }
        }
        $n = count($d);
        if ($n <= $maxLb) {
            $missing[] = $sym;
            continue;
        }
        // 異常な日次変動（分割・データ不良）を検出。その変動が窓にかかる記録は除外する
        $cnt = array_fill(0, $n, 0);
        for ($k = 1; $k < $n; $k++) {
            $cnt[$k] = $cnt[$k - 1];
            $ps = ($sc[$k] / $sc[$k - 1] - 1) * 100;
            $pb = ($bc[$k] / $bc[$k - 1] - 1) * 100;
            if (abs($ps) > $cfg['jump'] || abs($pb) > $cfg['jump']) {
                $cnt[$k]++;
                $who = (abs($ps) > $cfg['jump']) ? $sym : $cfg['bench'];
                $pct = (abs($ps) > $cfg['jump']) ? $ps : $pb;
                $jumps[$who . '|' . $d[$k]] = array($who, $d[$k], $pct);
            }
        }
        for ($i = $maxLb; $i < $n; $i++) {
            if ($cnt[$i] - $cnt[$i - $maxLb] > 0) {
                continue; // スコアの計算窓に異常な変動がある
            }
            $score = 0.0;
            $sig   = array();
            foreach ($cfg['lookbacks'] as $lb => $p) {
                $rel = (($sc[$i] / $sc[$i - $lb]) - 1) * 100 - (($bc[$i] / $bc[$i - $lb]) - 1) * 100;
                $sig[$lb] = wp_stocks_sv_sign($rel, $p[0]);
                $score += $p[1] * $sig[$lb];
            }
            $fwd = array();
            foreach ($cfg['horizons'] as $h) {
                if ($i + $h < $n && $cnt[$i + $h] - $cnt[$i] == 0) {
                    $fwd[$h] = (($sc[$i + $h] / $sc[$i]) - 1) * 100 - (($bc[$i + $h] / $bc[$i]) - 1) * 100;
                } else {
                    $fwd[$h] = null;
                }
            }
            $records[$sym][$i] = array('date' => $d[$i], 'score' => round($score, 4), 'sig' => $sig, 'fwd' => $fwd);
            $dateSet[$d[$i]]   = true;
        }
    }

    $dates = array_keys($dateSet);
    sort($dates);
    return array('records' => $records, 'dates' => $dates, 'missing' => array_values(array_unique($missing)), 'jumps' => array_values($jumps), 'removed' => $removed);
}

function wp_stocks_sv_median(array $v) {
    $n = count($v);
    if ($n === 0) {
        return null;
    }
    sort($v);
    $m = intdiv($n, 2);
    return ($n % 2) ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
}

function wp_stocks_sv_bucket($score) {
    $b = wp_stocks_sv_config()['bucket'];
    if ($score >= $b - 1e-9) {
        return 'top';
    }
    if ($score <= -$b + 1e-9) {
        return 'bottom';
    }
    return 'neutral';
}

/**
 * 期間 [$from, $to]（日付文字列、両端含む。null なら無制限）で集計する。
 * 戻り値: [H => ['top'=>[n,avg,hit], 'neutral'=>..., 'bottom'=>..., 'spread'=>, 'ic'=>, 'ic_pos'=>, 'ic_days'=>]]
 */
function wp_stocks_sv_aggregate(array $records, $from = null, $to = null, $variant = null) {
    $cfg = wp_stocks_sv_config();
    $out = array();
    foreach ($cfg['horizons'] as $h) {
        $acc    = array('top' => array(0, 0.0, 0), 'neutral' => array(0, 0.0, 0), 'bottom' => array(0, 0.0, 0));
        $perDay = array();
        $vals   = array('top' => array(), 'neutral' => array(), 'bottom' => array());
        foreach ($records as $sym => $byIdx) {
            foreach ($byIdx as $r) {
                if (($from !== null && $r['date'] < $from) || ($to !== null && $r['date'] > $to)) {
                    continue;
                }
                $f = $r['fwd'][$h];
                if ($f === null) {
                    continue;
                }
                $sv = ($variant === null) ? $r['score'] : $r['sig'][$variant];
                $b  = wp_stocks_sv_bucket($sv);
                $acc[$b][0]++;
                $vals[$b][] = $f;
                $acc[$b][1] += $f;
                if ($f > 0) {
                    $acc[$b][2]++;
                }
                $perDay[$r['date']]['s'][] = $sv;
                $perDay[$r['date']]['f'][] = $f;
            }
        }
        $row = array();
        foreach ($acc as $b => $a) {
            $row[$b] = array(
                'n'   => $a[0],
                'avg' => $a[0] ? $a[1] / $a[0] : null,
                'med' => wp_stocks_sv_median($vals[$b]),
                'hit' => $a[0] ? $a[2] / $a[0] : null,
            );
        }
        $row['spread'] = ($row['top']['avg'] !== null && $row['bottom']['avg'] !== null)
            ? $row['top']['avg'] - $row['bottom']['avg'] : null;

        $ics = array();
        foreach ($perDay as $day) {
            if (count($day['s']) >= $cfg['min_ic_n']) {
                $ic = wp_stocks_sv_spearman($day['s'], $day['f']);
                if ($ic !== null) {
                    $ics[] = $ic;
                }
            }
        }
        $row['ic_days'] = count($ics);
        $row['ic']      = $ics ? array_sum($ics) / count($ics) : null;
        $pos = 0;
        foreach ($ics as $v) {
            if ($v > 0) {
                $pos++;
            }
        }
        $row['ic_pos'] = $ics ? $pos / count($ics) : null;
        $out[$h] = $row;
    }
    return $out;
}

function wp_stocks_sv_fmt($v, $dec = 2, $suffix = '') {
    if ($v === null) {
        return '–';
    }
    return esc_html(number_format((float) $v, $dec) . $suffix);
}

function wp_stocks_sv_render_summary($title, array $agg) {
    $labels = array('top' => '上位（スコア≥+0.3）', 'neutral' => '中立', 'bottom' => '下位（スコア≤-0.3）');
    echo '<h3>' . esc_html($title) . '</h3>';
    foreach ($agg as $h => $row) {
        echo '<p><strong>' . intval($h) . '営業日後の超過リターン（業種ETF − 1306.T、%）</strong></p>';
        echo '<table class="widefat striped" style="max-width:760px"><thead><tr>'
            . '<th>スコア帯</th><th>件数</th><th>平均超過</th><th>中央値</th><th>プラスの割合</th></tr></thead><tbody>';
        foreach ($labels as $k => $label) {
            echo '<tr><td>' . esc_html($label) . '</td><td>' . intval($row[$k]['n']) . '</td><td>'
                . wp_stocks_sv_fmt($row[$k]['avg']) . '</td><td>'
                . wp_stocks_sv_fmt($row[$k]['med']) . '</td><td>'
                . wp_stocks_sv_fmt($row[$k]['hit'] === null ? null : $row[$k]['hit'] * 100, 0, '%') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>上位−下位の差: <strong>' . wp_stocks_sv_fmt($row['spread']) . '</strong>　'
            . '順位相関（日ごとの平均）: <strong>' . wp_stocks_sv_fmt($row['ic'], 3) . '</strong>　'
            . '相関がプラスだった日の割合: ' . wp_stocks_sv_fmt($row['ic_pos'] === null ? null : $row['ic_pos'] * 100, 0, '%')
            . '（' . intval($row['ic_days']) . '日）</p>';
    }
}

/** 直近の目視確認用：20営業日前のスコアと、その後の実績 */
function wp_stocks_sv_render_recent(array $records) {
    $names = wp_stocks_sv_sector_names();
    $h     = 20;
    echo '<h3>直近の確認：' . $h . '営業日前のスコア → その後の実績</h3>';
    echo '<p>「逆行」が多い業種だけ見れば十分です。1回の逆行では調整しない前提で見てください。</p>';
    echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
        . '<th>業種</th><th>' . $h . '営業日前の日付</th><th>そのときのスコア</th>'
        . '<th>その後の超過（%）</th><th>判定</th><th>現在のスコア</th></tr></thead><tbody>';
    $mismatch = 0;
    $counted  = 0;
    foreach ($names as $sym => $name) {
        if (empty($records[$sym])) {
            echo '<tr><td>' . esc_html($name) . '</td><td colspan="5">データなし</td></tr>';
            continue;
        }
        $keys = array_keys($records[$sym]);
        $last = end($keys);
        $past = $last - $h;
        if (!isset($records[$sym][$past])) {
            echo '<tr><td>' . esc_html($name) . '</td><td colspan="5">履歴不足</td></tr>';
            continue;
        }
        $p     = $records[$sym][$past];
        $f     = $p['fwd'][$h];
        $b     = wp_stocks_sv_bucket($p['score']);
        $judge = '中立';
        $style = '';
        if ($f !== null && $b !== 'neutral') {
            $counted++;
            if (($b === 'top' && $f > 0) || ($b === 'bottom' && $f < 0)) {
                $judge = '一致';
            } else {
                $judge = '逆行';
                $style = ' style="color:#b32d2e;font-weight:600"';
                $mismatch++;
            }
        }
        echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($p['date']) . '</td><td>'
            . wp_stocks_sv_fmt($p['score'], 1) . '</td><td>' . wp_stocks_sv_fmt($f) . '</td>'
            . '<td' . $style . '>' . esc_html($judge) . '</td><td>'
            . wp_stocks_sv_fmt($records[$sym][$last]['score'], 1) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p>上位・下位だった業種のうち逆行：' . intval($mismatch) . ' / ' . intval($counted) . '</p>';
}

/**
 * 合成スコアと、1ヶ月・2ヶ月・6ヶ月それぞれ単独のスコアを、
 * 全期間・前半・後半で並べて比べる（上位−下位の差と順位相関）。
 */
function wp_stocks_sv_render_compare(array $records, $mid) {
    $cfg      = wp_stocks_sv_config();
    $variants = array('' => '合成スコア（現行）');
    foreach (array_keys($cfg['lookbacks']) as $lb) {
        $variants[$lb] = max(1, (int) round($lb / 21)) . 'ヶ月の相対強度のみ';
    }
    $periods = array('全期間' => array(null, null), '前半' => array(null, $mid), '後半' => array($mid, null));
    $res     = array();
    foreach ($variants as $key => $label) {
        $v = ($key === '') ? null : (int) $key;
        foreach ($periods as $pn => $rng) {
            $res[$key][$pn] = wp_stocks_sv_aggregate($records, $rng[0], $rng[1], $v);
        }
    }
    echo '<h3>どの期間のスコアが効いているか（比較）</h3>';
    echo '<p>合成スコアと、1ヶ月・2ヶ月・6ヶ月それぞれ単独のスコアを比べます。'
        . '見るのは「前半と後半の両方で、差と相関が同じ向き（プラス）で出ているか」です。'
        . '片方の期間だけ良いものは、偶然の可能性が高いので採用しません。'
        . 'データは約1.5年・窓が重なるため、小さな差は誤差と区別できません。</p>';
    foreach ($cfg['horizons'] as $h) {
        echo '<p><strong>' . intval($h) . '営業日後</strong>（差＝上位−下位の超過リターン（%）、相関＝日ごとの順位相関の平均）</p>';
        echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>スコア</th>';
        foreach (array_keys($periods) as $pn) {
            echo '<th>' . esc_html($pn) . '：差</th><th>' . esc_html($pn) . '：相関</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($variants as $key => $label) {
            echo '<tr><td>' . esc_html($label) . '</td>';
            foreach (array_keys($periods) as $pn) {
                $row = $res[$key][$pn][$h];
                echo '<td>' . wp_stocks_sv_fmt($row['spread']) . '</td><td>' . wp_stocks_sv_fmt($row['ic'], 3) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}

function wp_stocks_sv_render_page($embedded = false) {
    if (!current_user_can('manage_options')) {
        return;
    }
    $data = wp_stocks_sv_build();
    if ($embedded) {
        echo '<div class="wp-stocks-sv-embed">';
        echo '<h2 style="margin-top:0;">&#x1F4CA; セクター検証</h2>';
    } else {
        echo '<div class="wrap"><h1>セクター検証</h1>';
    }
    echo '<p>業種ETF（TOPIX-17）の相対強度スコアが、その後の「業種ETF − 1306.T」の動きとずれていないかを確認します。'
        . '先の期間は重なるため、件数ほどの独立した検証にはなりません。見るのは「方向が合っているか」と「前半・後半で同じか」です。</p>';

    if (!empty($data['no_bench'])) {
        echo '<div class="notice notice-error"><p>1306.T の履歴がありません。</p></div></div>';
        return;
    }
    if (!empty($data['missing'])) {
        echo '<div class="notice notice-warning"><p>履歴が足りない業種ETF: '
            . esc_html(implode(', ', $data['missing'])) . '</p></div>';
    }
    if (!empty($data['removed'])) {
        echo '<div class="notice notice-info"><p>データ不良とみなして取り除いた日（数日で元の水準に戻る異常値）：</p><ul style="margin-left:20px;list-style:disc;">';
        foreach (array_slice($data['removed'], 0, 20) as $j) {
            echo '<li>' . esc_html($j[0]) . ' ' . esc_html($j[1]) . '：' . wp_stocks_sv_fmt($j[2], 1, '%') . '</li>';
        }
        echo '</ul></div>';
    }
    if (!empty($data['jumps'])) {
        echo '<div class="notice notice-warning"><p>水準が戻らない大きな変動（分割の疑い。前後の期間は集計から除外しています）：</p><ul style="margin-left:20px;list-style:disc;">';
        foreach (array_slice($data['jumps'], 0, 20) as $j) {
            echo '<li>' . esc_html($j[0]) . ' ' . esc_html($j[1]) . '：' . wp_stocks_sv_fmt($j[2], 1, '%') . '</li>';
        }
        echo '</ul></div>';
    }
    if (empty($data['dates'])) {
        echo '<p>検証できるデータがありません。</p></div>';
        return;
    }

    $dates = $data['dates'];
    $first = $dates[0];
    $last  = $dates[count($dates) - 1];
    $mid   = $dates[intdiv(count($dates), 2)];
    echo '<p>対象期間: ' . esc_html($first) . ' 〜 ' . esc_html($last) . '（営業日 ' . count($dates) . '日）</p>';

    wp_stocks_sv_render_recent($data['records']);
    wp_stocks_sv_render_summary('全期間', wp_stocks_sv_aggregate($data['records']));
    wp_stocks_sv_render_summary('前半（〜' . $mid . '）', wp_stocks_sv_aggregate($data['records'], null, $mid));
    wp_stocks_sv_render_summary('後半（' . $mid . '〜）', wp_stocks_sv_aggregate($data['records'], $mid, null));
    wp_stocks_sv_render_compare($data['records'], $mid);
    echo '</div>';
}

<?php
/**
 * 判定履歴（日次）
 *
 * 総合判定の材料（テクニカル・財務・ニュース・価格）を、銘柄ごと・日ごとに記録する。
 * あとから重みを調整したり、点数の帯ごとの騰落率で検証したりするための土台。
 *
 *   stock_judgment_history     : 銘柄 × 日（1行）。5・20・60営業日後の騰落率は日が経ってから埋める
 *   market_environment_history : 日ごと（全体で1行）。地合いの生データ（判定はまだ付けない）
 *
 * 取れなかった項目は0ではなくNULLにする（0と区別して、検証から外せるようにするため）。
 * sector_score / market_adj / total_score / total_label / weights_ver は、総合判定の形が決まってから埋める列。
 */

if (!defined('ABSPATH')) exit;

const WP_STOCKS_JH_VERSION = '2';

// v2で追加した列（判定の材料の生の値。judgment-factors.php が計算する）
function wp_stocks_jh_factor_columns() {
    return [
        'sur_ni_cons' => 'FLOAT DEFAULT NULL', 'sur_op_cons' => 'FLOAT DEFAULT NULL', 'sur_date' => 'DATE DEFAULT NULL',
        'eps_rev_30d' => 'FLOAT DEFAULT NULL', 'eps_rev_90d' => 'FLOAT DEFAULT NULL',
        'days_to_earn' => 'INT DEFAULT NULL',
        'pos_52w' => 'FLOAT DEFAULT NULL', 'dist_high_pct' => 'FLOAT DEFAULT NULL',
        'obv_chg20' => 'FLOAT DEFAULT NULL', 'mfi14' => 'FLOAT DEFAULT NULL', 'vol_ratio' => 'FLOAT DEFAULT NULL',
        'atr_pct' => 'FLOAT DEFAULT NULL',
    ];
}

function wp_stocks_jh_table() {
    global $wpdb;
    return $wpdb->prefix . 'stock_judgment_history';
}

function wp_stocks_jh_market_table() {
    global $wpdb;
    return $wpdb->prefix . 'market_environment_history';
}

function wp_stocks_jh_ensure_tables() {
    global $wpdb;
    if (get_option('wp_stocks_jh_db_version') === WP_STOCKS_JH_VERSION) return true;
    $charset = $wpdb->get_charset_collate();
    $t = wp_stocks_jh_table();
    $m = wp_stocks_jh_market_table();
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$t} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_id BIGINT UNSIGNED NOT NULL,
  judgment_date DATE NOT NULL,
  tech_score INT DEFAULT NULL,
  tech_label VARCHAR(20) DEFAULT NULL,
  fin_score INT DEFAULT NULL,
  fin_coverage VARCHAR(12) DEFAULT NULL,
  fin_ver VARCHAR(8) DEFAULT NULL,
  news_score FLOAT DEFAULT NULL,
  news_detail TEXT NULL,
  sector_code VARCHAR(8) DEFAULT NULL,
  sector_score FLOAT DEFAULT NULL,
  market_adj FLOAT DEFAULT NULL,
  total_score FLOAT DEFAULT NULL,
  total_label VARCHAR(20) DEFAULT NULL,
  weights_ver VARCHAR(16) DEFAULT NULL,
  price FLOAT DEFAULT NULL,
  sur_ni_cons FLOAT DEFAULT NULL, sur_op_cons FLOAT DEFAULT NULL, sur_date DATE DEFAULT NULL,
  eps_rev_30d FLOAT DEFAULT NULL, eps_rev_90d FLOAT DEFAULT NULL,
  days_to_earn INT DEFAULT NULL,
  pos_52w FLOAT DEFAULT NULL, dist_high_pct FLOAT DEFAULT NULL,
  obv_chg20 FLOAT DEFAULT NULL, mfi14 FLOAT DEFAULT NULL, vol_ratio FLOAT DEFAULT NULL,
  atr_pct FLOAT DEFAULT NULL,
  ret_5d FLOAT DEFAULT NULL,
  ret_20d FLOAT DEFAULT NULL,
  ret_60d FLOAT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_stock_date (stock_id, judgment_date),
  KEY judgment_date (judgment_date)
) {$charset};");
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$m} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trade_date DATE NOT NULL,
  topix_close FLOAT DEFAULT NULL,
  topix_ma25 FLOAT DEFAULT NULL,
  topix_ma75 FLOAT DEFAULT NULL,
  vix FLOAT DEFAULT NULL,
  us10y FLOAT DEFAULT NULL,
  regime VARCHAR(12) DEFAULT NULL,
  sector_rank_json MEDIUMTEXT NULL,
  theme_ret_json MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_trade_date (trade_date)
) {$charset};");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return false;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $m)) !== $m) return false;
    // 既存テーブルへ、足りない列を追加（v1 → v2）
    $have = $wpdb->get_col("DESCRIBE {$t}", 0);
    foreach (wp_stocks_jh_factor_columns() as $col => $def) {
        if (!in_array($col, $have, true)) $wpdb->query("ALTER TABLE {$t} ADD COLUMN {$col} {$def}");
    }
    update_option('wp_stocks_jh_db_version', WP_STOCKS_JH_VERSION);
    return true;
}

function wp_stocks_jh_today() {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

// ============================================================
// 記録：銘柄
// ============================================================

// 1銘柄ぶんを今日の日付で記録する（同じ日は上書き。5・20・60日後の騰落率は触らない）
function wp_stocks_jh_record_stock($stock, $date = null) {
    global $wpdb;
    if (!wp_stocks_jh_ensure_tables()) return false;
    $date = $date ?: wp_stocks_jh_today();
    $now  = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $is_usd = ($stock->currency ?? 'JPY') === 'USD';

    // テクニカル
    $tech = $wpdb->get_row($wpdb->prepare("SELECT composite_score, composite_label FROM {$wpdb->prefix}stock_technicals WHERE stock_id = %d", $stock->id));
    $tech_score = $tech ? (int)$tech->composite_score : null;
    $tech_label = $tech && $tech->composite_label !== '' ? $tech->composite_label : null;

    // 財務スコアカード
    $fin_score = $fin_cov = $fin_ver = null;
    if (function_exists('wp_stocks_calc_score')) {
        $sc = wp_stocks_calc_score($stock);
        if (is_array($sc) && isset($sc['score'])) {
            $fin_score = (int)$sc['score'];
            $fin_cov   = isset($sc['coverage']) ? (string)$sc['coverage'] : null;
            $fin_ver   = isset($sc['version']) ? (string)$sc['version'] : ($is_usd ? 'us' : null);
        }
    }

    // ニュース加減点（日本株のみ）
    $news_score = null; $news_detail = null; $sector_code = null;
    if (!$is_usd) {
        if (function_exists('wp_stocks_news_score_for_symbol')) {
            $code = function_exists('wp_stocks_nws_normalize_symbol') ? wp_stocks_nws_normalize_symbol($stock->code) : $stock->code;
            if (!function_exists('wp_stocks_nws_is_jp_code') || wp_stocks_nws_is_jp_code($code)) {
                $ns = wp_stocks_news_score_for_symbol($code);
                $news_score = (float)$ns['total'];
                $det = [];
                foreach ((array)$ns['items'] as $it) {
                    if (empty($it['counted'])) continue;
                    $det[] = ['at' => substr((string)($it['event_at'] ?? ''), 0, 16), 'type' => $it['event_type'] ?? '', 'reason' => $it['reason'] ?? '', 'eff' => round((float)($it['eff'] ?? 0), 2)];
                }
                $news_detail = $det ? wp_json_encode($det, JSON_UNESCAPED_UNICODE) : null;
            }
        }
        if (function_exists('wp_stocks_get_effective_sector') && function_exists('wp_stocks_sector33_to_topix17')) {
            $s17 = wp_stocks_sector33_to_topix17(wp_stocks_get_effective_sector($stock));
            $sector_code = $s17 !== null ? (string)$s17 : null;
        }
    }

    // 最新の価格
    $price = $wpdb->get_var($wpdb->prepare("SELECT price FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $stock->id));
    $price = ($price !== null && (float)$price > 0) ? (float)$price : null;

    $row = [
        'tech_score' => $tech_score, 'tech_label' => $tech_label,
        'fin_score' => $fin_score, 'fin_coverage' => $fin_cov, 'fin_ver' => $fin_ver,
        'news_score' => $news_score, 'news_detail' => $news_detail,
        'sector_code' => $sector_code, 'price' => $price, 'updated_at' => $now,
    ];
    // 判定の材料（決算サプライズ、EPS修正、決算接近、52週の位置、資金流入、値動きの大きさ）
    if (function_exists('wp_stocks_jf_collect')) $row = array_merge($row, wp_stocks_jf_collect($stock));
    $t = wp_stocks_jh_table();
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE stock_id = %d AND judgment_date = %s", $stock->id, $date));
    if ($id) {
        $wpdb->update($t, $row, ['id' => $id]);
    } else {
        $wpdb->insert($t, array_merge($row, ['stock_id' => (int)$stock->id, 'judgment_date' => $date, 'created_at' => $now]));
    }
    return true;
}

// 登録銘柄（保有・ウォッチ。セクターETFは除く）すべてを記録
function wp_stocks_jh_record_all() {
    global $wpdb;
    if (!wp_stocks_jh_ensure_tables()) return 0;
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio') AND is_sector_etf = 0");
    $n = 0;
    foreach ((array)$stocks as $s) {
        if (wp_stocks_jh_record_stock($s)) $n++;
    }
    return $n;
}

// ============================================================
// 記録：地合い（全体）
// ============================================================

function wp_stocks_jh_last_close($symbol) {
    if (!function_exists('wp_stocks_index_history_get')) return null;
    $rows = wp_stocks_index_history_get($symbol, 10);
    if (!$rows) return null;
    $v = (float)end($rows)['close_price'];
    return $v > 0 ? $v : null;
}

function wp_stocks_jh_ma($closes, $n) {
    if (count($closes) < $n) return null;
    return array_sum(array_slice($closes, -$n)) / $n;
}

function wp_stocks_jh_record_market() {
    global $wpdb;
    if (!wp_stocks_jh_ensure_tables()) return false;
    $date = wp_stocks_jh_today();
    $now  = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

    $topix = $ma25 = $ma75 = null;
    if (function_exists('wp_stocks_index_history_get')) {
        $closes = array_map(function ($r) { return (float)$r['close_price']; }, wp_stocks_index_history_get('1306.T', 160));
        $closes = array_values(array_filter($closes, function ($v) { return $v > 0; }));
        if ($closes) {
            $topix = end($closes);
            $ma25 = wp_stocks_jh_ma($closes, 25);
            $ma75 = wp_stocks_jh_ma($closes, 75);
        }
    }

    // TOPIX-17の各ETF：5日・21日の騰落率（生データ。順位や相対強度は後で計算する）
    $sector = [];
    $etfs = $wpdb->get_results("SELECT id, code FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 1");
    if ($etfs && function_exists('wp_stocks_get_etf_price_history')) {
        $hist = wp_stocks_get_etf_price_history($etfs, 45);
        foreach ($etfs as $e) {
            $h = $hist[$e->id] ?? [];
            $by_day = [];
            foreach ($h as $p) { if ($p['price'] > 0) $by_day[$p['date']] = $p['price']; }
            $px = array_values($by_day);
            $n = count($px);
            $r = ['r5' => null, 'r21' => null];
            if ($n > 5 && $px[$n - 6] > 0)  $r['r5']  = round(($px[$n - 1] / $px[$n - 6] - 1) * 100, 2);
            if ($n > 21 && $px[$n - 22] > 0) $r['r21'] = round(($px[$n - 1] / $px[$n - 22] - 1) * 100, 2);
            $sector[(string)$e->code] = $r;
        }
    }

    // テーマETF：5日・21日の騰落率
    $theme = [];
    if (function_exists('wp_stocks_theme_reference_symbols') && function_exists('wp_stocks_index_period_returns')) {
        foreach (wp_stocks_theme_reference_symbols() as $sym => $label) {
            $pr = wp_stocks_index_period_returns($sym);
            $theme[$sym] = ['asof' => $pr['asof'], 'r5' => isset($pr['returns']['5日']) && $pr['returns']['5日'] !== null ? round($pr['returns']['5日'], 2) : null,
                            'r21' => isset($pr['returns']['1ヶ月']) && $pr['returns']['1ヶ月'] !== null ? round($pr['returns']['1ヶ月'], 2) : null];
        }
    }

    $row = [
        'topix_close' => $topix, 'topix_ma25' => $ma25, 'topix_ma75' => $ma75,
        'vix' => wp_stocks_jh_last_close('^VIX'), 'us10y' => wp_stocks_jh_last_close('^TNX'),
        'sector_rank_json' => $sector ? wp_json_encode($sector, JSON_UNESCAPED_UNICODE) : null,
        'theme_ret_json'   => $theme ? wp_json_encode($theme, JSON_UNESCAPED_UNICODE) : null,
    ];
    $m = wp_stocks_jh_market_table();
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$m} WHERE trade_date = %s", $date));
    if ($id) $wpdb->update($m, $row, ['id' => $id]);
    else $wpdb->insert($m, array_merge($row, ['trade_date' => $date, 'created_at' => $now]));
    return true;
}

// ============================================================
// 後から埋める：5・20・60営業日後の騰落率
// ============================================================

function wp_stocks_jh_fill_returns() {
    global $wpdb;
    if (!wp_stocks_jh_ensure_tables()) return 0;
    $t = wp_stocks_jh_table();
    $limit_date = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->modify('-7 days')->format('Y-m-d');
    $pending = $wpdb->get_results($wpdb->prepare(
        "SELECT id, stock_id, judgment_date, price, ret_5d, ret_20d, ret_60d FROM {$t}
         WHERE price > 0 AND judgment_date <= %s AND (ret_5d IS NULL OR ret_20d IS NULL OR ret_60d IS NULL)
         ORDER BY stock_id, judgment_date", $limit_date));
    if (!$pending) return 0;
    $by_stock = [];
    foreach ($pending as $p) $by_stock[$p->stock_id][] = $p;
    $filled = 0;
    foreach ($by_stock as $sid => $rows) {
        $min = $rows[0]->judgment_date;
        // その日の最後の価格を日ごとの終値として使う
        $pr = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(datetime) AS d, price FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d AND DATE(datetime) >= %s AND price > 0 ORDER BY datetime ASC", $sid, $min));
        $close = [];
        foreach ($pr as $x) $close[$x->d] = (float)$x->price;
        $days = array_keys($close);
        foreach ($rows as $r) {
            $idx = array_search($r->judgment_date, $days, true);
            if ($idx === false) continue;
            $upd = [];
            foreach (['ret_5d' => 5, 'ret_20d' => 20, 'ret_60d' => 60] as $col => $n) {
                if ($r->$col !== null) continue;
                if (isset($days[$idx + $n])) $upd[$col] = round(($close[$days[$idx + $n]] / (float)$r->price - 1) * 100, 2);
            }
            if ($upd) { $wpdb->update($t, $upd, ['id' => $r->id]); $filled++; }
        }
    }
    return $filled;
}

// ============================================================
// 毎日の実行（日本時間 23:30。日本株のテクニカル計算のあと）
// ============================================================

function wp_stocks_jh_cron_run() {
    wp_stocks_jh_record_market();
    wp_stocks_jh_record_all();
    wp_stocks_jh_fill_returns();
}
add_action('wp_stocks_judgment_cron', 'wp_stocks_jh_cron_run');

add_action('init', function () {
    if (wp_next_scheduled('wp_stocks_judgment_cron')) return;
    $next = new DateTime('today 23:30', new DateTimeZone('Asia/Tokyo'));
    if ($next->getTimestamp() <= time()) $next->modify('+1 day');
    wp_schedule_event($next->getTimestamp(), 'daily', 'wp_stocks_judgment_cron');
});

// ============================================================
// 表示：銘柄ページの「判定履歴」タブ
// ============================================================

function wp_stocks_jh_sparkline(array $vals, $w = 360, $h = 60) {
    $pts = [];
    foreach ($vals as $i => $v) if ($v !== null) $pts[$i] = (float)$v;
    if (count($pts) < 2) return '';
    $min = min($pts); $max = max($pts);
    if ($max == $min) { $max += 1; $min -= 1; }
    $n = count($vals) - 1;
    $path = '';
    foreach ($pts as $i => $v) {
        $x = $n > 0 ? $i / $n * ($w - 8) + 4 : 4;
        $y = $h - 4 - ($v - $min) / ($max - $min) * ($h - 8);
        $path .= ($path === '' ? 'M' : 'L') . round($x, 1) . ',' . round($y, 1) . ' ';
    }
    return '<svg width="' . (int)$w . '" height="' . (int)$h . '" viewBox="0 0 ' . (int)$w . ' ' . (int)$h . '" style="background:#fafafa;border:1px solid #eee;border-radius:4px;">'
        . '<path d="' . esc_attr(trim($path)) . '" fill="none" stroke="#0073aa" stroke-width="2"/></svg>'
        . '<div style="font-size:11px;color:#888;">最小 ' . esc_html(rtrim(rtrim(number_format($min, 1), '0'), '.')) . ' ／ 最大 ' . esc_html(rtrim(rtrim(number_format($max, 1), '0'), '.')) . '</div>';
}

function wp_stocks_jh_fmt($v, $dec = 0, $signed = false) {
    if ($v === null) return '<span style="color:#ccc;">-</span>';
    $s = number_format((float)$v, $dec);
    if ($signed && (float)$v > 0) $s = '+' . $s;
    return esc_html($s);
}

function wp_stocks_jh_render_tab($stock) {
    global $wpdb;
    $id = (int)$stock->id;
    $notice = '';
    if (!empty($_GET['jh_record']) && current_user_can('manage_options') && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wp_stocks_jh_record_' . $id)) {
        wp_stocks_jh_record_stock($stock);
        $notice = '<div class="notice notice-success inline"><p>今日の分を記録しました。</p></div>';
    }
    if (!wp_stocks_jh_ensure_tables()) {
        echo '<p style="color:#c0392b;">判定履歴のテーブルを作成できませんでした。</p>';
        return;
    }
    $t = wp_stocks_jh_table();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE stock_id = %d ORDER BY judgment_date DESC LIMIT 120", $id));

    echo $notice;
    $url = wp_nonce_url(add_query_arg(['ctab' => 'history', 'jh_record' => 1], admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id)), 'wp_stocks_jh_record_' . $id);
    echo '<p><a href="' . esc_url($url) . '" class="button">&#x1F4DD; 今日の分を今すぐ記録</a> '
        . '<span class="description">毎日 23:30（日本時間）に全銘柄を自動で記録します。同じ日は上書きされます。</span></p>';

    if (!$rows) {
        echo '<p style="color:#888;">まだ記録がありません。記録は今日から貯まります。</p>';
        return;
    }

    $asc = array_reverse($rows);
    echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin:12px 0;">';
    foreach (['tech_score' => 'テクニカルの総合点', 'fin_score' => '財務スコア', 'news_score' => 'ニュース加減点'] as $col => $label) {
        $sp = wp_stocks_jh_sparkline(array_map(function ($r) use ($col) { return $r->$col !== null ? (float)$r->$col : null; }, $asc));
        if ($sp === '') continue;
        echo '<div><div style="font-weight:bold;font-size:12px;margin-bottom:4px;">' . esc_html($label) . '</div>' . $sp . '</div>';
    }
    echo '</div>';

    $lab = ['strong_buy' => '強い買い', 'buy' => '買い優勢', 'hold' => 'HOLD', 'sell' => '売り優勢', 'strong_sell' => '強い売り'];
    echo '<div style="overflow-x:auto;"><table class="widefat striped" style="font-size:12px;min-width:760px;"><thead><tr>'
        . '<th>日付</th><th>テクニカル</th><th>財務</th><th>ニュース</th><th>業種</th><th style="text-align:right;">終値</th>'
        . '<th style="text-align:right;">5日後</th><th style="text-align:right;">20日後</th><th style="text-align:right;">60日後</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $tech = $r->tech_score !== null ? esc_html(($r->tech_score > 0 ? '+' : '') . $r->tech_score) . ($r->tech_label ? '（' . esc_html($lab[$r->tech_label] ?? $r->tech_label) . '）' : '') : '<span style="color:#ccc;">-</span>';
        $news = wp_stocks_jh_fmt($r->news_score, 1, true);
        if ($r->news_detail) {
            $det = json_decode($r->news_detail, true);
            if (is_array($det) && $det) {
                $lines = [];
                foreach ($det as $d) $lines[] = esc_html(($d['at'] ?? '') . ' ' . ($d['reason'] ?? '') . ' (' . (($d['eff'] ?? 0) > 0 ? '+' : '') . ($d['eff'] ?? 0) . ')');
                $news = '<details style="display:inline-block;"><summary style="cursor:pointer;">' . $news . '</summary><div style="font-size:11px;line-height:1.6;">' . implode('<br>', $lines) . '</div></details>';
            }
        }
        $ret = function ($v) {
            if ($v === null) return '<span style="color:#ccc;">-</span>';
            $c = $v >= 0 ? '#e74c3c' : '#3498db';
            return '<span style="color:' . $c . ';font-weight:bold;">' . ($v >= 0 ? '+' : '') . number_format((float)$v, 2) . '%</span>';
        };
        echo '<tr><td>' . esc_html($r->judgment_date) . '</td><td>' . $tech . '</td><td>' . wp_stocks_jh_fmt($r->fin_score) . '</td><td>' . $news . '</td>'
            . '<td>' . ($r->sector_code ? esc_html($r->sector_code) : '<span style="color:#ccc;">-</span>') . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->price, ($r->price !== null && $r->price < 1000) ? 2 : 0) . '</td>'
            . '<td style="text-align:right;">' . $ret($r->ret_5d) . '</td><td style="text-align:right;">' . $ret($r->ret_20d) . '</td><td style="text-align:right;">' . $ret($r->ret_60d) . '</td></tr>';
    }
    echo '</tbody></table></div>';

    // 判定の材料（生の値）
    echo '<h4 style="margin:18px 0 6px;">判定の材料（生の値）</h4>';
    echo '<div style="overflow-x:auto;"><table class="widefat striped" style="font-size:12px;min-width:860px;"><thead><tr>'
        . '<th>日付</th><th style="text-align:right;">純利益のコンセンサス比</th><th style="text-align:right;">営業利益のコンセンサス比</th><th>決算日</th>'
        . '<th style="text-align:right;">予想EPS 30日</th><th style="text-align:right;">予想EPS 90日</th><th style="text-align:right;">決算まで</th>'
        . '<th style="text-align:right;">52週の位置</th><th style="text-align:right;">高値から</th><th style="text-align:right;">OBV 20日</th><th style="text-align:right;">MFI</th><th style="text-align:right;">出来高比</th><th style="text-align:right;">ATR%</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . esc_html($r->judgment_date) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->sur_ni_cons ?? null, 1, true) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->sur_op_cons ?? null, 1, true) . '</td>'
            . '<td>' . (!empty($r->sur_date) ? esc_html($r->sur_date) : '<span style="color:#ccc;">-</span>') . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->eps_rev_30d ?? null, 1, true) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->eps_rev_90d ?? null, 1, true) . '</td>'
            . '<td style="text-align:right;">' . (($r->days_to_earn ?? null) !== null ? esc_html($r->days_to_earn) . '日' : '<span style="color:#ccc;">-</span>') . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->pos_52w ?? null, 0) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->dist_high_pct ?? null, 1, true) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->obv_chg20 ?? null, 2, true) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->mfi14 ?? null, 0) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->vol_ratio ?? null, 2) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->atr_pct ?? null, 2) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="description" style="margin-top:6px;">コンセンサス比は、最新の決算短信のQUICKコンセンサスとの差（%）です。52週の位置は、0が安値・100が高値です。OBV 20日は、直近20日の出来高に対する資金の流れ（-1〜+1）です。点数にはまだ反映していません。</p>';
    echo '<p class="description" style="margin-top:6px;">5・20・60日後の騰落率は、日が経ってから自動で入ります。最新120日分を表示しています。</p>';
}

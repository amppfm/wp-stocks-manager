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

const WP_STOCKS_JH_VERSION = '3';

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

// v3で追加した列（朝の再計算と、夜間の先物の動き）
//   judgment_history   : am_total_score / am_total_label = 朝（日本時間7:30頃）の総合判定。夕方の total_* とは別に残す
//   market_environment : fut_nky = 日経先物（大阪の日中終値 15:45 からの変化率%）、fut_sp / fut_nq = S&P500・ナスダック100先物（米国現物の終値 16:00 ET からの変化率%）
//                        am_regime = 朝時点の日本株の地合い。点数にはまだ反映しない（記録だけ）
function wp_stocks_jh_am_columns($which) {
    if ($which === 'market') {
        return ['am_regime' => 'VARCHAR(12) DEFAULT NULL', 'fut_nky' => 'FLOAT DEFAULT NULL', 'fut_sp' => 'FLOAT DEFAULT NULL', 'fut_nq' => 'FLOAT DEFAULT NULL', 'am_at' => 'DATETIME DEFAULT NULL'];
    }
    return ['am_total_score' => 'FLOAT DEFAULT NULL', 'am_total_label' => 'VARCHAR(20) DEFAULT NULL', 'am_at' => 'DATETIME DEFAULT NULL'];
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
    foreach (wp_stocks_jh_am_columns('stock') as $col => $def) {
        if (!in_array($col, $have, true)) $wpdb->query("ALTER TABLE {$t} ADD COLUMN {$col} {$def}");
    }
    $have_m = $wpdb->get_col("DESCRIBE {$m}", 0);
    foreach (wp_stocks_jh_am_columns('market') as $col => $def) {
        if (!in_array($col, $have_m, true)) $wpdb->query("ALTER TABLE {$m} ADD COLUMN {$col} {$def}");
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
    // 評価の履歴（予想EPSなど）を先に記録する（下の判定の材料「EPS修正」が、今日の分も含めて計算できるように）
    wp_stocks_jh_record_valuation($stock, $date);

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

// 評価の履歴（stock_valuation_history）：予想EPSの修正率（judgment-factors.php）の元データ。1日1行、同じ日は上書き。
// 予想EPS＝stocks.forward_eps（手入力 → J-Quants → Yahoo/TDnet の順で決まった、画面に出ている予想EPS）。
// 予想EPSが無い日は記録しない（0を残すと修正率が壊れるため）。
function wp_stocks_jh_record_valuation($stock, $date = null) {
    global $wpdb;
    $t = $wpdb->prefix . 'stock_valuation_history';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return false;
    $feps = (float)($stock->forward_eps ?? 0);
    if ($feps <= 0) return false;
    $date = $date ?: wp_stocks_jh_today();

    $price = $wpdb->get_var($wpdb->prepare("SELECT price FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $stock->id));
    $price = ($price !== null && (float)$price > 0) ? (float)$price : null;

    $actual = null;
    if (function_exists('wp_stocks_get_effective_eps_actual')) {
        $ea = wp_stocks_get_effective_eps_actual($stock);
        if ((float)($ea['value'] ?? 0) > 0) $actual = (float)$ea['value'];
    }

    // 予想EPSの出所：画面の予想EPS（forward_eps）が、どの元の値と一致するかで決める。
    //   順番は forward_eps の決め方（手入力 → 決算短信（TDnet）→ J-Quants当期 → J-Quants来期 → Yahoo）と同じ。
    //   値が1つも一致しないときは 'other'（不明）。米国株は 'yahoo'。
    $same = function ($b) use ($feps) { $b = (float)$b; return $b > 0 && abs($feps - $b) <= max(0.02, $b * 0.0005); };
    $tde_fc = (function_exists('wp_stocks_tde_forecast_eps') && ($stock->currency ?? 'JPY') !== 'USD') ? wp_stocks_tde_forecast_eps($stock->id) : null;
    if (($stock->currency ?? 'JPY') === 'USD')                                                   $src = 'yahoo';
    elseif ($same($stock->manual_forecast_eps ?? 0) || $same($stock->manual_next_fy_forecast_eps ?? 0)) $src = 'manual';
    elseif ($tde_fc && $same($tde_fc['eps']))                                                    $src = 'tdnet';
    elseif ($same($stock->jquants_forecast_eps ?? 0))                                            $src = 'jquants';
    elseif ($same($stock->jquants_next_fy_forecast_eps ?? 0))                                    $src = 'jquants_next';
    else                                                                                          $src = 'yahoo';

    $row = [
        'price'          => $price,
        'actual_eps'     => $actual,
        'forecast_eps'   => $feps,
        'bps'            => (($stock->manual_bps ?? null) !== null && (float)$stock->manual_bps > 0) ? (float)$stock->manual_bps : ((float)($stock->jquants_bps ?? 0) > 0 ? (float)$stock->jquants_bps : null),
        'actual_per'     => ($price !== null && $actual !== null) ? round($price / $actual, 2) : null,
        'forecast_per'   => $price !== null ? round($price / $feps, 2) : null,
        'pbr'            => (float)($stock->pbr ?? 0) > 0 ? (float)$stock->pbr : null,
        'dividend_yield' => (float)($stock->dividend_yield ?? 0) > 0 ? (float)$stock->dividend_yield : null,
        'eps_source'     => $src,
    ];
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE stock_id = %d AND record_date = %s", $stock->id, $date));
    if ($id) {
        $wpdb->update($t, $row, ['id' => $id]);
    } else {
        $wpdb->insert($t, array_merge($row, ['stock_id' => (int)$stock->id, 'record_date' => $date]));
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

function wp_stocks_jh_last_close($symbol, $max_age_days = null) {
    if (!function_exists('wp_stocks_index_history_get')) return null;
    $rows = wp_stocks_index_history_get($symbol, 10);
    if (!$rows) return null;
    // 最終日が古すぎるときは記録しない（古い値を今日の値として残さない）
    if ($max_age_days !== null && (time() - strtotime(end($rows)['trade_date'])) > $max_age_days * DAY_IN_SECONDS) return null;
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
        'vix' => wp_stocks_jh_last_close('^VIX', 7), 'us10y' => wp_stocks_jh_last_close('^TNX', 7),
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
// 夜間の先物の動き（朝の再計算で記録する。点数にはまだ反映しない）
// ============================================================

// 先物の「基準時刻の価格」から「いま」までの変化率（%）。15分足を使う。
//   $tz/$hm : 基準にする時刻（日経先物＝日本時間 15:45、米国先物＝ニューヨーク時間 16:00）
// 基準時刻に足が無い日（週末・休場）は、さかのぼって直近の営業日を使う。取れなければ null。
function wp_stocks_jh_futures_change($symbol, $tz, $hm) {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol) . '?interval=15m&range=5d';
    $res = wp_remote_get($url, ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'], 'timeout' => 15]);
    if (is_wp_error($res)) return null;
    $body = json_decode(wp_remote_retrieve_body($res), true);
    $r = $body['chart']['result'][0] ?? null;
    $ts = $r['timestamp'] ?? null; $cl = $r['indicators']['quote'][0]['close'] ?? null;
    if (!$ts || !$cl) return null;

    $now = time();
    $bars = [];
    foreach ($ts as $i => $t) {
        if (isset($cl[$i]) && (float)$cl[$i] > 0 && $t <= $now) $bars[] = [(int)$t, (float)$cl[$i]];
    }
    if (count($bars) < 2) return null;
    $last = end($bars);
    if ($now - $last[0] > 6 * 3600) return null;   // 6時間以上動いていない（休場中）なら、今の値としては使わない

    list($h, $mi) = array_map('intval', explode(':', $hm));
    $zone = new DateTimeZone($tz);
    for ($d = 0; $d <= 6; $d++) {
        $dt = new DateTime('@' . $now);
        $dt->setTimezone($zone);
        $dt->modify("-{$d} day")->setTime($h, $mi, 0);
        $cand = $dt->getTimestamp();
        if ($cand > $now) continue;
        $base = null;
        foreach ($bars as $b) { if ($b[0] <= $cand) $base = $b; else break; }
        if ($base !== null && $cand - $base[0] <= 3600) {
            return ['pct' => round(($last[1] / $base[1] - 1) * 100, 2), 'base' => $base[1], 'last' => $last[1], 'last_at' => $last[0]];
        }
    }
    return null;
}

// 朝の記録：夜間の先物の動きと、朝時点の地合いを、今日の地合いの行に書く（行が無ければ作る。夕方の記録は同じ行を更新する）
function wp_stocks_jh_record_overnight() {
    global $wpdb;
    if (!wp_stocks_jh_ensure_tables()) return false;
    $date = wp_stocks_jh_today();
    $now  = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $nky = wp_stocks_jh_futures_change('NIY=F', 'Asia/Tokyo', '15:45');
    $sp  = wp_stocks_jh_futures_change('ES=F', 'America/New_York', '16:00');
    $nq  = wp_stocks_jh_futures_change('NQ=F', 'America/New_York', '16:00');
    $regime = function_exists('wp_stocks_ms_regime') ? wp_stocks_ms_regime(false) : null;
    $row = [
        'am_regime' => $regime['level'] ?? null,
        'fut_nky' => $nky['pct'] ?? null, 'fut_sp' => $sp['pct'] ?? null, 'fut_nq' => $nq['pct'] ?? null,
        'am_at' => $now,
    ];
    $m = wp_stocks_jh_market_table();
    $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$m} WHERE trade_date = %s", $date));
    if ($id) $wpdb->update($m, $row, ['id' => $id]);
    else $wpdb->insert($m, array_merge($row, ['trade_date' => $date, 'created_at' => $now]));
    return $row;
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
    if (!empty($_GET['jh_am']) && current_user_can('manage_options') && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wp_stocks_jh_am_' . $id) && function_exists('wp_stocks_oj_morning_run')) {
        wp_stocks_oj_morning_run($stock);
        $notice .= '<div class="notice notice-success inline"><p>朝の再計算を実行しました（先物の動きも記録）。</p></div>';
    }
    if (!wp_stocks_jh_ensure_tables()) {
        echo '<p style="color:#c0392b;">判定履歴のテーブルを作成できませんでした。</p>';
        return;
    }
    $t = wp_stocks_jh_table();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE stock_id = %d ORDER BY judgment_date DESC LIMIT 120", $id));

    echo $notice;
    $url = wp_nonce_url(add_query_arg(['ctab' => 'history', 'jh_record' => 1], admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id)), 'wp_stocks_jh_record_' . $id);
    $url_am = wp_nonce_url(add_query_arg(['ctab' => 'history', 'jh_am' => 1], admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id)), 'wp_stocks_jh_am_' . $id);
    $am_time = get_option('wp_stocks_judgment_am_time', '07:30');
    echo '<p><a href="' . esc_url($url) . '" class="button">&#x1F4DD; 今日の分を今すぐ記録</a> '
        . '<a href="' . esc_url($url_am) . '" class="button">&#x1F305; 朝の再計算を今すぐ実行</a> '
        . '<span class="description">毎日 23:30（日本時間）に全銘柄を自動で記録します。同じ日は上書きされます。朝（' . esc_html($am_time) . '、平日のみ）にも総合判定を再計算し、「朝の判定」に別に残します。</span></p>';

    if (!$rows) {
        echo '<p style="color:#888;">まだ記録がありません。記録は今日から貯まります。</p>';
        return;
    }

    $asc = array_reverse($rows);
    echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin:12px 0;">';
    foreach (['total_score' => '総合判定の点', 'tech_score' => 'テクニカルの総合点', 'fin_score' => '財務スコア', 'news_score' => 'ニュース加減点'] as $col => $label) {
        $sp = wp_stocks_jh_sparkline(array_map(function ($r) use ($col) { return $r->$col !== null ? (float)$r->$col : null; }, $asc));
        if ($sp === '') continue;
        echo '<div><div style="font-weight:bold;font-size:12px;margin-bottom:4px;">' . esc_html($label) . '</div>' . $sp . '</div>';
    }
    echo '</div>';

    $lab = ['strong_buy' => '強い買い', 'buy' => '買い優勢', 'hold' => 'HOLD', 'sell' => '売り優勢', 'strong_sell' => '強い売り'];
    echo '<div style="overflow-x:auto;"><table class="widefat striped" style="font-size:12px;min-width:980px;"><thead><tr>'
        . '<th>日付</th><th style="text-align:right;">総合点</th><th>総合判定</th><th style="text-align:right;" title="朝の再計算（平日）の結果。夕方の総合点とは別に残る">朝の点</th><th>朝の判定</th><th>テクニカル</th><th>財務</th><th>ニュース</th><th>業種</th><th style="text-align:right;">終値</th>'
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
        // 総合判定（総合判定が記録された日だけ）。色は総合判定タブのラベルと同じ。版は文字の上にカーソルを置くと出る
        $tot = wp_stocks_jh_fmt($r->total_score ?? null, 0, true);
        $tl  = '<span style="color:#ccc;">-</span>';
        if (!empty($r->total_label)) {
            $tcol = '#555';
            if (function_exists('wp_stocks_oj_label_defs')) {
                foreach (wp_stocks_oj_label_defs() as $d) { if ($d[0] === $r->total_label) { $tcol = $d[1]; break; } }
            }
            $tl = '<strong style="color:' . esc_attr($tcol) . ';" title="' . esc_attr('版：' . (($r->weights_ver ?? '') !== '' ? $r->weights_ver : '不明')) . '">' . esc_html($r->total_label) . '</strong>'
                . ' <span style="color:#aaa;font-size:10px;">' . esc_html($r->weights_ver ?? '') . '</span>';
        }
        $am_tot = wp_stocks_jh_fmt($r->am_total_score ?? null, 0, true);
        $am_lab = !empty($r->am_total_label) ? '<strong>' . esc_html($r->am_total_label) . '</strong>' : '<span style="color:#ccc;">-</span>';
        echo '<tr><td>' . esc_html($r->judgment_date) . '</td><td style="text-align:right;">' . $tot . '</td><td>' . $tl . '</td><td style="text-align:right;">' . $am_tot . '</td><td>' . $am_lab . '</td><td>' . $tech . '</td><td>' . wp_stocks_jh_fmt($r->fin_score) . '</td><td>' . $news . '</td>'
            . '<td>' . ($r->sector_code ? esc_html($r->sector_code) : '<span style="color:#ccc;">-</span>') . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->price, ($r->price !== null && $r->price < 1000) ? 2 : 0) . '</td>'
            . '<td style="text-align:right;">' . $ret($r->ret_5d) . '</td><td style="text-align:right;">' . $ret($r->ret_20d) . '</td><td style="text-align:right;">' . $ret($r->ret_60d) . '</td></tr>';
    }
    echo '</tbody></table></div>';

    // 判定の材料（生の値）
    echo '<h4 style="margin:18px 0 6px;">判定の材料（生の値）</h4>';
    // 予想EPSの出所（その日の評価の記録 stock_valuation_history.eps_source）
    $eps_src_map = [];
    $vh_t = $GLOBALS['wpdb']->prefix . 'stock_valuation_history';
    if ($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s', $vh_t)) === $vh_t) {
        foreach ((array)$GLOBALS['wpdb']->get_results($GLOBALS['wpdb']->prepare("SELECT record_date, eps_source FROM {$vh_t} WHERE stock_id = %d ORDER BY record_date DESC LIMIT 400", $id)) as $vr) {
            $eps_src_map[$vr->record_date] = $vr->eps_source;
        }
    }
    $eps_src_label = ['manual' => '手入力', 'tdnet' => '決算短信（TDnet）', 'jquants' => 'J-Quants（当期）', 'jquants_next' => 'J-Quants（来期）', 'yahoo' => 'Yahoo', 'other' => '不明'];
    echo '<div style="overflow-x:auto;"><table class="widefat striped" style="font-size:12px;min-width:860px;"><thead><tr>'
        . '<th>日付</th><th style="text-align:right;">純利益のコンセンサス比</th><th style="text-align:right;">営業利益のコンセンサス比</th><th title="コンセンサス比の元になった決算の発表日">サプライズの決算日</th>'
        . '<th style="text-align:right;">予想EPS 30日</th><th style="text-align:right;">予想EPS 90日</th><th title="その日に記録した予想EPSの元。出所が変わった日をまたぐ修正率は、見かけだけの変化の可能性があります">予想EPSの出所</th><th style="text-align:right;">決算まで</th>'
        . '<th style="text-align:right;">52週の位置</th><th style="text-align:right;">高値から</th><th style="text-align:right;">OBV 20日</th><th style="text-align:right;">MFI</th><th style="text-align:right;">出来高比</th><th style="text-align:right;">ATR%</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . esc_html($r->judgment_date) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->sur_ni_cons ?? null, 1, true) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->sur_op_cons ?? null, 1, true) . '</td>'
            . '<td>' . (!empty($r->sur_date) ? esc_html($r->sur_date) : '<span style="color:#ccc;">-</span>') . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->eps_rev_30d ?? null, 1, true) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_jh_fmt($r->eps_rev_90d ?? null, 1, true) . '</td>'
            . '<td>' . (isset($eps_src_map[$r->judgment_date]) && $eps_src_map[$r->judgment_date] !== null && $eps_src_map[$r->judgment_date] !== '' ? esc_html($eps_src_label[$eps_src_map[$r->judgment_date]] ?? $eps_src_map[$r->judgment_date]) : '<span style="color:#ccc;">-</span>') . '</td>'
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
    echo '<p class="description" style="margin-top:6px;">5・20・60日後の騰落率は、日が経ってから自動で入ります。最新120日分を表示しています。総合点・総合判定は、毎日 23:30 の記録のあとに入ります（重みや閾値を変えたときは、横の小さな文字の版で区別できます）。</p>';
}

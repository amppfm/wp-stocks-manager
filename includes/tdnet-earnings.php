<?php
/**
 * 決算短信（TDnet）のニュースを財務データへ取り込む
 *
 * 立花ニュースの次の2種類の本文から、登録銘柄の決算内容を構造化して保存する。
 *   - <決算> の行の本文: 数値表（実績・前年同期・通期予想・財政状態・株式数）
 *   - <TDnet>AI: の行の本文: 業績データ・通期予想の「前期比／会社予想比／QUICKコンセンサス比」・配当
 *
 * 保存先
 *   - stock_quarterly_financials（source='tdnet'）: 実績・財政状態（J-Quantsと同じ「期首からの累計値」・円単位）
 *   - stock_tdnet_results: 通期予想、会社予想比、QUICKコンセンサス比、予想配当
 * 新しい開示が取り込まれたときだけ更新される（ニュース取得cronと再判定の後に実行）。
 * 財務タブでは、J-Quantsにまだ無い期（無料プランは12週間遅れ）だけ補う（wp_stocks_tde_merge_rows）。
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 解析（純粋関数）
// ============================================================

function wp_stocks_tde_norm($s) {
    $s = mb_convert_kana((string)$s, 'as', 'UTF-8');
    return str_replace(['−', '▲', '△'], '-', $s);
}

// 文字列の最初の数値（なければnull）
function wp_stocks_tde_first_num($s) {
    if (!preg_match('/(-?[0-9][0-9,]*(?:\.[0-9]+)?)/', (string)$s, $m)) return null;
    return (float)str_replace(',', '', $m[1]);
}

// <決算> の数値表を解析。対象外の本文ならnull
function wp_stocks_tde_parse_table($body) {
    $t = wp_stocks_tde_norm($body);
    if (strpos($t, '業績予想') === false || strpos($t, '期初日') === false) return null;
    $mult = 1000000; // 単位:百万円
    if (preg_match('/単位\s*[:：]\s*(百万円|千円|円)/u', $t, $u)) $mult = ['百万円' => 1000000, '千円' => 1000, '円' => 1][$u[1]];

    $lines = preg_split('/\r\n|\r|\n/', $t);
    $starts = $ends = [];
    $quarter = null;
    $rows = [];
    foreach ($lines as $ln) {
        if (strpos($ln, '<四半期予想>') !== false) break; // 以降は四半期予想の枠
        if (preg_match('/^\s*期初日/u', $ln) && preg_match_all('#(\d{4})/(\d{2})/(\d{2})#', $ln, $m, PREG_SET_ORDER)) { $starts = $m; continue; }
        if (preg_match('/期末日/u', $ln) && preg_match_all('#(\d{4})/(\d{2})/(\d{2})#', $ln, $m, PREG_SET_ORDER)) { $ends = $m; continue; }
        if ($quarter === null && preg_match('/([1-4])Q実績/u', $ln, $q)) $quarter = (int)$q[1];
        if ($quarter === null && preg_match('/通期実績|本決算/u', $ln)) $quarter = 4;
        if ($quarter === null && preg_match('/次期予想\s+前期比/u', $ln)) $quarter = 4; // 本決算の表（実績｜次期予想｜2Q予想）
        if (preg_match('/^\s*(売上高|営業利益|税引前利益|経常利益|純利益|一株利益\(円\)|総資産|純資産|自己資本比率|自己資本|一株純資産\(円\)|営業キャッシュフロー|投資キャッシュフロー|財務キャッシュフロー|現金、現金同等物残高)\s*\|(.*)$/u', $ln, $m)) {
            if (isset($rows[$m[1]])) continue; // 最初の行だけ（潜在株式調整後などの重複を避ける）
            $cols = explode('|', $m[2]);
            $rows[$m[1]] = $cols;
        }
    }
    if (empty($starts) || empty($ends) || $quarter === null) return null;
    $div_annual = null;
    if ($quarter === 4) {
        foreach ($lines as $ln) {
            if (strpos($ln, '<四半期予想>') !== false) break;
            if (preg_match('#^\s*(\d{2})/(\d{2})\(実績\)\s+([0-9.\s]+)$#u', $ln, $dm) && (int)$dm[1] === (int)substr($ends[0][1], 2) && (int)$dm[2] === (int)$ends[0][2]) {
                if (preg_match_all('/[0-9]+(?:\.[0-9]+)?/', $dm[3], $nums) && $nums[0]) $div_annual = (float)end($nums[0]);
            }
        }
    }
    $period_end = $ends[0][1] . '-' . $ends[0][2] . '-' . $ends[0][3];
    $fy_start   = $starts[0];
    $fiscal_year = (int)$fy_start[1];

    $val = function ($label, $col = 0, $scale = true) use ($rows, $mult) {
        if (!isset($rows[$label][$col])) return null;
        $v = wp_stocks_tde_first_num($rows[$label][$col]);
        if ($v === null) return null;
        return $scale ? (int)round($v * $mult) : $v;
    };
    $out = [
        'period_end'       => $period_end,
        'fiscal_year'      => $fiscal_year,
        'fiscal_quarter'   => $quarter,
        'revenue'          => $val('売上高'),
        'operating_profit' => $val('営業利益'),
        'ordinary_profit'  => isset($rows['経常利益']) ? $val('経常利益') : $val('税引前利益'),
        'net_income'       => $val('純利益'),
        'eps'              => $val('一株利益(円)', 0, false),
        'total_assets'     => $val('総資産'),
        'equity'           => $val('純資産'), // J-Quantsの Eq（純資産）に合わせる
        'equity_ratio'     => null,
        'bps'              => $val('一株純資産(円)', 0, false),
        'shares_outstanding' => null,
        'treasury_shares'    => null,
        'cf_operating'     => $val('営業キャッシュフロー'),
        'cf_investing'     => $val('投資キャッシュフロー'),
        'cf_financing'     => $val('財務キャッシュフロー'),
        'cash_equivalents' => $val('現金、現金同等物残高'),
        'dividend_annual'  => $div_annual,
        // 会社の通期予想EPS（四半期の表は3列目、本決算の表は2列目＝次期予想）
        'fc_eps'           => $val('一株利益(円)', $quarter === 4 ? 1 : 2, false),
    ];
    $er = $val('自己資本比率', 0, false);
    if ($er !== null) $out['equity_ratio'] = $er / 100.0; // J-Quantsと同じ小数（0.598）
    if (preg_match('/期末発行済株式数[^0-9]*([0-9,]+)\s*株/u', $t, $x)) $out['shares_outstanding'] = (int)str_replace(',', '', $x[1]);
    if (preg_match('/期末自己株式数[^0-9]*([0-9,]+)\s*株/u', $t, $x)) $out['treasury_shares'] = (int)str_replace(',', '', $x[1]);
    return $out;
}

// <TDnet>AI: の本文（業績データ・予想期）を解析。対象外ならnull
function wp_stocks_tde_parse_ai($body) {
    $t = wp_stocks_tde_norm($body);
    if (strpos($t, '<業績データ>') === false) return null;
    $out = ['disclosed_at' => null, 'doc_title' => '', 'score' => null, 'act_period' => null, 'type' => '', 'fy_end' => null,
            'fc' => [], 'div_act' => null, 'div_fc' => null];
    $section = ''; $metric = '';
    $labels = ['売上高' => 'revenue', '営業利益' => 'op', '経常利益' => 'ord', '税引前利益' => 'ord', '純利益' => 'ni'];
    foreach (preg_split('/\r\n|\r|\n/', $t) as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        if (preg_match('#開示日時\s*[:：]\s*(\d{4})/(\d{2})/(\d{2})\s+(\d{2}):(\d{2})#u', $ln, $m)) { $out['disclosed_at'] = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:00"; continue; }
        if (preg_match('/開示書類\s*[:：]\s*(.+)$/u', $ln, $m)) { $out['doc_title'] = mb_substr($m[1], 0, 190); continue; }
        if (preg_match('/<決算スコア>\s*([+\-]?[0-9.]+)/u', $ln, $m)) { $out['score'] = (float)$m[1]; continue; }
        if (strpos($ln, '<業績データ>') === 0) { $section = 'act'; continue; }
        if (preg_match('#^発表期\s+(\d{4})/(\d{2})#u', $ln, $m)) { $out['act_period'] = [(int)$m[1], (int)$m[2]]; continue; }
        if (preg_match('/^種別\s+(\S+)/u', $ln, $m)) { $out['type'] = $m[1]; continue; }
        if (preg_match('#^予想期.*?(\d{4})/(\d{2})#u', $ln, $m)) { $section = 'fc'; $out['fy_end'] = [(int)$m[1], (int)$m[2]]; continue; }
        if (preg_match('/^実績年間配当\(円\)\s+([0-9.]+)/u', $ln, $m)) { $out['div_act'] = (float)$m[1]; continue; }
        if (preg_match('/^予想年間配当\(円\)\s+([0-9.]+)/u', $ln, $m)) { $out['div_fc'] = (float)$m[1]; continue; }
        if ($section !== 'fc') continue;
        if (preg_match('/^(売上高|営業利益|経常利益|税引前利益|純利益)\(百万円\)\s+(-?[0-9,]+)/u', $ln, $m)) {
            $metric = $labels[$m[1]];
            $out['fc'][$metric]['amount'] = (int)round((float)str_replace(',', '', $m[2]) * 1000000);
            continue;
        }
        if ($metric !== '' && preg_match('/^(前期比|会社予想比|QUICKコンセンサス比)\s+([+\-]?[0-9.]+)%/u', $ln, $m)) {
            $key = ['前期比' => 'yoy', '会社予想比' => 'vs_co', 'QUICKコンセンサス比' => 'vs_cons'][$m[1]];
            $out['fc'][$metric][$key] = (float)$m[2];
        }
    }
    // 会計年度・四半期（種別と予想期から）
    $q = null;
    if (preg_match('/^([1-3])Q$/u', $out['type'], $m)) $q = (int)$m[1];
    elseif (preg_match('/本|通期|4Q|FY/u', $out['type'])) $q = 4;
    $out['fiscal_quarter'] = $q;
    // 会計年度＝その決算期の期首の年（J-Quantsと同じ）。12月決算は期末と同年、それ以外は期末の前年。
    // 予想期は、四半期なら同じ会計年度、本決算なら翌期になるため、本決算では発表期（実績の期末）から求める。
    $out['fiscal_year'] = null;
    $end = ($q === 4) ? $out['act_period'] : $out['fy_end'];
    if ($end) $out['fiscal_year'] = ($end[1] === 12) ? $end[0] : $end[0] - 1;
    if ($out['act_period']) {
        [$y, $mo] = $out['act_period'];
        $out['period_end'] = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $y, $mo)));
    } else {
        $out['period_end'] = null;
    }
    return $out;
}

// ============================================================
// 保存
// ============================================================

function wp_stocks_tde_results_table() {
    global $wpdb;
    return $wpdb->prefix . 'stock_tdnet_results';
}

function wp_stocks_tde_ensure_table() {
    global $wpdb;
    $ver = '3';
    if (get_option('wp_stocks_tde_db_version') === $ver) return true;
    $table = wp_stocks_tde_results_table();
    $charset = $wpdb->get_charset_collate();
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_id BIGINT UNSIGNED NOT NULL,
  fiscal_year SMALLINT NOT NULL,
  fiscal_quarter TINYINT NOT NULL,
  period_end DATE DEFAULT NULL,
  fy_end VARCHAR(7) DEFAULT NULL,
  disclosed_at DATETIME DEFAULT NULL,
  doc_title VARCHAR(200) NOT NULL DEFAULT '',
  score FLOAT DEFAULT NULL,
  fc_revenue BIGINT DEFAULT NULL, fc_revenue_yoy FLOAT DEFAULT NULL, fc_revenue_vs_co FLOAT DEFAULT NULL, fc_revenue_vs_cons FLOAT DEFAULT NULL,
  fc_op BIGINT DEFAULT NULL, fc_op_yoy FLOAT DEFAULT NULL, fc_op_vs_co FLOAT DEFAULT NULL, fc_op_vs_cons FLOAT DEFAULT NULL,
  fc_ord BIGINT DEFAULT NULL, fc_ord_yoy FLOAT DEFAULT NULL, fc_ord_vs_co FLOAT DEFAULT NULL, fc_ord_vs_cons FLOAT DEFAULT NULL,
  fc_ni BIGINT DEFAULT NULL, fc_ni_yoy FLOAT DEFAULT NULL, fc_ni_vs_co FLOAT DEFAULT NULL, fc_ni_vs_cons FLOAT DEFAULT NULL,
  div_actual FLOAT DEFAULT NULL,
  div_forecast FLOAT DEFAULT NULL,
  fc_eps FLOAT DEFAULT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_stock_period (stock_id, fiscal_year, fiscal_quarter)
) {$charset};");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
    // v3: 会社予想EPS列を追加（既存テーブルのみ）
    if (!$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'fc_eps'")) $wpdb->query("ALTER TABLE {$table} ADD COLUMN fc_eps FLOAT DEFAULT NULL");
    // v1は本決算の会計年度が1年ずれていたため、作り直す（派生データなので「再判定」で復元される）
    if (get_option('wp_stocks_tde_db_version') === '1') $wpdb->query("DELETE FROM {$table}");
    update_option('wp_stocks_tde_db_version', $ver);
    return true;
}

// 登録銘柄 code(4桁) => stock id
function wp_stocks_tde_stock_ids() {
    global $wpdb;
    $map = [];
    foreach ((array)$wpdb->get_results("SELECT id, code FROM {$wpdb->prefix}stocks") as $s) {
        $c = strtoupper(preg_replace('/\.T$/i', '', trim((string)$s->code)));
        if (preg_match('/^[0-9]{3}[0-9A-Z]$/', $c)) $map[$c] = (int)$s->id;
    }
    return $map;
}

// <決算>の数値表 → stock_quarterly_financials（source='tdnet'）
function wp_stocks_tde_save_table($stock_id, array $p) {
    global $wpdb;
    $t = $wpdb->prefix . 'stock_quarterly_financials';
    $data = [
        'stock_id' => $stock_id, 'source' => 'tdnet',
        'period_end' => $p['period_end'], 'fiscal_year' => $p['fiscal_year'], 'fiscal_quarter' => $p['fiscal_quarter'],
    ];
    foreach (['revenue', 'operating_profit', 'ordinary_profit', 'net_income', 'eps', 'total_assets', 'equity', 'equity_ratio', 'shares_outstanding', 'treasury_shares',
              'cf_operating', 'cf_investing', 'cf_financing', 'cash_equivalents', 'dividend_annual'] as $k) {
        if (isset($p[$k]) && $p[$k] !== null) $data[$k] = $p[$k];
    }
    if ($p['revenue'] === null && $p['net_income'] === null) return false;
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE stock_id = %d AND period_end = %s AND source = 'tdnet'", $stock_id, $p['period_end']));
    if ($existing) $wpdb->update($t, $data, ['id' => $existing]);
    else $wpdb->insert($t, $data);
    if (isset($p['fc_eps']) && $p['fc_eps'] > 0 && wp_stocks_tde_ensure_table()) {
        $rt = wp_stocks_tde_results_table();
        $rid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$rt} WHERE stock_id = %d AND fiscal_year = %d AND fiscal_quarter = %d", $stock_id, $p['fiscal_year'], $p['fiscal_quarter']));
        if ($rid) $wpdb->update($rt, ['fc_eps' => $p['fc_eps']], ['id' => $rid]);
        else $wpdb->insert($rt, ['stock_id' => $stock_id, 'fiscal_year' => $p['fiscal_year'], 'fiscal_quarter' => $p['fiscal_quarter'],
            'period_end' => $p['period_end'], 'fc_eps' => $p['fc_eps'], 'updated_at' => wp_stocks_nws_now()]);
    }
    return true;
}

// <TDnet>AIの予想・比較 → stock_tdnet_results
function wp_stocks_tde_save_ai($stock_id, array $a) {
    global $wpdb;
    if (!$a['fiscal_year'] || !$a['fiscal_quarter'] || !wp_stocks_tde_ensure_table()) return false;
    $t = wp_stocks_tde_results_table();
    $row = [
        'stock_id' => $stock_id, 'fiscal_year' => $a['fiscal_year'], 'fiscal_quarter' => $a['fiscal_quarter'],
        'period_end' => $a['period_end'], 'disclosed_at' => $a['disclosed_at'], 'doc_title' => $a['doc_title'],
        'score' => $a['score'], 'div_actual' => $a['div_act'], 'div_forecast' => $a['div_fc'],
        'fy_end' => $a['fy_end'] ? sprintf('%04d-%02d', $a['fy_end'][0], $a['fy_end'][1]) : null,
        'updated_at' => wp_stocks_nws_now(),
    ];
    foreach (['revenue', 'op', 'ord', 'ni'] as $m) {
        $f = $a['fc'][$m] ?? [];
        $row["fc_{$m}"] = $f['amount'] ?? null;
        foreach (['yoy', 'vs_co', 'vs_cons'] as $k) $row["fc_{$m}_{$k}"] = $f[$k] ?? null;
    }
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id, disclosed_at FROM {$t} WHERE stock_id = %d AND fiscal_year = %d AND fiscal_quarter = %d",
        $stock_id, $a['fiscal_year'], $a['fiscal_quarter']));
    if ($existing) {
        // 古い開示で新しい内容を上書きしない
        if ($existing->disclosed_at && $a['disclosed_at'] && $a['disclosed_at'] < $existing->disclosed_at) return false;
        $wpdb->update($t, $row, ['id' => $existing->id]);
    } else {
        $wpdb->insert($t, $row);
    }
    return true;
}

// ニュース（保存済み）から取り込む。$full=trueは保存済みの全ニュース、そうでなければ前回以降に取得した分
function wp_stocks_tde_ingest($full = false) {
    global $wpdb;
    $news_t = $wpdb->prefix . 'stock_tachibana_news';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $news_t)) !== $news_t) return 0;
    $ids = wp_stocks_tde_stock_ids();
    if (!$ids) return 0;
    wp_stocks_tde_ensure_table();

    $started = wp_stocks_nws_now();
    $last = $full ? '' : (string)get_option('wp_stocks_tde_last_ingest', '');
    // prepare()の%と衝突しないよう、LIKEではなくLOCATEで先頭一致を見る
    $where = "(LOCATE('<決算>', headline) = 1 OR LOCATE('<TDnet>AI:', headline) = 1)";
    if ($last !== '') {
        $since = (new DateTime($last, new DateTimeZone('Asia/Tokyo')))->modify('-1 hour')->format('Y-m-d H:i:s');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT news_id, news_at, issues, headline, body FROM {$news_t} WHERE {$where} AND fetched_at >= %s ORDER BY news_at ASC", $since));
    } else {
        $rows = $wpdb->get_results("SELECT news_id, news_at, issues, headline, body FROM {$news_t} WHERE {$where} ORDER BY news_at ASC");
    }
    $n = 0;
    foreach ((array)$rows as $r) {
        $codes = wp_stocks_nws_news_codes($r->headline, $r->issues);
        if (count($codes) !== 1 || !isset($ids[$codes[0]])) continue;
        $stock_id = $ids[$codes[0]];
        $p = wp_stocks_tde_parse_table($r->body);
        if ($p && wp_stocks_tde_save_table($stock_id, $p)) $n++;
        $a = wp_stocks_tde_parse_ai($r->body);
        if ($a && wp_stocks_tde_save_ai($stock_id, $a)) $n++;
    }
    update_option('wp_stocks_tde_last_ingest', $started, false);
    return $n;
}

// ============================================================
// 表示側（財務タブ）
// ============================================================

// jquants行とtdnet行を、同じ（会計年度, 四半期）ではjquantsを優先して統合する。
// $rows: stock_quarterly_financialsの行（source='jquants'または'tdnet'）。並びは元の順序を保つ。
function wp_stocks_tde_merge_rows(array $rows) {
    $have = [];
    foreach ($rows as $r) {
        if ($r->source === 'jquants') $have[$r->fiscal_year . '-' . $r->fiscal_quarter] = true;
    }
    $out = [];
    foreach ($rows as $r) {
        if ($r->source === 'tdnet' && isset($have[$r->fiscal_year . '-' . $r->fiscal_quarter])) continue;
        $out[] = $r;
    }
    return $out;
}

// たちばな由来（TDnet）の行を示す小さなタグ
function wp_stocks_tde_badge() {
    return '<span title="立花証券ニュースの決算短信から補った値です（J-Quantsに反映されると自動でJ-Quantsの値に切り替わります）" style="display:inline-block;margin-left:6px;padding:0 6px;border-radius:8px;background:#d35400;color:#fff;font-size:10px;font-weight:bold;vertical-align:middle;">たちばな</span>';
}

// 財務タブ用: たちばな由来の期の一覧（なければ空文字）。$quarterly=trueなら四半期の名前で表示
function wp_stocks_tde_source_note(array $rows, $quarterly = false) {
    $names = [];
    $qn = [1 => '1Q', 2 => '2Q累計', 3 => '3Q累計', 4 => '通期'];
    foreach ($rows as $r) {
        if (($r->source ?? '') !== 'tdnet') continue;
        $names[] = $r->fiscal_year . '年度' . ($quarterly ? ' ' . ($qn[(int)$r->fiscal_quarter] ?? '') : '');
    }
    if (!$names) return '';
    return '<p style="margin:6px 0 12px;font-size:12px;color:#555;">' . wp_stocks_tde_badge()
        . ' 立花証券の決算短信から補った期: ' . esc_html(implode('、', $names))
        . '（J-Quantsに反映されると、自動でJ-Quantsの値に切り替わります）</p>';
}

function wp_stocks_tde_fmt_pct($v) {
    if ($v === null) return '<span style="color:#aaa;">-</span>';
    $v = (float)$v;
    $c = $v > 0 ? '#27ae60' : ($v < 0 ? '#c0392b' : '#666');
    return '<span style="color:' . $c . ';font-weight:bold;">' . ($v > 0 ? '+' : '') . number_format($v, 1) . '%</span>';
}

// 会社予想EPSと、QUICKコンセンサス比から逆算した概算コンセンサスEPS（最新の開示）。なければnull
function wp_stocks_tde_forecast_eps($stock_id) {
    global $wpdb;
    if (!wp_stocks_tde_ensure_table()) return null;
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT fc_eps, fc_ni_vs_cons, fy_end FROM " . wp_stocks_tde_results_table() . " WHERE stock_id = %d AND fc_eps > 0 ORDER BY COALESCE(disclosed_at, period_end) DESC LIMIT 1", $stock_id));
    if (!$r) return null;
    $cons = null;
    if ($r->fc_ni_vs_cons !== null && (float)$r->fc_ni_vs_cons > -90) $cons = (float)$r->fc_eps / (1 + (float)$r->fc_ni_vs_cons / 100);
    return ['eps' => (float)$r->fc_eps, 'cons' => $cons, 'fy_end' => $r->fy_end];
}

// 直近の「業績予想の修正」ニュース（ニュース履歴から。決算短信の表が無い修正のお知らせも拾う）
function wp_stocks_tde_render_revisions($stock_id, $standalone = false) {
    global $wpdb;
    if (!function_exists('wp_stocks_nws_history_ensure_table') || !wp_stocks_nws_history_ensure_table()) return;
    $code = strtoupper(preg_replace('/\.T$/i', '', trim((string)$wpdb->get_var($wpdb->prepare("SELECT code FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id)))));
    if ($code === '') return;
    $since = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->modify('-180 days')->format('Y-m-d H:i:s');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT news_at, headline, body FROM " . wp_stocks_nws_history_table() . " WHERE code = %s AND news_at >= %s AND LOCATE('修正', headline) > 0 AND LOCATE('訂正', headline) = 0 ORDER BY news_at DESC LIMIT 8",
        $code, $since));
    if (!$rows) return;
    if ($standalone) echo '<div style="background:#fff;border:1px solid #ddd;border-left:4px solid #0073aa;border-radius:6px;padding:12px 16px;margin-bottom:18px;max-width:900px;">';
    echo '<div style="margin-top:' . ($standalone ? '0' : '10px') . ';font-size:12px;"><strong>業績予想の修正（直近180日）</strong>';
    foreach ($rows as $r) {
        $body = (string)$r->body;
        if ($r->headline !== '' && strpos($body, $r->headline) === 0) $body = ltrim(substr($body, strlen($r->headline)), "\r\n");
        echo '<details style="border-bottom:1px solid #f0f0f0;padding:3px 0;"><summary style="cursor:pointer;"><span style="color:#888;margin-right:8px;">' . esc_html(substr($r->news_at, 0, 16)) . '</span>' . esc_html($r->headline) . '</summary>'
            . '<div style="padding:6px 0 2px;line-height:1.7;">' . ($body !== '' ? nl2br(esc_html($body)) : '<span style="color:#aaa;">（本文なし）</span>') . '</div></details>';
    }
    echo '</div>';
    if ($standalone) echo '</div>';
}

// 財務タブ上部: 最新の決算短信（TDnet）の通期予想と比較
function wp_stocks_tde_render_panel($stock_id) {
    global $wpdb;
    if (!wp_stocks_tde_ensure_table()) return;
    $r = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . wp_stocks_tde_results_table() . " WHERE stock_id = %d AND doc_title <> '' ORDER BY COALESCE(disclosed_at, period_end) DESC LIMIT 1", $stock_id));
    if (!$r) { wp_stocks_tde_render_revisions($stock_id, true); return; }
    $labels = ['revenue' => '売上高', 'op' => '営業利益', 'ord' => '経常利益', 'ni' => '純利益'];
    echo '<div style="background:#fff;border:1px solid #ddd;border-left:4px solid #0073aa;border-radius:6px;padding:12px 16px;margin-bottom:18px;max-width:900px;">';
    echo '<h3 style="margin:0 0 6px;font-size:14px;">&#x1F4C4; 最新の決算短信（TDnet）' . wp_stocks_tde_badge() . ($r->score !== null ? ' 決算スコア ' . esc_html(($r->score > 0 ? '+' : '') . $r->score) : '') . '</h3>';
    echo '<p class="description" style="margin:0 0 8px;">' . esc_html($r->doc_title) . ($r->disclosed_at ? '（' . esc_html(substr($r->disclosed_at, 0, 16)) . ' 開示）' : '') . '</p>';
    echo '<table class="widefat striped" style="font-size:12px;"><thead><tr><th>通期予想' . ($r->fy_end ? '（' . esc_html($r->fy_end) . '期）' : '') . '</th><th style="text-align:right;">金額(百万円)</th><th style="text-align:right;">前期比</th><th style="text-align:right;">会社予想比</th><th style="text-align:right;">QUICKコンセンサス比</th></tr></thead><tbody>';
    foreach ($labels as $k => $l) {
        $amt = $r->{"fc_{$k}"};
        if ($amt === null) continue;
        echo '<tr><td>' . esc_html($l) . '</td><td style="text-align:right;">' . esc_html(number_format($amt / 1000000)) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_tde_fmt_pct($r->{"fc_{$k}_yoy"}) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_tde_fmt_pct($r->{"fc_{$k}_vs_co"}) . '</td>'
            . '<td style="text-align:right;">' . wp_stocks_tde_fmt_pct($r->{"fc_{$k}_vs_cons"}) . '</td></tr>';
    }
    echo '</tbody></table>';
    $fe = wp_stocks_tde_forecast_eps($stock_id);
    if ($fe) {
        echo '<p style="margin:8px 0 0;font-size:12px;">予想EPS: 会社予想 <strong>' . esc_html(number_format($fe['eps'], 2)) . '円</strong>'
            . ($fe['cons'] !== null ? ' ／ 概算コンセンサス 約<strong>' . esc_html(number_format($fe['cons'], 2)) . '円</strong>（QUICKコンセンサス比の純利益から逆算）' : '') . '</p>';
    }
    if ($r->div_actual !== null || $r->div_forecast !== null) {
        echo '<p style="margin:8px 0 0;font-size:12px;">配当（年間）: 実績 ' . ($r->div_actual !== null ? esc_html(number_format($r->div_actual, 2)) . '円' : '-')
            . ' ／ 予想 ' . ($r->div_forecast !== null ? esc_html(number_format($r->div_forecast, 2)) . '円' : '-') . '</p>';
    }
    wp_stocks_tde_render_revisions($stock_id);
    echo '<p class="description" style="margin:6px 0 0;">立花ニュースの決算短信から自動で取り込んだ値です。四半期データは、J-Quantsにまだ無い期だけ補います。</p>';
    echo '</div>';
}

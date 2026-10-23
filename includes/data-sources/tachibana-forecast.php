<?php
/**
 * 立花証券 e支店 API の予想EPS（CLMMfdsGetIssueDetail の pEPSF）を、日本株の「予想EPS」の主ソースとして扱う。
 *
 * 優先順位：手動入力 → 立花 pEPSF → J-Quants(FEPS／なければNxFEPS) → 決算短信(TDnet) → Yahoo
 *   立花の値が無い・取得に失敗した銘柄は、従来どおりJ-Quants等の値のまま（フォールバック）。
 * 立花の値は wp_options 'wp_stocks_tc_forecast_eps' にキャッシュし、毎日1回更新する（約350銘柄・約7回の問合せ）。
 * 更新時に、手動入力が無い銘柄の stocks.forward_eps も立花の値に更新する（予想PER・PEG・適正株価(予想)は表示時に forward_eps から算出される）。
 */
if (!defined('ABSPATH')) exit;

const WP_STOCKS_TCF_OPTION = 'wp_stocks_tc_forecast_eps';

/** キャッシュ全体を返す: ['updated' => unix秒, 'map' => [正規化コード => 予想EPS]] */
function wp_stocks_tcf_cache() {
    $c = get_option(WP_STOCKS_TCF_OPTION, []);
    if (!is_array($c) || !isset($c['map']) || !is_array($c['map'])) return ['updated' => 0, 'map' => [], 'yield' => []];
    if (!isset($c['yield']) || !is_array($c['yield'])) $c['yield'] = [];
    return $c;
}

/**
 * 全日本株の立花予想EPSを取得してキャッシュし、forward_eps を更新する。
 * 戻り値: ['total' => 対象数, 'got' => 立花に値あり, 'none' => 立花に値なし, 'failed_chunks' => 失敗した問合せ数, 'updated_db' => forward_eps更新数]
 */
function wp_stocks_tcf_refresh() {
    global $wpdb;
    if (!function_exists('wp_stocks_tachibana_request')) return false;
    $t = $wpdb->prefix . 'stocks';
    $rows = $wpdb->get_results("SELECT id, code, forward_eps, manual_forecast_eps, manual_forecast_dividend_annual, dividend_yield FROM {$t} WHERE (currency = 'JPY' OR currency IS NULL OR currency = '')");
    if (!$rows) return false;

    $old   = wp_stocks_tcf_cache();
    $map   = $old['map'];
    $ymap  = $old['yield'];
    $codes = [];
    foreach ($rows as $r) {
        $n = wp_stocks_tachibana_normalize_code($r->code);
        if ($n) $codes[$n] = true;
    }
    $res = ['total' => count($codes), 'got' => 0, 'none' => 0, 'failed_chunks' => 0, 'updated_db' => 0];
    $fresh = [];
    $fresh_y = [];
    foreach (array_chunk(array_keys($codes), 50) as $chunk) {
        $data = wp_stocks_tachibana_request('master', ['sCLMID' => 'CLMMfdsGetIssueDetail', 'sTargetIssueCode' => implode(',', $chunk)]);
        if (is_wp_error($data)) {
            $res['failed_chunks']++;
            wp_stocks_log('error', 'tachibana_eps', '', '立花の予想EPS取得に失敗（J-Quants等の値のまま）: ' . $data->get_error_message());
            continue;
        }
        $seen = [];
        foreach (wp_stocks_tachibana_find_rows($data, 'sIssueCode') as $r) {
            $code = (string)($r['sIssueCode'] ?? '');
            $v    = $r['pEPSF'] ?? '';
            $seen[$code] = true;
            $fresh[$code] = ($v === '' || $v === null) ? 0.0 : (float)$v;
            $y = $r['pSYIE'] ?? '';
            $fresh_y[$code] = ($y === '' || $y === null) ? 0.0 : (float)$y;
        }
        foreach ($chunk as $c) { if (!isset($seen[$c])) $fresh[$c] = 0.0; } // 応答に無い銘柄は「値なし」
    }
    // 取得できた銘柄だけ上書き（失敗した問合せ分は前回値を残す）
    foreach ($fresh as $c => $v) {
        if ($v > 0) { $map[$c] = $v; $res['got']++; }
        else        { unset($map[$c]); $res['none']++; }
    }
    foreach ($fresh_y as $c => $y) { if ($y > 0) $ymap[$c] = $y; else unset($ymap[$c]); }
    // 失敗した問合せがあるときは更新日時を進めない（次回の参照で再試行できるように）
    update_option(WP_STOCKS_TCF_OPTION, ['updated' => $res['failed_chunks'] ? (int)$old['updated'] : time(), 'map' => $map, 'yield' => $ymap], false);

    // 手動入力が無い銘柄の forward_eps を立花の値に更新
    foreach ($rows as $r) {
        if (!empty($r->manual_forecast_eps) && (float)$r->manual_forecast_eps > 0) continue;
        $v = $map[wp_stocks_tachibana_normalize_code($r->code)] ?? null;
        if ($v !== null && $v > 0 && abs((float)$r->forward_eps - $v) > 0.0001) {
            $wpdb->update($t, ['forward_eps' => $v], ['id' => (int)$r->id]);
            $res['updated_db']++;
        }
    }
    // 配当利回り：立花の予想配当利回り(pSYIE、分割調整済み)を主にする。手動の予想年間配当があれば手動を優先（そのまま）。
    // J-Quantsの配当は分割で調整されず、分割後の株価で割ると利回りが極端に大きくなることがあるため。
    foreach ($rows as $r) {
        if (!empty($r->manual_forecast_dividend_annual) && (float)$r->manual_forecast_dividend_annual > 0) continue;
        $y = $ymap[wp_stocks_tachibana_normalize_code($r->code)] ?? null;
        if ($y !== null && $y > 0 && abs((float)$r->dividend_yield - $y) > 0.0001) {
            $wpdb->update($t, ['dividend_yield' => $y], ['id' => (int)$r->id]);
        }
    }
    wp_stocks_log('info', 'tachibana_eps', '', sprintf('立花の予想EPSを更新: 対象%d 取得%d なし%d 失敗%d 反映%d', $res['total'], $res['got'], $res['none'], $res['failed_chunks'], $res['updated_db']));
    return $res;
}

/** 銘柄コードの立花予想EPS。無ければ null。キャッシュが古い(36時間超)ときは更新を試みる（失敗時は30分間再試行しない）。 */
function wp_stocks_tcf_get($code) {
    $c = wp_stocks_tcf_cache();
    if (time() - (int)$c['updated'] > 36 * 3600 && !get_transient('wp_stocks_tcf_fail')) {
        $r = wp_stocks_tcf_refresh();
        if ($r === false || !empty($r['failed_chunks'])) set_transient('wp_stocks_tcf_fail', 1, 1800);
        $c = wp_stocks_tcf_cache();
    }
    $v = $c['map'][wp_stocks_tachibana_normalize_code($code)] ?? null;
    return ($v !== null && $v > 0) ? (float)$v : null;
}

/** 立花の予想配当利回り(%)。無ければ null。 */
function wp_stocks_tcf_get_yield($code) {
    $c = wp_stocks_tcf_cache();
    $v = $c['yield'][wp_stocks_tachibana_normalize_code($code)] ?? null;
    return ($v !== null && $v > 0) ? (float)$v : null;
}

/**
 * 画面表示用：予想EPSの出所とJ-Quants参考値。
 * 戻り値: ['value' => 画面の予想EPS, 'source' => 'manual'|'tachibana'|'other', 'tachibana' => 立花値|null,
 *          'jquants' => J-Quants値|null, 'diff_pct' => 立花とJ-Quantsの差(%)|null, 'differs' => 差が5%以上か]
 */
function wp_stocks_get_forecast_eps_info($stock) {
    $value = (float)($stock->forward_eps ?? 0);
    $tc    = (($stock->currency ?? 'JPY') === 'USD') ? null : wp_stocks_tcf_get($stock->code ?? '');
    $jq    = (float)($stock->jquants_forecast_eps ?? 0);
    if ($jq <= 0) $jq = (float)($stock->jquants_next_fy_forecast_eps ?? 0);
    $jq = $jq > 0 ? $jq : null;
    $manual = (float)($stock->manual_forecast_eps ?? 0);
    $source = 'other';
    if ($manual > 0) $source = 'manual';
    elseif ($tc !== null && $value > 0 && abs($value - $tc) / $tc < 0.001) $source = 'tachibana';
    $diff = ($tc !== null && $jq !== null) ? ($tc - $jq) / $jq * 100 : null;
    return ['value' => $value, 'source' => $source, 'tachibana' => $tc, 'jquants' => $jq,
            'diff_pct' => $diff, 'differs' => ($diff !== null && abs($diff) >= 5)];
}

// 毎日1回、立花の予想EPSを更新（WP-Cron）
add_action('init', function () {
    if (!wp_next_scheduled('wp_stocks_tcf_daily')) wp_schedule_event(time() + 600, 'daily', 'wp_stocks_tcf_daily');
});
add_action('wp_stocks_tcf_daily', 'wp_stocks_tcf_refresh');

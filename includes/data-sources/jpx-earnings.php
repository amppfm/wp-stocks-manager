<?php
// JPX（東証）「決算発表予定日」のExcelから、決算予定日（stocks.earnings_date）を取得する。
//   - J-Quants無料プランでは先の予定日が取れないため、東証が公開している一覧で補う
//   - Excelは月ごとのファイル（xlsx）。ZipArchiveが無い環境でも読めるよう、zipは自前で展開する
//   - 更新するのは「未取得」か「日付が過ぎた」銘柄だけ（これから先の日付・手入力は上書きしない）
//   - 先の予定日が一覧に無くても、発表済みの日付が一覧にあれば、前回決算として登録する（「未取得」のままにしない）。
//     次の予定日が一覧に載れば、翌日の取り込みで自動的にそちらへ置き換わる
//   - 毎日 6:00（日本時間）に実行。J-Quantsの同期（6:10）より先に走らせ、J-Quantsの問い合わせを減らす
if (!defined('ABSPATH')) exit;

const WP_STOCKS_JPX_BASE = 'https://www.jpx.co.jp';
const WP_STOCKS_JPX_INDEX = '/listing/event-schedules/financial-announcement/index.html';

// --------------------------------------------------
// zip（xlsx）を標準関数だけで読む
// --------------------------------------------------
function wp_stocks_jpx_zip_entries($bin) {
    $eocd = strrpos($bin, "PK\x05\x06");
    if ($eocd === false) return [];
    $h = unpack('vd/vcd/ven/vtotal/Vsize/Voff/vcl', substr($bin, $eocd + 4, 18));
    $out = [];
    $p = $h['off'];
    for ($i = 0; $i < $h['total']; $i++) {
        if (substr($bin, $p, 4) !== "PK\x01\x02") break;
        $f = 'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize';
        $f .= '/vnlen/velen/vclen/vdisk/vattr/Vext/Voffset';
        $e = unpack($f, substr($bin, $p + 4, 42));
        $out[substr($bin, $p + 46, $e['nlen'])] = $e;
        $p += 46 + $e['nlen'] + $e['elen'] + $e['clen'];
    }
    return $out;
}

function wp_stocks_jpx_zip_read($bin, $e) {
    $o = $e['offset'];
    $l = unpack('vnlen/velen', substr($bin, $o + 26, 4));
    $start = $o + 30 + $l['nlen'] + $l['elen'];
    $data = substr($bin, $start, $e['csize']);
    if ($e['method'] === 0) return $data;
    if ($e['method'] === 8) return @gzinflate($data);
    return false;
}

function wp_stocks_jpx_col_index($ref) {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) return 0;
    $n = 0;
    foreach (str_split($m[1]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

// xlsxの最初のシートを、行ごとの配列（列番号 => 値）にして返す。読めなければfalse
function wp_stocks_jpx_xlsx_rows($bin) {
    if (substr($bin, 0, 2) !== 'PK') return false;
    $entries = wp_stocks_jpx_zip_entries($bin);
    if (!$entries) return false;

    $prev = libxml_use_internal_errors(true);
    $rows = wp_stocks_jpx_xlsx_rows_inner($bin, $entries);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $rows;
}

function wp_stocks_jpx_xlsx_rows_inner($bin, $entries) {
    $ss = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        $raw = wp_stocks_jpx_zip_read($bin, $entries['xl/sharedStrings.xml']);
        $x = $raw === false ? false : simplexml_load_string($raw);
        if ($x) {
            foreach ($x->si as $si) {
                if (isset($si->t)) { $ss[] = (string) $si->t; continue; }
                $t = '';
                foreach ($si->r as $r) $t .= (string) $r->t;
                $ss[] = $t;
            }
        }
    }

    $name = isset($entries['xl/worksheets/sheet1.xml']) ? 'xl/worksheets/sheet1.xml' : '';
    if ($name === '') {
        foreach (array_keys($entries) as $n) {
            if (strpos($n, 'xl/worksheets/sheet') === 0) { $name = $n; break; }
        }
    }
    if ($name === '') return false;
    $raw = wp_stocks_jpx_zip_read($bin, $entries[$name]);
    $sheet = $raw === false ? false : simplexml_load_string($raw);
    if (!$sheet || !isset($sheet->sheetData)) return false;

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $v = (string) $c->v;
            $t = (string) $c['t'];
            if ($t === 's') $v = $ss[(int) $v] ?? '';
            elseif ($t === 'inlineStr') $v = (string) $c->is->t;
            if ($v !== '') $cells[wp_stocks_jpx_col_index((string) $c['r'])] = $v;
        }
        $rows[] = $cells;
    }
    return $rows;
}

// 一覧の行から「銘柄コード => [予定日...]」を作る（A列=予定日、B列=コード）
function wp_stocks_jpx_parse_rows(array $rows) {
    $out = [];
    foreach ($rows as $cells) {
        $d    = (string) ($cells[0] ?? '');
        $code = strtoupper(trim((string) ($cells[1] ?? '')));
        if ($code === '' || $d === '') continue;
        if (is_numeric($d) && $d > 40000 && $d < 60000) {
            $date = gmdate('Y-m-d', (int) (($d - 25569) * 86400));   // Excelのシリアル値
        } elseif (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})#', $d, $m)) {
            $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        } else {
            continue;   // 見出し行など
        }
        $out[$code][] = $date;
    }
    return $out;
}

// --------------------------------------------------
// 取得
// --------------------------------------------------
function wp_stocks_jpx_http_get($url) {
    $res = wp_remote_get($url, ['timeout' => 60, 'user-agent' => 'Mozilla/5.0 (compatible; wp-stocks-manager)']);
    if (is_wp_error($res)) return [false, $res->get_error_message()];
    $status = (int) wp_remote_retrieve_response_code($res);
    if ($status !== 200) return [false, 'HTTP ' . $status];
    return [wp_remote_retrieve_body($res), ''];
}

// 一覧ページにあるxlsxのURL（月ごとのファイル）。リンクが増えても自動で拾う
function wp_stocks_jpx_xlsx_urls(&$err) {
    list($html, $e) = wp_stocks_jpx_http_get(WP_STOCKS_JPX_BASE . WP_STOCKS_JPX_INDEX);
    if ($html === false) { $err = '一覧ページの取得に失敗（' . $e . '）'; return []; }
    preg_match_all('#href="(/listing/event-schedules/financial-announcement/[^"]+\.xlsx)"#i', $html, $m);
    $urls = [];
    foreach (array_unique($m[1]) as $path) $urls[] = WP_STOCKS_JPX_BASE . $path;
    return array_slice($urls, 0, 6);
}

// --------------------------------------------------
// 同期本体
// --------------------------------------------------
function wp_stocks_jpx_sync_earnings_dates() {
    global $wpdb;
    @set_time_limit(180);
    $st = ['at' => current_time('mysql'), 'files' => 0, 'updated' => 0, 'past' => 0, 'missing' => 0, 'error' => ''];

    $err  = '';
    $urls = wp_stocks_jpx_xlsx_urls($err);
    if (!$urls && $err === '') $err = 'Excelのリンクが見つかりません';

    $today = wp_stocks_jh_today_safe();
    $map   = [];   // コード => 今日以降で一番近い予定日
    $past  = [];   // コード => 今日より前で一番新しい発表日（前回決算）
    $first_err = $err;
    foreach ($urls as $u) {
        list($bin, $e) = wp_stocks_jpx_http_get($u);
        if ($bin === false) { if ($first_err === '') $first_err = basename($u) . '：' . $e; continue; }
        $rows = wp_stocks_jpx_xlsx_rows($bin);
        if ($rows === false) { if ($first_err === '') $first_err = basename($u) . '：Excelを解析できません'; continue; }
        $st['files']++;
        foreach (wp_stocks_jpx_parse_rows($rows) as $code => $dates) {
            foreach ($dates as $d) {
                if ($d < $today) {
                    if (!isset($past[$code]) || $d > $past[$code]) $past[$code] = $d;
                    continue;
                }
                if (!isset($map[$code]) || $d < $map[$code]) $map[$code] = $d;
            }
        }
    }

    if ($st['files'] === 0) {
        $st['error'] = $first_err !== '' ? $first_err : '取得できたファイルがありません';
    } else {
        $stocks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, code, currency, earnings_date FROM {$wpdb->prefix}stocks
             WHERE status IN ('watch','portfolio') AND is_sector_etf = 0
               AND (earnings_date IS NULL OR earnings_date < %s)", $today));
        foreach ((array) $stocks as $s) {
            if (($s->currency ?? 'JPY') === 'USD') continue;
            $code = strtoupper(preg_replace('/\.T$/i', '', trim((string) $s->code)));
            if (isset($map[$code])) {
                $wpdb->update($wpdb->prefix . 'stocks', ['earnings_date' => $map[$code]], ['id' => $s->id]);
                $st['updated']++;
                continue;
            }
            // 先の予定日が一覧に無い銘柄：発表済みの日付が一覧にあれば、前回決算として登録する
            if (isset($past[$code])) {
                if ($s->earnings_date === null || $s->earnings_date < $past[$code]) {
                    $wpdb->update($wpdb->prefix . 'stocks', ['earnings_date' => $past[$code]], ['id' => $s->id]);
                    $st['past']++;
                }
                continue;   // 登録済みの前回決算が一覧と同じか新しいなら、そのまま
            }
            $st['missing']++;
        }
        if ($first_err !== '') $st['error'] = $first_err;   // 一部のファイルだけ失敗した場合も記録する
    }

    update_option('wp_stocks_jpx_earnings_status', $st, false);
    if (function_exists('wp_stocks_log')) {
        if ($st['error'] !== '') wp_stocks_log('error', 'jpx_earnings_date', 'JP', $st['error']);
        else wp_stocks_log('info', 'jpx_earnings_date', 'JP', "JPXから決算予定日を更新：{$st['updated']}件、前回決算として登録{$st['past']}件（一覧に載っていない{$st['missing']}件）");
    }
    return $st;
}

add_action('wp_stocks_jpx_earnings_daily', function () { wp_stocks_jpx_sync_earnings_dates(); });

// 毎日 6:00（日本時間）
add_action('init', function () {
    if (wp_next_scheduled('wp_stocks_jpx_earnings_daily')) return;
    $next = new DateTime('today 06:00', new DateTimeZone('Asia/Tokyo'));
    if ($next->getTimestamp() <= time()) $next->modify('+1 day');
    wp_schedule_event($next->getTimestamp(), 'daily', 'wp_stocks_jpx_earnings_daily');
});

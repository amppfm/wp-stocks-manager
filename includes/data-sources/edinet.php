<?php
/**
 * WP Stocks Manager — EDINET 連携（コードリスト解決・財務データ・半期報告書）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// EDINET 財務データ取得
// --------------------------------------------------
// --------------------------------------------------
// EDINETコードリストCSV(EdinetcodeDlInfo.csv)をパースし、
// ['edinet' => [証券コード => EDINETコード], 'decdate' => [証券コード => 決算日文字列]]
// を返す。決算日は有価証券報告書の提出期限(決算日の3ヶ月後)を逆算し、
// docID検索の日付範囲を大幅に絞り込むために使用する。
// --------------------------------------------------
function wp_stocks_parse_edinet_codelist_csv($csv_content) {
    // ファイルはCP932(Shift-JIS)エンコードで配布されている
    $text  = @mb_convert_encoding($csv_content, 'UTF-8', 'SJIS-win');
    if (empty($text)) $text = $csv_content;
    $lines = preg_split('/\r\n|\r|\n/', $text);

    $edinet_map   = [];
    $decdate_map  = [];
    $header_found = false;
    $dec_date_col_index = null;
    $sec_code_col_index = null;
    $sample_logged = false;
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cols = str_getcsv($line);
        if (!$header_found) {
            // 1行目はダウンロード実行日等のメタ情報、2行目が本来のヘッダー行
            if (isset($cols[0]) && mb_strpos($cols[0], 'ＥＤＩＮＥＴコード') !== false) {
                $header_found = true;
                // ヘッダー内から「決算日」「証券コード」を含む列を自動検出
                // （決め打ちの列番号がズレていても対応できるように）
                foreach ($cols as $idx => $col_name) {
                    if ($dec_date_col_index === null && mb_strpos($col_name, '決算日') !== false) {
                        $dec_date_col_index = $idx;
                    }
                    if ($sec_code_col_index === null && mb_strpos($col_name, '証券コード') !== false) {
                        $sec_code_col_index = $idx;
                    }
                }
                wp_stocks_log('info', 'edinet_codelist', 'ALL', 'CSVヘッダー(' . count($cols) . '列): ' . implode(' | ', $cols) . ' / 決算日列インデックス=' . ($dec_date_col_index !== null ? $dec_date_col_index : '見つからず(5番目にフォールバック)') . ' / 証券コード列インデックス=' . ($sec_code_col_index !== null ? $sec_code_col_index : '見つからず(11番目にフォールバック)'));
            }
            continue;
        }
        $dec_col  = $dec_date_col_index !== null ? $dec_date_col_index : 5;
        $sec_col  = $sec_code_col_index !== null ? $sec_code_col_index : 11;
        $edinet_code = trim($cols[0] ?? '');
        $dec_date    = trim($cols[$dec_col] ?? '');
        $sec_code    = trim($cols[$sec_col] ?? '');
        if (!$sample_logged) {
            wp_stocks_log('info', 'edinet_codelist', 'ALL', 'サンプル行: edinetCode=' . $edinet_code . ', decDate(idx=' . $dec_col . ')=' . $dec_date . ', secCode(idx=' . $sec_col . ')=' . $sec_code);
            $sample_logged = true;
        }
        if ($edinet_code === '' || $sec_code === '') continue;
        $edinet_map[$sec_code] = $edinet_code;
        if ($dec_date !== '') $decdate_map[$sec_code] = $dec_date;
    }
    return ['edinet' => $edinet_map, 'decdate' => $decdate_map];
}

function wp_stocks_get_edinet_code($code) {
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare(
        "SELECT id, edinet_code FROM {$wpdb->prefix}stocks WHERE code = %s", $code
    ));
    if (!$stock) return false;
    if (!empty($stock->edinet_code)) return ['id' => $stock->id, 'edinet_code' => $stock->edinet_code];

    $api_key = get_option('wp_stocks_edinet_api_key', '');
    if (empty($api_key)) {
        wp_stocks_log('error', 'get_edinet_code', $code, 'EDINET APIキーが設定されていません');
        return false;
    }

    $sec_code = $code . '0';

    // 事前にアップロードされたEDINETコードリスト(CSV)があれば最優先で参照する。
    // これが見つかれば、以下の総当たり日付検索(APIを何十回も叩く処理)を
    // 完全にスキップでき、高速かつ確実にEDINETコードを解決できる。
    $edinet_codelist = get_option('wp_stocks_edinet_codelist', []);
    if (is_array($edinet_codelist) && isset($edinet_codelist[$sec_code])) {
        $found_from_list = $edinet_codelist[$sec_code];
        $wpdb->update($wpdb->prefix . 'stocks', ['edinet_code' => $found_from_list], ['id' => $stock->id]);
        wp_stocks_log('info', 'get_edinet_code', $code, 'EDINETコードリストのキャッシュから解決しました: ' . $found_from_list);
        return ['id' => $stock->id, 'edinet_code' => $found_from_list];
    }

    $found = '';
    $search_dates2 = [];
    for ($m = 0; $m <= 18; $m++) {
        $ts = mktime(0, 0, 0, date('n') - $m, 15, date('Y'));
        foreach ([25, 20, 15] as $day) {
            $search_dates2[] = date('Y-m', $ts) . '-' . sprintf('%02d', $day);
        }
    }
    $search_dates2 = array_unique($search_dates2);
    foreach ($search_dates2 as $date) {
        $url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents.json?date={$date}&type=2&Subscription-Key=" . urlencode($api_key);
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        foreach (($body['results'] ?? []) as $doc) {
            if (($doc['secCode'] ?? '') === $sec_code && ($doc['docTypeCode'] ?? '') === '120') {
                $found = $doc['edinetCode'] ?? '';
                break 2;
            }
        }
    }
    if ($found) {
        $wpdb->update($wpdb->prefix . 'stocks', ['edinet_code' => $found], ['id' => $stock->id]);
        return ['id' => $stock->id, 'edinet_code' => $found];
    }
    wp_stocks_log('error', 'get_edinet_code', $code, 'EDINETコードが見つかりませんでした（secCode=' . $sec_code . ', 検索日数=' . count($search_dates2) . '件, 検索範囲=過去18ヶ月の15/20/25日のみ）');
    return false;
}

// --------------------------------------------------
// EDINET半期報告書の診断取得（ZIP内ファイル一覧とCSV内容をログ出力）
// --------------------------------------------------
function wp_stocks_diagnose_half_year_report($stock_id, $code) {
    global $wpdb;
    $api_key = get_option('wp_stocks_edinet_api_key', '');
    if (empty($api_key)) {
        wp_stocks_log('error', 'diag_half_year', $code, 'EDINET APIキーが設定されていません');
        return false;
    }

    $edinet_info = wp_stocks_get_edinet_code($code);
    if (!$edinet_info) {
        wp_stocks_log('error', 'diag_half_year', $code, 'EDINETコードの取得に失敗');
        return false;
    }
    $edinet_code = $edinet_info['edinet_code'];

    $doc_id      = '';
    $sec_code    = $code . '0';
    $decdate_map = get_option('wp_stocks_edinet_decdate_map', []);
    $search_dates = [];

    if (is_array($decdate_map) && isset($decdate_map[$sec_code])
        && preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $decdate_map[$sec_code], $dm)) {
        // 本決算日から6ヶ月引いた「中間決算日」を基準に、その1.5ヶ月後（約45日後）
        // 前後を1日単位で探索する（本決算の提出期限探索ロジックを半期用に転用）
        $fy_month = intval($dm[1]);
        $fy_day   = intval($dm[2]);
        $today_ts = strtotime('today');
        for ($y = 0; $y <= 2; $y++) {
            $fy_year        = intval(date('Y')) - $y;
            $fy_end_ts      = mktime(0, 0, 0, $fy_month, $fy_day, $fy_year);
            $half_end_ts    = strtotime('-6 months', $fy_end_ts);
            $deadline_ts    = strtotime('+45 days', $half_end_ts);
            // 提出期限の30日前〜30日後を1日ずつ（早期提出・遅延両対応）
            for ($offset = -30; $offset <= 30; $offset++) {
                $d_ts = strtotime("{$offset} days", $deadline_ts);
                if ($d_ts > $today_ts) continue;
                $search_dates[] = date('Y-m-d', $d_ts);
            }
        }
        $search_dates = array_unique($search_dates);
        rsort($search_dates);
    } else {
        // 決算日が不明な場合は過去24ヶ月を1日単位で総当たり（時間はかかるが確実）
        $start_ts = strtotime('-24 months');
        $end_ts   = strtotime('today');
        for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 day', $ts)) {
            $search_dates[] = date('Y-m-d', $ts);
        }
        rsort($search_dates);
    }

    foreach ($search_dates as $date) {
        if (!empty($doc_id)) break;
        $url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents.json?date={$date}&type=2&Subscription-Key=" . urlencode($api_key);
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        foreach (($body['results'] ?? []) as $doc) {
            if (($doc['edinetCode'] ?? '') === $edinet_code && ($doc['docTypeCode'] ?? '') === '160') {
                $doc_id = $doc['docID'];
                break;
            }
        }
    }
    if (empty($doc_id)) {
        wp_stocks_log('error', 'diag_half_year', $code, '半期報告書のdocIDが見つかりませんでした（edinetCode=' . $edinet_code . ', 検索日数=' . count($search_dates) . '件）');
        return false;
    }
    wp_stocks_log('info', 'diag_half_year', $code, '半期報告書のdocIDを発見しました: docID=' . $doc_id);

    $zip_url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents/{$doc_id}?type=5&Subscription-Key=" . urlencode($api_key);
    $zip_res = wp_remote_get($zip_url, ['timeout' => 60]);
    if (is_wp_error($zip_res)) {
        wp_stocks_log('error', 'diag_half_year', $code, 'ZIPダウンロード失敗(通信エラー): ' . $zip_res->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($zip_res) !== 200) {
        wp_stocks_log('error', 'diag_half_year', $code, 'ZIPダウンロード失敗: HTTP ' . wp_remote_retrieve_response_code($zip_res));
        return false;
    }
    $zip_data = wp_remote_retrieve_body($zip_res);
    $tmp_file = sys_get_temp_dir() . '/edinet_half_' . $doc_id . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $file_names  = [];
    $csv_content = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $file_names[] = $name;
                if (empty($csv_content) && strpos($name, '.csv') !== false && strpos($name, 'jpcrp040300-ssr') !== false) {
                    $csv_content = $zip->getFromIndex($i);
                }
            }
            $zip->close();
        }
    }
    if (empty($file_names) && class_exists('PharData')) {
        try {
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $path = $file->getPathname();
                $file_names[] = $path;
                if (empty($csv_content) && strpos($path, '.csv') !== false && strpos($path, 'jpcrp040300-ssr') !== false) {
                    $csv_content = file_get_contents($path);
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', 'diag_half_year', $code, 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }
    unlink($tmp_file);

    wp_stocks_log('info', 'diag_half_year', $code, 'ZIP内ファイル一覧（' . count($file_names) . '件）: ' . implode(' | ', array_slice($file_names, 0, 30)));

    if (empty($csv_content)) {
        wp_stocks_log('error', 'diag_half_year', $code, 'CSVファイルが見つかりませんでした（PublicDoc配下の.csvが無い可能性）');
        return false;
    }

    $text = mb_convert_encoding($csv_content, 'UTF-8', 'UTF-16LE');
    wp_stocks_log('info', 'diag_half_year', $code, 'CSV先頭2000文字: ' . substr($text, 0, 2000));

    $lines = explode("\n", $text);
    $sample_lines = [];
    foreach ($lines as $line) {
        if (strpos($line, 'SummaryOfBusinessResults') !== false || strpos($line, 'NetSales') !== false || strpos($line, 'OrdinaryIncome') !== false) {
            $sample_lines[] = $line;
            if (count($sample_lines) >= 15) break;
        }
    }
    wp_stocks_log('info', 'diag_half_year', $code, 'SummaryOfBusinessResults関連行サンプル（' . count($sample_lines) . '件）: ' . implode(' || ', $sample_lines));

    return true;
}

// --------------------------------------------------
// EDINET半期報告書から当中間期の売上高・純利益を解析してDB保存
// --------------------------------------------------
function wp_stocks_fetch_half_year_financials($stock_id, $code) {
    global $wpdb;
    $api_key = get_option('wp_stocks_edinet_api_key', '');
    if (empty($api_key)) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'EDINET APIキーが設定されていません');
        return false;
    }

    $edinet_info = wp_stocks_get_edinet_code($code);
    if (!$edinet_info) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'EDINETコードの取得に失敗');
        return false;
    }
    $edinet_code = $edinet_info['edinet_code'];

    $doc_id      = '';
    $sec_code    = $code . '0';
    $decdate_map = get_option('wp_stocks_edinet_decdate_map', []);
    $search_dates = [];

    if (is_array($decdate_map) && isset($decdate_map[$sec_code])
        && preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $decdate_map[$sec_code], $dm)) {
        $fy_month = intval($dm[1]);
        $fy_day   = intval($dm[2]);
        $today_ts = strtotime('today');
        for ($y = 0; $y <= 2; $y++) {
            $fy_year     = intval(date('Y')) - $y;
            $fy_end_ts   = mktime(0, 0, 0, $fy_month, $fy_day, $fy_year);
            $half_end_ts = strtotime('-6 months', $fy_end_ts);
            $deadline_ts = strtotime('+45 days', $half_end_ts);
            for ($offset = -30; $offset <= 30; $offset++) {
                $d_ts = strtotime("{$offset} days", $deadline_ts);
                if ($d_ts > $today_ts) continue;
                $search_dates[] = date('Y-m-d', $d_ts);
            }
        }
        $search_dates = array_unique($search_dates);
        rsort($search_dates);
    } else {
        $start_ts = strtotime('-24 months');
        $end_ts   = strtotime('today');
        for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 day', $ts)) {
            $search_dates[] = date('Y-m-d', $ts);
        }
        rsort($search_dates);
    }

    foreach ($search_dates as $date) {
        if (!empty($doc_id)) break;
        $url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents.json?date={$date}&type=2&Subscription-Key=" . urlencode($api_key);
        $res = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = json_decode(wp_remote_retrieve_body($res), true);
        foreach (($body['results'] ?? []) as $doc) {
            if (($doc['edinetCode'] ?? '') === $edinet_code && ($doc['docTypeCode'] ?? '') === '160') {
                $doc_id = $doc['docID'];
                break;
            }
        }
    }
    if (empty($doc_id)) {
        wp_stocks_log('error', 'fetch_half_year', $code, '半期報告書のdocIDが見つかりませんでした（edinetCode=' . $edinet_code . '）');
        return false;
    }

    $zip_url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents/{$doc_id}?type=5&Subscription-Key=" . urlencode($api_key);
    $zip_res = wp_remote_get($zip_url, ['timeout' => 60]);
    if (is_wp_error($zip_res) || wp_remote_retrieve_response_code($zip_res) !== 200) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'ZIPダウンロード失敗');
        return false;
    }
    $zip_data = wp_remote_retrieve_body($zip_res);
    $tmp_file = sys_get_temp_dir() . '/edinet_half_' . $doc_id . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $csv_content   = '';
    $matched_name  = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, '.csv') !== false && strpos($name, 'jpcrp040300-ssr') !== false) {
                    $csv_content  = $zip->getFromIndex($i);
                    $matched_name = $name;
                    break;
                }
            }
            $zip->close();
        }
    }
    if (empty($csv_content) && class_exists('PharData')) {
        try {
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $path = $file->getPathname();
                if (strpos($path, '.csv') !== false && strpos($path, 'jpcrp040300-ssr') !== false) {
                    $csv_content  = file_get_contents($path);
                    $matched_name = $path;
                    break;
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', 'fetch_half_year', $code, 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }
    unlink($tmp_file);

    if (empty($csv_content)) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'CSVファイルが見つかりませんでした');
        return false;
    }

    // ファイル名末尾の日付（期末日）を抽出。例: ..._2025-09-30_01_2025-11-13.csv
    $period_end = null;
    if (preg_match('/_(\d{4}-\d{2}-\d{2})_\d+_\d{4}-\d{2}-\d{2}\.csv$/', $matched_name, $pm)) {
        $period_end = $pm[1];
    }
    if (!$period_end) {
        wp_stocks_log('error', 'fetch_half_year', $code, 'ファイル名から期末日を抽出できませんでした: ' . $matched_name);
        return false;
    }

    $text  = mb_convert_encoding($csv_content, 'UTF-8', 'UTF-16LE');
    $lines = explode("\n", $text);

    $revenue    = null;
    $net_income = null;
    foreach ($lines as $line) {
        if (strpos($line, 'InterimDuration') === false) continue;
        $cols = str_getcsv($line, "\t", '"');
        if (count($cols) < 9) continue;
        $element_id = $cols[0];
        $context_id = $cols[2];
        $value      = $cols[8];
        if ($context_id !== 'InterimDuration') continue;
        if ($revenue === null
            && (strpos($element_id, 'NetSales') !== false || strpos($element_id, 'OperatingRevenues') !== false)
            && (strpos($element_id, 'SummaryOfBusinessResults') !== false || strpos($element_id, 'KeyFinancialData') !== false)) {
            $revenue = intval($value);
        }
        if ($net_income === null
            && (strpos($element_id, 'ProfitLossAttributableToOwnersOfParent') !== false || strpos($element_id, 'NetIncome') !== false)
            && strpos($element_id, 'SummaryOfBusinessResults') !== false) {
            $net_income = intval($value);
        }
    }

    if ($revenue === null && $net_income === null) {
        wp_stocks_log('error', 'fetch_half_year', $code, '売上高・純利益のタグが見つかりませんでした（会計基準の違いでタグ名が異なる可能性）。docID=' . $doc_id);
        return false;
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND period_end = %s AND source = 'edinet_half'",
        $stock_id, $period_end
    ));
    $data = ['stock_id' => $stock_id, 'period_end' => $period_end, 'revenue' => $revenue, 'net_income' => $net_income, 'source' => 'edinet_half'];
    if ($existing) {
        $wpdb->update($wpdb->prefix . 'stock_quarterly_financials', $data, ['id' => $existing]);
    } else {
        $wpdb->insert($wpdb->prefix . 'stock_quarterly_financials', $data);
    }

    wp_stocks_log('info', 'fetch_half_year', $code, '半期データを保存しました（期末日=' . $period_end . ', 売上高=' . ($revenue ?? '-') . ', 純利益=' . ($net_income ?? '-') . '）');
    return true;
}

// --------------------------------------------------
// EDINET: ZIPをダウンロードし、ファイル名にfilename_must_containを含む
// CSVを抽出する共通ヘルパー（ZipArchive優先、失敗時はPharDataにフォールバック）
// 戻り値: ['content' => CSV文字列, 'matched_name' => ZIP内のファイルパス] または false
// --------------------------------------------------
function wp_stocks_edinet_extract_csv_from_zip($doc_id, $api_key, $filename_must_contain, $log_action = 'edinet_extract_zip', $log_symbol = '') {
    $zip_url = "https://disclosure.edinet-fsa.go.jp/api/v2/documents/{$doc_id}?type=5&Subscription-Key=" . urlencode($api_key);
    $zip_res = wp_remote_get($zip_url, ['timeout' => 60]);
    if (is_wp_error($zip_res)) {
        wp_stocks_log('error', $log_action, $log_symbol, 'ZIPダウンロード失敗(通信エラー): ' . $zip_res->get_error_message() . ' docID=' . $doc_id);
        return false;
    }
    if (wp_remote_retrieve_response_code($zip_res) !== 200) {
        wp_stocks_log('error', $log_action, $log_symbol, 'ZIPダウンロード失敗: HTTP ' . wp_remote_retrieve_response_code($zip_res) . ' docID=' . $doc_id);
        return false;
    }

    $zip_data = wp_remote_retrieve_body($zip_res);
    $tmp_file = sys_get_temp_dir() . '/edinet_' . $doc_id . '_' . uniqid() . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $csv_content  = '';
    $matched_name = '';

    // ext-zip (ZipArchive) が有効な環境ではこちらを優先して使用
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, '.csv') !== false && strpos($name, $filename_must_contain) !== false) {
                    $csv_content  = $zip->getFromIndex($i);
                    $matched_name = $name;
                    break;
                }
            }
            $zip->close();
        }
    }

    // ext-zipが無効な環境(Synology DSM等)向けフォールバック:
    // Phar拡張(多くの環境で標準有効)のPharDataクラスはext-zip無しでもZIPを読み込める
    if (empty($csv_content) && class_exists('PharData')) {
        try {
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                $path = $file->getPathname();
                if (strpos($path, '.csv') !== false && strpos($path, $filename_must_contain) !== false) {
                    $csv_content  = file_get_contents($path);
                    $matched_name = $path;
                    break;
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', $log_action, $log_symbol, 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }

    unlink($tmp_file);
    if (empty($csv_content)) {
        wp_stocks_log('error', $log_action, $log_symbol, 'ZIPからCSVを抽出できませんでした（ext-zip, PharDataいずれも利用不可か、対象ファイルが見つかりません）docID=' . $doc_id);
        return false;
    }
    wp_stocks_log('info', $log_action, $log_symbol, 'ZIPからCSVを抽出しました（' . strlen($csv_content) . 'バイト）docID=' . $doc_id);

    return ['content' => $csv_content, 'matched_name' => $matched_name];
}


// ★2026-09-26廃止：wp_stocks_fetch_financials()（EDINET年間財務データ取得）は
// J-Quantsのstock_quarterly_financials（fiscal_quarter=4=通期）に一本化したため削除。

// --------------------------------------------------
// EDINETコードリストを直接ダウンロードする(2026-07-14 動作確認済み)
// https://disclosure2dl.edinet-fsa.go.jp/searchdocument/codelist/Edinetcode.zip
// は認証・ブラウザのJS不要で直接取得可能なため、手動アップロードなしで
// 自動更新できる。
// --------------------------------------------------
if (!defined('WP_STOCKS_EDINET_CODELIST_URL')) {
    define('WP_STOCKS_EDINET_CODELIST_URL', 'https://disclosure2dl.edinet-fsa.go.jp/searchdocument/codelist/Edinetcode.zip');
}

function wp_stocks_download_edinet_codelist() {
    $response = wp_remote_get(WP_STOCKS_EDINET_CODELIST_URL, [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
        'timeout' => 30,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'EDINETコードリストの自動ダウンロードに失敗(通信エラー): ' . $response->get_error_message());
        return false;
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'EDINETコードリストの自動ダウンロードに失敗: HTTP ' . wp_remote_retrieve_response_code($response));
        return false;
    }
    $zip_data = wp_remote_retrieve_body($response);
    $tmp_file = sys_get_temp_dir() . '/edinetcode_' . uniqid() . '.zip';
    file_put_contents($tmp_file, $zip_data);

    $content = '';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp_file) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (stripos($name, '.csv') !== false) {
                    $content = $zip->getFromIndex($i);
                    break;
                }
            }
            $zip->close();
        }
    }
    if (empty($content) && class_exists('PharData')) {
        try {
            $phar = new PharData($tmp_file);
            foreach (new RecursiveIteratorIterator($phar) as $file) {
                if (stripos($file->getPathname(), '.csv') !== false) {
                    $content = file_get_contents($file->getPathname());
                    break;
                }
            }
        } catch (Throwable $e) {
            wp_stocks_log('error', 'edinet_codelist', 'ALL', 'ZIP展開失敗(PharData): ' . $e->getMessage());
        }
    }
    unlink($tmp_file);
    if (empty($content)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'ダウンロードしたZIPからCSVを抽出できませんでした');
        return false;
    }
    return $content;
}

function wp_stocks_save_edinet_codelist_content($content, $source_label = '自動ダウンロード') {
    $parsed = wp_stocks_parse_edinet_codelist_csv($content);
    $map    = $parsed['edinet']  ?? [];
    $decmap = $parsed['decdate'] ?? [];
    if (empty($map)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'CSVのパースに失敗、または0件でした（' . $source_label . '）');
        return false;
    }
    update_option('wp_stocks_edinet_codelist', $map, false);
    update_option('wp_stocks_edinet_decdate_map', $decmap, false);
    update_option('wp_stocks_edinet_codelist_updated_at', current_time('mysql'));
    wp_stocks_log('info', 'edinet_codelist', 'ALL', 'EDINETコードリストを更新しました（' . count($map) . '件、決算日情報' . count($decmap) . '件、' . $source_label . '）');
    return count($map);
}

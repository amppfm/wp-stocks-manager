<?php
/**
 * WP Stocks Manager — admin-post アクション群（銘柄操作・データ取得・診断系ハンドラー）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// admin-post アクション群
// --------------------------------------------------

// --------------------------------------------------
// admin_post_* ハンドラ共通: 権限チェック＋nonce検証
// --------------------------------------------------
function wp_stocks_require_admin_action($nonce_action) {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer($nonce_action);
}

add_action('admin_post_get_stock_price', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id    = intval($_GET['id'] ?? 0);
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $symbol = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $result = wp_stocks_save_price($id, $symbol);
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=' . ($result ? 'price_saved' : 'price_error')));
    exit;
});


add_action('admin_post_wp_stocks_webull_test_quotes_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test');

    $host     = 'api.webull.co.jp';
    $symbol   = sanitize_text_field($_GET['symbol'] ?? '7203');
    $category = sanitize_text_field($_GET['category'] ?? 'JP_STOCK');

    header('Content-Type: text/plain; charset=utf-8');

    // --------------------------------------------------
    // Step1: Get Instruments (/instrument/list) で instrument_id を取得
    // --------------------------------------------------
    $path1  = '/instrument/list';
    $query1 = array(
        'symbols'  => $symbol,
        'category' => $category,
    );
    $signed1  = wp_stocks_webull_sign_request('GET', $path1, $query1, '', $host);
    $headers1 = $signed1['headers'];
    $headers1['Accept'] = 'application/json';

    $url1 = 'https://' . $host . $path1 . '?' . http_build_query($query1);
    $res1 = wp_remote_get($url1, array('headers' => $headers1, 'timeout' => 30));

    if (is_wp_error($res1)) {
        echo "Step1(Get Instruments) 通信エラー: " . $res1->get_error_message();
        exit;
    }

    $status1 = wp_remote_retrieve_response_code($res1);
    $body1   = wp_remote_retrieve_body($res1);

    echo "=== Step1: Get Instruments ({$symbol} / {$category}) ===\n";
    echo "HTTP Status: {$status1}\n";
    echo $body1 . "\n\n";

    $instruments   = json_decode($body1, true);
    $instrument_id = '';
    if (is_array($instruments) && isset($instruments[0]['instrument_id'])) {
        $instrument_id = $instruments[0]['instrument_id'];
    }

    if (empty($instrument_id)) {
        echo "instrument_idが取得できなかったため、Step2(End-of-day Market)はスキップします。";
        exit;
    }

    // --------------------------------------------------
    // Step2: End-of-day Market (/market-data/eod-bars) で日足を取得
    // --------------------------------------------------
    $path2  = '/market-data/eod-bars';
    $query2 = array(
        'instrument_ids' => $instrument_id,
        'count'          => '10',
    );
    $signed2  = wp_stocks_webull_sign_request('GET', $path2, $query2, '', $host);
    $headers2 = $signed2['headers'];
    $headers2['Accept'] = 'application/json';

    $url2 = 'https://' . $host . $path2 . '?' . http_build_query($query2);
    $res2 = wp_remote_get($url2, array('headers' => $headers2, 'timeout' => 30));

    if (is_wp_error($res2)) {
        echo "Step2(End-of-day Market) 通信エラー: " . $res2->get_error_message();
        exit;
    }

    $status2 = wp_remote_retrieve_response_code($res2);
    $body2   = wp_remote_retrieve_body($res2);

    echo "=== Step2: End-of-day Market (instrument_id={$instrument_id}) ===\n";
    echo "HTTP Status: {$status2}\n";
    echo $body2;
    exit;
});


add_action('admin_post_wp_stocks_webull_test_single_bar_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test');

    $host     = 'api.webull.co.jp';
    $path     = '/openapi/market-data/stock/bars';
    $symbol   = sanitize_text_field($_GET['symbol'] ?? '7203');
    $category = sanitize_text_field($_GET['category'] ?? 'JP_STOCK');

    $query = array(
        'symbol'   => $symbol,
        'category' => $category,
        'timespan' => 'D',
        'count'    => '10',
    );

    $signed  = wp_stocks_webull_sign_request('GET', $path, $query, '', $host);
    $headers = $signed['headers'];
    $headers['Accept'] = 'application/json';

    $url = 'https://' . $host . $path . '?' . http_build_query($query);
    $response = wp_remote_get($url, array('headers' => $headers, 'timeout' => 30));

    header('Content-Type: text/plain; charset=utf-8');

    if (is_wp_error($response)) {
        echo "通信エラー: " . $response->get_error_message();
        exit;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);

    echo "URL: {$url}\n\n";
    echo "HTTP Status: {$status_code}\n\n";
    echo $raw_body;
    exit;
});

add_action('admin_post_wp_stocks_webull_test_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test');

    // 本番環境
    $host   = 'api.webull.co.jp';
    $path   = '/market-data/stocks/bars/list';
    $symbol = sanitize_text_field($_GET['symbol'] ?? '7203');
    $category = sanitize_text_field($_GET['category'] ?? 'JP_STOCK');
    $body = wp_json_encode(array(
        'symbols'            => array($symbol),
        'category'           => $category,
        'timespan'           => 'D',
        'count'              => 10,
        'real_time_required' => false,
    ));

    $signed  = wp_stocks_webull_sign_request('POST', $path, array(), $body, $host, 'v3', 'HMAC-SHA1');
    $headers = $signed['headers'];
    $headers['Content-Type'] = 'application/json';
    $headers['Accept']       = 'application/json';

    $response = wp_remote_post('https://' . $host . $path, array(
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 30,
    ));

    header('Content-Type: text/plain; charset=utf-8');

    if (is_wp_error($response)) {
        echo "通信エラー: " . $response->get_error_message();
        exit;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);

    echo "HTTP Status: {$status_code}\n\n";
    echo $raw_body;
    exit;
});

add_action('admin_post_wp_stocks_webull_yahoo_compare', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_yahoo_compare');

    $symbol   = sanitize_text_field($_GET['symbol']   ?? 'AAPL');
    $category = sanitize_text_field($_GET['category'] ?? 'US_STOCK');
    $count    = max(5, min(200, intval($_GET['count'] ?? 15)));

    // Yahoo Finance用シンボル：日本株ならcodeに.Tを付与、米国株はそのまま
    $yahoo_symbol = ($category === 'JP_STOCK') ? $symbol . '.T' : $symbol;

    $webull_bars = wp_stocks_webull_fetch_daily_bars($symbol, $category, $count);
    $yahoo_bars  = wp_stocks_get_ohlcv($yahoo_symbol); // 6ヶ月分、6時間キャッシュ

    header('Content-Type: text/html; charset=utf-8');

    echo '<html><head><meta charset="utf-8"><title>Webull vs Yahoo 突き合わせ: ' . esc_html($symbol) . '</title>';
    echo '<style>
        body { font-family: sans-serif; padding: 20px; }
        table { border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
        th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: right; }
        th { background: #f0f0f0; }
        td.date { text-align: left; font-weight: bold; }
        td.diff { color: #e74c3c; font-weight: bold; background: #fff0f0; }
        td.match { color: #27ae60; }
        td.missing { color: #999; background: #f5f5f5; }
        h2 { margin-top: 30px; }
    </style></head><body>';

    echo '<h1>Webull vs Yahoo Finance 日足突き合わせ</h1>';
    echo '<p>Symbol: <strong>' . esc_html($symbol) . '</strong> / Category: <strong>' . esc_html($category) . '</strong> / Yahoo Symbol: <strong>' . esc_html($yahoo_symbol) . '</strong> / 件数: ' . intval($count) . '</p>';

    if ($webull_bars === false) {
        echo '<p style="color:red;">Webullからのデータ取得に失敗しました。ログをご確認ください。</p></body></html>';
        exit;
    }
    if (!$yahoo_bars) {
        echo '<p style="color:red;">Yahoo Financeからのデータ取得に失敗しました。</p></body></html>';
        exit;
    }

    // 日付をキーにしたマップを作成
    $webull_map = array();
    foreach ($webull_bars as $b) $webull_map[$b['date']] = $b;

    $yahoo_map = array();
    foreach ($yahoo_bars as $y) $yahoo_map[$y['date']] = $y;

    // Webull側の日付範囲に絞って比較（Webullの取得件数を基準にする）
    $target_dates = array_keys($webull_map);
    sort($target_dates);

    echo '<table>';
    echo '<tr>
        <th rowspan="2">日付</th>
        <th colspan="5">Webull</th>
        <th colspan="5">Yahoo Finance</th>
        <th rowspan="2">判定</th>
    </tr>';
    echo '<tr>
        <th>始値</th><th>高値</th><th>安値</th><th>終値</th><th>出来高</th>
        <th>始値</th><th>高値</th><th>安値</th><th>終値</th><th>出来高</th>
    </tr>';

    $tolerance = 0.05; // 価格の許容誤差（±5銭・仕様差異吸収用）

    foreach ($target_dates as $date) {
        $w = $webull_map[$date] ?? null;
        $y = $yahoo_map[$date]  ?? null;

        echo '<tr>';
        echo '<td class="date">' . esc_html($date) . '</td>';

        if ($w) {
            echo '<td>' . number_format($w['open'], 2) . '</td>';
            echo '<td>' . number_format($w['high'], 2) . '</td>';
            echo '<td>' . number_format($w['low'], 2) . '</td>';
            echo '<td>' . number_format($w['close'], 2) . '</td>';
            echo '<td>' . number_format($w['volume']) . '</td>';
        } else {
            echo '<td colspan="5" class="missing">Webullデータなし</td>';
        }

        if ($y) {
            echo '<td>' . number_format($y['open'], 2) . '</td>';
            echo '<td>' . number_format($y['high'], 2) . '</td>';
            echo '<td>' . number_format($y['low'], 2) . '</td>';
            echo '<td>' . number_format($y['close'], 2) . '</td>';
            echo '<td>' . number_format($y['volume']) . '</td>';
        } else {
            echo '<td colspan="5" class="missing">Yahooデータなし</td>';
        }

        if ($w && $y) {
            $diffs = array();
            foreach (array('open', 'high', 'low', 'close') as $field) {
                if (abs($w[$field] - $y[$field]) > $tolerance) {
                    $diffs[] = $field . ':' . number_format($w[$field] - $y[$field], 2);
                }
            }
            $vol_diff_pct = $y['volume'] > 0 ? abs($w['volume'] - $y['volume']) / $y['volume'] * 100 : 0;
            if ($vol_diff_pct > 5) {
                $diffs[] = 'volume差' . number_format($vol_diff_pct, 1) . '%';
            }
            if (empty($diffs)) {
                echo '<td class="match">一致</td>';
            } else {
                echo '<td class="diff">差異: ' . esc_html(implode(', ', $diffs)) . '</td>';
            }
        } else {
            echo '<td class="missing">比較不可</td>';
        }

        echo '</tr>';
    }

    echo '</table>';
    echo '</body></html>';
    exit;
});

add_action('admin_post_wp_stocks_webull_test_batch_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_webull_test_batch');

    $host        = 'api.webull.co.jp';
    $path        = '/market-data/stocks/bars/list';
    $symbols_raw = sanitize_text_field($_GET['symbols'] ?? 'AAPL');
    $symbols     = array_values(array_filter(array_map('trim', explode(',', $symbols_raw))));
    $category    = sanitize_text_field($_GET['category'] ?? 'US_STOCK');
    $count       = max(1, min(1200, intval($_GET['count'] ?? 10)));

    header('Content-Type: text/plain; charset=utf-8');

    if (empty($symbols)) {
        echo "銘柄が指定されていません。";
        exit;
    }

    $body = wp_json_encode(array(
        'symbols'            => $symbols,
        'category'           => $category,
        'timespan'           => 'D',
        'count'              => $count,
        'real_time_required' => false,
    ));

    $signed  = wp_stocks_webull_sign_request('POST', $path, array(), $body, $host, 'v3', 'HMAC-SHA1');
    $headers = $signed['headers'];
    $headers['Content-Type'] = 'application/json';
    $headers['Accept']       = 'application/json';

    echo "=== リクエスト ===\n";
    echo "銘柄数: " . count($symbols) . "\n";
    echo "銘柄一覧: " . implode(', ', $symbols) . "\n";
    echo "category: {$category} / count: {$count}\n";
    echo "Body:\n{$body}\n\n";

    $response = wp_remote_post('https://' . $host . $path, array(
        'headers' => $headers,
        'body'    => $body,
        'timeout' => 30,
    ));

    if (is_wp_error($response)) {
        echo "=== 通信エラー ===\n" . $response->get_error_message();
        exit;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);

    echo "=== レスポンス ===\n";
    echo "HTTP Status: {$status_code}\n\n";
    echo $raw_body;

    // --- 診断：リクエストした銘柄のうち、レスポンスに含まれていなかったものを一覧表示 ---
    $decoded          = json_decode($raw_body, true);
    $returned_symbols = array();
    foreach (($decoded['result'] ?? array()) as $item) {
        if (!empty($item['symbol'])) $returned_symbols[] = $item['symbol'];
    }
    $missing = array_diff($symbols, $returned_symbols);

    echo "\n\n=== 診断 ===\n";
    echo "リクエストした銘柄数: " . count($symbols) . "\n";
    echo "レスポンスに含まれていた銘柄数: " . count($returned_symbols) . "\n";
    if (!empty($missing)) {
        echo "レスポンスに含まれていなかった銘柄: " . implode(', ', $missing) . "\n";
    } else {
        echo "リクエストした銘柄は全てレスポンスに含まれています。\n";
    }
    exit;
});

add_action('admin_post_update_company_info', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id    = intval($_GET['id'] ?? 0);
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $symbol   = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $result   = wp_stocks_save_company_info($id, $symbol);
    $redirect = isset($_GET['from']) ? sanitize_text_field($_GET['from']) : 'wp-stocks-manager';
    wp_redirect(admin_url('admin.php?page=' . $redirect . '&message=' . ($result ? 'info_saved' : 'info_error') . '&stock_id=' . $id));
    exit;
});

add_action('admin_post_fetch_stock_news', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id    = intval($_GET['id'] ?? 0);
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) wp_die('銘柄が見つかりません');
    if (isset($_GET['reset']) && $_GET['reset'] === '1')
        $wpdb->delete($wpdb->prefix . 'stock_news', ['stock_id' => $id]);
    $sym_news = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $saved = wp_stocks_fetch_news($id, $sym_news, $stock->name);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&ctab=news&message=' . ($saved !== false ? 'news_saved' : 'news_error')));
    exit;
});

add_action('admin_post_clear_stock_news', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'stock_news', ['stock_id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&ctab=news&message=news_cleared'));
    exit;
});

add_action('admin_post_update_theme_tags', function() {
    wp_stocks_require_admin_action('wp_stocks_theme_tags_nonce');
    global $wpdb;
    $id   = intval($_POST['stock_id']);
    $tags = sanitize_text_field($_POST['theme_tags']);
    $wpdb->update($wpdb->prefix . 'stocks', ['theme_tags' => $tags], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=tags_saved'));
    exit;
});

add_action('admin_post_update_sector', function() {
    wp_stocks_require_admin_action('wp_stocks_sector_nonce');
    global $wpdb;
    $id     = intval($_POST['stock_id']);
    $sector = sanitize_text_field($_POST['stock_sector']);
    if ($id) {
        $wpdb->update($wpdb->prefix . 'stocks', ['sector' => $sector], ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=sector_saved'));
    exit;
});
add_action('admin_post_update_screening_flags', function() {
    wp_stocks_require_admin_action('wp_stocks_screening_nonce');
    global $wpdb;
    $id = intval($_POST['stock_id']);
    $wpdb->update($wpdb->prefix . 'stocks', [
        'screen_op_profit'  => isset($_POST['screen_op_profit'])  ? 1 : 0,
        'screen_net_income' => isset($_POST['screen_net_income']) ? 1 : 0,
        'screen_shikiho'    => isset($_POST['screen_shikiho'])    ? 1 : 0,
    ], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=screening_saved'));
    exit;
});
// ★変更：J-Quantsが市場区分を判定できない銘柄（TOKYO PRO Market等）向けの手動指定用。
// manual_market_nameへのみ書き込み、実効値のmarket列への反映はwp_stocks_jquants_sync_stock()側で
// 「J-Quants値がnullの場合のみ」行う（cronによる自動上書きから分離するための設計）。
add_action('admin_post_update_market_segment', function() {
    wp_stocks_require_admin_action('wp_stocks_market_segment_nonce');
    global $wpdb;
    $id     = intval($_POST['stock_id']);
    $manual = sanitize_text_field($_POST['manual_market_name'] ?? '');
    if ($id) {
        $update = ['manual_market_name' => $manual];
        // J-Quantsが市場区分を判定できていない銘柄なら、次回cronを待たずその場でmarketへも反映
        $jquants_code = $wpdb->get_var($wpdb->prepare(
            "SELECT jquants_market_code FROM {$wpdb->prefix}stocks WHERE id = %d", $id
        ));
        if (wp_stocks_jquants_market_code_to_label($jquants_code) === null) {
            $update['market'] = $manual;
        }
        $wpdb->update($wpdb->prefix . 'stocks', $update, ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=market_segment_saved'));
    exit;
});

add_action('admin_post_update_sector_override', function() {
    wp_stocks_require_admin_action('wp_stocks_sector_override_nonce');
    global $wpdb;
    $id       = intval($_POST['stock_id']);
    $override = sanitize_text_field($_POST['sector_override'] ?? '');
    $valid_sectors = [];
    foreach (wp_stocks_get_topix17_sector_map() as $topix17_info) {
        foreach ($topix17_info['sectors'] as $s33) $valid_sectors[] = $s33;
    }
    if ($override !== '' && !in_array($override, $valid_sectors, true)) $override = '';
    if ($id) {
        $wpdb->update($wpdb->prefix . 'stocks', ['sector_override' => $override], ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=sector_override_saved'));
    exit;
});

add_action('admin_post_update_stock_name', function() {
    wp_stocks_require_admin_action('wp_stocks_name_nonce');
    global $wpdb;
    $id   = intval($_POST['stock_id']);
    $name = sanitize_text_field($_POST['stock_name']);
    if ($id && !empty($name)) {
        $wpdb->update($wpdb->prefix . 'stocks', ['name' => $name], ['id' => $id]);
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=name_saved'));
    exit;
});

add_action('admin_post_update_stock_code', function() {
    wp_stocks_require_admin_action('wp_stocks_code_nonce');
    global $wpdb;
    $id       = intval($_POST['stock_id']);
    $new_code = strtoupper(sanitize_text_field($_POST['stock_code'] ?? ''));

    if (!$id || empty($new_code)) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=code_error'));
        exit;
    }

    $duplicate = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}stocks WHERE code = %s AND id != %d", $new_code, $id
    ));
    if ($duplicate) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=code_duplicate'));
        exit;
    }

    $wpdb->update($wpdb->prefix . 'stocks', ['code' => $new_code], ['id' => $id]);
    wp_stocks_log('info', 'update_stock_code', $new_code, '銘柄コードを更新しました（stock_id=' . $id . '）');
    wp_redirect(admin_url('admin.php?page=wp-stocks-manager&message=code_saved'));
    exit;
});


add_action('admin_post_download_edinet_codelist', function() {
    wp_stocks_require_admin_action('wp_stocks_edinet_codelist_download_nonce');

    $content = wp_stocks_download_edinet_codelist();
    if (empty($content)) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }
    $n = wp_stocks_save_edinet_codelist_content($content, '自動ダウンロード');
    if ($n === false) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }
    wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_saved&n=' . $n));
    exit;
});

add_action('admin_post_upload_edinet_codelist', function() {
    wp_stocks_require_admin_action('wp_stocks_edinet_codelist_nonce');

    if (empty($_FILES['edinet_codelist_file']['tmp_name'])) {
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }

    $tmp_path  = $_FILES['edinet_codelist_file']['tmp_name'];
    $orig_name = $_FILES['edinet_codelist_file']['name'] ?? '';
    $content   = '';

    if (preg_match('/\.zip$/i', $orig_name)) {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp_path) === true) {
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
                $phar = new PharData($tmp_path);
                foreach (new RecursiveIteratorIterator($phar) as $file) {
                    if (stripos($file->getPathname(), '.csv') !== false) {
                        $content = file_get_contents($file->getPathname());
                        break;
                    }
                }
            } catch (Throwable $e) {
                wp_stocks_log('error', 'edinet_codelist', 'ALL', 'ZIP展開失敗: ' . $e->getMessage());
            }
        }
    } else {
        $content = file_get_contents($tmp_path);
    }

    if (empty($content)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'アップロードされたファイルからCSVを読み取れませんでした');
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }

    $parsed = wp_stocks_parse_edinet_codelist_csv($content);
    $map    = $parsed['edinet']  ?? [];
    $decmap = $parsed['decdate'] ?? [];
    if (empty($map)) {
        wp_stocks_log('error', 'edinet_codelist', 'ALL', 'CSVのパースに失敗、または0件でした（フォーマットを確認してください）');
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_error'));
        exit;
    }

    update_option('wp_stocks_edinet_codelist', $map, false);
    update_option('wp_stocks_edinet_decdate_map', $decmap, false);
    update_option('wp_stocks_edinet_codelist_updated_at', current_time('mysql'));
    wp_stocks_log('info', 'edinet_codelist', 'ALL', 'EDINETコードリストを更新しました（' . count($map) . '件、決算日情報' . count($decmap) . '件）');

    wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=edinet_list_saved&n=' . count($map)));
    exit;
});

add_action('admin_post_fetch_financials', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_fin_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $result = wp_stocks_fetch_financials($stock_id, $stock->code);
    $from   = sanitize_text_field($_GET['from'] ?? 'company');
    if ($from === 'finance') {
        $redirect = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&message=' . ($result ? 'fin_saved' : 'fin_error'));
    } else {
        $redirect = admin_url('admin.php?page=wp-stocks-manager&message=' . ($result ? 'fin_saved' : 'fin_error'));
    }
    wp_redirect($redirect);
    exit;
});
add_action('admin_post_fetch_quarterly_financials', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_qfin_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $symbol = ($stock->currency ?? 'JPY') === 'USD' ? $stock->code : $stock->code . '.T';
    $result = wp_stocks_fetch_quarterly_financials($stock_id, $symbol);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=quarterly&message=' . ($result ? 'fin_saved' : 'fin_error')));
    exit;
});
add_action('admin_post_fetch_quarterly_financials_edgar', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_qfin_edgar_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $result = wp_stocks_fetch_quarterly_financials_edgar($stock_id, $stock->code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=quarterly&message=' . ($result ? 'fin_saved' : 'fin_error')));
    exit;
});
add_action('admin_post_fetch_quarterly_financials_jquants', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_qfin_jquants_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $records = wp_stocks_jquants_get_fins_records($stock->code);
    $result = $records !== false ? wp_stocks_jquants_save_quarterly_financials($stock_id, $records) : false;
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=quarterly&message=' . ($result ? 'fin_saved' : 'fin_error')));
    exit;
});
add_action('admin_post_wp_stocks_edgar_test_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_edgar_test');
    $symbol   = sanitize_text_field($_GET['symbol'] ?? 'AAPL');
    $stock_id = intval($_GET['stock_id'] ?? 0);

    header('Content-Type: text/plain; charset=utf-8');

    $cik = wp_stocks_sec_get_cik_for_symbol($symbol);
    if ($cik === false) {
        echo "CIKが見つかりませんでした: {$symbol}\n";
        exit;
    }
    echo "Symbol: {$symbol}\nCIK: {$cik}\n\n";

    $facts = wp_stocks_sec_get_companyfacts($cik);
    if ($facts === false) {
        echo "companyfactsの取得に失敗しました（詳細はログを確認してください）\n";
        exit;
    }

    static $revenue_tags = array(
        'Revenues', 'RevenueFromContractWithCustomerExcludingAssessedTax',
        'RevenueFromContractWithCustomerIncludingAssessedTax', 'SalesRevenueNet',
        'SalesRevenueGoodsNet', 'SalesRevenueServicesNet',
    );
    static $net_income_tags = array('NetIncomeLoss', 'ProfitLoss');

    $revenue    = wp_stocks_sec_extract_quarterly($facts, $revenue_tags);
    $net_income = wp_stocks_sec_extract_quarterly($facts, $net_income_tags);

    $q4_rev_count = wp_stocks_sec_derive_q4($revenue['facts'], wp_stocks_sec_extract_annual($facts, $revenue_tags));
    $q4_ni_count  = wp_stocks_sec_derive_q4($net_income['facts'], wp_stocks_sec_extract_annual($facts, $net_income_tags));

    // companyfactsの生JSONはここで解放（KOのような報告履歴が長い企業はサイズが大きく、
    // 保持したままDB保存処理に入るとメモリを圧迫して致命的エラーの原因になるため）
    unset($facts);

    if ($stock_id > 0) {
        $result = wp_stocks_sec_save_quarterly_facts($stock_id, $symbol, $revenue, $net_income);
        echo "DB保存結果: " . ($result ? '成功' : '失敗') . " (stock_id={$stock_id}, source=edgar)\n\n";

        // 実際にDBへ保存された内容を読み戻して表示（書き込みそのものの実証確認）
        global $wpdb;
        $saved_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT period_end, revenue, net_income, created_at FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'edgar' ORDER BY period_end DESC",
            $stock_id
        ));
        echo "DB実読み戻し（stock_quarterly_financials, source=edgar, stock_id={$stock_id}）: " . count($saved_rows) . "件\n";
        printf("%-12s %18s %18s %s\n", '期末日', 'revenue', 'net_income', '保存日時');
        foreach ($saved_rows as $row) {
            printf("%-12s %17s  %17s  %s\n",
                $row->period_end,
                $row->revenue !== null ? number_format($row->revenue) : '-',
                $row->net_income !== null ? number_format($row->net_income) : '-',
                $row->created_at ?? ''
            );
        }
        echo "\n";
    } else {
        echo "(stock_id未指定のためDB保存はスキップ。保存するにはURLに &stock_id=XX を追加してください)\n\n";
    }

    echo "採用タグ: revenue=" . implode(',', $revenue['tags_used']) . " / net_income=" . implode(',', $net_income['tags_used']) . "\n";
    echo "Q4逆算件数: revenue={$q4_rev_count} / net_income={$q4_ni_count}\n\n";

    $period_ends = array_unique(array_merge(array_keys($revenue['facts']), array_keys($net_income['facts'])));
    rsort($period_ends);
    $period_ends = array_slice($period_ends, 0, 8);

    printf("%-12s %18s %18s\n", '期末日', 'revenue', 'net_income');
    foreach ($period_ends as $end) {
        $rev = $revenue['facts'][$end]['val'] ?? null;
        $ni  = $net_income['facts'][$end]['val'] ?? null;
        $rev_mark = !empty($revenue['facts'][$end]['derived']) ? '*' : ' ';
        $ni_mark  = !empty($net_income['facts'][$end]['derived']) ? '*' : ' ';
        printf("%-12s %17s%s %17s%s\n", $end, $rev !== null ? number_format($rev) : '-', $rev_mark, $ni !== null ? number_format($ni) : '-', $ni_mark);
    }
    echo "\n(* は年次-（Q1+Q2+Q3）による逆算値)\n";
    exit;
});

add_action('admin_post_wp_stocks_fmp_test_fetch', function() {
    wp_stocks_require_admin_action('wp_stocks_fmp_test');
    $symbol = sanitize_text_field($_GET['symbol'] ?? 'AAPL');

    header('Content-Type: text/plain; charset=utf-8');

    echo "Symbol: {$symbol}\n\n";

    $ratios = wp_stocks_fmp_get_ratios($symbol);
    if ($ratios === false) {
        echo "FMPからの比率取得に失敗しました（APIキー未設定、または通信エラー。詳細はログを確認してください）\n";
    } else {
        echo "--- FMP比率（ratios-ttm / key-metrics-ttm）---\n";
        foreach ($ratios as $k => $v) {
            echo str_pad($k, 20) . ": {$v}\n";
        }
    }

    echo "\n--- 次回決算予定日（earnings-calendar、全銘柄まとめてtransientキャッシュ）---\n";
    $earnings_dates = wp_stocks_fmp_get_earnings_dates();
    echo isset($earnings_dates[$symbol]) ? "{$symbol}: {$earnings_dates[$symbol]}\n" : "{$symbol}: 見つかりませんでした（90日以内に予定なし、またはキャッシュ未更新の可能性）\n";
    echo "（取得件数：全" . count($earnings_dates) . "銘柄）\n";

    echo "\n--- wp_stocks_get_company_info()統合後の結果（実際に保存される値）---\n";
    $info = wp_stocks_get_company_info($symbol);
    if ($info === false) {
        echo "wp_stocks_get_company_info()が失敗しました\n";
    } else {
        foreach ($info as $k => $v) {
            echo str_pad($k, 20) . ": " . (is_null($v) ? 'null' : $v) . "\n";
        }
    }
    exit;
});

add_action('admin_post_diagnose_half_year', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_diag_half_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    wp_stocks_diagnose_half_year_report($stock_id, $stock->code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=half&message=diag_done'));
    exit;
});
add_action('admin_post_fetch_half_year_financials', function() {
    $stock_id = intval($_GET['stock_id'] ?? 0);
    wp_stocks_require_admin_action('wp_stocks_fetch_half_' . $stock_id);
    global $wpdb;
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id));
    if (!$stock) wp_die('銘柄が見つかりません');
    $result = wp_stocks_fetch_half_year_financials($stock_id, $stock->code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $stock_id . '&ctab=finance&ftab=half&message=' . ($result ? 'fin_saved' : 'fin_error')));
    exit;
});

add_action('admin_post_toggle_watchlist', function() {
    wp_stocks_require_admin_action('wp_stocks_watchlist_nonce');
    global $wpdb;
    $id      = intval($_POST['stock_id']);
    $current = intval($wpdb->get_var($wpdb->prepare(
        "SELECT is_watchlist FROM {$wpdb->prefix}stocks WHERE id = %d", $id
    )));
    $wpdb->update($wpdb->prefix . 'stocks', ['is_watchlist' => $current ? 0 : 1], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=watchlist_saved'));
    exit;
});

add_action('admin_post_update_manual_forecast_eps', function() {
    wp_stocks_require_admin_action('wp_stocks_manual_forecast_eps_nonce');
    global $wpdb;
    $id = intval($_POST['stock_id']);
    // 空欄は0として保存（0はwp_stocks_get_forecast_eps_growth側で「未入力」扱いになりJ-Quants値にフォールバックする）
    $manual_forecast_eps         = floatval($_POST['manual_forecast_eps'] ?? 0);
    $manual_next_fy_forecast_eps = floatval($_POST['manual_next_fy_forecast_eps'] ?? 0);
    $wpdb->update($wpdb->prefix . 'stocks', [
        'manual_forecast_eps'         => $manual_forecast_eps,
        'manual_next_fy_forecast_eps' => $manual_next_fy_forecast_eps,
    ], ['id' => $id]);

    // 保存直後にPEG等を再計算して反映する（次回cronを待たせない）
    $stock = $wpdb->get_row($wpdb->prepare("SELECT code, currency FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if ($stock) {
        $symbol = ($stock->currency === 'USD') ? $stock->code : $stock->code . '.T';
        wp_stocks_save_company_info($id, $symbol);
    }

    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=manual_forecast_eps_saved'));
    exit;
});

add_action('admin_post_update_memo', function() {
    wp_stocks_require_admin_action('wp_stocks_memo_nonce');
    global $wpdb;
    $id   = intval($_POST['stock_id']);
    $memo = sanitize_textarea_field($_POST['memo']);
    $wpdb->update($wpdb->prefix . 'stocks', ['memo' => $memo], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=memo_saved'));
    exit;
});

add_action('admin_post_update_shikiho', function() {
    wp_stocks_require_admin_action('wp_stocks_shikiho_nonce');
    global $wpdb;
    $id      = intval($_POST['stock_id']);
    $shikiho = wp_stocks_sanitize_shikiho_text($_POST['shikiho']);
    $update_data = [
        'shikiho'            => $shikiho,
        'shikiho_updated_at' => current_time('mysql'),
    ];
    // 業種の自動判定はJ-Quants（equities/master）に一本化。四季報の[]内テキストからの抽出は廃止。
    $wpdb->update($wpdb->prefix . 'stocks', $update_data, ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=shikiho_saved'));
    exit;
});

add_action('admin_post_update_ai_analysis', function() {
    wp_stocks_require_admin_action('wp_stocks_ai_analysis_nonce');
    global $wpdb;
    $id          = intval($_POST['stock_id']);
    $ai_analysis = sanitize_textarea_field($_POST['ai_analysis']);
    $wpdb->update($wpdb->prefix . 'stocks', [
        'ai_analysis'            => $ai_analysis,
        'ai_analysis_updated_at' => current_time('mysql'),
    ], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=ai_analysis_saved'));
    exit;
});

add_action('admin_post_save_ai_history', function() {
    wp_stocks_require_admin_action('wp_stocks_ai_history_nonce');
    global $wpdb;
    $id       = intval($_POST['stock_id']);
    $raw_data = sanitize_textarea_field($_POST['pipe_data']);
    if (empty($raw_data)) wp_die('データが空です');
    error_log('AI HISTORY SAVE: stock_id=' . $id . ' data=' . substr($raw_data, 0, 50));

    // パイプ区切りをパース
    $parts = explode('|', $raw_data);
    $insert_result = $wpdb->insert($wpdb->prefix . 'stock_ai_history', [
        'stock_id'       => $id,
        'analysis_date'  => current_time('Y-m-d'),
        'raw_data'       => $raw_data,
        'valuation'      => $parts[3]  ?? '',
        'financial_health'=> $parts[4] ?? '',
        'growth'         => $parts[5]  ?? '',
        'market_status'  => $parts[6]  ?? '',
        'risk_factor'    => $parts[7]  ?? '',
        'catalyst'       => $parts[8]  ?? '',
        'dividend_eval'  => $parts[9]  ?? '',
        'fair_price'     => $parts[10] ?? '',
        'short_outlook'  => $parts[11] ?? '',
        'mid_outlook'    => $parts[12] ?? '',
        'total_score'    => $parts[13] ?? '',
        'comment'        => $parts[14] ?? '',
        'created_at'     => current_time('mysql'),
    ]);
    error_log('AI HISTORY INSERT result=' . var_export($insert_result, true) . ' error=' . $wpdb->last_error);
    wp_redirect(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id . '&message=ai_saved'));
    exit;
});

add_action('admin_post_move_to_portfolio', function() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    $id = intval($_GET['id'] ?? 0);
    wp_redirect(admin_url('admin.php?page=wp-stocks-portfolio&action=edit_purchase&stock_id=' . $id));
    exit;
});

add_action('admin_post_fetch_fund_price', function() {
    wp_stocks_require_admin_action('wp_stocks_action_' . intval($_GET['id'] ?? 0));
    global $wpdb;
    $id   = intval($_GET['id'] ?? 0);
    $fund = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stock_funds WHERE id = %d", $id));
    if (!$fund) wp_die('ファンドが見つかりません');
    $result = wp_stocks_save_fund_price($id, $fund->fund_code);
    wp_redirect(admin_url('admin.php?page=wp-stocks-funds&message=' . ($result ? 'price_saved' : 'price_error')));
    exit;
});

add_action('admin_post_move_to_watch', function() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    if ($id) $wpdb->update($wpdb->prefix . 'stocks', ['status' => 'watch', 'purchase_price' => 0, 'purchase_qty' => 0], ['id' => $id]);
    wp_redirect(admin_url('admin.php?page=wp-stocks-portfolio'));
    exit;
});

add_action('admin_post_backfill_all_prices', function() {
    wp_stocks_require_admin_action('wp_stocks_backfill_nonce');
    set_time_limit(60);
    global $wpdb;

    $batch_size     = 15;
    $offset         = intval($_GET['offset'] ?? 0);
    $total_inserted = intval($_GET['total'] ?? 0);

    $stocks = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stocks ORDER BY id ASC LIMIT %d OFFSET %d",
        $batch_size, $offset
    ));

    foreach ($stocks as $s) {
        $sym = ($s->currency ?? 'JPY') === 'USD' ? $s->code : $s->code . '.T';
        $total_inserted += wp_stocks_backfill_price_history($s->id, $sym, 30);
        usleep(300000); // 0.3秒
    }

    $done = count($stocks) < $batch_size;

    if ($done) {
        wp_stocks_log('info', 'backfill_all', 'ALL', "過去30日分の株価バックフィル完了：{$total_inserted}件挿入");
        wp_redirect(admin_url('admin.php?page=wp-stocks-settings&message=backfill_done&n=' . $total_inserted));
        exit;
    }

    // まだ続きがある → 手動で次に進むボタンを表示
    $next_offset = $offset + $batch_size;
    $next_url = wp_nonce_url(
        admin_url('admin-post.php?action=backfill_all_prices&offset=' . $next_offset . '&total=' . $total_inserted),
        'wp_stocks_backfill_nonce'
    );
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;">';
    echo '<h2>バックフィル処理中…</h2>';
    echo '<p>' . $next_offset . '件目まで処理しました（累計挿入：' . $total_inserted . '件）。</p>';
    echo '<a href="' . esc_url($next_url) . '" style="display:inline-block;padding:10px 24px;background:#0073aa;color:#fff;text-decoration:none;border-radius:4px;font-weight:bold;">次の' . $batch_size . '件を処理 →</a>';
    echo '</div>';
    exit;
});

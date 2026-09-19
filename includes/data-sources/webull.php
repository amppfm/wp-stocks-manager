<?php
/**
 * WP Stocks Manager — Webull OpenAPI 連携
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

/**
 * Webull OpenAPI 署名生成ヘルパー
 * 公式ドキュメント(developer.webull.co.jp/apis/docs/authentication/signature)の
 * 検証用サンプルと一致することを確認済みのロジック。
 *
 * @param string $method       'GET' | 'POST' など(署名自体には未使用だが呼び出し側の整理用)
 * @param string $path         リクエストパス(クエリを含まない)
 * @param array  $query_params クエリパラメータの連想配列(urlencodeしない生の値)
 * @param string $body         POSTボディの生JSON文字列。GETや空ボディなら ''
 * @param string $host         例: 'api.webull.co.jp'
 * @return array ['headers' => [...送信すべき全ヘッダー...]]
 */
function wp_stocks_webull_sign_request($method, $path, $query_params, $body, $host, $api_version = 'v2', $algorithm = 'HMAC-SHA1') {
    $app_key    = get_option('wp_stocks_webull_app_key', '');
    $app_secret = get_option('wp_stocks_webull_app_secret', '');

    $timestamp = gmdate('Y-m-d\TH:i:s\Z'); // UTC, RFC3339
    $nonce     = bin2hex(random_bytes(16));
    $version   = '1.0';

    $sign_headers = array(
        'host'                  => $host,
        'x-app-key'             => $app_key,
        'x-signature-algorithm' => $algorithm,
        'x-signature-nonce'     => $nonce,
        'x-signature-version'   => $version,
        'x-timestamp'           => $timestamp,
    );

    $map = array_merge($query_params, $sign_headers);
    ksort($map, SORT_STRING);

    $pairs = array();
    foreach ($map as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $s1 = implode('&', $pairs);

    if ($body === '' || $body === null) {
        $s3 = $path . '&' . $s1;
    } else {
        $s2 = strtoupper(md5($body));
        $s3 = $path . '&' . $s1 . '&' . $s2;
    }

    $sign_key  = $app_secret . '&';
    // 署名対象文字列(s3)はRFC3986準拠でURLエンコードしてからHMACに渡す必要がある
    // (公式ドキュメントの確定仕様。ここが抜けていたのが401エラーの根本原因だった)
    $encoded_sign_string = rawurlencode($s3);
    $signature = base64_encode(hash_hmac(strtolower(str_replace('HMAC-', '', $algorithm)), $encoded_sign_string, $sign_key, true));

    return array(
        'headers' => array(
            'x-app-key'             => $app_key,
            'x-timestamp'           => $timestamp,
            'x-signature-algorithm' => $algorithm,
            'x-signature-version'   => $version,
            'x-signature-nonce'     => $nonce,
            'x-version'             => $api_version,
            'x-signature'           => $signature,
            'host'                  => $host,
        ),
    );
}

/**
 * Webullの認証エラー（App Secret期限切れ等）を検知した際に、
 * 最終発生日時と詳細を記録するヘルパー。
 * HTTP 401/403、またはレスポンス本文に認証エラーを示すキーワードが
 * 含まれる場合に「認証エラーの可能性あり」として記録する。
 *
 * @param int    $status_code
 * @param string $raw_body
 * @param string $context ログ・保存用の識別子（例: 'webull_daily_bars:AAPL'）
 * @return bool 認証エラーと判定したかどうか
 */
function wp_stocks_webull_maybe_flag_auth_error($status_code, $raw_body, $context) {
    $status_code   = intval($status_code);
    $is_auth_error = ($status_code === 401 || $status_code === 403);
    if (!$is_auth_error && !empty($raw_body)) {
        if (preg_match('/(invalid[_ ]?signature|unauthoriz|expired|invalid[_ ]?app[_ ]?secret|invalid[_ ]?key)/i', (string) $raw_body)) {
            $is_auth_error = true;
        }
    }
    if ($is_auth_error) {
        update_option('wp_stocks_webull_last_auth_error_at', current_time('mysql'));
        update_option('wp_stocks_webull_last_auth_error_detail', $context . ' / HTTP ' . $status_code . ' / ' . substr((string) $raw_body, 0, 200));
        wp_stocks_log('error', 'webull_auth_error', $context, 'Webull認証エラーの可能性を検知しました（App Secret期限切れ等）：HTTP ' . $status_code);
    }
    return $is_auth_error;
}

/**
 * Webullへのリクエストが成功した際に、認証エラーの記録をクリアする。
 */
function wp_stocks_webull_clear_auth_error() {
    if (get_option('wp_stocks_webull_last_auth_error_at', '') !== '') {
        delete_option('wp_stocks_webull_last_auth_error_at');
        delete_option('wp_stocks_webull_last_auth_error_detail');
    }
}

/**
 * Webullの日足バッチ取得APIから指定銘柄の日足を取得し、
 * Yahoo側 wp_stocks_get_ohlcv() と同じ形式（date/open/high/low/close/volume）の配列で返す。
 * 失敗時は false を返す。
 *
 * @param string $symbol   例: 'AAPL', '7203'
 * @param string $category 例: 'US_STOCK', 'JP_STOCK'
 * @param int    $count    取得件数（デフォルト30、最大1200）
 * @return array|false
 */
function wp_stocks_webull_fetch_daily_bars($symbol, $category, $count = 30) {
    $host = 'api.webull.co.jp';
    $path = '/market-data/stocks/bars/list';
    $body = wp_json_encode(array(
        'symbols'            => array($symbol),
        'category'           => $category,
        'timespan'           => 'D',
        'count'              => intval($count),
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

    if (is_wp_error($response)) {
        wp_stocks_log('error', 'webull_daily_bars', $symbol, '通信エラー: ' . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $raw_body    = wp_remote_retrieve_body($response);
    if ($status_code !== 200) {
        wp_stocks_webull_maybe_flag_auth_error($status_code, $raw_body, 'webull_daily_bars:' . $symbol);
        wp_stocks_log('error', 'webull_daily_bars', $symbol, 'HTTPエラー: ' . $status_code . ' / ' . substr($raw_body, 0, 300));
        return false;
    }
    wp_stocks_webull_clear_auth_error();

    $decoded = json_decode($raw_body, true);
    $bars    = $decoded['result'][0]['result'] ?? null;
    if (!is_array($bars) || empty($bars)) {
        wp_stocks_log('error', 'webull_daily_bars', $symbol, 'レスポンスに日足データが含まれていません: ' . substr($raw_body, 0, 300));
        return false;
    }

    $data = array();
    foreach ($bars as $bar) {
        // 'time' は例: "2026-09-11T04:00:00.000+0000" 形式。
        // 日付部分（先頭10文字）がそのまま取引日ラベルに対応しているため、そのまま使用する。
        $date = substr($bar['time'] ?? '', 0, 10);
        if (empty($date)) continue;
        $data[] = array(
            'date'   => $date,
            'open'   => floatval($bar['open']   ?? 0),
            'high'   => floatval($bar['high']   ?? 0),
            'low'    => floatval($bar['low']    ?? 0),
            'close'  => floatval($bar['close']  ?? 0),
            'volume' => intval($bar['volume']   ?? 0),
        );
    }

    // Webullは新しい順で返ってくるため、Yahoo側（古い順）に合わせて昇順に並び替える
    usort($data, function($a, $b) { return strcmp($a['date'], $b['date']); });

    return $data;
}

/**
 * 複数銘柄の日足をWebullバッチAPIからcurl_multiで並列取得する。
 * 1リクエストにつき$chunk_size銘柄まで詰め込み、$concurrency件ずつ並行送信、
 * ラウンド間に$wait_between_rounds秒のウェイトを挟む。
 *
 * @param array  $symbols             取得したい銘柄コードの配列（同一categoryのみ対応）
 * @param string $category            例: 'US_STOCK'
 * @param int    $count               1銘柄あたりの取得件数
 * @param int    $chunk_size          1リクエストに詰める銘柄数（Webull上限は100だが安全のため既定20）
 * @param int    $concurrency         curl_multiで同時に投げるリクエスト数
 * @param int    $wait_between_rounds ラウンド間のウェイト秒数
 * @return array [$symbol => [['date'=>..,'open'=>..,'high'=>..,'low'=>..,'close'=>..,'volume'=>..], ...]]
 *               取得できなかった銘柄はキーごと含まれない。
 */
function wp_stocks_webull_daily_bars_parse_response($raw_body) {
    $decoded = json_decode($raw_body, true);
    $entries = $decoded['result'] ?? array();
    $parsed  = array();
    foreach ($entries as $item) {
        $sym  = $item['symbol'] ?? null;
        $bars = $item['result'] ?? null;
        if (!$sym || !is_array($bars)) continue;

        $data = array();
        foreach ($bars as $bar) {
            $date = substr($bar['time'] ?? '', 0, 10);
            if (empty($date)) continue;
            $data[] = array(
                'date'   => $date,
                'open'   => floatval($bar['open']   ?? 0),
                'high'   => floatval($bar['high']   ?? 0),
                'low'    => floatval($bar['low']    ?? 0),
                'close'  => floatval($bar['close']  ?? 0),
                'volume' => intval($bar['volume']   ?? 0),
            );
        }
        usort($data, function($a, $b) { return strcmp($a['date'], $b['date']); });
        if (!empty($data)) $parsed[$sym] = $data;
    }
    return $parsed;
}

/**
 * Webullのバッチ日足取得APIから、INVALID_SYMBOLエラーで指摘された銘柄を検出する。
 * 例: {"error_code":"INVALID_SYMBOL","message":"The symbols does not exist in the category. [ko].","status":417}
 *
 * @return array 除外すべき銘柄コードの配列（見つからなければ空配列）
 */
function wp_stocks_webull_extract_invalid_symbols($raw_body) {
    $decoded = json_decode($raw_body, true);
    if (($decoded['error_code'] ?? '') !== 'INVALID_SYMBOL' || empty($decoded['message'])) {
        return array();
    }
    if (preg_match('/\[(.*?)\]/', $decoded['message'], $m)) {
        return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
    }
    return array();
}

function wp_stocks_webull_fetch_daily_bars_multi($symbols, $category, $count = 130, $chunk_size = 20, $concurrency = 4, $wait_between_rounds = 1) {
    if (!function_exists('curl_multi_init')) {
        wp_stocks_log('error', 'webull_batch_fetch', 'ALL', 'curl_multi_initが利用できない環境のため、バッチ取得を実行できません');
        return array();
    }

    $host    = 'api.webull.co.jp';
    $path    = '/market-data/stocks/bars/list';
    $symbols = array_values(array_unique(array_filter($symbols)));
    if (empty($symbols)) return array();

    $chunks       = array_chunk($symbols, max(1, intval($chunk_size)));
    $results      = array();
    $retry_chunks = array(); // INVALID_SYMBOLで弾かれた銘柄を除いた再試行対象チャンク

    foreach (array_chunk($chunks, max(1, intval($concurrency))) as $round) {
        $mh           = curl_multi_init();
        $curl_entries = array(); // (int) $ch => array('symbols' => [...])

        foreach ($round as $chunk_symbols) {
            $body = wp_json_encode(array(
                'symbols'            => array_values($chunk_symbols),
                'category'           => $category,
                'timespan'           => 'D',
                'count'              => intval($count),
                'real_time_required' => false,
            ));

            $signed        = wp_stocks_webull_sign_request('POST', $path, array(), $body, $host, 'v3', 'HMAC-SHA1');
            $headers_assoc = $signed['headers'];
            $headers_assoc['Content-Type'] = 'application/json';
            $headers_assoc['Accept']       = 'application/json';
            $headers_list = array();
            foreach ($headers_assoc as $hk => $hv) $headers_list[] = $hk . ': ' . $hv;

            $ch = curl_init('https://' . $host . $path);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers_list);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            curl_multi_add_handle($mh, $ch);
            $curl_entries[(int) $ch] = array('handle' => $ch, 'symbols' => $chunk_symbols);
        }

        $running = null;
        do {
            $mrc = curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh);
        } while ($running > 0);

        foreach ($curl_entries as $entry) {
            $ch          = $entry['handle'];
            $raw_body    = curl_multi_getcontent($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error  = curl_error($ch);

            if ($curl_error) {
                wp_stocks_log('error', 'webull_batch_fetch', implode(',', $entry['symbols']), 'curlエラー: ' . $curl_error);
            } elseif ($status_code !== 200) {
                $invalid_symbols = wp_stocks_webull_extract_invalid_symbols($raw_body);
                if (!empty($invalid_symbols)) {
                    $remaining = array_values(array_diff($entry['symbols'], $invalid_symbols));
                    wp_stocks_log('error', 'webull_batch_fetch', implode(',', $invalid_symbols), 'INVALID_SYMBOLのため除外し、同一チャンクの残り銘柄で再試行します：' . implode(',', $remaining));
                    if (!empty($remaining)) $retry_chunks[] = $remaining;
                } else {
                    wp_stocks_webull_maybe_flag_auth_error($status_code, $raw_body, 'webull_batch_fetch:' . implode(',', $entry['symbols']));
                    wp_stocks_log('error', 'webull_batch_fetch', implode(',', $entry['symbols']), 'HTTPエラー: ' . $status_code . ' / ' . substr((string) $raw_body, 0, 300));
                }
            } else {
                wp_stocks_webull_clear_auth_error();
                $results = array_merge($results, wp_stocks_webull_daily_bars_parse_response($raw_body));
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        if ($wait_between_rounds > 0) sleep($wait_between_rounds);
    }

    // --- INVALID_SYMBOL除外リトライ：問題の銘柄を除いた残りだけで再送信する ---
    foreach ($retry_chunks as $retry_symbols) {
        if (empty($retry_symbols)) continue;

        $retry_body = wp_json_encode(array(
            'symbols'            => array_values($retry_symbols),
            'category'           => $category,
            'timespan'           => 'D',
            'count'              => intval($count),
            'real_time_required' => false,
        ));
        $retry_signed  = wp_stocks_webull_sign_request('POST', $path, array(), $retry_body, $host, 'v3', 'HMAC-SHA1');
        $retry_headers = $retry_signed['headers'];
        $retry_headers['Content-Type'] = 'application/json';
        $retry_headers['Accept']       = 'application/json';

        $response = wp_remote_post('https://' . $host . $path, array(
            'headers' => $retry_headers,
            'body'    => $retry_body,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $retry_symbols), '再試行時の通信エラー: ' . $response->get_error_message());
            continue;
        }

        $retry_status   = wp_remote_retrieve_response_code($response);
        $retry_raw_body = wp_remote_retrieve_body($response);

        if ($retry_status !== 200) {
            // 再試行でも別のINVALID_SYMBOLに当たった場合は、さらに除外して1回だけ追加リトライする
            $second_invalid = wp_stocks_webull_extract_invalid_symbols($retry_raw_body);
            if (!empty($second_invalid)) {
                $second_remaining = array_values(array_diff($retry_symbols, $second_invalid));
                wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $second_invalid), '再試行でも別のINVALID_SYMBOLを検出、さらに除外して再々試行します：' . implode(',', $second_remaining));
                if (!empty($second_remaining)) {
                    $second_body = wp_json_encode(array(
                        'symbols'            => array_values($second_remaining),
                        'category'           => $category,
                        'timespan'           => 'D',
                        'count'              => intval($count),
                        'real_time_required' => false,
                    ));
                    $second_signed  = wp_stocks_webull_sign_request('POST', $path, array(), $second_body, $host, 'v3', 'HMAC-SHA1');
                    $second_headers = $second_signed['headers'];
                    $second_headers['Content-Type'] = 'application/json';
                    $second_headers['Accept']       = 'application/json';
                    $second_response = wp_remote_post('https://' . $host . $path, array(
                        'headers' => $second_headers,
                        'body'    => $second_body,
                        'timeout' => 30,
                    ));
                    if (!is_wp_error($second_response) && wp_remote_retrieve_response_code($second_response) === 200) {
                        $results = array_merge($results, wp_stocks_webull_daily_bars_parse_response(wp_remote_retrieve_body($second_response)));
                        wp_stocks_log('info', 'webull_batch_fetch_retry', implode(',', $second_remaining), '再々試行に成功しました');
                    } else {
                        wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $second_remaining), '再々試行も失敗しました。手動確認が必要です');
                    }
                }
            } else {
                wp_stocks_webull_maybe_flag_auth_error($retry_status, $retry_raw_body, 'webull_batch_fetch_retry:' . implode(',', $retry_symbols));
                wp_stocks_log('error', 'webull_batch_fetch_retry', implode(',', $retry_symbols), '再試行も失敗: HTTP ' . $retry_status . ' / ' . substr($retry_raw_body, 0, 300));
            }
            continue;
        }

        wp_stocks_webull_clear_auth_error();
        $results = array_merge($results, wp_stocks_webull_daily_bars_parse_response($retry_raw_body));
        wp_stocks_log('info', 'webull_batch_fetch_retry', implode(',', $retry_symbols), 'INVALID_SYMBOL除外後の再試行に成功しました');
    }

    return $results;
}

/**
 * 米国株全銘柄の日足をWebullからバッチ取得し、
 *   1) stock_prices テーブルへ最新日の価格・前日終値・出来高を保存
 *   2) Yahoo側 wp_stocks_get_ohlcv() が参照するtransientキャッシュへ同じデータを書き込む
 *      （wp_stocks_us_technical_cron が変更なしでこのキャッシュを再利用できる。
 *        Webullで取得できなかった銘柄は次回のget_ohlcv呼び出し時に自動でYahooへフォールバックする）
 *
 * @return array ['ok' => int, 'ng' => int, 'total' => int]
 */
function wp_stocks_webull_batch_update_us_prices() {
    global $wpdb;
    $stocks = $wpdb->get_results("SELECT id, code FROM {$wpdb->prefix}stocks WHERE currency = 'USD'");
    if (!$stocks) return array('ok' => 0, 'ng' => 0, 'total' => 0);

    $symbols = array();
    foreach ($stocks as $s) $symbols[] = $s->code;

    $bars_map = wp_stocks_webull_fetch_daily_bars_multi($symbols, 'US_STOCK', 130, 20, 4, 1);

    $ok = 0;
    $ng = 0;

    foreach ($stocks as $s) {
        $bars = $bars_map[$s->code] ?? null;
        if (!$bars || count($bars) < 2) {
            wp_stocks_log('error', 'webull_batch_update', $s->code, 'バッチ結果に十分な日足データがありませんでした（Yahooへのフォールバックは次回のテクニカル計算時に自動実行されます）');
            $ng++;
            continue;
        }

        // --- stock_prices: 最新日の価格・前日終値・出来高を保存（重複日は上書き） ---
        $latest_bar = end($bars);
        $prev_bar   = ($bars[count($bars) - 2] ?? null);

        $existing_price_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d AND DATE(datetime) = %s",
            $s->id, $latest_bar['date']
        ));
        $price_row = array(
            'price'          => $latest_bar['close'],
            'previous_close' => $prev_bar ? $prev_bar['close'] : null,
            'volume'         => $latest_bar['volume'],
            'datetime'       => $latest_bar['date'] . ' 06:00:00',
        );
        if ($existing_price_id) {
            $wpdb->update($wpdb->prefix . 'stock_prices', $price_row, array('id' => $existing_price_id));
        } else {
            $price_row['stock_id'] = $s->id;
            $wpdb->insert($wpdb->prefix . 'stock_prices', $price_row);
        }

        // --- Yahoo互換のOHLCVキャッシュへ書き込み（wp_stocks_get_ohlcv()と同じtransientキー） ---
        set_transient('wp_stocks_ohlcv_' . md5($s->code), $bars, 6 * HOUR_IN_SECONDS);

        $ok++;
    }

    wp_stocks_log('info', 'webull_batch_update', 'US', "Webullバッチ更新完了：成功{$ok}件 / 失敗{$ng}件（全" . count($stocks) . "銘柄）");

    return array('ok' => $ok, 'ng' => $ng, 'total' => count($stocks));
}

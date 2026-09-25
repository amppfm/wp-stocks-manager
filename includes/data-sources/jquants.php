<?php
if (!defined('ABSPATH')) exit;

// -----------------------------------------------------------
// J-Quants API連携（V2、x-api-keyヘッダー認証）
// -----------------------------------------------------------

function wp_stocks_jquants_get_api_key() {
    return get_option('wp_stocks_jquants_api_key', '');
}

// 銘柄コードから最新のfins/summaryレコードを取得（複数開示がある場合は最新日付を採用）
function wp_stocks_jquants_get_fins_summary($code) {
    $api_key = wp_stocks_jquants_get_api_key();
    if (empty($api_key)) {
        wp_stocks_log('error', 'jquants_fins_summary', $code, 'APIキー未設定');
        return false;
    }

    $url = 'https://api.jquants.com/v2/fins/summary?code=' . urlencode($code);
    $response = wp_remote_get($url, [
        'headers' => ['x-api-key' => $api_key],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'jquants_fins_summary', $code, 'APIエラー: ' . $response->get_error_message());
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);

    // レート制限（429）の場合は少し待って1回だけ再試行する
    if ($status === 429) {
        sleep(5);
        $response = wp_remote_get($url, [
            'headers' => ['x-api-key' => $api_key],
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            wp_stocks_log('error', 'jquants_fins_summary', $code, 'APIエラー（再試行後）: ' . $response->get_error_message());
            return false;
        }
        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
    }

    if ($status !== 200) {
        wp_stocks_log('error', 'jquants_fins_summary', $code, 'HTTPエラー: ' . $status . ' / ' . $raw_body);
        return false;
    }

    $body = json_decode($raw_body, true);
    // レスポンスのトップレベルキー名は実地確認するまで複数候補を許容
	$records = $body['data'] ?? [];
    if (empty($records) || !is_array($records)) {
        wp_stocks_log('error', 'jquants_fins_summary', $code, 'レコードなし: ' . $raw_body);
        return false;
    }

    // 最新開示分を採用（DiscDate + DiscNoで判定）
    usort($records, function($a, $b) {
        $da = ($a['DiscDate'] ?? '') . ($a['DiscNo'] ?? '');
        $db = ($b['DiscDate'] ?? '') . ($b['DiscNo'] ?? '');
        return strcmp($db, $da); // 降順
    });
    $latest = $records[0];

    // ROEは本決算(FY)開示でしか算出されないため、直近の非空値を別途探す
    // （四半期開示のタイミングでは空になるが、それは「未計算」であって「悪化」ではないため
    //   直近の本決算時点の実力値をそのまま採用する）
    $latest_roe = null;
    foreach ($records as $r) {
        if (isset($r['ROE']) && $r['ROE'] !== '') {
            $latest_roe = $r['ROE'];
            break;
        }
    }

    // 空文字列はnullとして扱う（FEPSが期末近くで空になるケースに対応）

    $clean = function($v) {
        return ($v === '' || $v === null) ? null : floatval($v);
    };

    // ★2026-09-25変更：以前はFEPSが空の場合に実績EPSへフォールバックしていたが、
    // これだと「予想が未発表なだけ」なのか「実績が予想として返ってきている」のか区別が
    // つかず紛らわしい。forecast_epsは素直にFEPSのみを返す（フォールバックなし・null許容）。
    // 実績EPSが欲しい場面はepsキーで既に取得できるので、機能が失われるわけではない。
    // UI側で「予想未発表のため実績EPS表示中」等、明示的にラベルを分けて表示する。
    $forecast_eps = $clean($latest['FEPS'] ?? null);

    return [
        'eps'                      => $clean($latest['EPS'] ?? null),
        'forecast_eps'             => $forecast_eps,
        'next_fy_forecast_eps'     => $clean($latest['NxFEPS'] ?? null),
        'bps'                      => $clean($latest['BPS'] ?? null),
        'equity_ratio'             => $clean($latest['EqAR'] ?? null),
        'roe'                      => $clean($latest_roe),
        'forecast_dividend_annual' => $clean($latest['FDivAnn'] ?? null),
        'disc_date'                => $latest['DiscDate'] ?? null,
    ];
}

// -----------------------------------------------------------
// 上場銘柄一覧（equities/master）取得：33業種・市場区分をコード側に切替
// Freeプランでも利用可（fins/summaryと同じ「直近12週間データなし」の制約あり）
//
// ★2026-09-25 修正：Freeプランのレート制限は5コール/分と非常に厳しく、
// 銘柄ごとに個別リクエスト（code指定）していると429が大量発生し実用にならなかった。
// equities/masterはcode省略で「全銘柄情報一覧」を1回のリクエストで取得できる仕様のため、
// 1回だけ全銘柄分を取得してcodeをキーにキャッシュし、以降は銘柄ごとにネットワーク呼び出しを
// 行わない設計に変更（PHPプロセス内のstaticキャッシュ＝1回の同期実行内でのみ有効）。
// -----------------------------------------------------------
function wp_stocks_jquants_get_all_equities_master() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];

    $api_key = wp_stocks_jquants_get_api_key();
    if (empty($api_key)) {
        wp_stocks_log('error', 'jquants_equities_master', 'ALL', 'APIキー未設定');
        return $cache;
    }

    $url = 'https://api.jquants.com/v2/equities/master';
    $args = [
        'headers' => ['x-api-key' => $api_key],
        'timeout' => 30,
    ];
    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'jquants_equities_master', 'ALL', 'APIエラー: ' . $response->get_error_message());
        return $cache;
    }

    $status   = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);

    // レート制限（429）の場合は少し待って1回だけ再試行する
    if ($status === 429) {
        sleep(15);
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            wp_stocks_log('error', 'jquants_equities_master', 'ALL', 'APIエラー（再試行後）: ' . $response->get_error_message());
            return $cache;
        }
        $status   = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
    }

    if ($status !== 200) {
        wp_stocks_log('error', 'jquants_equities_master', 'ALL', 'HTTPエラー: ' . $status . ' / ' . $raw_body);
        return $cache;
    }

    $body    = json_decode($raw_body, true);
    $records = $body['data'] ?? [];
    if (!is_array($records)) {
        wp_stocks_log('error', 'jquants_equities_master', 'ALL', 'レコード形式不正: ' . $raw_body);
        return $cache;
    }

    foreach ($records as $rec) {
        $c = $rec['Code'] ?? null;
        if ($c === null || $c === '') continue;
        $cache[$c] = [
            'sector33_code' => $rec['S33']   ?? null,
            'sector33_name' => $rec['S33Nm'] ?? null,
            'market_code'   => $rec['Mkt']   ?? null,
            'market_name'   => $rec['MktNm'] ?? null,
        ];
    }
    wp_stocks_log('info', 'jquants_equities_master', 'ALL', '全銘柄一覧を一括取得：' . count($cache) . '件');
    return $cache;
}

function wp_stocks_jquants_get_equities_master($code) {
    $all = wp_stocks_jquants_get_all_equities_master();
    // 保存コードが4桁/5桁どちらの形式でも一致するようフォールバックして照合する
    if (isset($all[$code])) return $all[$code];
    if (isset($all[$code . '0'])) return $all[$code . '0'];
    if (strlen($code) === 5 && substr($code, -1) === '0' && isset($all[substr($code, 0, 4)])) {
        return $all[substr($code, 0, 4)];
    }
    wp_stocks_log('error', 'jquants_equities_master', $code, '一括取得結果に該当コードなし');
    return false;
}

// 市場区分コード（Mkt）→ 画面表示用の区分名（既存の「プライム/スタンダード/グロース」表記に合わせる）
// TOKYO PRO Market等、対応外の区分やnullはnullを返し、既存の手入力値を保持させる
function wp_stocks_jquants_market_code_to_label($market_code) {
    $map = [
        '0111' => 'プライム',
        '0112' => 'スタンダード',
        '0113' => 'グロース',
    ];
    return $map[$market_code] ?? null;
}

// -----------------------------------------------------------
// 週次cron用：1銘柄分をJ-Quantsから取得しjquants_*列へ保存
// （manual_*列には一切触れない。手動入力を自動上書きしないための設計）
// -----------------------------------------------------------
function wp_stocks_jquants_sync_stock($stock_id, $code) {
    global $wpdb;

    $data   = wp_stocks_jquants_get_fins_summary($code);
    // equities/masterは全銘柄一括取得＋キャッシュ方式に変更したため、ここでのネットワーク呼び出しは
    // 通常発生しない（初回のみ1回）。よってfins/summaryとの間隔調整は不要になった。
    $master = wp_stocks_jquants_get_equities_master($code);

    if (!$data && !$master) {
        return false;
    }

    $update = [];

    if ($data) {
        $update['jquants_eps']                     = $data['eps'];
        $update['jquants_forecast_eps']             = $data['forecast_eps'];
        $update['jquants_next_fy_forecast_eps']     = $data['next_fy_forecast_eps'];
        $update['jquants_bps']                      = $data['bps'];
        $update['jquants_equity_ratio']              = $data['equity_ratio'];
        $update['jquants_roe']                       = $data['roe'];
        $update['jquants_forecast_dividend_annual']  = $data['forecast_dividend_annual'];
        $update['jquants_disc_date']                 = $data['disc_date'];
    }

    if ($master) {
        $update['jquants_sector33_code'] = $master['sector33_code'];
        $update['jquants_sector33_name'] = $master['sector33_name'];
        $update['jquants_market_code']   = $master['market_code'];
        $update['jquants_market_name']   = $master['market_name'];

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT sector_override, manual_market_name FROM {$wpdb->prefix}stocks WHERE id = %d", $stock_id
        ));

        // 業種：手動指定（sector_override）が無ければJ-Quantsの33業種名を実効sectorへ反映
        // （四季報の[]内自動判定より優先。四季報側はJ-Quants値がnullのときのフォールバックに留める）
        if ((!$existing || empty($existing->sector_override)) && !empty($master['sector33_name'])) {
            $update['sector'] = $master['sector33_name'];
        }

        // 市場区分：J-Quantsで取得できた区分（プライム/スタンダード/グロース）を最優先で反映
        // 取得できない区分（TOKYO PRO Market等）やnullの場合のみ、手動指定（manual_market_name）を
        // フォールバックとして反映する。どちらも無ければ既存値を保持。
        $market_label = wp_stocks_jquants_market_code_to_label($master['market_code'] ?? null);
        if ($market_label !== null) {
            $update['market'] = $market_label;
        } elseif ($existing && !empty($existing->manual_market_name)) {
            $update['market'] = $existing->manual_market_name;
        }
    }

    $update['jquants_updated_at'] = current_time('mysql');

    $wpdb->update($wpdb->prefix . 'stocks', $update, ['id' => $stock_id]);

    return true;
}

// -----------------------------------------------------------
// テスト用エンドポイント（管理画面から手動実行、レスポンス構造確認用）
// -----------------------------------------------------------
add_action('admin_post_wp_stocks_jquants_test_fetch', 'wp_stocks_jquants_test_fetch');
function wp_stocks_jquants_test_fetch() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('wp_stocks_jquants_test'); 

    $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '86970'; // デフォルト: JPX自身

    $api_key = wp_stocks_jquants_get_api_key();
    if (empty($api_key)) {
        wp_die('J-Quants APIキーが未設定です。設定画面から登録してください。');
    }

    $url = 'https://api.jquants.com/v2/fins/summary?code=' . urlencode($code);
    $response = wp_remote_get($url, [
        'headers' => ['x-api-key' => $api_key],
        'timeout' => 15,
    ]);

    header('Content-Type: text/plain; charset=utf-8');
    if (is_wp_error($response)) {
        echo "APIエラー: " . $response->get_error_message();
        exit;
    }

    echo "=== HTTPステータス ===\n";
    echo wp_remote_retrieve_response_code($response) . "\n\n";
    echo "=== 生レスポンス ===\n";
    echo wp_remote_retrieve_body($response) . "\n\n";
    echo "=== wp_stocks_jquants_get_fins_summary() のパース結果 ===\n";
    print_r(wp_stocks_jquants_get_fins_summary($code));
    exit;
	}

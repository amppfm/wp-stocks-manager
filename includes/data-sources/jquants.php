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

	// FEPSが空欄（期末直後で当期予想が確定値に置き換わった状態）の場合、実績EPSにフォールバック
    $forecast_eps = $clean($latest['FEPS'] ?? null);
    if ($forecast_eps === null) {
        $forecast_eps = $clean($latest['EPS'] ?? null);
    }

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
// 週次cron用：1銘柄分をJ-Quantsから取得しjquants_*列へ保存
// （manual_*列には一切触れない。手動入力を自動上書きしないための設計）
// -----------------------------------------------------------
function wp_stocks_jquants_sync_stock($stock_id, $code) {
    global $wpdb;

    $data = wp_stocks_jquants_get_fins_summary($code);
    if (!$data) {
        return false;
    }

    $wpdb->update(
        $wpdb->prefix . 'stocks',
        [
            'jquants_eps'                      => $data['eps'],
            'jquants_forecast_eps'             => $data['forecast_eps'],
            'jquants_next_fy_forecast_eps'     => $data['next_fy_forecast_eps'],
            'jquants_bps'                      => $data['bps'],
            'jquants_equity_ratio'             => $data['equity_ratio'],
            'jquants_roe'                      => $data['roe'],
            'jquants_forecast_dividend_annual' => $data['forecast_dividend_annual'],
            'jquants_updated_at'               => current_time('mysql'),
        ],
        ['id' => $stock_id]
    );

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

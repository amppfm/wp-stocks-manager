<?php
/**
 * WP Stocks Manager — 出口戦略（保有日数・出口サイン）
 *
 * 保有銘柄(status='portfolio')の購入日から保有日数を出し、次の3種類の出口サインを出す。
 *  1. 損切り・利確ライン   : 取得単価に対する損益率
 *  2. 高値からの下落       : 購入日以降の最高値（トレーリング）からの下落率
 *  3. 決算接近・保有日数   : 次回決算日までの日数／保有日数の長さ
 * ％や日数は全銘柄共通で、ポートフォリオ画面の「出口サイン設定」で変更する。
 * 購入日が空欄の銘柄は、保有日数を「-」にし、購入日が必要なサイン（トレーリング・保有日数）は出さない。
 * 売買の指示ではなく、見直しのきっかけを示す目安。
 */

if (!defined('ABSPATH')) exit;

/** 出口サインの設定（全体共通）。未設定ならデフォルト値 */
function wp_stocks_exit_defaults() {
    return [
        'stoploss_pct'  => 8,   // 取得単価からこの％下がったら損切りライン
        'takeprofit_pct'=> 20,  // 取得単価からこの％上がったら利確ライン
        'trailing_pct'  => 10,  // 購入日以降の最高値からこの％下がったらサイン
        'earnings_days' => 7,   // 次回決算日までこの日数以内ならサイン
        'hold_days'     => 90,  // 保有日数がこの日数以上ならサイン
    ];
}

function wp_stocks_exit_get_settings() {
    $d = wp_stocks_exit_defaults();
    $saved = get_option('wp_stocks_exit_settings', []);
    if (!is_array($saved)) $saved = [];
    $out = [];
    foreach ($d as $k => $v) {
        $out[$k] = (isset($saved[$k]) && is_numeric($saved[$k]) && floatval($saved[$k]) > 0) ? floatval($saved[$k]) : $v;
    }
    return $out;
}

/** 設定の保存（POSTの値を検証して保存） */
function wp_stocks_exit_save_settings($post) {
    $d = wp_stocks_exit_defaults();
    $new = [];
    foreach ($d as $k => $v) {
        $val = isset($post['exit_' . $k]) ? floatval($post['exit_' . $k]) : 0;
        $new[$k] = $val > 0 ? $val : $v;
    }
    update_option('wp_stocks_exit_settings', $new);
}

/** 'Y-m-d' の購入日を検証。不正・空なら null */
function wp_stocks_exit_sanitize_date($raw) {
    $raw = trim((string)$raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) return null;
    return $raw;
}

/** 2つの日付（Y-m-d）の日数差（$to - $from）。どちらかが空なら null */
function wp_stocks_exit_days_between($from, $to) {
    if (empty($from) || empty($to)) return null;
    try {
        $a = new DateTime($from, wp_timezone());
        $b = new DateTime($to, wp_timezone());
    } catch (Exception $e) {
        return null;
    }
    $a->setTime(0, 0, 0);
    $b->setTime(0, 0, 0);
    return (int)$a->diff($b)->format('%r%a');
}

/** 保有日数。購入日が空欄・未来日なら null */
function wp_stocks_exit_holding_days($purchase_date) {
    if (empty($purchase_date) || $purchase_date === '0000-00-00') return null;
    $days = wp_stocks_exit_days_between($purchase_date, current_time('Y-m-d'));
    return ($days === null || $days < 0) ? null : $days;
}

/** 購入日以降の最高値（保存済み株価の最大値と取得単価の大きいほう）。購入日が空欄なら null */
function wp_stocks_exit_high_since($stock) {
    global $wpdb;
    $pd = $stock->purchase_date ?? null;
    if (empty($pd) || $pd === '0000-00-00') return null;
    $max = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(price) FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d AND datetime >= %s",
        $stock->id, $pd . ' 00:00:00'
    ));
    $buy = floatval($stock->purchase_price ?? 0);
    $high = max(floatval($max), $buy);
    return $high > 0 ? $high : null;
}

/**
 * 出口サインを返す。
 * 戻り値: [ ['key'=>..., 'level'=>'stop|profit|warn|info', 'label'=>..., 'detail'=>...], ... ]
 */
function wp_stocks_exit_signals($stock, $current_price) {
    $st    = wp_stocks_exit_get_settings();
    $buy   = floatval($stock->purchase_price ?? 0);
    $price = floatval($current_price);
    $out   = [];

    // 1. 損切り・利確ライン（取得単価と現在値が必要）
    if ($buy > 0 && $price > 0) {
        $pct = ($price - $buy) / $buy * 100;
        if ($pct <= -$st['stoploss_pct']) {
            $out[] = ['key' => 'stoploss', 'level' => 'stop', 'label' => '損切りライン',
                'detail' => sprintf('取得単価から%.1f%%（設定 -%s%%）', $pct, rtrim(rtrim(number_format($st['stoploss_pct'], 1), '0'), '.'))];
        } elseif ($pct >= $st['takeprofit_pct']) {
            $out[] = ['key' => 'takeprofit', 'level' => 'profit', 'label' => '利確ライン',
                'detail' => sprintf('取得単価から+%.1f%%（設定 +%s%%）', $pct, rtrim(rtrim(number_format($st['takeprofit_pct'], 1), '0'), '.'))];
        }
    }

    // 2. 高値からの下落（購入日以降の最高値が取得単価を上回っているときだけ。購入日が空欄なら出さない）
    $high = wp_stocks_exit_high_since($stock);
    if ($high !== null && $buy > 0 && $price > 0 && $high > $buy) {
        $dd = ($high - $price) / $high * 100;
        if ($dd >= $st['trailing_pct']) {
            $out[] = ['key' => 'trailing', 'level' => 'warn', 'label' => '高値から下落',
                'detail' => sprintf('購入後の高値%sから-%.1f%%（設定 -%s%%）', number_format($high, $high < 1000 ? 2 : 0), $dd, rtrim(rtrim(number_format($st['trailing_pct'], 1), '0'), '.'))];
        }
    }

    // 3a. 決算接近
    $ed = $stock->earnings_date ?? null;
    if (!empty($ed) && $ed !== '0000-00-00') {
        $to_earn = wp_stocks_exit_days_between(current_time('Y-m-d'), $ed);
        if ($to_earn !== null && $to_earn >= 0 && $to_earn <= $st['earnings_days']) {
            $out[] = ['key' => 'earnings', 'level' => 'info', 'label' => '決算接近',
                'detail' => $to_earn === 0 ? '本日が決算日' : sprintf('決算まであと%d日（%s）', $to_earn, $ed)];
        }
    }

    // 3b. 保有日数が長い（購入日が空欄なら出さない）
    $hd = wp_stocks_exit_holding_days($stock->purchase_date ?? null);
    if ($hd !== null && $hd >= $st['hold_days']) {
        $out[] = ['key' => 'holding', 'level' => 'info', 'label' => '保有長期',
            'detail' => sprintf('保有%d日（設定 %d日以上）', $hd, $st['hold_days'])];
    }

    return $out;
}

/** サインのバッジHTML。無ければ「-」 */
function wp_stocks_exit_badges_html($signals) {
    if (empty($signals)) return '<span style="color:#aaa;">-</span>';
    $colors = ['stop' => '#c0392b', 'profit' => '#27ae60', 'warn' => '#e67e22', 'info' => '#7f8c8d'];
    $icons  = ['stoploss' => '🛑', 'takeprofit' => '🎯', 'trailing' => '📉', 'earnings' => '📅', 'holding' => '⏳'];
    $html = '';
    foreach ($signals as $sg) {
        $html .= '<span title="' . esc_attr($sg['detail']) . '" style="display:inline-block;margin:1px 3px 1px 0;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:bold;color:#fff;background:'
            . ($colors[$sg['level']] ?? '#7f8c8d') . ';white-space:nowrap;">'
            . ($icons[$sg['key']] ?? '') . ' ' . esc_html($sg['label']) . '</span>';
    }
    return $html;
}

/** 購入日・保有日数のセルHTML */
function wp_stocks_exit_hold_cell_html($stock) {
    $pd = $stock->purchase_date ?? null;
    if (empty($pd) || $pd === '0000-00-00') {
        return '<span style="color:#aaa;" title="編集から購入日を入力すると保有日数が出ます">-</span>';
    }
    $hd = wp_stocks_exit_holding_days($pd);
    return esc_html($pd) . '<br><strong>' . ($hd === null ? '-' : esc_html($hd) . '日目') . '</strong>';
}

/** ポートフォリオ画面の「出口サイン設定」フォーム（折りたたみ） */
function wp_stocks_exit_settings_form_html() {
    $st = wp_stocks_exit_get_settings();
    $fields = [
        'stoploss_pct'   => ['損切りライン（取得単価から −％）', '%'],
        'takeprofit_pct' => ['利確ライン（取得単価から ＋％）', '%'],
        'trailing_pct'   => ['高値からの下落（購入後の最高値から −％）', '%'],
        'earnings_days'  => ['決算接近（決算日まで何日以内）', '日'],
        'hold_days'      => ['保有長期（何日以上）', '日'],
    ];
    $html  = '<details style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:10px 16px;margin-bottom:20px;">';
    $html .= '<summary style="cursor:pointer;font-weight:bold;">⚙️ 出口サイン設定（全銘柄共通）</summary>';
    $html .= '<form method="post" style="margin-top:10px;">' . wp_nonce_field('wp_stocks_exit_settings_nonce', '_wpnonce', true, false);
    $html .= '<table class="form-table" style="margin:0;"><tbody>';
    foreach ($fields as $k => $meta) {
        $v = $st[$k];
        $html .= '<tr><th style="padding:6px 10px;width:340px;">' . esc_html($meta[0]) . '</th><td style="padding:6px 10px;">'
            . '<input type="number" name="exit_' . esc_attr($k) . '" value="' . esc_attr(rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.')) . '" step="0.1" min="0.1" style="width:90px;"> ' . esc_html($meta[1]) . '</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p style="margin:8px 0 0;"><button type="submit" name="wp_stocks_save_exit_settings" class="button button-primary">設定を保存</button> '
        . '<span style="color:#888;font-size:12px;">購入日が空欄の銘柄は、保有日数が「-」になり、高値からの下落・保有長期のサインは出ません。</span></p>';
    $html .= '</form></details>';
    return $html;
}

/** ダッシュボード上部のパネル：サインが出ている保有銘柄だけを一覧表示 */
function wp_stocks_exit_dashboard_panel_html() {
    global $wpdb;
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE status = 'portfolio' AND is_sector_etf = 0 ORDER BY id ASC");
    if (!$stocks) return '';

    $items = [];
    foreach ($stocks as $s) {
        $latest = $wpdb->get_row($wpdb->prepare(
            "SELECT price FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $s->id
        ));
        $price = $latest ? floatval($latest->price) : 0;
        $sigs  = wp_stocks_exit_signals($s, $price);
        if (empty($sigs)) continue;
        $items[] = [$s, $price, $sigs];
    }

    $html  = '<div style="background:#fff;border:2px solid ' . ($items ? '#e67e22' : '#ddd') . ';border-radius:8px;padding:12px 16px;margin:0 0 20px 0;">';
    $html .= '<div style="font-weight:bold;font-size:14px;margin-bottom:6px;">🚪 出口サイン（保有' . count($stocks) . '銘柄中 ' . count($items) . '銘柄）'
        . ' <a href="' . esc_url(admin_url('admin.php?page=wp-stocks-portfolio')) . '" style="font-size:12px;font-weight:normal;margin-left:8px;">ポートフォリオで設定・確認 →</a></div>';
    if (!$items) {
        $html .= '<div style="color:#888;font-size:13px;">いま出ているサインはありません。</div>';
    } else {
        $html .= '<table class="widefat striped" style="font-size:12px;"><thead><tr><th>コード</th><th>銘柄名</th><th>保有日数</th><th>含み率</th><th>サイン</th></tr></thead><tbody>';
        foreach ($items as [$s, $price, $sigs]) {
            $buy = floatval($s->purchase_price ?? 0);
            $pct = ($buy > 0 && $price > 0) ? ($price - $buy) / $buy * 100 : null;
            $hd  = wp_stocks_exit_holding_days($s->purchase_date ?? null);
            $html .= '<tr><td>' . esc_html($s->code) . '</td>'
                . '<td><a href="' . esc_url(admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id)) . '">' . esc_html($s->name) . '</a></td>'
                . '<td>' . ($hd === null ? '-' : esc_html($hd) . '日目') . '</td>'
                . '<td style="font-weight:bold;color:' . ($pct !== null && $pct >= 0 ? '#e74c3c' : '#3498db') . ';">'
                . ($pct === null ? '-' : ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%') . '</td>'
                . '<td>' . wp_stocks_exit_badges_html($sigs) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    $html .= '<div style="color:#999;font-size:11px;margin-top:6px;">売買の指示ではなく、見直しの目安です。</div></div>';
    return $html;
}

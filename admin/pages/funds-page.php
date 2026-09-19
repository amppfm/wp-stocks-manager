<?php
/**
 * WP Stocks Manager — 管理画面: 投資信託管理ページ
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// 投資信託管理ページ
// --------------------------------------------------
function wp_stocks_funds_page() {
    global $wpdb;

    // 登録処理
    if (isset($_POST['wp_stocks_add_fund'])) {
        check_admin_referer('wp_stocks_fund_nonce');
        $fund_code     = sanitize_text_field($_POST['fund_code']);
        $fund_name     = sanitize_text_field($_POST['fund_name']);
        $fund_units    = floatval($_POST['fund_units']);
        $cost_per_unit = floatval($_POST['cost_per_unit']);
        $fund_type     = sanitize_text_field($_POST['fund_type']);
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}stock_funds WHERE fund_code = %s AND fund_type = %s",
            $fund_code, $fund_type
        ));
        if ($existing) {
            echo '<div class="error"><p>このファンドコードはすでに登録されています。</p></div>';
        } else {
            $wpdb->insert($wpdb->prefix . 'stock_funds', [
                'fund_code'     => $fund_code,
                'fund_name'     => $fund_name,
                'fund_units'    => $fund_units,
                'cost_per_unit' => $cost_per_unit,
                'fund_type'     => $fund_type,
                'created_at'    => current_time('mysql'),
            ]);
            $new_id = $wpdb->insert_id;
            wp_stocks_save_fund_price($new_id, $fund_code);
            echo '<div class="updated"><p>「' . esc_html($fund_name) . '」を登録し、基準価額を取得しました。</p></div>';
        }
    }

    // 削除処理
    if (isset($_GET['delete_fund']) && check_admin_referer('wp_stocks_delete_fund_' . intval($_GET['delete_fund']))) {
        $fid = intval($_GET['delete_fund']);
        $wpdb->delete($wpdb->prefix . 'stock_fund_prices', ['fund_id' => $fid]);
        $wpdb->delete($wpdb->prefix . 'stock_funds', ['id' => $fid]);
        echo '<div class="updated"><p>ファンドを削除しました。</p></div>';
    }

    // 口数・取得単価更新
    if (isset($_POST['wp_stocks_update_fund'])) {
        check_admin_referer('wp_stocks_fund_update_nonce');
        $fid           = intval($_POST['fund_id']);
        $fund_units    = floatval($_POST['fund_units']);
        $cost_per_unit = floatval($_POST['cost_per_unit']);
        $fund_type     = sanitize_text_field($_POST['fund_type']);
        $wpdb->update($wpdb->prefix . 'stock_funds',
            ['fund_units' => $fund_units, 'cost_per_unit' => $cost_per_unit, 'fund_type' => $fund_type],
            ['id' => $fid]
        );
        echo '<div class="updated"><p>ファンド情報を更新しました。</p></div>';
    }

    $funds = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_funds ORDER BY fund_type, id");

    echo '<div class="wrap"><h1>📊 投資信託管理</h1>';

    // 登録フォーム
    echo '<h2>ファンドを追加</h2>';
    echo '<form method="post" style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:20px;max-width:800px;margin-bottom:25px;">';
    wp_nonce_field('wp_stocks_fund_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th style="width:160px;">ファンドコード</th><td><input type="text" name="fund_code" placeholder="例：03316183" required style="width:180px;"> <small style="color:#888;"><a href="https://finance.yahoo.co.jp/fund/search" target="_blank">Yahoo!ファイナンスで検索</a></small></td></tr>';
    echo '<tr><th>ファンド名</th><td><input type="text" name="fund_name" placeholder="例：eMAXIS Slim 全世界株式（除く日本）" required style="width:400px;"></td></tr>';
    echo '<tr><th>保有口数</th><td><input type="number" name="fund_units" placeholder="例：140878" step="1" required style="width:150px;"> 口</td></tr>';
    echo '<tr><th>取得単価</th><td><input type="number" name="cost_per_unit" placeholder="例：34073" step="0.01" required style="width:150px;"> 円（1万口あたり）</td></tr>';
    echo '<tr><th>区分</th><td><select name="fund_type"><option value="growth">NISA成長投資枠</option><option value="tsumitate">NISAつみたて投資枠</option><option value="tokutei">特定口座</option></select></td></tr>';
    echo '</tbody></table>';
    echo '<p><button type="submit" name="wp_stocks_add_fund" class="button button-primary">登録（基準価額も自動取得）</button></p>';
    echo '</form>';

    if (!$funds) { echo '<p>ファンドが登録されていません。</p></div>'; return; }

    // サマリー計算
    $total_eval = $total_cost = $total_gain = 0;
    $fund_data = [];
    foreach ($funds as $f) {
        $prices = $wpdb->get_results($wpdb->prepare(
            "SELECT price, price_date FROM {$wpdb->prefix}stock_fund_prices WHERE fund_id = %d ORDER BY price_date DESC LIMIT 2",
            $f->id
        ));
        $today_price = $prices[0]->price ?? null;
        $prev_price  = $prices[1]->price ?? null;
        $eval_value  = $today_price ? ($f->fund_units * $today_price / 10000) : 0;
        $cost_value  = $f->fund_units * $f->cost_per_unit / 10000;
        $gain        = $eval_value - $cost_value;
        $gain_pct    = $cost_value > 0 ? ($gain / $cost_value * 100) : 0;
        $day_change  = ($today_price && $prev_price) ? $today_price - $prev_price : null;
        $day_change_pct = ($prev_price && $prev_price > 0 && $day_change !== null) ? ($day_change / $prev_price * 100) : null;
        $total_eval += $eval_value;
        $total_cost += $cost_value;
        $total_gain += $gain;
        $fund_data[] = compact('f', 'today_price', 'prev_price', 'eval_value', 'cost_value', 'gain', 'gain_pct', 'day_change', 'day_change_pct');
    }

    // サマリーカード
    $gain_color = $total_gain >= 0 ? '#e74c3c' : '#3498db';
    echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:25px;">';
    foreach ([
        ['評価額合計', number_format($total_eval, 0) . '円', '#333'],
        ['取得額合計', number_format($total_cost, 0) . '円', '#333'],
        ['含み損益',   ($total_gain >= 0 ? '+' : '') . number_format($total_gain, 0) . '円', $gain_color],
    ] as [$label, $val, $color]) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;text-align:center;">';
        echo '<div style="font-size:12px;color:#888;margin-bottom:6px;">' . $label . '</div>';
        echo '<div style="font-size:18px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // ファンド一覧
    $type_labels  = ['growth' => 'NISA成長投資枠', 'tsumitate' => 'NISAつみたて投資枠', 'tokutei' => '特定口座'];
    $current_type = null;

    echo '<table class="widefat striped" style="font-size:13px;">';
    echo '<thead><tr>';
    foreach (['ファンド名', '区分', '基準価額', '前日比', '口数', '評価額', '含み損益', '含み率', 'チャート', '操作'] as $h) {
        echo '<th>' . $h . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($fund_data as $row) {
        extract($row);

        if ($f->fund_type !== $current_type) {
            $current_type = $f->fund_type;
            echo '<tr style="background:#e8f4f8;"><td colspan="10" style="font-weight:bold;padding:8px 12px;">'
                . esc_html($type_labels[$f->fund_type] ?? $f->fund_type) . '</td></tr>';
        }

        $gain_color2 = $gain >= 0 ? '#e74c3c' : '#3498db';

        if ($day_change !== null) {
            $dc_color = $day_change >= 0 ? '#e74c3c' : '#3498db';
            $dc_arrow = $day_change >= 0 ? '▲' : '▼';
            $day_change_html = '<span style="color:' . $dc_color . ';font-weight:bold;">'
                . $dc_arrow . ($day_change >= 0 ? '+' : '') . number_format($day_change, 0) . '円'
                . ' (' . ($day_change_pct >= 0 ? '+' : '') . number_format($day_change_pct, 2) . '%)'
                . '</span>';
        } else {
            $day_change_html = '<span style="color:#888;">-</span>';
        }

        $chart_url  = 'https://finance.yahoo.co.jp/quote/' . $f->fund_code . '/chart';
        $delete_url = wp_nonce_url(admin_url('admin.php?page=wp-stocks-funds&delete_fund=' . $f->id), 'wp_stocks_delete_fund_' . $f->id);
        $fetch_url  = wp_nonce_url(admin_url('admin-post.php?action=fetch_fund_price&id=' . $f->id), 'wp_stocks_action_' . $f->id);

        echo '<tr>';
        echo '<td><strong>' . esc_html($f->fund_name) . '</strong><br><small style="color:#888;">' . esc_html($f->fund_code) . '</small></td>';
        echo '<td><span style="font-size:11px;background:#ddeeff;padding:2px 6px;border-radius:3px;">' . esc_html($type_labels[$f->fund_type] ?? $f->fund_type) . '</span></td>';
        echo '<td>' . ($today_price ? number_format($today_price, 0) . '円' : '未取得') . '</td>';
        echo '<td>' . $day_change_html . '</td>';
        echo '<td>' . number_format($f->fund_units, 0) . '口</td>';
        echo '<td>' . ($eval_value > 0 ? number_format($eval_value, 0) . '円' : '-') . '</td>';
        echo '<td style="color:' . $gain_color2 . ';font-weight:bold;">' . ($gain >= 0 ? '+' : '') . number_format($gain, 0) . '円</td>';
        echo '<td style="color:' . $gain_color2 . ';font-weight:bold;">' . ($gain_pct >= 0 ? '+' : '') . number_format($gain_pct, 2) . '%</td>';
        echo '<td><a href="' . esc_url($chart_url) . '" target="_blank" class="button button-small">📈 チャート</a></td>';
        echo '<td><a href="' . esc_url($fetch_url) . '" class="button button-small">更新</a> <a href="' . esc_url($delete_url) . '" class="button button-small" style="color:red;" onclick="return confirm(\'このファンドを削除しますか？\');">削除</a></td>';
        echo '</tr>';

        // 編集行
        echo '<tr style="background:#fafafa;"><td colspan="10" style="padding:6px 12px;">';
        echo '<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
        wp_nonce_field('wp_stocks_fund_update_nonce');
        echo '<input type="hidden" name="fund_id" value="' . esc_attr($f->id) . '">';
        echo '<span style="font-size:12px;color:#888;">口数：</span>';
        echo '<input type="number" name="fund_units" value="' . esc_attr($f->fund_units) . '" step="1" style="width:120px;font-size:12px;">';
        echo '<span style="font-size:12px;color:#888;">取得単価（1万口）：</span>';
        echo '<input type="number" name="cost_per_unit" value="' . esc_attr($f->cost_per_unit) . '" step="0.01" style="width:100px;font-size:12px;">';
        echo '<span style="font-size:12px;color:#888;">区分：</span>';
        echo '<select name="fund_type" style="font-size:12px;">';
        foreach ($type_labels as $val => $label) {
            $sel = $f->fund_type === $val ? 'selected' : '';
            echo '<option value="' . esc_attr($val) . '" ' . $sel . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" name="wp_stocks_update_fund" class="button button-small">保存</button>';
        echo '</form></td></tr>';
    }
    echo '</tbody></table></div>';
}

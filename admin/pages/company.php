<?php
/**
 * WP Stocks Manager — 管理画面: 企業情報詳細ページ（銘柄詳細・財務チャート・テクニカル・メモ等）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// 企業情報詳細ページ
// --------------------------------------------------
function wp_stocks_company_page() {
    global $wpdb;
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks ORDER BY code ASC");
    if (!$stocks) { echo '<div class="wrap"><h1>企業情報</h1><p>銘柄が登録されていません。</p></div>'; return; }

    $valid_ids = array_map(function($s) { return (int) $s->id; }, $stocks);
    $id = intval($_GET['stock_id'] ?? 0);
    if (!$id || !in_array($id, $valid_ids, true)) {
        $id = (int) $stocks[0]->id;
    }
    $current_ctab = sanitize_text_field($_GET['ctab'] ?? '');

    echo '<div class="wrap">';
    echo '<div style="display:flex;align-items:center;gap:14px;margin-bottom:15px;flex-wrap:wrap;">';
    echo '<h1 style="margin:0;">企業情報</h1>';
    // セクターごとにグループ化（プルダウンが長くなりすぎないように）
    $company_stocks_by_sector = [];
    foreach ($stocks as $s) {
        $sector_label = !empty($s->sector) ? wp_stocks_sector_ja($s->sector) : '未分類';
        $company_stocks_by_sector[$sector_label][] = $s;
    }
    ksort($company_stocks_by_sector);

    echo '<select id="wp_stocks_company_switcher" class="wp-stocks-sector-select" data-placeholder="銘柄を検索..." style="min-width:280px;">';
    foreach ($company_stocks_by_sector as $sector_label => $sector_stocks) {
        echo '<optgroup label="' . esc_attr($sector_label) . '">';
        foreach ($sector_stocks as $s) {
            $sel = ((int) $s->id === $id) ? 'selected' : '';
            echo '<option value="' . esc_attr($s->id) . '" ' . $sel . '>' . esc_html($s->code . ' - ' . $s->name) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
    echo '</div>';
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('wp_stocks_company_switcher');
        if (!el) return;
        el.addEventListener('change', function() {
            var newId = this.value;
            var ctab  = <?php echo json_encode($current_ctab); ?>;
            var url = 'admin.php?page=wp-stocks-company&stock_id=' + encodeURIComponent(newId) + (ctab ? '&ctab=' + encodeURIComponent(ctab) : '');
            window.location.href = url;
        });
    });
    </script>
    <?php
    wp_stocks_render_sector_dropdown_assets();
    $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stocks WHERE id = %d", $id));
    if (!$stock) { echo '<p>銘柄が見つかりません。</p></div>'; return; }

    if (isset($_GET['message'])) {
        $m = $_GET['message'];
        if ($m === 'watchlist_saved') echo '<div class="updated"><p>注目株の設定を変更しました。</p></div>';
        if ($m === 'tags_saved') echo '<div class="updated"><p>テーマタグを保存しました。</p></div>';
        if ($m === 'memo_saved') echo '<div class="updated"><p>メモを保存しました。</p></div>';
        if ($m === 'ai_saved')   echo '<div class="updated"><p>AI分析結果を履歴に保存しました。</p></div>';
        if ($m === 'ai_deleted') echo '<div class="updated"><p>AI分析履歴を削除しました。</p></div>';
        if ($m === 'info_saved') echo '<div class="updated"><p>企業情報を更新しました。</p></div>';
        if ($m === 'info_error') echo '<div class="error"><p>企業情報の取得に失敗しました。</p></div>';
        if ($m === 'news_saved') echo '<div class="updated"><p>適時開示を取得・保存しました。</p></div>';
        if ($m === 'news_error') echo '<div class="error"><p>適時開示の取得に失敗しました。</p></div>';
        if ($m === 'news_cleared') echo '<div class="updated"><p>適時開示情報を全削除しました。</p></div>';
    }

    $latest = $wpdb->get_row($wpdb->prepare(
        "SELECT price, previous_close, volume, datetime FROM {$wpdb->prefix}stock_prices WHERE stock_id = %d ORDER BY datetime DESC LIMIT 1", $id
    ));
    $tech = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}stock_technicals WHERE stock_id = %d", $id));

    $rg_color  = ($stock->revenue_growth  ?? 0) >= 0 ? '#e74c3c' : '#3498db';
    $eg_color  = ($stock->earnings_growth ?? 0) >= 0 ? '#e74c3c' : '#3498db';
    $pm_color  = ($stock->profit_margin   ?? 0) >= 10 ? '#27ae60' : '#e67e22';
    $per_color = ($stock->per ?? 0) > 0 && ($stock->per ?? 0) < 15 ? '#27ae60' : (($stock->per ?? 0) > 30 ? '#e74c3c' : '#555');
    $pbr_color = ($stock->pbr ?? 0) > 0 && ($stock->pbr ?? 0) < 1 ? '#27ae60' : (($stock->pbr ?? 0) > 3 ? '#e74c3c' : '#555');
    $roe_color = ($stock->roe ?? 0) >= 15 ? '#27ae60' : (($stock->roe ?? 0) < 5 ? '#e74c3c' : '#555');
    $peg_color = ($stock->peg ?? 0) > 0 && ($stock->peg ?? 0) < 1 ? '#27ae60' : (($stock->peg ?? 0) > 2 ? '#e74c3c' : '#555');
    $eq_color  = ($stock->equity_ratio ?? 0) >= 50 ? '#27ae60' : (($stock->equity_ratio ?? 0) >= 30 ? '#555' : '#e74c3c');

    // ★注目株ボタン
    $is_watchlist = intval($stock->is_watchlist ?? 0);
    $star_label   = $is_watchlist ? '★ 注目株に登録中' : '☆ 注目株に追加';
    $star_style   = $is_watchlist
        ? 'background:#f39c12;color:#fff;border-color:#e67e22;'
        : 'background:#fff;color:#888;border-color:#ddd;';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">';
    echo '<h2 style="margin:0;">' . esc_html($stock->code . ' ' . $stock->name . wp_stocks_market_segment_suffix($stock->market ?? '')) . '</h2>';
    if (!empty($stock->market)) {
        $market_badge_colors = ['プライム' => '#0073aa', 'スタンダード' => '#16a085', 'グロース' => '#e67e22'];
        $mb_color = $market_badge_colors[$stock->market] ?? '#888';
        echo '<span style="background:' . $mb_color . ';color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:bold;">' . esc_html($stock->market) . '</span>';
    }
    echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="margin:0;">';
    echo '<input type="hidden" name="action" value="toggle_watchlist">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($stock->id) . '">';
    wp_nonce_field('wp_stocks_watchlist_nonce');
    echo '<button type="submit" class="button" style="' . $star_style . 'font-size:13px;padding:4px 12px;">' . $star_label . '</button>';
    echo '</form>';
    echo '</div>';

    // 次回決算日表示
    if (!empty($stock->earnings_date)) {
        $today_dt = date('Y-m-d');
        $days     = (int)((strtotime($stock->earnings_date) - strtotime($today_dt)) / 86400);
        if ($days >= 0) {
            $ec = $days <= 7 ? '#e74c3c' : ($days <= 30 ? '#f39c12' : '#27ae60');
            echo '<div style="margin-bottom:12px;">'
                . '<span style="background:' . $ec . ';color:#fff;padding:4px 12px;border-radius:4px;font-size:13px;font-weight:bold;">'
                . '&#x1F4C5; 次回決算：' . esc_html($stock->earnings_date) . '（' . $days . '日後）'
                . '</span></div>';
        } else {
            echo '<div style="margin-bottom:12px;">'
                . '<span style="background:#aaa;color:#fff;padding:4px 12px;border-radius:4px;font-size:13px;">'
                . '&#x1F4C5; 前回決算：' . esc_html($stock->earnings_date)
                . '</span></div>';
        }
    }

    // 株価バナー
    $is_usd = ($stock->currency ?? 'JPY') === 'USD';
    $usd_jpy = $is_usd ? wp_stocks_get_usd_jpy() : 1;
    if ($latest) {
        $change_html  = $latest->previous_close ? wp_stocks_change_html($latest->price, $latest->previous_close, $is_usd) : '';
        $volume_str   = $latest->volume ? number_format(intval($latest->volume) / 10000, 1) . '万株' : '-';
        if ($is_usd) {
            $price_display = '$' . number_format($latest->price, 2) . ' <span style="font-size:16px;color:#888;">（≈' . number_format($latest->price * $usd_jpy) . '円）</span>';
        } else {
            $price_display = number_format($latest->price) . '円';
        }
        echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">';
        echo '<div style="font-size:28px;font-weight:bold;">' . $price_display . '</div>';
        echo '<div style="font-size:16px;">' . $change_html . '</div>';
        echo '<div style="font-size:13px;color:#888;">出来高：' . esc_html($volume_str) . '</div>';
        echo '<div style="font-size:13px;">トレンド：' . ($tech ? wp_stocks_trend_icon_html($tech) : '<span style="color:#aaa;">-</span>') . '</div>';
        echo '<div style="font-size:12px;color:#aaa;">' . esc_html($latest->datetime) . '</div>';
        // 適正株価をバナーに表示（セクター平均PER基準・循環参照なし）
        $usd_jpy_banner = $is_usd ? wp_stocks_get_usd_jpy() : 1;
        $fp_data  = wp_stocks_calc_fair_price($stock);
        $fp_parts = [];
        if ($fp_data['actual'] !== null) {
            $fp_val = $fp_data['actual'];
            $fp_parts[] = '実績：' . ($is_usd
                ? '$' . number_format($fp_val, 2) . '(≈' . number_format($fp_val * $usd_jpy_banner) . '円)'
                : number_format($fp_val, 0) . '円') . '（セクター平均PER' . number_format($fp_data['sector_avg_per'], 1) . '倍基準）';
        }
        if ($fp_data['forward'] !== null) {
            $fp_fwd = $fp_data['forward'];
            $fp_parts[] = '予想：' . ($is_usd
                ? '$' . number_format($fp_fwd, 2) . '(≈' . number_format($fp_fwd * $usd_jpy_banner) . '円)'
                : number_format($fp_fwd, 0) . '円') . '（セクター平均PER' . number_format($fp_data['sector_avg_per_forward'], 1) . '倍基準）';
        }
        if (!empty($fp_parts)) {
            echo '<div style="font-size:12px;color:#e67e22;border-left:2px solid #e67e22;padding-left:8px;">'
                . '📐 適正株価　' . implode('　', $fp_parts)
                . '</div>';
        }
        echo '</div>';
    }

    // 市場区分（日本株のみ・株価の下・タブの上に表示）
    if (isset($_GET['message']) && $_GET['message'] === 'market_segment_saved') {
        echo '<div class="updated"><p>市場区分を保存しました。</p></div>';
    }
    if (!$is_usd) {
        echo '<div style="margin-bottom:15px;">';
        echo '<h3>&#x1F3E2; 市場区分</h3>';
        // ★変更：J-Quantsで判定できる銘柄（プライム/スタンダード/グロース）は自動反映のみとし、
        // 手動入力フォームはJ-Quantsが判定できない銘柄（TOKYO PRO Market等）の場合のみ表示する
        $jquants_market_label = wp_stocks_jquants_market_code_to_label($stock->jquants_market_code ?? null);
        if ($jquants_market_label !== null) {
            echo '<p class="description">J-Quantsにより自動判定されています（' . esc_html($jquants_market_label) . '）。</p>';
        } else {
            echo '<p class="description">J-Quantsで市場区分を判定できない銘柄です（TOKYO PRO Market等）。必要であれば手動で入力してください。</p>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
            echo '<input type="hidden" name="action" value="update_market_segment">';
            echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
            wp_nonce_field('wp_stocks_market_segment_nonce');
            echo '<input type="text" name="manual_market_name" value="' . esc_attr($stock->manual_market_name ?? '') . '" placeholder="例：TOKYO PRO Market" style="width:220px;margin-right:10px;">';
            echo '<button type="submit" class="button button-primary">保存</button></form>';
        }
        echo '</div>';
    }

    // ===================== タブUI =====================
    $active_tab = sanitize_text_field($_GET['ctab'] ?? 'info');
    if (!in_array($active_tab, ['info','chart','finance','oscillator','news','ai','edit'])) $active_tab = 'info';
    $tab_defs = [
        'info'       => '&#x1F4CB; 基本情報',
        'chart'      => '&#x1F4CA; チャート',
        'finance'    => '&#x1F4B0; 財務',
        'oscillator' => '&#x1F4C8; テクニカル分析', // ★変更：「オシレータ分析」から改称（内部のctabキーはoscillatorのまま）
        'news'       => '&#x1F4F0; 適時開示',
        'ai'         => '&#x1F916; AI分析',
        'edit'       => '&#x270F;&#xFE0F; 編集',
    ];
    echo '<div class="company-tabs-wrapper" style="margin-top:15px;">';
    echo '<ul style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0 0 20px 0;padding:0;list-style:none;flex-wrap:wrap;">';
    foreach ($tab_defs as $key => $label) {
        $is_active = $active_tab === $key;
        $tab_url   = add_query_arg(['ctab' => $key], admin_url('admin.php?page=wp-stocks-company&stock_id=' . $id));
        $style = $is_active
            ? 'display:block;padding:10px 18px;background:#0073aa;color:#fff;text-decoration:none;font-size:13px;font-weight:bold;border-radius:4px 4px 0 0;'
            : 'display:block;padding:10px 18px;background:#f1f1f1;color:#555;text-decoration:none;font-size:13px;border-radius:4px 4px 0 0;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="' . esc_url($tab_url) . '" style="' . $style . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    // ===== テクニカル分析タブ（旧オシレータ分析：ミニチャート→複合判定→テクニカル総合→テクニカル指標一覧→オシレータ分析） =====
    if ($active_tab === 'oscillator') {
        echo '<div id="tab-oscillator">';
        if (!$tech) {
            echo '<p style="color:#888;">テクニカル指標がまだ計算されていません。日次のテクニカル計算Cron実行後に反映されます。</p>';
        } else {
            $osc_price   = $latest->price ?? null;
            $mini_symbol = $is_usd ? $stock->code : $stock->code . '.T';

            // ★変更：ミニチャートより先にOHLCVを取得し、今日の株価テーブル／株価トレンドでも再利用する
            $mini_ohlcv = wp_stocks_get_ohlcv($mini_symbol);

            // ① 今日の株価テーブル（始値・高値・安値・終値・出来高）＋株価トレンド（5日線／25日線／75日線）
            if ($mini_ohlcv && count($mini_ohlcv) > 0) {
                $today_ohlcv      = end($mini_ohlcv);
                $today_date_disp  = date('n月j日', strtotime($today_ohlcv['date']));

                echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px;margin-bottom:15px;width:80%;box-sizing:border-box;">';
                echo '<h4 style="margin:0 0 10px 0;font-size:13px;color:#555;">' . esc_html($today_date_disp) . 'の株価</h4>';
                echo '<table style="width:100%;border-collapse:collapse;font-size:13px;text-align:center;margin-bottom:10px;">';
                echo '<thead><tr style="background:#f8f9fa;">';
                echo '<th style="padding:6px;border:1px solid #eee;">始値</th><th style="padding:6px;border:1px solid #eee;">高値</th><th style="padding:6px;border:1px solid #eee;">安値</th><th style="padding:6px;border:1px solid #eee;">終値</th>';
                echo '</tr></thead><tbody><tr>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['open'], 1) . '</td>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['high'], 1) . '</td>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['low'], 1) . '</td>';
                echo '<td style="padding:6px;border:1px solid #eee;">' . number_format($today_ohlcv['close'], 1) . '</td>';
                echo '</tr></tbody></table>';
                echo '<div style="font-size:12px;color:#888;margin-bottom:15px;">出来高　' . number_format($today_ohlcv['volume'] ?? 0) . '株</div>';

                // ★追加：株価・MA5・MA25・MA75の「継続／転換」状態（直近3日分の値から判定）
                $closes_for_trend  = array_column($mini_ohlcv, 'close');
                $price_trend_state = wp_stocks_calc_trend_state(array_slice($closes_for_trend, -3));
                $ma5_state  = wp_stocks_calc_trend_state([
                    wp_stocks_ma_n_days_ago($closes_for_trend, 5, 2),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 5, 1),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 5, 0),
                ]);
                $ma25_state = wp_stocks_calc_trend_state([
                    wp_stocks_ma_n_days_ago($closes_for_trend, 25, 2),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 25, 1),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 25, 0),
                ]);
                $ma75_state = wp_stocks_calc_trend_state([
                    wp_stocks_ma_n_days_ago($closes_for_trend, 75, 2),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 75, 1),
                    wp_stocks_ma_n_days_ago($closes_for_trend, 75, 0),
                ]);

                echo '<table style="width:100%;border-collapse:collapse;font-size:12px;text-align:center;">';
                echo '<thead><tr style="background:#f8f9fa;">';
                echo '<th style="padding:6px;border:1px solid #eee;">株価トレンド</th><th style="padding:6px;border:1px solid #eee;">5日線</th><th style="padding:6px;border:1px solid #eee;">25日線</th><th style="padding:6px;border:1px solid #eee;">75日線</th>';
                echo '</tr></thead><tbody><tr>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($price_trend_state) . '</td>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($ma5_state) . '</td>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($ma25_state) . '</td>';
                echo '<td style="padding:8px;border:1px solid #eee;">' . wp_stocks_ma_trend_icon_html($ma75_state) . '</td>';
                echo '</tr></tbody></table>';
                echo '</div>';
            }

            // ① ミニチャート（直近90日のローソク足）
            if ($mini_ohlcv && count($mini_ohlcv) > 5) {
                $mini_recent = array_slice($mini_ohlcv, -90);
                $mini_candle_data = array_map(function($d) {
                    return [
                        'x' => $d['date'],
                        'y' => [round($d['open'], 1), round($d['high'], 1), round($d['low'], 1), round($d['close'], 1)],
                    ];
                }, $mini_recent);
                $mini_candle_json = json_encode($mini_candle_data);
                echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 16px;margin-bottom:20px;width:80%;box-sizing:border-box;">';
                echo '<h4 style="margin:0 0 8px 0;font-size:12px;color:#888;">直近90日の株価推移</h4>';
                echo '<div id="miniChart"></div>';
                echo '</div>';
                echo '<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>';
                echo '<script>
                new ApexCharts(document.getElementById("miniChart"), {
                    chart: { type: "candlestick", height: 250, toolbar: { show: false } },
                    series: [{ data: ' . $mini_candle_json . ' }],
                    xaxis: { type: "category", labels: { show: false } },
                    yaxis: { labels: { formatter: function(v){ return Number(v).toLocaleString(); } } },
                    plotOptions: { candlestick: { colors: { upward: "#e74c3c", downward: "#3498db" } } },
                    tooltip: { x: { show: true } },
                }).render();
                </script>';
            }

            $judge_badge = function($judge) {
                if ($judge === 'buy')  return '<span style="background:#3498db;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold;">買い</span>';
                if ($judge === 'sell') return '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold;">売り</span>';
                return '<span style="background:#ddd;color:#888;padding:2px 8px;border-radius:3px;font-size:11px;">中立</span>';
            };

            // ★変更：テクニカル指標の一覧テーブルより先に、各判定要素だけを計算しておく
            // （②「テクニカル総合」を③「テクニカル指標の一覧」の上に出すため）
            $ma_judge = null; $ma_reason = '並びが揃っておらずレンジ気味';
            if ($tech->ma5 !== null && $tech->ma25 !== null && $tech->ma75 !== null) {
                if ($tech->ma5 > $tech->ma25 && $tech->ma25 > $tech->ma75)     { $ma_judge = 'buy';  $ma_reason = 'MA5＞MA25＞MA75の並びで上昇トレンド'; }
                elseif ($tech->ma5 < $tech->ma25 && $tech->ma25 < $tech->ma75) { $ma_judge = 'sell'; $ma_reason = 'MA75＞MA25＞MA5の並びで下降トレンド'; }
            }

            $cross_judge = null; $cross_reason = '直近のクロスは発生していません';
            if (($tech->cross_signal ?? null) === 'golden') { $cross_judge = 'buy';  $cross_reason = 'MA5がMA25/MA75を下から上に突き抜けました（打診買い）'; }
            if (($tech->cross_signal ?? null) === 'dead')   { $cross_judge = 'sell'; $cross_reason = 'MA5がMA25/MA75を上から下に突き抜けました（打診売り）'; }

            $macd_judge = null; $macd_reason = 'ゼロライン付近で方向感に乏しい';
            if ($tech->macd !== null && $tech->macd_signal !== null) {
                if ($tech->macd > $tech->macd_signal && $tech->macd < 0)      { $macd_judge = 'buy';  $macd_reason = 'ゼロライン以下の低い位置でMACDがシグナルを上抜け'; }
                elseif ($tech->macd < $tech->macd_signal && $tech->macd > 0) { $macd_judge = 'sell'; $macd_reason = 'ゼロライン以上の高い位置でMACDがシグナルを下抜け'; }
                elseif ($tech->macd > 0 && $tech->macd_signal > 0)           { $macd_judge = 'buy';  $macd_reason = 'MACD・シグナルともにゼロラインより上で推移'; }
                elseif ($tech->macd < 0 && $tech->macd_signal < 0)           { $macd_judge = 'sell'; $macd_reason = 'MACD・シグナルともにゼロラインより下で推移'; }
            }

            $dmi_judge = null; $dmi_reason = 'ADXが25未満のためトレンド不明瞭';
            if ($tech->plus_di !== null && $tech->minus_di !== null && $tech->adx !== null && $tech->adx >= 25) {
                if ($tech->plus_di > $tech->minus_di) { $dmi_judge = 'buy';  $dmi_reason = '+DIが-DIを上回りADX' . $tech->adx . 'で明確な上昇トレンド'; }
                else                                   { $dmi_judge = 'sell'; $dmi_reason = '-DIが+DIを上回りADX' . $tech->adx . 'で明確な下降トレンド'; }
            }

            $sar_judge = null; $sar_reason = '直近の転換はありません';
            if (intval($tech->sar_reversal ?? 0) === 1) {
                if (($tech->sar_trend ?? '') === 'up')   { $sar_judge = 'buy';  $sar_reason = 'パラボリックSARが下から上へ転換'; }
                if (($tech->sar_trend ?? '') === 'down') { $sar_judge = 'sell'; $sar_reason = 'パラボリックSARが上から下へ転換'; }
            }

            // ★追加：テクニカル総合（MA・GC/DC・MACD・DMI・SARの多数決）
            $trend_judges     = array_filter([$ma_judge, $cross_judge, $macd_judge, $dmi_judge, $sar_judge], function($j) { return $j !== null; });
            $trend_buy_count  = count(array_filter($trend_judges, function($j) { return $j === 'buy'; }));
            $trend_sell_count = count(array_filter($trend_judges, function($j) { return $j === 'sell'; }));
            if (!empty($trend_judges) && $trend_buy_count > $trend_sell_count)      $trend_overall = 'buy';
            elseif (!empty($trend_judges) && $trend_sell_count > $trend_buy_count)  $trend_overall = 'sell';
            else                                                                     $trend_overall = 'neutral';

            // ② 複合判定（論点3〜5：固定重み・5段階・フィボナッチ加味）
            echo '<h3 style="margin-top:0;">&#x1F3AF; 複合判定</h3>';
            $composite_score  = intval($tech->composite_score ?? 0);
            $composite_detail = json_decode($tech->composite_detail ?? '[]', true);
            if (!is_array($composite_detail)) $composite_detail = [];
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;max-width:780px;margin-bottom:25px;">';
            echo '<div style="margin-bottom:10px;">' . wp_stocks_composite_badge_html($composite_score) . '</div>';
            if (!empty($composite_detail)) {
                echo '<ul style="margin:0;padding-left:20px;font-size:12px;color:#555;line-height:1.8;">';
                foreach ($composite_detail as $d) echo '<li>' . esc_html($d) . '</li>';
                echo '</ul>';
            }
            echo '</div>';

            // ③ テクニカル指標の一覧（トレンド系：MA・GC/DC・MACD・DMI・SAR）
            echo '<h3>&#x1F4C8; テクニカル指標の一覧</h3>';
            echo '<div style="margin-bottom:15px;">テクニカル総合：' . $judge_badge($trend_overall) . '</div>';
            echo '<table class="widefat fixed striped" style="max-width:780px;margin-bottom:25px;"><thead><tr><th style="width:190px;">指標</th><th style="width:170px;">値</th><th style="width:90px;">判定</th><th>判定理由</th></tr></thead><tbody>';

            echo '<tr><th>移動平均線（MA5/25/75）</th><td>'
                . ($tech->ma5  !== null ? number_format($tech->ma5, 1)  . '円 / ' : '- / ')
                . ($tech->ma25 !== null ? number_format($tech->ma25, 1) . '円 / ' : '- / ')
                . ($tech->ma75 !== null ? number_format($tech->ma75, 1) . '円' : '-')
                . '</td><td>' . ($ma_judge !== null ? $judge_badge($ma_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($ma_reason) . '</td></tr>';

            echo '<tr><th>ゴールデンクロス／デッドクロス</th><td>' . esc_html($tech->cross_signal ?? '-') . '</td><td>' . ($cross_judge !== null ? $judge_badge($cross_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($cross_reason) . '</td></tr>';

            echo '<tr><th>MACD / Signal</th><td>' . ($tech->macd !== null ? number_format($tech->macd, 2) : '-') . ' / ' . ($tech->macd_signal !== null ? number_format($tech->macd_signal, 2) : '-') . '</td><td>' . ($macd_judge !== null ? $judge_badge($macd_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($macd_reason) . '</td></tr>';

            echo '<tr><th>DMI（+DI / -DI / ADX）</th><td>' . ($tech->plus_di ?? '-') . ' / ' . ($tech->minus_di ?? '-') . ' / ' . ($tech->adx ?? '-') . '</td><td>' . ($dmi_judge !== null ? $judge_badge($dmi_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($dmi_reason) . '</td></tr>';

            echo '<tr><th>パラボリックSAR</th><td>' . ($tech->sar !== null ? number_format($tech->sar, 2) : '-') . '</td><td>' . ($sar_judge !== null ? $judge_badge($sar_judge) : $judge_badge('neutral')) . '</td><td style="font-size:12px;color:#666;">' . esc_html($sar_reason) . '</td></tr>';

            echo '</tbody></table>';

            // ④ オシレータ分析（既存の判定ロジックを維持し、判定理由列を明示）
            $rsi_judge = null; $rsi_reason = '-';
            if ($tech->rsi !== null) {
                if ($tech->rsi >= 70)      { $rsi_judge = 'sell'; $rsi_reason = 'RSI' . $tech->rsi . '（70以上・買われ過ぎ）'; }
                elseif ($tech->rsi <= 30)  { $rsi_judge = 'buy';  $rsi_reason = 'RSI' . $tech->rsi . '（30以下・売られ過ぎ）'; }
                else                        { $rsi_judge = 'neutral'; $rsi_reason = 'RSI' . $tech->rsi . '（中立圏）'; }
            }
            $rci_judge = null; $rci_reason = '-';
            if ($tech->rci !== null) {
                if ($tech->rci >= 80)      { $rci_judge = 'sell'; $rci_reason = 'RCI' . $tech->rci . '（+80以上・買われ過ぎ）'; }
                elseif ($tech->rci <= -80) { $rci_judge = 'buy';  $rci_reason = 'RCI' . $tech->rci . '（-80以下・売られ過ぎ）'; }
                else                        { $rci_judge = 'neutral'; $rci_reason = 'RCI' . $tech->rci . '（中立圏）'; }
            }
            // ★変更：バンドウォーク（強いトレンド中に±2σへ張り付いたまま推移する状態）では
            // バンド突破を逆張りシグナルとして扱わず、トレンド継続の裏付けとして中立扱いにする
            $bb_judge = null; $bb_reason = '-';
            if ($tech->bb_upper !== null && $tech->bb_lower !== null && $osc_price !== null) {
                $bb_is_trending = ($tech->adx !== null && $tech->adx >= 25);
                if ($osc_price >= $tech->bb_upper) {
                    if ($bb_is_trending && ($tech->plus_di ?? 0) > ($tech->minus_di ?? 0)) {
                        $bb_judge  = 'neutral';
                        $bb_reason = 'バンドウォーク中（ADX' . $tech->adx . '・+DI優勢）のため逆張りシグナルとしては扱いません';
                    } else {
                        $bb_judge  = 'sell';
                        $bb_reason = '価格が+2&sigma;ラインに到達／突破';
                    }
                } elseif ($osc_price <= $tech->bb_lower) {
                    if ($bb_is_trending && ($tech->minus_di ?? 0) > ($tech->plus_di ?? 0)) {
                        $bb_judge  = 'neutral';
                        $bb_reason = 'バンドウォーク中（ADX' . $tech->adx . '・-DI優勢）のため逆張りシグナルとしては扱いません';
                    } else {
                        $bb_judge  = 'buy';
                        $bb_reason = '価格が-2&sigma;ラインに到達／突破';
                    }
                } else {
                    $bb_judge  = 'neutral';
                    $bb_reason = 'バンド内で推移中';
                }
            }
            $stoch_judge = null; $stoch_reason = '-';
            if ($tech->stoch_k !== null) {
                if ($tech->stoch_k >= 80)      { $stoch_judge = 'sell'; $stoch_reason = '%K' . $tech->stoch_k . '（80以上・買われ過ぎゾーン）'; }
                elseif ($tech->stoch_k <= 20)  { $stoch_judge = 'buy';  $stoch_reason = '%K' . $tech->stoch_k . '（20以下・売られ過ぎゾーン）'; }
                else                            { $stoch_judge = 'neutral'; $stoch_reason = '%K' . $tech->stoch_k . '（中立圏）'; }
            }

            $judges = array_filter([$rsi_judge, $rci_judge, $bb_judge, $stoch_judge], function($j) { return $j !== null; });
            $buy_count  = count(array_filter($judges, function($j) { return $j === 'buy'; }));
            $sell_count = count(array_filter($judges, function($j) { return $j === 'sell'; }));
            if (!empty($judges) && $buy_count > $sell_count)      $overall = 'buy';
            elseif (!empty($judges) && $sell_count > $buy_count)  $overall = 'sell';
            else                                                  $overall = 'neutral';

            echo '<h3>&#x1F4C9; オシレータ分析</h3>';
            echo '<p style="color:#666;font-size:13px;">株価の水準とは無関係に「買われ過ぎ」「売られ過ぎ」を判定する指標です。判定日時：'
                . ($tech->calculated_at ? esc_html(date('Y/m/d H:i', strtotime($tech->calculated_at))) : '-') . '</p>';
            echo '<div style="margin-bottom:15px;">オシレータ総合：' . $judge_badge($overall) . '</div>';

            echo '<table class="widefat fixed striped" style="max-width:780px;margin-bottom:25px;"><thead><tr><th style="width:190px;">指標</th><th style="width:170px;">値</th><th style="width:90px;">判定</th><th>判定理由</th></tr></thead><tbody>';
            echo '<tr><th>RSI（14日）</th><td>' . ($tech->rsi !== null ? esc_html($tech->rsi) : '-') . '</td><td>' . $judge_badge($rsi_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($rsi_reason) . '</td></tr>';
            echo '<tr><th>RCI（9日）</th><td>' . ($tech->rci !== null ? esc_html($tech->rci) : '-') . '</td><td>' . $judge_badge($rci_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($rci_reason) . '</td></tr>';
            echo '<tr><th>ボリンジャーバンド（20日, &plusmn;2&sigma;）</th><td>'
                . ($tech->bb_upper !== null ? number_format($tech->bb_upper, 1) : '-') . ' / '
                . ($tech->bb_mid   !== null ? number_format($tech->bb_mid, 1)   : '-') . ' / '
                . ($tech->bb_lower !== null ? number_format($tech->bb_lower, 1) : '-')
                . '</td><td>' . $judge_badge($bb_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($bb_reason) . '</td></tr>';
            echo '<tr><th>ストキャス %K/%D（14日）</th><td>' . ($tech->stoch_k !== null ? esc_html($tech->stoch_k) . ' / ' . ($tech->stoch_d !== null ? esc_html($tech->stoch_d) : '-') : '-') . '</td><td>' . $judge_badge($stoch_judge ?? 'neutral') . '</td><td style="font-size:12px;color:#666;">' . esc_html($stoch_reason) . '</td></tr>';
            $fib_reason = intval($tech->fib_near ?? 0) === 1
                ? 'フィボナッチ' . ($tech->fib_level ?? '-') . '水準に接近'
                : '主要水準から離れています';
            echo '<tr><th>フィボナッチ（直近60日）</th><td>' . ($tech->fib_level !== null ? esc_html($tech->fib_level) . ' 水準' : '-') . '</td><td>'
                . (intval($tech->fib_near ?? 0) === 1
                    ? '<span style="background:#e67e22;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:bold;">接近</span>'
                    : '<span style="background:#ddd;color:#888;padding:2px 8px;border-radius:3px;font-size:11px;">-</span>')
                . '</td><td style="font-size:12px;color:#666;">' . esc_html($fib_reason) . '</td></tr>';
            echo '</tbody></table>';
        }

        // ★追加：チャートタブと同じ「外部チャートで確認する」リンク集をテクニカル分析タブの末尾にも表示
        echo wp_stocks_tradingview_widget($stock->code, 450, $is_usd);

        echo '</div>';
    }

    // ===== 基本情報タブ =====
    if ($active_tab === 'info') {
    echo '<div id="tab-info">';

    // ① 詳細テーブル（業種・産業・ウェブサイト・時価総額・売上高・決算予定日・情報更新日）
    echo '<table class="widefat fixed" style="max-width:700px;margin-bottom:25px;"><tbody>';
    foreach ([
        ['業種',       $stock->sector   ?? ''],
        ['産業',       $stock->industry ?? ''],
        ['ウェブサイト', $stock->website ?? ''],
        ['時価総額',   ($stock->market_cap ?? 0) ? number_format(intval($stock->market_cap) / 100000000) . '億円' : ''],
        ['売上高',     ($stock->revenue   ?? 0) ? number_format(intval($stock->revenue)    / 1000000) . '百万円' : ''],
        ['決算予定日', !empty($stock->earnings_date) ? $stock->earnings_date : ''],
        ['情報更新日', $stock->info_updated_at ? date('Y/m/d H:i', strtotime($stock->info_updated_at)) : '未取得'],
    ] as [$label, $val]) {
        if (empty($val)) continue;
        echo '<tr><th style="width:150px;">' . esc_html($label) . '</th>';
        if ($label === 'ウェブサイト') echo '<td><a href="' . esc_url($val) . '" target="_blank">' . esc_html($val) . '</a></td>';
        else echo '<td>' . esc_html($val) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    // 四季報情報（表示のみ・入力は編集タブへ）
    if (!$is_usd && !empty($stock->shikiho)) {
        echo '<h3>&#x1F4D6; 四季報情報</h3>';
        $updated = $stock->shikiho_updated_at ? date('Y/m/d H:i', strtotime($stock->shikiho_updated_at)) : '';
        echo '<div style="background:#fffef0;border:1px solid #e8d44d;border-radius:8px;padding:16px;margin-bottom:25px;">';
        if ($updated) echo '<div style="font-size:11px;color:#888;margin-bottom:8px;">更新日：' . esc_html($updated) . '</div>';
        echo '<pre style="white-space:pre-wrap;word-wrap:break-word;font-family:inherit;font-size:13px;line-height:1.8;margin:0;">' . esc_html($stock->shikiho) . '</pre>';
        echo '</div>';
    }
    
    // ② 財務スコアカード（指標・値・点数・評価の4列）
    echo '<h3>財務スコアカード</h3>';
    $score_result = wp_stocks_calc_score($stock);
    $sc_score     = $score_result['score'];
    $sc_judgment  = $score_result['judgment'];
    $sc_detail    = $score_result['detail'];

    // 指標ごとの実際の値
    // 適正株価計算（EPS × セクター平均PER。自社PERは使わないため循環参照なし）
    $is_usd_stock = ($stock->currency ?? 'JPY') === 'USD';
    $fp_data                            = wp_stocks_calc_fair_price($stock);
    $fair_price_actual                  = $fp_data['actual'];
    $fair_price_forward                 = $fp_data['forward'];
    $fair_price_sector_avg_per          = $fp_data['sector_avg_per'];
    $fair_price_sector_avg_per_forward  = $fp_data['sector_avg_per_forward'];

    $sc_values = [
        'PER'       => ($stock->per ?? 0) > 0 ? number_format($stock->per, 1) . '倍' : 'N/A',
        'PBR'       => ($stock->pbr ?? 0) > 0 ? number_format($stock->pbr, 2) . '倍' : 'N/A',
        'ROE'       => ($stock->roe ?? 0) != 0 ? number_format($stock->roe, 1) . '%' : 'N/A',
        '自己資本比率' => ($stock->equity_ratio ?? 0) > 0 ? number_format($stock->equity_ratio, 1) . '%' : 'N/A',
        '配当利回り' => ($stock->dividend_yield ?? 0) > 0 ? number_format($stock->dividend_yield, 2) . '%' : 'N/A',
        'PEG'       => ($stock->peg ?? 0) > 0 ? number_format($stock->peg, 2) : 'N/A',
        '利益率'    => ($stock->profit_margin ?? 0) != 0 ? number_format($stock->profit_margin, 1) . '%' : 'N/A',
    ];

    echo '<div style="background:#fff;border:2px solid ' . $sc_judgment['color'] . ';border-radius:8px;padding:20px;margin-bottom:25px;">';
    echo '<div style="display:flex;align-items:center;gap:20px;margin-bottom:15px;">';
    echo '<div style="text-align:center;"><div style="font-size:48px;font-weight:bold;color:' . $sc_judgment['color'] . ';">' . $sc_score . '</div><div style="font-size:12px;color:#888;">/ 100点</div></div>';
    echo '<div><div style="font-size:22px;font-weight:bold;color:' . $sc_judgment['color'] . ';">' . $sc_judgment['label'] . '</div><div style="font-size:12px;color:#888;margin-top:4px;">財務スコアカード</div></div>';
    echo '</div>';
    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="background:#f8f9fa;">';
    echo '<th style="padding:6px 10px;text-align:left;border:1px solid #ddd;">指標</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">値</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">点数</th>';
    echo '<th style="padding:6px 10px;text-align:center;border:1px solid #ddd;">評価</th>';
    echo '</tr></thead><tbody>';
    foreach ($sc_detail as $name => $d) {
        $c = $d['点数'] >= 10 ? '#27ae60' : ($d['点数'] >= 5 ? '#f39c12' : '#e74c3c');
        if ($d['評価'] === 'N/A') $c = '#888';
        echo '<tr>';
        echo '<td style="padding:5px 10px;border:1px solid #ddd;">' . esc_html($name) . '</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;">' . esc_html($sc_values[$name] ?? '-') . '</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;color:' . $c . ';">' . $d['点数'] . '点</td>';
        echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;color:' . $c . ';">' . esc_html($d['評価']) . '</td>';
        echo '</tr>';
    }
    // 適正株価行を追加（セクター平均PER基準・循環参照なし）
    if ($fair_price_actual !== null || $fair_price_forward !== null) {
        $usd_jpy_fp = $is_usd_stock ? wp_stocks_get_usd_jpy() : 1;
        if ($fair_price_actual !== null) {
            $fp_str = $is_usd_stock
                ? '$' . number_format($fair_price_actual, 2) . ' <span style="color:#aaa;font-size:11px;">(≈' . number_format($fair_price_actual * $usd_jpy_fp) . '円)</span>'
                : number_format($fair_price_actual, 0) . '円';
            echo '<tr style="background:#fff8e1;">';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;font-weight:bold;">適正株価（実績）</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;color:#e67e22;">' . $fp_str . '</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;color:#888;">-</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-size:11px;color:#888;">EPS×セクター平均PER(' . number_format($fair_price_sector_avg_per, 1) . '倍)</td>';
            echo '</tr>';
        }
        if ($fair_price_forward !== null) {
            $fp_fwd_str = $is_usd_stock
                ? '$' . number_format($fair_price_forward, 2) . ' <span style="color:#aaa;font-size:11px;">(≈' . number_format($fair_price_forward * $usd_jpy_fp) . '円)</span>'
                : number_format($fair_price_forward, 0) . '円';
            echo '<tr style="background:#e8f8f5;">';
            echo '<td style="padding:5px 10px;border:1px solid #ddd;font-weight:bold;">適正株価（予想）</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-weight:bold;color:#27ae60;">' . $fp_fwd_str . '</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;color:#888;">-</td>';
            echo '<td style="padding:5px 10px;text-align:center;border:1px solid #ddd;font-size:11px;color:#888;">予想EPS×セクター平均PER(' . number_format($fair_price_sector_avg_per_forward, 1) . '倍)</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';

    // ③ 株価指標カード
    echo '<h3>株価指標</h3>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">';
    $cards = [
        ['PER（実績）', ($stock->per ?? 0) > 0 ? number_format($stock->per, 1) . '倍' : 'N/A', $per_color, 'PER<15で割安'],
        ['PER（予想）', ($stock->forward_per ?? 0) > 0 ? number_format($stock->forward_per, 1) . '倍' : 'N/A', $per_color, '来期予想PER'],
        ['PBR',         ($stock->pbr ?? 0) > 0 ? number_format($stock->pbr, 2) . '倍' : 'N/A', $pbr_color, 'PBR<1で割安'],
        ['EPS（実績）', ($stock->eps ?? 0) != 0 ? number_format($stock->eps, 1) . '円' : 'N/A', '#555', '1株当たり利益'],
        ['EPS（予想）', ($stock->forward_eps ?? 0) != 0 ? number_format($stock->forward_eps, 1) . '円' : 'N/A', '#555', '来期予想EPS'],
        ['ROE',         ($stock->roe ?? 0) != 0 ? number_format($stock->roe, 1) . '%' : 'N/A', $roe_color, 'ROE>=15%が優良'],
        ['ROA',         ($stock->roa ?? 0) != 0 ? number_format($stock->roa, 1) . '%' : 'N/A', '#555', '総資産利益率'],
        ['PEG',         ($stock->peg ?? 0) > 0 ? number_format($stock->peg, 2) : 'N/A', $peg_color, 'PEG<1で割安成長（Yahoo・予想ベース寄り）'],
        ['PEGトレーリング', ($stock->peg_trailing ?? 0) > 0 ? number_format($stock->peg_trailing, 2) : 'N/A', '#555', '実績PER÷実績利益成長率'],
        ['配当利回り',  ($stock->dividend_yield ?? 0) > 0 ? number_format($stock->dividend_yield, 2) . '%' : 'N/A', '#27ae60', '年間配当÷株価'],
        ['自己資本比率',($stock->equity_ratio ?? 0) > 0 ? number_format($stock->equity_ratio, 1) . '%' : 'N/A', $eq_color, '>=50%が安全の目安'],
    ];
    foreach ($cards as [$label, $val, $color, $tip]) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;text-align:center;min-width:110px;" title="' . esc_attr($tip) . '">';
        echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . $label . '</div>';
        echo '<div style="font-size:18px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    // ④ 業績指標カード
    echo '<h3>業績指標</h3>';
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:25px;">';
    foreach ([
        ['売上成長率', number_format($stock->revenue_growth ?? 0, 1) . '%', $rg_color],
        ['利益成長率', number_format($stock->earnings_growth ?? 0, 1) . '%', $eg_color],
        ['利益率',     number_format($stock->profit_margin ?? 0, 1) . '%', $pm_color],
        ['従業員数',   number_format($stock->employees ?? 0) . '人', '#555'],
        ['時価総額',   ($stock->market_cap ?? 0) > 0 ? number_format(intval($stock->market_cap) / 100000000) . '億円' : 'N/A', '#555'],
    ] as [$label, $val, $color]) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 16px;text-align:center;min-width:110px;">';
        echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . $label . '</div>';
        echo '<div style="font-size:18px;font-weight:bold;color:' . $color . ';">' . esc_html($val) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '</div>'; // tab-info
    } // end info tab

    // ===== チャートタブ =====
    if ($active_tab === 'chart') {
    echo '<div id="tab-chart">';

    // ⑤ チャート
    echo '<h3>チャート</h3>';
    $is_usd_chart = ($stock->currency ?? 'JPY') === 'USD';
    $chart_symbol = $is_usd_chart ? $stock->code : $stock->code . '.T';
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$chart_symbol}?interval=1d&range=6mo";
    $response = wp_remote_get($url, [
        'headers' => ['User-Agent' => 'Mozilla/5.0'],
        'timeout' => 20,
    ]);
    $body   = json_decode(wp_remote_retrieve_body($response), true);
    $result = $body['chart']['result'][0] ?? null;

    if ($result) {
        $timestamps = $result['timestamp'] ?? [];
        $opens      = $result['indicators']['quote'][0]['open']   ?? [];
        $highs      = $result['indicators']['quote'][0]['high']   ?? [];
        $lows       = $result['indicators']['quote'][0]['low']    ?? [];
        $closes     = $result['indicators']['quote'][0]['close']  ?? [];
        $volumes    = $result['indicators']['quote'][0]['volume'] ?? [];

        $candle_data = [];
        $line_data   = [];
        $volume_data = [];

        foreach ($timestamps as $i => $ts) {
            if (!isset($closes[$i]) || $closes[$i] === null) continue;
            $candle_data[] = [
                'time'  => date('Y-m-d', $ts),
                'open'  => round(floatval($opens[$i]  ?? $closes[$i]), 1),
                'high'  => round(floatval($highs[$i]  ?? $closes[$i]), 1),
                'low'   => round(floatval($lows[$i]   ?? $closes[$i]), 1),
                'close' => round(floatval($closes[$i]), 1),
            ];
            $line_data[] = [
                'time'  => date('Y-m-d', $ts),
                'value' => round(floatval($closes[$i]), 1),
            ];
            $volume_data[] = [
                'time'  => date('Y-m-d', $ts),
                'value' => intval($volumes[$i] ?? 0),
                'color' => floatval($closes[$i]) >= floatval($opens[$i] ?? $closes[$i]) ? '#e74c3c88' : '#3498db88',
            ];
        }

        $candle_json = json_encode($candle_data);
        $line_json   = json_encode($line_data);
        $volume_json = json_encode($volume_data);
        ?>
        <div style="margin-bottom:20px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;max-width:90%;margin-left:auto;margin-right:auto;">
            <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:13px;color:#888;">表示：</span>
                <button onclick="setChartType('candlestick')" class="button button-small" id="btn_candle" style="background:#0073aa;color:#fff;">ローソク足</button>
                <button onclick="setChartType('line')" class="button button-small" id="btn_line">ライン</button>
                <span style="font-size:13px;color:#888;margin-left:10px;">サブ：</span>
                <button onclick="setSubChart('volume')" class="button button-small" id="btn_vol" style="background:#0073aa;color:#fff;">出来高</button>
                <button onclick="setSubChart('macd')"   class="button button-small" id="btn_macd">MACD</button>
                <button onclick="setSubChart('rsi')"    class="button button-small" id="btn_rsi">RSI</button>
                <button onclick="setSubChart('rci')"    class="button button-small" id="btn_rci">RCI</button>
                <button onclick="setSubChart('dmi')"    class="button button-small" id="btn_dmi">DMI</button>
                <span style="font-size:13px;color:#888;margin-left:10px;">表示：</span>
                <button onclick="toggleMA()"  class="button button-small" id="btn_ma"  style="background:#0073aa;color:#fff;">MA</button>
                <button onclick="toggleBB()"  class="button button-small" id="btn_bb">BB</button>
                <button onclick="toggleIchimoku()" class="button button-small" id="btn_ichimoku">一目</button>
                <button onclick="toggleFib()" class="button button-small" id="btn_fib">フィボナッチ</button>
                <button onclick="toggleSAR()" class="button button-small" id="btn_sar">パラボリック</button>
            </div>
            <div id="lwChart" style="width:100%;height:700px;"></div>
            <div id="lwChartHandle" style="width:100%;height:8px;background:#f0f0f0;cursor:ns-resize;display:flex;align-items:center;justify-content:center;border-top:1px solid #ddd;border-bottom:1px solid #ddd;margin:2px 0;">
                <div style="width:40px;height:3px;background:#bbb;border-radius:2px;"></div>
            </div>
            <div id="lwSubChart" style="width:100%;height:150px;margin-top:0;"></div>
        </div>

        <script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
        <script>
        var candleRaw  = <?php echo $candle_json; ?>;
        var lineRaw    = <?php echo $line_json; ?>;
        var volumeRaw  = <?php echo $volume_json; ?>;

        // ボリンジャーバンド計算
        function calcBB(data, period, sigma) {
            var result = { upper: [], mid: [], lower: [] };
            for (var i = period - 1; i < data.length; i++) {
                var slice = data.slice(i - period + 1, i + 1);
                var mean  = slice.reduce(function(s, d) { return s + d.value; }, 0) / period;
                var variance = slice.reduce(function(s, d) { return s + Math.pow(d.value - mean, 2); }, 0) / period;
                var std   = Math.sqrt(variance);
                result.upper.push({ time: data[i].time, value: Math.round((mean + sigma * std) * 10) / 10 });
                result.mid.push(  { time: data[i].time, value: Math.round(mean * 10) / 10 });
                result.lower.push({ time: data[i].time, value: Math.round((mean - sigma * std) * 10) / 10 });
            }
            return result;
        }

        // MA計算
        function calcMA(data, period) {
            return data.map(function(d, i) {
                if (i < period - 1) return null;
                var sum = 0;
                for (var j = i - period + 1; j <= i; j++) sum += data[j].value;
                return { time: d.time, value: Math.round(sum / period * 10) / 10 };
            }).filter(function(d) { return d !== null; });
        }

        // EMA計算
        function calcEMA(data, period) {
            var k = 2 / (period + 1);
            var result = [];
            var ema = null;
            for (var i = 0; i < data.length; i++) {
                if (i < period - 1) continue;
                if (ema === null) {
                    var sum = 0;
                    for (var j = 0; j < period; j++) sum += data[j].value;
                    ema = sum / period;
                } else {
                    ema = data[i].value * k + ema * (1 - k);
                }
                result.push({ time: data[i].time, value: Math.round(ema * 100) / 100 });
            }
            return result;
        }

        // MACD計算
        function calcMACD() {
            var ema12 = calcEMA(lineRaw, 12);
            var ema26 = calcEMA(lineRaw, 26);
            var macdMap = {};
            ema12.forEach(function(d) { macdMap[d.time] = { time: d.time, ema12: d.value }; });
            ema26.forEach(function(d) { if (macdMap[d.time]) macdMap[d.time].ema26 = d.value; });

            var macdLine = [];
            Object.keys(macdMap).sort().forEach(function(t) {
                var d = macdMap[t];
                if (d.ema12 !== undefined && d.ema26 !== undefined) {
                    macdLine.push({ time: t, value: Math.round((d.ema12 - d.ema26) * 100) / 100 });
                }
            });

            var signalLine = calcEMA(macdLine, 9);
            var signalMap  = {};
            signalLine.forEach(function(d) { signalMap[d.time] = d.value; });

            var hist = macdLine.filter(function(d) { return signalMap[d.time] !== undefined; })
                .map(function(d) {
                    var h = Math.round((d.value - signalMap[d.time]) * 100) / 100;
                    return { time: d.time, value: h, color: h >= 0 ? '#e74c3c88' : '#3498db88' };
                });

            return { macd: macdLine, signal: signalLine, hist: hist };
        }

        // RSI計算
        function calcRSI(period) {
            var result = [];
            for (var i = period; i < lineRaw.length; i++) {
                var gains = 0, losses = 0;
                for (var j = i - period + 1; j <= i; j++) {
                    var diff = lineRaw[j].value - lineRaw[j-1].value;
                    if (diff > 0) gains += diff;
                    else losses += Math.abs(diff);
                }
                var rs  = losses > 0 ? gains / losses : 999;
                var rsi = Math.round((100 - 100 / (1 + rs)) * 10) / 10;
                result.push({ time: lineRaw[i].time, value: rsi });
            }
            return result;
        }

        // RCI計算
        function calcRCI(data, period) {
            var result = [];
            for (var i = period - 1; i < data.length; i++) {
                var slice = data.slice(i - period + 1, i + 1);
                var priceRank = slice.map(function(d, idx) { return { idx: idx, value: d.value }; });
                priceRank.sort(function(a, b) { return b.value - a.value; });
                var rankOfIdx = {};
                priceRank.forEach(function(pr, rank) { rankOfIdx[pr.idx] = rank + 1; });
                var sumD2 = 0;
                for (var j = 0; j < period; j++) {
                    var dateRank = period - j;
                    var priceR   = rankOfIdx[j];
                    var d = dateRank - priceR;
                    sumD2 += d * d;
                }
                var rci = (1 - 6 * sumD2 / (period * (period * period - 1))) * 100;
                result.push({ time: data[i].time, value: Math.round(rci * 10) / 10 });
            }
            return result;
        }

        // DMI/ADX計算（Wilderスムージング簡易版）
        function calcDMI(candles, period) {
            var plusDM = [], minusDM = [], tr = [];
            for (var i = 1; i < candles.length; i++) {
                var upMove   = candles[i].high - candles[i - 1].high;
                var downMove = candles[i - 1].low - candles[i].low;
                var pdm = (upMove > downMove && upMove > 0) ? upMove : 0;
                var mdm = (downMove > upMove && downMove > 0) ? downMove : 0;
                var trueRange = Math.max(
                    candles[i].high - candles[i].low,
                    Math.abs(candles[i].high - candles[i - 1].close),
                    Math.abs(candles[i].low  - candles[i - 1].close)
                );
                plusDM.push(pdm);
                minusDM.push(mdm);
                tr.push(trueRange);
            }
            function wilderSmooth(values, p) {
                var result = [];
                if (values.length < p) return result;
                var sum = 0;
                for (var k = 0; k < p; k++) sum += values[k];
                result.push(sum);
                for (var k = p; k < values.length; k++) {
                    sum = sum - (sum / p) + values[k];
                    result.push(sum);
                }
                return result;
            }
            var smPlus  = wilderSmooth(plusDM, period);
            var smMinus = wilderSmooth(minusDM, period);
            var smTR    = wilderSmooth(tr, period);
            var plusDI = [], minusDI = [], adx = [];
            for (var k = 0; k < smTR.length; k++) {
                var candleIdx = k + period;
                if (!candles[candleIdx]) continue;
                if (smTR[k] > 0) {
                    var pdi = 100 * smPlus[k]  / smTR[k];
                    var mdi = 100 * smMinus[k] / smTR[k];
                    var dx  = (pdi + mdi) > 0 ? 100 * Math.abs(pdi - mdi) / (pdi + mdi) : 0;
                    plusDI.push({  time: candles[candleIdx].time, value: Math.round(pdi * 10) / 10 });
                    minusDI.push({ time: candles[candleIdx].time, value: Math.round(mdi * 10) / 10 });
                    adx.push({     time: candles[candleIdx].time, value: Math.round(dx  * 10) / 10 });
                }
            }
            return { plusDI: plusDI, minusDI: minusDI, adx: adx };
        }

        // 一目均衡表計算
        function calcIchimoku(candles) {
            function periodHL(idx, period) {
                if (idx < period - 1) return null;
                var hi = -Infinity, lo = Infinity;
                for (var k = idx - period + 1; k <= idx; k++) {
                    if (candles[k].high > hi) hi = candles[k].high;
                    if (candles[k].low  < lo) lo = candles[k].low;
                }
                return { high: hi, low: lo };
            }
            var tenkan = [], kijun = [], senkouARaw = [], senkouBRaw = [], chikou = [];
            for (var i = 0; i < candles.length; i++) {
                var hl9  = periodHL(i, 9);
                var hl26 = periodHL(i, 26);
                var hl52 = periodHL(i, 52);
                var tVal = hl9  ? (hl9.high + hl9.low) / 2   : null;
                var kVal = hl26 ? (hl26.high + hl26.low) / 2 : null;
                if (tVal !== null) tenkan.push({ time: candles[i].time, value: Math.round(tVal * 10) / 10 });
                if (kVal !== null) kijun.push({ time: candles[i].time, value: Math.round(kVal * 10) / 10 });
                if (tVal !== null && kVal !== null) {
                    senkouARaw.push({ index: i, value: Math.round((tVal + kVal) / 2 * 10) / 10 });
                }
                if (hl52) {
                    senkouBRaw.push({ index: i, value: Math.round((hl52.high + hl52.low) / 2 * 10) / 10 });
                }
            }
            function addBusinessDays(dateStr, days) {
                var d = new Date(dateStr + 'T00:00:00');
                var added = 0;
                while (added < days) {
                    d.setDate(d.getDate() + 1);
                    var day = d.getDay();
                    if (day !== 0 && day !== 6) added++;
                }
                return d.toISOString().slice(0, 10);
            }
            var lastDate = candles[candles.length - 1].time;
            var futureDates = [];
            for (var f = 1; f <= 26; f++) futureDates.push(addBusinessDays(lastDate, f));
            function shiftForward(rawArr) {
                var out = [];
                rawArr.forEach(function(item) {
                    var targetIdx = item.index + 26;
                    var time = targetIdx < candles.length ? candles[targetIdx].time : futureDates[targetIdx - candles.length];
                    if (time) out.push({ time: time, value: item.value });
                });
                return out;
            }
            var senkouA = shiftForward(senkouARaw);
            var senkouB = shiftForward(senkouBRaw);
            for (var i2 = 26; i2 < candles.length; i2++) {
                chikou.push({ time: candles[i2 - 26].time, value: candles[i2].close });
            }
            return { tenkan: tenkan, kijun: kijun, senkouA: senkouA, senkouB: senkouB, chikou: chikou };
        }

        // 一目均衡表の雲（先行スパンA/B間）塗りつぶし用プリミティブ
        function IchimokuCloudPaneView(source) {
            this._source = source;
            this._items = [];
        }
        IchimokuCloudPaneView.prototype.update = function() {
            var chart  = this._source._chart;
            var series = this._source._series;
            if (!chart || !series) { this._items = []; return; }
            var timeScale = chart.timeScale();
            var bMap = {};
            this._source._spanB.forEach(function(d) { bMap[d.time] = d.value; });
            var items = [];
            this._source._spanA.forEach(function(d) {
                var bVal = bMap[d.time];
                if (bVal === undefined) return;
                var x  = timeScale.timeToCoordinate(d.time);
                var yA = series.priceToCoordinate(d.value);
                var yB = series.priceToCoordinate(bVal);
                if (x === null || yA === null || yB === null) return;
                items.push({ x: x, yA: yA, yB: yB, bullish: d.value >= bVal });
            });
            this._items = items;
        };
        IchimokuCloudPaneView.prototype.renderer = function() {
            var items = this._items;
            return {
                draw: function(target) {
                    target.useBitmapCoordinateSpace(function(scope) {
                        var ctx = scope.context;
                        var hr  = scope.horizontalPixelRatio;
                        var vr  = scope.verticalPixelRatio;
                        for (var i = 0; i < items.length - 1; i++) {
                            var p0 = items[i], p1 = items[i + 1];
                            ctx.beginPath();
                            ctx.moveTo(p0.x * hr, p0.yA * vr);
                            ctx.lineTo(p1.x * hr, p1.yA * vr);
                            ctx.lineTo(p1.x * hr, p1.yB * vr);
                            ctx.lineTo(p0.x * hr, p0.yB * vr);
                            ctx.closePath();
                            ctx.fillStyle = p0.bullish ? 'rgba(76,175,80,0.15)' : 'rgba(231,76,60,0.15)';
                            ctx.fill();
                        }
                    });
                }
            };
        };
        function IchimokuCloudPrimitive(spanAData, spanBData) {
            this._spanA = spanAData;
            this._spanB = spanBData;
            this._chart = null;
            this._series = null;
            this._paneView = new IchimokuCloudPaneView(this);
        }
        IchimokuCloudPrimitive.prototype.attached = function(param) {
            this._chart = param.chart;
            this._series = param.series;
        };
        IchimokuCloudPrimitive.prototype.updateAllViews = function() {
            this._paneView.update();
        };
        IchimokuCloudPrimitive.prototype.paneViews = function() {
            return [this._paneView];
        };

        var ma5Data   = calcMA(lineRaw, 5);
        var ma25Data  = calcMA(lineRaw, 25);
        var ma75Data  = calcMA(lineRaw, 75);
        var macdData  = calcMACD();
        var rsiData   = calcRSI(14);
        var rciData   = calcRCI(lineRaw, 9);
        var dmiData   = calcDMI(candleRaw, 14);
        var ichimokuData = calcIchimoku(candleRaw);

        var mainChart = null;
        var subChart  = null;
        var currentType = 'candlestick';
        var currentSub  = 'volume';

        var chartOpts = {
            width:  document.getElementById('lwChart').clientWidth,
            height: 700,
            layout: { background: { color: '#fff' }, textColor: '#333' },
            grid: { vertLines: { color: '#f0f0f0' }, horzLines: { color: '#f0f0f0' } },
            crosshair: { mode: 1 },
            rightPriceScale: { borderColor: '#ddd' },
            timeScale: { borderColor: '#ddd', timeVisible: true },
        };

        var subOpts = {
            width:  document.getElementById('lwSubChart').clientWidth,
            height: 150,
            layout: { background: { color: '#fff' }, textColor: '#333' },
            grid: { vertLines: { color: '#f0f0f0' }, horzLines: { color: '#f0f0f0' } },
            rightPriceScale: { borderColor: '#ddd' },
            timeScale: { borderColor: '#ddd', timeVisible: false, visible: false, fixLeftEdge: true, fixRightEdge: true },
        };

        function setChartType(type) {
            currentType = type;
            document.getElementById('btn_candle').style.background = type === 'candlestick' ? '#0073aa' : '';
            document.getElementById('btn_candle').style.color      = type === 'candlestick' ? '#fff'    : '';
            document.getElementById('btn_line').style.background   = type === 'line'        ? '#0073aa' : '';
            document.getElementById('btn_line').style.color        = type === 'line'        ? '#fff'    : '';
            renderMain();
            syncTimeScale();
        }

        function setSubChart(type) {
            currentSub = type;
            ['vol','macd','rsi','rci','dmi'].forEach(function(b) {
                document.getElementById('btn_' + b).style.background = '';
                document.getElementById('btn_' + b).style.color = '';
            });
            var btnMap = { volume: 'vol', macd: 'macd', rsi: 'rsi', rci: 'rci', dmi: 'dmi' };
            document.getElementById('btn_' + btnMap[type]).style.background = '#0073aa';
            document.getElementById('btn_' + btnMap[type]).style.color = '#fff';
            renderSub();
            // メインの表示範囲をサブに適用してから同期
            try {
                var range = mainChart.timeScale().getVisibleLogicalRange();
                if (range) subChart.timeScale().setVisibleLogicalRange(range);
            } catch(e) {}
            syncTimeScale();
        }

        var showMA = true;
        var showBB = false;

        function toggleMA() {
            showMA = !showMA;
            document.getElementById('btn_ma').style.background = showMA ? '#0073aa' : '#ccc';
            document.getElementById('btn_ma').style.color      = showMA ? '#fff'    : '#333';
            renderMain();
        }

        function toggleBB() {
            showBB = !showBB;
            document.getElementById('btn_bb').style.background = showBB ? '#0073aa' : '';
            document.getElementById('btn_bb').style.color      = showBB ? '#fff'    : '';
            renderMain();
        }

        var showIchimoku = false;
        function toggleIchimoku() {
            showIchimoku = !showIchimoku;
            document.getElementById('btn_ichimoku').style.background = showIchimoku ? '#0073aa' : '';
            document.getElementById('btn_ichimoku').style.color      = showIchimoku ? '#fff'    : '';
            renderMain();
        }

        var showFib = false;
        function toggleFib() {
            showFib = !showFib;
            document.getElementById('btn_fib').style.background = showFib ? '#0073aa' : '';
            document.getElementById('btn_fib').style.color      = showFib ? '#fff'    : '';
            renderMain();
        }

        // フィボナッチ・リトレースメント計算
        // 表示期間内（candleRaw全体）の高値・安値から標準的な水準を算出する。
        // 安値→高値の順で出現していれば上昇トレンドの押し目水準として
        // 高値=0%・安値=100%、逆（高値→安値の順）なら下降トレンドの
        // 戻り水準として安値=0%・高値=100%で表示する。
        function calcFibLevels(candles) {
            if (!candles || candles.length < 2) return [];
            var highIdx = 0, lowIdx = 0;
            for (var i = 1; i < candles.length; i++) {
                if (candles[i].high > candles[highIdx].high) highIdx = i;
                if (candles[i].low  < candles[lowIdx].low)   lowIdx  = i;
            }
            var highVal = candles[highIdx].high;
            var lowVal  = candles[lowIdx].low;
            var range   = highVal - lowVal;
            if (range <= 0) return [];
            var uptrend = lowIdx <= highIdx; // 安値が先＝上昇トレンド中の押し目
            var ratios  = [0, 0.236, 0.382, 0.5, 0.618, 0.786, 1];
            return ratios.map(function(r) {
                var price = uptrend ? (highVal - range * r) : (lowVal + range * r);
                return { ratio: r, price: Math.round(price * 100) / 100 };
            });
        }

        var showSAR = false;
        function toggleSAR() {
            showSAR = !showSAR;
            document.getElementById('btn_sar').style.background = showSAR ? '#0073aa' : '';
            document.getElementById('btn_sar').style.color      = showSAR ? '#fff'    : '';
            renderMain();
        }

        // パラボリックSAR計算（標準的なWilder式、AF初期値0.02・刻み0.02・上限0.2）
        function calcSAR(candles, step, maxStep) {
            step = step || 0.02;
            maxStep = maxStep || 0.2;
            var result = [];
            if (!candles || candles.length < 2) return result;

            var uptrend = candles[1].close >= candles[0].close;
            var af  = step;
            var ep  = uptrend ? candles[0].high : candles[0].low;
            var sar = uptrend ? candles[0].low  : candles[0].high;
            result.push({ time: candles[0].time, value: Math.round(sar * 100) / 100 });

            for (var i = 1; i < candles.length; i++) {
                var prevSar = sar;
                sar = prevSar + af * (ep - prevSar);

                var prev1 = candles[i - 1];
                var prev2 = candles[i - 2] || prev1;

                if (uptrend) {
                    sar = Math.min(sar, prev1.low, prev2.low);
                    if (candles[i].low < sar) {
                        uptrend = false;
                        sar = ep;
                        ep  = candles[i].low;
                        af  = step;
                    } else if (candles[i].high > ep) {
                        ep = candles[i].high;
                        af = Math.min(af + step, maxStep);
                    }
                } else {
                    sar = Math.max(sar, prev1.high, prev2.high);
                    if (candles[i].high > sar) {
                        uptrend = true;
                        sar = ep;
                        ep  = candles[i].high;
                        af  = step;
                    } else if (candles[i].low < ep) {
                        ep = candles[i].low;
                        af = Math.min(af + step, maxStep);
                    }
                }
                result.push({ time: candles[i].time, value: Math.round(sar * 100) / 100 });
            }
            return result;
        }

        function renderMain() {
            if (mainChart) { mainChart.remove(); mainChart = null; }
            document.getElementById('lwChart').innerHTML = '';
            mainChart = LightweightCharts.createChart(document.getElementById('lwChart'), chartOpts);

            var mainSeries = null;
            if (currentType === 'candlestick') {
                var cs = mainChart.addCandlestickSeries({
                    upColor: '#e74c3c', downColor: '#3498db',
                    borderUpColor: '#e74c3c', borderDownColor: '#3498db',
                    wickUpColor: '#e74c3c', wickDownColor: '#3498db',
                });
                cs.setData(candleRaw);
                mainSeries = cs;
            } else {
                var ls = mainChart.addLineSeries({ color: '#333', lineWidth: 2 });
                ls.setData(lineRaw);
                mainSeries = ls;
            }

            // MA線
            if (showMA) {
                if (ma5Data.length > 0) {
                    var ma5s = mainChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: 'MA5' });
                    ma5s.setData(ma5Data);
                }
                if (ma25Data.length > 0) {
                    var ma25s = mainChart.addLineSeries({ color: '#3498db', lineWidth: 1, lineStyle: 1, priceLineVisible: false, lastValueVisible: false, title: 'MA25' });
                    ma25s.setData(ma25Data);
                }
                if (ma75Data.length > 0) {
                    var ma75s = mainChart.addLineSeries({ color: '#27ae60', lineWidth: 1, lineStyle: 2, priceLineVisible: false, lastValueVisible: false, title: 'MA75' });
                    ma75s.setData(ma75Data);
                }
            }

            // ボリンジャーバンド（±1σ・±2σ の4本）
            if (showBB) {
                var bbData2 = calcBB(lineRaw, 20, 2);
                var bbData1 = calcBB(lineRaw, 20, 1);
                if (bbData2.upper.length > 0) {
                    var bbUpper2 = mainChart.addLineSeries({ color: '#9b59b688', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: 'BB+2σ' });
                    bbUpper2.setData(bbData2.upper);
                    var bbUpper1 = mainChart.addLineSeries({ color: '#9b59b666', lineWidth: 1, lineStyle: 2, priceLineVisible: false, lastValueVisible: false, title: 'BB+1σ' });
                    bbUpper1.setData(bbData1.upper);
                    var bbMid = mainChart.addLineSeries({ color: '#9b59b6', lineWidth: 1, lineStyle: 1, priceLineVisible: false, lastValueVisible: false, title: 'BB中心' });
                    bbMid.setData(bbData2.mid);
                    var bbLower1 = mainChart.addLineSeries({ color: '#9b59b666', lineWidth: 1, lineStyle: 2, priceLineVisible: false, lastValueVisible: false, title: 'BB-1σ' });
                    bbLower1.setData(bbData1.lower);
                    var bbLower2 = mainChart.addLineSeries({ color: '#9b59b688', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: 'BB-2σ' });
                    bbLower2.setData(bbData2.lower);
                }
            }

            // 一目均衡表
            if (showIchimoku) {
                if (ichimokuData.tenkan.length > 0) {
                    var tenkanLine = mainChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '転換線' });
                    tenkanLine.setData(ichimokuData.tenkan);
                }
                if (ichimokuData.kijun.length > 0) {
                    var kijunLine = mainChart.addLineSeries({ color: '#3498db', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '基準線' });
                    kijunLine.setData(ichimokuData.kijun);
                }
                if (ichimokuData.chikou.length > 0) {
                    var chikouLine = mainChart.addLineSeries({ color: '#27ae60', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '遅行スパン' });
                    chikouLine.setData(ichimokuData.chikou);
                }
                if (ichimokuData.senkouA.length > 0 && ichimokuData.senkouB.length > 0) {
                    var senkouALine = mainChart.addLineSeries({ color: 'rgba(76,175,80,0.5)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '先行スパンA' });
                    senkouALine.setData(ichimokuData.senkouA);
                    var senkouBLine = mainChart.addLineSeries({ color: 'rgba(231,76,60,0.5)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false, title: '先行スパンB' });
                    senkouBLine.setData(ichimokuData.senkouB);
                    if (typeof senkouBLine.attachPrimitive === 'function') {
                        senkouBLine.attachPrimitive(new IchimokuCloudPrimitive(ichimokuData.senkouA, ichimokuData.senkouB));
                    }
                }
            }

            // パラボリックSAR（ドット表示）
            if (showSAR) {
                var sarData = calcSAR(candleRaw);
                if (sarData.length > 0) {
                    var sarSeries = mainChart.addLineSeries({
                        color: '#8e44ad',
                        lineVisible: false,
                        pointMarkersVisible: true,
                        pointMarkersRadius: 2,
                        lastValueVisible: false,
                        priceLineVisible: false,
                        title: 'SAR',
                    });
                    sarSeries.setData(sarData);
                }
            }

            // フィボナッチ・リトレースメント（水平線）
            if (showFib) {
                var fibLevels = calcFibLevels(candleRaw);
                fibLevels.forEach(function(lv) {
                    mainSeries.createPriceLine({
                        price: lv.price,
                        color: '#f39c12',
                        lineWidth: 1,
                        lineStyle: 2,
                        axisLabelVisible: true,
                        title: (lv.ratio * 100).toFixed(1) + '%',
                    });
                });
            }

            mainChart.timeScale().fitContent();
            // メインチャートが作り直されたので同期リスナーを再登録
            syncMainToSub();
        }

        function renderSub() {
            if (subChart) { subChart.remove(); subChart = null; }
            document.getElementById('lwSubChart').innerHTML = '';
            // 出来高表示時のみ fixLeftEdge/fixRightEdge を外し、メインと同じだけズームできるようにする
            // （MACD/RSI/RCI/DMIは先頭データが欠けているため、既存のsubOptsのまま維持する）
            var subOptsForRender = subOpts;
            if (currentSub === 'volume') {
                subOptsForRender = Object.assign({}, subOpts, {
                    timeScale: Object.assign({}, subOpts.timeScale, {
                        fixLeftEdge: false,
                        fixRightEdge: false,
                        minBarSpacing: 0.1,
                    }),
                });
            }
            subChart = LightweightCharts.createChart(document.getElementById('lwSubChart'), subOptsForRender);

            if (currentSub === 'volume') {
                var vs = subChart.addHistogramSeries({ priceFormat: { type: 'volume' }, priceScaleId: '' });
                vs.setData(volumeRaw);
                subChart.priceScale('').applyOptions({ scaleMargins: { top: 0.1, bottom: 0 } });

            } else if (currentSub === 'macd') {
                var hist = subChart.addHistogramSeries({ priceScaleId: 'macd', lastValueVisible: false, priceLineVisible: false });
                hist.setData(macdData.hist);
                var macdLine = subChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceScaleId: 'macd', lastValueVisible: false, priceLineVisible: false });
                macdLine.setData(macdData.macd);
                var sigLine = subChart.addLineSeries({ color: '#3498db', lineWidth: 1, priceScaleId: 'macd', lastValueVisible: false, priceLineVisible: false });
                sigLine.setData(macdData.signal);

            } else if (currentSub === 'rsi') {
                var rsiLine = subChart.addLineSeries({ color: '#9b59b6', lineWidth: 1, priceScaleId: 'rsi', lastValueVisible: true, priceLineVisible: false });
                rsiLine.setData(rsiData);
                subChart.priceScale('rsi').applyOptions({ scaleMargins: { top: 0.1, bottom: 0.1 }, autoScale: true, minimum: 0, maximum: 100 });

            } else if (currentSub === 'rci') {
                var rciLine = subChart.addLineSeries({ color: '#16a085', lineWidth: 1, priceScaleId: 'rci', lastValueVisible: true, priceLineVisible: false });
                rciLine.setData(rciData);
                subChart.priceScale('rci').applyOptions({ scaleMargins: { top: 0.1, bottom: 0.1 }, autoScale: true, minimum: -100, maximum: 100 });

            } else if (currentSub === 'dmi') {
                var plusDILine = subChart.addLineSeries({ color: '#27ae60', lineWidth: 1, priceScaleId: 'dmi', lastValueVisible: true, priceLineVisible: false, title: '+DI' });
                plusDILine.setData(dmiData.plusDI);
                var minusDILine = subChart.addLineSeries({ color: '#e74c3c', lineWidth: 1, priceScaleId: 'dmi', lastValueVisible: true, priceLineVisible: false, title: '-DI' });
                minusDILine.setData(dmiData.minusDI);
                var adxLine = subChart.addLineSeries({ color: '#888', lineWidth: 1, lineStyle: 2, priceScaleId: 'dmi', lastValueVisible: true, priceLineVisible: false, title: 'ADX' });
                adxLine.setData(dmiData.adx);
                subChart.priceScale('dmi').applyOptions({ scaleMargins: { top: 0.1, bottom: 0.1 }, autoScale: true });
            }

            // メインチャートの表示範囲に合わせる
            try {
                var range = mainChart.timeScale().getVisibleLogicalRange();
                if (range) {
                    subChart.timeScale().setVisibleLogicalRange(range);
                } else {
                    subChart.timeScale().fitContent();
                }
            } catch(e) {
                subChart.timeScale().fitContent();
            }

            // サブチャートが作り直されたので同期リスナーを再登録
            syncSubToMain();
        }

        // 時間軸同期（メイン→サブ方向は一度だけ張れば良い。
        // サブ側は renderSub() でインスタンスが作り直されるたびに再登録する）
        // isSyncingRange: プログラムによる setVisibleLogicalRange 実行中は
        // 相手側からのエコーバック（無限ループ）を無視するためのフラグ
        var isSyncingRange = false;

        function syncMainToSub() {
            if (!mainChart) return;
            mainChart.timeScale().subscribeVisibleLogicalRangeChange(function(range) {
                if (isSyncingRange) return;
                if (range && subChart) {
                    isSyncingRange = true;
                    subChart.timeScale().setVisibleLogicalRange(range);
                    requestAnimationFrame(function() { isSyncingRange = false; });
                }
            });
        }

        function syncSubToMain() {
            if (!subChart || !mainChart) return;
            subChart.timeScale().subscribeVisibleLogicalRangeChange(function(range) {
                if (isSyncingRange) return;
                if (range && mainChart) {
                    isSyncingRange = true;
                    mainChart.timeScale().setVisibleLogicalRange(range);
                    requestAnimationFrame(function() { isSyncingRange = false; });
                }
            });
        }

        // 初期表示（syncMainToSub/syncSubToMainはrenderMain/renderSubの中で自動登録される）
        renderMain();
        renderSub();

        // ドラッグリサイズ対応
        (function() {
            var handle    = document.getElementById('lwChartHandle');
            var mainDiv   = document.getElementById('lwChart');
            var subDiv    = document.getElementById('lwSubChart');
            var dragging  = false;
            var startY    = 0;
            var startMainH = 0;
            var startSubH  = 0;

            handle.addEventListener('mousedown', function(e) {
                dragging   = true;
                startY     = e.clientY;
                startMainH = mainDiv.clientHeight;
                startSubH  = subDiv.clientHeight;
                document.body.style.cursor = 'ns-resize';
                document.body.style.userSelect = 'none';
                e.preventDefault();
            });

            document.addEventListener('mousemove', function(e) {
                if (!dragging) return;
                var diff    = e.clientY - startY;
                var newMainH = Math.max(100, startMainH + diff);
                var newSubH  = Math.max(60,  startSubH  - diff);
                mainDiv.style.height = newMainH + 'px';
                subDiv.style.height  = newSubH  + 'px';
                if (mainChart) mainChart.applyOptions({ height: newMainH });
                if (subChart)  subChart.applyOptions({ height: newSubH });
            });

            document.addEventListener('mouseup', function() {
                if (!dragging) return;
                dragging = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            });

            // タッチ対応
            handle.addEventListener('touchstart', function(e) {
                dragging   = true;
                startY     = e.touches[0].clientY;
                startMainH = mainDiv.clientHeight;
                startSubH  = subDiv.clientHeight;
                e.preventDefault();
            }, { passive: false });

            document.addEventListener('touchmove', function(e) {
                if (!dragging) return;
                var diff    = e.touches[0].clientY - startY;
                var newMainH = Math.max(100, startMainH + diff);
                var newSubH  = Math.max(60,  startSubH  - diff);
                mainDiv.style.height = newMainH + 'px';
                subDiv.style.height  = newSubH  + 'px';
                if (mainChart) mainChart.applyOptions({ height: newMainH });
                if (subChart)  subChart.applyOptions({ height: newSubH });
            }, { passive: false });

            document.addEventListener('touchend', function() {
                dragging = false;
            });
        })();

        // ウィンドウリサイズ対応
        window.addEventListener('resize', function() {
            var w = document.getElementById('lwChart').clientWidth;
            if (mainChart) mainChart.applyOptions({ width: w });
            if (subChart)  subChart.applyOptions({ width: document.getElementById('lwSubChart').clientWidth });
        });
        </script>
        <?php
    } else {
        echo '<p style="color:#888;">チャートデータを取得できませんでした。</p>';
    }

    // ★削除：チャート下の簡易テクニカル指標ボックスは「テクニカル分析」タブに統合したため撤去

    // メモ表示（表示のみ・入力は編集タブへ）
    if (!empty($stock->memo)) {
        echo '<h3>&#x1F4DD; 銘柄メモ</h3>';
        echo '<div style="background:#f0f8ff;border:1px solid #a8d4f5;border-radius:8px;padding:16px;margin-bottom:20px;">';
        echo '<pre style="white-space:pre-wrap;word-wrap:break-word;font-family:inherit;font-size:13px;line-height:1.8;margin:0;">' . esc_html($stock->memo) . '</pre>';
        echo '</div>';
    }

    // 企業情報再取得ボタン
    $info_url = wp_nonce_url(admin_url('admin-post.php?action=update_company_info&id=' . $id . '&from=wp-stocks-company'), 'wp_stocks_action_' . $id);
    echo '<p><a href="' . esc_url($info_url) . '" class="button">&#x1F504; Yahoo!ファイナンスから企業情報を再取得</a></p>';


    // 外部チャートリンク
    echo wp_stocks_tradingview_widget($stock->code, 450, $is_usd);

    echo '</div>'; // tab-chart
    } // end chart tab

    // ===== 財務タブ =====
    if ($active_tab === 'finance') {
    echo '<div id="tab-finance">';

    if (!$is_usd) {
        $shikiho_url = 'https://shikiho.toyokeizai.net/stocks/' . rawurlencode($stock->code) . '/forecast';
        echo '<p style="margin-bottom:20px;"><a href="' . esc_url($shikiho_url) . '" target="_blank" rel="noopener" class="button button-primary">&#x1F4D6; 四季報オンラインで財務情報を見る</a></p>';
    }

    echo '<h2 style="border-left:4px solid #0073aa;padding-left:10px;">年間</h2>';

    if (!$is_usd) {
    $financials = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_financials WHERE stock_id = %d ORDER BY fiscal_year ASC", $id
    ));

    if (empty($financials)) {
        echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;margin-bottom:20px;">';
        echo '<p style="font-size:16px;font-weight:bold;margin-bottom:10px;">&#x1F4B0; 財務データがまだ取得されていません</p>';
        echo '<p style="font-size:13px;">設定ページから「財務データ取得」を実行するか、下のボタンで取得してください。</p>';
        $fin_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_financials&stock_id=' . $id . '&from=finance'), 'wp_stocks_fetch_fin_' . $id);
        echo '<p style="margin-top:15px;"><a href="' . esc_url($fin_url) . '" class="button button-primary">&#x1F4CA; EDINETから財務データを取得</a></p>';
        echo '</div>';
    } else {
        // グラフ用データ準備
        $fin_labels   = [];
        $fin_revenue  = [];
        $fin_op_profit = [];
        $fin_net_income = [];
        $fin_eq_ratio = [];
        $fin_op_margin = [];
        foreach ($financials as $f) {
            $fin_labels[]    = esc_js($f->period_label ?: $f->fiscal_year);
            $fin_revenue[]   = $f->revenue     ? round($f->revenue / 1000000)     : 0;
            $fin_op_profit[] = $f->operating_profit ? round($f->operating_profit / 1000000) : 0;
            $fin_net_income[] = $f->net_income  ? round($f->net_income / 1000000)  : 0;
            $fin_eq_ratio[]  = $f->equity_ratio ?? 0;
            // 営業利益率（売上高が無い期間はグラフ上プロットしない）
            $calc_op_margin = ($f->revenue && $f->revenue > 0 && $f->operating_profit !== null)
                ? round($f->operating_profit / $f->revenue * 100, 1)
                : null;
            // ±1000%を超える場合は明らかなデータ抽出異常とみなし、グラフには出さない
            // （実際の業績悪化による大きなマイナスまでは消さない。表示範囲の固定はJS側で対応）
            $fin_op_margin[] = ($calc_op_margin !== null && abs($calc_op_margin) <= 1000) ? $calc_op_margin : null;
        }
        $labels_json    = json_encode($fin_labels);
        $revenue_json   = json_encode($fin_revenue);
        $op_profit_json = json_encode($fin_op_profit);
        $net_income_json = json_encode($fin_net_income);
        $eq_ratio_json  = json_encode($fin_eq_ratio);
        $op_margin_json = json_encode($fin_op_margin);

        // 売上高・営業利益・純利益の3系列を同一スケールで描画するための共通min/maxを算出
        $fin_amount_values = array_merge($fin_revenue, $fin_op_profit, $fin_net_income);
        $fin_amount_max = !empty($fin_amount_values) ? max($fin_amount_values) : 0;
        $fin_amount_min = !empty($fin_amount_values) ? min(0, min($fin_amount_values)) : 0;
        // 上端に少し余白を持たせる
        $fin_amount_max = $fin_amount_max > 0 ? ceil(($fin_amount_max * 1.1) / 1000) * 1000 : 1000;
        $fin_amount_max_json = json_encode($fin_amount_max);
        $fin_amount_min_json = json_encode($fin_amount_min);

        // 財務データ取得ボタン
        $fin_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_financials&stock_id=' . $id . '&from=finance'), 'wp_stocks_fetch_fin_' . $id);
        echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($fin_url) . '" class="button">&#x1F504; EDINETから財務データを再取得</a></p>';

        ?>
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

        <!-- 業績グラフ（売上高・営業利益・純利益＋営業利益率） -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
            <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 業績グラフ（百万円／営業利益率%）</h3>
            <div id="finChart1"></div>
        </div>

        <!-- 自己資本比率 折れ線グラフ -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
            <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4CA; 自己資本比率（%）</h3>
            <div id="finChart2"></div>
        </div>

        <!-- 財務データ一覧テーブル -->
        <div style="overflow-x:auto;margin-bottom:20px;">
            <table class="widefat" style="font-size:13px;">
                <thead><tr>
                    <th>期</th>
                    <th>売上高（百万円）</th>
                    <th>営業利益（百万円）</th>
                    <th>純利益（百万円）</th>
                    <th>自己資本比率</th>
                </tr></thead>
                <tbody>
                <?php foreach ($financials as $f): ?>
                <tr>
                    <td><?php echo esc_html($f->period_label ?: $f->fiscal_year); ?></td>
                    <td><?php echo $f->revenue ? number_format($f->revenue / 1000000) : '-'; ?></td>
                    <td><?php echo $f->operating_profit ? number_format($f->operating_profit / 1000000) : '-'; ?></td>
                    <td><?php echo $f->net_income ? number_format($f->net_income / 1000000) : '-'; ?></td>
                    <td><?php echo $f->equity_ratio ? number_format($f->equity_ratio, 1) . '%' : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        // 業績グラフ（棒：売上高・営業利益・純利益／折れ線：営業利益率）
        var chart1 = new ApexCharts(document.getElementById('finChart1'), {
            chart: { height: 360, toolbar: { show: false } },
            series: [
                { name: '売上高',     type: 'column', data: <?php echo $revenue_json; ?> },
                { name: '営業利益',   type: 'column', data: <?php echo $op_profit_json; ?> },
                { name: '純利益',     type: 'column', data: <?php echo $net_income_json; ?> },
                { name: '営業利益率', type: 'line',   data: <?php echo $op_margin_json; ?> },
            ],
            xaxis: { categories: <?php echo $labels_json; ?> },
            colors: ['#2ecc71', '#2c3e50', '#3498db', '#f39c12'],
            stroke: { width: [0, 0, 0, 3], curve: 'smooth' },
            markers: { size: [0, 0, 0, 4], colors: ['#f39c12'] },
            dataLabels: { enabled: false },
            legend: { position: 'top' },
            plotOptions: { bar: { columnWidth: '65%' } },
            yaxis: [
                {
                    seriesName: '売上高',
                    min: <?php echo $fin_amount_min_json; ?>,
                    max: <?php echo $fin_amount_max_json; ?>,
                    title: { text: '百万円', style: { fontSize: '11px' } },
                    labels: { formatter: function(v) { return v.toLocaleString(); } },
                },
                {
                    seriesName: '営業利益',
                    min: <?php echo $fin_amount_min_json; ?>,
                    max: <?php echo $fin_amount_max_json; ?>,
                    show: false,
                },
                {
                    seriesName: '純利益',
                    min: <?php echo $fin_amount_min_json; ?>,
                    max: <?php echo $fin_amount_max_json; ?>,
                    show: false,
                },
                {
                    seriesName: '営業利益率',
                    opposite: true,
                    min: -100,
                    max: 100,
                    tickAmount: 4,
                    title: { text: '営業利益率(%)', style: { fontSize: '11px' } },
                    labels: { formatter: function(v) { return v === null ? '' : v + '%'; } },
                },
            ],
            tooltip: {
                y: {
                    formatter: function(v, opts) {
                        if (v === null || v === undefined) return '-';
                        var seriesIndex = opts.seriesIndex;
                        return seriesIndex === 3 ? v + '%' : Number(v).toLocaleString() + '百万円';
                    }
                }
            },
        });
        chart1.render();

        // 折れ線グラフ（自己資本比率）
        var chart2 = new ApexCharts(document.getElementById('finChart2'), {
            chart: { type: 'line', height: 200, toolbar: { show: false } },
            series: [{ name: '自己資本比率', data: <?php echo $eq_ratio_json; ?> }],
            xaxis: { categories: <?php echo $labels_json; ?> },
            colors: ['#9b59b6'],
            dataLabels: { enabled: true, formatter: function(v) { return v + '%'; } },
            stroke: { width: 3, curve: 'smooth' },
            yaxis: { labels: { formatter: function(v) { return v + '%'; } }, min: 0, max: 100 },
            markers: { size: 5 },
        });
        chart2.render();
        </script>
        <?php
    }

    } else {
        // ===== 米国株: SEC EDGAR由来の年間業績 =====
        $edgar_qf = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'edgar' AND fiscal_year IS NOT NULL AND fiscal_quarter IS NOT NULL ORDER BY fiscal_year ASC, fiscal_quarter ASC", $id
        ));
        $edgar_fetch_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_quarterly_financials_edgar&stock_id=' . $id), 'wp_stocks_fetch_qfin_edgar_' . $id);

        if (empty($edgar_qf)) {
            echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:30px;text-align:center;color:#888;margin-bottom:20px;">';
            echo '<p style="font-size:16px;font-weight:bold;margin-bottom:10px;">&#x1F4B0; 財務データがまだ取得されていません</p>';
            echo '<p style="font-size:13px;">下のボタンでSEC EDGARから取得してください。</p>';
            echo '<p style="margin-top:15px;"><a href="' . esc_url($edgar_fetch_url) . '" class="button button-primary">&#x1F4CA; SEC EDGARから財務データを取得</a></p>';
            echo '</div>';
        } else {
            $by_fy = array();
            foreach ($edgar_qf as $row) {
                $by_fy[$row->fiscal_year][$row->fiscal_quarter] = $row;
            }
            ksort($by_fy);

            $annual_labels = array(); $annual_revenue = array(); $annual_net_income = array(); $annual_margin = array();
            foreach ($by_fy as $fy => $quarters) {
                if (count($quarters) < 4) continue; // 4四半期揃っている年度のみ年間集計に採用
                $rev_sum = 0; $ni_sum = 0; $rev_ok = true; $ni_ok = true;
                foreach ($quarters as $q) {
                    if ($q->revenue === null) $rev_ok = false; else $rev_sum += $q->revenue;
                    if ($q->net_income === null) $ni_ok = false; else $ni_sum += $q->net_income;
                }
                $annual_labels[]     = 'FY' . $fy;
                $annual_revenue[]    = $rev_ok ? round($rev_sum / 1000000) : null;
                $annual_net_income[] = $ni_ok ? round($ni_sum / 1000000) : null;
                $annual_margin[]     = ($rev_ok && $ni_ok && $rev_sum != 0) ? round($ni_sum / $rev_sum * 100, 1) : null;
            }

            echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($edgar_fetch_url) . '" class="button">&#x1F504; SEC EDGARから財務データを再取得</a></p>';
            ?>
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
                <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 業績グラフ（百万ドル／純利益率%）</h3>
                <div id="finChartUsAnnual"></div>
            </div>
            <div style="overflow-x:auto;margin-bottom:20px;">
                <table class="widefat" style="font-size:13px;">
                    <thead><tr><th>会計年度</th><th>売上高（百万ドル）</th><th>純利益（百万ドル）</th><th>純利益率</th></tr></thead>
                    <tbody>
                    <?php foreach ($annual_labels as $i => $label): ?>
                    <tr>
                        <td><?php echo esc_html($label); ?></td>
                        <td><?php echo $annual_revenue[$i] !== null ? number_format($annual_revenue[$i]) : '-'; ?></td>
                        <td><?php echo $annual_net_income[$i] !== null ? number_format($annual_net_income[$i]) : '-'; ?></td>
                        <td><?php echo $annual_margin[$i] !== null ? number_format($annual_margin[$i], 1) . '%' : '-'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
            new ApexCharts(document.getElementById('finChartUsAnnual'), {
                chart: { height: 360, toolbar: { show: false } },
                series: [
                    { name: '売上高',   type: 'column', data: <?php echo json_encode($annual_revenue); ?> },
                    { name: '純利益',   type: 'column', data: <?php echo json_encode($annual_net_income); ?> },
                    { name: '純利益率', type: 'line',   data: <?php echo json_encode($annual_margin); ?> },
                ],
                xaxis: { categories: <?php echo json_encode($annual_labels); ?> },
                colors: ['#2ecc71', '#3498db', '#f39c12'],
                stroke: { width: [0, 0, 3], curve: 'smooth' },
                markers: { size: [0, 0, 4], colors: ['#f39c12'] },
                dataLabels: { enabled: false },
                legend: { position: 'top' },
                plotOptions: { bar: { columnWidth: '55%' } },
                yaxis: [
                    { seriesName: '売上高', title: { text: '百万ドル', style: { fontSize: '11px' } }, labels: { formatter: function(v) { return v === null ? '' : v.toLocaleString(); } } },
                    { seriesName: '純利益', show: false },
                    { seriesName: '純利益率', opposite: true, min: -50, max: 50, title: { text: '純利益率(%)', style: { fontSize: '11px' } }, labels: { formatter: function(v) { return v === null ? '' : v + '%'; } } },
                ],
                tooltip: { y: { formatter: function(v, opts) { if (v === null || v === undefined) return '-'; return opts.seriesIndex === 2 ? v + '%' : Number(v).toLocaleString() + '百万ドル'; } } },
            }).render();
            </script>
            <?php
        }
    }
    echo '<h2 style="border-left:4px solid #0073aa;padding-left:10px;margin-top:30px;">四半期</h2>';

    if (!$is_usd) {
        $qf = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'yahoo' ORDER BY period_end DESC", $id
        ));
        $qf_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_quarterly_financials&stock_id=' . $id), 'wp_stocks_fetch_qfin_' . $id);
        echo '<p style="color:#e67e22;font-size:12px;background:#fff8e1;border:1px solid #ffe08a;border-radius:4px;padding:8px 12px;margin-bottom:15px;">&#x26A0;&#xFE0F; Yahoo Finance由来のデータです。日本株は欠損や精度のばらつきがある場合があります。参考情報としてご利用ください。</p>';
        echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($qf_url) . '" class="button">&#x1F504; Yahoo Financeから四半期データを取得</a></p>';
        if (empty($qf)) {
            echo '<p style="color:#888;">四半期データがまだ取得されていません。上のボタンから取得してください。</p>';
        } else {
            echo '<table class="widefat" style="font-size:13px;"><thead><tr><th>期末日</th><th>売上高（百万円）</th><th>純利益（百万円）</th></tr></thead><tbody>';
            foreach ($qf as $q) {
                echo '<tr><td>' . esc_html($q->period_end) . '</td><td>' . ($q->revenue ? number_format($q->revenue / 1000000) : '-') . '</td><td>' . ($q->net_income ? number_format($q->net_income / 1000000) : '-') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    } else {
        // ===== 米国株: SEC EDGAR由来の四半期（累計比較＋進捗率ゲージ） =====
        $edgar_qf = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}stock_quarterly_financials WHERE stock_id = %d AND source = 'edgar' AND fiscal_year IS NOT NULL AND fiscal_quarter IS NOT NULL ORDER BY fiscal_year ASC, fiscal_quarter ASC", $id
        ));
        $edgar_fetch_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_quarterly_financials_edgar&stock_id=' . $id), 'wp_stocks_fetch_qfin_edgar_' . $id);
        echo '<p style="text-align:right;margin-bottom:15px;"><a href="' . esc_url($edgar_fetch_url) . '" class="button">&#x1F504; SEC EDGARから四半期データを取得</a></p>';

        if (empty($edgar_qf)) {
            echo '<p style="color:#888;">四半期データがまだ取得されていません。上のボタンから取得してください。</p>';
        } else {
            $by_fy = array();
            foreach ($edgar_qf as $row) {
                $by_fy[$row->fiscal_year][$row->fiscal_quarter] = $row;
            }
            krsort($by_fy);
            $fys     = array_keys($by_fy);
            $cur_fy  = $fys[0] ?? null;
            $prev_fy = $fys[1] ?? null;

            $cur_quarters  = $cur_fy  !== null ? $by_fy[$cur_fy]  : array();
            $prev_quarters = $prev_fy !== null ? $by_fy[$prev_fy] : array();

            $cur_revenue_cum  = wp_stocks_sec_cumulative_quarters($cur_quarters, 'revenue');
            $cur_ni_cum       = wp_stocks_sec_cumulative_quarters($cur_quarters, 'net_income');
            $prev_revenue_cum = wp_stocks_sec_cumulative_quarters($prev_quarters, 'revenue');
            $prev_ni_cum      = wp_stocks_sec_cumulative_quarters($prev_quarters, 'net_income');

            $categories = array('1Q', '2Q累計', '3Q累計', '通期');

            // 進捗率ゲージ：当期の最新累計 ÷ 前期通期実績
            $prev_revenue_full = $prev_revenue_cum[3];
            $prev_ni_full      = $prev_ni_cum[3];
            $cur_revenue_latest = null;
            foreach (array_reverse($cur_revenue_cum) as $v) { if ($v !== null) { $cur_revenue_latest = $v; break; } }
            $cur_ni_latest = null;
            foreach (array_reverse($cur_ni_cum) as $v) { if ($v !== null) { $cur_ni_latest = $v; break; } }
            $revenue_progress = (!empty($prev_revenue_full) && $cur_revenue_latest !== null) ? round($cur_revenue_latest / $prev_revenue_full * 100, 1) : null;
            $ni_progress      = (!empty($prev_ni_full) && $cur_ni_latest !== null) ? round($cur_ni_latest / $prev_ni_full * 100, 1) : null;
            ?>
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:20px;">
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;flex:1;min-width:200px;text-align:center;">
                    <h3 style="margin:0 0 5px 0;font-size:14px;">売上高 進捗率</h3>
                    <div id="gaugeUsRevenue"></div>
                </div>
                <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;flex:1;min-width:200px;text-align:center;">
                    <h3 style="margin:0 0 5px 0;font-size:14px;">純利益 進捗率</h3>
                    <div id="gaugeUsNetIncome"></div>
                </div>
            </div>

            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
                <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 売上高（前期比・百万ドル）</h3>
                <div id="finChartUsQuarterlyRevenue"></div>
            </div>
            <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">
                <h3 style="margin:0 0 15px 0;font-size:14px;">&#x1F4B9; 純利益（前期比・百万ドル）</h3>
                <div id="finChartUsQuarterlyNetIncome"></div>
            </div>

            <script>
            (function() {
                function wpStocksUsGauge(elId, value, label) {
                    if (value === null) {
                        document.getElementById(elId).innerHTML = '<p style="color:#888;padding:30px 0;">データ不足</p>';
                        return;
                    }
                    new ApexCharts(document.getElementById(elId), {
                        chart: { type: 'radialBar', height: 220 },
                        series: [Math.max(0, Math.min(value, 150))],
                        plotOptions: {
                            radialBar: {
                                startAngle: -90, endAngle: 90,
                                hollow: { size: '60%' },
                                dataLabels: {
                                    name: { show: false },
                                    value: { fontSize: '24px', formatter: function() { return value + '%'; }, offsetY: -10 },
                                },
                            },
                        },
                        fill: { colors: ['#0073aa'] },
                        labels: [label],
                    }).render();
                }
                wpStocksUsGauge('gaugeUsRevenue', <?php echo json_encode($revenue_progress); ?>, '売上高進捗率');
                wpStocksUsGauge('gaugeUsNetIncome', <?php echo json_encode($ni_progress); ?>, '純利益進捗率');

                function wpStocksUsQuarterlyChart(elId, prevData, curData) {
                    new ApexCharts(document.getElementById(elId), {
                        chart: { type: 'bar', height: 300, toolbar: { show: false } },
                        series: [
                            { name: '前期', data: prevData },
                            { name: '当期', data: curData },
                        ],
                        xaxis: { categories: <?php echo json_encode($categories); ?> },
                        colors: ['#aed6f1', '#2980b9'],
                        dataLabels: { enabled: false },
                        legend: { position: 'top' },
                        plotOptions: { bar: { columnWidth: '55%' } },
                        yaxis: { labels: { formatter: function(v) { return v === null ? '' : v.toLocaleString(); } } },
                        tooltip: { y: { formatter: function(v) { return v === null ? '-' : Number(v).toLocaleString() + '百万ドル'; } } },
                    }).render();
                }
                wpStocksUsQuarterlyChart('finChartUsQuarterlyRevenue', <?php echo json_encode($prev_revenue_cum); ?>, <?php echo json_encode($cur_revenue_cum); ?>);
                wpStocksUsQuarterlyChart('finChartUsQuarterlyNetIncome', <?php echo json_encode($prev_ni_cum); ?>, <?php echo json_encode($cur_ni_cum); ?>);
            })();
            </script>
            <?php
        }
    }

    echo '</div>'; // tab-finance
    } // end finance tab

    // ===== 適時開示タブ =====
    if ($active_tab === 'news') {
    echo '<div id="tab-news">';

    $fetch_news_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_stock_news&id=' . $id), 'wp_stocks_action_' . $id);
    $reset_news_url = wp_nonce_url(admin_url('admin-post.php?action=fetch_stock_news&id=' . $id . '&reset=1'), 'wp_stocks_action_' . $id);
    $clear_news_url = wp_nonce_url(admin_url('admin-post.php?action=clear_stock_news&id=' . $id), 'wp_stocks_action_' . $id);

    echo '<p>';
    echo '<a href="' . esc_url($fetch_news_url) . '" class="button button-primary">最新情報を取得</a> ';
    echo '<a href="' . esc_url($reset_news_url) . '" class="button" onclick="return confirm(\'既存情報を削除して再取得しますか？\')">リセットして再取得</a> ';
    echo '<a href="' . esc_url($clear_news_url) . '" class="button" style="color:red;" onclick="return confirm(\'全削除しますか？\')">全削除</a>';
    echo '</p>';

    $news_list = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_news WHERE stock_id = %d ORDER BY published_at DESC LIMIT 30", $id
    ));

    if (!$news_list) {
        echo '<p style="color:#888;">情報がありません。「最新情報を取得」を実行してください。</p>';
    } else {
        echo '<div style="display:flex;flex-direction:column;gap:15px;max-width:900px;">';
        foreach ($news_list as $n) {
            $pub_date = $n->published_at ? date('Y/m/d H:i', strtotime($n->published_at)) : '';
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px;display:flex;gap:15px;align-items:flex-start;">';
            echo '<div style="flex:1;">';
            echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . esc_html($n->publisher) . ($pub_date ? ' ・ ' . $pub_date : '') . '</div>';
            echo '<a href="' . esc_url($n->link) . '" target="_blank" style="font-size:15px;font-weight:bold;color:#0073aa;text-decoration:none;">' . esc_html($n->title) . '</a>';
            echo '</div></div>';
        }
        echo '</div>';
    }

    echo '</div>'; // tab-news
    } // end news tab

    // ===== AI分析タブ =====
    if ($active_tab === 'ai') {
    echo '<div id="tab-ai">';

    // AI詳細分析結果
    echo '<h3>🤖 AI詳細分析結果</h3>';
    if (isset($_GET['message']) && $_GET['message'] === 'ai_analysis_saved') {
        echo '<div class="updated"><p>AI詳細分析結果を保存しました。</p></div>';
    }
    if (!empty($stock->ai_analysis)) {
        $ai_updated = $stock->ai_analysis_updated_at ? date('Y/m/d H:i', strtotime($stock->ai_analysis_updated_at)) : '';
        echo '<div style="background:#f0f7ff;border:1px solid #3498db;border-radius:8px;padding:16px;margin-bottom:15px;">';
        if ($ai_updated) echo '<div style="font-size:11px;color:#888;margin-bottom:8px;">更新日：' . esc_html($ai_updated) . '</div>';
        echo '<pre style="white-space:pre-wrap;word-wrap:break-word;font-family:inherit;font-size:13px;line-height:1.8;margin:0;">' . esc_html($stock->ai_analysis) . '</pre>';
        echo '</div>';
    }
    echo '<details style="margin-bottom:25px;">';
    echo '<summary style="cursor:pointer;font-size:13px;color:#0073aa;padding:8px 0;">✏️ AI詳細分析結果を' . (!empty($stock->ai_analysis) ? '編集する' : '入力する') . '</summary>';
    echo '<div style="margin-top:10px;">';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_ai_analysis">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_ai_analysis_nonce');
    echo '<textarea name="ai_analysis" style="width:100%;height:300px;font-family:monospace;font-size:12px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="AIの詳細分析結果をそのまま貼り付けてください...">' . esc_textarea($stock->ai_analysis ?? '') . '</textarea>';
    echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">AI詳細分析結果を保存</button></p>';
    echo '</form>';
    echo '</div></details>';

    // パイプ→AI履歴保存
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px;margin-bottom:20px;">';
    echo '<h4 style="margin:0 0 10px 0;font-size:13px;">📊 AIの出力データを保存</h4>';
    echo '<textarea id="pipe_input" style="width:100%;height:60px;font-family:monospace;font-size:12px;padding:8px;border:1px solid #ddd;border-radius:4px;" placeholder="例: ' . esc_attr($stock->code) . '|' . esc_attr($stock->name) . '|2026/05/19|B|A|A|買い|..."></textarea>';
    echo '<div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
    echo '<button onclick="saveAiHistory()" class="button button-primary">💾 AI分析結果を履歴に保存</button>';
    echo '</div></div>';


    // AI分析履歴
    $ai_history = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}stock_ai_history WHERE stock_id = %d ORDER BY analysis_date DESC, created_at DESC LIMIT 20", $id
    ));
    if ($ai_history) {
        echo '<h3>📂 AI分析履歴</h3>';
        foreach ($ai_history as $ah) {
            $del_url = wp_nonce_url(admin_url('admin-post.php?action=delete_ai_history&id=' . $ah->id . '&stock_id=' . $id), 'wp_stocks_delete_ai_' . $ah->id);
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:15px;">';

            // ヘッダー行（分析日・総合スコア・削除）
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;border-bottom:1px solid #eee;padding-bottom:8px;">';
            echo '<div style="font-size:13px;font-weight:bold;color:#555;">📅 ' . esc_html($ah->analysis_date) . '</div>';
            echo '<div style="font-size:16px;font-weight:bold;color:#0073aa;">総合スコア：' . esc_html($ah->total_score) . '</div>';
            echo '<a href="' . esc_url($del_url) . '" class="button button-small" style="color:red;" onclick="return confirm(\'削除しますか？\');">削除</a>';
            echo '</div>';

            // 指標を2列グリッド表示
            echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">';
            foreach ([
                ['バリュエーション', $ah->valuation],
                ['財務健全性',       $ah->financial_health],
                ['成長性',           $ah->growth],
                ['相場状態',         $ah->market_status],
                ['配当評価',         $ah->dividend_eval],
                ['適正株価',         $ah->fair_price],
            ] as [$label, $val]) {
                if (empty($val)) continue;
                echo '<div style="background:#f8f9fa;border-radius:4px;padding:8px 10px;">';
                echo '<div style="font-size:11px;color:#888;margin-bottom:2px;">' . $label . '</div>';
                echo '<div style="font-size:13px;font-weight:bold;">' . esc_html($val) . '</div>';
                echo '</div>';
            }
            echo '</div>';

            // 見通し・リスク・カタリスト（全幅）
            foreach ([
                ['短期見通し', $ah->short_outlook],
                ['中期見通し', $ah->mid_outlook],
                ['リスク要因', $ah->risk_factor],
                ['カタリスト', $ah->catalyst],
            ] as [$label, $val]) {
                if (empty($val)) continue;
                echo '<div style="background:#f8f9fa;border-radius:4px;padding:8px 10px;margin-bottom:6px;">';
                echo '<div style="font-size:11px;color:#888;margin-bottom:2px;">' . $label . '</div>';
                echo '<div style="font-size:13px;">' . esc_html($val) . '</div>';
                echo '</div>';
            }

            // 一言コメント
            if (!empty($ah->comment)) {
                echo '<div style="background:#fff8e1;border:1px solid #f39c12;border-radius:4px;padding:10px;margin-top:8px;">';
                echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">💬 一言コメント</div>';
                echo '<div style="font-size:13px;line-height:1.6;">' . esc_html($ah->comment) . '</div>';
                echo '</div>';
            }

            echo '</div>';
        }
    }


    // AI分析用テキスト生成
    echo '<h3>🤖 AI分析用テキスト生成</h3>';
    $analysis_text  = "【" . $stock->name . "（" . $stock->code . "）AI分析依頼】\n\n";
    $analysis_text .= "■ 基本情報\n市場：" . ($stock->market ?: '東証') . "\n業種：" . ($stock->sector ?: '-') . " / " . ($stock->industry ?: '-') . "\n";
    if ($stock->market_cap > 0) $analysis_text .= "時価総額：" . number_format($stock->market_cap / 100000000) . "億円\n";
    if ($latest) {
        $analysis_text .= "現在株価：" . number_format($latest->price) . "円（" . $latest->datetime . "）\n";
        if ($latest->previous_close) {
            $ch = $latest->price - $latest->previous_close;
            $chp = $latest->previous_close > 0 ? ($ch / $latest->previous_close * 100) : 0;
            $analysis_text .= "前日比：" . ($ch >= 0 ? '+' : '') . number_format($ch) . "円（" . ($chp >= 0 ? '+' : '') . number_format($chp, 2) . "%）\n";
        }
    }
    if ($tech) {
        $analysis_text .= "\n■ テクニカル指標\n";
        $analysis_text .= "トレンド：" . $tech->trend . "（強さ：" . $tech->trend_strength . "）\n";
        if ($tech->ma5)  $analysis_text .= "MA5：" . number_format($tech->ma5, 1) . "円\n";
        if ($tech->ma25) $analysis_text .= "MA25：" . number_format($tech->ma25, 1) . "円\n";
        if ($tech->macd) $analysis_text .= "MACD：" . $tech->macd . " / Signal：" . ($tech->macd_signal ?? '-') . "\n";
        if ($tech->rsi)  $analysis_text .= "RSI：" . $tech->rsi . "\n";
        if ($tech->cross_signal) $analysis_text .= "シグナル：" . ($tech->cross_signal === 'golden' ? 'ゴールデンクロス' : 'デッドクロス') . "\n";
    }
    $analysis_text .= "\n■ 株価指標\n";
    if ($stock->per > 0)            $analysis_text .= "PER（実績）：" . number_format($stock->per, 1) . "倍\n";
    if ($stock->forward_per > 0)    $analysis_text .= "PER（予想）：" . number_format($stock->forward_per, 1) . "倍\n";
    if ($stock->pbr > 0)            $analysis_text .= "PBR：" . number_format($stock->pbr, 2) . "倍\n";
    if ($stock->eps != 0)           $analysis_text .= "EPS（実績）：" . number_format($stock->eps, 1) . "円\n";
    if ($stock->forward_eps != 0)   $analysis_text .= "EPS（予想）：" . number_format($stock->forward_eps, 1) . "円\n";
    if ($stock->roe != 0)           $analysis_text .= "ROE：" . number_format($stock->roe, 1) . "%\n";
    if ($stock->roa != 0)           $analysis_text .= "ROA：" . number_format($stock->roa, 1) . "%\n";
    if ($stock->peg > 0)            $analysis_text .= "PEG：" . number_format($stock->peg, 2) . "\n";
    if ($stock->dividend_yield > 0) $analysis_text .= "配当利回り：" . number_format($stock->dividend_yield, 2) . "%\n";
    if ($stock->equity_ratio > 0)   $analysis_text .= "自己資本比率：" . number_format($stock->equity_ratio, 1) . "%\n";
    $analysis_text .= "\n■ 業績指標\n";
    $analysis_text .= "売上成長率：" . ($stock->revenue_growth >= 0 ? '+' : '') . number_format($stock->revenue_growth, 1) . "%\n";
    $analysis_text .= "利益成長率：" . ($stock->earnings_growth >= 0 ? '+' : '') . number_format($stock->earnings_growth, 1) . "%\n";
    $analysis_text .= "利益率：" . number_format($stock->profit_margin, 1) . "%\n";
    if ($stock->revenue > 0)    $analysis_text .= "売上高：" . number_format($stock->revenue / 1000000) . "百万円\n";
    if ($stock->net_income > 0) $analysis_text .= "純利益：" . number_format($stock->net_income / 1000000) . "百万円\n";
    if (!empty($stock->theme_tags)) {
        $analysis_text .= "\n■ テーマタグ\n";
        foreach (explode(',', $stock->theme_tags) as $tag) $analysis_text .= "#" . trim($tag) . " ";
        $analysis_text .= "\n";
    }
    $news_list = $wpdb->get_results($wpdb->prepare("SELECT title, publisher, published_at FROM {$wpdb->prefix}stock_news WHERE stock_id = %d ORDER BY published_at DESC LIMIT 3", $id));
    if ($news_list) {
        $analysis_text .= "\n■ 最新ニュース\n";
        foreach ($news_list as $n) $analysis_text .= "・" . $n->title . "（" . $n->publisher . "）\n";
    }
    $analysis_text .= "\n■ 分析依頼\n以上のデータをもとに以下11項目をすべて漏れなく分析してください：\n";
    $analysis_text .= "1.バリュエーション評価　2.財務健全性　3.成長性　4.総合投資判断　5.同業他社比較\n";
    $analysis_text .= "6.相場状態　7.リスク要因　8.カタリスト　9.配当評価　10.適正株価試算　11.短期・中期見通し\n";
    $analysis_text .= "\n■ スプレッドシート貼り付け用データ出力\n";
    $analysis_text .= "分析後、以下の形式で「|」区切りで1行出力してください：\n";
    $analysis_text .= $stock->code . "|" . $stock->name . "|" . date('Y/m/d') . "|バリュエーション|財務健全性|成長性|相場状態|リスク要因|カタリスト|配当評価|適正株価|短期見通し|中期見通し|総合スコア|一言コメント\n";

    echo '<div style="margin-bottom:20px;">';
    echo '<button onclick="copyAnalysisText()" class="button button-primary" style="margin-bottom:10px;">📋 AI分析用テキストをコピー</button>';
    echo '<textarea id="analysis_text_area" style="width:100%;height:250px;font-family:monospace;font-size:12px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;">' . esc_textarea($analysis_text) . '</textarea>';
    echo '</div>';

    // 隠しフォーム（AI履歴保存用）
    echo '<form id="ai_history_form" method="post" action="' . admin_url('admin-post.php') . '" style="display:none;">';
    echo '<input type="hidden" name="action" value="save_ai_history">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    echo '<input type="hidden" name="pipe_data" id="ai_pipe_data" value="">';
    wp_nonce_field('wp_stocks_ai_history_nonce');
    echo '</form>';

    echo '<script>
    function copyAnalysisText() {
        var ta = document.getElementById("analysis_text_area");
        ta.select(); ta.setSelectionRange(0,99999);
        navigator.clipboard.writeText(ta.value).then(function(){alert("コピーしました！");}).catch(function(){document.execCommand("copy");alert("コピーしました！");});
    }
    function saveAiHistory() {
        var input = document.getElementById("pipe_input").value.trim();
        if (!input) { alert("AIの出力データを貼り付けてください"); return; }
        if (input.split("|").length < 5) { alert("パイプ区切り（|）のデータを貼り付けてください"); return; }
        document.getElementById("ai_pipe_data").value = input;
        if (confirm("この分析結果を履歴に保存しますか？")) {
            document.getElementById("ai_history_form").submit();
        }
    }
    </script>';
    echo '</div>'; // tab-ai
    } // end ai tab

    // ===== 編集タブ =====
    if ($active_tab === 'edit') {
    echo '<div id="tab-edit">';
    if (isset($_GET['message']) && $_GET['message'] === 'shikiho_saved') {
        echo '<div class="updated"><p>四季報情報を保存しました。</p></div>';
    }
    if (isset($_GET['message']) && $_GET['message'] === 'sector_override_saved') {
        echo '<div class="updated"><p>業種（手動設定）を保存しました。</p></div>';
    }
    // 業種（手動設定・日本株のみ）：四季報の自動判定を上書きする。四季報一括再抽出でも上書きされない。
    if (!$is_usd) {
        echo '<h3 style="margin-top:25px;">&#x1F3F7;&#xFE0F; 業種（手動設定）</h3>';
        echo '<p style="color:#666;font-size:13px;">四季報からの自動判定が実態と合わない場合（「その他」等）に、ここで33業種のいずれかを手動指定できます。指定するとこちらが優先され、「四季報からセクターを一括再抽出」を実行しても上書きされません。</p>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="update_sector_override">';
        echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('wp_stocks_sector_override_nonce');
        echo '<select name="sector_override" style="margin-right:10px;">';
        echo '<option value="">自動（四季報から取得：' . esc_html($stock->sector ?: '未設定') . '）</option>';
        foreach (wp_stocks_get_topix17_sector_map() as $topix17_code => $topix17_info) {
            echo '<optgroup label="' . esc_attr($topix17_code . ' ' . $topix17_info['name']) . '">';
            foreach ($topix17_info['sectors'] as $s33) {
                $osel = ($stock->sector_override ?? '') === $s33 ? 'selected' : '';
                echo '<option value="' . esc_attr($s33) . '" ' . $osel . '>' . esc_html($s33) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '</select>';
        echo '<button type="submit" class="button button-primary">保存</button></form>';
    }
    if (isset($_GET['message']) && $_GET['message'] === 'screening_saved') {
        echo '<div class="updated"><p>スクリーニングフラグを保存しました。</p></div>';
    }
    // スクリーニングフラグ（手動）
    echo '<h3>&#x1F3AF; スクリーニングフラグ</h3>';
    echo '<p style="color:#666;font-size:13px;">外部のスクリーニングツールや四季報を見て判断した結果を手動で記録し、ダッシュボードで絞り込めます。</p>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_screening_flags">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_screening_nonce');
    echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="screen_op_profit" ' . (intval($stock->screen_op_profit ?? 0) ? 'checked' : '') . '> 営業利益が高い</label>';
    echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="screen_net_income" ' . (intval($stock->screen_net_income ?? 0) ? 'checked' : '') . '> 純利益が高い</label>';
    echo '<label style="display:block;margin-bottom:10px;"><input type="checkbox" name="screen_shikiho" ' . (intval($stock->screen_shikiho ?? 0) ? 'checked' : '') . '> 四季報の見出しが良い</label>';
    echo '<button type="submit" class="button button-primary">保存</button></form>';
    // テーマタグ
    echo '<h3>&#x1F3F7;&#xFE0F; テーマタグ</h3>';
    $tags = $stock->theme_tags ?? '';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_theme_tags">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_theme_tags_nonce');
    echo '<input type="text" name="theme_tags" value="' . esc_attr($tags) . '" style="width:400px;margin-right:10px;" placeholder="例：ゲーム, ファブレス, 円安メリット">';
    echo '<button type="submit" class="button button-primary">保存</button></form>';
    // 銘柄メモ
    echo '<h3 style="margin-top:25px;">&#x1F4DD; 銘柄メモ</h3>';
    echo '<p style="color:#666;font-size:13px;">注目理由・分析メモ・決算後の所感など自由に記録できます。</p>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    echo '<input type="hidden" name="action" value="update_memo">';
    echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
    wp_nonce_field('wp_stocks_memo_nonce');
    echo '<textarea name="memo" style="width:100%;height:150px;font-size:13px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="例：PER10倍以下で割安。次の決算で増収増益なら買い増し検討。">' . esc_textarea($stock->memo ?? '') . '</textarea>';
    echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">メモを保存</button></p>';
    echo '</form>';

    // 予想EPS手動入力（日本株のみ。J-Quantsは無料プランのため最大12週遅延、決算直後の即時反映用）
    if (!$is_usd) {
        echo '<h3 style="margin-top:25px;">&#x270F;&#xFE0F; 予想EPS手動入力（J-Quants上書き）</h3>';
        $jq_updated   = !empty($stock->jquants_updated_at) ? esc_html($stock->jquants_updated_at) : '未取得';
        $jq_disc_date = !empty($stock->jquants_disc_date) ? esc_html($stock->jquants_disc_date) : '不明';
        // ★2026-09-25変更：以前はforecast_epsが空のとき実績EPSを予想扱いで代入していたが紛らわしいため廃止。
        // 予想が未発表の場合はforecast_epsがnullのまま返るので、その場合は実績EPS（jquants_eps）を
        // 「参考値」として明示的にラベルを分けて表示する（優先度：manual > jquants予想 > なし）。
        $forecast_line = !empty($stock->jquants_forecast_eps)
            ? esc_html($stock->jquants_forecast_eps) . '円'
            : 'N/A（未発表' . (!empty($stock->jquants_eps) ? '。参考=直近実績EPS ' . esc_html($stock->jquants_eps) . '円' : '') . '）';
        echo '<p style="color:#666;font-size:13px;">J-Quants自動取得値（当期予想: ' . $forecast_line . ' / 来期予想: ' . esc_html($stock->jquants_next_fy_forecast_eps ?? 'N/A') . '円）。<br>';
        echo '開示日（この予想値のもとになった決算開示日）: ' . $jq_disc_date . '　/　同期実行日時: ' . $jq_updated . '。開示日が古い場合は内容が最新でない可能性があるのでご注意ください。<br>';
        echo '決算直後などJ-Quantsの反映（最大12週遅延）を待てない場合は、下記に手動値を入力すると自動取得より優先されます。空欄に戻すとJ-Quants値に戻ります。</p>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="update_manual_forecast_eps">';
        echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('wp_stocks_manual_forecast_eps_nonce');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>当期予想EPS（円）</th><td><input type="number" name="manual_forecast_eps" value="' . esc_attr(($stock->manual_forecast_eps ?? 0) > 0 ? $stock->manual_forecast_eps : '') . '" step="0.01" style="width:150px;" placeholder="自動取得"></td></tr>';
        echo '<tr><th>来期予想EPS（円）</th><td><input type="number" name="manual_next_fy_forecast_eps" value="' . esc_attr(($stock->manual_next_fy_forecast_eps ?? 0) > 0 ? $stock->manual_next_fy_forecast_eps : '') . '" step="0.01" style="width:150px;" placeholder="自動取得"></td></tr>';
        echo '</tbody></table>';
        echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">手動値を保存</button></p>';
        echo '</form>';
    }

    // 四季報入力（日本株のみ）
    if (!$is_usd) {
        echo '<h3 style="margin-top:25px;">&#x1F4D6; 四季報情報</h3>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="update_shikiho">';
        echo '<input type="hidden" name="stock_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('wp_stocks_shikiho_nonce');
        echo '<textarea name="shikiho" style="width:100%;height:200px;font-family:monospace;font-size:12px;padding:10px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="四季報の情報をそのまま貼り付けてください...">' . esc_textarea($stock->shikiho ?? '') . '</textarea>';
        echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">四季報情報を保存</button></p>';
        echo '</form>';
    }
    // 企業情報再取得
    $info_url = wp_nonce_url(admin_url('admin-post.php?action=update_company_info&id=' . $id . '&from=wp-stocks-company'), 'wp_stocks_action_' . $id);
    echo '<p style="margin-top:25px;"><a href="' . esc_url($info_url) . '" class="button">&#x1F504; Yahoo!ファイナンスから企業情報を再取得</a></p>';
    echo '</div>'; // tab-edit
    } // end edit tab
    echo '</div>'; // tabs wrapper
}

<?php
/**
 * WP Stocks Manager — 管理画面: 運用メモ・決算カレンダー・インポート/エクスポート・ログページ
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// 運用メモページ
// --------------------------------------------------
function wp_stocks_daily_memos_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'stock_daily_memos';

    if (isset($_POST['wp_stocks_add_memo'])) {
        check_admin_referer('wp_stocks_memo_nonce');
        $memo_date    = sanitize_text_field($_POST['memo_date'] ?? '');
        $memo_content = sanitize_textarea_field($_POST['memo_content'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $memo_date) && $memo_content !== '') {
            $wpdb->insert($table, ['memo_date' => $memo_date, 'content' => $memo_content]);
            echo '<div class="notice notice-success"><p>メモを追加しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>日付または内容が正しくありません。</p></div>';
        }
    }

    if (isset($_GET['delete']) && check_admin_referer('wp_stocks_memo_delete_' . intval($_GET['delete']))) {
        $wpdb->delete($table, ['id' => intval($_GET['delete'])]);
        echo '<div class="notice notice-success"><p>メモを削除しました。</p></div>';
    }

    echo '<div class="wrap"><h1>&#x1F4D3; 運用メモ</h1>';
    echo '<p style="color:#666;font-size:13px;">相場全体の気づきや決算結果などを日付ごとに記録できます。本文中に銘柄コード（例：285A、9432）を書くと自動でリンクになります。</p>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:25px;max-width:700px;">';
    echo '<form method="post">';
    wp_nonce_field('wp_stocks_memo_nonce');
    echo '<div style="margin-bottom:10px;"><input type="date" name="memo_date" value="' . esc_attr(date('Y-m-d')) . '" required></div>';
    echo '<textarea name="memo_content" style="width:100%;height:100px;font-size:13px;padding:8px;border:1px solid #ddd;border-radius:4px;resize:vertical;" placeholder="例：285A QPS研究所 決算発表。売上は予想上振れ、株価急伸。" required></textarea>';
    echo '<p style="margin-top:8px;"><button type="submit" name="wp_stocks_add_memo" class="button button-primary">メモを追加</button></p>';
    echo '</form>';
    echo '</div>';

    $memos = $wpdb->get_results("SELECT * FROM $table ORDER BY memo_date DESC, id DESC");
    if (!$memos) {
        echo '<p style="color:#888;">まだメモがありません。</p>';
    } else {
        $current_date = null;
        foreach ($memos as $m) {
            if ($m->memo_date !== $current_date) {
                if ($current_date !== null) echo '</div>';
                $current_date = $m->memo_date;
                echo '<h3 style="margin-top:20px;border-left:4px solid #0073aa;padding-left:10px;">' . esc_html(date('Y年n月j日', strtotime($current_date))) . '</h3>';
                echo '<div style="display:flex;flex-direction:column;gap:10px;max-width:700px;margin-bottom:10px;">';
            }
            $del_url = wp_nonce_url(admin_url('admin.php?page=wp-stocks-memos&delete=' . $m->id), 'wp_stocks_memo_delete_' . $m->id);
            echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 15px;">';
            echo '<div style="font-size:13px;line-height:1.7;">' . wp_stocks_linkify_memo($m->content) . '</div>';
            echo '<div style="text-align:right;margin-top:6px;"><a href="' . esc_url($del_url) . '" onclick="return confirm(\'削除しますか？\');" style="font-size:11px;color:#e74c3c;text-decoration:none;">削除</a></div>';
            echo '</div>';
        }
        echo '</div>';
    }

    echo '</div>';
}

function wp_stocks_calendar_page() {
    global $wpdb;

    // 決算予定日の手動登録
    if (isset($_POST['wp_stocks_set_earnings'])) {
        check_admin_referer('wp_stocks_set_earnings_nonce');
        $stock_id = intval($_POST['stock_id'] ?? 0);
        $new_date = sanitize_text_field($_POST['earnings_date'] ?? '');
        if ($stock_id > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
            $wpdb->update($wpdb->prefix . 'stocks', ['earnings_date' => $new_date], ['id' => $stock_id]);
            echo '<div class="notice notice-success"><p>決算予定日を登録しました。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>日付の形式が正しくありません。</p></div>';
        }
    }

    // J-Quantsから決算予定日を取得（バックグラウンドで実行。件数が多いと数分〜十数分かかる）
    if (isset($_POST['wp_stocks_sync_earnings']) && current_user_can('manage_options') && function_exists('wp_stocks_jquants_sync_earnings_dates')) {
        check_admin_referer('wp_stocks_sync_earnings_nonce');
        delete_option('wp_stocks_earnings_checked');
        if (!wp_next_scheduled('wp_stocks_earnings_sync_cron')) wp_schedule_single_event(time(), 'wp_stocks_earnings_sync_cron');
        if (function_exists('spawn_cron')) spawn_cron();
        echo '<div class="notice notice-info"><p>J-Quantsから決算予定日の取得を開始しました。バックグラウンドで実行されるので、数分後にこのページを開き直してください。</p></div>';
    }

    $view  = in_array($_GET['view'] ?? '', ['list','month'], true) ? $_GET['view'] : 'list';
    $today = date('Y-m-d');
    // JPX（東証）の決算発表予定日一覧から取得（数秒で終わるので、その場で実行する）
    if (isset($_POST['wp_stocks_sync_jpx']) && current_user_can('manage_options') && function_exists('wp_stocks_jpx_sync_earnings_dates')) {
        check_admin_referer('wp_stocks_sync_earnings_nonce');
        $jr = wp_stocks_jpx_sync_earnings_dates();
        if ($jr['error'] !== '') {
            echo '<div class="notice notice-error"><p>JPXからの取得に失敗しました：' . esc_html($jr['error']) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>JPXから決算予定日を取得しました。ファイル ' . intval($jr['files']) . '個／更新 ' . intval($jr['updated']) . '件／一覧に予定なし ' . intval($jr['missing']) . '件。</p></div>';
        }
    }
    echo '<div class="wrap"><h1>📅 決算カレンダー</h1>';

    if (function_exists('wp_stocks_jquants_sync_earnings_dates')) {
        $es = get_option('wp_stocks_earnings_sync_status', []);
        echo '<form method="post" style="margin:0 0 12px;">';
        wp_nonce_field('wp_stocks_sync_earnings_nonce');
        echo '<button type="submit" name="wp_stocks_sync_jpx" class="button">&#x1F504; JPXから決算予定日を取得</button> ';
        echo '<button type="submit" name="wp_stocks_sync_earnings" class="button">&#x1F504; J-Quantsから決算予定日を取得</button> ';
        echo '<span class="description">毎日 6:10 にも自動で取得します。未取得の銘柄と、決算日が過ぎた銘柄だけを更新し、これから先の日付（手入力を含む）は上書きしません。</span>';
        if (is_array($es) && !empty($es['at'])) {
            echo '<div class="description" style="margin-top:4px;">前回（' . esc_html($es['at']) . '）：更新 ' . intval($es['updated']) . '件／予定なし・未定 ' . intval($es['none']) . '件／失敗 ' . intval($es['failed']) . '件'
                . (!empty($es['remaining']) ? '／残り ' . intval($es['remaining']) . '件（数分後に続きを実行）' : '')
                . (!empty($es['first_error']) ? '<br><span style="color:#b32d2e;">エラー：' . esc_html($es['first_error']) . '</span>' : '') . '</div>';
        }
        echo '</form>';
    }

    // 表示切り替え
    echo '<div style="margin-bottom:15px;">';
    foreach (['list' => '&#x1F4CB; 一覧', 'month' => '&#x1F5D3;&#xFE0F; カレンダー'] as $vkey => $vlabel) {
        $vactive = $view === $vkey;
        $vurl = add_query_arg(['view' => $vkey], admin_url('admin.php?page=wp-stocks-calendar'));
        echo '<a href="' . esc_url($vurl) . '" class="button" style="' . ($vactive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '') . 'font-weight:bold;margin-right:6px;">' . $vlabel . '</a>';
    }
    echo '</div>';

    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE earnings_date IS NOT NULL ORDER BY earnings_date ASC");

    if ($view === 'month') {
        wp_stocks_render_earnings_month_calendar($stocks, $today);
    } else {

    if (!$stocks) {
        echo '<p>決算予定日のデータがありません。各銘柄の「企業情報更新」を実行してください。</p>';
    } else {
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th style="width:80px;">コード</th><th>銘柄名</th><th style="width:130px;">決算予定日</th><th style="width:100px;">残り日数</th><th style="width:120px;">ステータス</th></tr></thead><tbody>';
    foreach ($stocks as $s) {
        if (empty($s->earnings_date)) continue;
        $days = (int)((strtotime($s->earnings_date) - strtotime($today)) / 86400);
        if ($days < 0)        { $days_str = abs($days) . '日前'; $row_style = 'color:#aaa;'; $badge = '<span style="background:#ddd;color:#888;padding:2px 8px;border-radius:3px;font-size:11px;">終了</span>'; }
        elseif ($days <= 7)   { $days_str = $days . '日後'; $row_style = 'color:#e74c3c;font-weight:bold;'; $badge = '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">直近</span>'; }
        elseif ($days <= 30)  { $days_str = $days . '日後'; $row_style = 'color:#f39c12;font-weight:bold;'; $badge = '<span style="background:#f39c12;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">今月</span>'; }
        else                  { $days_str = $days . '日後'; $row_style = ''; $badge = ''; }
        $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
        echo '<tr style="' . $row_style . '"><td>' . esc_html($s->code) . '</td><td><a href="' . esc_url($detail_url) . '">' . esc_html($s->name) . '</a></td><td>' . esc_html($s->earnings_date) . '</td><td style="' . $row_style . '">' . esc_html($days_str) . '</td><td>' . $badge . '</td></tr>';
    }
    echo '</tbody></table>';
    }

    // 決算予定日が未取得の銘柄一覧＋手動登録フォーム
    $missing_stocks = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio') AND earnings_date IS NULL AND is_sector_etf = 0 ORDER BY code ASC"
    );
    if ($missing_stocks) {
        echo '<h2 style="margin-top:30px;">⚠️ 決算予定日が未取得の銘柄（' . count($missing_stocks) . '件）</h2>';
        echo '<p class="description">決算予定日がまだ取れていない銘柄です（J-Quantsでも予定日が未定の可能性があります）。判明していれば手動で登録できます。</p>';
        echo '<table class="widefat fixed striped" style="max-width:600px;">';
        echo '<thead><tr><th style="width:80px;">コード</th><th>銘柄名</th><th style="width:260px;">決算予定日を登録</th></tr></thead><tbody>';
        foreach ($missing_stocks as $ms) {
            echo '<tr><td>' . esc_html($ms->code) . '</td><td>' . esc_html($ms->name) . '</td><td>';
            echo '<form method="post" style="display:flex;gap:6px;align-items:center;">';
            wp_nonce_field('wp_stocks_set_earnings_nonce');
            echo '<input type="hidden" name="stock_id" value="' . intval($ms->id) . '">';
            echo '<input type="date" name="earnings_date" required style="font-size:12px;">';
            echo '<button type="submit" name="wp_stocks_set_earnings" class="button button-small">登録</button>';
            echo '</form>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    } // end view=list

    echo '</div>';
}

// --------------------------------------------------
// 適時開示ページ（旧・独立ページ）は企業情報ページの
// 「適時開示」タブに統合されたため廃止。
// --------------------------------------------------

// --------------------------------------------------
// インポート/エクスポートページ
// --------------------------------------------------
function wp_stocks_importexport_page() {
    global $wpdb;
    echo '<div class="wrap"><h1>インポート / エクスポート</h1>';

    if (isset($_POST['wp_stocks_export'])) {
        check_admin_referer('wp_stocks_export_nonce');
        $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks ORDER BY id ASC");
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wp_stocks_export_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache'); header('Expires: 0');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['コード','銘柄名','ステータス','セクター','業種','市場','PER','PBR','ROE','ROA','PEG','EPS','EPS予想','配当利回り','自己資本比率','利益率','売上成長率','利益成長率','時価総額','売上高','純利益','従業員数','平均取得単価','保有株数','テーマタグ','決算予定日','情報更新日']);
        foreach ($stocks as $s) {
            fputcsv($out, [$s->code,$s->name,$s->status,wp_stocks_get_effective_sector($s),$s->industry,$s->market,$s->per,$s->pbr,$s->roe,$s->roa,$s->peg,$s->eps,$s->forward_eps,$s->dividend_yield,$s->equity_ratio,$s->profit_margin,$s->revenue_growth,$s->earnings_growth,$s->market_cap,$s->revenue,$s->net_income,$s->employees,$s->purchase_price,$s->purchase_qty,$s->theme_tags,$s->earnings_date,$s->info_updated_at]);
        }
        fclose($out); exit;
    }

    if (isset($_POST['wp_stocks_import']) && isset($_FILES['import_file'])) {
        check_admin_referer('wp_stocks_import_nonce');
        $file = $_FILES['import_file']['tmp_name'];
        if (empty($file)) {
            echo '<div class="error"><p>ファイルを選択してください。</p></div>';
        } else {
            $handle = fopen($file, 'r'); $imported = $skipped = $row_num = 0;
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);
            while (($row = fgetcsv($handle)) !== false) {
                $row_num++;
                if ($row_num === 1) continue;
                if (empty($row[0])) continue;
                $code = sanitize_text_field($row[0]);
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}stocks WHERE code = %s", $code))) { $skipped++; continue; }
                $wpdb->insert($wpdb->prefix . 'stocks', ['code' => $code, 'name' => sanitize_text_field($row[1] ?? $code), 'status' => in_array($row[2] ?? '', ['watch','portfolio']) ? $row[2] : 'watch', 'sector' => sanitize_text_field($row[3] ?? ''), 'industry' => sanitize_text_field($row[4] ?? ''), 'market' => sanitize_text_field($row[5] ?? ''), 'per' => floatval($row[6] ?? 0), 'pbr' => floatval($row[7] ?? 0), 'roe' => floatval($row[8] ?? 0), 'roa' => floatval($row[9] ?? 0), 'peg' => floatval($row[10] ?? 0), 'eps' => floatval($row[11] ?? 0), 'forward_eps' => floatval($row[12] ?? 0), 'dividend_yield' => floatval($row[13] ?? 0), 'equity_ratio' => floatval($row[14] ?? 0), 'profit_margin' => floatval($row[15] ?? 0), 'revenue_growth' => floatval($row[16] ?? 0), 'earnings_growth' => floatval($row[17] ?? 0), 'market_cap' => intval($row[18] ?? 0), 'revenue' => intval($row[19] ?? 0), 'net_income' => intval($row[20] ?? 0), 'employees' => intval($row[21] ?? 0), 'purchase_price' => floatval($row[22] ?? 0), 'purchase_qty' => intval($row[23] ?? 0), 'theme_tags' => sanitize_text_field($row[24] ?? ''), 'earnings_date' => !empty($row[25]) ? $row[25] : null, 'created_at' => current_time('mysql')]);
                $imported++;
            }
            fclose($handle);
            echo '<div class="updated"><p>' . $imported . '件インポートしました。' . ($skipped > 0 ? $skipped . '件スキップ。' : '') . '</p></div>';
        }
    }

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;max-width:900px;">';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;"><h2>📤 エクスポート</h2><p style="color:#666;font-size:13px;">全銘柄をCSVでダウンロードします。</p><form method="post">';
    wp_nonce_field('wp_stocks_export_nonce');
    echo '<button type="submit" name="wp_stocks_export" class="button button-primary">📥 CSVをダウンロード</button></form></div>';
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;"><h2>📥 インポート</h2><p style="color:#666;font-size:13px;">CSVから銘柄を一括登録します。重複コードはスキップされます。</p><form method="post" enctype="multipart/form-data">';
    wp_nonce_field('wp_stocks_import_nonce');
    echo '<input type="file" name="import_file" accept=".csv" style="margin-bottom:10px;display:block;">';
    echo '<button type="submit" name="wp_stocks_import" class="button button-primary">📤 インポート実行</button></form></div>';
    echo '</div></div>';
}

// --------------------------------------------------
// ログページ
// --------------------------------------------------
function wp_stocks_logs_page() {
    global $wpdb;
    echo '<div class="wrap"><h1>📋 取得ログ</h1>';
    $level_filter = sanitize_text_field($_GET['level'] ?? '');
    echo '<form method="get" style="margin-bottom:15px;"><input type="hidden" name="page" value="wp-stocks-logs">';
    echo '<select name="level"><option value="">全レベル</option>';
    foreach (['info','error'] as $l) echo '<option value="' . $l . '" ' . ($level_filter === $l ? 'selected' : '') . '>' . strtoupper($l) . '</option>';
    echo '</select> <button type="submit" class="button">絞り込み</button> <a href="' . admin_url('admin.php?page=wp-stocks-logs') . '" class="button">リセット</a></form>';
    $where = $level_filter ? $wpdb->prepare(" WHERE level = %s", $level_filter) : '';
    $logs  = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_logs{$where} ORDER BY created_at DESC LIMIT 200");
    if (!$logs) { echo '<p>ログがありません。</p></div>'; return; }
    echo '<table class="widefat fixed striped"><thead><tr><th style="width:140px;">日時</th><th style="width:60px;">レベル</th><th style="width:100px;">処理</th><th style="width:80px;">銘柄</th><th>メッセージ</th></tr></thead><tbody>';
    foreach ($logs as $log) {
        $lc = $log->level === 'error' ? '#e74c3c' : '#27ae60';
        $lb = $log->level === 'error' ? '#fde8e8' : '#e8f8e8';
        echo '<tr><td style="font-size:12px;">' . esc_html($log->created_at) . '</td>';
        echo '<td><span style="background:' . $lb . ';color:' . $lc . ';padding:2px 6px;border-radius:3px;font-size:11px;font-weight:bold;">' . esc_html(strtoupper($log->level)) . '</span></td>';
        echo '<td style="font-size:12px;">' . esc_html($log->action) . '</td>';
        echo '<td style="font-size:12px;">' . esc_html($log->symbol) . '</td>';
        echo '<td style="font-size:12px;">' . esc_html($log->message) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

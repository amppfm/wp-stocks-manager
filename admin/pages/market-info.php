<?php
/**
 * WP Stocks Manager — 管理画面: マーケット情報ページ（ニュース／指数／適時開示タブ）
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// マーケット情報ページ本体（ニュース／指数／適時開示タブ）
// --------------------------------------------------
// --------------------------------------------------
// マーケット情報「ニュース」タブ用：汎用RSS取得（15分キャッシュ）
// --------------------------------------------------
function wp_stocks_fetch_generic_rss($url, $limit = 20) {
    $cache_key = 'wp_stocks_news_rss_' . md5($url);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $response = wp_remote_get($url, [
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept'     => 'application/rss+xml, application/xml, text/xml',
        ],
        'timeout' => 15,
    ]);
    if (is_wp_error($response)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), '通信エラー: ' . $response->get_error_message() . ' / URL: ' . $url);
        return [];
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $xml_str   = wp_remote_retrieve_body($response);

    if ($http_code !== 200) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'HTTPエラー: ステータスコード ' . $http_code . ' / URL: ' . $url . ' / レスポンス先頭200文字: ' . substr((string)$xml_str, 0, 200));
        return [];
    }

    if (empty($xml_str)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'レスポンスが空でした（HTTP ' . $http_code . '） / URL: ' . $url);
        return [];
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_str);
    if (!$xml) {
        $xml_errors = libxml_get_errors();
        $err_msg    = !empty($xml_errors) ? trim($xml_errors[0]->message) : '不明なXMLパースエラー';
        libxml_clear_errors();
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'XMLパース失敗: ' . $err_msg . ' / URL: ' . $url . ' / レスポンス先頭200文字: ' . substr($xml_str, 0, 200));
        return [];
    }
    if (!isset($xml->channel->item)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'RSS形式として解釈できましたが channel/item が見つかりません / URL: ' . $url . ' / レスポンス先頭200文字: ' . substr($xml_str, 0, 200));
        return [];
    }

    $result = [];
    $count  = 0;
    foreach ($xml->channel->item as $item) {
        if ($count >= $limit) break;
        $title = trim((string)($item->title ?? ''));
        $link  = trim((string)($item->link  ?? ''));
        $pub   = (string)($item->pubDate ?? '');
        $desc  = trim(strip_tags((string)($item->description ?? '')));
        if (empty($title) || empty($link)) continue;
        $result[] = [
            'title'       => $title,
            'link'        => $link,
            'pub_date'    => $pub ? date('Y/m/d H:i', strtotime($pub)) : '',
            'description' => $desc,
        ];
        $count++;
    }

    if (empty($result)) {
        wp_stocks_log('error', 'fetch_news_rss', substr($url, 0, 30), 'XMLは解析できましたが有効な記事が0件でした（title/linkが空の項目のみ、または0件のitem） / URL: ' . $url);
    }

    set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
    return $result;
}

function wp_stocks_render_news_feed_list($url, $limit = 20) {
    $items = wp_stocks_fetch_generic_rss($url, $limit);
    if (empty($items)) {
        echo '<p style="color:#888;padding:20px 0;">ニュースを取得できませんでした（フィードが空、または更新が止まっている可能性があります）。</p>';
        return;
    }
    echo '<div style="display:flex;flex-direction:column;gap:12px;max-width:900px;">';
    foreach ($items as $it) {
        echo '<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 15px;">';
        if (!empty($it['pub_date'])) {
            echo '<div style="font-size:11px;color:#888;margin-bottom:4px;">' . esc_html($it['pub_date']) . '</div>';
        }
        echo '<a href="' . esc_url($it['link']) . '" target="_blank" rel="noopener" style="font-size:14px;font-weight:bold;color:#0073aa;text-decoration:none;">' . esc_html($it['title']) . '</a>';
        if (!empty($it['description'])) {
            echo '<div style="font-size:12px;color:#666;margin-top:4px;">' . esc_html($it['description']) . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

// --------------------------------------------------
// マーケット情報「立花ニュース」タブ（RSSとは独立。wp_stock_tachibana_news を表示）
// 見出し先頭のタグで種別分類: 市況(NQN/QUICK)・決算(決算/TDnet AI)・AI市況・開示要約(category=129)・その他
// --------------------------------------------------
function wp_stocks_render_tachibana_news_tab() {
    global $wpdb;
    if (!function_exists('wp_stocks_tachibana_news_table')) {
        echo '<p style="color:#888;">立花証券ニュースの機能が読み込まれていません。</p>';
        return;
    }
    $table = wp_stocks_tachibana_news_table();
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        echo '<p style="color:#888;">ニュース用テーブルがまだありません。</p>';
        return;
    }
    $dates = $wpdb->get_results("SELECT news_date, COUNT(*) AS c FROM {$table} GROUP BY news_date ORDER BY news_date DESC");
    if (empty($dates)) {
        echo '<p style="color:#888;">保存されたニュースがありません。</p>';
        return;
    }
    $date_list = array_map(function ($d) { return $d->news_date; }, $dates);

    $cat_labels = [
        'all'   => 'すべて',
        'mkt'   => '市況',
        'earn'  => '決算',
        'aimkt' => 'AI市況',
        'disc'  => '開示要約',
        'other' => 'その他',
    ];
    // 種別判定（排他）。%を使わずLOCATEで先頭一致を見る
    $grp_sql = "CASE WHEN category = '129' THEN 'disc' WHEN LOCATE('<AI市況>', headline) = 1 THEN 'aimkt' WHEN LOCATE('<決算>', headline) = 1 OR LOCATE('<TDnet>AI:', headline) = 1 THEN 'earn' WHEN LOCATE('<NQN>', headline) = 1 OR LOCATE('<QUICK>', headline) = 1 THEN 'mkt' ELSE 'other' END";

    $tdate = sanitize_text_field($_GET['tdate'] ?? '');
    if (!in_array($tdate, $date_list, true)) $tdate = $date_list[0];
    $tcat = sanitize_text_field($_GET['tcat'] ?? 'all');
    if (!isset($cat_labels[$tcat])) $tcat = 'all';
    $tq  = trim(sanitize_text_field(wp_unslash($_GET['tq'] ?? '')));
    $tp  = max(1, intval($_GET['tp'] ?? 1));
    $per = 100;
    $base = admin_url('admin.php?page=wp-stocks-market&mtab=tcnews');

    $btn = function ($label, $url, $active) {
        return '<a href="' . esc_url($url) . '" style="padding:6px 14px;border-radius:4px;text-decoration:none;font-size:13px;'
            . ($active ? 'background:#0073aa;color:#fff;font-weight:bold;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;')
            . '">' . $label . '</a>';
    };

    // 日付ボタン
    $wd = ['日', '月', '火', '水', '木', '金', '土'];
    echo '<div style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;">';
    foreach ($dates as $d) {
        $ts    = strtotime($d->news_date);
        $label = date('m/d', $ts) . '(' . $wd[(int)date('w', $ts)] . ') ' . intval($d->c);
        echo $btn(esc_html($label), add_query_arg(['tdate' => $d->news_date, 'tcat' => $tcat], $base), $tq === '' && $d->news_date === $tdate);
    }
    echo '</div>';

    // 種別ボタン（選択日の件数付き）
    $counts = array_fill_keys(array_keys($cat_labels), 0);
    $cnt_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT {$grp_sql} AS g, COUNT(*) AS c FROM {$table} WHERE news_date = %s GROUP BY g",
        $tdate
    ));
    foreach ($cnt_rows as $cr) {
        if (isset($counts[$cr->g])) $counts[$cr->g] = intval($cr->c);
        $counts['all'] += intval($cr->c);
    }
    echo '<div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;">';
    foreach ($cat_labels as $k => $l) {
        echo $btn(esc_html($l . ' (' . $counts[$k] . ')'), add_query_arg(['tdate' => $tdate, 'tcat' => $k], $base), $tcat === $k);
    }
    echo '</div>';

    // 検索フォーム（キーワード指定時は保存期間内の全日付が対象）
    echo '<form method="get" style="margin:0 0 14px 0;display:flex;gap:6px;align-items:center;">';
    echo '<input type="hidden" name="page" value="wp-stocks-market"><input type="hidden" name="mtab" value="tcnews">';
    echo '<input type="hidden" name="tcat" value="' . esc_attr($tcat) . '">';
    echo '<input type="search" name="tq" value="' . esc_attr($tq) . '" placeholder="キーワード（保存期間内の見出し・本文）" style="width:300px;">';
    echo '<button type="submit" class="button">検索</button>';
    if ($tq !== '') echo '<a href="' . esc_url(add_query_arg(['tdate' => $tdate, 'tcat' => $tcat], $base)) . '">クリア</a>';
    echo '</form>';

    // 抽出
    $where = [];
    $args  = [];
    if ($tq !== '') {
        $like    = '%' . $wpdb->esc_like($tq) . '%';
        $where[] = '(headline LIKE %s OR body LIKE %s)';
        $args[]  = $like;
        $args[]  = $like;
    } else {
        $where[] = 'news_date = %s';
        $args[]  = $tdate;
    }
    if ($tcat !== 'all') {
        $where[] = "({$grp_sql}) = %s";
        $args[]  = $tcat;
    }
    $where_sql = implode(' AND ', $where);
    $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args));
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT id, news_date, news_at, category, issues, headline, body FROM {$table} WHERE {$where_sql} ORDER BY news_at DESC, id DESC LIMIT %d OFFSET %d",
        array_merge($args, [$per, ($tp - 1) * $per])
    ));

    // 銘柄コード → 登録済み銘柄（日本株は .T 付きで登録されている想定）
    $parse = function ($issues) {
        $out = [];
        foreach (explode('|', (string)$issues) as $c) {
            $c = trim($c);
            if ($c !== '') $out[] = (string)$c;
        }
        return $out;
    };
    $all_codes = [];
    foreach ($rows as $r) {
        foreach ($parse($r->issues) as $c) $all_codes[$c] = true;
    }
    $stock_map = [];
    if (!empty($all_codes)) {
        $cand = [];
        foreach (array_keys($all_codes) as $c) {
            $cand[] = (string)$c;
            $cand[] = $c . '.T';
        }
        $ph = implode(',', array_fill(0, count($cand), '%s'));
        $found = $wpdb->get_results($wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}stocks WHERE code IN ({$ph})", $cand));
        foreach ($found as $s) {
            $stock_map[(string)preg_replace('/\.T$/', '', $s->code)] = $s;
        }
    }

    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;">';
    echo '<h3 style="margin:0 0 10px 0;font-size:14px;">'
        . ($tq !== '' ? '検索結果「' . esc_html($tq) . '」' : esc_html(date('Y/m/d', strtotime($tdate))))
        . ' &#8212; ' . intval($total) . '件</h3>';
    if (empty($rows)) {
        echo '<p style="color:#888;">該当するニュースはありません。</p>';
    }
    foreach ($rows as $r) {
        $ts       = strtotime($r->news_at);
        $time     = ($tq !== '') ? date('m/d H:i', $ts) : date('H:i', $ts);
        $headline = (string)$r->headline;
        $codes    = $parse($r->issues);
        $n_codes  = count($codes);

        echo '<details style="border-bottom:1px solid #f0f0f0;padding:6px 0;">';
        echo '<summary style="cursor:pointer;font-size:13px;">';
        echo '<span style="color:#888;margin-right:8px;">' . esc_html($time) . '</span>' . esc_html($headline);
        if ($n_codes > 0) {
            $short = implode(',', array_slice($codes, 0, 5)) . ($n_codes > 5 ? ' 他' . ($n_codes - 5) . '件' : '');
            echo ' <span style="color:#999;font-size:11px;">[' . esc_html($short) . ']</span>';
        }
        echo '</summary>';
        echo '<div style="padding:8px 0 4px 0;font-size:13px;line-height:1.7;color:#333;">';

        // 登録済み銘柄はリンク、未登録はまとめて1行
        if ($n_codes > 0) {
            $unreg = [];
            echo '<div style="margin-bottom:8px;line-height:2;">';
            foreach ($codes as $c) {
                if (isset($stock_map[$c])) {
                    $s = $stock_map[$c];
                    echo '<a href="' . esc_url(admin_url('admin.php?page=wp-stocks-company&stock_id=' . intval($s->id)))
                        . '" style="display:inline-block;margin:0 6px 2px 0;padding:0 8px;background:#eef6fb;border-radius:10px;font-size:12px;text-decoration:none;">'
                        . esc_html($c . ' ' . $s->name) . '</a>';
                } else {
                    $unreg[] = $c;
                }
            }
            if (!empty($unreg)) {
                echo '<div style="color:#999;font-size:11px;line-height:1.6;">未登録: ' . esc_html(implode(', ', $unreg)) . '</div>';
            }
            echo '</div>';
        }

        // 本文（先頭に見出しが重複していれば除去。決算は表が崩れないよう等幅・折り返しなし）
        $body = (string)$r->body;
        if ($headline !== '' && strpos($body, $headline) === 0) {
            $body = ltrim(substr($body, strlen($headline)), "\r\n");
        }
        if ($body === '') {
            echo '<span style="color:#aaa;">（本文なし）</span>';
        } elseif (strpos($headline, '<決算>') === 0) {
            echo '<pre style="margin:0;overflow-x:auto;white-space:pre;font-family:monospace;font-size:12px;line-height:1.5;background:#fafafa;padding:8px;border:1px solid #eee;">'
                . esc_html($body) . '</pre>';
        } else {
            echo nl2br(esc_html($body));
        }
        echo '</div></details>';
    }

    // ページ送り
    $pages = (int)ceil($total / $per);
    if ($pages > 1) {
        echo '<div style="margin-top:12px;display:flex;gap:8px;align-items:center;">';
        $q = ['tdate' => $tdate, 'tcat' => $tcat, 'tq' => $tq];
        if ($tp > 1) echo '<a class="button" href="' . esc_url(add_query_arg($q + ['tp' => $tp - 1], $base)) . '">&laquo; 前へ</a>';
        echo '<span style="font-size:12px;color:#666;">' . intval($tp) . ' / ' . intval($pages) . '</span>';
        if ($tp < $pages) echo '<a class="button" href="' . esc_url(add_query_arg($q + ['tp' => $tp + 1], $base)) . '">次へ &raquo;</a>';
        echo '</div>';
    }
    echo '</div>';
}

function wp_stocks_market_info_page() {
    $active_tab = sanitize_text_field($_GET['mtab'] ?? 'news');
    if (!in_array($active_tab, ['news', 'tcnews', 'index', 'heatmap', 'disclosure'], true)) $active_tab = 'news';

    echo '<div class="wrap"><h1>&#x1F30D; マーケット情報</h1>';

    $tab_defs = [
        'news'       => '&#x1F4F0; ニュース',
        'tcnews'     => '&#x1F4E1; 立花ニュース',
        'index'      => '&#x1F4CA; 指数',
        'heatmap'    => '&#x1F321; ヒートマップ',
        'disclosure' => '&#x1F4CB; 適時開示',
    ];
    echo '<ul style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0 0 20px 0;padding:0;list-style:none;flex-wrap:wrap;">';
    foreach ($tab_defs as $key => $label) {
        $is_active = $active_tab === $key;
        $tab_url   = admin_url('admin.php?page=wp-stocks-market&mtab=' . $key);
        $style = $is_active
            ? 'display:block;padding:10px 18px;background:#0073aa;color:#fff;text-decoration:none;font-size:13px;font-weight:bold;border-radius:4px 4px 0 0;'
            : 'display:block;padding:10px 18px;background:#f1f1f1;color:#555;text-decoration:none;font-size:13px;border-radius:4px 4px 0 0;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="' . esc_url($tab_url) . '" style="' . $style . '">' . $label . '</a></li>';
    }
    echo '</ul>';

    if ($active_tab === 'news') {
        $news_sources = [];
        for ($news_i = 1; $news_i <= 10; $news_i++) {
            $news_label = get_option('wp_stocks_news_rss_label_' . $news_i, '');
            $news_url   = get_option('wp_stocks_news_rss_url_' . $news_i, '');
            if (!empty($news_label) && !empty($news_url)) {
                $news_sources['slot' . $news_i] = ['label' => $news_label, 'url' => $news_url];
            }
        }

        if (empty($news_sources)) {
            $settings_url = admin_url('admin.php?page=wp-stocks-settings');
            echo '<div style="background:#f8f9fa;border:1px solid #ddd;border-radius:8px;padding:40px;text-align:center;color:#888;">';
            echo '<p style="font-size:14px;">&#x1F4F0; まだニュースRSSが登録されていません。<a href="' . esc_url($settings_url) . '">設定ページ</a>から登録してください（最大10件）。</p>';
            echo '</div>';
        } else {
            $ntab = sanitize_text_field($_GET['ntab'] ?? '');
            if (!array_key_exists($ntab, $news_sources)) {
                $news_keys = array_keys($news_sources);
                $ntab = $news_keys[0];
            }

            echo '<div style="margin-bottom:15px;display:flex;gap:8px;flex-wrap:wrap;">';
            foreach ($news_sources as $key => $src) {
                $is_ntab_active = $ntab === $key;
                $ntab_url = admin_url('admin.php?page=wp-stocks-market&mtab=news&ntab=' . $key);
                echo '<a href="' . esc_url($ntab_url) . '" style="padding:6px 16px;border-radius:4px;text-decoration:none;font-size:13px;font-weight:bold;'
                    . ($is_ntab_active ? 'background:#0073aa;color:#fff;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;') . '">'
                    . esc_html($src['label']) . '</a>';
            }
            echo '</div>';

            wp_stocks_render_news_feed_list($news_sources[$ntab]['url'], 20);
        }
    } elseif ($active_tab === 'index') {
        wp_stocks_render_market_indices();
    } elseif ($active_tab === 'heatmap') {
        wp_stocks_sector_page(true, admin_url('admin.php?page=wp-stocks-market&mtab=heatmap'));
    } elseif ($active_tab === 'tcnews') {
        wp_stocks_render_tachibana_news_tab();
    } elseif ($active_tab === 'disclosure') {
        wp_stocks_render_recent_news_widget(30);
    }

    echo '</div>';
}

// --------------------------------------------------
// 最近の適時開示ウィジェット（全銘柄横断）
// ダッシュボードやマーケット情報ページなど、複数箇所から
// 呼び出せるよう独立関数化。
// --------------------------------------------------
function wp_stocks_render_recent_news_widget($limit = 15) {
    global $wpdb;
    $limit = intval($limit);
    $recent_news = $wpdb->get_results($wpdb->prepare(
        "SELECT sn.*, s.code, s.name
         FROM {$wpdb->prefix}stock_news sn
         INNER JOIN {$wpdb->prefix}stocks s ON sn.stock_id = s.id
         ORDER BY sn.published_at DESC
         LIMIT %d",
        $limit
    ));
    if (empty($recent_news)) return;

    $news_new_cutoff = date('Y-m-d H:i:s', strtotime('-3 days'));
    echo '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px;">';
    echo '<h3 style="margin:0 0 12px 0;font-size:14px;">&#x1F4F0; 最近の適時開示</h3>';
    echo '<div style="display:flex;flex-direction:column;">';
    foreach ($recent_news as $n) {
        $news_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $n->stock_id . '&ctab=news');
        $pub_date = $n->published_at ? date('Y/m/d H:i', strtotime($n->published_at)) : '';
        $is_new   = $n->published_at && $n->published_at >= $news_new_cutoff;
        echo '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:13px;">';
        if ($is_new) {
            echo '<span style="background:#e74c3c;color:#fff;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:bold;flex-shrink:0;">NEW</span>';
        } else {
            echo '<span style="width:32px;flex-shrink:0;"></span>';
        }
        echo '<span style="color:#888;flex-shrink:0;white-space:nowrap;">' . esc_html($pub_date) . '</span>';
        echo '<a href="' . esc_url($news_url) . '" style="color:#0073aa;text-decoration:none;font-weight:bold;flex-shrink:0;white-space:nowrap;">' . esc_html($n->code) . ' ' . esc_html($n->name) . '</a>';
        echo '<a href="' . esc_url($n->link) . '" target="_blank" style="color:#333;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html($n->title) . '</a>';
        echo '</div>';
    }
    echo '</div></div>';
}

<?php
/**
 * WP Stocks Manager — 管理画面: セクター別分析サマリーページ
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// セクター別分析サマリーページ
// --------------------------------------------------
// --------------------------------------------------
// 前日比(%)からヒートマップ調の色を計算する共通関数
// （ヒートマップページと同じ計算式。セクター分析ページでも使用）
// --------------------------------------------------
function wp_stocks_change_heat_color($pct) {
    $pct = floatval($pct);
    $abs = min(abs($pct), 5);
    $intensity = intval($abs / 5 * 180) + 40;
    if ($pct > 0) {
        return "rgb(" . intval($intensity * 0.2) . ", {$intensity}, " . intval($intensity * 0.2) . ")";
    } elseif ($pct < 0) {
        return "rgb({$intensity}, " . intval($intensity * 0.2) . ", " . intval($intensity * 0.2) . ")";
    }
    return '#888';
}

// --------------------------------------------------
// セクター分析ページ：セクターバケットへの集計を行う共通ヘルパー
// （日本株/米国株バケットとポートフォリオバケット、両方で使い回す）
// --------------------------------------------------
function wp_stocks_sector_accumulate(&$bucket, $sector_ja, $s, $score_data, $tech) {
    if (!isset($bucket[$sector_ja])) {
        $bucket[$sector_ja] = ['stocks' => [], 'per_sum' => 0, 'per_cnt' => 0,
            'roe_sum' => 0, 'roe_cnt' => 0, 'div_sum' => 0, 'div_cnt' => 0,
            'score_sum' => 0, 'up' => 0, 'down' => 0, 'flat' => 0];
    }
    $bucket[$sector_ja]['stocks'][]   = $s;
    $bucket[$sector_ja]['score_sum'] += $score_data['score'];
    if (($s->per ?? 0) > 0)            { $bucket[$sector_ja]['per_sum'] += $s->per; $bucket[$sector_ja]['per_cnt']++; }
    if (($s->roe ?? 0) != 0)           { $bucket[$sector_ja]['roe_sum'] += $s->roe; $bucket[$sector_ja]['roe_cnt']++; }
    if (($s->dividend_yield ?? 0) > 0) { $bucket[$sector_ja]['div_sum'] += $s->dividend_yield; $bucket[$sector_ja]['div_cnt']++; }
    if ($tech) {
        if ($tech->trend === 'up')       $bucket[$sector_ja]['up']++;
        elseif ($tech->trend === 'down') $bucket[$sector_ja]['down']++;
        else                             $bucket[$sector_ja]['flat']++;
    }
}

function wp_stocks_sector_page($skip_wrap = false, $custom_base_url = null) {
    global $wpdb;

    $tab      = in_array($_GET['tab'] ?? '', ['jp', 'us', 'portfolio', 'etf', 'calendar', 'period']) ? $_GET['tab'] : 'jp';
    $base_url = $custom_base_url ?: admin_url('admin.php?page=wp-stocks-sector');

    $stocks    = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 0 ORDER BY sector, id");
    $tech_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_technicals");
    $tech_map  = [];
    foreach ($tech_rows as $t) $tech_map[$t->stock_id] = $t;

    // 最新株価を一括取得
    $price_map  = [];
    $price_rows = $wpdb->get_results("SELECT sp.stock_id, sp.price, sp.previous_close FROM {$wpdb->prefix}stock_prices sp INNER JOIN (SELECT stock_id, MAX(datetime) as md FROM {$wpdb->prefix}stock_prices GROUP BY stock_id) latest ON sp.stock_id = latest.stock_id AND sp.datetime = latest.md");
    foreach ($price_rows as $p) $price_map[$p->stock_id] = $p;

    // TOPIX-17セクターETF銘柄取得（旧wp_stocks_render_heatmap_tab()から移植）
    $etf_stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE is_sector_etf = 1 ORDER BY id");

    // 日本株・米国株・ポートフォリオのセクター別に集計
    $jp_sectors = [];
    $us_sectors = [];
    $pf_sectors = [];
    foreach ($stocks as $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        if ($is_usd) {
            $sector_key = !empty($s->sector) ? $s->sector : 'その他';
        } else {
            // 日本株：手動上書き（sector_override）があれば優先し、なければ四季報抽出値を使う
            $effective_sector = !empty($s->sector_override) ? $s->sector_override : ($s->sector ?? '');
            $sector_key = !empty($effective_sector) ? $effective_sector : 'その他';
        }
        $sector_ja  = wp_stocks_sector_ja($sector_key);
        $score_data = wp_stocks_calc_score($s);
        $tech       = $tech_map[$s->id] ?? null;

        if ($is_usd) {
            wp_stocks_sector_accumulate($us_sectors, $sector_ja, $s, $score_data, $tech);
        } else {
            wp_stocks_sector_accumulate($jp_sectors, $sector_ja, $s, $score_data, $tech);
        }
        if (($s->status ?? '') === 'portfolio') {
            wp_stocks_sector_accumulate($pf_sectors, $sector_ja, $s, $score_data, $tech);
        }
    }
    ksort($jp_sectors);
    ksort($us_sectors);
    ksort($pf_sectors);

    // セクター描画クロージャ
    $render_sectors = function($sectors, $tab_color) use ($tech_map, $price_map) {
        if (empty($sectors)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
            return;
        }
        foreach ($sectors as $sector_name => $data) {
            $cnt       = count($data['stocks']);
            $avg_per   = $data['per_cnt'] > 0 ? round($data['per_sum'] / $data['per_cnt'], 1) : null;
            $avg_roe   = $data['roe_cnt'] > 0 ? round($data['roe_sum'] / $data['roe_cnt'], 1) : null;
            $avg_div   = $data['div_cnt'] > 0 ? round($data['div_sum'] / $data['div_cnt'], 2) : null;
            $avg_score = $cnt > 0 ? round($data['score_sum'] / $cnt) : 0;
            $score_color = $avg_score >= 70 ? '#27ae60' : ($avg_score >= 50 ? '#f39c12' : '#e74c3c');

            echo '<div style="background:#fff;border:1px solid #ddd;border-left:4px solid ' . $tab_color . ';border-radius:8px;padding:16px;margin-bottom:20px;">';
            echo '<div style="display:flex;align-items:center;gap:15px;margin-bottom:12px;flex-wrap:wrap;">';
            echo '<h3 style="margin:0;font-size:16px;">' . esc_html($sector_name) . '</h3>';
            echo '<span style="color:#888;font-size:13px;">' . $cnt . '銘柄</span>';
            echo '<span style="font-weight:bold;color:' . $score_color . ';">平均スコア：' . $avg_score . '点</span>';
            if ($avg_per) echo '<span style="font-size:13px;">平均PER：' . $avg_per . '倍</span>';
            if ($avg_roe) echo '<span style="font-size:13px;">平均ROE：' . $avg_roe . '%</span>';
            if ($avg_div) echo '<span style="font-size:13px;">平均配当：' . $avg_div . '%</span>';

            $total_tech = $data['up'] + $data['down'] + $data['flat'];
            if ($total_tech > 0) {
                echo '<span style="font-size:13px;">トレンド：'
                    . '<span style="color:#e74c3c;">↑' . $data['up'] . '</span> '
                    . '<span style="color:#888;">→' . $data['flat'] . '</span> '
                    . '<span style="color:#3498db;">↓' . $data['down'] . '</span></span>';
            }
            echo '</div>';

            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            foreach ($data['stocks'] as $s) {
                $score_data  = wp_stocks_calc_score($s);
                $sc          = $score_data['score'];
                $jc          = $score_data['judgment']['color'];
                $tech        = $tech_map[$s->id] ?? null;
                $detail_url  = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
                $price       = $price_map[$s->id] ?? null;
                $s_is_usd    = ($s->currency ?? 'JPY') === 'USD';

                $pct_change = null;
                if ($price && ($price->previous_close ?? 0) > 0) {
                    $pct_change = ($price->price - $price->previous_close) / $price->previous_close * 100;
                }
                $heat_bg = ($pct_change !== null) ? wp_stocks_change_heat_color($pct_change) : '#aaa';

                echo '<div style="background:' . $heat_bg . ';border-radius:6px;padding:8px 12px;min-width:140px;color:#fff;">';
                echo '<div style="font-size:11px;opacity:0.85;">' . esc_html($s->code) . '</div>';
                echo '<div style="font-weight:bold;font-size:13px;"><a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:#fff;">' . esc_html($s->name) . '</a></div>';
                if ($price) {
                    $price_disp = $s_is_usd ? '$' . number_format($price->price, 2) : number_format($price->price) . '円';
                    $pct_disp   = ($pct_change !== null) ? (($pct_change >= 0 ? '+' : '') . number_format($pct_change, 2) . '%') : '-';
                    echo '<div style="font-size:12px;margin-top:2px;">' . $price_disp . ' <strong>' . $pct_disp . '</strong></div>';
                } else {
                    echo '<div style="font-size:12px;margin-top:2px;opacity:0.7;">未取得</div>';
                }
                echo '<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">';
                echo '<span style="background:rgba(255,255,255,0.85);color:' . $jc . ';font-weight:bold;font-size:11px;padding:1px 6px;border-radius:3px;">' . $sc . '点</span>';
                if ($tech) {
                    echo '<span style="background:rgba(255,255,255,0.85);border-radius:3px;padding:1px 6px;">' . wp_stocks_trend_icon_html($tech) . '</span>';
                }
                echo '</div>';
                echo '</div>';
            }
            echo '</div></div>';
        }
    };

    // --------------------------------------------------
    // 日本株タブ専用：TOPIX-17（1617〜1633）→33業種→個別銘柄の階層マップ描画
    // 対応表に一致しない（＝分類不能な）銘柄は末尾の「未分類」枠にまとめる
    // --------------------------------------------------
    $render_jp_hierarchy = function($jp_sectors) use ($tech_map, $price_map, $etf_stocks) {
        if (empty($jp_sectors)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
            return;
        }

        $topix17_map = wp_stocks_get_topix17_sector_map();

        // TOPIX-17セクターETF自体の価格・騰落率（ベンチマークとして見出しに併記する）
        $etf_price_map = [];
        foreach ($etf_stocks as $es) {
            $p = $price_map[$es->id] ?? null;
            if ($p && ($p->previous_close ?? 0) > 0) {
                $etf_price_map[$es->code] = [
                    'price' => $p->price,
                    'pct'   => ($p->price - $p->previous_close) / $p->previous_close * 100,
                ];
            } else {
                $etf_price_map[$es->code] = ['price' => null, 'pct' => null];
            }
        }

        $tile_html = function($s) use ($tech_map, $price_map) {
            $score_data = wp_stocks_calc_score($s);
            $sc         = $score_data['score'];
            $jc         = $score_data['judgment']['color'];
            $tech       = $tech_map[$s->id] ?? null;
            $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);
            $price      = $price_map[$s->id] ?? null;

            $pct_change = null;
            if ($price && ($price->previous_close ?? 0) > 0) {
                $pct_change = ($price->price - $price->previous_close) / $price->previous_close * 100;
            }
            $heat_bg = ($pct_change !== null) ? wp_stocks_change_heat_color($pct_change) : '#aaa';

            echo '<div style="background:' . $heat_bg . ';border-radius:6px;padding:8px 12px;min-width:140px;color:#fff;">';
            echo '<div style="font-size:11px;opacity:0.85;">' . esc_html($s->code) . '</div>';
            echo '<div style="font-weight:bold;font-size:13px;"><a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:#fff;">' . esc_html($s->name) . '</a></div>';
            if ($price) {
                $price_disp = number_format($price->price) . '円';
                $pct_disp   = ($pct_change !== null) ? (($pct_change >= 0 ? '+' : '') . number_format($pct_change, 2) . '%') : '-';
                echo '<div style="font-size:12px;margin-top:2px;">' . $price_disp . ' <strong>' . $pct_disp . '</strong></div>';
            } else {
                echo '<div style="font-size:12px;margin-top:2px;opacity:0.7;">未取得</div>';
            }
            echo '<div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">';
            echo '<span style="background:rgba(255,255,255,0.85);color:' . $jc . ';font-weight:bold;font-size:11px;padding:1px 6px;border-radius:3px;">' . $sc . '点</span>';
            if ($tech) {
                echo '<span style="background:rgba(255,255,255,0.85);border-radius:3px;padding:1px 6px;">' . wp_stocks_trend_icon_html($tech) . '</span>';
            }
            echo '</div></div>';
        };

        $avg_pct_of = function($stock_list) use ($price_map) {
            $list = [];
            foreach ($stock_list as $s) {
                $p = $price_map[$s->id] ?? null;
                if ($p && ($p->previous_close ?? 0) > 0) {
                    $list[] = ($p->price - $p->previous_close) / $p->previous_close * 100;
                }
            }
            return !empty($list) ? array_sum($list) / count($list) : null;
        };

        // 33業種バケットをTOPIX-17コードごとにグルーピング。対応表に無いものは未分類へ。
        $grouped       = [];
        $unclassified  = [];
        foreach ($jp_sectors as $sector33 => $data) {
            $topix17_code = wp_stocks_sector33_to_topix17($sector33);
            if ($topix17_code === null) {
                foreach ($data['stocks'] as $s) $unclassified[] = $s;
                continue;
            }
            $grouped[$topix17_code][$sector33] = $data;
        }
        ksort($grouped);

        foreach ($grouped as $topix17_code => $sub_sectors) {
            ksort($sub_sectors);
            $group_stocks = [];
            foreach ($sub_sectors as $data) $group_stocks = array_merge($group_stocks, $data['stocks']);

            $group_avg_pct = $avg_pct_of($group_stocks);
            $group_color   = $group_avg_pct !== null ? wp_stocks_change_heat_color($group_avg_pct) : '#aaa';
            $topix17_name  = $topix17_map[$topix17_code]['name'] ?? $topix17_code;
            $etf_info      = $etf_price_map[$topix17_code] ?? null;
            $panel_id      = 'wss-topix17-' . $topix17_code;

            echo '<div style="border:1px solid #ddd;border-radius:8px;margin-bottom:16px;overflow:hidden;">';
            echo '<div class="wp-stocks-topix17-header" data-target="' . esc_attr($panel_id) . '" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;cursor:pointer;background:' . $group_color . ';color:#fff;">';
            echo '<div style="display:flex;align-items:center;gap:8px;">';
            echo '<span class="wss-topix17-caret" style="display:inline-block;transition:transform 0.2s;">&#x25BC;</span>';
            echo '<strong style="font-size:15px;">' . esc_html($topix17_code) . ' ' . esc_html($topix17_name) . '</strong>';
            echo '<span style="font-size:12px;opacity:0.85;">（' . count($group_stocks) . '銘柄）</span>';
            echo '</div>';
            echo '<div style="display:flex;align-items:center;gap:14px;">';
            if ($etf_info && $etf_info['price'] !== null) {
                $etf_pct_str = ($etf_info['pct'] >= 0 ? '+' : '') . number_format($etf_info['pct'], 2) . '%';
                echo '<span style="font-size:12px;opacity:0.9;">ETF ' . number_format($etf_info['price']) . '円　' . esc_html($etf_pct_str) . '</span>';
            }
            echo '<span style="font-size:14px;font-weight:bold;">' . ($group_avg_pct !== null ? (($group_avg_pct >= 0 ? '+' : '') . number_format($group_avg_pct, 2) . '%') : '-') . '</span>';
            echo '</div></div>';

            echo '<div id="' . esc_attr($panel_id) . '" style="padding:12px 16px;">';
            foreach ($sub_sectors as $sector33 => $data) {
                $sub_avg_pct = $avg_pct_of($data['stocks']);
                $sub_color   = $sub_avg_pct !== null ? wp_stocks_change_heat_color($sub_avg_pct) : '#888';

                echo '<div style="display:flex;align-items:center;justify-content:space-between;margin:10px 0 6px;">';
                echo '<span style="font-size:13px;color:#555;">' . esc_html($sector33) . ' <span style="color:#999;">（' . count($data['stocks']) . '銘柄）</span></span>';
                echo '<span style="font-size:12px;font-weight:bold;color:' . $sub_color . ';">' . ($sub_avg_pct !== null ? (($sub_avg_pct >= 0 ? '+' : '') . number_format($sub_avg_pct, 2) . '%') : '-') . '</span>';
                echo '</div>';

                echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">';
                foreach ($data['stocks'] as $s) $tile_html($s);
                echo '</div>';
            }
            echo '</div></div>';
        }

        if (!empty($unclassified)) {
            echo '<div style="border:1px dashed #ccc;border-radius:8px;padding:12px 16px;margin-top:8px;">';
            echo '<div style="font-size:13px;color:#888;margin-bottom:8px;">未分類（' . count($unclassified) . '銘柄）　'
                . '<span style="font-size:12px;">四季報から業種を判定できなかった銘柄です。各銘柄の編集タブ「業種（手動設定）」から分類してください。</span></div>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            foreach ($unclassified as $s) {
                $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id . '&tab=edit');
                echo '<a href="' . esc_url($detail_url) . '" style="text-decoration:none;">';
                echo '<div style="background:#eee;border-radius:6px;padding:6px 10px;font-size:12px;color:#555;">' . esc_html($s->code) . ' ' . esc_html($s->name) . '</div>';
                echo '</a>';
            }
            echo '</div></div>';
        }

        echo '<script>
        (function(){
            var headers = document.querySelectorAll(".wp-stocks-topix17-header");
            headers.forEach(function(h){
                h.addEventListener("click", function(){
                    var body = document.getElementById(this.dataset.target);
                    var caret = this.querySelector(".wss-topix17-caret");
                    if (!body) return;
                    if (body.style.display === "none") {
                        body.style.display = "block";
                        if (caret) caret.style.transform = "rotate(0deg)";
                    } else {
                        body.style.display = "none";
                        if (caret) caret.style.transform = "rotate(-90deg)";
                    }
                });
            });
        })();
        </script>';
    };

    // ============================================================
    // 描画
    // ============================================================
    if (!$skip_wrap) echo '<div class="wrap"><h1>&#x1F3ED; セクター別分析サマリー</h1>';

    // タブ
    echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
    foreach ([
        'jp'        => '&#x1F1EF;&#x1F1F5; 日本株（' . array_sum(array_map(fn($d) => count($d['stocks']), $jp_sectors)) . '銘柄）',
        'us'        => '&#x1F1FA;&#x1F1F8; 米国株（' . array_sum(array_map(fn($d) => count($d['stocks']), $us_sectors)) . '銘柄）',
        'portfolio' => '&#x1F4C1; ポートフォリオ（' . array_sum(array_map(fn($d) => count($d['stocks']), $pf_sectors)) . '銘柄）',
        'etf'       => '&#x1F3C6; ランキング',
        'calendar'  => '&#x1F4C5; カレンダー',
        'period'    => '&#x23F1;&#xFE0F; 期間別',
    ] as $key => $label) {
        $active = $tab === $key;
        $url    = $base_url . '&tab=' . $key;
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;margin-bottom:20px;">';

    // 凡例（前日比の色分け）を上部に表示（日本株/米国株/ポートフォリオ/ランキングタブのみ）
    if (in_array($tab, ['jp', 'us', 'portfolio', 'etf'], true)) {
    echo '<div style="margin-bottom:20px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:12px;color:#555;">';
    echo '<strong>凡例：</strong> ';
    echo '<span style="display:inline-block;background:rgb(40,220,40);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25B2;+5%以上</span>';
    echo '<span style="display:inline-block;background:rgb(30,150,30);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25B2;+1〜5%</span>';
    echo '<span style="display:inline-block;background:#888;color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">0%</span>';
    echo '<span style="display:inline-block;background:rgb(150,30,30);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25BC;-1〜5%</span>';
    echo '<span style="display:inline-block;background:rgb(220,40,40);color:#fff;padding:2px 10px;border-radius:3px;margin-right:5px;">&#x25BC;-5%以上</span>';
    echo '<span style="display:inline-block;background:#aaa;color:#fff;padding:2px 10px;border-radius:3px;">未取得</span>';
    echo '</div>';
    }

    if ($tab === 'jp') {
        $render_jp_hierarchy($jp_sectors);
    } elseif ($tab === 'us') {
        $render_sectors($us_sectors, '#3498db');
    } elseif ($tab === 'etf') {
        // TOPIX-17セクター指数ランキング（実際のETF価格ベース、騰落率順に全17件を1列表示）
        if (empty($etf_stocks)) {
            echo '<p style="color:#888;padding:20px;">TOPIX-17セクターETFが登録されていません。</p>';
        } else {
            $topix17_map = wp_stocks_get_topix17_sector_map();
            $ranking = [];
            foreach ($etf_stocks as $s) {
                $p        = $price_map[$s->id] ?? null;
                $map_info = $topix17_map[$s->code] ?? null;
                $label    = $map_info['name'] ?? $s->name;
                if ($p && ($p->previous_close ?? 0) > 0) {
                    $pct   = ($p->price - $p->previous_close) / $p->previous_close * 100;
                    $price = $p->price;
                } else {
                    $pct   = null;
                    $price = null;
                }
                $ranking[] = [
                    'id'    => $s->id,
                    'code'  => $s->code,
                    'label' => $label,
                    'pct'   => $pct,
                    'price' => $price,
                ];
            }
            // 騰落率降順（未取得はnullとして最後尾）
            usort($ranking, function($a, $b) {
                if ($a['pct'] === null && $b['pct'] === null) return 0;
                if ($a['pct'] === null) return 1;
                if ($b['pct'] === null) return -1;
                return $b['pct'] <=> $a['pct'];
            });

            echo '<div style="max-width:520px;">';
            echo '<div style="background:#222;color:#fff;padding:8px 14px;font-size:13px;font-weight:bold;border-radius:6px 6px 0 0;">'
                . '&#x1F3C6; TOPIX-17業種別指数 ランキング（更新日時：' . esc_html(date('Y/m/d H:i')) . '）</div>';
            echo '<div style="border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;overflow:hidden;">';
            foreach ($ranking as $i => $row) {
                $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $row['id']);
                if ($row['pct'] === null) {
                    $row_bg   = '#f5f5f5';
                    $pct_bg   = '#999';
                    $pct_str  = '未取得';
                } else {
                    $up      = $row['pct'] >= 0;
                    $row_bg  = $up ? '#eaf7ee' : '#fdecec';
                    $pct_bg  = $up ? '#2ecc71' : '#e74c3c';
                    $pct_str = ($up ? '&#x25B2;' : '&#x25BC;') . number_format(abs($row['pct']), 2) . '%';
                }
                $price_str = $row['price'] !== null ? number_format($row['price']) : '-';

                echo '<a href="' . esc_url($detail_url) . '" style="text-decoration:none;color:inherit;">';
                echo '<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;background:' . $row_bg . ';'
                    . ($i > 0 ? 'border-top:1px solid #eee;' : '') . '">';
                echo '<span style="background:' . $pct_bg . ';color:#fff;font-size:12px;font-weight:bold;padding:2px 8px;border-radius:4px;min-width:64px;text-align:center;">' . $pct_str . '</span>';
                echo '<span style="flex:1;font-size:13px;color:#333;">' . esc_html($row['label']) . '</span>';
                echo '<span style="font-size:12px;color:#666;">' . $price_str . '</span>';
                echo '</div></a>';
            }
            echo '</div></div>';
        }
    } elseif ($tab === 'calendar') {
        // TOPIX-17業種別 日次騰落率カレンダー（2ヶ月分の枠、データが無い日は空欄）
        $etf_history = wp_stocks_get_etf_price_history($etf_stocks, 65);
        if (empty($etf_stocks) || empty($etf_history)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
        } else {
            $topix17_map = wp_stocks_get_topix17_sector_map();
            $cols = [];
            foreach ($etf_stocks as $s) {
                $name  = $topix17_map[$s->code]['name'] ?? $s->name;
                $cols[$s->code] = ['id' => $s->id, 'label' => str_replace('NF・', '', $name)];
            }
            ksort($cols);

            // 日付ごと・コードごとの騰落率
            $pct_by_date = [];
            $date_set    = [];
            foreach ($cols as $code => $c) {
                foreach ($etf_history[$c['id']] ?? [] as $row) {
                    if ($row['previous_close'] > 0) {
                        $pct_by_date[$row['date']][$code] = ($row['price'] - $row['previous_close']) / $row['previous_close'] * 100;
                        $date_set[$row['date']] = true;
                    }
                }
            }
            krsort($date_set);
            $dates = array_slice(array_keys($date_set), 0, 44); // 2ヶ月分の枠（営業日ベース上限）

            // 連続日数（直近日を起点に同方向が何日続いているか。営業日ベースで判定）
            $dates_asc = array_reverse($dates);
            $streaks   = [];
            foreach ($cols as $code => $c) {
                $streak = 0;
                $prev_sign = null;
                foreach ($dates_asc as $d) {
                    $pct = $pct_by_date[$d][$code] ?? null;
                    if ($pct === null) { $streak = 0; $prev_sign = null; continue; }
                    $sign = $pct >= 0 ? 1 : -1;
                    $streak = ($sign === $prev_sign) ? $streak + 1 : 1;
                    $prev_sign = $sign;
                }
                $streaks[$code] = $streak;
            }

            $cell_style = function($pct) {
                if ($pct === null) return ['bg' => '#f7f7f7', 'fg' => '#ccc'];
                $mag = min(abs($pct), 3) / 3; // 3%で最大濃度に正規化
                if ($pct >= 0) {
                    return ['bg' => 'rgba(46,204,113,' . round(0.15 + 0.55 * $mag, 2) . ')', 'fg' => '#1e7e34'];
                }
                return ['bg' => 'rgba(231,76,60,' . round(0.15 + 0.55 * $mag, 2) . ')', 'fg' => '#b02a1e'];
            };

            echo '<div style="overflow-x:auto;">';
            echo '<table style="border-collapse:collapse;font-size:11px;white-space:nowrap;">';
            echo '<tr>';
            echo '<th style="padding:4px 8px;background:#fafafa;border:1px solid #eee;position:sticky;left:0;z-index:1;">業種</th>';
            foreach ($cols as $c) {
                echo '<th style="padding:4px 4px;background:#fafafa;border:1px solid #eee;writing-mode:vertical-rl;text-orientation:upright;font-weight:normal;height:92px;">' . esc_html($c['label']) . '</th>';
            }
            echo '</tr>';
            echo '<tr>';
            echo '<th style="padding:4px 8px;background:#fafafa;border:1px solid #eee;position:sticky;left:0;z-index:1;">連続</th>';
            foreach ($cols as $code => $c) {
                $st = $streaks[$code] ?? 0;
                echo '<td style="padding:4px;text-align:center;border:1px solid #eee;color:#1e7e34;font-weight:bold;">' . ($st >= 2 ? $st : '') . '</td>';
            }
            echo '</tr>';
            foreach ($dates as $d) {
                echo '<tr>';
                echo '<th style="padding:4px 8px;background:#fafafa;border:1px solid #eee;position:sticky;left:0;z-index:1;font-weight:normal;">' . esc_html(date('m/d', strtotime($d))) . '</th>';
                foreach ($cols as $code => $c) {
                    $pct   = $pct_by_date[$d][$code] ?? null;
                    $style = $cell_style($pct);
                    $disp  = $pct !== null ? number_format($pct, 2) . '%' : '';
                    echo '<td style="padding:4px;text-align:center;border:1px solid #eee;background:' . $style['bg'] . ';color:' . $style['fg'] . ';">' . esc_html($disp) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table></div>';
        }
    } elseif ($tab === 'period') {
        // TOPIX-17業種別 期間別騰落率（1日/5日/1ヶ月/2ヶ月/1年）。データ不足の期間は「-」表示
        $etf_history = wp_stocks_get_etf_price_history($etf_stocks, 400);
        if (empty($etf_stocks)) {
            echo '<p style="color:#888;padding:20px;">データがありません。</p>';
        } else {
            $topix17_map = wp_stocks_get_topix17_sector_map();
            $periods = ['1日' => 1, '5日' => 5, '1ヶ月' => 21, '2ヶ月' => 42, '6ヶ月' => 126];

            echo '<div style="overflow-x:auto;">';
            echo '<table style="border-collapse:collapse;width:100%;font-size:13px;">';
            echo '<tr style="background:#222;color:#fff;">';
            echo '<th style="padding:8px 12px;text-align:left;">業種</th>';
            foreach (array_keys($periods) as $label) {
                echo '<th style="padding:8px 12px;text-align:right;">' . esc_html($label) . '</th>';
            }
            echo '</tr>';

            foreach ($etf_stocks as $i => $s) {
                $name   = $topix17_map[$s->code]['name'] ?? $s->name;
                $short  = str_replace('NF・', '', $name);
                $series = $etf_history[$s->id] ?? [];
                $n      = count($series);
                $row_bg = $i % 2 === 0 ? '#fff' : '#f9f9f9';

                echo '<tr style="background:' . $row_bg . ';">';
                echo '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html($short) . '</td>';

                foreach ($periods as $back) {
                    $pct = null;
                    if ($n > 0) {
                        $latest = $series[$n - 1];
                        $idx    = $n - 1 - $back;
                        if ($idx >= 0 && $series[$idx]['price'] > 0) {
                            $pct = ($latest['price'] - $series[$idx]['price']) / $series[$idx]['price'] * 100;
                        }
                    }
                    if ($pct === null) {
                        echo '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;color:#ccc;">-</td>';
                    } else {
                        $color = $pct >= 0 ? '#1e7e34' : '#c0392b';
                        $sign  = $pct >= 0 ? '+' : '';
                        echo '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;color:' . $color . ';font-weight:bold;">' . $sign . number_format($pct, 2) . '%</td>';
                    }
                }
                echo '</tr>';
            }
            echo '</table></div>';
        }
    } else {
        $render_sectors($pf_sectors, '#27ae60');
    }
    echo '</div>';


    if (!$skip_wrap) echo '</div>';
}

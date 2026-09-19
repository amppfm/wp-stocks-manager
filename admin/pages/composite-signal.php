<?php
/**
 * WP Stocks Manager — 管理画面: 複合シグナル判定ページ・判定ロジック
 * wp-stocks-manager.php から分割（Stage 1 ファイル分割）
 */

if (!defined('ABSPATH')) exit;

// --------------------------------------------------
// 複合シグナル判定ロジック
// 既存の stock_technicals テーブル（MA5/MA25/MA75・MACD・RSI・
// ゴールデンクロス/デッドクロス）だけを使い、追加のAPI取得は不要。
// --------------------------------------------------
function wp_stocks_classify_signal($tech, $latest_price = null) {
    $result = [
        'tentative_buy'  => false, // 打診買い（トレンド転換の初動：ゴールデンクロス）
        'tentative_sell' => false, // 打診売り（トレンド転換の初動：デッドクロス）
        'chase_buy'      => false, // 追撃買い（上昇トレンド継続・モメンタム）
        'chase_sell'     => false, // 追撃売り（下降トレンド継続・モメンタム）
        'oversold'       => false, // 売られ過ぎ（逆張り買い候補）
        'overbought'     => false, // 買われ過ぎ（逆張り売り候補）
        'dmi_buy'        => false, // DMI買い（+DIが-DIを上回りADX>=25）
        'dmi_sell'       => false, // DMI売り（-DIが+DIを上回りADX>=25）
        'rci_oversold'   => false, // RCI売られ過ぎ（RCI<=-80）
        'rci_overbought' => false, // RCI買われ過ぎ（RCI>=80）
        'bb_oversold'    => false, // ボリンジャーバンド-2σ突破（逆張り買い）
        'bb_overbought'  => false, // ボリンジャーバンド+2σ突破（逆張り売り）
        'stoch_oversold'      => false, // ストキャス売られ過ぎ（%K<=20）
        'stoch_overbought'    => false, // ストキャス買われ過ぎ（%K>=80）
        'sar_bullish_reversal'=> false, // パラボリックSAR 下→上への転換
        'sar_bearish_reversal'=> false, // パラボリックSAR 上→下への転換
        'fib_near'            => false, // 主要フィボナッチ水準に接近
        'oscillator_reversal_buy'  => false, // RSI売られ過ぎ＋厳格な陽線反転
        'oscillator_reversal_sell' => false, // RSI買われ過ぎ＋厳格な陰線反転
    ];
    if (!$tech) return $result;

    $ma5      = $tech->ma5           ?? null;
    $ma25     = $tech->ma25          ?? null;
    $macd     = $tech->macd          ?? null;
    $macd_sig = $tech->macd_signal   ?? null;
    $rsi      = $tech->rsi           ?? null;
    $cross    = $tech->cross_signal  ?? null;
    $trend    = $tech->trend         ?? 'flat';
    $strength = intval($tech->trend_strength ?? 1);

    // ★変更：①打診買い・打診売りは、単純なGC/DCではなく
    // ボリンジャーσタッチ＋終値反転（下落/上昇トレンド終盤・底値/天井圏向け）を使う
    if (intval($tech->tasin_buy  ?? 0) === 1) $result['tentative_buy']  = true;
    if (intval($tech->tasin_sell ?? 0) === 1) $result['tentative_sell'] = true;

    // ★追加：オシレーター反転確認（RSI売られ過ぎ/買われ過ぎ ＋ 厳格な陽線/陰線反転）
    if (intval($tech->oscillator_reversal_buy  ?? 0) === 1) $result['oscillator_reversal_buy']  = true;
    if (intval($tech->oscillator_reversal_sell ?? 0) === 1) $result['oscillator_reversal_sell'] = true;

    // ② 追撃買い・追撃売り：MA位置関係＋MACD＋トレンド強度が揃った継続モメンタム
    if ($ma5 !== null && $ma25 !== null && $macd !== null && $macd_sig !== null) {
        if ($trend === 'up' && $strength >= 2 && $ma5 > $ma25 && $macd > $macd_sig) {
            $result['chase_buy'] = true;
        }
        if ($trend === 'down' && $strength >= 2 && $ma5 < $ma25 && $macd < $macd_sig) {
            $result['chase_sell'] = true;
        }
    }

    // ③ 売られ過ぎ・買われ過ぎ：RSI基準の逆張りシグナル
    if ($rsi !== null) {
        if ($rsi <= 30) $result['oversold']   = true;
        if ($rsi >= 70) $result['overbought'] = true;
    }

    // ④ DMI：+DI/-DIの優劣（ADX>=25でトレンドが明確な時のみ判定）
    $plus_di  = $tech->plus_di  ?? null;
    $minus_di = $tech->minus_di ?? null;
    $adx      = $tech->adx      ?? null;
    if ($plus_di !== null && $minus_di !== null && $adx !== null && $adx >= 25) {
        if ($plus_di > $minus_di) $result['dmi_buy']  = true;
        if ($minus_di > $plus_di) $result['dmi_sell'] = true;
    }

    // ⑤ RCI：±80基準の逆張りシグナル
    $rci = $tech->rci ?? null;
    if ($rci !== null) {
        if ($rci <= -80) $result['rci_oversold']   = true;
        if ($rci >= 80)  $result['rci_overbought'] = true;
    }

    // ⑥ ボリンジャーバンド：±2σ突破による逆張りシグナル
    $bb_upper = $tech->bb_upper ?? null;
    $bb_lower = $tech->bb_lower ?? null;
    if ($latest_price !== null) {
        if ($bb_lower !== null && $latest_price <= $bb_lower) $result['bb_oversold']   = true;
        if ($bb_upper !== null && $latest_price >= $bb_upper) $result['bb_overbought'] = true;
    }
    // ⑦ ストキャスティクス：Slow %K 20/80基準の逆張りシグナル
    $stoch_k = $tech->stoch_k ?? null;
    if ($stoch_k !== null) {
        if ($stoch_k <= 20) $result['stoch_oversold']   = true;
        if ($stoch_k >= 80) $result['stoch_overbought'] = true;
    }
    // ⑧ パラボリックSAR：直近バーでのトレンド転換
    if (intval($tech->sar_reversal ?? 0) === 1) {
        if (($tech->sar_trend ?? '') === 'up')   $result['sar_bullish_reversal'] = true;
        if (($tech->sar_trend ?? '') === 'down') $result['sar_bearish_reversal'] = true;
    }
    // ⑨ フィボナッチ：主要水準への接近
    if (intval($tech->fib_near ?? 0) === 1) $result['fib_near'] = true;

    return $result;
}

// --------------------------------------------------
// 個別シグナルの方向マップ（買い方向/売り方向）。
// 「テクニカル」タブで複合スコアとの整合性（裏付け/騙しの可能性）を判定するために使用。
// --------------------------------------------------
function wp_stocks_signal_direction_map() {
    return [
        'tentative_buy'        => 'buy',  'tentative_sell'       => 'sell',
        'chase_buy'            => 'buy',  'chase_sell'           => 'sell',
        'oversold'             => 'buy',  'overbought'           => 'sell',
        'dmi_buy'              => 'buy',  'dmi_sell'             => 'sell',
        'rci_oversold'         => 'buy',  'rci_overbought'       => 'sell',
        'bb_oversold'          => 'buy',  'bb_overbought'        => 'sell',
        'stoch_oversold'       => 'buy',  'stoch_overbought'     => 'sell',
        'sar_bullish_reversal' => 'buy',  'sar_bearish_reversal' => 'sell',
        // ローソク足パターン（「ローソク足」タブでも複合判定列を出せるように）
        'bullish_engulfing'    => 'buy',  'bearish_engulfing'    => 'sell',
        'bullish'              => 'buy',  'bearish'              => 'sell',
        'aka_sanpei'           => 'buy',  'strong_aka_sanpei'    => 'buy',
        'narabi_aka'           => 'buy',
        // ★追加：オシレーター反転確認
        'oscillator_reversal_buy' => 'buy', 'oscillator_reversal_sell' => 'sell',
    ];
}

// --------------------------------------------------
// 個別シグナルと複合スコアの整合性バッジ（✅裏付けあり／⚠️騙しの可能性）
// --------------------------------------------------
function wp_stocks_signal_backing_badge_html($bucket_key, $composite_score) {
    $dir = wp_stocks_signal_direction_map()[$bucket_key] ?? null;
    if ($dir === null) return '';
    $is_backed = ($dir === 'buy') ? ($composite_score > 0) : ($composite_score < 0);
    return $is_backed
        ? '<span style="display:block;margin-top:3px;font-size:10px;color:#27ae60;font-weight:bold;">&#x2705; 裏付けあり</span>'
        : '<span style="display:block;margin-top:3px;font-size:10px;color:#e67e22;font-weight:bold;">&#x26A0;&#xFE0F; 騙しの可能性</span>';
}

// --------------------------------------------------
// ★追加：シグナル一覧の各バケットをアコーディオン（折りたたみ）表示にするためのヘルパー
// 銘柄数が多いバケットが常時展開されて縦に長くなる問題を解消する
// --------------------------------------------------
function wp_stocks_render_accordion_assets() {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    ?>
    <script>
    (function() {
        function initAccordions() {
            document.querySelectorAll('.wp-stocks-accordion-header').forEach(function(header) {
                if (header.dataset.accInit) return;
                header.dataset.accInit = '1';
                header.addEventListener('click', function() {
                    var body = document.getElementById(this.dataset.target);
                    if (!body) return;
                    var isOpen = body.style.display !== 'none';
                    body.style.display = isOpen ? 'none' : 'block';
                    var arrow = this.querySelector('.wp-stocks-accordion-arrow');
                    if (arrow) arrow.innerHTML = isOpen ? '&#9660;' : '&#9650;';
                });
            });
            if (window.location.hash) {
                try {
                    var target = document.querySelector(window.location.hash);
                    if (target && target.classList.contains('wp-stocks-accordion-item')) {
                        var body   = target.querySelector('.wp-stocks-accordion-body');
                        var header = target.querySelector('.wp-stocks-accordion-header');
                        if (body) body.style.display = 'block';
                        if (header) {
                            var arrow = header.querySelector('.wp-stocks-accordion-arrow');
                            if (arrow) arrow.innerHTML = '&#9650;';
                        }
                        setTimeout(function() { target.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
                    }
                } catch (e) {}
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAccordions);
        } else {
            initAccordions();
        }
    })();
    </script>
    <?php
}

function wp_stocks_accordion_open($id, $heading_html) {
    echo '<div class="wp-stocks-accordion-item" id="' . esc_attr($id) . '" style="margin-bottom:10px;border:1px solid #eee;border-radius:6px;overflow:hidden;">';
    echo '<div class="wp-stocks-accordion-header" data-target="' . esc_attr($id) . '-body" style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fafafa;">';
    echo '<div style="flex:1;">' . $heading_html . '</div>';
    echo '<span class="wp-stocks-accordion-arrow" style="font-size:12px;color:#888;margin-left:10px;">&#9660;</span>';
    echo '</div>';
    echo '<div class="wp-stocks-accordion-body" id="' . esc_attr($id) . '-body" style="display:none;padding:12px 14px;">';
}

function wp_stocks_accordion_close() {
    echo '</div></div>';
}

// --------------------------------------------------
// ★追加：打診買い/打診売り専用の信頼性バッジ（4段階）
// バンドウォーク中の危険域・逆方向の過熱警戒・裏付けあり・要観察、を判定する
// --------------------------------------------------
function wp_stocks_reliability_badge_html($tech, $direction) {
    if (!$tech) return '';
    $score       = intval($tech->composite_score ?? 0);
    $rsi         = $tech->rsi ?? null;
    $macd_hist   = $tech->macd_hist ?? null;
    $bandwalk_dir = $tech->bandwalk_direction ?? null;

    // ★変更：下落バンドウォーク中は買いシグナルを、上昇バンドウォーク中は売りシグナルを警告扱いにする
    $is_bandwalk_danger   = ($direction === 'buy' && $bandwalk_dir === 'down')
                          || ($direction === 'sell' && $bandwalk_dir === 'up');
    $is_high_price_danger = ($direction === 'buy'  && $rsi !== null && $rsi >= 70)
                          || ($direction === 'sell' && $rsi !== null && $rsi <= 30);
    $has_backing = ($direction === 'buy')
        ? ($score > 0 || ($macd_hist !== null && $macd_hist > 0))
        : ($score < 0 || ($macd_hist !== null && $macd_hist < 0));

    if ($is_bandwalk_danger) {
        return '<span style="background:#e74c3c;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;" title="下落トレンドが強すぎます。即死ゾーンにつき静観推奨。">&#x1F6A8; 騙しの可能性（警告）</span>';
    } elseif ($is_high_price_danger) {
        return '<span style="background:#e67e22;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;" title="逆方向の過熱シグナルです。飛び乗りに注意。">&#x26A0;&#xFE0F; 騙しの可能性（過熱警戒）</span>';
    } elseif ($has_backing) {
        return '<span style="background:#27ae60;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;" title="複合スコアまたはMACDの裏付けがあります。">&#x1F7E2; 裏付けあり（本物候補）</span>';
    }
    return '<span style="background:#95a5a6;color:#fff;padding:3px 9px;border-radius:4px;font-weight:bold;font-size:10px;display:inline-block;margin-top:3px;">&#x26AA; 要観察（シグナルのみ）</span>';
}

// --------------------------------------------------
// 複合シグナルページ本体
// --------------------------------------------------
function wp_stocks_composite_signal_page() {
    global $wpdb;

    $tab      = in_array($_GET['tab'] ?? '', ['jp', 'us']) ? $_GET['tab'] : 'jp';
    $filter   = in_array($_GET['filter'] ?? '', ['watchlist', 'portfolio'], true) ? $_GET['filter'] : 'all';
    // ★変更：3タブ構成（シグナル／テクニカル／ローソク足）に変更し、既定タブを「シグナル」に
    $mtab     = in_array($_GET['mtab'] ?? '', ['signal', 'technical', 'candle'], true) ? $_GET['mtab'] : 'signal';
    $base_url = admin_url('admin.php?page=wp-stocks-signal');

    $signal_labels = [
        'tentative_buy'        => '打診買い',
        'tentative_sell'       => '打診売り',
        'chase_buy'            => '追撃買い',
        'chase_sell'           => '追撃売り',
        'oversold'             => '売られ過ぎ',
        'overbought'           => '買われ過ぎ',
        'dmi_buy'              => 'DMI買い',
        'dmi_sell'             => 'DMI売り',
        'rci_oversold'         => 'RCI売られ過ぎ',
        'rci_overbought'       => 'RCI買われ過ぎ',
        'bb_oversold'          => 'BB下限突破',
        'bb_overbought'        => 'BB上限突破',
        // ★追加：ラベル未定義だったため「🔥複合:」表示に「・・」が入るバグを修正
        'stoch_oversold'       => 'ストキャス売られ過ぎ',
        'stoch_overbought'     => 'ストキャス買われ過ぎ',
        'sar_bullish_reversal' => 'SAR強気転換',
        'sar_bearish_reversal' => 'SAR弱気転換',
        'fib_near'             => 'フィボナッチ接近',
    ];

    // 全銘柄＋最新テクニカル＋最新株価を一括取得
    $stocks = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio') AND is_sector_etf = 0 ORDER BY code ASC"
    );
    $tech_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stock_technicals");
    $tech_map  = [];
    foreach ($tech_rows as $t) $tech_map[$t->stock_id] = $t;

    $price_rows = $wpdb->get_results(
        "SELECT sp.stock_id, sp.price, sp.previous_close
         FROM {$wpdb->prefix}stock_prices sp
         INNER JOIN (
             SELECT stock_id, MAX(datetime) as md
             FROM {$wpdb->prefix}stock_prices GROUP BY stock_id
         ) latest ON sp.stock_id = latest.stock_id AND sp.datetime = latest.md"
    );
    $price_map = [];
    foreach ($price_rows as $p) $price_map[$p->stock_id] = $p;

    // 日本株／米国株に分けつつ、各カテゴリへ仕分け
    $buckets = [
        'tentative_buy'  => [], 'tentative_sell' => [],
        'chase_buy'      => [], 'chase_sell'     => [],
        'oversold'       => [], 'overbought'     => [],
        'dmi_buy'        => [], 'dmi_sell'       => [],
        'rci_oversold'   => [], 'rci_overbought' => [],
        'bb_oversold'    => [], 'bb_overbought'  => [],
    ];
    // ★追加：複合判定（5段階）のバケット。「シグナル」タブで使用
    $composite_buckets = [
        'strong_buy' => [], 'buy' => [], 'hold' => [], 'sell' => [], 'strong_sell' => [],
    ];
    $jp_count   = 0;
    $us_count   = 0;
    $signal_map = []; // stock_id => 発生中のシグナルキー一覧（複合シグナル判定用）

    foreach ($stocks as $s) {
        $is_usd = ($s->currency ?? 'JPY') === 'USD';
        if ($is_usd) $us_count++; else $jp_count++;
        if (($is_usd && $tab !== 'us') || (!$is_usd && $tab !== 'jp')) continue;

        // 絞り込みフィルター
        if ($filter === 'watchlist' && intval($s->is_watchlist ?? 0) !== 1) continue;
        if ($filter === 'portfolio' && ($s->status ?? '') !== 'portfolio') continue;

        $tech   = $tech_map[$s->id] ?? null;
        $latest = $price_map[$s->id] ?? null;
        $flags  = wp_stocks_classify_signal($tech, $latest ? $latest->price : null);
        $active_keys = [];
        foreach ($flags as $key => $on) {
            if ($on) {
                $buckets[$key][] = $s;
                $active_keys[] = $key;
            }
        }
        if (!empty($active_keys)) $signal_map[$s->id] = $active_keys;

        // ★追加：複合判定バケットへの仕分け（stock_technicals.composite_labelを使用）
        $composite_label = $tech->composite_label ?? 'hold';
        if (!isset($composite_buckets[$composite_label])) $composite_label = 'hold';
        $composite_buckets[$composite_label][] = $s;
    }

    // ★変更：銘柄コード順ではなく、複合判定スコア順に並び替える。
    // 買い系バケットはスコアの高い順（降順）、売り系バケットはマイナスが大きい順（昇順）にする。
    $wp_stocks_sort_by_composite = function(array &$list, $ascending) use ($tech_map) {
        usort($list, function($a, $b) use ($tech_map, $ascending) {
            $score_a = intval($tech_map[$a->id]->composite_score ?? 0);
            $score_b = intval($tech_map[$b->id]->composite_score ?? 0);
            return $ascending ? ($score_a <=> $score_b) : ($score_b <=> $score_a);
        });
    };
    // $buckets のうち「売り方向」のバケットは昇順（マイナスが大きい順）にする
    $wp_stocks_sell_direction_buckets = ['tentative_sell', 'chase_sell', 'overbought', 'dmi_sell', 'rci_overbought', 'bb_overbought'];
    foreach ($buckets as $wp_stocks_bkey => &$wp_stocks_bucket_ref) {
        $wp_stocks_sort_by_composite($wp_stocks_bucket_ref, in_array($wp_stocks_bkey, $wp_stocks_sell_direction_buckets, true));
    }
    unset($wp_stocks_bucket_ref);
    // $composite_buckets のうち sell/strong_sell は昇順（マイナスが大きい順）にする
    $wp_stocks_sell_composite_labels = ['sell', 'strong_sell'];
    foreach ($composite_buckets as $wp_stocks_ckey => &$wp_stocks_bucket_ref) {
        $wp_stocks_sort_by_composite($wp_stocks_bucket_ref, in_array($wp_stocks_ckey, $wp_stocks_sell_composite_labels, true));
    }
    unset($wp_stocks_bucket_ref);

    // 1銘柄行描画クロージャ（既存ダッシュボードと似た体裁に統一）
    // ★変更：$bucket_key を追加。個別シグナルバケットの「方向」との整合性バッジ（✅/⚠️）を出すために使用
    $render_stock_row = function($s, $bucket_key = null) use ($tech_map, $price_map, $signal_map, $signal_labels) {
        $is_usd     = ($s->currency ?? 'JPY') === 'USD';
        $latest     = $price_map[$s->id] ?? null;
        $tech       = $tech_map[$s->id]  ?? null;
        $detail_url = admin_url('admin.php?page=wp-stocks-company&stock_id=' . $s->id);

        $price_str = '未取得';
        if ($latest) {
            $price_str = $is_usd ? '$' . number_format($latest->price, 2) : number_format($latest->price) . '円';
        }
        $change_html = ($latest && ($latest->previous_close ?? 0) > 0)
            ? wp_stocks_change_html($latest->price, $latest->previous_close, $is_usd) : '';

        $composite_detail_arr = [];
        if ($tech && !empty($tech->composite_detail)) {
            $decoded_detail = json_decode($tech->composite_detail, true);
            if (is_array($decoded_detail)) $composite_detail_arr = $decoded_detail;
        }
        $is_multi    = count($composite_detail_arr) >= 2;
        $row_style   = $is_multi ? 'background:#fff8e1;' : '';
        $judged_at   = ($tech && !empty($tech->calculated_at)) ? date('n/j', strtotime($tech->calculated_at)) : '-';

        // ★追加：複合判定バッジ＋（バケット指定時のみ）裏付け/騙しバッジ
        $composite_score = intval($tech->composite_score ?? 0);
        if ($tech) {
            $composite_html = wp_stocks_composite_badge_html($composite_score);
            if ($bucket_key === 'tentative_buy') {
                $composite_html .= wp_stocks_reliability_badge_html($tech, 'buy');
            } elseif ($bucket_key === 'tentative_sell') {
                $composite_html .= wp_stocks_reliability_badge_html($tech, 'sell');
            } elseif ($bucket_key !== null) {
                $composite_html .= wp_stocks_signal_backing_badge_html($bucket_key, $composite_score);
            }
        } else {
            $composite_html = '<span style="color:#aaa;">-</span>';
        }

        echo '<tr style="' . $row_style . '">';
        echo '<td>' . esc_html($s->code) . '</td>';
        echo '<td><a href="' . esc_url($detail_url) . '">' . esc_html($s->name) . '</a>' . esc_html(wp_stocks_market_segment_suffix($s->market ?? ''));
        if ($is_multi) {
            echo '<br><span style="font-size:10px;color:#e67e22;font-weight:bold;">&#x1F525; 根拠: ' . esc_html(implode('・', $composite_detail_arr)) . '</span>';
        }
        echo '</td>';
        echo '<td>' . esc_html(!empty($s->sector) ? wp_stocks_sector_ja($s->sector) : '-') . '</td>';
        echo '<td>' . $price_str . '</td>';
        echo '<td>' . $change_html . '</td>';
        echo '<td>' . ($tech ? wp_stocks_trend_icon_html($tech) : '<span style="color:#aaa;">-</span>') . '</td>';
        echo '<td>' . $composite_html . '</td>';
        echo '<td style="font-size:11px;color:#888;">' . esc_html($judged_at) . '</td>';
        echo '</tr>';
    };

    // ★変更：$bucket_key を受け取り $render_stock_row に橋渡しし、ヘッダーに「複合判定」列を追加
    $render_bucket_table = function($stocks_list, $bucket_key = null) use ($render_stock_row) {
        if (empty($stocks_list)) {
            echo '<p style="color:#888;padding:10px 0;">該当銘柄はありません。</p>';
            return;
        }
        echo '<table class="widefat fixed striped" style="font-size:12px;margin-bottom:20px;">';
        echo '<thead><tr><th>コード</th><th>銘柄名</th><th>セクター</th><th>現在値</th><th>前日比</th><th>トレンド</th><th>複合判定</th><th>判定日時</th></tr></thead><tbody>';
        foreach ($stocks_list as $s) $render_stock_row($s, $bucket_key);
        echo '</tbody></table>';
    };

    echo '<div class="wrap"><h1>&#x1F3AF; 今日のシグナル一覧</h1>';
    wp_stocks_render_accordion_assets();
    echo '<p style="color:#666;font-size:13px;">登録済み銘柄（ウォッチ＋ポートフォリオ）のテクニカル指標から、'
        . 'ゴールデンクロス/デッドクロス（打診）・トレンド継続モメンタム（追撃）・RSI逆張り（売られ過ぎ/買われ過ぎ）の3種類のシグナルを自動判定します。'
        . '毎日のテクニカル計算Cron実行後に反映されます。&#x1F525;マークが付いた銘柄は複数のシグナルが同時に発生しています。クリックで開閉できます。</p>';

    // ★変更：大分類タブを「シグナル／テクニカル／ローソク足」の3つに
    echo '<ul style="display:flex;gap:0;border-bottom:2px solid #0073aa;margin:0 0 20px 0;padding:0;list-style:none;flex-wrap:wrap;">';
    foreach (['signal' => '&#x1F3AF; シグナル', 'technical' => '&#x1F4C8; テクニカル', 'candle' => '&#x1F56F;&#xFE0F; ローソク足'] as $mkey => $mlabel) {
        $mis_active = $mtab === $mkey;
        $murl = $base_url . '&mtab=' . $mkey;
        $mstyle = $mis_active
            ? 'display:block;padding:10px 18px;background:#0073aa;color:#fff;text-decoration:none;font-size:13px;font-weight:bold;border-radius:4px 4px 0 0;'
            : 'display:block;padding:10px 18px;background:#f1f1f1;color:#555;text-decoration:none;font-size:13px;border-radius:4px 4px 0 0;border:1px solid #ddd;border-bottom:none;';
        echo '<li style="margin:0 2px 0 0;"><a href="' . esc_url($murl) . '" style="' . $mstyle . '">' . $mlabel . '</a></li>';
    }
    echo '</ul>';

    // ★追加：「シグナル」タブ本体。複合スコア5段階（強い買い/買い優勢/HOLD/売り優勢/強い売り）で銘柄を分類する
    if ($mtab === 'signal') {

    echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
    foreach (['jp' => '&#x1F1EF;&#x1F1F5; 日本株（' . $jp_count . '）', 'us' => '&#x1F1FA;&#x1F1F8; 米国株（' . $us_count . '）'] as $key => $label) {
        $active = $tab === $key;
        $url    = $base_url . '&tab=' . $key . '&filter=' . $filter . '&mtab=signal';
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;">';

    // 打診買い・打診売りの実データをシグナルタブの上部に直接表示
    // （テクニカルタブと同じ $buckets / $render_bucket_table を再利用）
    echo '<h3 style="margin-top:0;border-left:4px solid #27ae60;padding-left:10px;">&#x1F7E2; 打診買い（ゴールデンクロス）（' . count($buckets['tentative_buy']) . '銘柄）</h3>';
    $render_bucket_table($buckets['tentative_buy'], 'tentative_buy');

    echo '<h3 style="border-left:4px solid #e74c3c;padding-left:10px;">&#x1F534; 打診売り（デッドクロス）（' . count($buckets['tentative_sell']) . '銘柄）</h3>';
    $render_bucket_table($buckets['tentative_sell'], 'tentative_sell');

    echo '<div style="margin-bottom:15px;display:flex;gap:8px;">';
    foreach (['all' => '全て', 'watchlist' => '&#x2605; 注目株のみ', 'portfolio' => '&#x1F4C1; ポートフォリオのみ'] as $fkey => $flabel) {
        $is_factive = $filter === $fkey;
        $furl = $base_url . '&tab=' . $tab . '&filter=' . $fkey . '&mtab=signal';
        echo '<a href="' . esc_url($furl) . '" class="button" style="'
            . ($is_factive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '')
            . 'font-weight:bold;">' . $flabel . '</a>';
    }
    echo '</div>';

    $composite_sections = [
        'strong_buy'  => ['&#x1F7E2; 強い買い',  '#27ae60'],
        'buy'         => ['&#x1F535; 買い優勢',  '#3498db'],
        'sell'        => ['&#x1F7E0; 売り優勢',  '#e67e22'],
        'strong_sell' => ['&#x1F534; 強い売り',  '#e74c3c'],
    ];
    foreach ($composite_sections as $ckey => [$clabel, $ccolor]) {
        $ccount = count($composite_buckets[$ckey]);
        echo '<h2 style="border-left:4px solid ' . $ccolor . ';padding-left:10px;">' . $clabel . '（' . $ccount . '銘柄）</h2>';
        // シグナルタブでは「バケットの方向との整合性」バッジは対象外（bucket_key=null）
        $render_bucket_table($composite_buckets[$ckey], null);
    }

    echo '</div>';

    } elseif ($mtab === 'technical') {

    // 日本株/米国株タブ
    echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
    foreach (['jp' => '&#x1F1EF;&#x1F1F5; 日本株（' . $jp_count . '）', 'us' => '&#x1F1FA;&#x1F1F8; 米国株（' . $us_count . '）'] as $key => $label) {
        $active = $tab === $key;
        // ★修正：mtab=technicalを引き継がないと日本株/米国株切替時に既定タブ（シグナル）へ戻ってしまうため追加
        $url    = $base_url . '&tab=' . $key . '&filter=' . $filter . '&mtab=technical';
        echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
            . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
            . '">' . $label . '</a>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;">';

    // 絞り込みフィルター
    echo '<div style="margin-bottom:15px;display:flex;gap:8px;">';
    foreach (['all' => '全て', 'watchlist' => '&#x2605; 注目株のみ', 'portfolio' => '&#x1F4C1; ポートフォリオのみ'] as $fkey => $flabel) {
        $is_factive = $filter === $fkey;
        $furl = $base_url . '&tab=' . $tab . '&filter=' . $fkey . '&mtab=technical';
        echo '<a href="' . esc_url($furl) . '" class="button" style="'
            . ($is_factive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '')
            . 'font-weight:bold;">' . $flabel . '</a>';
    }
    echo '</div>';

    // ★追加：RSI由来とRCI由来の「売られ過ぎ／買われ過ぎ」を1つのバケットに統合する
    // （銘柄数が多いバケットが乱立する問題を緩和。両方同時発生時は既存の🔥複合タグで分かる）
    $wp_stocks_merge_unique_buckets = function(array $keys) use ($buckets) {
        $seen = [];
        $merged = [];
        foreach ($keys as $k) {
            foreach ($buckets[$k] as $s) {
                if (!isset($seen[$s->id])) {
                    $seen[$s->id] = true;
                    $merged[] = $s;
                }
            }
        }
        return $merged;
    };
    $buckets['oscillator_oversold']   = $wp_stocks_merge_unique_buckets(['oversold', 'rci_oversold']);
    $buckets['oscillator_overbought'] = $wp_stocks_merge_unique_buckets(['overbought', 'rci_overbought']);
    $wp_stocks_sort_by_composite($buckets['oscillator_oversold'], false);
    $wp_stocks_sort_by_composite($buckets['oscillator_overbought'], true);

    // シグナル一覧サマリーテーブル（件数＋アンカーリンク）
    $summary_rows = [
        ['tentative_buy',        '&#x1F7E2; 打診買い（ゴールデンクロス）'],
        ['tentative_sell',       '&#x1F534; 打診売り（デッドクロス）'],
        ['chase_buy',            '&#x1F7E2; 追撃買い（上昇モメンタム継続）'],
        ['chase_sell',           '&#x1F534; 追撃売り（下降モメンタム継続）'],
        ['oscillator_oversold',   '&#x1F535; 売られ過ぎ（RSI&le;30 または RCI&le;-80）'],
        ['oscillator_overbought', '&#x1F7E0; 買われ過ぎ（RSI&ge;70 または RCI&ge;80）'],
        ['dmi_buy',              '&#x1F7E2; DMI買い（+DI優勢・ADX&ge;25）'],
        ['dmi_sell',             '&#x1F534; DMI売り（-DI優勢・ADX&ge;25）'],
        ['bb_oversold',          '&#x1F535; BB下限突破（-2&sigma;・逆張り買い）'],
        ['bb_overbought',        '&#x1F7E0; BB上限突破（+2&sigma;・逆張り売り）'],
    ];
    echo '<table class="widefat fixed striped" style="font-size:13px;max-width:480px;margin-bottom:25px;">';
    echo '<thead><tr><th>シグナル</th><th style="text-align:right;">該当銘柄数</th></tr></thead><tbody>';
    foreach ($summary_rows as $row) {
        list($key, $label) = $row;
        $cnt = count($buckets[$key]);
        echo '<tr><td><a href="#sig_' . esc_attr($key) . '">' . $label . '</a></td><td style="text-align:right;">' . intval($cnt) . ' 銘柄</td></tr>';
    }
    echo '</tbody></table>';

    // ★変更：各バケットをアコーディオン化（クリックで開閉）。見出しにも件数を表示する。
    wp_stocks_accordion_open('sig_tentative_buy', '<span style="border-left:4px solid #27ae60;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E2; 打診買い（ゴールデンクロス）（' . count($buckets['tentative_buy']) . '銘柄）</span>');
    $render_bucket_table($buckets['tentative_buy'], 'tentative_buy');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_tentative_sell', '<span style="border-left:4px solid #e74c3c;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F534; 打診売り（デッドクロス）（' . count($buckets['tentative_sell']) . '銘柄）</span>');
    $render_bucket_table($buckets['tentative_sell'], 'tentative_sell');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_chase_buy', '<span style="border-left:4px solid #27ae60;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E2; 追撃買い（上昇モメンタム継続）（' . count($buckets['chase_buy']) . '銘柄）</span>');
    $render_bucket_table($buckets['chase_buy'], 'chase_buy');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_chase_sell', '<span style="border-left:4px solid #e74c3c;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F534; 追撃売り（下降モメンタム継続）（' . count($buckets['chase_sell']) . '銘柄）</span>');
    $render_bucket_table($buckets['chase_sell'], 'chase_sell');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_oscillator_oversold', '<span style="border-left:4px solid #3498db;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F535; 売られ過ぎ（RSI&le;30 または RCI&le;-80）（' . count($buckets['oscillator_oversold']) . '銘柄）</span>');
    $render_bucket_table($buckets['oscillator_oversold'], 'oversold');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_oscillator_overbought', '<span style="border-left:4px solid #f39c12;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E0; 買われ過ぎ（RSI&ge;70 または RCI&ge;80）（' . count($buckets['oscillator_overbought']) . '銘柄）</span>');
    $render_bucket_table($buckets['oscillator_overbought'], 'overbought');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_dmi_buy', '<span style="border-left:4px solid #27ae60;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E2; DMI買い（+DI優勢・ADX&ge;25）（' . count($buckets['dmi_buy']) . '銘柄）</span>');
    $render_bucket_table($buckets['dmi_buy'], 'dmi_buy');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_dmi_sell', '<span style="border-left:4px solid #e74c3c;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F534; DMI売り（-DI優勢・ADX&ge;25）（' . count($buckets['dmi_sell']) . '銘柄）</span>');
    $render_bucket_table($buckets['dmi_sell'], 'dmi_sell');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_bb_oversold', '<span style="border-left:4px solid #3498db;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F535; BB下限突破（-2&sigma;・逆張り買い）（' . count($buckets['bb_oversold']) . '銘柄）</span>');
    $render_bucket_table($buckets['bb_oversold'], 'bb_oversold');
    wp_stocks_accordion_close();

    wp_stocks_accordion_open('sig_bb_overbought', '<span style="border-left:4px solid #f39c12;padding-left:10px;font-size:15px;font-weight:bold;">&#x1F7E0; BB上限突破（+2&sigma;・逆張り売り）（' . count($buckets['bb_overbought']) . '銘柄）</span>');
    $render_bucket_table($buckets['bb_overbought'], 'bb_overbought');
    wp_stocks_accordion_close();

    echo '</div>';

    } elseif ($mtab === 'candle') {

        $candle_labels = [
            'bullish_engulfing' => '&#x1F7E2; 陽線包み足（強気転換）',
            'bearish_engulfing' => '&#x1F534; 陰線包み足（弱気転換）',
            'doji'              => '&#x26AA; 十字線（迷い）',
            'bullish'           => '&#x1F7E2; 陽線',
            'bearish'           => '&#x1F534; 陰線',
        ];
        $candle_buckets = [
            'bullish_engulfing' => [], 'bearish_engulfing' => [],
            'doji' => [], 'bullish' => [], 'bearish' => [],
        ];
        foreach ($stocks as $s) {
            $is_usd = ($s->currency ?? 'JPY') === 'USD';
            if (($is_usd && $tab !== 'us') || (!$is_usd && $tab !== 'jp')) continue;
            if ($filter === 'watchlist' && intval($s->is_watchlist ?? 0) !== 1) continue;
            if ($filter === 'portfolio' && ($s->status ?? '') !== 'portfolio') continue;

            $tech    = $tech_map[$s->id] ?? null;
            $pattern = $tech->candle_pattern ?? null;
            if ($pattern && isset($candle_buckets[$pattern])) {
                $candle_buckets[$pattern][] = $s;
            }
        }

        // 日本株/米国株タブ
        echo '<div style="margin-bottom:0;border-bottom:3px solid #0073aa;">';
        foreach (['jp' => '&#x1F1EF;&#x1F1F5; 日本株（' . $jp_count . '）', 'us' => '&#x1F1FA;&#x1F1F8; 米国株（' . $us_count . '）'] as $key => $label) {
            $active = $tab === $key;
            $url    = $base_url . '&tab=' . $key . '&filter=' . $filter . '&mtab=candle';
            echo '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 20px;margin-right:4px;margin-bottom:-3px;border-radius:4px 4px 0 0;text-decoration:none;font-size:14px;font-weight:bold;'
                . ($active ? 'background:#0073aa;color:#fff;border:3px solid #0073aa;border-bottom:none;' : 'background:#f0f0f0;color:#555;border:1px solid #ddd;border-bottom:none;')
                . '">' . $label . '</a>';
        }
        echo '</div>';

        echo '<div style="background:#fff;border:1px solid #ddd;border-top:none;padding:20px;">';

        // 絞り込みフィルター
        echo '<div style="margin-bottom:15px;display:flex;gap:8px;">';
        foreach (['all' => '全て', 'watchlist' => '&#x2605; 注目株のみ', 'portfolio' => '&#x1F4C1; ポートフォリオのみ'] as $fkey => $flabel) {
            $is_factive = $filter === $fkey;
            $furl = $base_url . '&tab=' . $tab . '&filter=' . $fkey . '&mtab=candle';
            echo '<a href="' . esc_url($furl) . '" class="button" style="'
                . ($is_factive ? 'background:#0073aa;color:#fff;border-color:#005f8b;' : '')
                . 'font-weight:bold;">' . $flabel . '</a>';
        }
        echo '</div>';

        echo '<p style="color:#888;font-size:12px;">直近の日足終値をもとに判定した単純なローソク足パターンです。まだテクニカル計算が実行されていない銘柄は表示されません。</p>';

        foreach ($candle_labels as $ckey => $clabel) {
            wp_stocks_accordion_open('sig_candle_' . $ckey, '<span style="border-left:4px solid #0073aa;padding-left:10px;font-size:15px;font-weight:bold;">' . $clabel . '（' . count($candle_buckets[$ckey]) . '銘柄）</span>');
            $render_bucket_table($candle_buckets[$ckey], $ckey);
            wp_stocks_accordion_close();
        }

        echo '</div>';

    }

    echo '</div>';
}

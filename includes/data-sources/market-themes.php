<?php
/**
 * WP Stocks Manager — テーマ・基準指数の定義（相場スコア用）
 * 2026-10-11 追加。ここに載せた系列は、指数の履歴（wp_stock_index_history）に日次で保存される。
 * 第1段階：基準になる系列の履歴を貯め、期間別騰落率で確認できるようにする。
 * 銘柄へのテーマのタグ付けと相場スコアへの反映は、このあとの段階で追加する。
 *
 * ※シンボルはYahoo!ファイナンス形式。日本のETFは「コード.T」。取得できないシンボルは
 *   マーケット情報「指数」タブの履歴状況表に件数0で出るので、取得ログで理由を確認する。
 */

if (!defined('ABSPATH')) exit;

// グループ別：[グループ名 => [['label' => .., 'symbol' => ..], ...]]
function wp_stocks_theme_reference_groups() {
    return [
        '基準指数' => [
            ['label' => 'TOPIX（連動ETF 1306）', 'symbol' => '1306.T'],
        ],
        'テーマETF（日本）' => [
            ['label' => '防衛テック・日本株式（513A）',      'symbol' => '513A.T'],
            ['label' => '防衛テック・世界（466A）',          'symbol' => '466A.T'],
            ['label' => 'ゴールド（SPDRゴールド 1326）',     'symbol' => '1326.T'],
            ['label' => '高配当（日経高配当50 1489）',       'symbol' => '1489.T'],
        ],
        '米国セクターETF（SPDR）' => array_map(function ($sym, $d) {
            return ['label' => $d['label'] . '（' . $sym . '）', 'symbol' => $sym];
        }, array_keys(wp_stocks_us_sector_etfs()), array_values(wp_stocks_us_sector_etfs())),
        'テーマETF（米国）' => [
            ['label' => '防衛（ITA）',                       'symbol' => 'ITA'],
            ['label' => 'サイバーセキュリティ（CIBR）',      'symbol' => 'CIBR'],
            ['label' => '再生可能エネルギー（ICLN）',        'symbol' => 'ICLN'],
            ['label' => 'バイオ（XBI）',                     'symbol' => 'XBI'],
            ['label' => 'ウラン・原子力（URA）',             'symbol' => 'URA'],
            ['label' => 'ゴールド（GLD）',                   'symbol' => 'GLD'],
        ],
    ];
}

// 米国株のセクター（業種）用：SPDRのセクターETF11本。names は stocks.sector に入っている表記（英語・日本語の両方）
function wp_stocks_us_sector_etfs() {
    return [
        'XLK'  => ['label' => 'テクノロジー', 'names' => ['Technology', 'テクノロジー', '情報技術']],
        'XLC'  => ['label' => '通信サービス', 'names' => ['Communication Services', '通信サービス']],
        'XLY'  => ['label' => '一般消費財',   'names' => ['Consumer Cyclical', 'Consumer Discretionary', '消費財(景気敏感)', '消費財（景気敏感）']],
        'XLP'  => ['label' => '生活必需品',   'names' => ['Consumer Defensive', 'Consumer Staples', '消費財(生活必需品)', '消費財（生活必需品）']],
        'XLF'  => ['label' => '金融',         'names' => ['Financial Services', 'Financials', '金融サービス']],
        'XLV'  => ['label' => 'ヘルスケア',   'names' => ['Healthcare', 'Health Care', 'ヘルスケア']],
        'XLI'  => ['label' => '資本財',       'names' => ['Industrials', '資本財・サービス']],
        'XLE'  => ['label' => 'エネルギー',   'names' => ['Energy', 'エネルギー']],
        'XLB'  => ['label' => '素材',         'names' => ['Basic Materials', 'Materials', '素材']],
        'XLU'  => ['label' => '公益事業',     'names' => ['Utilities', '公益事業']],
        'XLRE' => ['label' => '不動産',       'names' => ['Real Estate', '不動産']],
    ];
}

// stocks.sector の表記から、対応するSPDRセクターETFのシンボルを返す（大文字小文字は区別しない）。無ければ null
function wp_stocks_us_sector_etf_for($sector) {
    $sector = mb_strtolower(trim((string) $sector));
    if ($sector === '') return null;
    foreach (wp_stocks_us_sector_etfs() as $sym => $d) {
        foreach ($d['names'] as $n) {
            if (mb_strtolower($n) === $sector) return $sym;
        }
    }
    return null;
}

// 履歴の取得対象に加える用：[symbol => label]
function wp_stocks_theme_reference_symbols() {
    $list = [];
    foreach (wp_stocks_theme_reference_groups() as $items) {
        foreach ($items as $item) {
            $list[$item['symbol']] = $item['label'];
        }
    }
    return $list;
}

// --------------------------------------------------
// テーマの定義：基準にする系列（履歴にあるシンボル）と、株探テーマタグと照合するキーワード
// キーワードは stocks.theme_tags（株探から取得したテーマ。カンマ区切り）の各タグに部分一致で照合する。
// ※キーワードは、実際の株探タグ名（stocks.theme_tags の集計）に合わせて設定。下の「テーマ別の該当銘柄」表で拾えている銘柄を確認しながら調整する。
// ※金利・小型新興は、株探のタグでは判定しにくいので、このテーマ定義には含めない（市場区分などで別に扱う）。
// --------------------------------------------------
function wp_stocks_theme_defs() {
    return [
        'semiconductor' => ['label' => '半導体',               'reference' => '^SOX',     'keywords' => ['半導体']],   // 半導体製造装置・半導体部材・パワー半導体も含む
        'ai'            => ['label' => 'AI・大型ハイテク',     'reference' => '^NYFANG',  'keywords' => ['人工知能', '生成AI', 'フィジカルAI', 'データセンター', 'エヌビディア関連']],
        'resource'      => ['label' => '資源・エネルギー',     'reference' => 'CL=F',     'keywords' => ['原油', '石油', 'LNG', 'シェールガス', 'メタンハイドレート']],
        'weak_yen'      => ['label' => '円安メリット',         'reference' => 'USDJPY=X', 'keywords' => ['円安メリット']],
        'defense'       => ['label' => '防衛',                 'reference' => '513A.T',   'keywords' => ['防衛'], 'exclude' => ['生活防衛']],   // 「生活防衛」は別の意味のタグ
        'cyber'         => ['label' => 'サイバーセキュリティ', 'reference' => 'CIBR',     'keywords' => ['サイバーセキュリティ']],
        'renewable'     => ['label' => '再生可能エネルギー',   'reference' => 'ICLN',     'keywords' => ['再生可能エネルギー', '風力発電', '太陽光', 'メガソーラー', '地熱発電', '水素', '燃料電池']],
        'bio'           => ['label' => 'バイオ・創薬',         'reference' => 'XBI',      'keywords' => ['創薬', 'バイオ医薬', 'バイオテクノロジー', '再生医療', 'ゲノム']],   // 「バイオ」単独は「バイオエタノール」に当たるので使わない
        'nuclear'       => ['label' => '原子力・ウラン',       'reference' => 'URA',      'keywords' => ['原子力', 'ウラン', '小型原子炉']],
        'gold'          => ['label' => 'ゴールド',             'reference' => '1326.T',   'keywords' => ['ゴールド', '金鉱']],
        'dividend'      => ['label' => '高配当',               'reference' => '1489.T',   'keywords' => ['好配当', '高配当']],
    ];
}

// --------------------------------------------------
// 銘柄が当てはまるテーマと、そのテーマに一致したタグの数：[テーマのキー => タグの数]
// 各タグにキーワードが部分一致すれば該当。'exclude' に挙げた語を含むタグは対象外。
// $stock: stocks テーブルの1行（theme_tags を参照）
// --------------------------------------------------
function wp_stocks_get_stock_theme_hits($stock) {
    $raw = trim((string) ($stock->theme_tags ?? ''));
    if ($raw === '') return [];

    $tags = [];
    foreach (preg_split('/[,、，]/u', $raw) as $t) {
        $t = trim($t);
        if ($t !== '') $tags[] = mb_strtolower($t);
    }

    $hits = [];
    foreach (wp_stocks_theme_defs() as $key => $def) {
        $excl = array_map('mb_strtolower', $def['exclude'] ?? []);
        foreach ($tags as $tag) {
            foreach ($excl as $ex) {
                if (mb_strpos($tag, $ex) !== false) continue 2;
            }
            foreach ($def['keywords'] as $kw) {
                if (mb_strpos($tag, mb_strtolower($kw)) !== false) {
                    $hits[$key] = ($hits[$key] ?? 0) + 1;
                    break;
                }
            }
        }
    }
    return $hits;
}

// 銘柄が当てはまるテーマのキー一覧
function wp_stocks_get_stock_themes($stock) {
    return array_keys(wp_stocks_get_stock_theme_hits($stock));
}

// --------------------------------------------------
// マーケット情報「指数」タブ：テーマ別の該当銘柄（キーワード調整用の確認表を兼ねる）
// --------------------------------------------------
function wp_stocks_render_theme_stock_panel() {
    global $wpdb;
    $stocks = $wpdb->get_results(
        "SELECT id, code, name, theme_tags FROM {$wpdb->prefix}stocks
         WHERE status IN ('watch','portfolio') AND is_sector_etf = 0 AND theme_tags IS NOT NULL AND theme_tags <> ''
         ORDER BY code ASC"
    );

    $by_theme = [];
    foreach ((array) $stocks as $s) {
        foreach (wp_stocks_get_stock_themes($s) as $key) {
            $by_theme[$key][] = $s;
        }
    }

    $labels = function_exists('wp_stocks_index_history_symbols') ? wp_stocks_index_history_symbols() : [];
    $fmt = function ($pct) {
        if ($pct === null) return '<span style="color:#ccc;">-</span>';
        $c = $pct >= 0 ? '#e74c3c' : '#3498db';
        return '<span style="color:' . $c . ';font-weight:bold;">' . ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%</span>';
    };

    echo '<div style="margin-top:35px;">';
    echo '<h3>&#x1F3F7;&#xFE0F; テーマ別の該当銘柄</h3>';
    echo '<p style="color:#666;font-size:13px;">テーマタグ（株探から取得）に、各テーマのキーワードが含まれる銘柄です（対象：ウォッチ・保有でタグのある ' . count((array) $stocks) . ' 銘柄）。'
        . '拾えていない・余計に拾っている場合は、キーワード（market-themes.php の wp_stocks_theme_defs）を調整します。</p>';

    echo '<div style="overflow-x:auto;"><table style="border-collapse:collapse;background:#fff;border:1px solid #ddd;min-width:760px;"><thead><tr style="background:#f8f9fa;">'
        . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">テーマ</th>'
        . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">基準の系列</th>'
        . '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ddd;">1ヶ月</th>'
        . '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ddd;">6ヶ月</th>'
        . '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ddd;">該当</th>'
        . '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">銘柄（先頭15件）</th>'
        . '</tr></thead><tbody>';

    foreach (wp_stocks_theme_defs() as $key => $def) {
        $list = $by_theme[$key] ?? [];
        $ret  = function_exists('wp_stocks_index_period_returns') ? wp_stocks_index_period_returns($def['reference'])['returns'] : [];
        $names = [];
        foreach (array_slice($list, 0, 15) as $s) $names[] = esc_html($s->name) . '(' . esc_html($s->code) . ')';

        echo '<tr>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">' . esc_html($def['label'])
            . '<div style="font-weight:normal;font-size:11px;color:#999;">' . esc_html(implode(' / ', $def['keywords'])) . '</div></td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-size:12px;">' . esc_html($labels[$def['reference']] ?? $def['reference']) . '</td>'
            . '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;">' . $fmt($ret['1ヶ月'] ?? null) . '</td>'
            . '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;">' . $fmt($ret['6ヶ月'] ?? null) . '</td>'
            . '<td style="padding:8px 12px;text-align:right;border-bottom:1px solid #eee;font-weight:bold;">' . count($list) . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eee;font-size:12px;color:#555;">' . ($names ? implode('、', $names) . (count($list) > 15 ? ' ほか' : '') : '<span style="color:#ccc;">-</span>') . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div></div>';
}

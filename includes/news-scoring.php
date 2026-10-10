<?php
/**
 * WP Stocks Manager — ニュース加減点
 *
 * 立花証券APIで取り込んだニュース（{prefix}stock_tachibana_news）の見出しをルールで判定し、
 * 銘柄ごとの加点・減点（ニューススコア）を作る。
 *
 * - 判定は見出しのキーワード／数値によるルールベース（配信元タグ <TDnet> <決算> <EDINET> ごとにON/OFF可）
 * - 判定結果は {prefix}stock_news_events に保存（ニュース本体の保持期間が過ぎても残る）
 * - スコア = Σ(点数 × 営業日経過による減衰)。同じ種類（group）は最も効いている1件だけ採用、合計は±上限で頭打ち
 * - 手動登録（自分で確認したニュース）も同じ仕組みで加減点する
 *
 * 公開関数:
 *   wp_stocks_news_score_for_symbol($symbol) : ['total'=>float,'items'=>[...]]（複合シグナル等への組み込み用）
 *   wp_stocks_news_company_box($stock)        : 銘柄ページ用の表示ボックスをechoする
 */

if (!defined('ABSPATH')) exit;

const WP_STOCKS_NWS_PAGE = 'wp-stocks-news-score';

// ============================================================
// 設定（点数・上限・配信元ON/OFF・自作キーワード）
// ============================================================

// 点数の初期値（設定画面で変更可）。key => [ラベル, 初期点数]
function wp_stocks_nws_point_defs() {
    return [
        'earnings_score'  => ['決算スコア（÷分母で換算・TDnet）', 0], // 換算は別設定。表示用
        'rev_up'          => ['上方修正（修正幅15%以上／幅不明）', 3],
        'rev_up_small'    => ['上方修正（修正幅15%未満）', 2],
        'rev_down'        => ['下方修正（修正幅15%以上／幅不明）', -3],
        'rev_down_small'  => ['下方修正（修正幅15%未満）', -2],
        'turn_black'      => ['営業利益 黒字転換', 2],
        'turn_red'        => ['営業利益 赤字転落', -2],
        'red_shrink'      => ['営業損失 赤字縮小', 1],
        'red_expand'      => ['営業損失 赤字拡大', -1],
        'op_up_big'       => ['営業利益 +50%以上', 2],
        'op_up'           => ['営業利益 +20〜50%', 1],
        'op_down_big'     => ['営業利益 −50%以上', -2],
        'op_down'         => ['営業利益 −20〜50%', -1],
        'div_up'          => ['増配・配当予想の増額', 2],
        'div_down'        => ['減配・無配・配当予想の減額', -2],
        'div_revival'     => ['復配', 2],
        'buyback'         => ['自社株買い（発行済み3%未満／不明）', 2],
        'buyback_big'     => ['自社株買い（発行済み3%以上）', 3],
        'tob_target'      => ['TOB（当社株式が対象・賛同）', 3],
        'alliance'        => ['資本業務提携', 1],
        'alliance_end'    => ['提携の解消', -1],
        'special_loss'    => ['特別損失・減損', -2],
        'special_gain'    => ['特別利益', 1],
        'dilution'        => ['公募増資・新株発行', -2],
        'sell_out'        => ['株式の売出し', -1],
        'warrant'         => ['新株予約権の発行（第三者割当・行使価額修正条項付）', -1],
        'warrant_cancel'  => ['新株予約権の取得・消却', 1],
        'material_event'  => ['経営成績に著しい影響を与える事象（臨時報告書）', -1],
        'scandal'         => ['不適切会計・粉飾', -3],
        'delisting'       => ['監理・整理銘柄', -3],
        'bankrupt'        => ['民事再生・破産・会社更生', -3],
        'incident'        => ['不正アクセス・情報漏えい（当社の被害）', -2],
        'penalty'         => ['行政処分・課徴金', -2],
        'lawsuit'         => ['当社に対する訴訟の提起', -1],
        'going_concern'   => ['継続企業の前提・債務超過', -2],
        'index_in'        => ['指数の構成銘柄に選定', 1],
        'index_out'       => ['指数の構成銘柄から除外', -1],
        'split'           => ['株式分割', 1],
    ];
}

function wp_stocks_nws_points() {
    $out = [];
    foreach (wp_stocks_nws_point_defs() as $k => $d) $out[$k] = (float)$d[1];
    $saved = get_option('wp_stocks_nws_points', []);
    if (is_array($saved)) {
        foreach ($saved as $k => $v) {
            if (isset($out[$k]) && is_numeric($v)) $out[$k] = (float)$v;
        }
    }
    return $out;
}

function wp_stocks_nws_setting($key, $default) {
    $s = get_option('wp_stocks_nws_settings', []);
    return (is_array($s) && array_key_exists($key, $s)) ? $s[$key] : $default;
}

// 判定対象にする配信元（TDnetは初期オフ）
function wp_stocks_nws_sources() {
    $saved = wp_stocks_nws_setting('sources', null);
    $def = ['決算' => 1, 'EDINET' => 1, 'TDnet' => 0];
    if (!is_array($saved)) return $def;
    foreach ($def as $k => $v) {
        if (array_key_exists($k, $saved)) $def[$k] = $saved[$k] ? 1 : 0;
    }
    return $def;
}

// 自作キーワード（1行1ルール: キーワード,点数,ラベル）
function wp_stocks_nws_custom_rules() {
    $raw = (string)get_option('wp_stocks_nws_custom_rules', '');
    $rules = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $p = array_map('trim', explode(',', $line));
        if (count($p) < 2 || $p[0] === '' || !is_numeric($p[1])) continue;
        $rules[] = ['kw' => $p[0], 'points' => (float)$p[1], 'label' => $p[2] ?? $p[0]];
    }
    return $rules;
}

// ============================================================
// 見出しの解析と判定
// ============================================================

function wp_stocks_nws_num($s) {
    $s = str_replace([',', ' '], '', (string)$s);
    $neg = (strpos($s, '▲') !== false) || (isset($s[0]) && $s[0] === '-');
    $s = ltrim($s, '▲-+');
    $v = is_numeric($s) ? (float)$s : 0.0;
    return $neg ? -$v : $v;
}

function wp_stocks_nws_hit(array &$hits, array $P, $key, $group, $reason, $points = null) {
    $pt = ($points !== null) ? (float)$points : (float)($P[$key] ?? 0);
    if ($pt == 0.0) return;
    $hits[] = ['type' => $key, 'group' => $group, 'points' => $pt, 'reason' => $reason];
}

// "<配信元>AI: 銘柄名(コード) 本文" を分解。銘柄コードが決まらなければ null
function wp_stocks_nws_parse_headline($headline, $issues = '') {
    $h = mb_convert_kana((string)$headline, 'as', 'UTF-8'); // 全角英数・記号・空白を半角に
    $h = trim(preg_replace('/\s+/u', ' ', $h));
    if (!preg_match('/^<([^>]+)>\s*(.*)$/u', $h, $m)) return null;
    $tag = $m[1];
    $t = preg_replace('/^AI:\s*/u', '', $m[2]);

    $code = '';
    if (preg_match('/\(([0-9]{3}[0-9A-Z])\)/', $t, $cm)) {
        $code = $cm[1];
    } else {
        $codes = array_values(array_filter(array_map('trim', explode('|', (string)$issues)), 'strlen'));
        if (count($codes) === 1) $code = strtoupper($codes[0]);
    }
    if ($code === '') return null;
    return ['tag' => $tag, 'text' => $t, 'code' => $code];
}

// 見出し1本を判定。戻り値: null（対象外）または ['code','tag','hits'=>[['type','group','points','reason']...]]
function wp_stocks_nws_classify($headline, $issues = '', $sources = null) {
    $p = wp_stocks_nws_parse_headline($headline, $issues);
    if (!$p) return null;
    $tag = $p['tag'];
    $t = $p['text'];
    $P = wp_stocks_nws_points();
    if ($sources === null) $sources = wp_stocks_nws_sources();

    $hits = [];
    $enabled = !empty($sources[$tag]);

    // 訂正・説明会系は判定しない
    if ($enabled && !preg_match('/訂正|説明会|説明資料/u', $t)) {

        // --- 決算スコア（TDnetの決算発表・業績修正の行） ---
        if (preg_match('/決算スコア:\s*([+\-−▲]?)([0-9]+(?:\.[0-9]+)?)/u', $t, $x)) {
            $v = (float)$x[2];
            if (in_array($x[1], ['-', '−', '▲'], true)) $v = -$v;
            $div = (float)wp_stocks_nws_setting('score_div', 2);
            $cap = (float)wp_stocks_nws_setting('score_cap', 3);
            if ($div <= 0) $div = 2;
            $pt = round(2 * $v / $div) / 2;
            $pt = max(-$cap, min($cap, $pt));
            wp_stocks_nws_hit($hits, $P, 'earnings_score', 'earnings', '決算スコア ' . ($v > 0 ? '+' : '') . $v, $pt);
            // 同じ行の配当予想の修正
            if (preg_match('/配当予想:修正\(([0-9.]+)円→([0-9.]+)円\)/u', $t, $d)) {
                if ((float)$d[2] > (float)$d[1]) wp_stocks_nws_hit($hits, $P, 'div_up', 'dividend', '配当予想の増額 ' . $d[1] . '→' . $d[2] . '円');
                elseif ((float)$d[2] < (float)$d[1]) wp_stocks_nws_hit($hits, $P, 'div_down', 'dividend', '配当予想の減額 ' . $d[1] . '→' . $d[2] . '円');
            }
        }

        // --- <決算> の行：修正・配当・増減益 ---
        if ($tag === '決算') {
            // 「、2027/08予想 …」より前＝実績の部分だけで増減益・黒字赤字を判定する（会社予想を拾わない）
            $a = preg_split('/、\s*\d{4}\/\d{2}予想/u', $t)[0];
            if (preg_match('/(増額修正|減額修正)\s+配当/u', $t, $x)) {
                $up = ($x[1] === '増額修正');
                wp_stocks_nws_hit($hits, $P, $up ? 'div_up' : 'div_down', 'dividend', $up ? '配当の増額修正' : '配当の減額修正');
            } elseif (preg_match('/(上方修正|増額修正|下方修正|減額修正)/u', $t, $x)) {
                $up = in_array($x[1], ['上方修正', '増額修正'], true);
                $big = true;
                $mag = null;
                if (preg_match('/\((▲?[0-9,.]+)[^←)]*←(▲?[0-9,.]+)/u', $t, $q)) {
                    $new = wp_stocks_nws_num($q[1]);
                    $old = wp_stocks_nws_num($q[2]);
                    if ($old != 0.0) $mag = abs(($new - $old) / abs($old));
                }
                if ($mag !== null && $mag < 0.15) $big = false;
                $key = $up ? ($big ? 'rev_up' : 'rev_up_small') : ($big ? 'rev_down' : 'rev_down_small');
                $reason = ($up ? '上方修正' : '下方修正') . ($mag !== null ? sprintf('（%.0f%%）', $mag * 100) : '');
                wp_stocks_nws_hit($hits, $P, $key, 'earnings', $reason);
            } elseif (strpos($a, '黒字転換') !== false) {
                wp_stocks_nws_hit($hits, $P, 'turn_black', 'earnings', '黒字転換');
            } elseif (preg_match('/赤字転落|赤字転換/u', $a)) {
                wp_stocks_nws_hit($hits, $P, 'turn_red', 'earnings', '赤字転落');
            } elseif (strpos($a, '赤字縮小') !== false) {
                wp_stocks_nws_hit($hits, $P, 'red_shrink', 'earnings', '赤字縮小');
            } elseif (strpos($a, '赤字拡大') !== false) {
                wp_stocks_nws_hit($hits, $P, 'red_expand', 'earnings', '赤字拡大');
            } elseif (preg_match('/営業利益\s+([0-9.]+)%(増|減)/u', $a, $x)) {
                $pct = (float)$x[1];
                $up = ($x[2] === '増');
                if ($pct >= 50)      wp_stocks_nws_hit($hits, $P, $up ? 'op_up_big' : 'op_down_big', 'earnings', '営業利益 ' . ($up ? '+' : '−') . $pct . '%');
                elseif ($pct >= 20)  wp_stocks_nws_hit($hits, $P, $up ? 'op_up' : 'op_down', 'earnings', '営業利益 ' . ($up ? '+' : '−') . $pct . '%');
            }
        }

        // --- 配当（TDnet等の見出し） ---
        if (!preg_grep('/^dividend$/', array_column($hits, 'group'))) {
            if (strpos($t, '復配') !== false)              wp_stocks_nws_hit($hits, $P, 'div_revival', 'dividend', '復配');
            elseif (strpos($t, '増配') !== false)          wp_stocks_nws_hit($hits, $P, 'div_up', 'dividend', '増配');
            elseif (preg_match('/減配|無配/u', $t))        wp_stocks_nws_hit($hits, $P, 'div_down', 'dividend', '減配・無配');
        }

        // --- 自社株買い ---
        if (preg_match('/自社株買い|自己株式の取得|自己株式取得/u', $t) && !preg_match('/状況|結果|終了|完了|処分/u', $t)) {
            $pct = null;
            if (preg_match('/発行済み?株式(?:総)?数の([0-9.]+)%/u', $t, $x)) $pct = (float)$x[1];
            $big = ($pct !== null && $pct >= 3.0);
            wp_stocks_nws_hit($hits, $P, $big ? 'buyback_big' : 'buyback', 'buyback', '自社株買い' . ($pct !== null ? "（発行済み{$pct}%）" : ''));
        }

        // --- TOB（当社株式が対象）---
        if (preg_match('/当社(?:の)?株式(?:等)?に対する公開買付け|当社株券等に対する公開買付け|公開買付けに(?:関する)?(?:賛同|応募)|賛同の意見/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'tob_target', 'tob', 'TOB（当社株式が対象）');
        }

        // --- 提携 ---
        if (preg_match('/提携(?:の)?解消/u', $t))               wp_stocks_nws_hit($hits, $P, 'alliance_end', 'alliance', '提携の解消');
        elseif (preg_match('/資本業務提携|資本提携/u', $t))     wp_stocks_nws_hit($hits, $P, 'alliance', 'alliance', '資本業務提携');

        // --- 特別損益 ---
        if (preg_match('/特別損失|減損損失|減損の計上/u', $t))   wp_stocks_nws_hit($hits, $P, 'special_loss', 'special', '特別損失・減損');
        elseif (strpos($t, '特別利益') !== false)               wp_stocks_nws_hit($hits, $P, 'special_gain', 'special', '特別利益');

        // --- 希薄化 ---
        if (preg_match('/新株予約権.{0,20}(?:取得及び消却|消却)/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'warrant_cancel', 'dilution', '新株予約権の取得・消却');
        } elseif (preg_match('/公募増資|新株式の?発行|公募による新株/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'dilution', 'dilution', '公募増資・新株発行');
        } elseif (preg_match('/株式の?売出し/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'sell_out', 'dilution', '株式の売出し');
        } elseif (preg_match('/行使価額修正条項付|第三者割当による(?:新株|新株予約権|増資)|新株予約権\(第三者割当\)/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'warrant', 'dilution', '新株予約権・第三者割当');
        }

        // --- 臨時報告書（EDINET）---
        if ($tag === 'EDINET' && strpos($t, '経営成績などに著しい影響を与える事象') !== false) {
            wp_stocks_nws_hit($hits, $P, 'material_event', 'material', '経営成績に著しい影響を与える事象');
        }

        // --- リスク（最も重いものを採用するため group は同一）---
        if (preg_match('/不適切な会計|不適切会計|会計不正|粉飾/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'scandal', 'risk', '不適切会計');
        }
        if (preg_match('/監理銘柄|整理銘柄|上場廃止猶予/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'delisting', 'risk', '監理・整理銘柄');
        }
        if (preg_match('/民事再生|会社更生|破産|特別清算|事業再生ADR/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'bankrupt', 'risk', '法的整理');
        }
        if (preg_match('/不正アクセス|情報漏えい|情報漏洩|ランサムウェア|サイバー攻撃/u', $t)
            && preg_match('/お詫び|発生|被害|調査|対応について|影響/u', $t)
            && !preg_match('/支援|窓口|サービス|開始|提供|発売|対策|ソリューション/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'incident', 'risk', '不正アクセス・情報漏えい');
        }
        if (preg_match('/行政処分|業務停止命令|課徴金|排除措置命令/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'penalty', 'risk', '行政処分');
        }
        if (preg_match('/当社[^ ]{0,12}に対する[^ ]{0,6}訴訟/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'lawsuit', 'risk', '当社に対する訴訟');
        }
        if (preg_match('/継続企業の前提|債務超過/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'going_concern', 'risk', '継続企業の前提・債務超過');
        }

        // --- 指数・株式分割 ---
        if (preg_match('/構成銘柄から(?:の)?(?:除外|外れ)/u', $t))        wp_stocks_nws_hit($hits, $P, 'index_out', 'index', '指数から除外');
        elseif (preg_match('/構成銘柄(?:への|に)(?:選定|採用)/u', $t))    wp_stocks_nws_hit($hits, $P, 'index_in', 'index', '指数の構成銘柄に選定');
        if (strpos($t, '株式分割') !== false && !preg_match('/取りやめ|中止/u', $t)) {
            wp_stocks_nws_hit($hits, $P, 'split', 'split', '株式分割');
        }
    }

    // --- 自作キーワード（配信元ON/OFFに関係なく、銘柄コードが決まる見出しに適用）---
    foreach (wp_stocks_nws_custom_rules() as $r) {
        if (mb_strpos($t, $r['kw']) !== false) {
            $hits[] = ['type' => 'custom', 'group' => 'custom:' . $r['label'], 'points' => $r['points'], 'reason' => $r['label']];
        }
    }

    return ['code' => $p['code'], 'tag' => $tag, 'hits' => $hits];
}

// ============================================================
// 保存（stock_news_events）
// ============================================================

function wp_stocks_nws_events_table() {
    global $wpdb;
    return $wpdb->prefix . 'stock_news_events';
}

function wp_stocks_nws_ensure_table() {
    global $wpdb;
    $ver = '1';
    if (get_option('wp_stocks_nws_db_version') === $ver) return true;
    $table = wp_stocks_nws_events_table();
    $charset = $wpdb->get_charset_collate();
    // このコードベースの慣習に合わせ、dbDeltaではなく明示的なCREATE TABLE IF NOT EXISTSを使う
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  news_id VARCHAR(64) NOT NULL DEFAULT '',
  code VARCHAR(12) NOT NULL,
  event_at DATETIME NOT NULL,
  event_date DATE NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  event_group VARCHAR(64) NOT NULL,
  points DECIMAL(4,1) NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  headline VARCHAR(500) NOT NULL DEFAULT '',
  source VARCHAR(8) NOT NULL DEFAULT 'auto',
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_event (news_id, code, event_type),
  KEY idx_code_date (code, event_date)
) {$charset};");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return false;
    update_option('wp_stocks_nws_db_version', $ver);
    return true;
}

function wp_stocks_nws_now() {
    return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
}

// ニュースを判定してeventsへ保存。$full=true は直近分の自動判定を消して全件やり直す
function wp_stocks_nws_classify_recent($full = false) {
    global $wpdb;
    $res = ['scanned' => 0, 'events' => 0, 'error' => ''];
    if (!wp_stocks_nws_ensure_table()) { $res['error'] = '加減点テーブルを作成できません'; return $res; }

    $news_t = $wpdb->prefix . 'stock_tachibana_news';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $news_t)) !== $news_t) {
        $res['error'] = 'ニューステーブルがありません'; return $res;
    }
    if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');

    $started = wp_stocks_nws_now();
    $ev_t = wp_stocks_nws_events_table();

    if ($full) {
        $min = $wpdb->get_var("SELECT MIN(news_date) FROM {$news_t}");
        if ($min) $wpdb->query($wpdb->prepare("DELETE FROM {$ev_t} WHERE source = 'auto' AND event_date >= %s", $min));
        $rows = $wpdb->get_results("SELECT news_id, news_at, issues, headline FROM {$news_t} ORDER BY news_at ASC");
    } else {
        $last = (string)get_option('wp_stocks_nws_last_scan', '');
        if ($last !== '') {
            $since = (new DateTime($last, new DateTimeZone('Asia/Tokyo')))->modify('-1 hour')->format('Y-m-d H:i:s');
            $rows = $wpdb->get_results($wpdb->prepare("SELECT news_id, news_at, issues, headline FROM {$news_t} WHERE fetched_at >= %s ORDER BY news_at ASC", $since));
        } else {
            $rows = $wpdb->get_results("SELECT news_id, news_at, issues, headline FROM {$news_t} ORDER BY news_at ASC");
        }
    }

    $sources = wp_stocks_nws_sources();
    $now = wp_stocks_nws_now();
    foreach ((array)$rows as $r) {
        $res['scanned']++;
        $c = wp_stocks_nws_classify($r->headline, $r->issues, $sources);
        if (!$c || !$c['hits']) continue;
        foreach ($c['hits'] as $h) {
            $ok = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$ev_t} (news_id, code, event_at, event_date, event_type, event_group, points, reason, headline, source, created_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %f, %s, %s, 'auto', %s)
                 ON DUPLICATE KEY UPDATE points = VALUES(points), reason = VALUES(reason), event_group = VALUES(event_group), headline = VALUES(headline)",
                $r->news_id, $c['code'], $r->news_at, substr($r->news_at, 0, 10),
                $h['type'], $h['group'], $h['points'], mb_substr($h['reason'], 0, 250),
                mb_substr($r->headline, 0, 480), $now
            ));
            if ($ok !== false) $res['events']++;
        }
    }
    update_option('wp_stocks_nws_last_scan', $started, false);
    update_option('wp_stocks_nws_last_run_ts', time(), false);
    return $res;
}

// 画面表示時の遅延実行（10分に1回まで）
function wp_stocks_nws_maybe_classify() {
    if (time() - (int)get_option('wp_stocks_nws_last_run_ts', 0) > 600) {
        wp_stocks_nws_classify_recent(false);
    }
}

// 取得cronの直後（優先度99）に判定を走らせる
function wp_stocks_nws_cron_run() {
    wp_stocks_nws_classify_recent(false);
}
add_action('wp_stocks_tcn_fetch_event', 'wp_stocks_nws_cron_run', 99);
add_action('wp_stocks_tcn_catchup_event', 'wp_stocks_nws_cron_run', 99);

// ============================================================
// スコア計算（営業日の減衰・同種は最大1件・合計は上限で頭打ち）
// ============================================================

function wp_stocks_nws_normalize_symbol($symbol) {
    return strtoupper(preg_replace('/\.T$/i', '', trim((string)$symbol)));
}

function wp_stocks_nws_is_jp_code($code) {
    return (bool)preg_match('/^[0-9]{3}[0-9A-Z]$/', $code);
}

function wp_stocks_nws_bdays_since($date_ymd, $today_ymd = null) {
    $tz = new DateTimeZone('Asia/Tokyo');
    $d = new DateTime($date_ymd, $tz);
    $today = new DateTime($today_ymd ?: 'today', $tz);
    $holidays = function_exists('wp_stocks_get_holidays') ? (array)wp_stocks_get_holidays() : [];
    $n = 0;
    $guard = 0;
    while ($d < $today && $guard++ < 60) {
        $d->modify('+1 day');
        if ((int)$d->format('N') < 6 && !in_array($d->format('Y-m-d'), $holidays, true)) $n++;
    }
    return $n;
}

// 0〜3営業日 100% / 4〜7日 70% / 8〜14日 40% / 15日以降 0%
function wp_stocks_nws_decay($bdays) {
    if ($bdays <= 3) return 1.0;
    if ($bdays <= 7) return 0.7;
    if ($bdays <= 14) return 0.4;
    return 0.0;
}

// $rows: eventsの行（配列/オブジェクト）。戻り値: ['total'=>, 'items'=>[... + weight, eff, counted]]
function wp_stocks_nws_score_rows(array $rows, $today_ymd = null) {
    $cap = (float)wp_stocks_nws_setting('total_cap', 5);
    $items = [];
    $best = []; // group => index of items
    foreach ($rows as $r) {
        $r = (array)$r;
        $bd = wp_stocks_nws_bdays_since($r['event_date'], $today_ymd);
        $w = wp_stocks_nws_decay($bd);
        $eff = (float)$r['points'] * $w;
        $r['bdays'] = $bd;
        $r['weight'] = $w;
        $r['eff'] = $eff;
        $r['counted'] = false;
        $items[] = $r;
        $i = count($items) - 1;
        if ($w <= 0.0) continue;
        $g = $r['event_group'];
        if (strpos($g, 'manual_') === 0) { $items[$i]['counted'] = true; continue; } // 手動は全件加算
        if (!isset($best[$g]) || abs($eff) > abs($items[$best[$g]]['eff'])) $best[$g] = $i;
    }
    foreach ($best as $i) $items[$i]['counted'] = true;
    $sum = 0.0;
    foreach ($items as $it) if ($it['counted']) $sum += $it['eff'];
    $total = max(-$cap, min($cap, $sum));
    usort($items, function ($a, $b) { return strcmp($b['event_at'], $a['event_at']); });
    return ['total' => round($total, 1), 'items' => $items];
}

// 全銘柄分（直近30日）をまとめて: code => score結果
function wp_stocks_nws_scores_all() {
    global $wpdb;
    wp_stocks_nws_ensure_table();
    $t = wp_stocks_nws_events_table();
    $since = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->modify('-30 days')->format('Y-m-d');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE event_date >= %s", $since), ARRAY_A);
    $by = [];
    foreach ((array)$rows as $r) $by[$r['code']][] = $r;
    $out = [];
    foreach ($by as $code => $list) $out[$code] = wp_stocks_nws_score_rows($list);
    return $out;
}

function wp_stocks_news_score_for_symbol($symbol) {
    global $wpdb;
    wp_stocks_nws_ensure_table();
    $code = wp_stocks_nws_normalize_symbol($symbol);
    $t = wp_stocks_nws_events_table();
    $since = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->modify('-30 days')->format('Y-m-d');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE code = %s AND event_date >= %s", $code, $since), ARRAY_A);
    return wp_stocks_nws_score_rows((array)$rows);
}

// ============================================================
// 表示：銘柄ページ用ボックス
// ============================================================

function wp_stocks_nws_badge($total) {
    $total = (float)$total;
    if ($total >= 3)       { $bg = '#27ae60'; }
    elseif ($total > 0)    { $bg = '#7dcea0'; }
    elseif ($total <= -3)  { $bg = '#c0392b'; }
    elseif ($total < 0)    { $bg = '#e59866'; }
    else                   { $bg = '#999'; }
    return '<span style="display:inline-block;min-width:44px;text-align:center;padding:2px 10px;border-radius:12px;color:#fff;font-weight:bold;background:' . $bg . ';">'
        . esc_html(($total > 0 ? '+' : '') . number_format($total, 1)) . '</span>';
}

function wp_stocks_news_company_box($stock) {
    $symbol = is_object($stock) ? ($stock->symbol ?? '') : (string)$stock;
    $code = wp_stocks_nws_normalize_symbol($symbol);
    if (!wp_stocks_nws_is_jp_code($code)) return;
    wp_stocks_nws_maybe_classify();
    $sc = wp_stocks_news_score_for_symbol($code);

    echo '<div class="wp-stocks-news-box" style="margin:14px 0;padding:12px 16px;background:#fff;border:1px solid #ddd;border-left:4px solid #0073aa;border-radius:4px;max-width:1100px;">';
    echo '<h3 style="margin:0 0 8px;">&#x1F4F0; ニュース加減点 ' . wp_stocks_nws_badge($sc['total']) . '</h3>';
    $shown = array_slice($sc['items'], 0, 8);
    if (!$shown) {
        echo '<p class="description" style="margin:0;">直近30日の加減点対象ニュースはありません。</p>';
    } else {
        echo '<table class="widefat striped" style="font-size:12px;"><thead><tr><th style="width:110px;">日時</th><th style="width:150px;">理由</th><th style="width:70px;">点数</th><th style="width:60px;">減衰</th><th>見出し</th></tr></thead><tbody>';
        foreach ($shown as $it) {
            $style = $it['counted'] ? '' : 'color:#999;';
            $pt = ($it['points'] > 0 ? '+' : '') . rtrim(rtrim(number_format((float)$it['points'], 1), '0'), '.');
            $note = $it['weight'] <= 0 ? '失効' : (!$it['counted'] ? '同種で重複' : '');
            echo '<tr style="' . $style . '"><td>' . esc_html(substr($it['event_at'], 5, 11)) . '</td>'
               . '<td>' . esc_html($it['reason']) . '</td>'
               . '<td>' . esc_html($pt) . '</td>'
               . '<td>' . esc_html(round($it['weight'] * 100) . '%' . ($note ? "（{$note}）" : '')) . '</td>'
               . '<td>' . esc_html($it['headline']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<p class="description" style="margin:8px 0 0;"><a href="' . esc_url(admin_url('admin.php?page=' . WP_STOCKS_NWS_PAGE)) . '">ニュース加減点の一覧・設定</a></p>';
    echo '</div>';
}

// company.phpを編集しなくても銘柄ページに出す（ページslugに"company"を含み、銘柄を特定できるとき）
add_action('admin_notices', function () {
    if (!get_option('wp_stocks_nws_auto_box', 1)) return;
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if ($page === '' || strpos($page, 'company') === false) return;
    global $wpdb;
    $t = $wpdb->prefix . 'stocks';
    $stock = null;
    foreach (['id', 'stock_id'] as $k) {
        if (!empty($_GET[$k]) && ctype_digit((string)$_GET[$k])) {
            $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int)$_GET[$k]));
            break;
        }
    }
    if (!$stock) {
        foreach (['symbol', 'code'] as $k) {
            if (!empty($_GET[$k])) {
                $stock = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE symbol = %s", sanitize_text_field(wp_unslash($_GET[$k]))));
                break;
            }
        }
    }
    if ($stock) wp_stocks_news_company_box($stock);
});

// ============================================================
// 管理画面：ニュース加減点（ランキング・手動登録・設定）
// ============================================================

add_action('admin_menu', function () {
    global $menu;
    $parent = '';
    foreach ((array)$menu as $m) {
        if (!empty($m[2]) && (strpos($m[2], 'wp-stocks') === 0 || strpos($m[2], 'wp_stocks') === 0)) { $parent = $m[2]; break; }
    }
    if ($parent !== '') {
        add_submenu_page($parent, 'ニュース加減点', '📰 ニュース加減点', 'manage_options', WP_STOCKS_NWS_PAGE, 'wp_stocks_nws_page');
    } else {
        add_menu_page('ニュース加減点', 'ニュース加減点', 'manage_options', WP_STOCKS_NWS_PAGE, 'wp_stocks_nws_page', 'dashicons-megaphone', 59);
    }
}, 99);

add_action('admin_post_wp_stocks_nws_action', 'wp_stocks_nws_handle_action');

function wp_stocks_nws_handle_action() {
    global $wpdb;
    if (!current_user_can('manage_options')) wp_die('権限がありません');
    check_admin_referer('wp_stocks_nws_action');
    $op = isset($_REQUEST['op']) ? sanitize_key($_REQUEST['op']) : '';
    $msg = '';
    wp_stocks_nws_ensure_table();
    $ev_t = wp_stocks_nws_events_table();

    if ($op === 'save_settings') {
        $defs = wp_stocks_nws_point_defs();
        $pts = [];
        $in = isset($_POST['pt']) && is_array($_POST['pt']) ? wp_unslash($_POST['pt']) : [];
        foreach ($defs as $k => $d) {
            if ($k === 'earnings_score') continue;
            if (isset($in[$k]) && is_numeric($in[$k])) $pts[$k] = max(-10, min(10, (float)$in[$k]));
        }
        update_option('wp_stocks_nws_points', $pts, false);
        $src = [];
        foreach (['決算', 'EDINET', 'TDnet'] as $s) $src[$s] = !empty($_POST['src'][$s]) ? 1 : 0;
        update_option('wp_stocks_nws_settings', [
            'sources'   => $src,
            'score_div' => max(0.5, (float)($_POST['score_div'] ?? 2)),
            'score_cap' => max(0.5, (float)($_POST['score_cap'] ?? 3)),
            'total_cap' => max(1, (float)($_POST['total_cap'] ?? 5)),
        ], false);
        update_option('wp_stocks_nws_custom_rules', sanitize_textarea_field(wp_unslash($_POST['custom_rules'] ?? '')), false);
        update_option('wp_stocks_nws_auto_box', !empty($_POST['auto_box']) ? 1 : 0, false);
        $msg = 'saved';
    } elseif ($op === 'reclassify') {
        $r = wp_stocks_nws_classify_recent(true);
        $msg = $r['error'] ? 'error' : ('reclassified:' . $r['scanned'] . ':' . $r['events']);
    } elseif ($op === 'add_manual') {
        $code = wp_stocks_nws_normalize_symbol(sanitize_text_field(wp_unslash($_POST['code'] ?? '')));
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $pt = isset($_POST['points']) && is_numeric($_POST['points']) ? max(-10, min(10, (float)$_POST['points'])) : 0.0;
        $memo = sanitize_text_field(wp_unslash($_POST['memo'] ?? ''));
        if (wp_stocks_nws_is_jp_code($code) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $pt != 0.0) {
            $uid = uniqid('', true);
            $wpdb->insert($ev_t, [
                'news_id' => 'manual-' . $uid, 'code' => $code,
                'event_at' => $date . ' 12:00:00', 'event_date' => $date,
                'event_type' => 'manual', 'event_group' => 'manual_' . $uid,
                'points' => $pt, 'reason' => '手動登録', 'headline' => mb_substr($memo, 0, 480),
                'source' => 'manual', 'created_at' => wp_stocks_nws_now(),
            ]);
            $msg = 'manual_added';
        } else {
            $msg = 'manual_invalid';
        }
    } elseif ($op === 'delete_manual') {
        $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        if ($id > 0) $wpdb->query($wpdb->prepare("DELETE FROM {$ev_t} WHERE id = %d AND source = 'manual'", $id));
        $msg = 'manual_deleted';
    }
    wp_safe_redirect(add_query_arg(['page' => WP_STOCKS_NWS_PAGE, 'msg' => $msg], admin_url('admin.php')));
    exit;
}

function wp_stocks_nws_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) return;
    wp_stocks_nws_ensure_table();
    wp_stocks_nws_maybe_classify();

    $action_url = esc_url(admin_url('admin-post.php'));
    $ev_t = wp_stocks_nws_events_table();
    $show_all = !empty($_GET['all']);

    echo '<div class="wrap"><h1>&#x1F4F0; ニュース加減点</h1>';

    // メッセージ
    $msg = isset($_GET['msg']) ? sanitize_text_field(wp_unslash($_GET['msg'])) : '';
    if ($msg !== '') {
        $text = '';
        if ($msg === 'saved') $text = '設定を保存しました。ルールを変えた場合は「再判定」を押してください。';
        elseif (strpos($msg, 'reclassified:') === 0) { $p = explode(':', $msg); $text = '再判定しました（対象ニュース ' . intval($p[1] ?? 0) . ' 件 / 加減点 ' . intval($p[2] ?? 0) . ' 件）。'; }
        elseif ($msg === 'manual_added') $text = '手動の加減点を登録しました。';
        elseif ($msg === 'manual_invalid') $text = '入力が不正です（銘柄コード4桁・日付・0以外の点数が必要）。';
        elseif ($msg === 'manual_deleted') $text = '手動の加減点を削除しました。';
        elseif ($msg === 'error') $text = '再判定に失敗しました。ニューステーブルを確認してください。';
        if ($text) echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    // ---- ランキング ----
    $scores = wp_stocks_nws_scores_all();
    $stocks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}stocks WHERE status IN ('watch','portfolio')");
    $list = [];
    foreach ((array)$stocks as $s) {
        $code = wp_stocks_nws_normalize_symbol($s->symbol ?? '');
        if (!wp_stocks_nws_is_jp_code($code)) continue;
        $sc = $scores[$code] ?? ['total' => 0.0, 'items' => []];
        if (!$show_all && abs($sc['total']) < 0.05 && !array_filter($sc['items'], function ($i) { return $i['counted']; })) continue;
        $list[] = ['code' => $code, 'name' => (string)($s->name ?? ''), 'status' => (string)($s->status ?? ''), 'sc' => $sc];
    }
    usort($list, function ($a, $b) { return $b['sc']['total'] <=> $a['sc']['total']; });

    echo '<h2 style="margin-top:20px;">登録銘柄のニューススコア</h2>';
    echo '<p class="description">直近30日のニュースを、営業日の経過で減衰（0〜3日100%／4〜7日70%／8〜14日40%／15日以降0）させて合計。保有・ウォッチの日本株が対象です。 ';
    echo $show_all
        ? '<a href="' . esc_url(admin_url('admin.php?page=' . WP_STOCKS_NWS_PAGE)) . '">加減点のある銘柄だけ表示</a>'
        : '<a href="' . esc_url(admin_url('admin.php?page=' . WP_STOCKS_NWS_PAGE . '&all=1')) . '">全銘柄を表示</a>';
    echo '</p>';
    if (!$list) {
        echo '<p>加減点のある登録銘柄はありません。</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th style="width:70px;">コード</th><th style="width:200px;">銘柄</th><th style="width:80px;">区分</th><th style="width:80px;">スコア</th><th>主な理由</th></tr></thead><tbody>';
        foreach ($list as $row) {
            $reasons = [];
            foreach ($row['sc']['items'] as $it) {
                if (!$it['counted']) continue;
                $reasons[] = $it['reason'] . '(' . ($it['points'] > 0 ? '+' : '') . rtrim(rtrim(number_format((float)$it['points'], 1), '0'), '.') . '×' . round($it['weight'] * 100) . '%)';
            }
            echo '<tr><td>' . esc_html($row['code']) . '</td><td>' . esc_html($row['name']) . '</td><td>' . esc_html($row['status'] === 'portfolio' ? '保有' : 'ウォッチ') . '</td>'
               . '<td>' . wp_stocks_nws_badge($row['sc']['total']) . '</td><td>' . esc_html(implode(' / ', array_slice($reasons, 0, 5))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ---- 手動登録 ----
    echo '<h2 style="margin-top:30px;">手動で加減点を登録</h2>';
    echo '<form method="post" action="' . $action_url . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
    wp_nonce_field('wp_stocks_nws_action');
    echo '<input type="hidden" name="action" value="wp_stocks_nws_action"><input type="hidden" name="op" value="add_manual">';
    echo '銘柄コード <input type="text" name="code" style="width:80px;" placeholder="7203" required> ';
    echo '日付 <input type="date" name="date" value="' . esc_attr(wp_stocks_nws_now() ? substr(wp_stocks_nws_now(), 0, 10) : '') . '" required> ';
    echo '点数 <input type="number" name="points" step="0.5" min="-10" max="10" style="width:70px;" required> ';
    echo 'メモ <input type="text" name="memo" style="width:320px;" placeholder="例: 新工場の稼働開始報道"> ';
    echo '<button type="submit" class="button button-primary">登録</button></form>';
    echo '<p class="description">手動分も同じ減衰で効き、同種の重複排除は行わず全件加算します。</p>';

    $manual = $wpdb->get_results("SELECT * FROM {$ev_t} WHERE source = 'manual' ORDER BY event_date DESC, id DESC LIMIT 30");
    if ($manual) {
        echo '<table class="widefat striped" style="max-width:900px;margin-top:8px;"><thead><tr><th>日付</th><th>コード</th><th>点数</th><th>メモ</th><th></th></tr></thead><tbody>';
        foreach ($manual as $m) {
            $del = wp_nonce_url(admin_url('admin-post.php?action=wp_stocks_nws_action&op=delete_manual&id=' . (int)$m->id), 'wp_stocks_nws_action');
            echo '<tr><td>' . esc_html($m->event_date) . '</td><td>' . esc_html($m->code) . '</td><td>' . esc_html($m->points) . '</td><td>' . esc_html($m->headline) . '</td>'
               . '<td><a href="' . esc_url($del) . '" onclick="return confirm(\'削除しますか？\');">削除</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    // ---- 設定 ----
    $P = wp_stocks_nws_points();
    $src = wp_stocks_nws_sources();
    echo '<h2 style="margin-top:30px;">判定ルールの設定</h2>';
    echo '<form method="post" action="' . $action_url . '">';
    wp_nonce_field('wp_stocks_nws_action');
    echo '<input type="hidden" name="action" value="wp_stocks_nws_action"><input type="hidden" name="op" value="save_settings">';
    echo '<table class="form-table"><tbody>';

    echo '<tr><th>判定に使う配信元</th><td>';
    foreach (['決算' => '&lt;決算&gt;（決算・修正の速報）', 'EDINET' => '&lt;EDINET&gt;（臨時報告書など）', 'TDnet' => '&lt;TDnet&gt;（適時開示・決算スコア付き）'] as $k => $lbl) {
        echo '<label style="margin-right:16px;"><input type="checkbox" name="src[' . esc_attr($k) . ']" value="1"' . checked(!empty($src[$k]), true, false) . '> ' . $lbl . '</label>';
    }
    echo '<p class="description">TDnetをオフにすると、決算スコア・自社株買い・TOB・提携・特別損益・不祥事などは判定されません（&lt;決算&gt;の修正・増減益は残ります）。変更後は「再判定」を押してください。</p></td></tr>';

    echo '<tr><th>決算スコアの換算</th><td>点数 = 決算スコア ÷ <input type="number" name="score_div" step="0.5" min="0.5" value="' . esc_attr(wp_stocks_nws_setting('score_div', 2)) . '" style="width:60px;"> を0.5刻みで丸め、±<input type="number" name="score_cap" step="0.5" min="0.5" value="' . esc_attr(wp_stocks_nws_setting('score_cap', 3)) . '" style="width:60px;"> で頭打ち（TDnetがオンのときのみ）</td></tr>';
    echo '<tr><th>合計の上限</th><td>±<input type="number" name="total_cap" step="0.5" min="1" value="' . esc_attr(wp_stocks_nws_setting('total_cap', 5)) . '" style="width:60px;"> 点で頭打ち</td></tr>';

    echo '<tr><th>点数表</th><td><table class="widefat striped" style="max-width:640px;"><tbody>';
    foreach (wp_stocks_nws_point_defs() as $k => $d) {
        if ($k === 'earnings_score') continue;
        echo '<tr><td>' . esc_html($d[0]) . '</td><td style="width:90px;"><input type="number" name="pt[' . esc_attr($k) . ']" step="0.5" min="-10" max="10" value="' . esc_attr($P[$k]) . '" style="width:70px;"></td></tr>';
    }
    echo '</tbody></table></td></tr>';

    echo '<tr><th>自作キーワード</th><td><textarea name="custom_rules" rows="5" style="width:520px;" placeholder="キーワード,点数,ラベル（1行1ルール）&#10;例: 新工場,1,新工場の稼働">' . esc_textarea((string)get_option('wp_stocks_nws_custom_rules', '')) . '</textarea>';
    echo '<p class="description">見出しにキーワードが含まれ、銘柄コードが決まるニュースに加減点します（配信元の選択に関係なく適用）。</p></td></tr>';

    echo '<tr><th>銘柄ページへの自動表示</th><td><label><input type="checkbox" name="auto_box" value="1"' . checked((bool)get_option('wp_stocks_nws_auto_box', 1), true, false) . '> 銘柄ページ（企業情報）の上部にニュース加減点を表示する</label></td></tr>';
    echo '</tbody></table>';
    echo '<p><button type="submit" class="button button-primary">設定を保存</button></p></form>';

    echo '<form method="post" action="' . $action_url . '" onsubmit="return confirm(\'保存済みニュースから、自動判定分をすべて判定し直します。よろしいですか？\');">';
    wp_nonce_field('wp_stocks_nws_action');
    echo '<input type="hidden" name="action" value="wp_stocks_nws_action"><input type="hidden" name="op" value="reclassify">';
    echo '<button type="submit" class="button">再判定（保存済みニュースから判定し直す）</button></form>';

    // ---- 直近の判定結果（確認用）----
    $recent = $wpdb->get_results("SELECT * FROM {$ev_t} WHERE source = 'auto' ORDER BY event_at DESC, id DESC LIMIT 60");
    echo '<h2 style="margin-top:30px;">直近の判定結果（確認用）</h2>';
    if (!$recent) {
        echo '<p>まだ判定結果がありません。「再判定」を押してください。</p>';
    } else {
        echo '<table class="widefat striped" style="font-size:12px;"><thead><tr><th style="width:110px;">日時</th><th style="width:60px;">コード</th><th style="width:170px;">理由</th><th style="width:50px;">点数</th><th>見出し</th></tr></thead><tbody>';
        foreach ($recent as $r) {
            echo '<tr><td>' . esc_html(substr($r->event_at, 5, 11)) . '</td><td>' . esc_html($r->code) . '</td><td>' . esc_html($r->reason) . '</td><td>' . esc_html($r->points > 0 ? '+' . $r->points : $r->points) . '</td><td>' . esc_html($r->headline) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

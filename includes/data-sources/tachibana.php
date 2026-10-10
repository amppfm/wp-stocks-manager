<?php
/**
 * WP Stocks Manager — 立花証券 e支店 API 連携（読み取り専用：時価・日足）
 *
 * 前提:
 *   - ログインはNAS側のroot cronが毎朝行い、/volume1/tachibana_keys/session.json に
 *     仮想URLとログイン時点のp_no(last_p_no)を書き出す（PHPからはログインしない）
 *   - p_no は本ファイルが flock 付きで一元発番する（ログイン毎にリセット）
 *   - 戻り値の形は yahoo-scraping.php（wp_stocks_get_price / wp_stocks_get_ohlcv）に合わせる
 *
 * 公開関数:
 *   wp_stocks_tachibana_get_prices(array $codes)       複数銘柄の現在値を1リクエスト単位でまとめて取得
 *   wp_stocks_tachibana_get_price($symbol)             Yahoo互換 ['c','previous_close','volume'] / 失敗時 false（60秒キャッシュ）
 *   wp_stocks_tachibana_fetch_daily_bars($symbol, $n)  Yahoo互換の日足配列 / 失敗時 false
 *   wp_stocks_tachibana_debug($symbol)                 動作確認用（生の応答の一部を返す）
 *
 * ※ 要確認項目には「要確認」と注記している。debug関数の出力で確定させる。
 */

if (!defined('ABSPATH')) exit;

if (!defined('WP_STOCKS_TACHIBANA_SESSION_FILE')) {
    define('WP_STOCKS_TACHIBANA_SESSION_FILE', '/volume1/tachibana_keys/session.json');
}
if (!defined('WP_STOCKS_TACHIBANA_STATE_FILE')) {
    define('WP_STOCKS_TACHIBANA_STATE_FILE', '/volume1/tachibana_keys/p_no_state.json');
}

// --------------------------------------------------
// ログ（既存の wp_stocks_log があればそれを使う。仮想URLは絶対にログへ出さない）
// --------------------------------------------------
function wp_stocks_tachibana_log($level, $context, $message) {
    // 同じメッセージのログは10分間まとめる（セッション失効時の大量ログ防止）
    $dedupe_key = 'wp_stocks_tcb_log_' . md5($level . $message);
    if (get_transient($dedupe_key) !== false) return;
    set_transient($dedupe_key, 1, 600);
    if (function_exists('wp_stocks_log')) {
        wp_stocks_log($level, 'tachibana', $context, $message);
    }
}

// --------------------------------------------------
// 銘柄コードの正規化（Yahoo形式 "7203.T" → "7203"）
// --------------------------------------------------
function wp_stocks_tachibana_is_jp_symbol($symbol) {
    return (bool)preg_match('/^[0-9]{3}[0-9A-Z]\.T$/i', trim((string)$symbol));
}

function wp_stocks_tachibana_normalize_code($symbol) {
    return preg_replace('/\.T$/i', '', trim((string)$symbol));
}

function wp_stocks_tachibana_num($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return floatval($v);
}

// --------------------------------------------------
// session.json の読み込みと簡易チェック
//   - ログイン時刻の次の 03:30（API閉局）を過ぎていたら失効扱いにし、APIは呼ばない
// --------------------------------------------------
function wp_stocks_tachibana_session() {
    $raw = @file_get_contents(WP_STOCKS_TACHIBANA_SESSION_FILE);
    if ($raw === false) {
        return new WP_Error('no_session', 'session.json を読めません（権限・open_basedir・ログイン未実施の可能性）');
    }
    $s = json_decode($raw, true);
    if (!is_array($s) || empty($s['login_at']) || empty($s['urls']['sUrlPrice'])) {
        return new WP_Error('bad_session', 'session.json の形式が想定と違います');
    }

    $tz     = new DateTimeZone('Asia/Tokyo');
    $login  = (new DateTime('@' . (int)$s['login_at']))->setTimezone($tz);
    $expire = clone $login;
    $expire->setTime(3, 30, 0);
    if ($expire <= $login) $expire->modify('+1 day');
    if (new DateTime('now', $tz) >= $expire) {
        return new WP_Error('session_expired', '仮想URLは閉局(03:30)で失効済みです（次回のログインcronまで利用不可）');
    }
    return $s;
}

// --------------------------------------------------
// p_no の一元発番（flock）
//   - ログイン毎に session.json の login_at が変わる → その時の last_p_no から再開
//   - p_no は「前回より大きい値」であればよい（+1ずつ増やす）
// --------------------------------------------------
function wp_stocks_tachibana_next_p_no(array $session) {
    $fp = @fopen(WP_STOCKS_TACHIBANA_STATE_FILE, 'c+');
    if (!$fp) {
        return new WP_Error('p_no_state', 'p_no状態ファイルを開けません（/volume1/tachibana_keys の書き込み権限を確認）');
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return new WP_Error('p_no_lock', 'p_no状態ファイルのロックに失敗しました');
    }

    $st = json_decode(stream_get_contents($fp), true);
    if (!is_array($st) || (int)($st['login_at'] ?? 0) !== (int)$session['login_at']) {
        $st = ['login_at' => (int)$session['login_at'], 'p_no' => (int)($session['last_p_no'] ?? 1)];
    }
    $st['p_no']++;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, wp_json_encode($st));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod(WP_STOCKS_TACHIBANA_STATE_FILE, 0600);

    return $st['p_no'];
}

// --------------------------------------------------
// 共通リクエスト
//   $iface: 'request' | 'master' | 'price'（session.json の sUrlRequest / sUrlMaster / sUrlPrice）
//   戻り値: 応答の連想配列 / WP_Error
// --------------------------------------------------
function wp_stocks_tachibana_request($iface, array $params) {
    static $last = 0.0;

    $session = wp_stocks_tachibana_session();
    if (is_wp_error($session)) return $session;

    $keymap = ['request' => 'sUrlRequest', 'master' => 'sUrlMaster', 'price' => 'sUrlPrice'];
    $base   = $session['urls'][$keymap[$iface] ?? ''] ?? '';
    if ($base === '') return new WP_Error('no_url', "仮想URL({$iface})がsession.jsonにありません");

    // 連続リクエストの間隔を確保（約0.15秒）
    $wait = 0.15 - (microtime(true) - $last);
    if ($wait > 0) usleep((int)($wait * 1000000));

    $p_no = wp_stocks_tachibana_next_p_no($session);
    if (is_wp_error($p_no)) return $p_no;

    $now     = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    $payload = array_merge(
        ['p_no' => (string)$p_no, 'p_sd_date' => $now->format('Y.m.d-H:i:s.v')],
        $params,
        ['sJsonOfmt' => '4'] // 項目名付きJSON（要確認: 公式サンプルは "5"。どちらも項目名は付く）
    );

    // 送信: JSON → URLエンコードして ? の後ろへ
    $url = $base . '?' . urlencode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $res = wp_remote_get($url, ['timeout' => 20, 'headers' => ['Accept' => 'application/json']]);
    $last = microtime(true);

    if (is_wp_error($res)) {
        return new WP_Error('http_error', '通信エラー: ' . $res->get_error_message());
    }
    $http = wp_remote_retrieve_response_code($res);
    if ($http !== 200) {
        return new WP_Error('http_status', "HTTP {$http}");
    }

    // 受信: 応答はShift_JIS → UTF-8
    $body = mb_convert_encoding(wp_remote_retrieve_body($res), 'UTF-8', 'SJIS-win');
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return new WP_Error('bad_json', '応答をJSONとして解釈できません');
    }

    // エラー判定: p_errno（通信・セッション系）と sResultCode（業務系）
    $errno = (string)($data['p_errno'] ?? '');
    $rc    = (string)($data['sResultCode'] ?? '');
    if (($errno !== '' && $errno !== '0') || ($rc !== '' && $rc !== '0')) {
        $text = (string)($data['p_err'] ?? '') . ' ' . (string)($data['sResultText'] ?? '');
        return new WP_Error('api_error', "p_errno={$errno} sResultCode={$rc} " . trim($text), [
            'p_errno' => $errno, 'sResultCode' => $rc,
        ]);
    }
    return $data;
}

// 応答の中から「各要素が $key を持つ配列」のリストを探す（リスト項目名に依存しないため）
function wp_stocks_tachibana_find_rows(array $data, $key) {
    foreach ($data as $v) {
        if (is_array($v) && isset($v[0]) && is_array($v[0]) && array_key_exists($key, $v[0])) return $v;
    }
    return [];
}

// --------------------------------------------------
// 複数銘柄の現在値（CLMMfdsGetMarketPrice）
//   戻り値: [ '7203' => ['c'=>..., 'previous_close'=>..., 'volume'=>...], ... ]（取得できた銘柄のみ）
//   ※ 公式は「大量かつ頻繁な株価取得」の自粛を依頼している。探索表示では下のキャッシュ付き関数を使うこと
// --------------------------------------------------
function wp_stocks_tachibana_get_prices(array $codes) {
    $codes = array_values(array_unique(array_filter(array_map('wp_stocks_tachibana_normalize_code', $codes))));
    $out   = [];

    foreach (array_chunk($codes, 50) as $chunk) { // 1リクエストの銘柄数上限は要確認（保守的に50）
        $data = wp_stocks_tachibana_request('price', [
            'sCLMID'           => 'CLMMfdsGetMarketPrice',
            'sTargetIssueCode' => implode(',', $chunk),
            'sTargetColumn'    => 'pDPP,tDPP:T,pPRP,pDV,pDOP,pDHP,pDLP', // 現在値, 現在値時刻, 前日終値, 出来高, 始値, 高値, 安値
        ]);
        if (is_wp_error($data)) {
            wp_stocks_tachibana_log('error', implode(',', array_slice($chunk, 0, 3)), '時価取得失敗: ' . $data->get_error_message());
            continue;
        }
        foreach (wp_stocks_tachibana_find_rows($data, 'sIssueCode') as $row) {
            $c = wp_stocks_tachibana_num($row['pDPP'] ?? null);
            if ($c === null || $c <= 0) continue;
            $v = wp_stocks_tachibana_num($row['pDV'] ?? null);
            $out[(string)$row['sIssueCode']] = [
                'c'              => $c,
                'previous_close' => wp_stocks_tachibana_num($row['pPRP'] ?? null),
                'volume'         => $v !== null ? intval($v) : null,
                'o'              => wp_stocks_tachibana_num($row['pDOP'] ?? null),
                'h'              => wp_stocks_tachibana_num($row['pDHP'] ?? null),
                'l'              => wp_stocks_tachibana_num($row['pDLP'] ?? null),
            ];
        }
    }
    return $out;
}

// --------------------------------------------------
// 1銘柄の現在値（Yahoo版 wp_stocks_get_price と同じ戻り値）。60秒キャッシュ
//   ※ 場中に呼ぶと「その時点の現在値」。日足(stock_prices)として保存する用途は16時以降のcronだけにする
// --------------------------------------------------
function wp_stocks_tachibana_get_price($symbol) {
    $code = wp_stocks_tachibana_normalize_code($symbol);
    if ($code === '') return false;

    $ck     = 'wp_stocks_tcb_px_' . md5($code);
    $cached = get_transient($ck);
    if ($cached !== false) return $cached;

    $map = wp_stocks_tachibana_get_prices([$code]);
    if (!isset($map[$code])) return false;

    set_transient($ck, $map[$code], 60);
    return $map[$code];
}

// --------------------------------------------------
// 複数銘柄の現在値をまとめて取得し、get_price と同じキャッシュへ入れる（cron用）
//   戻り値: キャッシュに入れた銘柄数。取れなかった銘柄は、後続の get_price が個別に取得／Yahooへ
// --------------------------------------------------
function wp_stocks_tachibana_prefetch_prices(array $symbols, $ttl = 300) {
    $codes = [];
    foreach ($symbols as $s) {
        if (wp_stocks_tachibana_is_jp_symbol($s)) $codes[] = wp_stocks_tachibana_normalize_code($s);
    }
    $map = wp_stocks_tachibana_get_prices($codes);
    foreach ($map as $code => $row) {
        set_transient('wp_stocks_tcb_px_' . md5($code), $row, $ttl);
    }
    wp_stocks_tachibana_store_today_bars($map);
    return count($map);
}

// --------------------------------------------------
// 日足（CLMMfdsGetMarketPriceHistory）→ Yahoo版 wp_stocks_get_ohlcv と同じ形式
//   - 1要求1銘柄、期間指定なし（全期間が返る）→ 末尾 $count 本だけ使う
//   - 株式分割換算後の値（xK付き）を優先（Yahooのchartも分割調整済みのため）
//   - 日足の最新値は取引終了後 18:00〜翌03:30 に更新される → 当日分が必要なら18時以降に取得
// --------------------------------------------------
function wp_stocks_tachibana_fetch_daily_bars($symbol, $count = 130) {
    $code = wp_stocks_tachibana_normalize_code($symbol);
    if ($code === '') return false;

    $data = wp_stocks_tachibana_request('price', [ // 要確認: 履歴も sUrlPrice で受け付けるか
        'sCLMID'     => 'CLMMfdsGetMarketPriceHistory',
        'sIssueCode' => $code,
        'sSizyouC'   => '00', // 東証
    ]);
    if (is_wp_error($data)) {
        wp_stocks_tachibana_log('error', $code, '日足取得失敗: ' . $data->get_error_message());
        return false;
    }

    $rows = wp_stocks_tachibana_find_rows($data, 'sDate');
    if (!$rows) {
        wp_stocks_tachibana_log('error', $code, '日足の応答に sDate を持つリストがありません');
        return false;
    }

    $bars = [];
    foreach ($rows as $r) {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', (string)($r['sDate'] ?? ''), $m)) continue;

        $close = wp_stocks_tachibana_num($r['pDPPxK'] ?? null) ?? wp_stocks_tachibana_num($r['pDPP'] ?? null);
        if ($close === null) continue;

        $open = wp_stocks_tachibana_num($r['pDOPxK'] ?? null) ?? wp_stocks_tachibana_num($r['pDOP'] ?? null) ?? $close;
        $high = wp_stocks_tachibana_num($r['pDHPxK'] ?? null) ?? wp_stocks_tachibana_num($r['pDHP'] ?? null) ?? $close;
        $low  = wp_stocks_tachibana_num($r['pDLPxK'] ?? null) ?? wp_stocks_tachibana_num($r['pDLP'] ?? null) ?? $close;
        $vol  = wp_stocks_tachibana_num($r['pDVxK'] ?? null)  ?? wp_stocks_tachibana_num($r['pDV'] ?? null)  ?? 0;

        $bars[] = [
            'date'   => "{$m[1]}-{$m[2]}-{$m[3]}",
            'open'   => $open,
            'close'  => $close,
            'high'   => $high,
            'low'    => $low,
            'volume' => intval($vol),
        ];
    }
    if (!$bars) return false;

    usort($bars, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    $bars = wp_stocks_tachibana_append_today_bar($code, $bars);
    if ($count > 0 && count($bars) > $count) $bars = array_slice($bars, -$count);
    return $bars;
}

// --------------------------------------------------
// 動作確認用: 生の応答の構造を返す（仮想URLは含まれない）
// --------------------------------------------------
function wp_stocks_tachibana_debug($symbol = '7203') {
    $code = wp_stocks_tachibana_normalize_code($symbol);
    $out  = ['code' => $code];

    $px = wp_stocks_tachibana_request('price', [
        'sCLMID'           => 'CLMMfdsGetMarketPrice',
        'sTargetIssueCode' => $code,
        'sTargetColumn'    => 'pDPP,tDPP:T,pPRP,pDV',
    ]);
    $out['price_raw'] = is_wp_error($px) ? 'ERROR: ' . $px->get_error_message() : $px;

    $hist = wp_stocks_tachibana_request('price', [
        'sCLMID'     => 'CLMMfdsGetMarketPriceHistory',
        'sIssueCode' => $code,
        'sSizyouC'   => '00',
    ]);
    if (is_wp_error($hist)) {
        $out['history_raw'] = 'ERROR: ' . $hist->get_error_message();
    } else {
        $rows = wp_stocks_tachibana_find_rows($hist, 'sDate');
        $out['history_top_level_keys'] = array_keys($hist);
        $out['history_rows']           = count($rows);
        $out['history_first']          = $rows[0] ?? null;
        $out['history_last']           = $rows ? $rows[count($rows) - 1] : null;
    }

    $out['mapped_price'] = wp_stocks_tachibana_get_price($code);
    $bars = wp_stocks_tachibana_fetch_daily_bars($code, 5);
    $out['mapped_bars_last5'] = $bars;
    return $out;
}

// --------------------------------------------------
// 当日分の日足が取得できる状態か（日足は取引終了後に更新されるため、cronの実行可否の判定に使う）
//   true : 当日分あり、または今日は取引日でない（待つ必要なし）
//   false: 取引日なのに当日分が未反映（待つべき）
//   null : 判定不能（APIエラー・セッション失効など → 待たずに従来どおり進める）
// --------------------------------------------------
function wp_stocks_tachibana_daily_ready($probe = '7203') {
    $now  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    $wday = (int)$now->format('w');
    if ($wday === 0 || $wday === 6) return true;
    if (function_exists('wp_stocks_get_holidays')
        && in_array($now->format('Y-m-d'), wp_stocks_get_holidays(), true)) {
        return true;
    }

    // 当日バーを時価から組み立て済みなら、履歴の更新を待たずに進める
    $tcb_today = get_transient('wp_stocks_tcb_today_bars');
    if (is_array($tcb_today) && ($tcb_today['date'] ?? '') === $now->format('Y-m-d') && !empty($tcb_today['bars'])) return true;
    $bars = wp_stocks_tachibana_fetch_daily_bars($probe . '.T', 3);
    if (!$bars) return null;
    $last = end($bars);
    return $last['date'] >= $now->format('Y-m-d');
}


// --------------------------------------------------
// 当日バー（時価から組み立て）の保存
//   立花証券の日足履歴は当日分の反映が引け後18:00〜翌03:30のどこかで、日によって前後する。
//   待たずに計算できるよう、引け後に取得した時価の 始値・高値・安値・現在値・出来高 を
//   当日バーとして保存しておく（履歴に当日分が入ったら履歴を優先する）。
//   取引日の15:45以降に取得した確定値だけを使う（場中の途中経過のバーは作らない）。
//   $map: wp_stocks_tachibana_get_prices() の戻り値
// --------------------------------------------------
function wp_stocks_tachibana_store_today_bars(array $map) {
    $now  = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
    $wday = (int) $now->format('w');
    if ($wday === 0 || $wday === 6) return;
    if (function_exists('wp_stocks_get_holidays')
        && in_array($now->format('Y-m-d'), wp_stocks_get_holidays(), true)) {
        return;
    }
    if ((int) $now->format('Hi') < 1545) return;

    $date = $now->format('Y-m-d');
    $bars = [];
    foreach ($map as $code => $row) {
        $o = $row['o'] ?? null;
        $h = $row['h'] ?? null;
        $l = $row['l'] ?? null;
        $c = $row['c'] ?? null;
        $v = $row['volume'] ?? null;
        if ($o === null || $h === null || $l === null || $c === null || $v === null) continue;
        if ($o <= 0 || $h <= 0 || $l <= 0 || $c <= 0) continue;
        $bars[(string) $code] = [
            'open'   => $o,
            'high'   => $h,
            'low'    => $l,
            'close'  => $c,
            'volume' => intval($v),
        ];
    }
    if (!$bars) return;

    // 同じ日の保存分とは銘柄単位でマージ（一部の銘柄だけ取れた回で消さない）
    $cur = get_transient('wp_stocks_tcb_today_bars');
    if (is_array($cur) && ($cur['date'] ?? '') === $date && !empty($cur['bars']) && is_array($cur['bars'])) {
        $bars = array_merge($cur['bars'], $bars);
    }
    set_transient('wp_stocks_tcb_today_bars', ['date' => $date, 'bars' => $bars], 20 * HOUR_IN_SECONDS);
}

// --------------------------------------------------
// 日足配列（日付昇順）に当日バーを足す
//   - 履歴の最終日が保存日以降なら何もしない（履歴を優先）
//   - 前営業日のバーと完全に同じ値なら、取引がなかった（休場など）とみなして足さない
//   - 株式分割があった日は、時価が分割調整前の値のため履歴と合わないが、
//     翌日以降は履歴（調整済み）に置き換わる
// --------------------------------------------------
function wp_stocks_tachibana_append_today_bar($code, array $bars) {
    if (!$bars) return $bars;
    $store = get_transient('wp_stocks_tcb_today_bars');
    if (!is_array($store) || empty($store['date']) || empty($store['bars'][$code])) return $bars;

    $last = end($bars);
    if ($last['date'] >= $store['date']) return $bars;

    $q = $store['bars'][$code];
    if ($last['open'] == $q['open'] && $last['high'] == $q['high'] && $last['low'] == $q['low']
        && $last['close'] == $q['close'] && $last['volume'] == $q['volume']) {
        return $bars;
    }

    $bars[] = [
        'date'   => $store['date'],
        'open'   => $q['open'],
        'close'  => $q['close'],
        'high'   => $q['high'],
        'low'    => $q['low'],
        'volume' => intval($q['volume']),
    ];
    return $bars;
}


// ----------------------------------------------------------------
// ニュース（確認用）: 保存せず、取得・デコード結果を返すだけ
//   - 見出し: CLMMfdsGetNewsHead（sUrlMaster）。カテゴリ $category は p_CG（100/110/120/129、空なら全件）
//   - 本文  : CLMMfdsGetNewsBody（p_ID を1件だけ指定）
//   - p_HDL / p_TX は base64 → URLデコード → Shift_JIS(またはUTF-8) の順で戻す
// ----------------------------------------------------------------
function wp_stocks_tachibana_news_decode($s) {
    if (!is_string($s) || $s === '') return '';
    $raw = base64_decode($s, true);
    if ($raw === false) return '';
    // base64 → URLデコード（Shift_JISのバイト列）→ UTF-8
    return mb_convert_encoding(urldecode($raw), 'UTF-8', 'SJIS-win');
}

function wp_stocks_tachibana_news_debug($date = '', $show = 10) {
    // 確認用: このプロセス内だけ HTTP タイムアウトを延ばす
    add_filter('http_request_args', function ($args) {
        $args['timeout'] = 120;
        return $args;
    });

    if ($date === '') {
        $date = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Ymd');
    }

    $res = wp_stocks_tachibana_request('master', [
        'sCLMID' => 'CLMMfdsGetNews',
        'p_DT'   => (string)$date,
    ]);
    if (is_wp_error($res)) {
        return ['error' => $res->get_error_message()];
    }

    $rows = $res['aCLMMfdsNews'] ?? [];
    $out  = [
        'date'           => $date,
        'top_level_keys' => array_keys($res),
        'rows'           => count($rows),
        'first_row_keys' => $rows ? array_keys($rows[0]) : [],
    ];

    $cats = [];
    $gens = [];
    $sum  = 0;
    $max  = 0;
    $list = [];
    foreach ($rows as $i => $r) {
        $cg = (string)($r['p_CGL'] ?? '');
        $gn = (string)($r['p_GNL'] ?? '');
        $cats[$cg] = ($cats[$cg] ?? 0) + 1;
        $gens[$gn] = ($gens[$gn] ?? 0) + 1;

        $text = wp_stocks_tachibana_news_decode((string)($r['p_TX'] ?? ''));
        $len  = mb_strlen($text);
        $sum += $len;
        if ($len > $max) $max = $len;

        if ($i < $show) {
            $list[] = [
                'p_ID'      => $r['p_ID'] ?? '',
                'p_TM'      => $r['p_TM'] ?? '',
                'p_CGL'     => $cg,
                'p_GNL'     => $gn,
                'p_ISL'     => $r['p_ISL'] ?? '',
                'headline'  => wp_stocks_tachibana_news_decode((string)($r['p_HDL'] ?? '')),
                'text_len'  => $len,
                'text_head' => mb_substr($text, 0, 150),
            ];
        }
    }
    $out['count_by_p_CGL']      = $cats;
    $out['count_by_p_GNL']      = $gens;
    $out['text_chars_total']    = $sum;
    $out['text_chars_max']      = $max;
    $out['first_rows_decoded']  = $list;
    return $out;
}


// ================================================================
// ニュース保存（CLMMfdsGetNews）
//   - p_DT（YYYYMMDD）で1日分の見出し＋本文をまとめて取得し、DBに保存する
//   - 保持は直近N営業日（当日を含む・土日を除く、初期値7）。取得のたびに古い分を削除
//   - 21:00 に当日分、翌朝 07:30 に前日分（夜間配信の取りこぼし補完）を取得
//   - 重複は p_ID（news_id）で排除
// ================================================================
function wp_stocks_tachibana_news_table() {
    global $wpdb;
    return $wpdb->prefix . 'stock_tachibana_news';
}

function wp_stocks_tachibana_news_ensure_table() {
    global $wpdb;
    $ver = '1';
    if (get_option('wp_stocks_tcn_db_version') === $ver) return true;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table   = wp_stocks_tachibana_news_table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  news_id VARCHAR(64) NOT NULL,
  news_date DATE NOT NULL,
  news_at DATETIME NOT NULL,
  category VARCHAR(8) NOT NULL DEFAULT '',
  genre TEXT NULL,
  issues TEXT NULL,
  headline TEXT NOT NULL,
  body MEDIUMTEXT NULL,
  fetched_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY news_id (news_id),
  KEY news_date (news_date),
  KEY news_at (news_at)
) {$charset};";
    dbDelta($sql);

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        wp_stocks_tachibana_log('error', 'news', 'ニュース用テーブルの作成に失敗');
        return false;
    }
    update_option('wp_stocks_tcn_db_version', $ver);
    return true;
}

// 1日分を取得して保存する。戻り値: 件数などの配列（error が空なら成功）
function wp_stocks_tachibana_news_fetch_day($ymd) {
    global $wpdb;
    $result = ['date' => (string)$ymd, 'rows' => 0, 'inserted' => 0, 'skipped' => 0, 'failed' => 0, 'error' => ''];

    if (!preg_match('/^\d{8}$/', (string)$ymd)) {
        $result['error'] = '日付の形式が不正です（YYYYMMDD）';
        return $result;
    }
    if (!wp_stocks_tachibana_news_ensure_table()) {
        $result['error'] = 'テーブルがありません';
        return $result;
    }

    // 1日分は数MBになるため、メモリと実行時間に余裕を持たせる
    if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
    @set_time_limit(300);

    $timeout = function ($args) {
        $args['timeout'] = 120;
        return $args;
    };
    add_filter('http_request_args', $timeout);
    $res = wp_stocks_tachibana_request('master', [
        'sCLMID' => 'CLMMfdsGetNews',
        'p_DT'   => (string)$ymd,
    ]);
    remove_filter('http_request_args', $timeout);

    if (is_wp_error($res)) {
        $result['error'] = $res->get_error_message();
        wp_stocks_tachibana_log('error', 'news', 'ニュース取得失敗(' . $ymd . '): ' . $result['error']);
        return $result;
    }

    if (!is_array($res)) $res = [];
    $rows = $res['aCLMMfdsNews'] ?? [];
    if (!is_array($rows)) $rows = [];
    $api_code  = trim((string)($res['sResultCode'] ?? ''));
    $api_errno = trim((string)($res['p_errno'] ?? ''));
    $api_text  = trim((string)($res['sResultText'] ?? ($res['p_err'] ?? '')));
    $api_keys  = implode(',', array_slice(array_keys($res), 0, 8));
    unset($res);
    $result['rows'] = count($rows);

    // 0件のときは応答の中身を残す（APIエラーの見落とし防止）
    if ($result['rows'] === 0) {
        $api_ng = ($api_code !== '' && $api_code !== '0') || ($api_errno !== '' && $api_errno !== '0');
        $detail = sprintf('応答: sResultCode=%s p_errno=%s text=%s keys=%s', $api_code, $api_errno, $api_text, $api_keys);
        if ($api_ng) {
            $result['error'] = $detail;
            wp_stocks_tachibana_log('error', 'news', 'ニュース ' . $ymd . ': 0件・APIエラー応答 ' . $detail);
        } else {
            wp_stocks_tachibana_log('info', 'news', 'ニュース ' . $ymd . ': 0件（' . $detail . '）');
        }
        return $result;
    }

    $table    = wp_stocks_tachibana_news_table();
    $date_sql = substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
    $existing = array_flip($wpdb->get_col($wpdb->prepare("SELECT news_id FROM {$table} WHERE news_date = %s", $date_sql)));
    $now      = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

    foreach ($rows as $r) {
        $id = trim((string)($r['p_ID'] ?? ''));
        if ($id === '') {
            $result['failed']++;
            continue;
        }
        if (isset($existing[$id])) {
            $result['skipped']++;
            continue;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $id, $m)) {
            $news_at = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        } else {
            $tm      = str_pad((string)($r['p_TM'] ?? '0000'), 4, '0', STR_PAD_LEFT);
            $news_at = $date_sql . ' ' . substr($tm, 0, 2) . ':' . substr($tm, 2, 2) . ':00';
        }

        // 銘柄コードは '|7203|6758|' の形で保存（LIKE '%|7203|%' で検索できる）
        $issues = trim((string)($r['p_ISL'] ?? ''));

        $ok = $wpdb->insert($table, [
            'news_id'    => $id,
            'news_date'  => substr($news_at, 0, 10),
            'news_at'    => $news_at,
            'category'   => (string)($r['p_CGL'] ?? ''),
            'genre'      => (string)($r['p_GNL'] ?? ''),
            'issues'     => $issues !== '' ? '|' . $issues . '|' : '',
            'headline'   => trim(wp_stocks_tachibana_news_decode((string)($r['p_HDL'] ?? ''))),
            'body'       => wp_stocks_tachibana_news_decode((string)($r['p_TX'] ?? '')),
            'fetched_at' => $now,
        ]);

        if ($ok === false) {
            if (stripos((string)$wpdb->last_error, 'Duplicate') !== false) {
                $result['skipped']++;
            } else {
                $result['failed']++;
                if ($result['error'] === '') $result['error'] = 'INSERT失敗: ' . $wpdb->last_error;
            }
        } else {
            $result['inserted']++;
            $existing[$id] = true;
        }
    }
    unset($rows);

    wp_stocks_tachibana_log(
        $result['failed'] > 0 ? 'error' : 'info',
        'news',
        sprintf('ニュース %s: 取得%d件 追加%d件 既存%d件 失敗%d件', $ymd, $result['rows'], $result['inserted'], $result['skipped'], $result['failed'])
    );
    return $result;
}

// 保持の起点日（この日以降を残す）。直近N営業日（当日を含む・土日を除く）。祝日は営業日として数える
function wp_stocks_tachibana_news_cutoff_date($bdays = null) {
    if ($bdays === null) $bdays = (int)get_option('wp_stocks_tcn_retention_bdays', 7);
    $bdays = max(1, (int)$bdays);

    $d = new DateTime('today', new DateTimeZone('Asia/Tokyo'));
    $n = 0;
    while (true) {
        if ((int)$d->format('N') < 6) {
            $n++;
            if ($n >= $bdays) break;
        }
        $d->modify('-1 day');
    }
    return $d->format('Y-m-d');
}

function wp_stocks_tachibana_news_cleanup() {
    global $wpdb;
    if (!wp_stocks_tachibana_news_ensure_table()) return ['cutoff' => '', 'deleted' => 0];

    $table  = wp_stocks_tachibana_news_table();
    $cutoff = wp_stocks_tachibana_news_cutoff_date();
    $n      = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE news_date < %s", $cutoff));
    if ($n === false) {
        wp_stocks_tachibana_log('error', 'news', 'ニュース削除失敗: ' . $wpdb->last_error);
        $n = 0;
    }
    return ['cutoff' => $cutoff, 'deleted' => (int)$n];
}

// 定時実行: 'today' = 当日分、'catchup' = 直近N営業日分の取り直し（夜間配信の補完）。取得後に古い分を削除
function wp_stocks_tachibana_news_run($which) {
    if (get_transient('wp_stocks_tcn_lock')) return null;
    set_transient('wp_stocks_tcn_lock', 1, 10 * MINUTE_IN_SECONDS);

    $tz = new DateTimeZone('Asia/Tokyo');
    if ($which === 'catchup') {
        // 今日より前の直近N営業日（土日除く）を取り直す。既存行はスキップされる
        $n = max(1, min(5, (int)get_option('wp_stocks_tcn_catchup_bdays', 2)));
        $d = new DateTime('today', $tz);
        $r = [];
        for ($i = 0; $i < 14 && count($r) < $n; $i++) {
            $d->modify('-1 day');
            if ((int)$d->format('N') >= 6) continue;
            if (count($r) > 0) sleep(2);
            $r[] = wp_stocks_tachibana_news_fetch_day($d->format('Ymd'));
        }
    } else {
        $d = new DateTime('now', $tz);
        $r = wp_stocks_tachibana_news_fetch_day($d->format('Ymd'));
    }
    wp_stocks_tachibana_news_cleanup();

    delete_transient('wp_stocks_tcn_lock');
    return $r;
}

// 手動用: 直近N営業日分をまとめて取得（初回の埋め用）。1日ずつ順に取得し、間に待機を入れる
function wp_stocks_tachibana_news_backfill($bdays = 7) {
    $d   = new DateTime('today', new DateTimeZone('Asia/Tokyo'));
    $out = [];
    $n   = 0;
    $bdays = max(1, (int)$bdays);
    while (true) {
        if ((int)$d->format('N') < 6) {
            $out[] = wp_stocks_tachibana_news_fetch_day($d->format('Ymd'));
            $n++;
            if ($n >= $bdays) break;
            sleep(2);
        }
        $d->modify('-1 day');
    }
    $out['cleanup'] = wp_stocks_tachibana_news_cleanup();
    return $out;
}

add_action('wp_stocks_tcn_fetch_event', function () {
    wp_stocks_tachibana_news_run('today');
});
add_action('wp_stocks_tcn_catchup_event', function () {
    wp_stocks_tachibana_news_run('catchup');
});

// 設定変更後に呼ぶ: 登録済みのイベントを解除して、保存済みの時刻で登録し直す
function wp_stocks_tachibana_news_reschedule() {
    foreach (['wp_stocks_tcn_fetch_event', 'wp_stocks_tcn_catchup_event'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    wp_stocks_tachibana_news_schedule();
}

function wp_stocks_tachibana_news_schedule() {
    $tz     = new DateTimeZone('Asia/Tokyo');
    $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
    $fetch_hm   = (string)get_option('wp_stocks_tcn_fetch_time', '21:00');
    $catchup_hm = (string)get_option('wp_stocks_tcn_catchup_time', '07:30');
    if (!preg_match($re, $fetch_hm))   $fetch_hm   = '21:00';
    if (!preg_match($re, $catchup_hm)) $catchup_hm = '07:30';
    $events = ['wp_stocks_tcn_fetch_event' => $fetch_hm, 'wp_stocks_tcn_catchup_event' => $catchup_hm];
    foreach ($events as $hook => $hm) {
        if (wp_next_scheduled($hook)) continue;
        $t = new DateTime('today ' . $hm, $tz);
        if ($t->getTimestamp() <= time()) $t->modify('+1 day');
        wp_schedule_event($t->getTimestamp(), 'daily', $hook);
    }
}
if (did_action('init')) {
    wp_stocks_tachibana_news_schedule();
} else {
    add_action('init', 'wp_stocks_tachibana_news_schedule');
}

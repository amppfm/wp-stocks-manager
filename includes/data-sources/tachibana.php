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
            'sTargetColumn'    => 'pDPP,tDPP:T,pPRP,pDV', // 現在値, 現在値時刻, 前日終値, 出来高
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

#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
四季報の[]内テキストからのセクター自動抽出機能を削除するスクリプト。
J-Quants equities/master への一本化に伴い、以下の3箇所を削除する:
  1. wp-stocks-manager.php        : wp_stocks_extract_sector_from_shikiho() 関数定義
  2. admin/handlers/admin-post-handlers.php : admin_post_update_shikiho 内のsector書き換え
  3. admin/pages/settings.php     : 「四季報からセクターを一括再抽出」のボタン＋ハンドラ

プラグインのルートディレクトリ（wp-stocks-manager.php がある場所）で実行してください:
    python3 remove_shikiho_sector_extraction.py

各置換は「一致箇所がちょうど1つ」であることを確認してから実行する。
一致しない/複数一致する場合はその旨を表示して当該ファイルはスキップする
（他のファイルの処理は続行する）。
"""

import sys

def apply_removal(path, old_block, label, new_block=""):
    try:
        with open(path, "r", encoding="utf-8") as f:
            content = f.read()
    except FileNotFoundError:
        print(f"[SKIP] {path} が見つかりません（カレントディレクトリを確認してください）")
        return False

    count = content.count(old_block)
    if count == 0:
        print(f"[SKIP] {path}: 「{label}」に一致するブロックが見つかりませんでした（既に修正済み、または内容が想定と異なる可能性があります）")
        return False
    if count > 1:
        print(f"[SKIP] {path}: 「{label}」に一致するブロックが{count}箇所あり、一意に特定できません")
        return False

    new_content = content.replace(old_block, new_block)
    with open(path, "w", encoding="utf-8") as f:
        f.write(new_content)
    print(f"[OK]   {path}: 「{label}」を削除しました")
    return True


def main():
    changed_any = False

    # -------------------------------------------------------------
    # 1. wp-stocks-manager.php : wp_stocks_extract_sector_from_shikiho() 関数定義を削除
    # -------------------------------------------------------------
    old_1 = """// -----------------------------------------------------------------------
// 四季報本文の1行目「【...】」内の文字列をセクター名として抽出
// 例: 9519　レノバ　（東証プ　）；電気機器 ⇒ 電気機器
// -----------------------------------------------------------------------
function wp_stocks_extract_sector_from_shikiho($shikiho) {
    if (empty($shikiho)) return '';
    $lines      = preg_split('/\\r\\n|\\r|\\n/', $shikiho);
    $first_line = trim($lines[0] ?? '');
    if (preg_match('/[\\[\uff3b]\\s*([^\\]\uff3d]+?)\\s*[\\]\uff3d]/u', $first_line, $m)) {
        $sector = trim($m[1]);
        // 全角スペース等の除去
        $sector = preg_replace('/^[\\s\\x{3000}]+|[\\s\\x{3000}]+$/u', '', $sector);
        return $sector;
    }
    return '';
}

"""
    changed_any |= apply_removal(
        "wp-stocks-manager.php",
        old_1,
        "wp_stocks_extract_sector_from_shikiho() 関数定義",
        new_block="",
    )

    # -------------------------------------------------------------
    # 2. admin/handlers/admin-post-handlers.php : admin_post_update_shikiho 内のsector書き換えを削除
    # -------------------------------------------------------------
    old_2 = """    $sector_from_shikiho = wp_stocks_extract_sector_from_shikiho($shikiho);
    if (!empty($sector_from_shikiho)) {
        $update_data['sector'] = $sector_from_shikiho;
    }
    $wpdb->update($wpdb->prefix . 'stocks', $update_data, ['id' => $id]);"""
    new_2 = """    // 業種の自動判定はJ-Quants（equities/master）に一本化。四季報の[]内テキストからの抽出は廃止。
    $wpdb->update($wpdb->prefix . 'stocks', $update_data, ['id' => $id]);"""
    changed_any |= apply_removal(
        "admin/handlers/admin-post-handlers.php",
        old_2,
        "update_shikiho ハンドラ内のsector自動抽出ロジック",
        new_block=new_2,
    )

    # -------------------------------------------------------------
    # 3. admin/pages/settings.php : 「四季報からセクターを一括再抽出」ボタン＋ハンドラを削除
    # -------------------------------------------------------------
    # 3a. ハンドラ本体（POST受け取りブロック）
    old_3a = """    // 四季報からセクターを一括再抽出
    if (isset($_POST['wp_stocks_bulk_resector_shikiho'])) {
        check_admin_referer('wp_stocks_settings_nonce');
        $shikiho_stocks = $wpdb->get_results(
            "SELECT id, code, name, shikiho, sector FROM {$wpdb->prefix}stocks WHERE shikiho IS NOT NULL AND shikiho != ''"
        );
        $resector_updated = 0;
        $resector_skipped = 0;
        $resector_samples = [];
        foreach ($shikiho_stocks as $rs) {
            $new_sector = wp_stocks_extract_sector_from_shikiho($rs->shikiho);
            if (!empty($new_sector)) {
                $wpdb->update($wpdb->prefix . 'stocks', ['sector' => $new_sector], ['id' => $rs->id]);
                if ($new_sector !== $rs->sector) {
                    $resector_samples[] = $rs->code . '(' . ($rs->sector ?: '未設定') . '→' . $new_sector . ')';
                }
                $resector_updated++;
            } else {
                $resector_skipped++;
            }
        }
        wp_stocks_log('info', 'bulk_resector_shikiho', 'ALL',
            "四季報から一括再抽出：更新{$resector_updated}件 / 抽出失敗{$resector_skipped}件"
            . (!empty($resector_samples) ? '　変更例：' . implode(', ', array_slice($resector_samples, 0, 10)) : '')
        );
        echo '<div class="updated"><p>四季報からセクターを一括更新しました：更新' . $resector_updated . '件 / 抽出失敗' . $resector_skipped . '件</p></div>';
    }
"""
    changed_any |= apply_removal(
        "admin/pages/settings.php",
        old_3a,
        "四季報一括再抽出ハンドラ本体",
        new_block="",
    )

    # 3b. ボタンUI
    old_3b = """    // 四季報からセクターを一括再抽出
    $shikiho_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}stocks WHERE shikiho IS NOT NULL AND shikiho != ''"));
    echo '<tr><th>セクター一括再抽出（四季報）</th><td>';
    echo '<p class="description">四季報情報が登録済みの全銘柄について（' . number_format($shikiho_count) . '件）にういて、';
    echo '四季報1行目の《...》内の文字列からセクターを再抽出し、上書き更新します。<br>';
    echo '英語セクターのままになっている旧登録銘柄の一括修正に使用してください。</p>';
    echo '<button type="submit" name="wp_stocks_bulk_resector_shikiho" class="button button-primary" onclick="return confirm(\\'四季報登録済みの' . $shikiho_count . '件について、セクターを再抽出して上書きしますか？\\');">四季報からセクターを一括再抽出</button>';
    echo '</td></tr>';
"""
    changed_any |= apply_removal(
        "admin/pages/settings.php",
        old_3b,
        "四季報一括再抽出ボタンUI",
        new_block="",
    )

    print()
    if changed_any:
        print("完了。php -l で各ファイルの構文チェックを行ってから、git add -A && git commit && git push してください。")
    else:
        print("どのファイルも変更されませんでした。上記のSKIP理由を確認してください。")
    return 0


if __name__ == "__main__":
    sys.exit(main())

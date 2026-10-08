#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
EDINET関連コード削除パッチ生成スクリプト

プラグインのルート（wp-stocks-manager.php のあるディレクトリ）で実行します:
    python3 make_remove_edinet_diff.py

NAS上の現ファイルを読み込み、削除後の内容との unified diff を
remove_edinet.diff として出力し、最後に git apply --check で検証します。
ファイルは一切書き換えません（適用は ./apply_patch.sh remove_edinet.diff）。
アンカーが1か所に特定できない場合は、何も出力せずに中断します。
"""
import difflib
import re
import subprocess
import sys

OUT = "remove_edinet.diff"
patch_parts = []


def read(path):
    with open(path, "rb") as f:
        return f.read().decode("utf-8")


def unified(path, old, new):
    old_lines = old.splitlines(keepends=True)
    new_lines = new.splitlines(keepends=True)
    to_file = "b/" + path if new_lines else "/dev/null"
    return "".join(
        difflib.unified_diff(old_lines, new_lines, "a/" + path, to_file, n=3)
    )


def sub_once(pattern, text, label, flags=re.DOTALL):
    found = re.findall(pattern, text, flags)
    if len(found) != 1:
        sys.exit("中断: [%s] のアンカーが %d か所見つかりました（1か所のみ期待）" % (label, len(found)))
    return re.sub(pattern, "", text, count=1, flags=flags)


# ---------------------------------------------------------------
# 1) wp-stocks-manager.php : require_once の削除
# ---------------------------------------------------------------
p = "wp-stocks-manager.php"
old = read(p)
new = sub_once(
    r"require_once WP_STOCKS_PLUGIN_DIR \. 'includes/data-sources/edinet\.php';\n",
    old, "main require_once")
patch_parts.append(unified(p, old, new))

# ---------------------------------------------------------------
# 2) includes/data-sources/edinet.php : ファイルごと削除
# ---------------------------------------------------------------
p = "includes/data-sources/edinet.php"
old = read(p)
patch_parts.append(unified(p, old, ""))

# ---------------------------------------------------------------
# 3) admin/handlers/admin-post-handlers.php : 4ハンドラーの削除
# ---------------------------------------------------------------
p = "admin/handlers/admin-post-handlers.php"
old = read(p)
new = old
for name in [
    "download_edinet_codelist",
    "upload_edinet_codelist",
    "diagnose_half_year",
    "fetch_half_year_financials",
]:
    pat = (r"add_action\('admin_post_%s', function\(\) \{\n"
           r"(?:(?!add_action\().)*?\n\}\);\n\n?") % re.escape(name)
    new = sub_once(pat, new, "handler " + name)
patch_parts.append(unified(p, old, new))

# ---------------------------------------------------------------
# 4) admin/pages/settings.php : コードリスト欄・APIキー欄・保存処理
# ---------------------------------------------------------------
p = "admin/pages/settings.php"
old = read(p)
new = old

# 4-1) 手動実行タブ（前半）のパネルごと（EDINETコードリストのみを含む）
new = sub_once(
    r"    // -+\n    // 【手動実行タブ・前半】[^\n]*\n    // -+\n"
    r".*?    echo '</div>'; // end panel: manual \(前半\)\n\n?",
    new, "settings: manual panel(前半)")

# 4-2) 外部API連携タブのEDINET APIキー入力欄
new = sub_once(
    r"    // EDINET APIキー設定\n.*?    echo '</td></tr>';\n\n?",
    new, "settings: api key row")

# 4-3) 保存処理
new = sub_once(
    r"        // EDINET APIキー保存\n[^\n]*\n[^\n]*\n\n?",
    new, "settings: api key save")
patch_parts.append(unified(p, old, new))

# ---------------------------------------------------------------
# 出力と検証
# ---------------------------------------------------------------
with open(OUT, "w", encoding="utf-8", newline="") as f:
    f.write("".join(patch_parts))
print("生成:", OUT)

r = subprocess.run(["git", "apply", "--check", OUT], capture_output=True, text=True)
if r.returncode == 0:
    print("git apply --check: OK")
    print("次: ./apply_patch.sh %s" % OUT)
else:
    print("git apply --check: NG")
    print(r.stderr)
    sys.exit(1)

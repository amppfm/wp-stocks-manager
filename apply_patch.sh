#!/bin/bash
# wp-stocks-manager.php パッチ適用スクリプト
# 使い方: ./apply_patch.sh パッチファイル名（.patch / .diff どちらも可）
#
# git apply でファイル/ディレクトリを新規作成すると所有者・パーミッションが
# 実行ユーザー（tomo）のものになり、WordPressから読み込めなくなる
# （500エラー/致命的エラー）ため、適用直後にパッチが触れた
# 全ファイル・全ディレクトリの所有者・パーミッションを
# WordPress:http / ディレクトリ755・ファイル644 に戻す。
#
# ※ファイル分割で新規ディレクトリ（例: includes/data-sources/）が
#   作られるケースに対応するため、対象を $TARGET 単体ではなく
#   パッチの numstat から動的に洗い出す。

set -e

if [ -z "$1" ]; then
    echo "使い方: $0 パッチファイル名.patch"
    exit 1
fi

PATCH_FILE="$1"

if [ ! -f "$PATCH_FILE" ]; then
    echo "エラー: $PATCH_FILE が見つかりません"
    exit 1
fi

echo "== 1. パッチ事前チェック =="
git apply --check "$PATCH_FILE"

# パッチが追加・変更するファイル一覧（新規ファイルも含む）
CHANGED_FILES=$(git apply --numstat "$PATCH_FILE" | awk '{print $3}')

echo "== 2. パッチ適用 =="
git apply "$PATCH_FILE"

echo "== 3. 所有者・パーミッションを復元 =="
# 触れられた全ファイルと、その親ディレクトリ（新規作成分も含む）を
# 重複を除きつつ復元する
declare -A SEEN_DIRS
for f in $CHANGED_FILES; do
    if [ -f "$f" ]; then
        sudo chown WordPress:http "$f"
        sudo chmod 644 "$f"
    fi

    dir=$(dirname "$f")
    while [ "$dir" != "." ] && [ "$dir" != "/" ] && [ -n "$dir" ]; do
        if [ -z "${SEEN_DIRS[$dir]}" ]; then
            if [ -d "$dir" ]; then
                sudo chown WordPress:http "$dir"
                # 777: プラグインルート自体が drwxrwxrwx のため、それに合わせる。
                # 755にするとオーナー(WordPress)以外の tomo が次回パッチで
                # 新規ファイルを書き込めなくなり git apply が失敗するため注意。
                sudo chmod 777 "$dir"
            fi
            SEEN_DIRS[$dir]=1
        fi
        dir=$(dirname "$dir")
    done
done

echo "== 4. 構文チェック =="
for f in $CHANGED_FILES; do
    case "$f" in
        *.php)
            if [ -f "$f" ]; then
                sudo php -l "$f"
            fi
            ;;
    esac
done

echo "== 5. 現在の状態 =="
for f in $CHANGED_FILES; do
    if [ -e "$f" ]; then
        ls -la "$f"
    fi
done

echo ""
echo "完了しました。問題なければ:"
echo "  git add -A && git commit -m \"...\" && git push"
echo "おかしければ:"
echo "  git checkout -- . && git clean -fd"

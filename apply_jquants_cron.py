import io

# ============================================================
# 1. includes/data-sources/jquants.php にsync関数を追加
# ============================================================
path1 = "includes/data-sources/jquants.php"
content1 = io.open(path1, encoding="utf-8").read()

marker1 = (
    "// -----------------------------------------------------------\n"
    "// テスト用エンドポイント（管理画面から手動実行、レスポンス構造確認用）\n"
    "// -----------------------------------------------------------\n"
)
assert content1.count(marker1) == 1, "jquants.php: 対象箇所が見つからないか複数あります"

new_function = (
    "// -----------------------------------------------------------\n"
    "// 週次cron用：1銘柄分をJ-Quantsから取得しjquants_*列へ保存\n"
    "// （manual_*列には一切触れない。手動入力を自動上書きしないための設計）\n"
    "// -----------------------------------------------------------\n"
    "function wp_stocks_jquants_sync_stock($stock_id, $code) {\n"
    "    global $wpdb;\n"
    "\n"
    "    $data = wp_stocks_jquants_get_fins_summary($code);\n"
    "    if (!$data) {\n"
    "        return false;\n"
    "    }\n"
    "\n"
    "    $wpdb->update(\n"
    "        $wpdb->prefix . 'stocks',\n"
    "        [\n"
    "            'jquants_eps'                      => $data['eps'],\n"
    "            'jquants_forecast_eps'             => $data['forecast_eps'],\n"
    "            'jquants_next_fy_forecast_eps'     => $data['next_fy_forecast_eps'],\n"
    "            'jquants_bps'                      => $data['bps'],\n"
    "            'jquants_equity_ratio'             => $data['equity_ratio'],\n"
    "            'jquants_roe'                      => $data['roe'],\n"
    "            'jquants_forecast_dividend_annual' => $data['forecast_dividend_annual'],\n"
    "            'jquants_updated_at'               => current_time('mysql'),\n"
    "        ],\n"
    "        ['id' => $stock_id]\n"
    "    );\n"
    "\n"
    "    return true;\n"
    "}\n"
    "\n"
)

content1 = content1.replace(marker1, new_function + marker1)
io.open(path1, "w", encoding="utf-8").write(content1)
print("jquants.php: OK")

# ============================================================
# 2. admin/handlers/cron-handlers.php にJ-Quants呼び出しを組み込み
# ============================================================
path2 = "admin/handlers/cron-handlers.php"
content2 = io.open(path2, encoding="utf-8").read()

old_a = "    $edgar_ok = $edgar_ng = 0;\n"
assert content2.count(old_a) == 1, "cron-handlers.php: カウンタ初期化行が見つからないか複数あります"
new_a = old_a + "    $jquants_ok = $jquants_ng = 0;\n"
content2 = content2.replace(old_a, new_a)

old_b = (
    "        if ($is_usd) {\n"
    "            $edgar_result = wp_stocks_fetch_quarterly_financials_edgar($s->id, $s->code);\n"
    "            if ($edgar_result) $edgar_ok++; else $edgar_ng++;\n"
    "        }\n"
)
assert content2.count(old_b) == 1, "cron-handlers.php: EDGAR呼び出しブロックが見つからないか複数あります"
new_b = (
    "        if ($is_usd) {\n"
    "            $edgar_result = wp_stocks_fetch_quarterly_financials_edgar($s->id, $s->code);\n"
    "            if ($edgar_result) $edgar_ok++; else $edgar_ng++;\n"
    "        } else {\n"
    "            // 日本株はついでにJ-Quantsの予想EPS等も週次で取得する\n"
    "            $jquants_result = wp_stocks_jquants_sync_stock($s->id, $s->code);\n"
    "            if ($jquants_result) $jquants_ok++; else $jquants_ng++;\n"
    "        }\n"
)
content2 = content2.replace(old_b, new_b)

old_c = (
    "    wp_stocks_log('info', 'company_cron', 'ALL', "
    "\"企業情報週次更新完了：成功{$ok}件 / 失敗{$ng}件"
    "（うち米国株EDGAR財務：成功{$edgar_ok}件 / 失敗{$edgar_ng}件）\");\n"
)
assert content2.count(old_c) == 1, "cron-handlers.php: ログ出力行が見つからないか複数あります"
new_c = (
    "    $msg = sprintf(\n"
    "        '企業情報週次更新完了：成功%d件 / 失敗%d件"
    "（うち米国株EDGAR財務：成功%d件 / 失敗%d件、日本株J-Quants：成功%d件 / 失敗%d件）',\n"
    "        $ok, $ng, $edgar_ok, $edgar_ng, $jquants_ok, $jquants_ng\n"
    "    );\n"
    "    wp_stocks_log('info', 'company_cron', 'ALL', $msg);\n"
)
content2 = content2.replace(old_c, new_c)

io.open(path2, "w", encoding="utf-8").write(content2)
print("cron-handlers.php: OK")

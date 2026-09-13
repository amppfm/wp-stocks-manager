#!/usr/bin/env python3
import sys
import json

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "symbol required"}))
        sys.exit(1)
    symbol = sys.argv[1]
    try:
        import yfinance as yf
        t = yf.Ticker(symbol)
        df = t.get_income_stmt(freq='quarterly')
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

    result = []
    if df is not None and not df.empty:
        revenue_row = None
        income_row = None
        for candidate in ['TotalRevenue', 'OperatingRevenue']:
            if candidate in df.index:
                revenue_row = candidate
                break
        for candidate in ['NetIncome', 'NetIncomeCommonStockholders', 'NetIncomeContinuousOperations']:
            if candidate in df.index:
                income_row = candidate
                break
        for col in df.columns:
            period_end = str(col.date()) if hasattr(col, 'date') else str(col)
            rev = df.loc[revenue_row, col] if revenue_row else None
            inc = df.loc[income_row, col] if income_row else None
            try:
                rev_val = int(rev) if rev is not None and str(rev) != 'nan' else None
            except Exception:
                rev_val = None
            try:
                inc_val = int(inc) if inc is not None and str(inc) != 'nan' else None
            except Exception:
                inc_val = None
            if rev_val is None and inc_val is None:
                continue
            result.append({"period_end": period_end, "revenue": rev_val, "net_income": inc_val})

    print(json.dumps(result))

if __name__ == '__main__':
    main()

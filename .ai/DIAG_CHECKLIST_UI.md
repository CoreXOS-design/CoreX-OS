# UI Not Loading / Values = 0 — Mandatory Checklist

Follow in order. Do not skip steps.

1) ROUTE
- Confirm route exists and points to correct controller@method
- Confirm middleware (auth/role/branch) doesn't block
- Confirm correct environment/base URL

2) CONTROLLER
- Confirm it returns the view you expect
- Confirm all variables used in Blade are passed
- Temporarily add logger()->info() or dd() for key variables if needed (remove after)

3) QUERY / DATA
- Confirm query filters match real data (branch_id, period, statuses, dates)
- Confirm status derivation rules
- Count rows returned; confirm sample entity exists

4) BLADE / VIEW
- Confirm variable names match controller keys exactly
- Confirm loops/conditionals render non-empty states
- Confirm no JS overwrites placeholders

5) CACHE
- Run: php artisan optimize:clear
- Run: php artisan view:clear
- Hard refresh browser

6) LOGS
- Check storage/logs/laravel.log around the failing request time
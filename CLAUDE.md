# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Running the App

No build process. Files are served directly by MAMP/XAMPP Apache.

- **Start:** Open MAMP, start Apache + MySQL
- **Access:** `http://localhost/trading-app/`
- **Database:** Import `schema.sql` into a MySQL database named `trading_journal` via phpMyAdmin (`http://localhost/phpmyadmin`)
- **Config:** Edit `config/db.php` for DB credentials and risk limits

## Architecture

TradeLog Pro is a server-rendered PHP + MySQL app with no framework or SPA. All state lives in MySQL; the only client-side state is the dark/light theme preference in `localStorage`.

**Request flow:**
1. Browser hits a PHP page (e.g., `pages/journal.php`)
2. Page `require_once`s `config/db.php` (which in turn loads `includes/risk_engine.php`)
3. POST actions are handled at the top of each page
4. Data is queried via PDO and rendered as HTML
5. `includes/header.php` and `includes/footer.php` wrap all pages

**Key files:**
- `config/db.php` — DB connection (PDO singleton via `getDB()`), helper functions, risk config constants. Entry point for all backend logic.
- `includes/risk_engine.php` — Risk management engine. Call `getRiskMetrics(DEFAULT_USER_ID)` to get all risk data.
- `includes/header.php` — Sidebar navigation, topbar, theme toggle
- `includes/trade_modal.php` — Reusable Bootstrap modal for add/edit trade (included in every page that needs it)
- `index.php` — Dashboard (root, not in pages/)

**Multi-variant structure:** `demo/` and `india/` are fully independent subtrees, each with their own `config/db.php`, `includes/`, `pages/`, and `assets/`. Changes to the main app's config do not affect them.

## Database

**Connection:** `getDB()` returns a PDO singleton. All queries use prepared statements.

**Core tables:**
- `trades` — Trade log. `trade_datetime` = close time; `open_time` = open time. `profit_loss` is raw P/L; net P/L = `profit_loss - brokerage + swap`.
- `transactions` — Deposits/withdrawals. Balance = `initial_balance + deposits - withdrawals + SUM(profit_loss)`.
- `risk_snapshots` — Daily/weekly baseline snapshots. Auto-created by `getRiskMetrics()` on first call each day/week.
- `users` — Single row (id=1, "Demo Trader"). No authentication; `DEFAULT_USER_ID = 1` is used everywhere.

**India/Demo tables:** Prefixed `india_` and `demo_` — isolated from main tables.

## Configuration Constants (`config/db.php`)

```php
define('DAILY_LOSS_LIMIT_PCT',  20.0);   // 20% max daily loss
define('WEEKLY_LOSS_LIMIT_PCT', 40.0);   // 40% max weekly loss
define('WARNING_THRESHOLD_PCT', 90.0);   // Alert at 90% limit consumed
define('PROFIT_LOCK_MODE', 2);           // 1=Aggressive, 2=Balanced, 3=Conservative
define('WEEKLY_DRAWDOWN_LIMIT', 6.0);    // Legacy — kept for sidebar UI only, not used by risk engine
```

## Helper Functions (`config/db.php`)

- `getCurrentBalance($userId)` — Equity = initial + deposits − withdrawals + P/L
- `formatPL($value)` — Returns `"+$120.00"` or `"-$50.00"`
- `formatUSD($value)` — Returns `"$1,200.00"`
- `getTotalBrokerage($userId, $from, $to)` — Sum of `brokerage` for period
- `getNetPLAfterCharges($userId, $from, $to)` — `SUM(profit_loss - brokerage + swap)`

## Risk Engine (`includes/risk_engine.php`)

Call `getRiskMetrics(DEFAULT_USER_ID)` — returns an array with:
- `current_equity`, `daily_loss_used`, `daily_loss_remaining`, `daily_loss_pct_used`
- `weekly_*` equivalents
- `breach_daily`, `breach_weekly` — boolean flags; if true, trading is halted
- `warning_daily`, `warning_weekly` — boolean flags (90% threshold)
- `profit_lock_active`, `dynamic_floor` — profit lock state

Snapshots are created with `INSERT IGNORE`, so calling `getRiskMetrics()` multiple times is safe.

## Frontend

- **Bootstrap 5.3.2**, **Chart.js 4.4.0**, **Font Awesome 6.5.0** — all via CDN
- **CSS variables** in `assets/css/style.css` power light/dark themes. Theme is set via `data-theme` attribute on `<html>` and stored in `localStorage` key `tl_theme`.
- `assets/js/app.js` — theme toggle, sidebar state, modal helpers (auto-fill date/time, live P/L preview)

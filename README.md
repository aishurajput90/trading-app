# TradeLog Pro — Trading Journal & Risk Management App
## XAMPP Setup Guide

---

## 📋 Requirements
- XAMPP (Apache + MySQL + PHP 8.0+)
- Browser (Chrome / Firefox / Edge)

---

## 🚀 Installation Steps

### Step 1 — Copy Project Files
Place the entire `trading-app` folder inside your XAMPP `htdocs` directory:

```
C:\xampp\htdocs\trading-app\        ← Windows
/Applications/XAMPP/htdocs/trading-app/  ← Mac
/opt/lampp/htdocs/trading-app/      ← Linux
```

### Step 2 — Start XAMPP Services
Open XAMPP Control Panel and click **Start** for:
- ✅ Apache
- ✅ MySQL

### Step 3 — Import the Database

1. Open your browser and go to: `http://localhost/phpmyadmin`
2. Click **"New"** in the left sidebar
3. Create a database named **`trading_journal`**
4. Click the database → click **"Import"** tab
5. Choose file → select `trading-app/schema.sql`
6. Click **"Go"** — tables + sample data will be created

### Step 4 — Open the App

Visit: **`http://localhost/trading-app/`**

You should see the Dashboard with sample data loaded. ✅

---

## 📁 Project Structure

```
trading-app/
├── index.php               ← Dashboard
├── schema.sql              ← Database setup (import this!)
├── config/
│   └── db.php              ← DB connection + helper functions
├── includes/
│   ├── header.php          ← Sidebar + Topbar + Dark Mode Toggle
│   ├── footer.php          ← Scripts
│   └── trade_modal.php     ← Reusable Add/Edit Trade Popup
├── pages/
│   ├── journal.php         ← Trade Journal (full CRUD)
│   ├── calendar.php        ← Monthly Calendar P/L view
│   ├── funds.php           ← Deposit / Withdraw Manager
│   └── reports.php         ← Weekly / Monthly / Yearly Analytics
└── assets/
    ├── css/style.css       ← All styles (Light + Dark mode)
    └── js/app.js           ← Theme toggle, charts, modal logic
```

---

## ✨ Feature Summary

### 🎯 Trade Entry Modal (Popup)
- Click **"Add Trade"** anywhere — a modal popup appears
- Fields: Date (auto-filled), Time (auto-filled), Symbol, Entry Price, Exit Price, Quantity, P/L, Notes
- Date + Time are combined into a single `DATETIME` field in the database
- **P/L Input format**: Enter `+200` for profit or `-50` for loss
- Preview shows formatted USD value in green/red instantly

### 💵 USD Currency Only
- All values displayed as `$100.00`, `+$200.00`, `-$50.00`
- No INR or other currencies anywhere

### 🌗 Dark / Light Mode
- Toggle in the top navbar (sun/moon icons)
- Default: **Light mode**
- Preference saved in `localStorage` — persists across reloads
- Full dark theme: dark backgrounds, light text, all charts update

### 📊 Dashboard
- Today's trades, P/L, Max Drawdown, Remaining Weekly Risk
- Weekly Risk Meter (6% drawdown rule with color-coded bar)
- Win/Loss doughnut chart
- 14-day P/L bar chart
- Recent trades list

### 📓 Trade Journal
- Full CRUD: Add, Edit, Delete trades
- Filter by symbol, date range
- Shows Date + Time for every trade
- P/L displayed green (+) / red (-)

### 📅 Calendar View
- Monthly grid with profit/loss per day
- Green = profit day, Red = loss day
- Month-to-month navigation

### 💰 Fund Manager
- Deposit / Withdraw with date and notes
- Running balance calculation
- Transaction history table

### 📈 Reports
- Weekly / Monthly / Yearly tabs
- Daily P/L bar chart
- Equity curve (cumulative)
- Symbol-by-symbol performance breakdown
- Stats: Best trade, Worst trade, Avg P/L, Win rate

---

## 🗄️ Database Schema

| Table         | Purpose                        |
|---------------|-------------------------------|
| `users`       | User account (demo: id=1)     |
| `trades`      | Trade log with `trade_datetime`|
| `transactions`| Deposits & withdrawals         |

### Key column: `trades.profit_loss`
- Stored as **signed FLOAT**
- `+750.00` = profit of $750
- `-300.00` = loss of $300

### Key column: `trades.trade_datetime`
- Type: `DATETIME` (e.g. `2026-04-07 09:30:00`)
- Date + Time entered separately in the form, combined in PHP

---

## 🔧 Configuration

Edit `config/db.php` to change settings:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // XAMPP default is empty
define('DB_NAME', 'trading_journal');
define('WEEKLY_DRAWDOWN_LIMIT', 6.0);  // 6% weekly rule
```

---

## 🎨 Customization Tips

**Change weekly drawdown limit:**
Edit `WEEKLY_DRAWDOWN_LIMIT` in `config/db.php`

**Change default theme:**
Edit `localStorage.getItem('tl_theme') || 'light'` in `header.php` to `'dark'`

**Change accent color:**
Edit `--accent: #2563eb;` in `assets/css/style.css`

---

## 🐛 Troubleshooting

| Problem | Solution |
|---------|----------|
| Blank page | Check Apache & MySQL are running in XAMPP |
| DB error | Import `schema.sql` via phpMyAdmin first |
| No styles | Check folder is named `trading-app` exactly |
| Dark mode not saving | Enable localStorage in browser settings |

---

*Built with PHP + MySQL + Bootstrap 5 + Chart.js*
# trading-app

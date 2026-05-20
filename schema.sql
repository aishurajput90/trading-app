-- ============================================================
-- Trading Journal & Risk Management App - MySQL Schema v2
-- Updated: trade_datetime (DATETIME), USD only, signed P/L
-- ============================================================

CREATE DATABASE IF NOT EXISTS trading_journal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE trading_journal;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    initial_balance DECIMAL(15,2) DEFAULT 10000.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Trades table (v2 - uses DATETIME)
CREATE TABLE IF NOT EXISTS trades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL DEFAULT 1,
    trade_datetime DATETIME NOT NULL COMMENT 'Closing time of trade',
    open_time DATETIME DEFAULT NULL COMMENT 'Opening time of trade (from broker CSV)',
    symbol VARCHAR(20) NOT NULL,
    entry_price DECIMAL(15,4) NOT NULL,
    exit_price DECIMAL(15,4) NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    profit_loss FLOAT NOT NULL COMMENT 'Signed float: positive=profit, negative=loss',
    brokerage DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'Commission/brokerage charge (always positive $)',
    swap DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'Overnight swap charge (signed)',
    trade_type ENUM('buy','sell','buy_limit','sell_limit') NOT NULL DEFAULT 'buy' COMMENT 'Direction of trade',
    close_reason VARCHAR(20) DEFAULT NULL COMMENT 'sl=stop loss, tp=take profit, user=manual, so=stop out',
    ticket VARCHAR(50) DEFAULT NULL COMMENT 'Broker ticket/order ID',
    import_source VARCHAR(50) DEFAULT NULL COMMENT 'csv_import or manual',
    notes TEXT,
    sl_amount  DECIMAL(10,2) DEFAULT NULL COMMENT 'Stop loss risk in USD',
    tp_amount  DECIMAL(10,2) DEFAULT NULL COMMENT 'Take profit target in USD',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Transactions table (deposits & withdrawals)
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL DEFAULT 1,
    type ENUM('deposit','withdraw','stop_out') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    note VARCHAR(255),
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- Sample Data
-- ============================================================

INSERT IGNORE INTO users (id, name, email, password, initial_balance) VALUES
(1, 'Demo Trader', 'demo@trading.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 50000.00);

-- Sample trades using DATETIME
INSERT INTO trades (user_id, trade_datetime, symbol, entry_price, exit_price, quantity, profit_loss, notes) VALUES
(1, DATE_SUB(NOW(), INTERVAL 28 DAY), 'BTC/USDT', 42000.00, 43500.00, 0.5, 750.00, 'Breakout trade'),
(1, DATE_SUB(NOW(), INTERVAL 27 DAY), 'AAPL', 182.50, 180.00, 10, -250.00, 'Support broke'),
(1, DATE_SUB(NOW(), INTERVAL 26 DAY), 'ETH/USDT', 2200.00, 2350.00, 3, 450.00, 'Momentum trade'),
(1, DATE_SUB(NOW(), INTERVAL 25 DAY), 'BTC/USDT', 44000.00, 43200.00, 0.3, -240.00, 'Failed reversal'),
(1, DATE_SUB(NOW(), INTERVAL 24 DAY), 'TSLA', 250.00, 245.00, 5, -500.00, 'Failed breakout'),
(1, DATE_SUB(NOW(), INTERVAL 21 DAY), 'BTC/USDT', 43000.00, 44500.00, 0.4, 600.00, 'Trend continuation'),
(1, DATE_SUB(NOW(), INTERVAL 20 DAY), 'NVDA', 890.00, 920.00, 2, 600.00, 'Bull flag'),
(1, DATE_SUB(NOW(), INTERVAL 19 DAY), 'SOL/USDT', 95.00, 102.00, 10, 70.00, 'Altcoin swing'),
(1, DATE_SUB(NOW(), INTERVAL 18 DAY), 'ETH/USDT', 2400.00, 2300.00, 2, -200.00, 'Reversal failed'),
(1, DATE_SUB(NOW(), INTERVAL 17 DAY), 'AAPL', 180.00, 188.00, 8, 700.00, 'Weekly pivot bounce'),
(1, DATE_SUB(NOW(), INTERVAL 14 DAY), 'BTC/USDT', 45000.00, 44200.00, 0.5, -400.00, 'Stop hit'),
(1, DATE_SUB(NOW(), INTERVAL 13 DAY), 'MSFT', 415.00, 425.00, 4, 400.00, 'Gap up play'),
(1, DATE_SUB(NOW(), INTERVAL 12 DAY), 'BTC/USDT', 44500.00, 45800.00, 0.6, 780.00, 'Range breakout'),
(1, DATE_SUB(NOW(), INTERVAL 11 DAY), 'ETH/USDT', 2350.00, 2280.00, 3, -210.00, 'False breakout'),
(1, DATE_SUB(NOW(), INTERVAL 10 DAY), 'SOL/USDT', 105.00, 112.00, 15, 105.00, 'Momentum'),
(1, DATE_SUB(NOW(), INTERVAL 7 DAY), 'BTC/USDT', 46000.00, 47200.00, 0.4, 480.00, 'Bull trend'),
(1, DATE_SUB(NOW(), INTERVAL 6 DAY), 'TSLA', 245.00, 260.00, 4, 600.00, 'Short scalp profit'),
(1, DATE_SUB(NOW(), INTERVAL 5 DAY), 'NVDA', 920.00, 905.00, 2, -300.00, 'Trend reversal'),
(1, DATE_SUB(NOW(), INTERVAL 4 DAY), 'ETH/USDT', 2300.00, 2450.00, 4, 600.00, 'Breakout retest'),
(1, DATE_SUB(NOW(), INTERVAL 3 DAY), 'BTC/USDT', 47000.00, 47800.00, 0.5, 400.00, 'Continuation'),
(1, DATE_SUB(NOW(), INTERVAL 2 DAY), 'SOL/USDT', 115.00, 108.00, 10, -70.00, 'Short term short'),
(1, DATE_SUB(NOW(), INTERVAL 1 DAY), 'MSFT', 420.00, 432.00, 5, 600.00, 'Breakout trade'),
(1, DATE_FORMAT(NOW(), '%Y-%m-%d 09:30:00'), 'BTC/USDT', 48000.00, 48650.00, 0.3, 195.00, 'Morning scalp'),
(1, DATE_FORMAT(NOW(), '%Y-%m-%d 11:15:00'), 'ETH/USDT', 2450.00, 2390.00, 2, -120.00, 'Stop hit on news');

-- Sample transactions
INSERT INTO transactions (user_id, type, amount, note, date) VALUES
(1, 'deposit', 50000.00, 'Initial capital', DATE_SUB(CURDATE(), INTERVAL 30 DAY)),
(1, 'deposit', 10000.00, 'Added funds', DATE_SUB(CURDATE(), INTERVAL 15 DAY)),
(1, 'withdraw', 5000.00, 'Monthly withdrawal', DATE_SUB(CURDATE(), INTERVAL 5 DAY));

-- ============================================================
-- Risk Management Extension (v3)
-- Adds daily/weekly snapshot tracking + profit lock state
-- ============================================================

-- Stores daily and weekly baseline snapshots for risk calculations
CREATE TABLE IF NOT EXISTS risk_snapshots (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL DEFAULT 1,
    snapshot_date   DATE NOT NULL,
    snapshot_type   ENUM('daily','weekly') NOT NULL,
    balance_at_open DECIMAL(15,2) NOT NULL COMMENT 'Balance at start of period',
    highest_equity  DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Intraday/intraweek peak equity seen',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_date_type (user_id, snapshot_date, snapshot_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Sample risk snapshot seed (uses Monday of this week for weekly, today for daily)
-- These are inserted by the PHP app on first load; schema seed is optional.

-- ============================================================
-- v4 Migration — run these if upgrading an existing database:
-- ============================================================
-- ALTER TABLE trades ADD COLUMN brokerage    DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER profit_loss;
-- ALTER TABLE trades ADD COLUMN swap         DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER brokerage;
-- ALTER TABLE trades ADD COLUMN trade_type   ENUM('buy','sell','buy_limit','sell_limit') NOT NULL DEFAULT 'buy' AFTER swap;
-- ALTER TABLE trades ADD COLUMN close_reason VARCHAR(20)   DEFAULT NULL AFTER trade_type;
-- ALTER TABLE trades ADD COLUMN ticket       VARCHAR(50)   DEFAULT NULL AFTER close_reason;
-- ALTER TABLE trades ADD COLUMN import_source VARCHAR(50)  DEFAULT NULL AFTER ticket;

-- ============================================================
-- India Market Panel — Tables
-- ============================================================
CREATE TABLE IF NOT EXISTS india_trades (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL DEFAULT 1,
    trade_date      DATE NOT NULL               COMMENT 'IST trade date',
    open_time       DATETIME DEFAULT NULL        COMMENT 'IST open time',
    close_time      DATETIME NOT NULL            COMMENT 'IST close time',
    instrument      VARCHAR(100) NOT NULL        COMMENT 'Full instrument name e.g. BANKNIFTY 22 MAY 48100 PUT',
    base_instrument VARCHAR(30)  NOT NULL        COMMENT 'NIFTY / BANKNIFTY / SENSEX etc.',
    trade_type      ENUM('BUY','SELL') NOT NULL  COMMENT 'Direction of entry',
    order_type      ENUM('INTRADAY','MARGIN','DELIVERY') NOT NULL DEFAULT 'INTRADAY',
    exchange        ENUM('NSE','BSE','MCX','NFO','BFO') NOT NULL DEFAULT 'NSE',
    segment         VARCHAR(30) DEFAULT 'Derivative',
    quantity        DECIMAL(15,4) NOT NULL,
    buy_price       DECIMAL(15,4) NOT NULL DEFAULT 0,
    sell_price      DECIMAL(15,4) NOT NULL DEFAULT 0,
    buy_value       DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT 'Total buy value in ₹',
    sell_value      DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT 'Total sell value in ₹',
    profit_loss     DECIMAL(15,2) NOT NULL            COMMENT 'sell_value - buy_value',
    brokerage       DECIMAL(10,2) NOT NULL DEFAULT 0  COMMENT 'Brokerage/commission ₹',
    net_pl          DECIMAL(15,2) NOT NULL DEFAULT 0  COMMENT 'profit_loss - brokerage',
    close_reason    VARCHAR(20) DEFAULT NULL           COMMENT 'tp/sl/manual/so',
    notes           TEXT DEFAULT NULL,
    import_source   VARCHAR(50) DEFAULT 'manual',
    ticket          VARCHAR(50) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, trade_date),
    INDEX idx_instrument (base_instrument),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- India risk snapshots (same structure, separate table)
CREATE TABLE IF NOT EXISTS india_risk_snapshots (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL DEFAULT 1,
    snapshot_date   DATE NOT NULL,
    snapshot_type   ENUM('daily','weekly') NOT NULL,
    balance_at_open DECIMAL(15,2) NOT NULL DEFAULT 0,
    highest_equity  DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_india_snap (user_id, snapshot_date, snapshot_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- India transactions (deposits/withdrawals in ₹)
CREATE TABLE IF NOT EXISTS india_transactions (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id  INT NOT NULL DEFAULT 1,
    type     ENUM('deposit','withdraw') NOT NULL,
    amount   DECIMAL(15,2) NOT NULL,
    note     VARCHAR(255),
    date     DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- Demo Trading Panel — Tables
-- ============================================================

-- Demo trades table (virtual money, USD)
CREATE TABLE IF NOT EXISTS demo_trades (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL DEFAULT 1,
    open_time       DATETIME NOT NULL               COMMENT 'When trade was opened',
    close_time      DATETIME DEFAULT NULL           COMMENT 'When trade was closed (NULL = still open)',
    symbol          VARCHAR(20) NOT NULL            COMMENT 'e.g. XAUUSD, EURUSD',
    trade_type      ENUM('buy','sell') NOT NULL,
    lots            DECIMAL(10,4) NOT NULL,
    entry_price     DECIMAL(15,5) NOT NULL,
    exit_price      DECIMAL(15,5) DEFAULT NULL,
    stop_loss       DECIMAL(15,5) DEFAULT NULL,
    take_profit     DECIMAL(15,5) DEFAULT NULL,
    profit_loss     DECIMAL(15,2) NOT NULL DEFAULT 0,
    brokerage       DECIMAL(10,2) NOT NULL DEFAULT 0,
    net_pl          DECIMAL(15,2) NOT NULL DEFAULT 0,
    close_reason    ENUM('tp','sl','manual','so') DEFAULT NULL,
    status          ENUM('open','closed') NOT NULL DEFAULT 'open',
    emotion_tag     VARCHAR(50) DEFAULT NULL        COMMENT 'greedy/fearful/disciplined/impulsive/patient',
    strategy_tag    VARCHAR(100) DEFAULT NULL       COMMENT 'e.g. breakout/reversal/scalp/swing',
    notes           TEXT DEFAULT NULL,
    lesson          TEXT DEFAULT NULL               COMMENT 'What I learned from this trade',
    real_trade_id   INT DEFAULT NULL               COMMENT 'Linked real trade for comparison',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_demo_user_date (user_id, open_time),
    INDEX idx_demo_status (user_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Demo configuration per user
CREATE TABLE IF NOT EXISTS demo_config (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL DEFAULT 1 UNIQUE,
    starting_balance    DECIMAL(15,2) NOT NULL DEFAULT 10000.00,
    risk_per_trade_pct  DECIMAL(5,2) NOT NULL DEFAULT 2.0   COMMENT '% of balance risked per trade',
    daily_loss_pct      DECIMAL(5,2) NOT NULL DEFAULT 5.0,
    weekly_loss_pct     DECIMAL(5,2) NOT NULL DEFAULT 10.0,
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Demo challenges / targets
CREATE TABLE IF NOT EXISTS demo_challenges (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL DEFAULT 1,
    title           VARCHAR(100) NOT NULL,
    description     TEXT DEFAULT NULL,
    target_pct      DECIMAL(8,2) NOT NULL         COMMENT 'Target profit %',
    max_loss_pct    DECIMAL(8,2) NOT NULL DEFAULT 5.0,
    min_win_rate    DECIMAL(5,2) DEFAULT NULL,
    min_trades      INT DEFAULT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    status          ENUM('active','passed','failed','pending') NOT NULL DEFAULT 'pending',
    result_pct      DECIMAL(8,2) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- Demo Trading Panel — Tables
-- ============================================================

-- Demo accounts (virtual trading accounts)
CREATE TABLE IF NOT EXISTS demo_accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL DEFAULT 1,
    name            VARCHAR(100) NOT NULL DEFAULT 'My Demo Account',
    starting_balance DECIMAL(15,2) NOT NULL DEFAULT 10000.00,
    current_balance  DECIMAL(15,2) NOT NULL DEFAULT 10000.00,
    currency        VARCHAR(10) NOT NULL DEFAULT 'USD',
    description     TEXT DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Demo trades (virtual trades for practice)
CREATE TABLE IF NOT EXISTS demo_trades (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    account_id      INT NOT NULL,
    user_id         INT NOT NULL DEFAULT 1,
    open_time       DATETIME NOT NULL,
    close_time      DATETIME DEFAULT NULL    COMMENT 'NULL = trade still open',
    symbol          VARCHAR(30) NOT NULL,
    trade_type      ENUM('buy','sell') NOT NULL,
    lots            DECIMAL(10,4) NOT NULL,
    entry_price     DECIMAL(15,5) NOT NULL,
    exit_price      DECIMAL(15,5) DEFAULT NULL,
    stop_loss       DECIMAL(15,5) DEFAULT NULL,
    take_profit     DECIMAL(15,5) DEFAULT NULL,
    profit_loss     DECIMAL(15,2) DEFAULT NULL COMMENT 'NULL until closed',
    commission      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    swap            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_pl          DECIMAL(15,2) DEFAULT NULL,
    status          ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
    close_reason    ENUM('tp','sl','manual','so') DEFAULT NULL,
    strategy        VARCHAR(100) DEFAULT NULL COMMENT 'Strategy used for this trade',
    setup           VARCHAR(100) DEFAULT NULL COMMENT 'Setup type: breakout, reversal, etc.',
    timeframe       VARCHAR(10) DEFAULT NULL  COMMENT '1M, 5M, 15M, 1H, 4H, 1D',
    confidence      TINYINT DEFAULT NULL      COMMENT '1-5 confidence before entry',
    emotion         VARCHAR(30) DEFAULT NULL  COMMENT 'calm, anxious, fomo, revenge etc.',
    notes           TEXT DEFAULT NULL,
    screenshot_note TEXT DEFAULT NULL         COMMENT 'Chart observation notes',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account (account_id),
    INDEX idx_user_date (user_id, open_time),
    FOREIGN KEY (account_id) REFERENCES demo_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Demo rules (personal trading rules)
CREATE TABLE IF NOT EXISTS demo_rules (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL DEFAULT 1,
    rule_text   VARCHAR(255) NOT NULL,
    category    ENUM('risk','entry','exit','mindset','strategy') NOT NULL DEFAULT 'risk',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Demo rule compliance (did user follow rules per trade)
CREATE TABLE IF NOT EXISTS demo_rule_checks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    trade_id    INT NOT NULL,
    rule_id     INT NOT NULL,
    followed    TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=followed, 0=broke',
    FOREIGN KEY (trade_id) REFERENCES demo_trades(id) ON DELETE CASCADE,
    FOREIGN KEY (rule_id) REFERENCES demo_rules(id) ON DELETE CASCADE
);

-- Seed: default demo account
INSERT IGNORE INTO demo_accounts (id, user_id, name, starting_balance, current_balance, currency, description)
VALUES (1, 1, 'Forex Practice Account', 10000.00, 10000.00, 'USD', 'Main demo account for forex trading practice');

-- Seed: default trading rules
INSERT IGNORE INTO demo_rules (user_id, rule_text, category, sort_order) VALUES
(1, 'Risk max 1-2% per trade', 'risk', 1),
(1, 'Always set Stop Loss before entering', 'risk', 2),
(1, 'Minimum 1:2 Risk:Reward ratio', 'risk', 3),
(1, 'Only trade confirmed setups (2+ confirmations)', 'entry', 4),
(1, 'No trading in first 15 minutes of session', 'entry', 5),
(1, 'Close all trades 30 mins before major news', 'exit', 6),
(1, 'No revenge trading after a loss', 'mindset', 7),
(1, 'Max 3 trades per day', 'mindset', 8),
(1, 'Stop trading after 2 consecutive losses', 'mindset', 9),
(1, 'Record every trade with notes', 'strategy', 10);

-- ============================================================
-- Coaching & Discipline System — Tables
-- ============================================================

-- Daily discipline calendar (discipline/psychology/risk scores + day mark)
CREATE TABLE IF NOT EXISTS discipline_calendar (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL DEFAULT 1,
    cal_date            DATE NOT NULL,
    day_mark            ENUM('green','yellow','red','stop','star') DEFAULT NULL
                        COMMENT 'green=rules followed, yellow=minor mistakes, red=major breaks, stop=revenge/breakdown, star=perfect',
    discipline_score    TINYINT UNSIGNED DEFAULT NULL COMMENT '0-10',
    psychology_score    TINYINT UNSIGNED DEFAULT NULL COMMENT '0-10',
    risk_score          TINYINT UNSIGNED DEFAULT NULL COMMENT '0-10',
    total_trades        TINYINT UNSIGNED DEFAULT 0,
    wins                TINYINT UNSIGNED DEFAULT 0,
    losses              TINYINT UNSIGNED DEFAULT 0,
    net_pl              DECIMAL(10,2) DEFAULT NULL,
    rule_breaks         TEXT DEFAULT NULL,
    emotional_mistakes  TEXT DEFAULT NULL,
    best_trade_note     TEXT DEFAULT NULL,
    worst_trade_note    TEXT DEFAULT NULL,
    what_went_well      TEXT DEFAULT NULL,
    what_to_improve     TEXT DEFAULT NULL,
    notes               TEXT DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_disc_user_date (user_id, cal_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Pre-trade daily checklist (7 questions before trading begins)
CREATE TABLE IF NOT EXISTS pre_trade_checklist (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL DEFAULT 1,
    checklist_date      DATE NOT NULL,
    slept_well          TINYINT(1) DEFAULT NULL   COMMENT '1=yes 0=no',
    emotionally_calm    TINYINT(1) DEFAULT NULL,
    trading_session     VARCHAR(50) DEFAULT NULL  COMMENT 'London/NY/Asian/Other',
    trading_plan        TEXT DEFAULT NULL,
    max_loss_today      DECIMAL(10,2) DEFAULT NULL,
    setups_waiting      TEXT DEFAULT NULL,
    patience_level      ENUM('patience','neutral','urgency') DEFAULT NULL,
    cleared_to_trade    TINYINT(1) DEFAULT 1      COMMENT '1=cleared, 0=not cleared',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pre_user_date (user_id, checklist_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- POV Accuracy System — Tables
-- ============================================================

CREATE TABLE IF NOT EXISTS pov_entries (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    symbol           VARCHAR(20) NOT NULL,
    session          ENUM('asian','london','new_york','london_ny_overlap','other') DEFAULT 'other',
    higher_timeframe ENUM('1M','1W','1D','4H','1H') DEFAULT '1D',
    lower_timeframe  ENUM('4H','1H','30M','15M','5M','1M') DEFAULT '15M',
    market_bias      ENUM('bullish','bearish','neutral') NOT NULL,
    entry_price      DECIMAL(12,5) DEFAULT NULL,
    stop_loss        DECIMAL(12,5) DEFAULT NULL,
    take_profit      DECIMAL(12,5) DEFAULT NULL,
    reasoning        TEXT DEFAULT NULL,
    confidence_level TINYINT UNSIGNED NOT NULL DEFAULT 50,
    higher_tf_bias   ENUM('bullish','bearish','neutral') DEFAULT NULL,
    trend_aligned    TINYINT(1) DEFAULT NULL,
    psychology_state ENUM('calm','fearful','overconfident','fomo','revenge','neutral') DEFAULT 'neutral',
    status           ENUM('pending','analyzed','expired') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS pov_outcomes (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    pov_id             INT NOT NULL,
    analyzed_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actual_direction   ENUM('bullish','bearish','neutral') NOT NULL,
    actual_move_points DECIMAL(10,2) DEFAULT NULL,
    max_move_against   DECIMAL(10,2) DEFAULT NULL,
    tp_hit             TINYINT(1) DEFAULT 0,
    sl_hit             TINYINT(1) DEFAULT 0,
    direction_score    TINYINT UNSIGNED DEFAULT 0,
    timing_score       TINYINT UNSIGNED DEFAULT 0,
    overall_pov_score  TINYINT UNSIGNED DEFAULT 0,
    trade_category     ENUM('good_analysis_good_exec','good_analysis_bad_exec',
                            'bad_analysis_good_exec','bad_analysis','emotional','random') DEFAULT 'random',
    notes              TEXT DEFAULT NULL,
    FOREIGN KEY (pov_id) REFERENCES pov_entries(id) ON DELETE CASCADE
);

-- ============================================================
-- Trading Discipline Challenge System — Tables
-- ============================================================

CREATE TABLE IF NOT EXISTS challenges (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT NOT NULL DEFAULT 1,
    title                   VARCHAR(150) NOT NULL DEFAULT 'My Discipline Challenge',
    duration_days           INT NOT NULL DEFAULT 30,
    start_date              DATE NOT NULL,
    end_date                DATE NOT NULL,
    status                  ENUM('active','completed','abandoned') NOT NULL DEFAULT 'active',
    starting_capital        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    max_risk_per_trade_pct  DECIMAL(5,2)  NOT NULL DEFAULT 2.0,
    max_daily_loss_pct      DECIMAL(5,2)  NOT NULL DEFAULT 5.0,
    max_trades_per_day      TINYINT       NOT NULL DEFAULT 3,
    min_risk_reward         DECIMAL(4,1)  NOT NULL DEFAULT 2.0,
    session_start           TIME          DEFAULT NULL,
    session_end             TIME          DEFAULT NULL,
    total_xp                INT           NOT NULL DEFAULT 0,
    level                   TINYINT       NOT NULL DEFAULT 1,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chal_user_status (user_id, status)
);

CREATE TABLE IF NOT EXISTS challenge_days (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    challenge_id        INT NOT NULL,
    user_id             INT NOT NULL DEFAULT 1,
    day_date            DATE NOT NULL,
    day_number          INT NOT NULL,
    check_higher_tf     TINYINT(1) NOT NULL DEFAULT 0,
    check_key_levels    TINYINT(1) NOT NULL DEFAULT 0,
    check_confirmation  TINYINT(1) NOT NULL DEFAULT 0,
    check_risk_mgmt     TINYINT(1) NOT NULL DEFAULT 0,
    check_no_revenge    TINYINT(1) NOT NULL DEFAULT 0,
    check_setup_only    TINYINT(1) NOT NULL DEFAULT 0,
    check_stop_loss     TINYINT(1) NOT NULL DEFAULT 0,
    check_calm          TINYINT(1) NOT NULL DEFAULT 0,
    checklist_submitted TINYINT(1) NOT NULL DEFAULT 0,
    result              ENUM('followed','broke','no_trade') DEFAULT NULL,
    trades_count        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    daily_pl            DECIMAL(12,2)    NOT NULL DEFAULT 0.00,
    equity_end          DECIMAL(15,2)    DEFAULT NULL,
    wins                TINYINT UNSIGNED NOT NULL DEFAULT 0,
    losses              TINYINT UNSIGNED NOT NULL DEFAULT 0,
    emotions            VARCHAR(500)     DEFAULT NULL,
    went_well           TEXT DEFAULT NULL,
    mistakes            TEXT DEFAULT NULL,
    lessons             TEXT DEFAULT NULL,
    discipline_score    INT  NOT NULL DEFAULT 0,
    xp_earned           INT  NOT NULL DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_challenge_day (challenge_id, day_date),
    FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
    INDEX idx_cd_user_date (user_id, day_date)
);

-- Coaching session logs (pre/post-trade coaching records)
CREATE TABLE IF NOT EXISTS coaching_logs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL DEFAULT 1,
    log_date            DATE NOT NULL,
    log_type            ENUM('pre_trade','post_trade','weekly_review','general') DEFAULT 'general',
    content             TEXT NOT NULL,
    coach_feedback      TEXT DEFAULT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- Psychology & Discipline Tracker — Tables
-- ============================================================

-- Daily psychology journal: emotion state, bad habits, reflection, computed scores
CREATE TABLE IF NOT EXISTS psych_daily (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    user_id              INT NOT NULL DEFAULT 1,
    entry_date           DATE NOT NULL,
    pre_emotion          ENUM('calm','fear','greedy','angry','confident','revenge','fomo') DEFAULT NULL,
    habits_triggered     JSON DEFAULT NULL  COMMENT 'Array of habit code strings',
    habit_severity       JSON DEFAULT NULL  COMMENT 'Object mapping habit_code to 1|2|3',
    followed_plan        TINYINT(1) DEFAULT NULL,
    emotional_entry      TINYINT(1) DEFAULT NULL,
    emotional_exit       TINYINT(1) DEFAULT NULL,
    forced_trade         TINYINT(1) DEFAULT NULL,
    entered_early        TINYINT(1) DEFAULT NULL,
    had_patience         TINYINT(1) DEFAULT NULL,
    followed_rules       TINYINT(1) DEFAULT NULL,
    discipline_score     TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
    psychology_score     TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
    emotional_stability  TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
    coach_feedback       TEXT DEFAULT NULL,
    notes                TEXT DEFAULT NULL,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_psych_user_date (user_id, entry_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Per-trade quality scoring: 7 criteria rated 1-10, overall_score computed 0-100
CREATE TABLE IF NOT EXISTS psych_trade_quality (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    user_id           INT NOT NULL DEFAULT 1,
    trade_id          INT DEFAULT NULL COMMENT 'Optional FK to trades.id',
    entry_date        DATE NOT NULL,
    symbol            VARCHAR(20) DEFAULT NULL,
    pre_emotion       ENUM('calm','fear','greedy','angry','confident','revenge','fomo') DEFAULT NULL,
    setup_quality     TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10',
    emotional_control TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10',
    risk_management   TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10',
    patience          TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10',
    rr_quality        TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10',
    rule_following    TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10',
    sl_discipline     TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1-10',
    overall_score     TINYINT UNSIGNED DEFAULT NULL COMMENT '0-100',
    notes             TEXT DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ptq_user_date (user_id, entry_date)
);

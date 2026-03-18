# 📋 POST OFFICE SCHEMES MODULE - PHP + MySQL BLUEPRINT

---

## 🗂️ FOLDER STRUCTURE

```
wealthdash/
│
├── app/
│   ├── modules/
│   │   ├── post_office_schemes/
│   │   │   ├── controllers/
│   │   │   │   ├── SchemeController.php
│   │   │   │   ├── AccountController.php
│   │   │   │   ├── ReportController.php
│   │   │   │   ├── RecommendationController.php
│   │   │   │   ├── TaxController.php
│   │   │   │   └── MaturityController.php
│   │   │   │
│   │   │   ├── models/
│   │   │   │   ├── Scheme.php
│   │   │   │   ├── Account.php
│   │   │   │   ├── Transaction.php
│   │   │   │   ├── Interest.php
│   │   │   │   ├── TaxTracking.php
│   │   │   │   ├── Recommendation.php
│   │   │   │   └── GoalTracking.php
│   │   │   │
│   │   │   ├── services/
│   │   │   │   ├── SchemeComparison.php (Comparison Charts)
│   │   │   │   ├── RecommendationEngine.php (AI Recommender)
│   │   │   │   ├── InterestCalculator.php (Interest & Maturity)
│   │   │   │   ├── TaxOptimizer.php (80C Optimizer)
│   │   │   │   ├── GoalPlanner.php (Investment Path)
│   │   │   │   ├── SchemeSwitcher.php (Smart Migration)
│   │   │   │   ├── InflationCalculator.php (Real Returns)
│   │   │   │   ├── PerformanceAnalyzer.php (Scorecard)
│   │   │   │   ├── DocumentGenerator.php (Auto Docs)
│   │   │   │   └── NotificationService.php (Alerts)
│   │   │   │
│   │   │   ├── views/
│   │   │   │   ├── dashboard/
│   │   │   │   │   ├── portfolio_overview.php
│   │   │   │   │   ├── comparison_chart.php
│   │   │   │   │   └── recommendations.php
│   │   │   │   │
│   │   │   │   ├── accounts/
│   │   │   │   │   ├── open_account.php
│   │   │   │   │   ├── account_list.php
│   │   │   │   │   └── account_details.php
│   │   │   │   │
│   │   │   │   ├── reports/
│   │   │   │   │   ├── interest_report.php
│   │   │   │   │   ├── tax_report.php
│   │   │   │   │   ├── goal_tracking.php
│   │   │   │   │   └── performance_scorecard.php
│   │   │   │   │
│   │   │   │   └── tools/
│   │   │   │       ├── comparison_tool.php
│   │   │   │       ├── maturity_manager.php
│   │   │   │       └── tax_planner.php
│   │   │   │
│   │   │   ├── config/
│   │   │   │   ├── schemes_data.php (Scheme details)
│   │   │   │   ├── interest_rates.php (Current rates)
│   │   │   │   └── tax_rules.php (Tax calculations)
│   │   │   │
│   │   │   ├── api/
│   │   │   │   ├── v1/
│   │   │   │   │   ├── schemes.php
│   │   │   │   │   ├── accounts.php
│   │   │   │   │   ├── recommendations.php
│   │   │   │   │   ├── reports.php
│   │   │   │   │   ├── tax.php
│   │   │   │   │   └── maturity.php
│   │   │   │   │
│   │   │   │   └── handlers/
│   │   │   │       ├── comparison_handler.php
│   │   │   │       ├── calculator_handler.php
│   │   │   │       └── report_handler.php
│   │   │   │
│   │   │   ├── migrations/
│   │   │   │   ├── 001_create_schemes_table.php
│   │   │   │   ├── 002_create_accounts_table.php
│   │   │   │   ├── 003_create_transactions_table.php
│   │   │   │   ├── 004_create_interest_table.php
│   │   │   │   ├── 005_create_tax_tracking_table.php
│   │   │   │   ├── 006_create_goals_table.php
│   │   │   │   └── 007_create_maturity_alerts_table.php
│   │   │   │
│   │   │   ├── helpers/
│   │   │   │   ├── CalculationHelper.php
│   │   │   │   ├── ValidationHelper.php
│   │   │   │   └── FormatHelper.php
│   │   │   │
│   │   │   └── tests/
│   │   │       ├── SchemeComparisonTest.php
│   │   │       ├── InterestCalculatorTest.php
│   │   │       └── RecommendationEngineTest.php
│   │   │
│   │   └── [other_modules]/
│   │
│   ├── core/
│   │   ├── Database.php
│   │   ├── Controller.php
│   │   ├── Model.php
│   │   └── Service.php
│   │
│   └── utils/
│       ├── Logger.php
│       ├── Cache.php
│       └── Email.php
│
├── public/
│   ├── index.php (Router)
│   ├── api.php (API Router)
│   │
│   ├── assets/
│   │   ├── js/
│   │   │   ├── schemes/
│   │   │   │   ├── comparison-chart.js
│   │   │   │   ├── calculator.js
│   │   │   │   └── recommender.js
│   │   │   │
│   │   │   └── charts/
│   │   │       └── chart-config.js
│   │   │
│   │   └── css/
│   │       ├── schemes.css
│   │       └── dashboard.css
│   │
│   └── uploads/
│       ├── documents/
│       └── certificates/
│
├── storage/
│   ├── cache/
│   ├── logs/
│   └── temp/
│
├── tests/
│   └── PostOfficeTest.php
│
├── .env.example
├── composer.json (PHP Dependencies)
├── routes.php
└── README.md
```

---

## 🗄️ DATABASE SCHEMA (MySQL)

### **1. SCHEMES TABLE** - Master scheme data
```sql
CREATE TABLE po_schemes (
    scheme_id INT PRIMARY KEY AUTO_INCREMENT,
    scheme_name VARCHAR(100) NOT NULL,
    scheme_type ENUM('SB','RD','TD','MIS','PPF','Sukanya','NSC','KVP','MSSC','SCSS') NOT NULL,
    min_amount DECIMAL(12, 2),
    max_amount DECIMAL(12, 2),
    tenure_months INT,
    current_interest_rate DECIMAL(5, 2),
    tax_treatment ENUM('Taxable', 'Tax-Free', 'Partial-Free') DEFAULT 'Taxable',
    section_80c_eligible BOOLEAN DEFAULT FALSE,
    liquidity_status ENUM('Liquid', 'Restricted', 'None'),
    withdrawal_before_maturity BOOLEAN DEFAULT FALSE,
    description TEXT,
    eligibility_criteria TEXT, -- e.g., 'Girl child only', 'Senior citizen 60+', etc.
    key_features JSON, -- Store as JSON array
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### **2. ACCOUNTS TABLE** - Customer accounts per scheme
```sql
CREATE TABLE po_accounts (
    account_id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    scheme_id INT NOT NULL,
    account_number VARCHAR(50) UNIQUE NOT NULL,
    opening_date DATE NOT NULL,
    maturity_date DATE,
    status ENUM('Active', 'Matured', 'Closed', 'Inactive') DEFAULT 'Active',
    amount_invested DECIMAL(15, 2) NOT NULL,
    interest_rate DECIMAL(5, 2) NOT NULL,
    monthly_deposit DECIMAL(12, 2), -- For RD/MIS
    tenure_months INT,
    current_balance DECIMAL(15, 2),
    nominee_name VARCHAR(100),
    nominee_relationship VARCHAR(50),
    documents_uploaded JSON, -- File paths
    renewal_count INT DEFAULT 0,
    auto_renewal BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    FOREIGN KEY (scheme_id) REFERENCES po_schemes(scheme_id),
    INDEX idx_customer_id (customer_id),
    INDEX idx_scheme_id (scheme_id),
    INDEX idx_maturity_date (maturity_date),
    INDEX idx_status (status)
);
```

### **3. TRANSACTIONS TABLE** - All deposits/withdrawals
```sql
CREATE TABLE po_transactions (
    transaction_id INT PRIMARY KEY AUTO_INCREMENT,
    account_id INT NOT NULL,
    transaction_type ENUM('Deposit', 'Withdrawal', 'Interest', 'Renewal', 'Maturity') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    transaction_date DATE NOT NULL,
    balance_after DECIMAL(15, 2),
    description VARCHAR(255),
    reference_number VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (account_id) REFERENCES po_accounts(account_id),
    INDEX idx_account_id (account_id),
    INDEX idx_transaction_date (transaction_date)
);
```

### **4. INTEREST_CALCULATION TABLE** - Track interest earned
```sql
CREATE TABLE po_interest_calculation (
    interest_id INT PRIMARY KEY AUTO_INCREMENT,
    account_id INT NOT NULL,
    calculation_month DATE NOT NULL, -- YYYY-MM-01
    principal_amount DECIMAL(15, 2),
    interest_rate DECIMAL(5, 2),
    interest_earned DECIMAL(15, 2),
    cumulative_interest DECIMAL(15, 2),
    tax_applicable DECIMAL(15, 2) DEFAULT 0,
    tax_slab_percentage INT,
    tds_deducted DECIMAL(15, 2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (account_id) REFERENCES po_accounts(account_id),
    INDEX idx_account_id (account_id),
    INDEX idx_calculation_month (calculation_month)
);
```

### **5. TAX_TRACKING TABLE** - Tax related data
```sql
CREATE TABLE po_tax_tracking (
    tax_record_id INT PRIMARY KEY AUTO_INCREMENT,
    account_id INT NOT NULL,
    financial_year VARCHAR(10), -- '2025-26'
    interest_earned DECIMAL(15, 2),
    tax_applicable DECIMAL(15, 2),
    section_80c_amount DECIMAL(15, 2) DEFAULT 0,
    tds_deducted DECIMAL(15, 2),
    net_tax_payable DECIMAL(15, 2),
    tax_slab_id INT,
    certificate_generated BOOLEAN DEFAULT FALSE,
    certificate_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (account_id) REFERENCES po_accounts(account_id),
    INDEX idx_account_id (account_id),
    INDEX idx_financial_year (financial_year)
);
```

### **6. GOALS_TRACKING TABLE** - Customer financial goals
```sql
CREATE TABLE po_goals_tracking (
    goal_id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    goal_name VARCHAR(100) NOT NULL, -- 'Daughter Education', 'Retirement', etc.
    target_amount DECIMAL(15, 2) NOT NULL,
    target_date DATE NOT NULL,
    current_progress DECIMAL(15, 2) DEFAULT 0,
    status ENUM('On Track', 'At Risk', 'Delayed', 'Completed') DEFAULT 'On Track',
    linked_accounts JSON, -- Array of account_ids contributing to this goal
    projected_value DECIMAL(15, 2),
    shortfall_amount DECIMAL(15, 2) DEFAULT 0,
    recommendation TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    INDEX idx_customer_id (customer_id),
    INDEX idx_target_date (target_date)
);
```

### **7. MATURITY_ALERTS TABLE** - Auto-renewal reminders
```sql
CREATE TABLE po_maturity_alerts (
    alert_id INT PRIMARY KEY AUTO_INCREMENT,
    account_id INT NOT NULL,
    maturity_date DATE NOT NULL,
    days_before INT, -- 90, 60, 30, 15
    alert_sent BOOLEAN DEFAULT FALSE,
    alert_sent_date TIMESTAMP NULL,
    alert_type ENUM('Email', 'SMS', 'WhatsApp', 'InApp') DEFAULT 'Email',
    renewal_options JSON, -- Store 4 renewal paths
    status ENUM('Pending', 'Sent', 'Dismissed') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (account_id) REFERENCES po_accounts(account_id),
    INDEX idx_maturity_date (maturity_date),
    INDEX idx_alert_sent (alert_sent)
);
```

### **8. RECOMMENDATIONS TABLE** - Store recommendation history
```sql
CREATE TABLE po_recommendations (
    recommendation_id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    recommendation_type ENUM('Portfolio', 'Switcher', 'Monthly', 'Tax') NOT NULL,
    scheme_mix JSON, -- Recommended scheme allocation
    confidence_score DECIMAL(3, 2), -- 0.00 to 1.00
    expected_return DECIMAL(15, 2),
    tax_benefit DECIMAL(15, 2),
    generated_at TIMESTAMP,
    action_taken BOOLEAN DEFAULT FALSE,
    action_date TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
    INDEX idx_customer_id (customer_id)
);
```

### **9. INTEREST_RATES_HISTORY TABLE** - Track rate changes
```sql
CREATE TABLE po_interest_rates_history (
    rate_history_id INT PRIMARY KEY AUTO_INCREMENT,
    scheme_id INT NOT NULL,
    interest_rate DECIMAL(5, 2),
    effective_date DATE,
    updated_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (scheme_id) REFERENCES po_schemes(scheme_id),
    INDEX idx_scheme_id (scheme_id),
    INDEX idx_effective_date (effective_date)
);
```

---

## 🔌 API ENDPOINTS (RESTful)

### **Base URL:** `/api/v1/post-office`

#### **SCHEMES ENDPOINTS**
```php
// Get all schemes with filters
GET  /api/v1/post-office/schemes
     ?type=PPF&min_rate=7&tax_free=true

// Get single scheme details
GET  /api/v1/post-office/schemes/:scheme_id

// Get current interest rates
GET  /api/v1/post-office/schemes/rates/current

// Get rate history (6 months)
GET  /api/v1/post-office/schemes/:scheme_id/rate-history

// Response Example:
{
    "status": "success",
    "data": {
        "schemes": [
            {
                "scheme_id": 5,
                "scheme_name": "Public Provident Fund",
                "scheme_type": "PPF",
                "current_interest_rate": 7.1,
                "min_amount": 500,
                "max_amount": "Unlimited",
                "section_80c_eligible": true,
                "tax_treatment": "Tax-Free",
                "liquidity_status": "Restricted"
            }
        ]
    }
}
```

#### **ACCOUNTS ENDPOINTS**
```php
// Get customer's all accounts (Portfolio view)
GET  /api/v1/post-office/accounts
     ?customer_id=123&status=Active

// Create new account
POST /api/v1/post-office/accounts
{
    "customer_id": 123,
    "scheme_id": 5,
    "amount_invested": 5000,
    "monthly_deposit": null,
    "tenure_months": 180,
    "nominee_name": "Spouse",
    "nominee_relationship": "Spouse"
}

// Get account details
GET  /api/v1/post-office/accounts/:account_id

// Update account
PUT  /api/v1/post-office/accounts/:account_id
{
    "auto_renewal": true,
    "nominee_name": "Child"
}

// Close account
DELETE /api/v1/post-office/accounts/:account_id
```

#### **COMPARISON ENDPOINTS**
```php
// Compare schemes (RD vs PPF vs NSC)
POST /api/v1/post-office/compare
{
    "schemes": [2, 5, 7],
    "amount": 100000,
    "tenure_years": 10,
    "tax_slab": 30,
    "include_inflation": true
}

// Response:
{
    "status": "success",
    "data": {
        "comparison": [
            {
                "scheme_name": "PPF",
                "amount_invested": 100000,
                "interest_rate": 7.1,
                "tenure_years": 10,
                "maturity_value": 196000,
                "tax_adjusted_value": 196000,
                "real_value_after_inflation": 114000,
                "tax_saved": 0,
                "annual_growth": "7.1%"
            },
            // ... more schemes
        ],
        "best_scheme": "PPF (Tax-adjusted)",
        "best_returns": "Sukanya (highest nominal)"
    }
}
```

#### **RECOMMENDATION ENDPOINTS**
```php
// Get AI recommendation
POST /api/v1/post-office/recommend
{
    "monthly_investment": 5000,
    "investment_horizon_years": 15,
    "goal": "maximize_returns",
    "tax_bracket": 30,
    "customer_type": "salaried",
    "has_girl_child": true,
    "age": 35
}

// Response:
{
    "status": "success",
    "data": {
        "recommendation": {
            "portfolio_mix": [
                {
                    "scheme_name": "PPF",
                    "monthly_amount": 2500,
                    "percentage": 50,
                    "reason": "Maximum tax benefit"
                },
                {
                    "scheme_name": "Sukanya Samriddhi",
                    "monthly_amount": 1500,
                    "percentage": 30,
                    "reason": "Highest interest + tax-free"
                },
                {
                    "scheme_name": "MIS",
                    "monthly_amount": 1000,
                    "percentage": 20,
                    "reason": "Monthly income"
                }
            ],
            "expected_15_year_value": 1332000,
            "annual_tax_saved": 27000,
            "confidence_score": 0.98
        }
    }
}

// Get monthly investment recommendations
GET  /api/v1/post-office/recommend/monthly
    ?amount=1000&goal=maximize_returns

// Get best scheme for amount
GET  /api/v1/post-office/recommend/best-scheme
    ?amount=5000&tenure_years=5&priority=returns
```

#### **TAX ENDPOINTS**
```php
// Calculate 80C deduction
POST /api/v1/post-office/tax/80c-calculator
{
    "ppf_investment": 150000,
    "sukanya_investment": 50000,
    "life_insurance": 40000,
    "tax_bracket": 30
}

// Response:
{
    "status": "success",
    "data": {
        "total_80c_investment": 240000,
        "max_80c_limit": 150000,
        "utilized_deduction": 150000,
        "wasted_potential": 90000,
        "tax_saved": 45000,
        "recommendation": "Reduce investments by ₹90,000"
    }
}

// Generate tax report for ITR
POST /api/v1/post-office/tax/generate-report
{
    "account_id": 42,
    "financial_year": "2025-26"
}

// Get interest certificate
GET  /api/v1/post-office/tax/interest-certificate
    ?account_id=42&year=2025
```

#### **MATURITY ENDPOINTS**
```php
// Get maturity alerts (3/6/12 months)
GET  /api/v1/post-office/maturity/upcoming
    ?days_range=90

// Get maturity options for account
GET  /api/v1/post-office/maturity/:account_id/options

// Response:
{
    "status": "success",
    "data": {
        "maturity_date": "2026-04-30",
        "current_value": 684000,
        "options": [
            {
                "option": 1,
                "action": "Full Renewal",
                "scheme": "PPF",
                "new_tenure_years": 5,
                "expected_maturity": 925000,
                "tax_impact": "Tax-free"
            },
            {
                "option": 2,
                "action": "Switch to Sukanya",
                "scheme": "Sukanya Samriddhi",
                "tenure_years": 5,
                "expected_maturity": 1152000,
                "extra_earnings": 227000,
                "recommendation": "BEST FOR RETURNS"
            }
        ]
    }
}

// Initiate renewal
POST /api/v1/post-office/maturity/:account_id/renew
{
    "renewal_option": 1,
    "new_scheme_id": 5
}
```

#### **REPORTS ENDPOINTS**
```php
// Portfolio overview
GET  /api/v1/post-office/reports/portfolio
    ?customer_id=123

// Interest earned report
GET  /api/v1/post-office/reports/interest
    ?account_id=42&month=2026-03

// Goal tracking report
GET  /api/v1/post-office/reports/goals
    ?customer_id=123

// Performance scorecard
GET  /api/v1/post-office/reports/scorecard
    ?customer_id=123

// Generate PDF reports
POST /api/v1/post-office/reports/export
{
    "report_type": "portfolio_summary",
    "account_id": 42,
    "format": "pdf"
}
```

---

## 💻 CORE PHP CLASSES & IMPLEMENTATION

### **1. SchemeComparison Service** - Comparison Charts Logic
```php
<?php
// app/modules/post_office_schemes/services/SchemeComparison.php

namespace PostOfficeSchemes\Services;

class SchemeComparison {
    private $db;
    private $interestCalculator;

    public function __construct($db, InterestCalculator $calculator) {
        $this->db = $db;
        $this->interestCalculator = $calculator;
    }

    /**
     * Compare multiple schemes with projections
     * @param array $scheme_ids [5, 2, 7]
     * @param float $investment Amount to invest
     * @param int $tenure_months Duration
     * @param int $tax_bracket Customer tax bracket (10, 20, 30%)
     * @param bool $include_inflation Whether to adjust for inflation
     * @return array Comparison data
     */
    public function compareSchemes(array $scheme_ids, float $investment, 
                                   int $tenure_months, int $tax_bracket = 30, 
                                   bool $include_inflation = true) {
        $comparison = [];

        foreach ($scheme_ids as $scheme_id) {
            $scheme = $this->getSchemeDetails($scheme_id);
            
            $maturity = $this->interestCalculator->calculateMaturity(
                $investment,
                $scheme['current_interest_rate'],
                $tenure_months
            );

            $tax_adjusted = $this->calculateTaxAdjusted(
                $maturity,
                $scheme,
                $tax_bracket
            );

            $real_value = $include_inflation ? 
                $this->applyInflationAdjustment($tax_adjusted, $tenure_months) : 
                $tax_adjusted;

            $comparison[] = [
                'scheme_id' => $scheme_id,
                'scheme_name' => $scheme['scheme_name'],
                'scheme_type' => $scheme['scheme_type'],
                'amount_invested' => $investment,
                'interest_rate' => $scheme['current_interest_rate'],
                'tenure_months' => $tenure_months,
                'tenure_years' => $tenure_months / 12,
                'maturity_value' => round($maturity, 2),
                'interest_earned' => round($maturity - $investment, 2),
                'tax_applicable' => $scheme['tax_treatment'] === 'Taxable',
                'tax_amount' => round($this->calculateTax($maturity - $investment, $tax_bracket), 2),
                'tax_adjusted_value' => round($tax_adjusted, 2),
                'real_value_after_inflation' => round($real_value, 2),
                'effective_return_percent' => round((($tax_adjusted - $investment) / $investment * 100), 2),
                'real_return_percent' => round((($real_value - $investment) / $investment * 100), 2),
                'tax_saved' => $scheme['section_80c_eligible'] ? 
                    round($investment * $tax_bracket / 100, 2) : 0,
                'liquidity' => $scheme['liquidity_status']
            ];
        }

        // Rank by best returns (tax-adjusted)
        usort($comparison, function($a, $b) {
            return $b['tax_adjusted_value'] <=> $a['tax_adjusted_value'];
        });

        return [
            'comparison' => $comparison,
            'best_overall' => $comparison[0],
            'best_by_returns' => $this->findBestByReturns($comparison),
            'best_by_tax_benefit' => $this->findBestByTaxBenefit($comparison),
            'summary' => $this->generateSummary($comparison)
        ];
    }

    /**
     * Calculate tax-adjusted maturity value
     */
    private function calculateTaxAdjusted($maturity, $scheme, $tax_bracket) {
        $interest = $maturity - ($maturity / (1 + $scheme['current_interest_rate']/100));
        
        if ($scheme['tax_treatment'] === 'Tax-Free') {
            return $maturity;
        } elseif ($scheme['tax_treatment'] === 'Partial-Free') {
            $tax_free_limit = 10000; // Up to ₹10K usually tax-free
            $taxable_interest = max(0, $interest - $tax_free_limit);
            $tax = $taxable_interest * ($tax_bracket / 100);
            return $maturity - $tax;
        } else {
            $tax = $interest * ($tax_bracket / 100);
            return $maturity - $tax;
        }
    }

    /**
     * Apply inflation adjustment (5.5% assumed)
     */
    private function applyInflationAdjustment($amount, $months) {
        $inflation_rate = 0.055; // 5.5% annual
        $years = $months / 12;
        return $amount / pow(1 + $inflation_rate, $years);
    }

    /**
     * Calculate tax on interest
     */
    private function calculateTax($interest, $tax_bracket) {
        return $interest * ($tax_bracket / 100);
    }

    private function findBestByReturns($comparison) {
        return array_reduce($comparison, function($max, $item) {
            return ($item['tax_adjusted_value'] > ($max['tax_adjusted_value'] ?? 0)) ? $item : $max;
        });
    }

    private function findBestByTaxBenefit($comparison) {
        return array_reduce($comparison, function($max, $item) {
            return ($item['tax_saved'] > ($max['tax_saved'] ?? 0)) ? $item : $max;
        });
    }

    private function generateSummary($comparison) {
        return [
            'highest_return' => max(array_column($comparison, 'maturity_value')),
            'highest_tax_benefit' => max(array_column($comparison, 'tax_saved')),
            'average_return' => array_sum(array_column($comparison, 'maturity_value')) / count($comparison)
        ];
    }

    private function getSchemeDetails($scheme_id) {
        $sql = "SELECT * FROM po_schemes WHERE scheme_id = ?";
        return $this->db->queryOne($sql, [$scheme_id]);
    }
}
```

### **2. RecommendationEngine Service** - AI Recommender
```php
<?php
// app/modules/post_office_schemes/services/RecommendationEngine.php

namespace PostOfficeSchemes\Services;

class RecommendationEngine {
    private $db;
    private $schemeComparison;
    private $interestCalculator;

    public function __construct($db, SchemeComparison $comparison, InterestCalculator $calculator) {
        $this->db = $db;
        $this->schemeComparison = $comparison;
        $this->interestCalculator = $calculator;
    }

    /**
     * Generate personalized recommendation based on customer profile
     * @param array $profile Customer profile data
     * @return array Recommendation with scheme mix
     */
    public function generateRecommendation(array $profile) {
        // Profile structure:
        // [
        //     'monthly_investment' => 5000,
        //     'investment_horizon_years' => 15,
        //     'primary_goal' => 'maximize_returns', // or 'tax_saving', 'income', 'balanced'
        //     'tax_bracket' => 30,
        //     'customer_type' => 'salaried', // or 'senior_citizen', 'woman'
        //     'has_girl_child' => true,
        //     'age' => 35,
        //     'risk_tolerance' => 'conservative' // or 'moderate', 'aggressive'
        // ]

        // Score each scheme based on profile
        $schemes = $this->getAllSchemes();
        $scored_schemes = [];

        foreach ($schemes as $scheme) {
            $score = $this->scoreScheme($scheme, $profile);
            $scored_schemes[] = array_merge($scheme, ['score' => $score]);
        }

        // Sort by score
        usort($scored_schemes, fn($a, $b) => $b['score'] <=> $a['score']);

        // Generate portfolio mix
        $portfolio_mix = $this->generatePortfolioMix($scored_schemes, $profile);

        // Calculate projections
        $projections = $this->calculateProjections($portfolio_mix, $profile);

        return [
            'recommendation' => [
                'portfolio_mix' => $portfolio_mix,
                'expected_return' => $projections['total_value'],
                'annual_tax_saved' => $projections['tax_saved'],
                'monthly_income' => $projections['monthly_income'] ?? 0,
                'confidence_score' => $this->calculateConfidenceScore($portfolio_mix),
                'reasoning' => $this->generateReasoning($portfolio_mix, $profile)
            ],
            'alternative_mixes' => $this->generateAlternatives($scored_schemes, $profile)
        ];
    }

    /**
     * Score scheme based on customer profile
     */
    private function scoreScheme($scheme, $profile) {
        $score = 50; // Base score

        // Eligibility checks
        if ($scheme['scheme_type'] === 'Sukanya' && !$profile['has_girl_child']) {
            return 0; // Not eligible
        }
        if ($scheme['scheme_type'] === 'SCSS' && $profile['age'] < 60) {
            return 0; // Not eligible
        }

        // Goal matching
        switch ($profile['primary_goal']) {
            case 'tax_saving':
                if ($scheme['section_80c_eligible']) $score += 25;
                if ($scheme['tax_treatment'] === 'Tax-Free') $score += 15;
                break;
            case 'maximize_returns':
                $score += $scheme['current_interest_rate'] * 2; // Higher rate = higher score
                if ($scheme['tax_treatment'] === 'Tax-Free') $score += 10;
                break;
            case 'income':
                if ($scheme['scheme_type'] === 'MIS') $score += 30;
                break;
            case 'balanced':
                $score += $scheme['current_interest_rate'] * 1.5;
                if ($scheme['section_80c_eligible']) $score += 10;
                break;
        }

        // Risk profile matching
        if ($profile['risk_tolerance'] === 'conservative') {
            if ($scheme['liquidity_status'] !== 'None') $score += 10;
        }

        return $score;
    }

    /**
     * Generate optimal portfolio mix
     */
    private function generatePortfolioMix($scored_schemes, $profile) {
        $monthly_investment = $profile['monthly_investment'];
        $max_80c_limit = 150000; // ₹1.5L per year
        
        $portfolio = [];
        $remaining_investment = $monthly_investment;
        $annual_80c_used = 0;

        // Select top schemes
        $top_schemes = array_slice($scored_schemes, 0, 4); // Top 4 schemes

        foreach ($top_schemes as $scheme) {
            if ($remaining_investment <= 0) break;

            // Calculate allocation
            if ($scheme['section_80c_eligible'] && 
                $annual_80c_used < $max_80c_limit &&
                $profile['primary_goal'] === 'tax_saving') {
                
                // Prioritize 80C schemes
                $annual_amount = min($remaining_investment * 12, 150000 - $annual_80c_used);
                $monthly_amount = $annual_amount / 12;
            } else {
                // Distribute equally or by priority
                $monthly_amount = $remaining_investment * 0.3; // 30% allocation
            }

            if ($monthly_amount > 0) {
                $portfolio[] = [
                    'scheme_id' => $scheme['scheme_id'],
                    'scheme_name' => $scheme['scheme_name'],
                    'scheme_type' => $scheme['scheme_type'],
                    'monthly_amount' => round($monthly_amount, 2),
                    'percentage' => round(($monthly_amount / $monthly_investment) * 100, 1),
                    'reason' => $this->getSchemeReason($scheme, $profile),
                    'interest_rate' => $scheme['current_interest_rate'],
                    'tenure_months' => $scheme['tenure_months'] ?? 60
                ];

                $remaining_investment -= $monthly_amount;
                $annual_80c_used += ($monthly_amount * 12);
            }
        }

        return $portfolio;
    }

    /**
     * Calculate 15-year projections
     */
    private function calculateProjections($portfolio_mix, $profile) {
        $total_value = 0;
        $total_tax_saved = 0;
        $total_monthly_income = 0;
        $months = $profile['investment_horizon_years'] * 12;

        foreach ($portfolio_mix as $scheme) {
            // Simple compound interest calculation
            $principal = $scheme['monthly_amount'] * $months;
            $rate = $scheme['interest_rate'] / 100 / 12;
            
            // FV of annuity formula
            $maturity = $scheme['monthly_amount'] * 
                        (((pow(1 + $rate, $months) - 1) / $rate) * (1 + $rate));

            $total_value += $maturity;

            // Tax calculation
            if (strpos($scheme['scheme_name'], 'PPF') !== false || 
                strpos($scheme['scheme_name'], 'Sukanya') !== false) {
                $tax_saved = ($scheme['monthly_amount'] * 12) * ($profile['tax_bracket'] / 100);
                $total_tax_saved += $tax_saved;
            }

            // Monthly income (for MIS)
            if ($scheme['scheme_type'] === 'MIS') {
                $total_monthly_income += ($scheme['monthly_amount'] * $scheme['interest_rate'] / 100 / 12);
            }
        }

        return [
            'total_value' => round($total_value, 2),
            'tax_saved' => round($total_tax_saved, 2),
            'monthly_income' => round($total_monthly_income, 2)
        ];
    }

    private function calculateConfidenceScore($portfolio_mix) {
        $diversity = min(count($portfolio_mix) / 4, 1.0); // Max 4 schemes
        $allocation_balance = $this->checkAllocationBalance($portfolio_mix);
        return round((0.5 * $diversity + 0.5 * $allocation_balance) * 100, 0) / 100;
    }

    private function checkAllocationBalance($portfolio_mix) {
        $percentages = array_column($portfolio_mix, 'percentage');
        $variance = 0;
        $mean = 100 / count($percentages);
        
        foreach ($percentages as $p) {
            $variance += pow($p - $mean, 2);
        }
        
        $balance = 1 - ($variance / 10000); // Normalize
        return max(0, $balance);
    }

    private function getSchemeReason($scheme, $profile) {
        if ($scheme['section_80c_eligible'] && $profile['primary_goal'] === 'tax_saving') {
            return "Maximum tax deduction benefit";
        }
        if ($scheme['current_interest_rate'] > 7) {
            return "Highest interest rate available";
        }
        if ($scheme['scheme_type'] === 'MIS') {
            return "Monthly income generation";
        }
        if ($scheme['tax_treatment'] === 'Tax-Free') {
            return "Tax-free returns";
        }
        return "Diversified portfolio";
    }

    private function generateReasoning($portfolio_mix, $profile) {
        $reasons = [
            "Based on your ₹" . $profile['monthly_investment'] . " budget",
            "Optimized for " . $profile['primary_goal'],
            "Matching your " . $profile['investment_horizon_years'] . "-year horizon"
        ];
        return $reasons;
    }

    private function generateAlternatives($scored_schemes, $profile) {
        return []; // Would generate 2-3 alternative portfolio mixes
    }

    private function getAllSchemes() {
        $sql = "SELECT * FROM po_schemes ORDER BY scheme_type";
        return $this->db->queryAll($sql);
    }
}
```

### **3. InterestCalculator Service** - Core calculations
```php
<?php
// app/modules/post_office_schemes/services/InterestCalculator.php

namespace PostOfficeSchemes\Services;

class InterestCalculator {
    /**
     * Calculate compound interest
     */
    public function calculateMaturity($principal, $rate, $months) {
        $years = $months / 12;
        $rate_decimal = $rate / 100;
        return $principal * pow(1 + $rate_decimal, $years);
    }

    /**
     * Calculate monthly deposit FV (for RD schemes)
     * Uses FV of Annuity formula
     */
    public function calculateRecurringDepositMaturity($monthly_deposit, $rate, $months) {
        $rate_monthly = $rate / 100 / 12;
        $n = $months;
        
        // FV = P * [((1 + r)^n - 1) / r]
        return $monthly_deposit * ((pow(1 + $rate_monthly, $n) - 1) / $rate_monthly);
    }

    /**
     * Calculate interest for given period
     */
    public function calculateInterestForPeriod($principal, $rate, $start_date, $end_date) {
        $days = $this->daysBetween($start_date, $end_date);
        $years = $days / 365;
        $rate_decimal = $rate / 100;
        
        $final_amount = $principal * pow(1 + $rate_decimal, $years);
        return $final_amount - $principal;
    }

    /**
     * Calculate monthly interest breakdown (for reports)
     */
    public function getMonthlyInterestBreakdown($principal, $rate, $start_date, $months) {
        $breakdown = [];
        $rate_monthly = $rate / 100 / 12;
        $current_principal = $principal;

        for ($i = 1; $i <= $months; $i++) {
            $interest = $current_principal * $rate_monthly;
            $breakdown[] = [
                'month' => $i,
                'principal' => round($current_principal, 2),
                'interest' => round($interest, 2),
                'cumulative_interest' => round(array_sum(array_column($breakdown, 'interest')) + $interest, 2)
            ];
            $current_principal += $interest;
        }

        return $breakdown;
    }

    /**
     * Calculate days between two dates
     */
    private function daysBetween($date1, $date2) {
        $d1 = new \DateTime($date1);
        $d2 = new \DateTime($date2);
        return $d2->diff($d1)->days;
    }
}
```

### **4. Account Controller** - Handle account operations
```php
<?php
// app/modules/post_office_schemes/controllers/AccountController.php

namespace PostOfficeSchemes\Controllers;

use PostOfficeSchemes\Models\Account;
use PostOfficeSchemes\Services\InterestCalculator;

class AccountController {
    private $accountModel;
    private $interestCalculator;

    public function __construct(Account $model, InterestCalculator $calculator) {
        $this->accountModel = $model;
        $this->interestCalculator = $calculator;
    }

    /**
     * Get all accounts for customer
     */
    public function getCustomerAccounts() {
        $customer_id = $_GET['customer_id'] ?? null;
        $status = $_GET['status'] ?? null;

        if (!$customer_id) {
            return $this->errorResponse("Customer ID required", 400);
        }

        $accounts = $this->accountModel->getByCustomer($customer_id, $status);

        // Enrich with current values
        foreach ($accounts as &$account) {
            $account['current_value'] = $this->calculateCurrentValue($account);
            $account['interest_earned'] = $account['current_value'] - $account['amount_invested'];
            $account['days_to_maturity'] = $this->daysToMaturity($account['maturity_date']);
        }

        return $this->successResponse($accounts);
    }

    /**
     * Create new account
     */
    public function createAccount() {
        $data = $this->getJsonInput();

        // Validate
        $errors = $this->validateAccountData($data);
        if (!empty($errors)) {
            return $this->errorResponse("Validation failed", 422, $errors);
        }

        try {
            $account_id = $this->accountModel->create($data);
            
            // Log transaction
            $this->logTransaction($account_id, 'Deposit', $data['amount_invested']);

            return $this->successResponse([
                'account_id' => $account_id,
                'message' => 'Account created successfully'
            ], 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get account details with full history
     */
    public function getAccountDetails() {
        $account_id = $_GET['account_id'] ?? null;

        if (!$account_id) {
            return $this->errorResponse("Account ID required", 400);
        }

        $account = $this->accountModel->getById($account_id);
        
        if (!$account) {
            return $this->errorResponse("Account not found", 404);
        }

        // Add calculations
        $account['current_value'] = $this->calculateCurrentValue($account);
        $account['interest_earned'] = $account['current_value'] - $account['amount_invested'];
        $account['interest_percentage'] = ($account['interest_earned'] / $account['amount_invested']) * 100;
        $account['days_to_maturity'] = $this->daysToMaturity($account['maturity_date']);
        $account['transactions'] = $this->accountModel->getTransactions($account_id);

        return $this->successResponse($account);
    }

    /**
     * Close account (with withdrawal)
     */
    public function closeAccount() {
        $account_id = $_POST['account_id'] ?? null;

        if (!$account_id) {
            return $this->errorResponse("Account ID required", 400);
        }

        try {
            // Check if premature closure allowed
            $account = $this->accountModel->getById($account_id);
            
            if ($account['status'] !== 'Active') {
                return $this->errorResponse("Account is not active", 400);
            }

            // Calculate final amount
            $current_value = $this->calculateCurrentValue($account);

            // Update account status
            $this->accountModel->update($account_id, ['status' => 'Closed']);

            // Log closure transaction
            $this->logTransaction($account_id, 'Withdrawal', $current_value, 'Account Closed');

            return $this->successResponse([
                'account_id' => $account_id,
                'final_value' => $current_value,
                'message' => 'Account closed successfully'
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Calculate current account value
     */
    private function calculateCurrentValue($account) {
        if ($account['status'] === 'Matured') {
            return $this->interestCalculator->calculateMaturity(
                $account['amount_invested'],
                $account['interest_rate'],
                $account['tenure_months']
            );
        }

        // For active accounts, calculate till today
        $months_elapsed = $this->monthsBetween($account['opening_date'], date('Y-m-d'));
        $months_remaining = $account['tenure_months'] - $months_elapsed;

        if ($months_remaining <= 0) {
            return $this->interestCalculator->calculateMaturity(
                $account['amount_invested'],
                $account['interest_rate'],
                $account['tenure_months']
            );
        }

        return $this->interestCalculator->calculateMaturity(
            $account['amount_invested'],
            $account['interest_rate'],
            $months_elapsed
        );
    }

    private function monthsBetween($date1, $date2) {
        $d1 = new \DateTime($date1);
        $d2 = new \DateTime($date2);
        return ($d2->format('Y') - $d1->format('Y')) * 12 + 
               ($d2->format('m') - $d1->format('m'));
    }

    private function daysToMaturity($maturity_date) {
        $today = new \DateTime();
        $maturity = new \DateTime($maturity_date);
        return $maturity->diff($today)->days;
    }

    private function validateAccountData($data) {
        $errors = [];
        
        if (empty($data['customer_id'])) $errors[] = "Customer ID required";
        if (empty($data['scheme_id'])) $errors[] = "Scheme ID required";
        if (empty($data['amount_invested']) || $data['amount_invested'] <= 0) {
            $errors[] = "Valid amount required";
        }
        
        return $errors;
    }

    private function logTransaction($account_id, $type, $amount, $description = '') {
        // Log to po_transactions table
    }

    private function getJsonInput() {
        return json_decode(file_get_contents('php://input'), true) ?? $_POST;
    }

    private function successResponse($data, $code = 200) {
        http_response_code($code);
        return json_encode(['status' => 'success', 'data' => $data]);
    }

    private function errorResponse($message, $code = 400, $errors = null) {
        http_response_code($code);
        return json_encode([
            'status' => 'error',
            'message' => $message,
            'errors' => $errors
        ]);
    }
}
```

---

## 🔄 DATABASE QUERIES (IMPORTANT)

### **Query: Get all active accounts with interest calculation**
```sql
SELECT 
    pa.account_id,
    pa.account_number,
    ps.scheme_name,
    pa.amount_invested,
    pa.interest_rate,
    pa.opening_date,
    pa.maturity_date,
    pa.status,
    ps.scheme_type,
    ps.section_80c_eligible,
    ps.tax_treatment,
    (
        SELECT SUM(pic.interest_earned)
        FROM po_interest_calculation pic
        WHERE pic.account_id = pa.account_id
    ) as total_interest_earned,
    DATEDIFF(pa.maturity_date, CURDATE()) as days_to_maturity
FROM po_accounts pa
JOIN po_schemes ps ON pa.scheme_id = ps.scheme_id
WHERE pa.status = 'Active'
ORDER BY pa.maturity_date ASC;
```

### **Query: Monthly interest calculation for all accounts**
```sql
INSERT INTO po_interest_calculation 
(account_id, calculation_month, principal_amount, interest_rate, interest_earned, cumulative_interest)
SELECT 
    pa.account_id,
    DATE_FORMAT(CURDATE(), '%Y-%m-01'),
    pa.current_balance,
    pa.interest_rate,
    (pa.current_balance * pa.interest_rate / 100 / 12),
    (
        SELECT COALESCE(SUM(interest_earned), 0)
        FROM po_interest_calculation
        WHERE account_id = pa.account_id
    ) + (pa.current_balance * pa.interest_rate / 100 / 12)
FROM po_accounts pa
WHERE pa.status = 'Active'
ON DUPLICATE KEY UPDATE 
    interest_earned = VALUES(interest_earned),
    cumulative_interest = VALUES(cumulative_interest);
```

### **Query: Get upcoming maturity alerts (90 days)**
```sql
SELECT 
    pa.account_id,
    pa.account_number,
    ps.scheme_name,
    pa.maturity_date,
    pa.amount_invested,
    (pa.current_balance) as expected_maturity_value,
    DATEDIFF(pa.maturity_date, CURDATE()) as days_remaining,
    pma.alert_sent
FROM po_accounts pa
JOIN po_schemes ps ON pa.scheme_id = ps.scheme_id
LEFT JOIN po_maturity_alerts pma ON pa.account_id = pma.account_id 
    AND DATEDIFF(pa.maturity_date, CURDATE()) = pma.days_before
WHERE pa.status = 'Active'
    AND DATEDIFF(pa.maturity_date, CURDATE()) IN (90, 60, 30, 15)
    AND (pma.alert_sent IS NULL OR pma.alert_sent = FALSE)
ORDER BY pa.maturity_date ASC;
```

### **Query: Tax report for ITR (Annual)**
```sql
SELECT 
    pa.account_id,
    pa.account_number,
    ps.scheme_name,
    ps.section_80c_eligible,
    ps.tax_treatment,
    pa.amount_invested,
    SUM(pic.interest_earned) as total_interest,
    CASE 
        WHEN ps.tax_treatment = 'Tax-Free' THEN 0
        WHEN ps.tax_treatment = 'Partial-Free' THEN 
            GREATEST(0, (SUM(pic.interest_earned) - 10000)) * 0.30
        ELSE SUM(pic.interest_earned) * 0.30
    END as tax_payable,
    CASE WHEN ps.section_80c_eligible THEN pa.amount_invested ELSE 0 END as 80c_deductible
FROM po_accounts pa
JOIN po_schemes ps ON pa.scheme_id = ps.scheme_id
LEFT JOIN po_interest_calculation pic ON pa.account_id = pic.account_id
WHERE YEAR(pic.calculation_month) = YEAR(CURDATE()) - 1
GROUP BY pa.account_id
ORDER BY ps.section_80c_eligible DESC;
```

---

## 🎯 CRON JOBS / SCHEDULED TASKS

### **Daily Cron: Update interest calculations**
```php
<?php
// app/modules/post_office_schemes/crons/daily_interest_update.php

$db = new Database();
$calendar = new InterestCalculator();

// Calculate interest for all active accounts
$accounts = $db->queryAll("SELECT * FROM po_accounts WHERE status = 'Active'");

foreach ($accounts as $account) {
    $interest = $calendar->calculateInterestForPeriod(
        $account['current_balance'],
        $account['interest_rate'],
        date('Y-m-d', strtotime('1 day ago')),
        date('Y-m-d')
    );

    // Log interest
    $db->execute(
        "INSERT INTO po_transactions (account_id, transaction_type, amount, transaction_date) 
         VALUES (?, ?, ?, ?)",
        [$account['account_id'], 'Interest', $interest, date('Y-m-d')]
    );

    // Update current balance
    $new_balance = $account['current_balance'] + $interest;
    $db->execute(
        "UPDATE po_accounts SET current_balance = ? WHERE account_id = ?",
        [$new_balance, $account['account_id']]
    );
}

echo "✅ Daily interest calculation complete";
```

### **Weekly Cron: Send maturity alerts**
```php
<?php
// app/modules/post_office_schemes/crons/weekly_maturity_alerts.php

$db = new Database();
$emailService = new EmailService();

// Find accounts maturing in 90, 60, 30, 15 days
$alerts_to_send = $db->queryAll("
    SELECT pa.*, ps.scheme_name, c.customer_email
    FROM po_accounts pa
    JOIN po_schemes ps ON pa.scheme_id = ps.scheme_id
    JOIN customers c ON pa.customer_id = c.customer_id
    JOIN po_maturity_alerts pma ON pa.account_id = pma.account_id
    WHERE pma.alert_sent = FALSE 
        AND DATEDIFF(pa.maturity_date, CURDATE()) IN (90, 60, 30, 15)
");

foreach ($alerts_to_send as $alert) {
    $emailService->sendMaturityAlert($alert['customer_email'], $alert);
    
    // Mark as sent
    $db->execute(
        "UPDATE po_maturity_alerts SET alert_sent = TRUE, alert_sent_date = NOW() 
         WHERE account_id = ? AND days_before = ?",
        [$alert['account_id'], $alert['days_before']]
    );
}

echo "✅ Maturity alerts sent: " . count($alerts_to_send);
```

---

## 📊 SAMPLE API RESPONSES

### **Portfolio Overview Response**
```json
{
    "status": "success",
    "data": {
        "portfolio_summary": {
            "total_invested": 3000000,
            "total_current_value": 3982000,
            "total_gains": 982000,
            "average_growth_rate": 7.2,
            "total_accounts": 4,
            "active_accounts": 4,
            "matured_accounts": 0
        },
        "scheme_breakdown": {
            "PPF": {
                "amount_invested": 1500000,
                "current_value": 2250000,
                "gains": 750000,
                "percentage_of_portfolio": 56
            },
            "Sukanya Samriddhi": {
                "amount_invested": 800000,
                "current_value": 984000,
                "gains": 184000,
                "percentage_of_portfolio": 25
            },
            "MIS": {
                "amount_invested": 200000,
                "current_value": 235000,
                "gains": 35000,
                "percentage_of_portfolio": 6
            },
            "NSC": {
                "amount_invested": 500000,
                "current_value": 513000,
                "gains": 13000,
                "percentage_of_portfolio": 13
            }
        },
        "tax_benefits": {
            "annual_section_80c": 9000,
            "annual_tax_saved": 27000,
            "interest_tax_free_amount": 500000
        },
        "maturity_schedule": [
            {
                "scheme_name": "PPF",
                "maturity_date": "2041-03-15",
                "days_to_maturity": 5475,
                "expected_value": 6250000
            }
        ]
    }
}
```

---

## 🚀 MIGRATION FILE (Setup)

```php
<?php
// app/modules/post_office_schemes/migrations/001_create_schemes_table.php

class CreateSchemesTable {
    public static function up($db) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS po_schemes (
                scheme_id INT PRIMARY KEY AUTO_INCREMENT,
                scheme_name VARCHAR(100) NOT NULL,
                scheme_type ENUM('SB','RD','TD','MIS','PPF','Sukanya','NSC','KVP','MSSC','SCSS'),
                min_amount DECIMAL(12, 2),
                max_amount DECIMAL(12, 2),
                tenure_months INT,
                current_interest_rate DECIMAL(5, 2),
                tax_treatment ENUM('Taxable', 'Tax-Free', 'Partial-Free'),
                section_80c_eligible BOOLEAN DEFAULT FALSE,
                liquidity_status ENUM('Liquid', 'Restricted', 'None'),
                withdrawal_before_maturity BOOLEAN DEFAULT FALSE,
                description TEXT,
                eligibility_criteria TEXT,
                key_features JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Insert scheme data
        self::seedSchemes($db);
    }

    private static function seedSchemes($db) {
        $schemes = [
            [
                'scheme_name' => 'Public Provident Fund',
                'scheme_type' => 'PPF',
                'min_amount' => 500,
                'max_amount' => 1500000,
                'tenure_months' => 180,
                'current_interest_rate' => 7.1,
                'tax_treatment' => 'Tax-Free',
                'section_80c_eligible' => true,
                'liquidity_status' => 'Restricted',
                'withdrawal_before_maturity' => true
            ],
            // ... more schemes
        ];

        foreach ($schemes as $scheme) {
            $db->execute(
                "INSERT INTO po_schemes (scheme_name, scheme_type, min_amount, max_amount, 
                 tenure_months, current_interest_rate, tax_treatment, section_80c_eligible, 
                 liquidity_status, withdrawal_before_maturity) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $scheme['scheme_name'], $scheme['scheme_type'], $scheme['min_amount'],
                    $scheme['max_amount'], $scheme['tenure_months'], 
                    $scheme['current_interest_rate'], $scheme['tax_treatment'],
                    $scheme['section_80c_eligible'], $scheme['liquidity_status'],
                    $scheme['withdrawal_before_maturity']
                ]
            );
        }
    }
}
```

---

## ✅ IMPLEMENTATION CHECKLIST

- [ ] **Database Setup**
  - [ ] Create all 9 tables
  - [ ] Setup foreign keys & indexes
  - [ ] Load initial scheme data

- [ ] **Core Services**
  - [ ] SchemeComparison
  - [ ] RecommendationEngine
  - [ ] InterestCalculator
  - [ ] TaxOptimizer
  - [ ] GoalPlanner
  - [ ] SchemeSwitcher

- [ ] **API Endpoints**
  - [ ] Schemes CRUD
  - [ ] Accounts CRUD
  - [ ] Comparison API
  - [ ] Recommendation API
  - [ ] Reports API
  - [ ] Tax API

- [ ] **Frontend Components**
  - [ ] Dashboard
  - [ ] Comparison Tool
  - [ ] Recommendation Widget
  - [ ] Portfolio View
  - [ ] Reports

- [ ] **Cron Jobs**
  - [ ] Daily interest update
  - [ ] Weekly maturity alerts
  - [ ] Monthly tax tracking

- [ ] **Testing**
  - [ ] Unit tests
  - [ ] Integration tests
  - [ ] API tests

---

## 🔗 ROUTING SETUP

```php
<?php
// public/api.php

$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

$routes = [
    // Schemes
    'GET|/api/v1/post-office/schemes' => 'SchemeController@getAll',
    'GET|/api/v1/post-office/schemes/:id' => 'SchemeController@getOne',
    
    // Accounts
    'GET|/api/v1/post-office/accounts' => 'AccountController@getCustomerAccounts',
    'POST|/api/v1/post-office/accounts' => 'AccountController@createAccount',
    'GET|/api/v1/post-office/accounts/:id' => 'AccountController@getAccountDetails',
    
    // Comparison
    'POST|/api/v1/post-office/compare' => 'ReportController@compareSchemes',
    
    // Recommendations
    'POST|/api/v1/post-office/recommend' => 'RecommendationController@getRecommendation',
    
    // Tax
    'POST|/api/v1/post-office/tax/80c-calculator' => 'TaxController@calculate80C',
    
    // Maturity
    'GET|/api/v1/post-office/maturity/upcoming' => 'MaturityController@getUpcoming',
];

foreach ($routes as $route => $handler) {
    if (matchRoute($route, $request_uri, $request_method)) {
        list($controller, $method) = explode('@', $handler);
        callController($controller, $method);
        exit;
    }
}

// 404
http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Route not found']);
```

---

**Ready to implement! Let me know if you need:**
- ✅ Complete test cases
- ✅ Frontend React/Vue components
- ✅ More detailed business logic
- ✅ Payment integration
- ✅ Email notification templates


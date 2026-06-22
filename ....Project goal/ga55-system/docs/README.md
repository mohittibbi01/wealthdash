# GA-55A Salary Management System

## Setup Instructions

1. Yeh poora folder `C:\xampp\htdocs\ga55-system\` me copy karo
2. XAMPP me Apache aur MySQL start karo
3. phpMyAdmin kholo → SQL tab → `database/` folder ki files ek ek karke run karo:
   - 001_create_database_and_users.sql
   - 002_create_employees_table.sql
   - 003_create_salary_columns_table.sql
   - 004_create_salary_bills_table.sql
   - 005_create_bill_values_table.sql
   - 006_create_temp_import_table.sql
4. Browser me kholo: `http://localhost/ga55-system`
5. Default login: username=`admin`, password=`Admin@123`

## Folder Structure

```
ga55-system/
├── database/         ← SQL files (numbered sequence)
├── includes/         ← Common PHP files (db, auth, functions)
├── pages/            ← All HTML/PHP pages (numbered)
├── api/              ← AJAX endpoints
├── assets/
│   ├── css/          ← CSS files (numbered)
│   ├── js/           ← JS files (numbered)
│   └── uploads/      ← CSV upload temp folder
├── docs/             ← Documentation
├── config.php        ← DB settings (sirf yahan badlo)
└── index.php         ← Entry point
```

## Dusre PC pe le jaane ka tarika

1. Poora `ga55-system/` folder copy karo
2. `database/` folder ki saari SQL files naye PC me bhi run karo
3. `config.php` me agar DB settings alag hain to wahan badlo
4. Done!

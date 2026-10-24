# Jenny & Ding Sari-Sari Store Inventory Management System

A web-based inventory management system for **Jenny & Ding Sari-Sari Store** (15 Sapphire St., Brgy. San Agustin, Quezon City).
Capstone project for **BSCPE 41003 – Elective 3: Software Development**, Bestlink College of the Philippines.

## Features
- **Login and user roles** – Admin (store owner) and Staff; new sign-ups need the owner's approval
- **Products and categories** – 137 products in 8 categories, with prices, stock, reorder level and pictures
- **Stock In / Stock Out** – deliveries and items leaving the store (sold, expired, damaged, etc.); stock never goes below zero
- **Low-stock alerts** on the dashboard
- **Stock history** – every movement with date, user, quantity and value
- **Reports** – sales per day, estimated profit, best sellers, losses; print and Excel (CSV) export
- **Customer credit (utang)** – balances, credit limits, payments and history
- **Backup** – download the whole database as a `.sql` file

## Built with
HTML, CSS, JavaScript · PHP 8 (object-oriented) · MySQL · XAMPP (Apache + MySQL)

## Object-oriented design
| Class | Role |
|---|---|
| `User` (abstract) | Common data and behavior of all accounts |
| `Administrator` extends `User` | Full access: users, deleting records, backup |
| `Staff` extends `User` | Manages stock, cannot manage users or delete records |
| `Product` | Stock checks: `isLowStock()`, `hasEnough()`, `stockValue()` |
| `Customer` | Credit checks: `getBalance()`, `canCharge()`, `canPay()` |

## How to run (XAMPP)
1. Install XAMPP and start **Apache** and **MySQL**.
2. Copy this folder into `C:\xampp\htdocs\` and name it `jennyding`.
3. Open **http://localhost/phpmyadmin**, click **Import**, and import `database.sql`.
   (Optional: also import `demo_data.sql` for two weeks of sample activity.)
4. Open **http://localhost/jennyding** and log in:
   - Username: `admin`
   - Password: `admin123` (change it after the first login)

## Development method
Built using the **Sprint method** in eight sprints: foundation → products → categories → stock in → stock out → reports → user management → customer credit and backup.

## Developers
- Noces, Arth Joshua S.
- Lucañas, Jerimy O.
- Egang, Fritz
- De Vera, Kiel
- Pablo, Marvin
- Cataluña, Daverelle
- Codilla, Mark James

Adviser: **Dr. Ronaldo S. Tinio**, Associate Professor III

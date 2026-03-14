# LCRC eReview – Step-by-Step Local Setup Guide

This document explains how to run the **LCRC eReview** system (PHP + MySQL) on your local machine (localhost).

---

## What This System Is

- **LCRC eReview**: A CPA review LMS with:
  - **Public**: Landing page, registration (with payment proof upload), login.
  - **Students**: Dashboard, subjects, lessons, videos, handouts, quizzes (after admin approval).
  - **Admins**: Dashboard, student approval, subjects, lessons, videos, handouts, quizzes, payment proof viewer.
- **Tech stack**: PHP (with `mysqli`), MySQL/MariaDB, Bootstrap (CDN). No Node.js or Composer required.
- **Optional**: An **Inventory** sub-application under `/inventory/` (separate database).

---

## Prerequisites

1. **PHP** (7.4 or 8.x) with:
   - `mysqli` extension
   - `session` support  
   (Default XAMPP/WAMP/Laragon PHP builds include these.)

2. **MySQL** or **MariaDB** (e.g. from XAMPP, WAMP, Laragon, or standalone).

3. **Web server** (one of):
   - **XAMPP** (Apache + MySQL + PHP), or  
   - **WAMP**, or  
   - **Laragon**, or  
   - **PHP built-in server** (for quick testing).

---

## Step 1: Install a PHP + MySQL Environment

- **Windows**: Install [XAMPP](https://www.apachefriends.org/) or [Laragon](https://laragon.org/).
- **macOS**: You can use MAMP, Laragon, or Homebrew PHP + MySQL.
- **Linux**: Install `php`, `php-mysqli`, and `mysql-server` (or `mariadb-server`) from your package manager.

Ensure:
- MySQL/MariaDB service is **running**.
- PHP is in your PATH if you plan to use the built-in server.

---

## Step 2: Place the Project in the Web Root

- **XAMPP**: Copy the `Ereview` folder to `C:\xampp\htdocs\Ereview` (or `htdocs` on your install).
- **WAMP**: Copy to `C:\wamp64\www\Ereview` (or your `www` path).
- **Laragon**: Copy to `C:\laragon\www\Ereview` (or your `www` path).
- **PHP built-in server**: You can run from any folder (see Step 6).

So your project root should contain files like:
`index.php`, `db.php`, `auth.php`, `admin_dashboard.php`, `student_dashboard.php`, etc.

---

## Step 3: Create the MySQL Database and Import Schema

1. Open **phpMyAdmin** (e.g. `http://localhost/phpmyadmin`) or any MySQL client.
2. Create a database named **`ereview`** (if it does not exist).
3. Select the **`ereview`** database.
4. Import the **complete** schema and seed data:
   - Open **`ereview_complete.sql`** from the project root in a text editor.
   - Copy its entire content and run it in phpMyAdmin’s **SQL** tab,  
     **or** use the **Import** tab and choose `ereview_complete.sql`.

This file:

- Creates the `ereview` database (if not exists) and uses it.
- Creates all tables: `users`, `subjects`, `lessons`, `lesson_videos`, `lesson_handouts`, `handout_annotations`, `quizzes`, `quiz_questions`, `quiz_answers`.
- Adds performance indexes.
- Inserts **default admin** and sample data.

**Default admin (from the SQL file):**

- **Email:** `admin@ereview.ph`  
- **Password:** `admin123`  

*(Login accepts both hashed and plain-text passwords for compatibility with this seed.)*

---

## Step 4: Configure Database Connection

1. Open **`db.php`** in the project root.
2. Set your local MySQL credentials:

```php
$host = "localhost";
$user = "root";   // your MySQL username
$pass = "";       // your MySQL password (empty for default XAMPP)
$db   = "ereview";
```

Save the file. If MySQL has a password, set `$pass` accordingly.

---

## Step 5: Create Upload Folders (Recommended)

The app can create these on first use, but creating them upfront avoids permission issues:

In the **project root** (same folder as `index.php`), create:

- `uploads`
- `uploads/handouts`
- `uploads/videos`

**Windows (PowerShell, from project root):**

```powershell
New-Item -ItemType Directory -Force -Path "uploads", "uploads\handouts", "uploads\videos"
```

**Linux/macOS:**

```bash
mkdir -p uploads uploads/handouts uploads/videos
```

Ensure the web server (or PHP process) can write to these folders.

---

## Step 6: Run the Application

### Option A: Using XAMPP / WAMP / Laragon (Apache)

1. Start **Apache** and **MySQL** from the control panel.
2. Open a browser and go to:
   - **XAMPP**: `http://localhost/Ereview/`
   - **WAMP**: `http://localhost/Ereview/`
   - **Laragon**: `http://ereview.test/` (if you use Laragon’s “Auto” virtual host) or `http://localhost/Ereview/`

The landing page (`index.php`) should load with **Login** and **Register** buttons.

### Option B: Using PHP Built-in Server

1. Open a terminal in the **project root** (where `index.php` is).
2. Run:

```bash
php -S localhost:8000
```

3. In the browser go to: **`http://localhost:8000`**

You should see the same landing page.  
*(Stop the server with Ctrl+C when done.)*

---

## Step 7: Verify It Works

1. **Landing page**: You should see “LCRC eReview” with Login and Register.
2. **Login as admin**:
   - Click **Login**.
   - Email: `admin@ereview.ph`, Password: `admin123`.
   - You should be redirected to **Admin Dashboard** (`admin_dashboard.php`).
3. **Register a student**:
   - Click **Register**, fill the form, upload a payment proof (image or PDF).
   - Submit; then an admin can approve the student from the admin panel.
4. **Student login**: After approval, log in with that student’s email/password; you should see the **Student Dashboard**.

---

## Optional: Inventory Module

The **`inventory/`** folder is a separate “Office Supplies Inventory” app:

- It uses its **own** database: **`inventory_db`**.
- Config: **`inventory/config.php`** (host, user, pass, db name).
- To run it:
  1. Create database **`inventory_db`** in MySQL.
  2. Run **`inventory/sql/schema.sql`** in that database.
  3. Open **`http://localhost/Ereview/inventory/`** (or the same path with PHP built-in server).

---

## Troubleshooting

| Issue | What to check |
|-------|----------------|
| **Blank page or 500 error** | Enable errors: in `index.php` or `db.php` add at the top (temporarily): `ini_set('display_errors', 1); error_reporting(E_ALL);` Then check PHP/MySQL errors. |
| **“Connection failed”** | MySQL is running; `db.php` has correct `$host`, `$user`, `$pass`, `$db`; database `ereview` exists and schema was imported. |
| **“Database connection failed” in inventory** | Create `inventory_db` and run `inventory/sql/schema.sql`; check `inventory/config.php`. |
| **Upload fails (payment proof / handouts / videos)** | `uploads`, `uploads/handouts`, `uploads/videos` exist and are writable by the web server/PHP. |
| **Login does nothing or wrong redirect** | Confirm you imported **`ereview_complete.sql`** (so `users` table and admin user exist). Clear browser cache/cookies and try again. |
| **Session / redirect issues** | Ensure no output before `session_start()` (no BOM or spaces before `<?php` in included files). |

---

## Summary Checklist

- [ ] PHP + MySQL (XAMPP/WAMP/Laragon or equivalent) installed and running.
- [ ] Project copied to web root (or you run PHP built-in server from project root).
- [ ] Database **`ereview`** created and **`ereview_complete.sql`** imported.
- [ ] **`db.php`** updated with correct MySQL user/password.
- [ ] Folders **`uploads`**, **`uploads/handouts`**, **`uploads/videos`** created and writable.
- [ ] Open **`http://localhost/Ereview/`** (or `http://localhost:8000` with built-in server).
- [ ] Login as **admin@ereview.ph** / **admin123** and confirm Admin Dashboard loads.

After that, the system is running on your local environment (localhost).

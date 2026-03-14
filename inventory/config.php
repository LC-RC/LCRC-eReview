<?php
// Office Supplies Inventory - Database config
// Uses separate database: inventory_db (create via sql/schema.sql)
$host = "localhost";
$user = "root";
$pass = "";
$db   = "inventory_db";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Database connection failed. Make sure you ran sql/schema.sql in phpMyAdmin.");
}

mysqli_set_charset($conn, "utf8");

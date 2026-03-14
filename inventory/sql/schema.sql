-- Office Supplies Inventory System - Database Schema
-- Run this in phpMyAdmin or MySQL to create the database and tables

CREATE DATABASE IF NOT EXISTS inventory_db;
USE inventory_db;

-- Categories (e.g. Writing Instruments, Paper, Filing Supplies)
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products (office supplies items)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    sku VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
    cost_price DECIMAL(12,2) DEFAULT 0.00,
    selling_price DECIMAL(12,2) DEFAULT 0.00,
    quantity INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
);

-- Stock movement log (Stock In, Stock Out, Adjustment)
CREATE TABLE stock_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type ENUM('in', 'out', 'adjust') NOT NULL,
    quantity INT NOT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Optional: default categories for office supplies
INSERT INTO categories (name, description) VALUES
('Writing Instruments', 'Pens, pencils, markers, highlighters'),
('Paper & Notebooks', 'Bond paper, notebooks, pads, sticky notes'),
('Filing & Organization', 'Folders, binders, envelopes, clips'),
('Desk Supplies', 'Staplers, tape, scissors, hole punch'),
('Cleaning & Breakroom', 'Tissue, soap, trash bags');

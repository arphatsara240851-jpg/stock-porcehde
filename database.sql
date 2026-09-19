-- =================================================================
-- โครงสร้างฐานข้อมูล: sirinath_inventory
-- โครงการ: ระบบรายงานนับสต็อกสินค้า หจก.สิรณัฐการค้า
-- ภาษา: MySQL (รองรับ phpMyAdmin / XAMPP / MariaDB)
-- =================================================================

-- 1. สร้างฐานข้อมูลและเลือกใช้งาน
CREATE DATABASE IF NOT EXISTS `sirinath_inventory` 
DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `sirinath_inventory`;

-- 2. สร้างตาราง users (ผู้ใช้งานระบบ)
DROP TABLE IF EXISTS `stock_counts`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `fullname` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่มข้อมูลบัญชีผู้ใช้เริ่มต้น
-- แอดมิน: a11 / 1234aa
-- พนักงาน: p11 / pa12345
INSERT INTO `users` (`username`, `password`, `fullname`, `role`) VALUES
('a11', '1234aa', 'ผู้ดูแลระบบ', 'admin'),
('p11', 'pa12345', 'พนักงานนับสต็อก', 'staff');

-- 3. สร้างตาราง products (รายการสินค้า)
CREATE TABLE `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_code` VARCHAR(50) NOT NULL UNIQUE,
  `product_name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `system_qty` INT NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่มข้อมูลสินค้าเริ่มต้น 5 รายการ
INSERT INTO `products` (`product_code`, `product_name`, `category`, `system_qty`) VALUES
('P-001', 'สายฉีดชำระสแตนเลส 304', 'หมวดอุปกรณ์ห้องน้ำ', 45),
('P-002', 'ก๊อกน้ำอ่างล้างหน้าเซรามิกวาล์ว', 'หมวดอุปกรณ์ห้องน้ำ', 30),
('P-003', 'สีสเปรย์อเนกประสงค์ สีดำเงา', 'หมวดเคมีภัณฑ์และสี', 120),
('P-004', 'สกรูเกลียวปล่อย 1 นิ้ว', 'หมวดฮาร์ดแวร์และสกรู', 200),
('P-005', 'แปรงทาสี ขนเคมี 2 นิ้ว', 'หมวดอุปกรณ์ทาสี', 85);

-- 4. สร้างตาราง stock_counts (ประวัติการนับสต็อกโดยพนักงาน)
CREATE TABLE `stock_counts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `staff_id` INT NOT NULL,
  `counted_qty` INT NOT NULL,
  `status` ENUM('pending', 'matched', 'mismatch') NOT NULL DEFAULT 'pending',
  `discrepancy_note` TEXT NULL,
  `counted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_counts_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_counts_user` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. สร้างตาราง stock_movements (ประวัติการเคลื่อนไหวสต็อก)
CREATE TABLE `stock_movements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `type` ENUM('in', 'out', 'adjust') NOT NULL,
  `qty` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_movements_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ข้อมูลตัวอย่างเริ่มต้นสำหรับการทดสอบรายงาน
INSERT INTO `stock_movements` (`product_id`, `type`, `qty`, `created_at`) VALUES
(1, 'in', 45, NOW() - INTERVAL 2 DAY),
(2, 'in', 30, NOW() - INTERVAL 2 DAY),
(3, 'in', 120, NOW() - INTERVAL 2 DAY),
(4, 'in', 200, NOW() - INTERVAL 2 DAY),
(5, 'in', 85, NOW() - INTERVAL 2 DAY);

INSERT INTO `stock_counts` (`product_id`, `staff_id`, `counted_qty`, `status`, `discrepancy_note`, `counted_at`) VALUES
(1, 2, 45, 'matched', 'ยอดตรงตามระบบ', NOW() - INTERVAL 1 DAY),
(3, 2, 115, 'mismatch', 'ยอดในระบบคือ 120 ขาดไป 5 ชิ้น กรุณานับซ้ำ', NOW() - INTERVAL 3 HOUR);

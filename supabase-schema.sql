-- ==============================================================================
-- Intelligent Stock Management System - หจก.สิรณัฐการค้า
-- Database Schema for Supabase (PostgreSQL) + Realtime Replication
-- ==============================================================================

-- 1. ตารางผู้ใช้งาน (users)
CREATE TABLE IF NOT EXISTS public.users (
  id BIGSERIAL PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  fullname VARCHAR(255) NOT NULL,
  nickname VARCHAR(100) DEFAULT '',
  role VARCHAR(20) NOT NULL DEFAULT 'staff' CHECK (role IN ('admin', 'staff')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 2. ตารางสินค้า (products)
CREATE TABLE IF NOT EXISTS public.products (
  id BIGSERIAL PRIMARY KEY,
  product_code VARCHAR(50) UNIQUE NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NOT NULL,
  system_qty INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 3. ตารางการตรวจนับสต็อก (stock_counts)
CREATE TABLE IF NOT EXISTS public.stock_counts (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT REFERENCES public.products(id) ON DELETE CASCADE,
  staff_id BIGINT REFERENCES public.users(id) ON DELETE SET NULL,
  staff_name VARCHAR(255),
  counted_qty INT NOT NULL,
  system_qty_at_count INT DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'matched', 'mismatch')),
  discrepancy_note TEXT DEFAULT '',
  counted_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 4. ตารางความเคลื่อนไหวสินค้า (stock_movements)
CREATE TABLE IF NOT EXISTS public.stock_movements (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT REFERENCES public.products(id) ON DELETE CASCADE,
  type VARCHAR(20) NOT NULL CHECK (type IN ('in', 'out', 'adjust')),
  qty INT NOT NULL,
  note TEXT DEFAULT '',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ==============================================================================
-- 5. ข้อมูลตั้งต้น (Initial Seed Data)
-- ==============================================================================

-- เพิ่มผู้ใช้งานเริ่มต้น (แอดมิน และ พนักงาน)
INSERT INTO public.users (id, username, password, fullname, nickname, role) VALUES
(1, 'a11', '1234aa', 'ผู้ดูแลระบบ', 'แอดมิน', 'admin'),
(2, 'p11', 'pa12345', 'พนักงานนับสต็อก', 'พนักงาน', 'staff')
ON CONFLICT (username) DO NOTHING;

-- เพิ่มสินค้าเริ่มต้น
INSERT INTO public.products (id, product_code, product_name, category, system_qty) VALUES
(1, 'P-001', 'สายฉีดชำระสแตนเลส 304', 'หมวดอุปกรณ์ห้องน้ำ', 45),
(2, 'P-002', 'ก๊อกน้ำอ่างล้างหน้าเซรามิกวาล์ว', 'หมวดอุปกรณ์ห้องน้ำ', 30),
(3, 'P-003', 'สีสเปรย์อเนกประสงค์ สีดำเงา', 'หมวดเคมีภัณฑ์และสี', 120),
(4, 'P-004', 'สกรูเกลียวปล่อย 1 นิ้ว', 'หมวดฮาร์ดแวร์และสกรู', 200),
(5, 'P-005', 'แปรงทาสี ขนเคมี 2 นิ้ว', 'หมวดอุปกรณ์ทาสี', 85)
ON CONFLICT (product_code) DO NOTHING;

-- ปรับ sequence ID ให้ถูกต้อง
SELECT setval('public.users_id_seq', (SELECT MAX(id) FROM public.users));
SELECT setval('public.products_id_seq', (SELECT MAX(id) FROM public.products));

-- ==============================================================================
-- 6. นโยบายความปลอดภัย Row Level Security (RLS)
-- อนุญาตให้เว็บแอปพลิเคชันเชื่อมต่อและอ่าน/เขียนข้อมูลได้
-- ==============================================================================

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.products ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.stock_counts ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.stock_movements ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Allow public read-write on users" ON public.users FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Allow public read-write on products" ON public.products FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Allow public read-write on stock_counts" ON public.stock_counts FOR ALL USING (true) WITH CHECK (true);
CREATE POLICY "Allow public read-write on stock_movements" ON public.stock_movements FOR ALL USING (true) WITH CHECK (true);

-- ==============================================================================
-- 7. **คำสั่งสำคัญมาก**: เปิดการซิงค์ข้อมูล Realtime สำหรับ Supabase
-- ทำให้เมื่อมีการบันทึกสต็อก หน้าจอ Dashboard จะอัปเดตทันทีโดยไม่ต้อง Refresh
-- ==============================================================================

ALTER PUBLICATION supabase_realtime ADD TABLE public.products;
ALTER PUBLICATION supabase_realtime ADD TABLE public.stock_counts;
ALTER PUBLICATION supabase_realtime ADD TABLE public.stock_movements;
ALTER PUBLICATION supabase_realtime ADD TABLE public.users;

# คู่มือการนำระบบ Intelligent Stock ไปใช้งานบน Vercel และเชื่อมต่อ Supabase Realtime

ระบบจัดการและตรวจนับคลังสินค้า (Intelligent Stock - หจก.สิรณัฐการค้า) พร้อมไฟล์โค้ดเต็ม (Full Code) และคำสั่งฐานข้อมูล SQL สามารถนำไป Deploy บน **Vercel** ได้ทันที

---

## 📂 โครงสร้างไฟล์สำหรับ Vercel

| ชื่อไฟล์ | คำอธิบาย |
| :--- | :--- |
| **`stock-count.html`** | หน้าตรวจนับสต็อกสินค้าสำหรับพนักงาน พร้อมปุ่ม **"บันทึกและส่งผลการตรวจนับ"** ส่งจำนวนจริงและสาเหตุไปอัปเดตสต็อกในฐานข้อมูลทันทีแบบ Realtime |
| **`dashboard.html`** | หน้าภาพรวมและจัดการสต็อกสินค้า (Dashboard) อัปเดตตัวเลขล่าสุดทันทีเมื่อพนักงานบันทึก โดยไม่ต้องกด Refresh |
| **`login.html`** | หน้าระบบยืนยันตัวตน (เข้าสู่ระบบ / สมัครสมาชิก) พร้อมจดจำ Session ในเบราว์เซอร์ เข้าใช้งานวันถัดไปได้ทันที |
| **`supabase-schema.sql`** | สคริปต์ SQL สร้างตาราง `products`, `stock_counts`, `stock_movements`, `users` พร้อมคำสั่งเปิด Supabase Realtime Replication |
| **`vercel.json`** | ไฟล์ตั้งค่า Routing สำหรับ Vercel |

---

## 🚀 ขั้นตอนที่ 1: ตั้งค่าฐานข้อมูล Supabase (2 นาที)

1. เข้าเว็บไซต์ [https://supabase.com](https://supabase.com) และสร้างโปรเจกต์ใหม่ (ฟรี)
2. ไปที่เมนู **SQL Editor** (รูปไอคอน `>_` ด้านซ้าย)
3. คัดลอกโค้ดทั้งหมดจากไฟล์ **`supabase-schema.sql`** มาวาง แล้วกดปุ่ม **RUN**
4. ระบบจะสร้างตารางและเปิดใช้งาน Realtime Publication ให้อัตโนมัติ:
   ```sql
   ALTER PUBLICATION supabase_realtime ADD TABLE public.products;
   ALTER PUBLICATION supabase_realtime ADD TABLE public.stock_counts;
   ALTER PUBLICATION supabase_realtime ADD TABLE public.stock_movements;
   ALTER PUBLICATION supabase_realtime ADD TABLE public.users;
   ```
5. ไปที่ **Project Settings** > **API** เพื่อคัดลอก:
   - **Project URL** (เช่น `https://xxxx.supabase.co`)
   - **anon public key** (เช่น `eyJhbGci...`)

---

## 🚀 ขั้นตอนที่ 2: นำขึ้น Vercel (Deploy to Vercel)

### วิธีที่ 1: Deploy ผ่าน Vercel CLI (ง่ายและรวดเร็ว)
1. เปิด Terminal ในโฟลเดอร์โปรเจกต์
2. ติดตั้งและสั่ง deploy:
   ```bash
   npm i -g vercel
   vercel
   ```
3. ทำตามคำแนะนำบนหน้าจอ จากนั้นจะได้ลิงก์เว็บไซต์พร้อมใช้งานทันที (เช่น `https://your-stock.vercel.app`)

### วิธีที่ 2: Deploy ผ่าน GitHub & Vercel Dashboard
1. นำโฟลเดอร์นี้ขึ้น GitHub Repository ของคุณ
2. เข้าสู่ระบบ [https://vercel.com](https://vercel.com) แล้วกด **Add New... > Project**
3. เลือก Repository ที่คุณอัปโหลดไว้ แล้วกด **Deploy** ได้ทันที

---

## ⚡ ขั้นตอนที่ 3: เปิดใช้งานและทดสอบระบบ Realtime

1. เปิดหน้า **`https://your-site.vercel.app/stock-count.html`** (หรือกดปุ่ม "ตั้งค่า Supabase" บนมุมขวาบน)
2. วาง **Supabase URL** และ **Anon Key** ที่ได้จากขั้นตอนที่ 1 แล้วกด **บันทึกการตั้งค่า**
3. เปิดหน้า **`dashboard.html`** ในอีกหน้าจอหรืออีกอุปกรณ์หนึ่ง
4. ในหน้า **`stock-count.html`** กรอกจำนวนที่นับได้จริงและสาเหตุ แล้วกด **"บันทึกและส่งผลการตรวจนับ"**
5. **ผลลัพธ์:** หน้า **`dashboard.html`** จะอัปเดตตัวเลขสต็อกสินค้าล่าสุดขึ้นมาทันที มีเสียงแจ้งเตือนและแบนเนอร์แจ้งเตือนแบบ Realtime โดยไม่ต้องกด Refresh หน้าเว็บ!

---

## 👤 บัญชีผู้ใช้งานเริ่มต้น
- **พนักงาน (Staff):** Username: `p11` / Password: `pa12345`
- **ผู้ดูแลระบบ (Admin):** Username: `a11` / Password: `1234aa`
- สามารถกดสมัครสมาชิกบัญชีใหม่ได้ที่หน้า `login.html` ระบบจะบันทึก Session ไว้ในเครื่องให้อัตโนมัติ

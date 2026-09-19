import express from 'express';
import path from 'path';
import { createServer as createViteServer } from 'vite';
import {
  INITIAL_PRODUCTS,
  INITIAL_STOCK_COUNTS,
  INITIAL_MOVEMENTS,
  INITIAL_USERS,
} from './src/data/initialData';
import { Product, StockCount, StockMovement, User } from './src/types';
import { formatLocalDateTime } from './src/utils/dateUtils';

interface SSEClient {
  id: number;
  res: express.Response;
}

// In-memory Server State (Central source of truth across all clients & devices)
let serverProducts: Product[] = JSON.parse(JSON.stringify(INITIAL_PRODUCTS));
let serverCounts: StockCount[] = JSON.parse(JSON.stringify(INITIAL_STOCK_COUNTS));
let serverMovements: StockMovement[] = JSON.parse(JSON.stringify(INITIAL_MOVEMENTS));
let serverUsers: User[] = JSON.parse(JSON.stringify(INITIAL_USERS));

let sseClients: SSEClient[] = [];
let nextClientId = 1;

// Broadcast helper for Server-Sent Events (SSE)
function broadcastSSE(event: { type: string; payload?: unknown }) {
  const data = `data: ${JSON.stringify(event)}\n\n`;
  sseClients.forEach((client) => {
    try {
      client.res.write(data);
    } catch {
      // Disconnected client will be pruned
    }
  });
}

async function startServer() {
  const app = express();
  const PORT = 3000;

  app.use(express.json());

  // -------------------------------------------------------------
  // API Routes (Always declared FIRST before Vite/static middlewares)
  // -------------------------------------------------------------

  // Health check
  app.get('/api/health', (req, res) => {
    res.json({ status: 'ok', clientsConnected: sseClients.length, timestamp: new Date().toISOString() });
  });

  // Get current global state
  app.get('/api/state', (req, res) => {
    res.json({
      products: serverProducts,
      counts: serverCounts,
      movements: serverMovements,
      users: serverUsers,
    });
  });

  // Real-time SSE Stream Endpoint
  app.get('/api/events', (req, res) => {
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache, no-transform',
      'Connection': 'keep-alive',
      'X-Accel-Buffering': 'no',
    });

    // Send initial connection handshake
    res.write(`data: ${JSON.stringify({ type: 'CONNECTED', clientId: nextClientId })}\n\n`);

    const clientId = nextClientId++;
    const newClient: SSEClient = { id: clientId, res };
    sseClients.push(newClient);

    req.on('close', () => {
      sseClients = sseClients.filter((c) => c.id !== clientId);
    });
  });

  // Staff: Submit real-time count & permanent stock update
  app.post('/api/counts', (req, res) => {
    const { productId, countedQty, staffId, staffName, staffNickname, reason, updateStockImmediately } = req.body;
    const prod = serverProducts.find((p) => p.id === Number(productId));

    if (!prod) {
      res.status(404).json({ error: 'Product not found' });
      return;
    }

    const oldQty = prod.system_qty;
    const newQty = Number(countedQty);
    const diff = newQty - oldQty;
    const isMatched = oldQty === newQty;
    const note = (reason && reason.trim()) ? reason.trim() : (isMatched ? 'ยอดตรวจตรงตามระบบ' : `ตรวจนับได้ ${newQty} ชิ้น (ผลต่าง ${diff > 0 ? '+' : ''}${diff})`);

    const newCount: StockCount = {
      id: Date.now(),
      product_id: prod.id,
      product_code: prod.product_code,
      product_name: prod.product_name,
      category: prod.category,
      staff_id: Number(staffId) || 0,
      staff_name: staffName || 'พนักงาน',
      staff_nickname: staffNickname || staffName || 'พนักงาน',
      counted_qty: newQty,
      system_qty_at_count: oldQty,
      status: isMatched ? 'matched' : (reason ? 'mismatch' : 'pending'),
      discrepancy_note: note,
      counted_at: req.body.counted_at || formatLocalDateTime(),
    };

    serverCounts = [newCount, ...serverCounts];

    // If stock update is requested (default enabled)
    let movement: StockMovement | null = null;
    if (updateStockImmediately !== false) {
      prod.system_qty = newQty;
      movement = {
        id: Date.now() + 1,
        product_id: prod.id,
        product_code: prod.product_code,
        product_name: prod.product_name,
        type: 'adjust',
        qty: diff,
        note: `ตรวจนับสต็อกโดย ${staffNickname || staffName || 'พนักงาน'}: ${note}`,
        created_at: req.body.counted_at || formatLocalDateTime(),
      };
      serverMovements = [movement, ...serverMovements];

      broadcastSSE({
        type: 'STOCK_ADJUSTED',
        payload: {
          productId: prod.id,
          newQty,
          movement,
          productName: prod.product_name,
        },
      });
      broadcastSSE({ type: 'PRODUCT_UPDATED', payload: prod });
    }

    // Broadcast instantly to all connected screens (Admin & Staff screens)
    broadcastSSE({ type: 'NEW_COUNT', payload: newCount });

    res.json({ success: true, count: newCount, product: prod, movement });
  });

  // Admin: Verify count as matched
  app.post('/api/counts/:id/verify', (req, res) => {
    const countId = Number(req.params.id);
    let updatedCount: StockCount | null = null;

    serverCounts = serverCounts.map((c) => {
      if (c.id === countId) {
        updatedCount = {
          ...c,
          status: 'matched',
          discrepancy_note: 'ตรวจสอบแล้ว ยอดตรงตามระบบ',
        };
        return updatedCount;
      }
      return c;
    });

    if (updatedCount) {
      broadcastSSE({ type: 'COUNT_VERIFIED', payload: { countId } });
      res.json({ success: true, count: updatedCount });
    } else {
      res.status(404).json({ error: 'Count not found' });
    }
  });

  // Admin: Report mismatch
  app.post('/api/counts/:id/mismatch', (req, res) => {
    const countId = Number(req.params.id);
    const { note } = req.body;
    let updatedCount: StockCount | null = null;

    serverCounts = serverCounts.map((c) => {
      if (c.id === countId) {
        updatedCount = {
          ...c,
          status: 'mismatch',
          discrepancy_note: note || 'พบยอดไม่ตรงตามระบบ',
        };
        return updatedCount;
      }
      return c;
    });

    if (updatedCount) {
      broadcastSSE({ type: 'COUNT_MISMATCH', payload: { countId, note: note || '' } });
      res.json({ success: true, count: updatedCount });
    } else {
      res.status(404).json({ error: 'Count not found' });
    }
  });

  // Admin: Force adjust stock to match physical count
  app.post('/api/stock/adjust', (req, res) => {
    const { countId, productId, countedQty, note } = req.body;
    const prod = serverProducts.find((p) => p.id === Number(productId));

    if (!prod) {
      res.status(404).json({ error: 'Product not found' });
      return;
    }

    const oldQty = prod.system_qty;
    const diff = Number(countedQty) - oldQty;
    prod.system_qty = Number(countedQty);

    const movement: StockMovement = {
      id: Date.now(),
      product_id: prod.id,
      product_code: prod.product_code,
      product_name: prod.product_name,
      type: 'adjust',
      qty: diff,
      note: note || `ปรับสต็อกตามผลตรวจนับ (เดิม ${oldQty} เป็น ${countedQty})`,
      created_at: formatLocalDateTime(),
    };

    serverMovements = [movement, ...serverMovements];

    if (countId) {
      serverCounts = serverCounts.map((c) =>
        c.id === Number(countId)
          ? {
              ...c,
              status: 'matched',
              discrepancy_note: `แอดมินอนุมัติปรับยอดสต็อกในระบบเป็น ${countedQty} ชิ้น เรียบร้อยแล้ว`,
            }
          : c
      );
    }

    broadcastSSE({
      type: 'STOCK_ADJUSTED',
      payload: {
        productId: prod.id,
        newQty: prod.system_qty,
        movement,
        productName: prod.product_name,
      },
    });

    res.json({ success: true, product: prod, movement });
  });

  // Admin: Restock goods into inventory (นำสินค้าเข้าคลัง)
  app.post('/api/stock/restock', (req, res) => {
    const { productId, qty } = req.body;
    const prod = serverProducts.find((p) => p.id === Number(productId));

    if (!prod) {
      res.status(404).json({ error: 'Product not found' });
      return;
    }

    const restockQty = Number(qty);
    if (isNaN(restockQty) || restockQty <= 0) {
      res.status(400).json({ error: 'Invalid quantity' });
      return;
    }

    prod.system_qty += restockQty;

    const movement: StockMovement = {
      id: Date.now(),
      product_id: prod.id,
      product_code: prod.product_code,
      product_name: prod.product_name,
      type: 'in',
      qty: restockQty,
      note: `นำเข้าสินค้าใหม่จำนวน ${restockQty} ชิ้น`,
      created_at: formatLocalDateTime(),
    };

    serverMovements = [movement, ...serverMovements];

    // Broadcast instantly so all screens update their remaining balance immediately!
    broadcastSSE({
      type: 'PRODUCT_RESTOCKED',
      payload: {
        productId: prod.id,
        qty: restockQty,
        newQty: prod.system_qty,
        movement,
        productName: prod.product_name,
      },
    });

    res.json({ success: true, product: prod, movement });
  });

  // Admin: Add or update product
  app.post('/api/stock/product', (req, res) => {
    const { id, product_code, product_name, category, system_qty } = req.body;

    let targetProd: Product | undefined;
    let isNew = false;
    let initialMovement: StockMovement | null = null;

    if (id) {
      targetProd = serverProducts.find((p) => p.id === Number(id));
      if (targetProd) {
        targetProd.product_code = product_code || targetProd.product_code;
        targetProd.product_name = product_name || targetProd.product_name;
        targetProd.category = category || targetProd.category;
        targetProd.system_qty = Number(system_qty) ?? targetProd.system_qty;
      }
    } else {
      isNew = true;
      const newId = Date.now();
      const initialQty = Number(system_qty) || 0;
      targetProd = {
        id: newId,
        product_code: product_code || `P-${String(serverProducts.length + 1).padStart(3, '0')}`,
        product_name: product_name || 'สินค้าใหม่',
        category: category || 'หมวดทั่วไป',
        system_qty: initialQty,
      };
      serverProducts.push(targetProd);

      if (initialQty > 0) {
        initialMovement = {
          id: Date.now() + 1,
          product_id: targetProd.id,
          product_code: targetProd.product_code,
          product_name: targetProd.product_name,
          type: 'in',
          qty: initialQty,
          note: `เพิ่มสินค้าใหม่เข้าระบบ (สต็อกตั้งต้น ${initialQty} ชิ้น)`,
          created_at: formatLocalDateTime(),
        };
        serverMovements = [initialMovement, ...serverMovements];
      }
    }

    if (targetProd) {
      if (isNew) {
        broadcastSSE({
          type: 'PRODUCT_ADDED',
          payload: { product: targetProd, movement: initialMovement },
        });
      }
      broadcastSSE({ type: 'PRODUCT_UPDATED', payload: targetProd });
      res.json({ success: true, product: targetProd, movement: initialMovement });
    } else {
      res.status(400).json({ error: 'Failed to create or update product' });
    }
  });

  // Register new user
  app.post('/api/users/register', (req, res) => {
    const { username, password, fullname, nickname, role } = req.body;
    const cleanUser = (username || '').trim();

    if (!cleanUser || !password) {
      res.status(400).json({ error: 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน' });
      return;
    }

    if (serverUsers.some((u) => u.username.toLowerCase() === cleanUser.toLowerCase())) {
      res.status(400).json({ error: `ชื่อผู้ใช้ "${cleanUser}" มีอยู่ในระบบแล้ว` });
      return;
    }

    const newUser: User = {
      id: Date.now(),
      username: cleanUser,
      password: String(password).trim(),
      fullname: (fullname || `คุณ${nickname || cleanUser}`).trim(),
      nickname: (nickname || '').trim(),
      role: role === 'admin' ? 'admin' : 'staff',
    };

    serverUsers.push(newUser);
    broadcastSSE({ type: 'USER_REGISTERED', payload: newUser });
    res.json({ success: true, user: newUser });
  });

  // Reset data to initial seeds
  app.post('/api/reset', (req, res) => {
    serverProducts = JSON.parse(JSON.stringify(INITIAL_PRODUCTS));
    serverCounts = JSON.parse(JSON.stringify(INITIAL_STOCK_COUNTS));
    serverMovements = JSON.parse(JSON.stringify(INITIAL_MOVEMENTS));
    broadcastSSE({ type: 'DATA_RESET' });
    res.json({ success: true, message: 'Reset data successfully' });
  });

  // Keep-alive heartbeat for SSE connections (every 20s)
  setInterval(() => {
    sseClients.forEach((client) => {
      try {
        client.res.write(': heartbeat\n\n');
      } catch {
        // Disconnected client
      }
    });
  }, 20000);

  // -------------------------------------------------------------
  // Vite Middleware Integration (Dev) vs Static Dist (Prod)
  // -------------------------------------------------------------
  if (process.env.NODE_ENV !== 'production') {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: 'spa',
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), 'dist');
    app.use(express.static(distPath));
    app.get('*', (req, res) => {
      res.sendFile(path.join(distPath, 'index.html'));
    });
  }

  app.listen(PORT, '0.0.0.0', () => {
    console.log(`Express server with Realtime SSE running on http://0.0.0.0:${PORT}`);
  });
}

startServer().catch((err) => {
  console.error('Failed to start server:', err);
  process.exit(1);
});

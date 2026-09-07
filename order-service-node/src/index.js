import { randomUUID } from "node:crypto";
import express from "express";

const PORT = Number(process.env.PORT ?? 8080);
const STOCK_SERVICE_URL = process.env.STOCK_SERVICE_URL ?? "http://stock-service:8081";
const NOTIFICATION_SERVICE_URL = process.env.NOTIFICATION_SERVICE_URL ?? "http://notification-service:8082";
const REQUEST_TIMEOUT_MS = 5000;

const orders = new Map();

const app = express();
app.use(express.json());

app.get("/health", (_req, res) => {
  res.json({ status: "ok", service: "order-service" });
});

app.get("/orders", (_req, res) => {
  res.json({ orders: [...orders.values()] });
});

app.get("/orders/:id", (req, res) => {
  const order = orders.get(req.params.id);
  if (!order) {
    res.status(404).json({ error: "ORDER_NOT_FOUND", message: "Pedido no encontrado" });
    return;
  }
  res.json(order);
});

app.post("/orders", async (req, res) => {
  const { customerId, address, items } = req.body ?? {};

  if (!customerId || !address || !Array.isArray(items) || items.length === 0) {
    res.status(400).json({
      error: "INVALID_ORDER",
      message: "customerId, address e items son obligatorios",
    });
    return;
  }

  const order = {
    id: randomUUID(),
    customerId,
    address,
    items,
    status: "PENDING",
    reservationId: null,
    notifications: [],
    createdAt: new Date().toISOString(),
  };
  orders.set(order.id, order);

  try {
    const reservation = await reserveStock(order);
    order.reservationId = reservation.reservationId;
  } catch (error) {
    order.status = "REJECTED";
    order.error = error.payload ?? { message: error.message };
    const status = error.status === 409 ? 409 : error.status === 404 ? 404 : 502;
    res.status(status).json(order);
    return;
  }

  try {
    order.notifications.push(
      await notify("warehouse", order.id, {
        customerId: order.customerId,
        items: order.items,
      }),
    );
    order.notifications.push(
      await notify("courier", order.id, {
        address: order.address,
        items: order.items,
      }),
    );
    order.notifications.push(
      await notify("customer", order.id, {
        customerId: order.customerId,
        status: "CONFIRMED",
      }),
    );
    order.status = "CONFIRMED";
    res.status(201).json(order);
  } catch (error) {
    await releaseStock(order.id);
    order.status = "FAILED";
    order.error = error.payload ?? { message: error.message };
    res.status(502).json(order);
  }
});

app.listen(PORT, () => {
  console.log(`order-service listening on ${PORT}`);
});

async function reserveStock(order) {
  return requestJson(`${STOCK_SERVICE_URL}/stock/reserve`, {
    method: "POST",
    body: { orderId: order.id, items: order.items },
  });
}

async function releaseStock(orderId) {
  try {
    await requestJson(`${STOCK_SERVICE_URL}/stock/release`, {
      method: "POST",
      body: { orderId },
    });
  } catch (error) {
    console.error("No se pudo liberar stock", orderId, error.message);
  }
}

async function notify(type, orderId, payload) {
  return requestJson(`${NOTIFICATION_SERVICE_URL}/v1/notifications`, {
    method: "POST",
    body: { type, orderId, payload },
  });
}

async function requestJson(url, { method, body }) {
  let response;
  try {
    response = await fetch(url, {
      method,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
    });
  } catch (error) {
    const wrapped = new Error(`No se pudo contactar ${url}: ${error.message}`);
    wrapped.status = 502;
    throw wrapped;
  }

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const wrapped = new Error(payload.message ?? `HTTP ${response.status} en ${url}`);
    wrapped.status = response.status;
    wrapped.payload = payload;
    throw wrapped;
  }

  return payload;
}

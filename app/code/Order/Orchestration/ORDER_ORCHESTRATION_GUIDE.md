# 📦 Order Orchestration — Master Guide (Magento → Odoo + Medusa)

A friendly, step-by-step walkthrough of how **one order** travels all the way from a customer
clicking "Place Order" in Magento, through a message queue and an Adobe App Builder action, and
finally lands in **two** systems: **Odoo (ERP)** and **Medusa (OMS)**.

Read it top to bottom — each step explains *what happens*, *which file does it*, and *why*.

---

## 1. The big picture (in one breath)

```
  Customer clicks "Place Order" in Magento
              │
   (1) Observer catches the event  →  builds a clean order JSON
              │
   (2) Publisher drops it into RabbitMQ  (exchange "magento" → queue "order.export")
              │
   (3) Consumer picks it up, logs in to Adobe (IMS token), and POSTs it
              │
   (4) Adobe App Builder action "order-sync" receives the order
              │
        ┌─────┴───────────────────────────┐
   (5) Odoo ERP                       (6) Medusa OMS
   create customer + products         create draft order
   create + confirm sale.order        convert draft → real order
        │                                  │
   visible in Odoo "Sales"            visible in Medusa "Orders"
```

Think of the App Builder action as a **post office**: one order comes in, it makes two copies and
delivers one to Odoo and one to Medusa. This "one-in, many-out" idea is called **fan-out**.

---

## 2. Who's who (the cast)

| Component | Role | Where it lives |
|-----------|------|----------------|
| **Magento** | The shop. Where the order is placed. | `c:\xampp_lite_8_3\www\magento249` (Docker: `magento_app`) |
| **Order/Orchestration module** | Custom Magento module: observer + publisher + consumer | `…\magento249\app\code\Order\Orchestration\` |
| **RabbitMQ** | The "post box" that holds the order until it's processed | Docker: `magento_rabbitmq` |
| **App Builder `order-sync` action** | The post office — receives the order, fans it out | `d:\Adobe_app\adobeappbuilder\actions\order-sync\` |
| **Odoo** | The ERP — final home for accounting/inventory | Docker: `odoo` (`http://localhost:8069`) |
| **Medusa** | The OMS — order dashboard | `…\adobeappbuilder\my-oms` (`http://localhost:9000`) |

---

## 3. What must be running (checklist before any order)

Order tabhi flow karega jab ye **sab ON** hon:

- [ ] **Magento** (Docker `magento_app`)
- [ ] **RabbitMQ** (Docker `magento_rabbitmq`)
- [ ] **The consumer** — `order.export.consumer` (warna message queue me ruka rahega)
- [ ] **Adobe App Builder dev server** — `aio app dev` (port **9081**)
- [ ] **Odoo** (Docker `odoo`, port 8069)
- [ ] **Medusa** (`my-oms`, port 9000)

> ⚠️ Sabse common galti: **consumer band hona** ya **`aio app dev` `.env` change ke baad restart na karna**.

---

## 4. The journey — step by step (follow one order)

### Step 1 — Order placed → an event fires
Jab customer Magento me order place karta hai, Magento ek event nikalta hai:
`sales_order_place_after`. Hamara module us event ko "sunta" hai.

📄 `app\code\Order\Orchestration\etc\events.xml`
```xml
<event name="sales_order_place_after">
    <observer name="..._sales_order_place_after"
              instance="Order\Orchestration\Observer\Sales\OrderPlaceAfter"/>
</event>
```

### Step 2 — Observer builds a clean order and publishes it
Observer order ki saari details nikaal kar ek saaf JSON banata hai, aur use **`order.export`**
topic pe publish kar deta hai.

📄 `app\code\Order\Orchestration\Observer\Sales\OrderPlaceAfter.php`
```php
$payload = [
  'increment_id' => $order->getIncrementId(),     // e.g. "000000005"
  'customer'     => [ 'email' => ..., 'firstname' => ..., 'lastname' => ... ],
  'billing_address' => ..., 'shipping_address' => ...,
  'items'  => [ ['sku'=>..., 'name'=>..., 'qty'=>..., 'price'=>...] ],
  'totals' => [ 'grand_total' => ... ],
];
$this->logger->info('ORDER EXPORT PAYLOAD: ' . json_encode($payload));
$this->publisher->publish('order.export', json_encode($payload));
```
> Log line `ORDER EXPORT PAYLOAD: …` = observer ne kaam kar diya. ✅

### Step 3 — The message waits in RabbitMQ
Publish hone par message **`magento` exchange** se ho kar **`order.export` queue** me chala jata hai
(routing rules in topology).

📄 `queue_publisher.xml` (kaun publish karta hai) · `queue_topology.xml` (exchange + binding) ·
`queue_consumer.xml` (kaun consume karega) · `communication.xml` (topic define)
```xml
<!-- topology: exchange "magento" → queue "order.export" -->
<exchange name="magento" type="topic" connection="amqp">
  <binding id="OrderExportBinding" topic="order.export"
           destinationType="queue" destination="order.export"/>
</exchange>
```
> Agar ye exchange/queue RabbitMQ me **declare nahi** hue, message **drop** ho jata hai. Fix:
> `bin/magento setup:upgrade` (topology create karta hai).

### Step 4 — Consumer picks it up & forwards to Adobe
Consumer queue se message uthata hai, **Adobe se login** (IMS token) karta hai, aur order ko App
Builder action ki URL pe **POST** karta hai.

📄 `app\code\Order\Orchestration\Model\OrderConsumer.php`
```php
public function process($message) {
  $this->logger->info('ORDER RECEIVED : ' . $message);     // ✅ consumer chala
  $endpoint = config('order_orchestration/endpoint_url');   // App Builder action URL
  $token    = $this->getAccessToken();                      // Adobe IMS OAuth (S2S)
  $curl->addHeader('Authorization', 'Bearer ' . $token);
  $curl->addHeader('x-gw-ims-org-id', $orgId);
  $curl->post($endpoint, $message);                         // forward the order
}
```
Config (Magento `app/etc/env.php` → `order_orchestration` node):
```php
'endpoint_url' => 'https://host.docker.internal:9081/api/v1/web/adobeappbuilder/order-sync',
'ims' => [ 'token_url' => '…/ims/token/v3', 'client_id' => '…', 'client_secret' => '…', 'scopes' => '…' ],
'org_id' => '…@AdobeOrg',
```
> Log lines: `ORDER RECEIVED : …` then `Order export OK (HTTP 200)` = forward successful.
> **Consumer running hona zaroori hai** warna `ORDER RECEIVED` kabhi nahi aayega.

### Step 5 — App Builder action receives the order
Action pehle check karta hai ki request valid hai (auth header + `increment_id` + `customer`),
phir Odoo aur Medusa dono ke liye config padhta hai.

📄 [actions/order-sync/index.js](actions/order-sync/index.js)
```js
const requiredParams  = ['increment_id', 'customer']
const requiredHeaders = ['Authorization']    // consumer sends the IMS Bearer token
// then: Odoo (primary) … then Medusa (best-effort) … then return combined result
```

### Step 6 — Fan-out #1: create the order in Odoo (ERP)
Action Odoo me JSON-RPC se login karta hai, phir: customer upsert → products upsert → sale.order
create → confirm.

📄 [actions/order-sync/odoo.js](actions/order-sync/odoo.js)
```js
login → findSaleOrderByRef (idempotency) → ensurePartner (customer, country/state)
      → ensureProduct (per SKU) → createSaleOrder → confirmSaleOrder
```
> Console: `Created Odoo sale.order id=…` + `Confirmed Odoo sale.order id=…`
> Dekho: Odoo → **Sales → Orders** (Customer Reference = Magento increment id).

### Step 7 — Fan-out #2: create the order in Medusa (OMS)
Same order Medusa me bhi jata hai. Medusa me manual order **draft** ke roop me banta hai — par is
Medusa version me **Drafts admin page buggy** hai, isliye hum draft ko **real order me convert**
kar dete hain (jo working "Orders" page me dikhta hai).

📄 [actions/order-sync/medusa.js](actions/order-sync/medusa.js)
```js
connect (apiKey Basic, ya email/password → JWT)
  → findDraftOrderByRef (best-effort idempotency)
  → createDraftOrder (custom line items: title/qty/unit_price)
  → convertToOrder (draft → real order)
```
> Console: `Created Medusa draft order id=…` + `Converted Medusa order id=… to a real order`
> Dekho: Medusa → **Orders** (Drafts nahi) → order #.

> 🛡️ **Best-effort:** agar Medusa down ho, action sirf log karta hai — **Odoo order phir bhi
> safe rehta hai.** Odoo primary, Medusa secondary.

### Step 8 — Done ✅
Ab wahi ek order **teen jagah** orchestrate ho chuka hai: Magento (source), Odoo (ERP), Medusa (OMS).

---

## 5. One-time setup (scratch se)

### A. Magento side
1. Module `Order/Orchestration` enabled ho:
   `docker exec -u www-data magento_app php bin/magento module:enable Order_Orchestration`
2. Topology + config apply:
   `docker exec -u www-data magento_app php bin/magento setup:upgrade`
   `docker exec -u www-data magento_app php bin/magento cache:flush`
3. `app/etc/env.php` me `order_orchestration` node bharo (endpoint_url + IMS creds + org_id).

### B. Adobe App Builder side
1. [.env](.env) me bharo:
   ```
   ODOO_URL=http://localhost:8069/jsonrpc
   ODOO_DB=orderorchestration
   ODOO_USERNAME=...   ODOO_PASSWORD=...   ODOO_AUTO_CONFIRM=true
   MEDUSA_URL=http://localhost:9000
   MEDUSA_EMAIL=...    MEDUSA_PASSWORD=...        (ya MEDUSA_API_KEY)
   MEDUSA_REGION_ID=reg_...   MEDUSA_SALES_CHANNEL_ID=sc_...
   ```
2. Action inputs already wired in [app.config.yaml](app.config.yaml).

### C. Odoo
Already running (Docker `odoo`). Creds `.env` me. Sale orders yahan banenge.

### D. Medusa (detailed guide: [medusa-oms-integration.md](medusa-oms-integration.md))
1. Postgres: `docker run -d --name medusa_db -e POSTGRES_USER=medusa -e POSTGRES_PASSWORD=medusa -e POSTGRES_DB=medusa -p 5433:5432 postgres:16`
2. Install: `npx create-medusa-app@latest my-oms --db-url "postgres://medusa:medusa@localhost:5433/medusa"`
3. Region id + Sales Channel id Settings se lo, `.env` me daalo.

---

## 6. How to run & test (har baar)

```powershell
# 1) Medusa start (turbo NAHI — direct backend se)
cd d:\Adobe_app\adobeappbuilder\my-oms\apps\backend
npx medusa develop                       # → http://localhost:9000/app

# 2) App Builder dev server (NAYE terminal me; .env change ke baad RESTART zaroori)
cd d:\Adobe_app\adobeappbuilder
aio app dev                              # → port 9081

# 3) Magento me ORDER PLACE karo (browser se)

# 4) Consumer chalao taaki queue drain ho
docker exec -u www-data magento_app php bin/magento queue:consumers:start order.export.consumer --max-messages=10 --single-thread
```

Phir `aio app dev` console me **4 lines** dikhni chahiye:
```
Created Odoo sale.order id=…
Confirmed Odoo sale.order id=…
Created Medusa draft order id=…
Converted Medusa order id=… to a real order
```
Aur dashboards: **Odoo → Sales → Orders** · **Medusa → Orders**. 🎉

---

## 📸 Live demo — screenshots (step by step)

Ek real run ke screenshots — setup se le kar order dono dashboards me dikhne tak.
(Images `docs/images/` folder me hain.)

**Step A — Medusa OMS setup (admin account banao)**

![Medusa setup](docs/images/00-medusa-setup.png)
<img width="1915" height="971" alt="Medusa_ss" src="https://github.com/user-attachments/assets/648f8419-80f3-4504-9656-d1de2a372453" />


**Step B — RabbitMQ: order message queue me flow ho raha hai (`order.export`)**

![RabbitMQ dashboard](docs/images/01-rabbitmq.png)
<img width="1907" height="976" alt="RabbitMQ_dasboard" src="https://github.com/user-attachments/assets/3b7d9c2b-c29c-47c2-8ae9-19885b95bded" />


**Step C — Odoo (ERP): order Sales → Orders me aa gaya**

![Odoo Sales Orders](docs/images/02-odoo-orders.png)
<img width="1905" height="957" alt="Odoo_ERP_Sales_order" src="https://github.com/user-attachments/assets/1d8befb0-f403-4d78-915e-ca55b6258645" />


**Step D — Medusa (OMS): order Orders page me aa gaya (multi-channel ready)**

![Medusa Orders](docs/images/03-medusa-orders.png)
<img width="1917" height="862" alt="medusa_order_capture" src="https://github.com/user-attachments/assets/4b5959a9-3fd2-4830-95a7-a0024c6c74d9" />


> 🖼️ **Images add karne ke liye:** apne 4 screenshots ko `docs/images/` folder me **exact in
> naam** se save karo — phir guide me automatically render ho jayenge:
> `00-medusa-setup.png` · `01-rabbitmq.png` · `02-odoo-orders.png` · `03-medusa-orders.png`

---

## 7. Verify each hop (jab kuch na dikhe)

| Hop | Check |
|-----|-------|
| Observer chala? | `docker exec magento_app sh -c "grep -a 'ORDER EXPORT PAYLOAD' var/log/system.log \| tail -1"` |
| Queue exist karta hai? | `docker exec magento_rabbitmq rabbitmqctl list_queues name messages_ready` |
| Consumer chala? | `docker exec magento_app sh -c "grep -a 'ORDER RECEIVED' var/log/system.log \| tail -1"` |
| Forward hua? | `…grep -a 'Order export OK'…` |
| Action chala? | `aio app dev` console |
| Odoo me aaya? | Odoo → Sales → Orders |
| Medusa me aaya? | Medusa → Orders (Drafts nahi) |

---

## 8. Troubleshooting (jo dikkatein humne hit ki)

| Symptom | Cause | Fix |
|---------|-------|-----|
| `ORDER EXPORT PAYLOAD` hai, par `ORDER RECEIVED` nahi | Consumer band hai | Consumer start karo (Step in §6) |
| Kuch bhi consume nahi ho raha | RabbitMQ me `magento` exchange/`order.export` queue missing | `bin/magento setup:upgrade` |
| Action me Medusa skip ho raha (`medusa: skipped`) | `.env` Medusa values khaali ya `aio app dev` restart nahi hua | `.env` bharo + `aio app dev` restart |
| Medusa `npm run dev` turant fail (turbo exit 1) | turbo wrapper Windows issue | `cd my-oms\apps\backend && npx medusa develop` |
| Medusa **Drafts** page crash (`useLocation … <Router>`) | `@medusajs/draft-order` admin ka bug | Expected — order **Orders** page me dekho (hum convert karte hain) |
| Action 401/403 | IMS token / `require-adobe-auth` | Consumer ke IMS creds + org_id check karo |
| Order Odoo me hai, Medusa me nahi (ya ulta) | ek system down tha | Us system ke logs dekho; Odoo primary |

---

## 9. File map (sab ek jagah)

**Magento module** — `c:\xampp_lite_8_3\www\magento249\app\code\Order\Orchestration\`
- `etc/events.xml` — observe `sales_order_place_after`
- `Observer/Sales/OrderPlaceAfter.php` — build payload + publish
- `etc/queue_publisher.xml`, `queue_topology.xml`, `queue_consumer.xml`, `communication.xml` — queue wiring
- `Model/OrderConsumer.php` — consume + IMS auth + POST to Adobe
- (config) `app/etc/env.php` → `order_orchestration` node

**App Builder** — `d:\Adobe_app\adobeappbuilder\`
- [actions/order-sync/index.js](actions/order-sync/index.js) — main action (fan-out)
- [actions/order-sync/odoo.js](actions/order-sync/odoo.js) — Odoo JSON-RPC client
- [actions/order-sync/medusa.js](actions/order-sync/medusa.js) — Medusa REST client
- [app.config.yaml](app.config.yaml) — action + inputs
- [.env](.env) — Odoo + Medusa secrets
- [test/order-sync.test.js](test/order-sync.test.js) — unit tests (14)
- [medusa-oms-integration.md](medusa-oms-integration.md) — Medusa-specific deep dive

---

## 10. Good to know (limits / next steps)

### 🧭 ERP vs OMS — Medusa kyun? (kab dono, kab ek)

Ye demo me Odoo aur Medusa dono ne **same order** capture kiya — toh dono kyun? Inka **role alag** hai:

- **Odoo (ERP)** = back-office "system of record": **accounting, taxes, invoicing, inventory
  valuation, purchasing**. Order yahan ek financial/stock document hai.
- **Medusa (OMS)** = order ka front-line hub: **order capture + lifecycle + fulfillment + returns**.

> 🎯 **Medusa ka asli kaam: MULTI-CHANNEL orders ko ek single platform me laana.**
> Aaj order sirf Magento se aa raha hai. Par real business me orders kai jagah se aate hain —
> **Magento + Amazon + eBay + POS + Instagram shop**, etc. Har channel ka apna order format/dashboard.
> Medusa (OMS) in **sabhi channels ke orders ko ek hi jagah** consolidate karta hai — ek unified
> Orders list, ek customer view, ek fulfillment/return flow. Phir wahan se Odoo (ERP) ko finance
> ke liye feed kiya jata hai.

```
Magento ─┐
Amazon  ─┤
eBay    ─┼─►  Medusa (OMS)  ─►  ek single unified Orders platform  ─►  Odoo (ERP, finance/stock)
POS     ─┤
Instagram┘
```

**Bottom line:**
- *Is demo me:* abhi ek hi channel (Magento) hai, isliye Medusa ka multi-channel fayda dikhta nahi —
  humne ise **orchestration pattern seekhne** ke liye add kiya.
- *Real project me:* Medusa tab shine karta hai jab **2+ sales channels** ho — sabko ek jagah laata
  hai; Odoo sirf finance/stock ka source-of-truth rehta hai. Sirf 1 channel ho to **Odoo akela kaafi**.

---

- **Idempotency:** dono targets Magento `increment_id` se duplicate order banने se bachte hain.
- **Odoo** order auto-confirmed hota hai (`ODOO_AUTO_CONFIRM=false` se quotation rehne do).
- **Medusa** order draft→convert hota hai; products simple line items (catalog match nahi).
- **Local only:** `localhost`/`host.docker.internal` sirf dev me kaam karta hai. Cloud deploy ke
  liye Odoo/Medusa ke public URLs chahiye.
- **Next ideas:** order status sync-back, Medusa product-variant matching, retry/dead-letter on
  failed forwards.

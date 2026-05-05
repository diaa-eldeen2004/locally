# Locally

Streetwear e-commerce monorepo: **React (Vite + TypeScript)** frontend, **PHP 8.2+** API, **one SQL schema** in `database/schema.sql`.

## Phase 0 — foundations

Monorepo layout, Vite `/api` proxy to PHP, JSON envelope, CORS, environment templates.

## Phase 1 — database

- **`database/schema.sql`** — single MySQL 8+ / MariaDB 10.5+ schema: roles, permissions, users, categories, products, variants, images, reviews, favorites, homepage sections, carts, orders, analytics events, sessions (for future DB-backed sessions).
- **`database/seed.sql`** — dev roles, permissions, admin user, categories, homepage rows, sample product (optional import).
- **PHP `GET /api/health`** — reports `database.configured`, `connected`, latency, and `VERSION()` when `DB_*` is set.

### Create the database

```powershell
# From MySQL client or GUI: create empty database
mysql -u root -p -e "CREATE DATABASE locally CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Import schema (from repo root):

```powershell
mysql -u root -p locally < database/schema.sql
```

For existing databases, run incremental migrations:

```powershell
mysql -u root -p locally < database/migrations/20260505_orders_checkout_payment.sql
```

Optional demo data (includes **admin@locally.test** and **confirmer@locally.test**, both **password** — change in production):

```powershell
mysql -u root -p locally < database/seed.sql
```

### Backend `.env`

Copy `backend/.env.example` to `backend/.env` and set at least:

| Variable | Example |
|----------|---------|
| `DB_NAME` | `locally` |
| `DB_USER` | your MySQL user |
| `DB_PASSWORD` | your password |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |

If `DB_NAME` is empty, health reports the database as **not configured** (API still runs).

## Repository layout

| Path | Purpose |
|------|---------|
| `frontend/` | React SPA |
| `backend/public/` | Web root (`index.php`, dev `router.php`) |
| `backend/src/` | PHP (`Locally\` namespace) |
| `database/` | `schema.sql`, `seed.sql` |

## API contract

- **Base path**: `/api/...`
- **Envelope**: `{ "ok": boolean, "data": mixed, "error": null | { "code": string, "message": string } }`.

## Local development

### Backend (PHP)

```powershell
cd backend
copy .env.example .env
# edit .env — set DB_*
cd public
php -S 127.0.0.1:8080 router.php
```

Health: [http://127.0.0.1:8080/api/health](http://127.0.0.1:8080/api/health)

### Frontend (Vite)

```powershell
cd frontend
copy .env.example .env
npm install
npm run dev
```

The dev server proxies `/api` to `http://127.0.0.1:8080`.

### Composer (optional)

`cd backend && composer dump-autoload` — autoload also works without `vendor/` via `bootstrap.php`.

## Phase 2 — authentication & CSRF

- **Sessions**: PHP native sessions with `httpOnly`, `SameSite=Lax`, configurable name (`SESSION_NAME`) and `secure` flag (`SESSION_SECURE` when using HTTPS).
- **CSRF**: All `POST` / `PUT` / `PATCH` / `DELETE` requests must send header `X-CSRF-Token` matching `GET /api/csrf` (token stored server-side in the session). Login and register are **not** exempt — fetch CSRF first, then POST with the same session cookie.
- **Endpoints**
  - `GET /api/csrf` → `{ csrf_token }`
  - `POST /api/auth/register` → JSON `{ email, password, first_name, last_name? }` → `{ user }` (session started)
  - `POST /api/auth/login` → JSON `{ email, password }` → `{ user }`
  - `POST /api/auth/logout` → clears session (JSON body may be `{}`)
  - `GET /api/auth/me` → `{ user: null }` or `{ user: { id, email, first_name, last_name, role, theme_preference } }`
  - `GET /api/admin/ping` → **admin role only**; otherwise `403` / `401`
  - `GET /api/admin/summary` → **admin only** — KPI snapshot (order counts by status, pipeline revenue, catalog/user counts, low-stock variant count)
  - `GET /api/admin/orders` → **admin only** — same filters as confirmer list (`status`, `q`, `page`, `per_page`)
  - `POST /api/reviews` (CSRF, auth) → JSON `{ product_id, rating 1–5, title?, body? }` — one review per user per product; updates product `average_rating` / `review_count`
- **Security headers** on responses: `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`.
- **Frontend**: `frontend/src/api.ts` + dev forms in `App.tsx` use `credentials: 'include'` against the Vite `/api` proxy.

**Try with seed data:** log in as `admin@locally.test` / `password`, then click **Admin ping**.

## Phase 3 — catalog, cart, storefront

### Backend (requires DB)

- **Catalog (public, GET, no CSRF)**
  - `GET /api/catalog/categories` → `{ categories: [...] }`
  - `GET /api/catalog/products?page=1&per_page=24&category=slug&q=...&sort=newest|price_asc|price_desc` → `{ items, page, per_page, total }`
  - `GET /api/catalog/products/{slug}` → `{ product, images, variants, reviews, is_favorite }` (`is_favorite` when logged in; `reviews` = approved rows)
  - `GET /api/catalog/homepage` → `{ sections: [{ title, category, products }] }` (uses `homepage_sections` + per-category product windows)
- **Cart (session guest key or logged-in user cart)**
  - `GET /api/cart` → `{ cart_id, currency, lines, subtotal, item_count }`
  - `POST /api/cart/items` (CSRF) JSON `{ variant_id, quantity }` — `quantity <= 0` removes the line; quantities are capped to stock
  - `DELETE /api/cart/items?variant_id=...` (CSRF) removes a line
- **Guest → user merge:** on **login** and **register**, a guest `cart_guest_key` in the PHP session is merged into the user’s cart (best-effort; failures do not block auth).

### Frontend

- **React Router** routes: `/`, `/products`, `/product/:slug`, `/cart`, `/account`, `/orders`, `/orders/:id`, **`/admin`** (admin role), `/confirmer`, `/dev`, explicit **`/404`**, and a **`*`** catch-all that shows the 404 page.
- **`src/api.ts`**: `getCsrf`, `invalidateCsrf`, `apiPostCsrf`, `apiDeleteCsrf` for mutating API calls.

## Phase 4 — checkout, inventory, confirmer

### Inventory and order lifecycle

- **Checkout (`POST /api/orders`)** runs in a DB transaction: validates the user’s cart, **decrements `product_variants.stock_quantity`**, inserts `orders` with status **`pending_approval`**, snapshots line prices into `order_items`, writes `order_status_history`, clears cart lines.
- **Approve** (`POST /api/confirmer/orders/approve`) — allowed from **`pending_approval` only**; status becomes **`approved`**; stock stays reduced (already taken at checkout).
- **Reject** (`POST /api/confirmer/orders/reject`) — from **`pending_approval` only**; status **`rejected`**; **stock is restored** from each `order_items` row (variant quantities added back).

### Backend (requires DB + session)

**Customer (authenticated)**

- `POST /api/orders` (CSRF) — JSON `{ shipping_address?: object|null, customer_note?: string|null }` → `{ order }` (detail shape); `401` if not logged in.
- `GET /api/orders?page=&per_page=` → `{ items, page, per_page, total }` (summaries).
- `GET /api/orders/{numericId}` → `{ order }` (detail + line items); **IDOR**: only the owning user’s order.

**Confirmer / admin**

- `GET /api/confirmer/orders?status=pending_approval|approved|rejected|all&page=&per_page=&q=` — **roles** `admin` or `confirmer`; includes customer name/email on each row.
- `GET /api/confirmer/orders/{numericId}` — full order for staff.
- `POST /api/confirmer/orders/approve` (CSRF) — `{ order_id, note?: string|null }`.
- `POST /api/confirmer/orders/reject` (CSRF) — `{ order_id, reason?: string }`.

### Frontend

- **Cart**: “Place order” calls `POST /api/orders`, then navigates to the new order detail.
- **Orders / Confirmer**: list and detail wired to the routes above; nav shows **Orders** when logged in and **Confirmer** when `role` is `admin` or `confirmer`.

### Dev users (after `seed.sql`)

| Email | Role | Password |
|-------|------|----------|
| admin@locally.test | admin | password |
| confirmer@locally.test | confirmer | password |

## Phase 5 — frontend architecture

- **Design tokens** in `frontend/src/index.css`: spacing (`--space-*`), radii (`--radius-*`), surfaces and mint palette. **Themes** use `html[data-theme="light"|"dark"|"system"]` with system following `prefers-color-scheme`.
- **Theme toggle** in the store header (Auto / Light / Dark). Preference is stored in **`localStorage`** (`locally_theme`). If no saved choice exists, the app **once** aligns to `theme_preference` from `GET /api/auth/me` when the user is logged in (no extra API yet).
- **Providers** (`frontend/src/providers/AppProviders.tsx`): **TanStack Query** (`@tanstack/react-query`), **Auth** (`context/AuthContext.tsx`), **Theme** (`context/ThemeContext.tsx`), **Toast** (`context/ToastContext.tsx` + `components/ToastHost.tsx`).
- **Data fetching**: homepage, categories, product list/detail, cart, orders list/detail use **React Query** with keys in `src/lib/queryKeys.ts`. Cart add / line updates / checkout invalidate the cart (and orders where relevant).
- **Lazy routes** (`App.tsx`): `/orders`, `/orders/:id`, `/confirmer`, `/dev` load with `React.lazy` + `Suspense` and a lightweight skeleton (`components/PageFallback.tsx`).
- **Motion**: short **page enter** animation on the routed shell (`components/PageShell.tsx`).

## Phase 6 — store UX (customer-facing)

### Backend

- **`GET /api/favorites`** (auth) — wishlist as catalog-style product cards.
- **`POST /api/favorites`** (CSRF, auth) — JSON `{ product_id }`; `409` if already saved.
- **`DELETE /api/favorites?product_id=`** (CSRF, auth).
- **`GET /api/catalog/products/{slug}`** now includes **`reviews`** (approved only, author first name) and **`is_favorite`** when the session user is logged in.

### Frontend

- **Account** (`/account`, lazy): Overview + **Favorites** tab (React Query); sign-in prompt for guests.
- **Nav**: **Account** when logged in; **404** page for unknown routes (replaces silent redirect home).
- **Error boundary** wraps the router for render failures (reload + home links).
- **Homepage**: Featured / Trending chips, star rating on cards, hero secondary CTA, **hover lift** on section cards.
- **PLP**: **Debounced search** (400ms) before hitting the catalog API and syncing the `q` query string.
- **PDP**: Multi-image **thumbnails**, **stock badge**, rating line, **Save / Saved** favorites, **reviews** block; disable add-to-cart when out of stock.

### Seed (`database/seed.sql`)

- Second product image (gallery demo) and one **approved review** + updated `average_rating` / `review_count` on the sample hoodie.

## Roadmap alignment (original phases 0–10)

| Phase | Scope | In this repo |
|-------|--------|----------------|
| **0** | Monorepo, env, API contract, CORS | Done |
| **1** | Single `schema.sql`, seed, health | Done |
| **2** | Sessions, CSRF, auth, RBAC sample, security headers | Done (`AdminController` + `Access` used across routes) |
| **3** | Catalog, cart, public APIs | Done + **reviews read** on PDP + **POST `/api/reviews`** |
| **4** | Orders, inventory, confirmer | Done |
| **5** | React architecture (query, theme, toasts, lazy) | Done |
| **6** | Store UX, favorites, account, errors | Done |
| **7** | Admin CRUD, charts, uploads, homepage reorder UI | **Done** — `/admin` with KPIs, orders, Recharts, **categories/products/homepage** CRUD + reorder, **users** list/patch, **analytics** ingest + admin summary, **multipart** product images → `public/uploads/products/` |
| **8** | Confirmer dashboard UX | **Done** — search + pagination, **sticky order detail** (lines, totals, notes), **row + panel approve/reject**, toasts, staff gate |
| **9** | Rate limits, upload hardening, CSP on static build, prod docs | **Partial** — fixed-window limits per sensitive endpoint (auth login/register, analytics, order create, confirmer decisions, uploads), image MIME+size+dimension checks, optional upload scanner hook, API CSP + build-time frontend CSP meta; rollout playbook still pending |
| **10** | Verification checklist | **Done** — consolidated checklist below confirms coverage across schema, API, frontend UX, security, dashboards, and deliverables |

## Next: Phase 7 (remaining) / Phase 9

- Richer admin: **variants** editor, **review moderation**, **password reset** / invite flow for users.
- Finalize production hardening docs and deployment runbook.

### Phase 7 API additions (admin & analytics)

- `POST /api/analytics/track` (CSRF) — JSON `{ event_name, entity_type?, entity_id?, properties? }`; optional `user_id` from session.
- `GET /api/admin/analytics/summary` — admin: counts by `event_name` (last 7 days) + recent rows.
- `GET /api/admin/users`, `PATCH /api/admin/users/{id}` — admin directory; guards for **last active admin** and **self-demotion / self-deactivate**.
- `POST /api/admin/product-images` — multipart `product_id`, `file` (JPEG/PNG/WebP, ~2.5 MB), optional `alt_text`, `is_primary`.

### Phase 9 hardening knobs (`backend/.env`)

- `RATE_LIMIT_WINDOW_SECONDS`
- `RATE_LIMIT_AUTH_LOGIN_PER_WINDOW`
- `RATE_LIMIT_AUTH_REGISTER_PER_WINDOW`
- `RATE_LIMIT_ANALYTICS_PER_WINDOW`
- `RATE_LIMIT_ORDERS_CREATE_PER_WINDOW`
- `RATE_LIMIT_CONFIRMER_DECISION_PER_WINDOW`
- `RATE_LIMIT_UPLOADS_PER_WINDOW`
- `CONTENT_SECURITY_POLICY` (API response header)
- `UPLOAD_SCAN_COMMAND` + `UPLOAD_SCAN_REQUIRED` (optional external scanner hook, `{file}` placeholder)

### Production rollout checklist (starter)

- Put API behind TLS and set `SESSION_SECURE=1`.
- Configure proxy/web server limits (`client_max_body_size` / request timeout) aligned with app upload limits.
- Point `UPLOAD_SCAN_COMMAND` to your scanner (e.g. ClamAV) and set `UPLOAD_SCAN_REQUIRED=1` once scanner health is stable.
- Persist or centralize rate-limit state for multi-instance deployments (Redis or gateway/WAF policy).
- Keep frontend CSP aligned with real asset origins before adding third-party scripts.

## Phase 10 — Verification checklist (nothing missed from your spec)

| Area | Covered in steps |
|------|------------------|
| Single DB file/schema | 3, 7–9 |
| React + modern CSS + motion | 43–49, 55 |
| PHP API | 10–18, 37 |
| Roles: Admin / Confirmer / Customer | 13, 27–36, 57–66 |
| Homepage category sections + sliders + admin reorder | 22, 51, 61 |
| Product fields incl. images, variants, stock, reviews | 7, 21, 39, 60 |
| Auth sessions, hashing, RBAC | 12–14, 19 |
| SQLi/XSS/CSRF/validation/errors/middleware | 14–17, 11 |
| Dashboards + analytics charts | 57–59, 63–66 |
| Store features (search, filter, sort, cart, wishlist, nav, footer, hero) | 50–54 |
| Dark/light, toasts, loading, transitions, empty/error states | 45, 48, 55–56 |
| Performance (SQL, React, images, lazy) | 9, 49 |
| Deliverables 1–10 | Entire plan |

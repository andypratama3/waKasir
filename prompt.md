# WaKasir — WhatsApp Commerce Bot SaaS
## Dokumen Produk Lengkap (Master Spec)
*Disusun 12 Juli 2026 — versi 3.0 (update arsitektur BSP WhatsApp: 15 Juli 2026)*

---

## Daftar Isi
1. Ringkasan Produk & Posisi Pasar
2. Alur Lengkap Pelanggan (Ongkir Sebelum Bayar)
3. Alur Pemilik Toko (termasuk onboarding BSP)
4. Arsitektur Teknis Sistem
5. Arsitektur WhatsApp — Model BSP Multi-Tenant *(keputusan 15 Juli 2026)*
6. Tech Stack — Detail Lengkap
7. State Machine Bot
8. Skema Database
9. Dashboard Owner — Spesifikasi per Halaman
10. Katalog Masalah & Solusi (Edge Cases)
11. Fitur MVP vs V2
12. Model Bisnis & Harga
13. Rencana Eksekusi 8 Minggu
14. Risiko & Mitigasi
15. Strategi Promosi

---
## 1. Ringkasan Produk & Posisi Pasar

**WaKasir** adalah SaaS multi-tenant yang menghubungkan nomor WhatsApp Business milik toko/UKM ke satu bot otomatis yang bisa: menampilkan katalog, memproses pesanan, menghitung ongkir otomatis, generate QRIS via Midtrans, konfirmasi pembayaran otomatis, dan meminta alamat pengiriman — tanpa admin toko perlu balas chat manual.

**Celah pasar:** solusi WA API di Indonesia terbagi dua kutub — chatbot AI generik murah (Rp25rb–150rb/bln) yang tidak bisa generate pembayaran+ongkir otomatis, atau platform omnichannel (Rp500rb–2,5jt/bln) yang terlalu mahal untuk UKM. Belum ada yang fokus pada alur "chat → pilih produk → hitung ongkir → bayar QRIS → kirim" dalam satu paket harga UKM.

**Keunggulan teknis:** rekombinasi dari yang sudah teruji — integrasi Midtrans QRIS (produksi di sistem sekolah & tiketing) + WhatsApp Cloud API (produksi di sistem sekolah).

---

## 2. Alur Lengkap Pelanggan

> Kota tujuan ditanyakan **sebelum** generate QR supaya ongkir sudah masuk ke total tagihan.

```
1. SAPA & DETEKSI INTENT
   Pelanggan chat → Bot: "Halo! Selamat datang di [Nama Toko] 👋
   1️⃣ Lihat Katalog  2️⃣ Cek Status Pesanan  3️⃣ Hubungi Admin"

2. TAMPILKAN KATALOG
   Bot ambil produk aktif dari DB → kirim daftar bernomor

3. PILIH PRODUK + VARIAN (jika ada) + JUMLAH
   "Baju Batik - Rp150.000. Pilih ukuran: 1.S 2.M 3.L"
   "Mau berapa pcs?"

4. RINGKASAN KERANJANG
   "Baju Batik M x2 = Rp300.000. Tambah produk lain? (ya/tidak)"

5. TANYA KOTA TUJUAN (sebelum bayar)
   Bot: "Kirim ke kota mana?"
   Autocomplete dari cache kota → konfirmasi jika ada beberapa pilihan

6. HITUNG ONGKIR OTOMATIS (RajaOngkir)
   Bot: "Pilihan pengiriman:
        1. JNE REG - Rp25.000 (2-3 hari)
        2. JNE YES - Rp98.000 (1 hari)
        3. Ambil Sendiri - Gratis"

7. TOTAL FINAL = Produk + Ongkir
   "Total: Rp325.000. Lanjut bayar?"

8. GENERATE QRIS OTOMATIS (Midtrans Core API)
   Bot kirim QR Code + Order ID + countdown 15 menit

9. PELANGGAN BAYAR via QRIS (e-wallet/m-banking apapun)

10. WEBHOOK MIDTRANS settlement → konfirmasi otomatis
    "✅ Bayar diterima! Kirim alamat lengkap (jalan, RT/RW, patokan):"

11. PELANGGAN KIRIM ALAMAT DETAIL
    "Alamat diterima. Order #ORD-xxx sedang diproses."

12. NOTIFIKASI OWNER (WA + dashboard)
    "🔔 Order #ORD-xxx Rp325.000 LUNAS — via JNE REG ke [kota]"

13. UPDATE RESI (opsional, dipicu owner di dashboard)
    Bot otomatis kirim notif resi ke pelanggan
```

---
## 3. Alur Pemilik Toko

```
1. ONBOARDING — Model BSP (client TIDAK perlu sentuh Meta Developer)
   Daftar di WaKasir → pilih paket
   → Klik "Hubungkan WhatsApp" di dashboard
   → Popup Facebook Embedded Signup muncul (dihost oleh Meta, dipicu dari WaKasir)
   → Client login Facebook mereka → pilih/buat nomor WA Business
   → Meta kirim authorization_code ke backend WaKasir
   → WaKasir exchange code → dapat System User Token + phone_number_id + waba_id
   → Token disimpan terenkripsi di DB; client tidak perlu tahu detail teknis apapun

2. SETUP INTEGRASI (client isi sendiri di dashboard)
   → Midtrans: Server Key + Client Key (dari dashboard Midtrans milik client)
   → RajaOngkir: API Key (dari akun rajaongkir.com milik client)
   → Alamat asal toko (kota untuk hitung ongkir)

3. SETUP KATALOG
   Upload produk: nama, harga, stok, foto, kategori, BERAT GRAM (wajib)

4. KUSTOMISASI BOT
   Pesan sapaan, jam operasional, template notifikasi

5. OPERASIONAL HARIAN
   Pantau order di dashboard → input resi → bot notif pelanggan otomatis
```

---

## 4. Arsitektur Teknis Sistem

```
┌──────────────────┐  Webhook masuk   ┌──────────────────────────┐
│  Meta WhatsApp    │ ───────────────▶ │  Laravel Backend          │
│  Cloud API        │ ◀─────────────── │  (REST API + Bot Engine)  │
└──────────────────┘  Kirim pesan     └────────────┬─────────────┘
                                                    │
              ┌─────────────────────────────────────┼──────────────────────┐
              ▼                                     ▼                      ▼
   ┌─────────────────┐                  ┌────────────────────┐  ┌─────────────────┐
   │ MySQL/PostgreSQL │                  │  Midtrans Core API  │  │  RajaOngkir API │
   │ (multi-tenant)   │                  │  (QRIS + webhook)   │  │  (ongkir+kota)  │
   └─────────────────┘                  └────────────────────┘  └─────────────────┘
              ▲
   ┌──────────┴──────────┐    ┌────────────────────────────────┐
   │ Redis                │    │ Laravel REST API (Sanctum)      │
   │ (cart, session bot,  │◀──▶│ → dikonsumsi Angular SPA        │
   │  cache kota)         │    └───────────────┬────────────────┘
   └─────────────────────┘                    ▼
   ┌─────────────────────┐          ┌──────────────────────────┐
   │ Laravel Queue        │          │ Angular Admin Dashboard   │
   │ + Horizon            │          │ (SPA, owner per toko)     │
   │ (async webhook jobs) │          └──────────────────────────┘
   └─────────────────────┘
```

**Prinsip desain:**
- Webhook jawab < 1 detik (validasi + push ke Queue) — proses berat di Job async
- Session bot per `(business_id, wa_number)` di Redis — isolasi antar tenant & pelanggan
- Data kota RajaOngkir di-cache di DB lokal (diizinkan Meta) — hanya ongkir & resi yang real-time
- Dashboard Angular pure SPA, komunikasi lewat REST API (Sanctum token)

---
## 5. Arsitektur WhatsApp — Model BSP Multi-Tenant
*Keputusan teknis final: 15 Juli 2026*

### Konsep Inti

WaKasir adalah **penyedia layanan**, bukan pengguna WA API biasa. Kamu punya **1 Meta App** saja untuk semua client. Setiap client yang onboarding menghubungkan nomor WA **mereka sendiri** ke Meta App milikmu via Embedded Signup — tanpa perlu buat akun Meta Developer sama sekali.

Model ini identik dengan yang dipakai Wati, Zoko, Respond.io. Istilah resmi Meta: **Cloud API Solution Provider**.

---

### Struktur di Meta

```
developers.facebook.com
└── Meta App: "WaKasir" (1 app, milik kamu selamanya)
    └── WhatsApp Business Platform
        ├── WABA: Toko A → phone_number_id: 111xxx → +62812xxxxxxx
        ├── WABA: Toko B → phone_number_id: 222xxx → +62813xxxxxxx
        ├── WABA: Toko C → phone_number_id: 333xxx → +62814xxxxxxx
        └── ... (tidak terbatas)
```

Satu webhook URL untuk semua client:
```
POST https://api.wakasir.com/api/webhooks/whatsapp
GET  https://api.wakasir.com/api/webhooks/whatsapp  ← Meta challenge verification
```

Backend routing berdasarkan `phone_number_id` dari payload webhook:
```php
$phoneNumberId = $value['metadata']['phone_number_id'];
$business = Business::where('wa_phone_id', $phoneNumberId)->firstOrFail();
dispatch(new ProcessIncomingWhatsAppMessage($waNumber, $text, $business->id));
```

---

### Flow Embedded Signup (Sisi Client)

```
Client klik "Hubungkan WhatsApp" di dashboard WaKasir
    ↓
Popup Facebook OAuth muncul (dipicu FB.login dengan config_id milik kamu)
    ↓
Client login Facebook → pilih/buat WhatsApp Business Account
    ↓
Meta kirim authorization_code (sekali pakai) ke backend WaKasir
    ↓
Backend WaKasir:
  POST graph.facebook.com/oauth/access_token → dapat user_token
  POST graph.facebook.com/{waba_id}/assigned_system_users → buat System User Token
    ↓
Simpan ke DB: wa_access_token (encrypted), wa_phone_id, wa_waba_id
    ↓
Client selesai — nomor WA aktif, bot langsung berjalan
```

---

### Setup Sekali di Meta Developer Console (oleh kamu, bukan client)

1. Buat Meta App di developers.facebook.com → tambah produk WhatsApp
2. Daftarkan Webhook URL + verify token
3. Subscribe events: `messages`, `messaging_postbacks`
4. Buat **Configuration ID** Embedded Signup di WhatsApp Manager
5. Simpan `Meta App ID` dan `Configuration ID` di environment Angular

---

### Penyimpanan Token Per-Client di DB

```
businesses
├── wa_phone_id          — Phone Number ID Meta (untuk kirim pesan + routing webhook)
├── wa_phone_number      — Nomor tampilan: +62812xxx
├── wa_access_token      — System User Token — TERENKRIPSI (encrypt() Laravel)
├── wa_waba_id           — WhatsApp Business Account ID
├── wa_token_expires_at  — NULL jika System User Token (tidak expire)
└── wa_connected         — Boolean, health status terakhir
```

**Kenapa token disimpan per-client, bukan di .env?**
Karena setiap client punya WABA berbeda. Bot harus kirim pesan dari nomor WA **milik client itu** — pelanggan melihat nama toko client, bukan nama WaKasir. Satu token global tidak bisa mengirim dari banyak nomor berbeda.

Cara pakai di backend:
```php
$token = $business->getWaAccessTokenDecrypted(); // decrypt on-the-fly
Http::withToken($token)->post("{$baseUrl}/{$business->wa_phone_id}/messages", [...]);
```

---

### Midtrans & RajaOngkir — Tetap Per-Client

| Integrasi | Kenapa per-client |
|---|---|
| **Midtrans** | Settlement masuk ke rekening merchant client langsung, bukan rekening WaKasir |
| **RajaOngkir** | Rate limit per API key; quota masing-masing client terpisah |

Credentials diinput manual client di tab Pengaturan Toko → disimpan **terenkripsi** di DB.

---

### Biaya WA API — Cara Perhitungan

Meta charge per conversation (window 24 jam):
- **User-initiated** (pelanggan chat duluan): ~Rp301–400 per conversation
- **Business-initiated** (kamu kirim duluan, notifikasi): ~Rp500–780 per conversation

Kamu membayar Meta dari 1 kartu kredit yang terhubung ke Meta App-mu, mencakup total pemakaian semua client. Biaya ini di-absorb ke harga paket WaKasir (buffer 30–40%). Client tidak perlu punya kartu kredit untuk Meta.

---

### Limitasi Penting

| Item | Penjelasan |
|---|---|
| **Jendela 24 jam** | Pesan teks bebas hanya boleh dikirim dalam 24 jam sejak chat terakhir pelanggan. Di luar itu, wajib pakai **Template Message** yang sudah di-approve Meta. |
| **Template Message** | Didaftarkan per WABA di Meta Business Manager. Kamu perlu bantu client daftarkan template "konfirmasi bayar" dan "update resi" saat onboarding. |
| **Embedded Signup Public** | Butuh review Meta (~1–2 minggu) sebelum bisa dipakai user umum. Selama dev, gunakan tester accounts. |
| **System User Token** | Pakai System User Token (tidak expire), bukan User Access Token (expire 60 hari). Dibuat di Meta Business Manager → System Users. |
| **Quality Rating** | Meta monitor apakah nomor sering di-block. Jaga bot tidak kirim massal tanpa opt-in. |

---
## 6. Tech Stack — Detail Lengkap

### Backend: Laravel 12 (PHP 8.3)

| Layer | Teknologi |
|---|---|
| Framework | Laravel 12 |
| Auth API | Laravel Sanctum (token SPA) |
| Queue | Laravel Horizon + Redis driver |
| HTTP client | Guzzle (built-in Laravel) |
| Upload media | spatie/laravel-medialibrary |
| Excel | maatwebsite/excel |
| PDF | barryvdh/laravel-dompdf (v2) |
| Redis client | predis/predis |
| Debug (dev) | laravel/telescope |
| Permissions | spatie/laravel-permission (v2 multi-staff) |

### Frontend: Angular 22 (SPA)

| Layer | Teknologi |
|---|---|
| Framework | Angular 22 (standalone components) |
| UI Components | PrimeNG 21 |
| Charts | Chart.js + ng2-charts |
| HTTP + Auth | HttpClient + custom auth interceptor (Sanctum token) |
| Forms | Reactive Forms |
| WA Signup | Facebook JS SDK (Embedded Signup) |

### Struktur Modul Backend (Domain-Driven)

```
app/
├── Domain/
│   ├── Bot/            — StateMachine, MessageHandler, IntentParser
│   ├── Catalog/        — ProductService, CategoryService
│   ├── Order/          — OrderService, OrderStatusService
│   ├── Payment/        — MidtransService, PaymentService
│   ├── Shipping/       — RajaOngkirService, ShippingService
│   └── Tenant/         — BusinessService, SubscriptionService, WhatsAppService
├── Http/Controllers/Api/
│   ├── AuthController, BusinessController, CustomerController
│   ├── DashboardController, OrderController, ProductController, SettingController
├── Http/Resources/     — JSON transformers per entitas
├── Jobs/
│   ├── ProcessIncomingWhatsAppMessage
│   ├── ProcessMidtransWebhook
│   └── SendWhatsAppNotification
└── Models/             — Business, Product, Order, Customer, Conversation, ...
```

### Struktur Modul Frontend (Feature-Based)

```
src/app/
├── core/               — AuthService, guards, interceptors, theme tokens
├── shared/             — reusable components, pipes (RupiahPipe, WaIconComponent)
└── features/
    ├── auth/           — login, register
    ├── dashboard/      — KPI cards, sales chart, live polling 30s
    ├── products/       — CRUD produk + varian
    ├── orders/         — list + detail inline dialog + update resi
    ├── customers/      — list + detail dengan riwayat order
    ├── bot-settings/   — pesan sapaan, jam operasional
    ├── store-settings/ — profil toko, WA (Embedded Signup), Midtrans, RajaOngkir
    ├── subscription/   — paket, kuota, upgrade
    └── reports/        — grafik penjualan, produk terlaris, kota teratas
```

---
## 7. State Machine Bot

| State | Trigger Masuk | Aksi Bot | Keluar Ke |
|---|---|---|---|
| `IDLE` | Pesan pertama / "menu" / "halo" | Kirim menu utama | BROWSING |
| `BROWSING` | Pilih Katalog | Kirim daftar produk bernomor | SELECTING_VARIANT / SELECTING_QTY |
| `SELECTING_VARIANT` | Produk punya varian | Tampilkan pilihan varian | SELECTING_QTY |
| `SELECTING_QTY` | Varian dipilih / produk tanpa varian | Tanya jumlah (pcs) | CART_REVIEW |
| `CART_REVIEW` | Qty dikonfirmasi | Ringkasan cart, tanya tambah | BROWSING (ya) / SELECTING_CITY (tidak) |
| `SELECTING_CITY` | Checkout | Autocomplete kota, disambiguation | SELECTING_COURIER |
| `SELECTING_COURIER` | Kota valid | Hitung ongkir + tampilkan kurir | AWAITING_PAYMENT |
| `AWAITING_PAYMENT` | Kurir dipilih | Buat Order + QRIS Midtrans | PAID_AWAITING_ADDRESS (settlement) / EXPIRED |
| `PAID_AWAITING_ADDRESS` | Webhook settlement | Minta alamat lengkap | COMPLETED |
| `COMPLETED` | Alamat diterima | Konfirmasi final + notif owner | IDLE (reset) |
| `EXPIRED` | QR > 15 menit | Info expired, tawarkan buat ulang | AWAITING_PAYMENT / IDLE |
| `FALLBACK_CS` | 2x input tidak dikenali | Alihkan ke admin manual | IDLE (setelah admin selesai) |

**Ketentuan:**
- Session timeout **30 menit** tanpa aktivitas → auto reset ke IDLE
- Ketik "menu", "reset", "halo", "mulai" → selalu kembali ke IDLE dari state apapun
- Fallback threshold: 2x berturut-turut tidak dikenali → FALLBACK_CS
- Jendela 24 jam Meta: notifikasi di luar window wajib Template Message

---

## 8. Skema Database

```
businesses
  id, name
  wa_phone_id, wa_phone_number             — dari Embedded Signup
  wa_access_token (encrypted), wa_waba_id  — BSP token per-client
  wa_token_expires_at, wa_connected
  midtrans_server_key (encrypted), midtrans_client_key (encrypted), midtrans_merchant_id
  rajaongkir_api_key (encrypted), origin_city_id, origin_address
  subscription_plan, status, subscription_ends_at
  bot_settings (json), operating_hours (json)

users
  id, name, email, password, business_id, role

subscriptions
  id, business_id, plan, quota_conversation, quota_used, max_products
  renewed_at, ends_at, status

products
  id, business_id, name, description, price, stock, weight_gram
  category, image_url, is_active

product_variants
  id, product_id, variant_name, stock_override, price_override, is_active

customers
  id, business_id, wa_number, name, email

conversations
  id, customer_id, current_state, cart_data (json)
  selected_city_id, selected_city_name, selected_courier (json)
  fallback_count, last_activity_at

orders
  id, business_id, customer_id, order_number
  subtotal, shipping_cost, total_amount
  status, courier_name, courier_service, tracking_number
  paid_at, shipped_at, completed_at

order_items
  id, order_id, product_id, variant_id (nullable)
  qty, price_at_order, variant_name

payments
  id, order_id, midtrans_transaction_id, payment_type
  qr_code_url, status, amount, expires_at, paid_at, payment_details (json)

addresses
  id, order_id, city_id, city_name, subdistrict_id, subdistrict_name
  full_address, recipient_name, recipient_phone, postal_code, notes

shipping_cache
  id, city_id, province_id, city_name, province_name, city_type, postal_code
  (cache dari RajaOngkir, refresh berkala via artisan command)

message_logs
  id, business_id, wa_number, conversation_id
  direction (in/out), content, message_type, metadata (json), timestamp
```

---
## 9. Dashboard Owner — Spesifikasi per Halaman

### 9.1 Dashboard Home (Ringkasan)
- KPI cards: omzet hari ini, order hari ini, perlu diproses, total pelanggan
- Grafik penjualan 7d/30d/90d (line chart, live polling 30 detik)
- Badge notifikasi order baru (muncul otomatis saat pending bertambah)
- Tabel recent orders + produk stok menipis

### 9.2 Menu Produk
- Tabel + filter status aktif/nonaktif, search nama
- Form CRUD: nama, deskripsi, harga, stok, **berat gram (wajib)**, kategori, foto
- Sub-form varian: ukuran/warna per produk, stok & harga override per varian
- Toggle aktif/nonaktif tanpa hapus data
- Import massal Excel (v2)

### 9.3 Menu Pesanan
- Tabel dengan filter: status, tanggal, search (nomor order / WA)
- Detail inline dialog: items, subtotal, ongkir, total, customer, alamat, kurir, payment
- Timeline status visual (pending → paid → processing → shipped → completed)
- Input resi → bot kirim notif ke pelanggan otomatis
- Aksi: mulai proses, batalkan, tandai selesai

### 9.4 Menu Pelanggan
- Tabel: nomor WA, nama, total order, total belanja, terakhir transaksi
- Klik → modal detail riwayat order lengkap per customer
- Direct link ke WhatsApp (wa.me)
- Server-side pagination + search

### 9.5 Menu Pengaturan Bot
- Edit pesan sapaan, pesan fallback
- Jam operasional (enable/disable + waktu + timezone)
- (v2) Template pesan Meta: daftarkan + preview

### 9.6 Menu Pengaturan Toko & Integrasi
- Tab Profil: nama toko, status akun
- Tab WhatsApp: tombol **"Hubungkan WhatsApp"** (Embedded Signup)
  - Status koneksi (terhubung/terputus + nomor aktif)
  - Tombol disconnect
- Tab Midtrans: Server Key + Client Key (masked), environment sandbox/production
- Tab RajaOngkir: API Key (masked), kota asal, alamat toko, pilih kurir aktif

### 9.7 Menu Langganan
- Paket aktif, tanggal perpanjangan, progress bar kuota
- Tabel perbandingan paket + tombol upgrade
- Alert jika kuota > 80%

### 9.8 Menu Laporan
- Period selector: 7d / 30d / 90d
- Grafik penjualan (line chart), produk terlaris (bar chart horizontal)
- Kota tujuan teratas (bar progress list)
- Summary status order (total, selesai, dikirim, dibatalkan)

---

## 10. Katalog Masalah & Solusi (Edge Cases)

### 10.1 WhatsApp & Onboarding BSP
| Masalah | Solusi |
|---|---|
| Client tidak punya akun Meta Developer | Embedded Signup — client hanya login Facebook biasa, tidak perlu developer console |
| Token WA expired (User Token 60 hari) | Pakai System User Token (tidak expire) — dibuat di Meta Business Manager → System Users |
| Nomor WA client di-banned Meta | Jaga bot tidak broadcast tanpa opt-in; monitor Quality Rating di dashboard Meta |
| Embedded Signup belum di-approve Meta | Selama review, onboard client manual (masukkan phone_number_id & token manual) |
| Pesan di luar jendela 24 jam | Wajib Template Message yang sudah approve Meta; daftarkan template notifikasi saat onboarding |

### 10.2 Ongkir & Pengiriman
| Masalah | Solusi |
|---|---|
| Ongkir beda tiap kota | Integrasi RajaOngkir real-time per checkout |
| Pelanggan typo nama kota | Autocomplete dari cache DB + tampilkan 5 kandidat untuk dipilih |
| Kurir tidak cover kota tujuan | Hanya tampilkan kurir yang mengembalikan result dari API |
| Berat produk tidak diisi | Field wajib saat buat produk; warning di dashboard jika kosong |
| Ambil sendiri di toko | Opsi tetap ditambahkan di daftar kurir dengan ongkir Rp0 |

### 10.3 Pembayaran
| Masalah | Solusi |
|---|---|
| QR expired sebelum bayar | State EXPIRED → tawarkan generate ulang (cart tetap tersimpan) |
| Ubah pesanan setelah QR muncul | Cancel transaksi Midtrans lama dulu via API, baru generate QR baru |
| Refund | v1: manual owner (update status + notif); v2: Midtrans refund API |
| Race condition stok terakhir | Lock row `SELECT ... FOR UPDATE` saat generate QR |

### 10.4 Bot & Percakapan
| Masalah | Solusi |
|---|---|
| Input tidak dikenali 2x | FALLBACK_CS → admin takeover manual |
| Session terbengkalai | Timeout 30 menit → auto reset IDLE |
| Pelanggan ketik "menu" di tengah alur | Selalu reset ke IDLE, tidak peduli state aktif |
| Banyak pelanggan bersamaan | Session diisolasi per `(business_id, wa_number)` |

### 10.5 Kepatuhan
| Masalah | Solusi |
|---|---|
| Data antar tenant bocor | Query selalu scoped ke `business_id`; token WA tidak pernah dikembalikan ke frontend |
| RajaOngkir larangan auto-request | Semua API call ongkir/resi dipicu aksi user nyata (bukan cron) |
| Broadcast spam | Tidak ada fitur broadcast di v1; v2 hanya dengan opt-in eksplisit |

---
## 11. Fitur MVP vs V2

### MVP — Target selesai (implementasi sudah dikerjakan)

**Backend (Laravel 12)**
- [x] Multi-tenant isolasi via `business_id`
- [x] Auth: register, login, logout, me (Sanctum)
- [x] CRUD Produk + varian + upload foto (MediaLibrary)
- [x] Bot engine: StateMachine + MessageHandler + IntentParser
  - [x] States: IDLE, BROWSING, SELECTING_VARIANT, SELECTING_QTY, CART_REVIEW,
        SELECTING_CITY, SELECTING_COURIER, AWAITING_PAYMENT, PAID_AWAITING_ADDRESS,
        COMPLETED, EXPIRED, FALLBACK_CS
  - [x] Multi-item cart, session timeout 30 menit, operating hours
  - [x] Product matching by number or name, city disambiguation
- [x] Integrasi RajaOngkir (cache kota, hitung ongkir, track resi)
- [x] Integrasi Midtrans Core API (QRIS charge, webhook settlement)
  - [x] Per-business key injection (tiap client pakai Midtrans mereka sendiri)
- [x] Order management: create, paginate, filter, cancel (restore stock), stats
- [x] Notifikasi WA: kirim resi, konfirmasi bayar, notif owner
- [x] WhatsApp webhook: GET challenge, POST routing per phone_number_id
- [x] CustomerController dengan stats per customer
- [x] Dashboard API: KPI, sales chart, top products, top cities
- [x] Settings API: bot settings, operating hours, subscription, categories
- [x] Subscription: quota tracking, upgrade plan, limit produk per plan
- [x] Queue Jobs: ProcessIncomingWhatsAppMessage, ProcessMidtransWebhook,
      SendWhatsAppNotification (retry 3x, failed handler)
- [x] Migrations: fallback_count di conversations, wa_number di message_logs,
      wa_access_token + wa_waba_id + wa_connected di businesses

**Frontend (Angular 22)**
- [x] Auth: login, register, route guards, Sanctum interceptor
- [x] Dashboard: KPI, sales chart, live polling 30 detik, badge notif order baru
- [x] Produk: list + CRUD + varian
- [x] Pesanan: list paginasi + filter + detail dialog + resi + status update
- [x] Pelanggan: list paginasi + search + detail modal (riwayat order)
- [x] Bot Settings: pesan sapaan, fallback, jam operasional
- [x] Store Settings: profil toko, tab WA (siap Embedded Signup), Midtrans, RajaOngkir
- [x] Langganan: paket aktif, kuota, upgrade
- [x] Laporan: grafik penjualan, produk terlaris, kota teratas, status summary

### V2 — Setelah 3–5 toko aktif & tervalidasi

- [ ] Embedded Signup self-service (butuh approve Meta)
- [ ] WhatsAppService per-business token di SendWhatsAppNotification
- [ ] Import produk massal Excel
- [ ] Template Message Meta (daftar + kirim dari dashboard)
- [ ] Refund otomatis via Midtrans API
- [ ] Invoice PDF otomatis (dikirim ke pelanggan via WA)
- [ ] COD support
- [ ] Manajemen tim/staff per toko (spatie/laravel-permission)
- [ ] Broadcast promo (opt-in saja)
- [ ] Biteship untuk pickup otomatis
- [ ] WhatsApp Flows (form alamat terstruktur, bukan free text)

---

## 12. Model Bisnis & Harga

| Paket | Harga/bulan | Kuota Conversation | Maks Produk |
|---|---|---|---|
| Starter | Rp99.000 | 200 | 30 |
| Growth | Rp249.000 | 600 | 200 |
| Pro | Rp499.000 | 1.500 | Unlimited |

**Komponen biaya yang kamu tanggung:**
- Biaya conversation Meta: ~Rp301–780/conversation (user-initiated lebih murah)
- Buffer 30–40% dari harga paket untuk menutup biaya Meta
- Kamu bayar Meta 1 tagihan global dari semua client; client tidak perlu kartu kredit Meta

---

## 13. Rencana Eksekusi 8 Minggu

| Minggu | Fokus |
|---|---|
| 1 | Database schema + Laravel setup + Sanctum auth + Angular skeleton |
| 2 | Webhook WA (GET challenge + POST routing) + queue/Horizon + kirim pesan dasar |
| 3 | Bot flow: katalog → varian → qty → cart → multi-item |
| 4 | RajaOngkir: cache kota, hitung ongkir, pilih kurir |
| 5 | Midtrans QRIS: generate + webhook settlement + state PAID_AWAITING_ADDRESS |
| 6 | Dashboard Angular lengkap: semua halaman |
| 7 | End-to-end test dengan 1–2 toko beta; onboarding manual (belum Embedded Signup) |
| 8 | Fix feedback beta + landing page + submit Embedded Signup review ke Meta |

---

## 14. Risiko & Mitigasi

| Risiko | Mitigasi |
|---|---|
| Meta suspend nomor WA client | Tidak ada broadcast tanpa opt-in; pakai template resmi untuk di luar 24 jam |
| Embedded Signup review lambat | MVP onboarding manual dulu; Embedded Signup push setelah ada client nyata |
| Biaya conversation Meta membengkak | Monitor pemakaian per toko; alert owner sebelum kuota habis |
| QR expired sebelum bayar | Reminder menit ke-10 + regenerate mudah (cart tetap) |
| Race condition stok | SELECT FOR UPDATE saat generate QR |
| RajaOngkir rate limit | Semua API call dipicu aksi user; cache data statis kota/provinsi |
| Token WA client expire | System User Token (tidak expire); monitoring wa_token_expires_at |
| Data bocor antar tenant | Semua query scoped `business_id`; token tidak pernah ke frontend |

---

## 15. Strategi Promosi

Alur "chat → pilih produk → QR muncul otomatis → bayar → selesai" sangat visual:
- **Rekam demo dari HP**: proses order dari sisi pelanggan 30 detik — QR muncul sendiri
- **Testimoni toko beta**: "dulu balas chat manual 3 jam sehari, sekarang bot yang handle"
- **Series building in public**: dokumentasi proses bangun SaaS dari nol — journey lebih menarik dari sekadar promosi produk jadi
- **Konten teknis**: "cara satu WA bot melayani 50 toko berbeda dengan 1 server" — narasi BSP ini unik dan jarang dibahas

---

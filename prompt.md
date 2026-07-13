# WaKasir — WhatsApp Commerce Bot SaaS
## Dokumen Produk Lengkap (Master Spec)
*Disusun 12 Juli 2026 — versi 2.0 (menggabungkan seluruh riset & revisi sebelumnya)*

---

## Daftar Isi
1. Ringkasan Produk & Posisi Pasar
2. Alur Lengkap Pelanggan (Sudah Direvisi: Ongkir Sebelum Bayar)
3. Alur Pemilik Toko
4. Arsitektur Teknis
5. Tech Stack Laravel — Detail Lengkap
6. State Machine Bot
7. Skema Database
8. Dashboard Owner — Spesifikasi Lengkap per Halaman
9. Katalog Masalah & Solusi (Edge Cases)
10. Fitur MVP vs V2
11. Model Bisnis & Harga
12. Rencana Eksekusi 8 Minggu
13. Risiko & Mitigasi
14. Strategi Promosi
15. Langkah Selanjutnya

---

## 1. Ringkasan Produk & Posisi Pasar

**WaKasir** adalah SaaS multi-tenant yang menghubungkan nomor WhatsApp Business milik toko/UKM ke satu bot otomatis yang bisa: menjawab pertanyaan, menampilkan katalog, memproses pesanan sampai selesai, menghitung ongkir otomatis, generate QRIS pembayaran via Midtrans, konfirmasi pembayaran otomatis, dan meminta alamat pengiriman — semuanya tanpa admin toko perlu balas chat manual.

**Kenapa ini celah pasar yang valid:** solusi WA API di Indonesia saat ini terbagi dua kutub — chatbot AI generik murah (Rp25rb–150rb/bulan) yang fokus jawab pertanyaan tapi tidak generate pembayaran+ongkir otomatis, atau platform omnichannel lengkap (Rp500rb–2,5jt/bulan) yang terlalu kompleks dan mahal untuk toko kecil. Belum ada yang fokus spesifik pada alur "chat → pilih produk → hitung ongkir → bayar QRIS → kirim" dalam satu paket harga UKM.

**Modal utama Anda:** sistem ini adalah gabungan dari 2 hal yang sudah pernah Anda bangun di produksi — integrasi Midtrans (VA/QRIS generation, webhook status) di sistem sekolah & tiketing, dan integrasi WhatsApp Cloud API (kirim pesan, webhook, notifikasi otomatis) di sistem sekolah. Ini bukan riset teknologi baru, tapi rekombinasi dari yang sudah teruji.

---

## 2. Alur Lengkap Pelanggan (Sudah Direvisi: Ongkir Sebelum Bayar)

> **Catatan penting:** versi awal alur ini menanyakan alamat SETELAH bayar. Itu keliru — ongkir berbeda tiap kota, jadi kota tujuan harus diketahui SEBELUM QR digenerate supaya total tagihan benar. Alur di bawah ini sudah diperbaiki.

```
1. SAPA & DETEKSI INTENT
   Pelanggan chat → Bot: "Halo! Selamat datang di [Nama Toko] 👋
   1️⃣ Lihat Katalog  2️⃣ Tanya Produk  3️⃣ Cek Status Pesanan"

2. TAMPILKAN KATALOG (WhatsApp Interactive List)
   Bot ambil data dari database produk toko → kirim sebagai list/carousel

3. PILIH PRODUK + JUMLAH (+ VARIAN jika ada)
   "Baju Batik Lengan Panjang - Rp150.000. Mau berapa pcs? Ukuran?"

4. RINGKASAN KERANJANG
   "Baju Batik x2 = Rp300.000. Tambah produk lain? (Ya/Tidak)"

5. TANYA KOTA/KECAMATAN TUJUAN (BARU — sebelum bayar)
   Bot: "Kirim ke kota mana?"
   Pelanggan ketik sebagian nama → bot cocokkan dari cache data kota (autocomplete)
   → konfirmasi kota yang benar (hindari typo "Jkt" vs "Jakarta")

6. HITUNG ONGKIR OTOMATIS (Panggil API RajaOngkir)
   Bot: "Pilihan pengiriman:
        1. JNE Reguler - Rp25.000 (2-3 hari)
        2. JNE YES - Rp98.000 (1 hari)
        3. Ambil Sendiri di Toko - Gratis"
   Pelanggan pilih salah satu

7. TOTAL FINAL = Harga Produk + Ongkir
   Bot: "Total: Rp325.000 (produk Rp300.000 + ongkir Rp25.000)
        Lanjut ke pembayaran?"

8. GENERATE QRIS OTOMATIS (Midtrans Core API, charge type QRIS)
   Bot kirim gambar QR + Order ID + timer 15 menit

9. PELANGGAN BAYAR (lewat e-wallet/mbanking apapun via QRIS)

10. WEBHOOK MIDTRANS "settlement" MASUK KE SERVER
    Server update status → trigger bot kirim konfirmasi otomatis
    Bot: "✅ Pembayaran diterima! Sekarang kirim alamat lengkap
         (nama jalan, RT/RW, patokan) untuk pengiriman:"

11. PELANGGAN KIRIM ALAMAT DETAIL
    (Kota sudah didapat di step 5 — ini tinggal detail jalan/rumah)
    Bot: "Alamat diterima. Order #1234 sedang diproses."

12. NOTIFIKASI KE OWNER (dashboard + opsional WA terpisah)
    "🔔 Order baru #1234 - Rp325.000 - LUNAS
     Kirim ke: [alamat] via JNE Reguler"

13. (OPSIONAL) BOT KIRIM UPDATE RESI saat owner input nomor resi di dashboard
```

---

## 3. Alur Pemilik Toko

```
1. ONBOARDING
   Sign up → pilih paket → connect nomor WhatsApp Business (Embedded Signup)
   → connect akun Midtrans sendiri (Server Key/Client Key) → connect API key RajaOngkir
   → input alamat asal toko (untuk hitung ongkir)

2. SETUP KATALOG
   Upload produk: nama, harga, stok, foto, kategori, BERAT (gram — wajib untuk ongkir)

3. KUSTOMISASI BOT
   Atur pesan sapaan, jam operasional, template konfirmasi

4. PANTAU ORDER via Dashboard (detail di Bagian 8)

5. INPUT RESI saat barang dikirim → bot otomatis notif ke pelanggan
```

---

## 4. Arsitektur Teknis

```
┌─────────────┐   Webhook Pesan Masuk    ┌────────────────────────┐
│  Meta WA     │ ───────────────────────▶│  Laravel Backend        │
│  Cloud API   │◀─────────────────────── │  (API + Bot Engine)     │
└─────────────┘   Kirim Balasan Pesan    └───────────┬─────────────┘
                                                       │
                        ┌──────────────────────────────┼──────────────────────────┐
                        ▼                              ▼                          ▼
                ┌───────────────┐            ┌──────────────────┐        ┌───────────────┐
                │ MySQL/Postgres│            │  Midtrans Core API│        │  RajaOngkir    │
                │ (tenant, produk│            │  (QRIS charge,    │        │  API (ongkir,  │
                │  order, dst)   │            │  webhook status)  │        │  kota, kurir)  │
                └───────────────┘            └──────────────────┘        └───────────────┘
                        ▲
                        │
                ┌───────┴────────┐         ┌──────────────────────┐
                │ Redis           │         │ Laravel REST API      │
                │ (session bot,   │◀───────▶│ (Sanctum auth)        │
                │  cart, cache    │         └──────────┬────────────┘
                │  kota)          │                    │
                └────────────────┘                    ▼
                        ▲                    ┌──────────────────────┐
                        │                    │ Angular Admin          │
                ┌───────┴────────┐           │ Dashboard (SPA, owner) │
                │ Laravel Queue/  │           └──────────────────────┘
                │ Horizon (proses │
                │ webhook async)  │
                └────────────────┘
```

**Prinsip desain penting:**
- **Webhook harus jawab cepat** (Meta & Midtrans butuh respons cepat, biasanya <5 detik) → webhook controller cukup validasi + push ke queue job, proses berat (update DB, kirim pesan balasan) dikerjakan job di background via Laravel Horizon.
- **Session percakapan disimpan di Redis**, bukan database utama — supaya cepat diakses dan bisa expire otomatis (TTL) tanpa perlu cron pembersihan manual.
- **Data kota/provinsi/kecamatan dari RajaOngkir di-cache** di database lokal (bukan panggil API tiap kali pelanggan ketik nama kota) — RajaOngkir sendiri mengizinkan cache untuk data ini, sementara endpoint cek ongkir/tracking resi harus selalu request langsung karena datanya real-time.
- **Dashboard Angular terpisah dari backend** — komunikasi murni lewat REST API (Laravel Sanctum untuk autentikasi token-based SPA). Ini beda dari pendekatan admin-panel-jadi-satu seperti Filament; Anda perlu membangun API Controller + API Resource untuk tiap entitas (produk, order, dst) yang dikonsumsi Angular.

---

## 5. Tech Stack Laravel — Detail Lengkap

### Core Framework
- **Laravel 12** (PHP 8.3) — sudah Anda kuasai penuh
- **Laravel Sanctum** — autentikasi API (untuk dashboard SPA jika dipisah dari admin panel)
- **Laravel Horizon** — monitoring & manajemen queue (krusial karena webhook harus diproses async)
- **Laravel Queue (Redis driver)** — job untuk kirim pesan WA, proses webhook, generate laporan

### Admin Dashboard (Owner)
**Stack: Angular (SPA) + Laravel REST API terpisah** — backend dan frontend jadi 2 project independen, komunikasi murni via API.

**Yang perlu disiapkan di sisi Laravel:**
- **Laravel Sanctum** untuk autentikasi token-based (login owner dari Angular, dapat token, dipakai di tiap request API)
- **API Controller + API Resource** untuk tiap entitas (ProductController, OrderController, dst) — setiap halaman dashboard di Bagian 8 butuh endpoint REST tersendiri (`GET /api/products`, `POST /api/products`, dst)
- **CORS dikonfigurasi** dengan benar (`config/cors.php`) supaya Angular (domain berbeda) bisa akses API Laravel
- **API Resource classes** (`php artisan make:resource`) untuk transformasi data konsisten dari Eloquent model ke JSON yang dikonsumsi Angular

**Yang perlu disiapkan di sisi Angular:**
- **Angular Material** atau **PrimeNG** untuk komponen UI siap pakai (tabel, form, chart) — mempercepat pembangunan halaman CRUD dibanding bikin komponen dari nol
- **HttpClient + Interceptor** untuk otomatis menyisipkan token Sanctum di tiap request, dan menangani error 401 (redirect ke login)
- **NgRx** (opsional, kalau state makin kompleks) atau cukup **Services + RxJS** untuk state management sederhana di awal — untuk skala dashboard ini, service-based state biasanya cukup, NgRx baru relevan kalau nanti fitur bertambah banyak
- **Reactive Forms** untuk semua form (produk, pengaturan, dst) — cocok untuk validasi kompleks seperti field berat produk yang wajib diisi

**Trade-off dibanding Filament (untuk transparansi keputusan):** karena backend dan frontend terpisah penuh, waktu development dashboard akan **lebih lama** daripada pakai admin panel Laravel siap pakai — Anda perlu bangun API Controller + API Resource untuk tiap entitas, plus komponen Angular untuk tiap halaman. Trade-off ini sepadan kalau Anda memang lebih familiar dan cepat kerja di Angular, dan kalau ke depan mau dashboard dengan UX/branding yang lebih kustom daripada tampilan admin-panel generik.

### Package Pendukung (Laravel Backend)
| Package | Fungsi |
|---|---|
| `laravel/sanctum` | Autentikasi token-based untuk Angular SPA (login owner, proteksi API) |
| `spatie/laravel-permission` | RBAC — sudah Anda kuasai, dipakai kalau toko punya multi-admin/staff |
| `spatie/laravel-multitenancy` atau custom scoping via `business_id` | Isolasi data antar tenant (toko) |
| `spatie/laravel-medialibrary` | Kelola upload foto produk |
| `guzzlehttp/guzzle` | HTTP client untuk panggil WhatsApp Cloud API, Midtrans, RajaOngkir |
| `maatwebsite/excel` | Import produk massal, export laporan penjualan (reuse skill xlsx Anda) |
| `barryvdh/laravel-dompdf` | Generate invoice PDF (fitur v2) |
| `predis/predis` | Redis client untuk session percakapan bot |
| `laravel/telescope` (dev only) | Debug request/job selama development |
| `spatie/laravel-webhook-client` | Validasi & terima webhook Midtrans dengan aman (verifikasi signature) |
| `fruitcake/laravel-cors` (built-in Laravel 12) | Konfigurasi CORS supaya Angular bisa akses API |

### Package Pendukung (Angular Frontend)
| Package | Fungsi |
|---|---|
| `@angular/material` atau `primeng` | Komponen UI siap pakai: tabel data, form, chart, modal |
| `ngx-charts` atau `chart.js` + `ng2-charts` | Grafik penjualan & laporan di halaman Ringkasan/Laporan |
| `@auth0/angular-jwt` atau interceptor custom | Kelola token Sanctum, auto-attach ke header request |
| `ngx-toastr` | Notifikasi sukses/gagal untuk aksi CRUD |

### Struktur Modul Backend (Domain-Driven, sesuai pola yang sudah Anda pakai di proyek sekolah)
```
app/
├── Domain/
│   ├── Bot/            (state machine, message handler, intent parser)
│   ├── Catalog/        (produk, kategori, stok)
│   ├── Order/          (order, order_items, status lifecycle)
│   ├── Payment/        (Midtrans integration, webhook handler)
│   ├── Shipping/       (RajaOngkir integration, kota cache, kurir)
│   └── Tenant/         (business, subscription, onboarding)
├── Http/
│   ├── Controllers/Api/  (ProductController, OrderController, DashboardController, dst)
│   └── Resources/        (ProductResource, OrderResource — transformasi JSON untuk Angular)
├── Jobs/
│   ├── ProcessIncomingWhatsAppMessage.php
│   ├── ProcessMidtransWebhook.php
│   └── SendWhatsAppNotification.php
```

### Struktur Modul Frontend (Angular, feature-based)
```
src/app/
├── core/               (auth service, http interceptor, guards)
├── shared/             (komponen reusable: tabel, modal, chart)
├── features/
│   ├── dashboard/      (halaman Ringkasan)
│   ├── products/       (Menu Produk — list, form tambah/edit, varian)
│   ├── orders/         (Menu Pesanan — list, detail, update status/resi)
│   ├── customers/      (Menu Pelanggan)
│   ├── bot-settings/    (Menu Pengaturan Bot)
│   ├── store-settings/  (Menu Pengaturan Toko & Integrasi)
│   ├── subscription/    (Menu Langganan & Billing)
│   └── reports/         (Menu Laporan)
```

---

## 6. State Machine Bot

| State | Trigger Masuk | Aksi Bot | Trigger Keluar |
|---|---|---|---|
| `IDLE` | Pesan pertama / "menu" | Kirim menu utama | User pilih opsi |
| `BROWSING` | Pilih "Lihat Katalog" | Kirim list produk | User pilih produk |
| `SELECTING_QTY` | Pilih 1 produk | Tanya jumlah (+varian) | User kirim angka |
| `CART_REVIEW` | Qty dikonfirmasi | Ringkasan, tanya tambah lagi | Ya → BROWSING; Tidak → lanjut |
| `SELECTING_CITY` | Checkout dikonfirmasi | Tanya kota tujuan, autocomplete | Kota dikonfirmasi |
| `SELECTING_COURIER` | Kota valid | Panggil API ongkir, tampilkan opsi kurir | User pilih kurir |
| `AWAITING_PAYMENT` | Kurir dipilih, total final dihitung | Generate QRIS via Midtrans, kirim gambar | Webhook Midtrans masuk / expired |
| `PAID_AWAITING_ADDRESS` | Webhook status = settlement | Kirim pesan minta alamat detail | User kirim alamat |
| `COMPLETED` | Alamat diterima | Konfirmasi final, notif ke owner | — |
| `EXPIRED` | QR lewat 15 menit tanpa bayar | Info kadaluarsa, tawarkan generate ulang | User minta QR baru |
| `FALLBACK_CS` | Bot tidak mengenali input 2x berturut | Alihkan ke admin, matikan bot sementara | Admin ambil alih manual |

**Catatan jendela 24 jam:** WhatsApp hanya izinkan pesan bebas dalam 24 jam sejak chat terakhir pelanggan. Kalau webhook pembayaran masuk setelah lewat 24 jam (pelanggan bayar telat), notifikasi `PAID_AWAITING_ADDRESS` harus pakai **template message** yang sudah di-approve Meta, bukan pesan bebas.

---

## 7. Skema Database

```
businesses        : id, name, wa_phone_id, midtrans_server_key, midtrans_client_key,
                     rajaongkir_api_key, origin_city_id, subscription_plan, status

products          : id, business_id, name, price, stock, weight_gram, image_url, category

product_variants  : id, product_id, variant_name (misal "Size: L"), stock_override

customers         : id, business_id, wa_number, name

conversations     : id, customer_id, current_state, cart_data(json),
                     selected_city_id, selected_courier(json), updated_at

orders            : id, business_id, customer_id, subtotal, shipping_cost, total_amount,
                     status, midtrans_order_id, courier_name, courier_service

order_items       : id, order_id, product_id, variant_id(nullable), qty, price_at_order

payments          : id, order_id, midtrans_transaction_id, qr_code_url, status, paid_at

addresses         : id, order_id, city_id, subdistrict, full_address, recipient_name, postal_code

shipping_cache    : id, province_id, city_id, city_name, subdistrict_id, subdistrict_name
                     (cache data wilayah RajaOngkir, refresh berkala)

message_logs      : id, conversation_id, direction(in/out), content, timestamp

subscriptions     : id, business_id, plan, quota_conversation, quota_used, renewed_at
```

---

## 8. Dashboard Owner — Spesifikasi Lengkap per Halaman

### 8.1 Halaman Ringkasan (Dashboard Home)
- Kartu ringkasan: omzet hari ini, jumlah order hari ini, order menunggu diproses, order menunggu pembayaran
- Grafik penjualan 7/30 hari terakhir
- Notifikasi order baru real-time (badge counter)

### 8.2 Menu Produk
- Tabel produk: nama, harga, stok, berat, status aktif/nonaktif
- Form tambah/edit produk: nama, deskripsi, harga, stok, **berat (gram, wajib)**, kategori, upload foto (bisa multi-foto)
- Fitur varian: tambah opsi ukuran/warna per produk dengan stok terpisah opsional
- Import massal via Excel (template disediakan) — reuse kemampuan xlsx Anda
- Toggle "nonaktifkan sementara" untuk produk yang stok habis, tanpa perlu hapus data

### 8.3 Menu Pesanan
- Tabel order dengan filter: status (menunggu bayar/lunas/diproses/dikirim/selesai/dibatalkan), tanggal, kota tujuan
- Detail order: item dibeli, subtotal, ongkir, total, data pelanggan, alamat lengkap, kurir dipilih
- Aksi: input nomor resi (trigger notifikasi otomatis ke pelanggan), tandai "dibatalkan"/"refund", tandai "selesai"
- Search berdasarkan nomor order atau nomor WA pelanggan

### 8.4 Menu Pelanggan
- Riwayat pembeli: nomor WA, nama (jika ada), total transaksi, total order
- Bisa lihat riwayat chat/order per pelanggan untuk konteks kalau ada komplain

### 8.5 Menu Pengaturan Bot
- Edit pesan sapaan otomatis
- Atur jam operasional (di luar jam, bot tetap terima order tapi kasih info "diproses besok")
- Preview simulasi percakapan bot (test sebelum publish perubahan)
- Kelola template pesan yang sudah di-approve Meta (untuk notifikasi di luar jendela 24 jam)

### 8.6 Menu Pengaturan Toko & Integrasi
- Connect/reconnect nomor WhatsApp Business (status koneksi: aktif/terputus)
- Input kredensial Midtrans (Server Key, Client Key) milik toko sendiri
- Input API key RajaOngkir + pilih alamat asal toko (kota/kecamatan untuk hitung ongkir)
- Pilih kurir yang diaktifkan (JNE, J&T, SiCepat, dst — sesuai yang didukung RajaOngkir)

### 8.7 Menu Langganan & Billing
- Paket aktif, tanggal perpanjangan
- Pemakaian kuota conversation bulan ini (progress bar, alert kalau mendekati batas)
- Riwayat pembayaran langganan
- Upgrade/downgrade paket

### 8.8 Menu Laporan
- Grafik penjualan per hari/minggu/bulan
- Produk terlaris
- Kota tujuan terbanyak (insight untuk strategi ongkir/stok)
- Export laporan ke Excel (reuse skill xlsx)

### 8.9 Manajemen Tim (v2)
- Undang staff/admin tambahan dengan role terbatas (misal: staff hanya bisa lihat & update status order, tidak bisa ubah pengaturan toko) — reuse `spatie/laravel-permission` yang sudah Anda kuasai

---

## 9. Katalog Masalah & Solusi (Edge Cases)

### 9.1 Ongkir & Alamat
| Masalah | Solusi |
|---|---|
| Ongkir beda tiap kota, tidak bisa hardcode | Integrasi RajaOngkir — fitur hitung ongkos kirim otomatis, pelacakan resi, dan pengiriman COD/non-COD via REST/JSON yang kompatibel dengan Laravel. Paket gratis cukup untuk 100 request/hari di awal. |
| RajaOngkir vs Biteship | RajaOngkir menawarkan biaya langganan tetap tanpa tambahan per call, sementara Biteship charge berdasarkan jumlah API request yang bisa membengkak saat traffic tinggi. **Untuk MVP dengan volume belum pasti, RajaOngkir lebih aman.** Biteship unggul di resi otomatis & request pickup langsung — cocok untuk v2 saat volume besar. |
| Pelanggan salah ketik nama kota | Gunakan autocomplete dari cache data kota (RajaOngkir mengizinkan cache data provinsi/kota/kecamatan), tampilkan 3 kandidat kota untuk dikonfirmasi, bukan free text bebas. |
| Alamat di luar jangkauan kurir tertentu | Render ulang daftar kurir dari response API setiap kali — kalau kurir tidak mengembalikan hasil untuk kota tujuan, otomatis hide opsi itu. |
| Berat produk belum diinput | Wajibkan field berat saat input produk; beri default + warning di dashboard jika kosong. |
| Permintaan COD | RajaOngkir mendukung COD dan non-COD, tapi untuk v1 batasi ke non-COD saja (bayar QRIS dulu) — COD butuh alur berbeda (kurir yang nagih), jadi ditunda ke v2. |
| Kirim ke luar negeri | RajaOngkir punya endpoint international origin/destination, tapi untuk MVP jangan didukung dulu — kompleksitas bea cukai & kurs terlalu besar. Bot balas: "hanya melayani domestik". |
| Ambil sendiri di toko | Tambahkan opsi "Ambil Sendiri" di step kurir — skip ongkir, total = harga produk saja. |

### 9.2 Pembayaran
| Masalah | Solusi |
|---|---|
| QR expired sebelum sempat bayar | Reminder otomatis menit ke-10, tombol "Generate QR Baru" tanpa ulang dari awal (cart & alamat tetap tersimpan). |
| Mau ubah pesanan setelah QR digenerate | Cancel transaksi Midtrans lama via API sebelum generate QR baru — hindari 2 tagihan aktif. |
| Refund setelah bayar | v1: manual oleh owner (update status "refund requested" + notifikasi); refund otomatis via API jadi fitur v2. |
| Double order dari 2 device | Tampilkan Order ID jelas di setiap pesan supaya pelanggan sadar ada transaksi berjalan. |

### 9.3 Stok & Produk
| Masalah | Solusi |
|---|---|
| Race condition stok terakhir | Lock stok **saat generate QR**, bukan saat pilih produk — pakai `SELECT ... FOR UPDATE`. |
| Produk punya varian | Sub-state tambahan di `SELECTING_QTY`, simpan sebagai atribut `order_items`, bukan produk terpisah. |
| Stok habis setelah dibayar (multi-channel jualan) | Owner tandai "stok habis" di dashboard → bot otomatis kirim permintaan maaf + info refund. |

### 9.4 Percakapan & UX
| Masalah | Solusi |
|---|---|
| Pesan di luar skrip | Fallback ke `FALLBACK_CS` — matikan bot sementara, alihkan ke admin manual. |
| Pelanggan hilang di tengah alur | Session timeout 30 menit → reset ke `IDLE`. |
| Ubah alamat setelah dikirim ke bot | Command "ubah alamat" selama status belum "dikirim". |
| Banyak pelanggan chat bersamaan | Session per `business_id + wa_number` di Redis, bukan global. |
| Minta invoice formal | v2: generate PDF otomatis, kirim sebagai dokumen di chat. |
| Chat di luar jam operasional | Bot tetap proses, beri info kapan mulai diproses. |

### 9.5 Kepatuhan (Compliance)
| Masalah | Solusi |
|---|---|
| Nomor WA di-banned Meta karena dianggap spam | Jangan broadcast tanpa opt-in; pakai template resmi untuk notifikasi di luar 24 jam. |
| Data pelanggan antar toko tercampur | Isolasi ketat per `business_id` di level query, di dashboard maupun API. |
| RajaOngkir melarang request otomatis tanpa aksi user | Setiap API call ongkir/tracking harus dipicu aksi nyata pelanggan/admin, bukan cron job berulang tanpa henti — dilarang keras "dumping" data ongkir atau auto-update resi otomatis. |

---

## 10. Fitur MVP vs V2

### MVP (Target 8 minggu)
- [ ] Onboarding toko + connect WA number (dibantu manual dulu)
- [ ] Upload produk manual + wajib input berat
- [ ] Bot flow lengkap: sapa → katalog → pilih produk → **kota → ongkir → kurir** → bayar → alamat
- [ ] Integrasi RajaOngkir (paket gratis/starter)
- [ ] Generate QRIS Midtrans otomatis + webhook konfirmasi
- [ ] Lock stok saat generate QR
- [ ] Fallback CS kalau bot tidak paham
- [ ] Laravel Sanctum + API Controller/Resource untuk Produk, Pesanan, Pengaturan Toko, Ringkasan
- [ ] Dashboard Angular: halaman Produk, Pesanan, Pengaturan Toko, Ringkasan (konsumsi API di atas)
- [ ] Isolasi data multi-tenant

### V2 (Setelah 3–5 toko aktif & tervalidasi)
- [ ] Self-service Embedded Signup WA
- [ ] Import produk massal Excel
- [ ] COD, pengiriman internasional
- [ ] Refund otomatis via API
- [ ] Invoice PDF otomatis
- [ ] Manajemen tim/staff (multi-admin per toko)
- [ ] Broadcast promo (dengan opt-in)
- [ ] Upgrade ke Biteship untuk otomatisasi pickup kurir

---

## 11. Model Bisnis & Harga

| Paket | Harga/bulan | Kuota Conversation | Jumlah Produk |
|---|---|---|---|
| Starter | Rp99.000 | ~200 percakapan | 30 produk |
| Growth | Rp249.000 | ~600 percakapan | 200 produk |
| Pro | Rp499.000 | ~1.500 percakapan | Unlimited |

Harga WA API resmi berkisar Rp301–Rp780 per conversation tergantung kategori pesan — sisihkan buffer 30-40% dari harga jual untuk menutup biaya conversation ke Meta.

---

## 12. Rencana Eksekusi 8 Minggu

| Minggu | Fokus |
|---|---|
| 1 | Desain state machine final + skema database + setup project Laravel (backend) & Angular (frontend) + Sanctum auth |
| 2 | Webhook receiver WA + kirim pesan teks dasar + queue/Horizon setup |
| 3 | Flow katalog (interactive list) + pilih produk + qty + varian |
| 4 | Integrasi RajaOngkir: cache kota, hitung ongkir, pilih kurir |
| 5 | Integrasi Midtrans QRIS + webhook konfirmasi + minta alamat |
| 6 | API Controller/Resource + Dashboard Angular: Produk, Pesanan, Pengaturan, Ringkasan |
| 7 | Testing end-to-end dengan 1-2 toko beta (gratis) — jadi studi kasus & konten TikTok |
| 8 | Perbaikan dari feedback beta, landing page, mulai jual |

---

## 13. Risiko & Mitigasi

- **Meta suspend nomor WA** → hindari broadcast tanpa opt-in, pakai template resmi
- **QR expired sebelum sempat bayar** → reminder otomatis + regenerate mudah
- **Alamat berantakan** → v2 pakai WhatsApp Flow (form terstruktur), bukan free text
- **Race condition stok** → lock database saat generate QR
- **Biaya conversation membengkak** → monitor pemakaian per toko, alert sebelum kuota habis
- **RajaOngkir rate limit / larangan auto-request** → semua panggilan API dipicu aksi user nyata, cache data statis (kota/provinsi)

---

## 14. Strategi Promosi

Alur "chat → ongkir otomatis → QR muncul → bayar → selesai" sangat visual — cocok untuk:
- Rekam layar HP: proses order dari sisi pelanggan dari awal sampai QR muncul otomatis
- Testimoni toko beta pertama: "sebelum pakai bot, saya balas chat manual 3 jam sehari — sekarang otomatis semua"
- Series "membangun SaaS ini dari nol" — storytelling proses membangun biasanya mendapat banyak interaksi karena orang suka mengikuti journey yang jujur dan detail

---

## 15. Langkah Selanjutnya

Yang bisa dikerjakan lebih detail dari sini:
1. Migration Laravel siap pakai untuk skema database di atas
2. Contoh kode integrasi RajaOngkir + Midtrans (request/response, webhook handler)
3. API Controller + API Resource Laravel untuk Produk & Pesanan, plus komponen Angular (tabel + form) yang mengonsumsinya
4. Setup Sanctum + Angular HttpInterceptor untuk autentikasi dashboard
5. Diagram flowchart visual dari state machine bot

Beri tahu mana yang mau dikerjakan lebih dulu.

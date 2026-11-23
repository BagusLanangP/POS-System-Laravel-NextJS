# Perancangan Backend POS System Minimarket (MVP)

**Tech stack:** Laravel 12 (API only, Service-Repository Pattern) · PostgreSQL 16 · Sanctum (Bearer token) · Frontend Next.js (repo terpisah)

---

## 1. Ruang Lingkup MVP

- Login dan role (admin, kepala toko, supervisor, kasir)
- Master data: gerai, kategori, produk, barcode, harga per gerai
- Promo sederhana: diskon persen atau nominal per produk, dengan periode aktif
- Shift kasir: buka dengan modal awal, tutup dengan hitung uang fisik dan selisih
- Transaksi penjualan: total dihitung ulang di server, multi-metode bayar (tunai, QRIS, EDC sebagai simulasi), nomor struk unik
- Void transaksi dengan otorisasi supervisor
- Stok berbasis ledger (`stock_movements`)
- Laporan penjualan harian per gerai dan per kasir

---

## 2. Analisis Kebutuhan

| Modul | Inti masalah | Risiko utama |
|---|---|---|
| Auth & Role | 4 role, sebagian terikat ke gerai | Kasir bisa mengakses data gerai lain |
| Master Data | Produk global, harga berbeda tiap gerai | Barcode ganda, harga gerai belum diisi |
| Promo | Diskon persen atau nominal, dengan periode | Dua promo aktif bertumpuk pada produk yang sama |
| Shift | Modal awal, hitung uang fisik, selisih | Kasir membuka 2 shift, transaksi masuk tanpa shift |
| Penjualan | Total dihitung ulang di server, multi-bayar, nomor struk unik | Request ganda (double submit), nomor struk bentrok, manipulasi harga dari client |
| Void | Harus ada otorisasi supervisor | Penyalahgunaan void, stok dan kas tidak ikut dibalik |
| Stok (ledger) | Semua perubahan stok tercatat di `stock_movements` | Race condition saat stok diperbarui bersamaan |
| Laporan | Harian, per gerai dan per kasir | Salah zona waktu, transaksi void ikut terhitung |

---

## 3. Arsitektur

```
Request → FormRequest (validasi) → Controller (tipis)
        → Service (logika bisnis + DB::transaction)
        → Repository (Interface → Eloquent impl) → Model
        → API Resource (format response)
```

### Aturan pola Service-Repository

- **Controller** tidak berisi logika. Tugasnya hanya memanggil service lalu mengembalikan Resource.
- **Service** memegang aturan bisnis, transaksi DB, dan orkestrasi antar-repository. Contohnya `SaleService` yang memanggil `PricingService`, `StockService`, dan `ReceiptNumberService`.
- **Repository** hanya berisi akses data: query, lock, dan persist. Repository tidak berisi aturan bisnis.
- **DTO** (readonly class) dipakai sebagai input service, supaya service tidak bergantung pada `Request`.
- Interface di-bind ke implementasinya di `RepositoryServiceProvider`.
- Repository tidak perlu dibuat untuk semua hal. CRUD master yang sederhana boleh memakai base repository generik.

### Struktur folder

```
app/
├── Enums/            (Role, SaleStatus, PaymentMethod, MovementType, PromoType, ShiftStatus)
├── DTOs/             (CreateSaleData, OpenShiftData, CloseShiftData, VoidSaleData, …)
├── Http/
│   ├── Controllers/Api/V1/
│   ├── Requests/
│   ├── Resources/
│   └── Middleware/   (EnsureOutletAccess, EnsureOpenShift)
├── Models/
├── Policies/
├── Repositories/
│   ├── Contracts/    (ProductRepositoryInterface, …)
│   └── Eloquent/     (ProductRepository, …)
├── Services/         (AuthService, ProductService, PricingService, PromoService,
│                      ShiftService, SaleService, VoidService, StockService,
│                      ReceiptNumberService, ReportService)
├── Exceptions/       (InsufficientPaymentException, ShiftNotOpenException, …)
└── Providers/RepositoryServiceProvider.php
```

---

## 4. Desain Database (PostgreSQL)

**Konvensi:**

- Uang disimpan sebagai `bigint` dalam satuan Rupiah (tanpa desimal, jadi tidak ada masalah floating point).
- Waktu disimpan sebagai `timestamptz`.
- Primary key memakai `bigserial`, ditambah `uuid` untuk ID yang terlihat oleh publik bila perlu.

### 4.1 Auth & Organisasi

```
outlets            id, code (unik, mis. "DPS01"), name, address, timezone, is_active
users              id, name, username (unik), password, pin_hash (nullable, utk supervisor),
                   role (enum), outlet_id (nullable → admin = global), is_active
```

Admin bersifat global. Kepala toko, supervisor, dan kasir terikat ke satu gerai.

### 4.2 Master Data

```
categories         id, name, parent_id (nullable)
products           id, sku (unik), name, category_id, unit, is_active
product_barcodes   id, product_id, barcode (UNIQUE), is_primary
outlet_prices      id, outlet_id, product_id, price, UNIQUE(outlet_id, product_id)
```

Barcode disimpan di tabel terpisah karena satu produk bisa punya beberapa barcode, misalnya kemasan lama dan baru.

### 4.3 Promo

```
promos             id, product_id, outlet_id (nullable = semua gerai),
                   type (percent|nominal), value, starts_at, ends_at, is_active
```

Dua promo yang periodenya tumpang-tindih untuk produk dan gerai yang sama dicegah dengan **EXCLUDE constraint** (`btree_gist` + `tstzrange`). Aturannya dijaga di level database, bukan cuma di kode.

### 4.4 Shift

```
shifts             id, outlet_id, cashier_id, opened_at, closed_at,
                   opening_cash, expected_cash, counted_cash, difference,
                   status (open|closed), closing_note
```

Partial unique index `UNIQUE(cashier_id) WHERE status='open'` membuat satu kasir hanya bisa punya satu shift terbuka.

### 4.5 Penjualan

```
sales              id, receipt_no (UNIQUE), client_uuid (UNIQUE, idempotency),
                   outlet_id, shift_id, cashier_id, business_date,
                   subtotal, discount_total, grand_total, paid_total, change_amount,
                   status (completed|voided),
                   voided_at, voided_by, void_approved_by, void_reason
sale_items         id, sale_id, product_id, product_name, barcode,   ← snapshot
                   qty, unit_price, discount_amount, promo_id, line_total
sale_payments      id, sale_id, method (cash|qris|edc), amount, reference_no
receipt_sequences  outlet_id, business_date, last_number, PK(outlet_id, business_date)
```

### 4.6 Stok (Ledger)

```
stock_movements    id, outlet_id, product_id, type (sale|void|adjustment|initial|receive),
                   qty_change (+/-), reference_type, reference_id, note, created_by, created_at
stock_balances     outlet_id, product_id, qty, PK(outlet_id, product_id)   ← proyeksi/cache
```

- `stock_movements` adalah sumber kebenaran dan bersifat append-only (tidak pernah di-update atau dihapus).
- `stock_balances` adalah cache yang diperbarui di transaksi DB yang sama, supaya cek stok tetap cepat. Isinya selalu bisa direkonsiliasi ulang dari ledger.

### 4.7 Audit

```
audit_logs         id, user_id, action, subject_type, subject_id, payload (jsonb), ip, created_at
```

---

## 5. Aturan Bisnis Kritis

### 5.1 Transaksi Penjualan (`SaleService::create`)

Dari client, yang dikirim **hanya**: `client_uuid`, daftar `items[{product_id, qty}]`, dan `payments[{method, amount, reference_no}]`. Client tidak mengirim harga atau total.

Alur di dalam satu `DB::transaction`:

1. **Cek idempotency.** Jika `client_uuid` sudah ada, kembalikan sale yang sudah tersimpan tanpa membuat transaksi baru. Ini mencegah double submit saat jaringan putus.
2. **Pastikan shift kasir terbuka** di gerai tersebut.
3. **Ambil data dari server.** `PricingService` mengambil harga dari `outlet_prices`, lalu mengambil promo aktif berdasarkan `now()`.
4. **Hitung per item:** `unit_price × qty − diskon`. Diskon persen dibulatkan ke rupiah. Diskon nominal tidak boleh melebihi harga.
5. **Validasi pembayaran.** Total pembayaran harus ≥ grand_total. Kembalian hanya boleh berasal dari porsi tunai (QRIS/EDC tidak boleh lebih bayar).
6. **Buat nomor struk** lewat `ReceiptNumberService`. Query ini atomik dan bebas race condition:
   ```sql
   INSERT INTO receipt_sequences (outlet_id, business_date, last_number) VALUES (?, ?, 1)
   ON CONFLICT (outlet_id, business_date)
   DO UPDATE SET last_number = receipt_sequences.last_number + 1
   RETURNING last_number;
   ```
   Formatnya `DPS01-20261123-00042`.
7. **Simpan** sale, items (dengan snapshot nama, harga, dan promo), serta payments.
8. **Catat stok.** `StockService` mencatat movement `sale` (qty negatif), lalu meng-update `stock_balances` dengan `SELECT … FOR UPDATE` yang diurutkan berdasarkan `product_id` agar tidak terjadi deadlock.

### 5.2 Void (`VoidService`)

- Kasir mengajukan void, lalu **supervisor memasukkan username dan PIN** pada request yang sama.
- Server memverifikasi beberapa hal: role supervisor (atau kepala toko), gerai yang sama, PIN benar, dan sale masih berstatus `completed`.
- Batasan MVP: void hanya untuk transaksi **di shift yang masih terbuka** dan di hari yang sama.
- Dampaknya: status berubah jadi `voided`, movement `void` (qty positif) dicatat untuk mengembalikan stok, `expected_cash` shift ikut terkoreksi, dan aksi ini masuk audit log.
- Endpoint void diberi rate limit, dan PIN dikunci setelah N kali salah.

### 5.3 Shift

- **Buka:** input `opening_cash`. Kasir ditolak jika masih punya shift yang terbuka.
- **Tutup:**
  - `expected_cash = opening_cash + Σ(pembayaran tunai − kembalian, untuk sale completed)`
  - Kasir menginput `counted_cash`, lalu sistem menghitung `difference = counted_cash − expected_cash`.
- Penutupan shift mengunci shift itu. Setelah ditutup, transaksi dan void tidak bisa lagi masuk ke shift tersebut.

### 5.4 Laporan

- Laporan memakai `business_date` yang ditentukan dari zona waktu gerai, bukan UTC.
- Yang dihitung hanya sale `completed`. Jumlah void ditampilkan sebagai kolom terpisah.
- **Harian per gerai** menampilkan jumlah transaksi, omzet kotor, diskon, omzet bersih, rincian per metode bayar, dan jumlah void.
- **Harian per kasir** menampilkan metrik yang sama ditambah selisih kas dari shift-shift kasir tersebut.
- Untuk MVP, laporan cukup memakai query agregat langsung dengan index `(outlet_id, business_date, status)`. Tabel ringkasan baru dipertimbangkan kalau datanya sudah besar.

---

## 6. Matriks Hak Akses

| Aksi | Admin | Kepala Toko | Supervisor | Kasir |
|---|:-:|:-:|:-:|:-:|
| Kelola gerai & user | ✅ | ❌ | ❌ | ❌ |
| Kelola kategori/produk/barcode | ✅ | ❌ | ❌ | ❌ |
| Atur harga gerai | ✅ | ✅ (gerainya) | ❌ | ❌ |
| Kelola promo | ✅ | ✅ (gerainya) | ❌ | ❌ |
| Penyesuaian stok | ✅ | ✅ | ❌ | ❌ |
| Buka/tutup shift & transaksi | ❌ | ❌ | ❌ | ✅ |
| Otorisasi void | ❌ | ✅ | ✅ | ❌ |
| Lihat laporan | ✅ (semua) | ✅ (gerainya) | ✅ (gerainya) | Ringkasan shift sendiri |

Implementasinya memakai enum `Role`, Policy per model, dan middleware `EnsureOutletAccess` supaya user non-admin hanya bisa melihat data gerainya sendiri. Package Spatie tidak diperlukan untuk MVP karena jumlah role-nya tetap.

---

## 7. Rancangan Endpoint API (`/api/v1`)

```
# Auth
POST   /auth/login
GET    /auth/me
POST   /auth/logout

# Master data
CRUD   /outlets  /users  /categories  /products
POST   /products/{id}/barcodes
DELETE /barcodes/{id}
GET    /products/lookup?barcode=…&outlet_id=…   ← dipakai kasir (harga + promo aktif)
PUT    /outlets/{id}/prices                     (bulk upsert)
CRUD   /promos

# Shift
GET    /shifts/current
POST   /shifts/open
POST   /shifts/{id}/close
GET    /shifts/{id}/summary

# Penjualan
POST   /sales
GET    /sales
GET    /sales/{id}
POST   /sales/{id}/void          (body: reason, supervisor_username, supervisor_pin)

# Stok
GET    /stock?outlet_id=…
GET    /stock/movements?product_id=…
POST   /stock/adjustments

# Laporan
GET    /reports/daily-outlet?outlet_id=…&date=…
GET    /reports/daily-cashier?outlet_id=…&date=…
```

Semua response memakai format yang konsisten, `{ data, meta, message }`. Error memakai kode error domain, misalnya `SHIFT_NOT_OPEN` atau `INSUFFICIENT_PAYMENT`.

---

## 8. Tahapan Pengerjaan

| Fase | Isi | Output |
|---|---|---|
| **0. Setup** | Laravel 12, Postgres via Docker, Sanctum, Pint, Pest, base repository, format response, exception handler | Skeleton siap |
| **1. Auth & Org** | outlets, users, role, login, policy, middleware gerai | Login berjalan |
| **2. Master Data** | kategori, produk, barcode, harga gerai, endpoint lookup | Data siap jual |
| **3. Stok** | ledger, balance, stok awal, penyesuaian | Stok terlacak |
| **4. Promo** | CRUD, exclusion constraint, `PricingService` | Harga final benar |
| **5. Shift** | buka/tutup, hitung expected | Kas terkendali |
| **6. Penjualan** | SaleService, nomor struk, idempotency, multi-bayar | Inti POS |
| **7. Void** | otorisasi PIN, reversal stok & kas, audit | Kontrol fraud |
| **8. Laporan** | harian per gerai & per kasir | MVP selesai |
| **9. Hardening** | test konkurensi, seeder demo, dokumentasi API (Scribe/OpenAPI) | Siap untuk FE |

### Area yang wajib dites (Pest, feature test)

- Perhitungan total dan promo
- Nomor struk saat request bersamaan
- Idempotency `client_uuid`
- Void yang membalik stok dan kas
- Perhitungan selisih shift
- Isolasi data antar-gerai

---

## 9. Keputusan yang Masih Terbuka

| # | Pertanyaan | Rekomendasi |
|---|---|---|
| 1 | **Stok minus:** apakah transaksi boleh tetap jalan jika stok di sistem 0? | Boleh, tapi diberi flag/peringatan, karena di minimarket stok fisik sering lebih dulu ada daripada input penerimaan barang |
| 2 | **Pajak (PPN):** apakah harga sudah termasuk pajak, atau pajak tidak perlu dimodelkan di MVP? | Harga sudah termasuk PPN, tanpa kolom pajak |
| 3 | **Qty desimal:** apakah ada barang timbangan (kg)? | Kalau ada, `qty` bertipe `numeric(12,3)`; kalau tidak, integer |
| 4 | **Mesin kasir/terminal:** apakah perlu entitas `registers`? | Shift per kasir dulu untuk MVP |
| 5 | **Batas void:** cukup di shift yang masih terbuka, atau perlu juga void hari sebelumnya? | Hanya di shift yang masih terbuka |
| 6 | **Multi-gerai per user:** apakah kepala toko bisa memegang lebih dari satu gerai? | Kalau bisa, ganti `users.outlet_id` dengan pivot `outlet_user` |
| 7 | **Auth FE:** Sanctum Bearer token atau cookie SPA? | Bearer token (sederhana, cocok untuk perangkat kasir) |

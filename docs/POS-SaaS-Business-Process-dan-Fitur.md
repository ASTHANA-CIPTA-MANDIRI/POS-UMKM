# Sistem POS SaaS Multi-Tenant untuk UMKM (FnB, Toko Kelontong & Sejenisnya)

## 1. Konsep Dasar Sistem

Sistem ini adalah **POS berbasis SaaS multi-tenant**, artinya satu instance aplikasi melayani banyak merchant (penyewa) sekaligus, dengan **isolasi data ketat** antar merchant. Target segmen utama: warteg/rumah makan, toko kelontong, depot air isi ulang, laundry, dan usaha sejenis — bukan resto modern/retail formal yang jadi fokus POS SaaS besar seperti Moka/Pawoon/Majoo. Karena jenis usahanya beragam, dan satu merchant bisa saja menjalankan **campuran usaha dalam satu outlet** (misal warteg yang juga jual sembako, atau depot air yang juga terima laundry), sistem dirancang dengan **mode transaksi yang dipilih per-transaksi oleh kasir, bukan dikunci per outlet**. Outlet cukup mengaktifkan mode mana saja yang relevan saat setup, lalu kasir tinggal pilih mode yang sesuai tiap kali mulai transaksi baru. Lihat detail di bagian 3.2.A.

Ada 3 level aktor utama:

| Level | Aktor | Deskripsi |
|---|---|---|
| 1 | **Super Admin (Pemilik Sistem)** | Anda — mengelola seluruh platform, merchant, billing, dan monitoring |
| 2 | **Merchant Owner (Penyewa)** | Pemilik UMKM yang menyewa sistem, punya akses penuh ke datanya sendiri |
| 3 | **Staff Merchant (Kasir/Karyawan)** | Dibuat oleh Merchant Owner, punya akses terbatas sesuai role |

### Prinsip Isolasi Data (Multi-Tenancy)
- Setiap merchant punya `tenant_id` unik yang menempel di **semua** tabel data transaksional (produk, transaksi, stok, pelanggan, laporan).
- Pendekatan teknis yang bisa dipilih:
  - **Shared database, shared schema** dengan `tenant_id` di setiap query (paling murah, cocok untuk awal/skala kecil-menengah).
  - **Shared database, separate schema per tenant** (lebih aman, agak lebih mahal secara operasional).
  - **Separate database per tenant** (paling aman & terisolasi, tapi mahal — biasanya untuk merchant enterprise/paket tertinggi).
- Semua query wajib difilter otomatis (middleware/row-level security) berdasarkan `tenant_id` dari sesi login, bukan mengandalkan filter di sisi frontend.
- Backup dan restore per tenant harus dimungkinkan (misal merchant minta export data saat berhenti berlangganan).

---

## 2. Proses Bisnis Utama

### 2.1 Alur Onboarding Merchant (Sign Up → Aktif)
1. Calon merchant mendaftar via landing page (isi data usaha: nama, jenis usaha FnB/Retail, jumlah outlet).
2. Sistem membuat akun **trial** otomatis (misal 14 hari) atau menunggu approval Super Admin.
3. Merchant memilih paket langganan (lihat bagian Billing di bawah).
4. Merchant melakukan pembayaran (manual transfer + upload bukti, atau otomatis via payment gateway).
5. Super Admin/sistem mengaktifkan akun → tenant_id dibuat → merchant bisa login ke dashboard.
6. **Setup Wizard** untuk merchant baru: isi profil toko, tambah outlet pertama, setup kategori produk, upload produk (manual atau import Excel), setting pajak/service charge, tambah metode pembayaran, buat user kasir pertama.

### 2.2 Alur Operasional Harian (Kasir/Merchant)
1. Kasir login (PIN/username) → buka sesi kasir (**cash drawer opening**, catat modal awal kas).
2. Transaksi penjualan berlangsung sepanjang shift (lihat detail fitur kasir di bawah).
3. Tutup sesi kasir (**cash drawer closing**) → sistem hitung selisih kas fisik vs sistem.
4. Laporan shift otomatis terkirim/tersimpan untuk direview owner.

### 2.3 Alur Billing & Langganan (Super Admin ↔ Merchant)
1. Sistem generate invoice otomatis tiap periode (bulanan/tahunan) sesuai paket.
2. Notifikasi jatuh tempo (H-7, H-1, H+1) via email/WA/in-app.
3. Jika telat bayar → masa **grace period** → jika lewat, akun **suspend** (merchant tidak bisa transaksi, tapi data tetap tersimpan, tidak dihapus).
4. Jika ingin upgrade/downgrade paket → prorata otomatis dihitung sistem.
5. Merchant berhenti langganan → data di-retain sesuai kebijakan (misal 90 hari) sebelum dihapus permanen, beri opsi export data.

### 2.4 Alur Monitoring Platform (Super Admin)
1. Dashboard global: jumlah merchant aktif, revenue platform (MRR/ARR), churn rate, merchant yang mendekati limit paket.
2. Monitoring kesehatan sistem: uptime, error rate, penggunaan resource per tenant (deteksi tenant "noisy neighbor").
3. Support tiket dari merchant (jika ada masalah) masuk ke Super Admin.
4. Audit log seluruh aktivitas penting (login, perubahan harga, void transaksi, dsb) untuk keamanan dan investigasi jika ada sengketa.

---

## 3. Struktur Fitur per Role

## 3.1 SUPER ADMIN (Pemilik Platform)

**Manajemen Merchant**
- List semua merchant, status (trial/aktif/suspend/nonaktif)
- Approve/reject pendaftaran merchant baru
- Impersonate login ke akun merchant (untuk bantu troubleshooting, dengan log jelas)
- Suspend/aktifkan akun merchant

**Manajemen Paket & Billing**
- Kelola paket langganan (nama, harga, fitur, limit — misal limit jumlah outlet, jumlah user, jumlah transaksi/bulan)
- Kelola invoice, riwayat pembayaran, status tunggakan
- Laporan revenue platform (MRR, ARR, churn, LTV)
- Integrasi payment gateway untuk pembayaran otomatis (Midtrans/Xendit dsb)

**Monitoring & Analitik Platform**
- Dashboard jumlah merchant aktif, transaksi total platform, growth trend
- Statistik penggunaan fitur (fitur mana yang paling dipakai)
- Log aktivitas & audit trail

**Konfigurasi Global**
- Kelola template/kategori bisnis default (FnB vs Retail punya modul beda)
- Broadcast pengumuman/maintenance notice ke semua merchant
- Kelola knowledge base/FAQ untuk merchant

**Support**
- Sistem tiket/keluhan dari merchant
- Live chat/helpdesk terintegrasi (opsional)

**Manajemen Aset Perangkat (Untuk Paket Sewa+Device)**

Karena paket ke depan ada 2 jenis — **Sewa Sistem Saja** (merchant pakai perangkat sendiri/BYOD) dan **Sewa Sistem + Perangkat** (tablet, printer thermal, EDC dipinjamkan platform) — untuk paket kedua perlu modul khusus mengelola risiko perangkat fisik:

- **Registrasi Aset per Perangkat** — tiap tablet/printer/EDC yang dipinjamkan dicatat dengan nomor seri/IMEI, terikat ke merchant & outlet tertentu (siapa pegang perangkat apa, sejak kapan)
- **Status Aset** — dashboard status tiap perangkat: Aktif Dipakai / Rusak-Dalam Klaim / Hilang / Ditarik Kembali
- **Deposit/Jaminan Perangkat** — merchant yang ambil paket +device wajib bayar deposit di awal (dikembalikan saat kontrak selesai & perangkat dikembalikan utuh, atau dipotong kalau ada kerusakan/kehilangan)
- **Kontrak Sewa Digital** — merchant menyetujui perjanjian sewa saat pilih paket +device, berisi klausul tanggung jawab kerusakan & kehilangan, ditandatangani digital saat onboarding
- **Remote Lock/Disable via MDM (Mobile Device Management)** — ini pertahanan paling penting untuk kasus **dibawa kabur**: karena tablet terikat ke akun/login sistem Anda, kalau merchant berhenti bayar, kabur, atau perangkat dilaporkan hilang, Super Admin bisa **remote-disable perangkat itu dari jarak jauh** (device jadi tidak bisa dipakai untuk apa pun, bukan cuma logout dari aplikasi POS-nya saja) — jadi perangkat yang dibawa kabur jadi tidak bernilai jual/pakai
- **Pelacakan Lokasi — Terbatas pada Perangkat yang Punya GPS & Koneksi Sendiri**:
  - **Tablet** — bisa dilacak lokasinya via MDM (mirip fitur "Find My Device"), karena tablet punya GPS + koneksi data sendiri
  - **EDC** — bisa dilacak **hanya jika** EDC-nya pakai SIM/jaringan seluler sendiri (bukan sekadar nebeng Wi-Fi tablet). Kalau EDC-nya disediakan pihak ketiga/bank, pelacakan di luar kendali sistem Anda
  - **Printer thermal — tidak bisa dilacak**. Ini perangkat pasif (sambung ke tablet via Bluetooth/USB/Wi-Fi lokal), tidak punya GPS/koneksi sendiri. Untuk printer, deposit jadi satu-satunya proteksi
  - Lokasi yang tercatat sebaiknya berupa **"lokasi terakhir saat perangkat online"**, bukan pelacakan real-time terus-menerus — selain lebih hemat baterai/data, ini juga lebih wajar dari sisi privasi
  - Persetujuan pelacakan lokasi harus dicantumkan jelas di **Kontrak Sewa Digital** saat merchant mengambil paket +device, supaya tidak jadi masalah hukum/privasi di kemudian hari
- **Alur Klaim Kerusakan**: merchant lapor → verifikasi kondisi kerusakan (wajar/wear-and-tear vs kelalaian/kesengajaan) → wajar biasanya ditanggung platform (bagian biaya operasional sewa), kelalaian dibebankan ke deposit merchant
- **Alur Kehilangan/Dibawa Kabur**: merchant/staff lapor kehilangan → perangkat langsung di-lock via MDM → cek lokasi terakhir (jika perangkatnya tablet/EDC ber-GPS) → deposit dipotong untuk ganti rugi → kalau nilainya besar dan ada indikasi kesengajaan, bisa lanjut proses hukum (laporan polisi), sistem sediakan data pendukung (log lokasi terakhir aktif, riwayat pemakaian) sebagai bukti

**Contoh Skema Deposit Bertingkat (Dijaga Tetap Terjangkau)**

| Perangkat | Alasan Deposit Beda | Kisaran Deposit (Contoh) |
|---|---|---|
| Printer Thermal saja | Harga alat murah, risiko kerugian kecil | Paling rendah |
| Tablet saja | Harga alat menengah, ada risiko dibawa kabur tapi bisa dilacak+dilock | Menengah |
| EDC saja | Tergantung kebijakan penyedia EDC (kadang EDC dari bank/payment provider, bukan aset Anda — cek dulu skema kerja sama sebelum tentukan deposit) | Menengah, atau tidak perlu deposit kalau EDC dari pihak ketiga |
| Paket Lengkap (Tablet + Printer + EDC) | Digabung, tapi diberi **diskon dari total deposit satuan** — supaya tidak memberatkan merchant baru yang justru butuh dorongan untuk mulai coba sistemnya | Lebih murah dari jumlah deposit 3 alat terpisah |

*Catatan: angka pastinya perlu disesuaikan dengan harga modal alat & daya beli target UMKM Anda — prinsip utamanya deposit cukup untuk menutup risiko, tapi tidak jadi penghalang orang mau coba paket +device.*

---

## 3.2 MERCHANT OWNER (Penyewa)

### A. Manajemen Kasir (Point of Sales) — Inti Sistem

**Umum**
- Input transaksi cepat (search produk, scan barcode, kategori/grid produk)
- Multi metode pembayaran dalam 1 transaksi (split payment: cash + QRIS, dsb)
- Diskon per item / per transaksi (nominal atau persen), diskon butuh approval untuk nominal besar (opsional)
- Void/cancel transaksi dengan alasan wajib diisi + approval owner/supervisor
- Cetak struk via printer thermal (fokus 100% ke struk fisik, tanpa dependensi WA/email)

**Sistem punya beberapa mode transaksi yang bisa diaktifkan per outlet, lalu dipilih kasir per-transaksi** (bukan dikunci satu mode untuk seluruh outlet) — supaya merchant dengan usaha campuran (misal warteg + jual sembako, atau depot air + terima laundry) tetap bisa pakai satu sistem yang sama tanpa harus pilih salah satu:

**Mode A — Transaksi Langsung (Toko Kelontong/Retail)**
- Alur klasik: pilih/scan produk → total otomatis → bayar → struk cetak → transaksi selesai saat itu juga
- Cocok untuk toko kelontong karena pembeli datang, ambil barang, langsung bayar di kasir — tidak ada jeda "pesan dulu, bayar belakangan"
- Kasbon tetap bisa dicatat manual per pelanggan (lihat bagian CRM) untuk pelanggan langganan yang minta ngutang, tapi ini terpisah dari alur transaksi utama — dicatat sebagai "belum lunas" setelah struk tercetak, bukan bagian dari proses bayar itu sendiri

**Mode B — Buka Bill/Bayar di Akhir (FnB/Warteg)**
- Pelanggan datang, pesan (bisa nambah beberapa kali), kasir catat tiap item ke satu bill terbuka, baru cetak struk final + terima pembayaran saat pelanggan selesai makan/mau pulang
- Simpan banyak bill terbuka sekaligus (**hold/park bill** per meja atau per nama pelanggan), kasir bisa buka-tutup bill mana saja tanpa harus urut
- Split bill (bagi tagihan per orang) — opsional
- Merge bill (gabung pesanan) — opsional
- Refund/retur transaksi (berlaku untuk kedua mode)

**Mode C — Pesan/Antar & Titip-Ambil (Depot Air, Laundry, Katering, dsb)**
- Cocok untuk depot air isi ulang: pelanggan datang bawa galon kosong → diisi → langsung bayar & bawa pulang (mirip Mode A), **atau** galon dititip dulu untuk diisi/diambil nanti, **atau** pesan antar ke rumah
- Cocok juga untuk laundry: pakaian dititip → ditimbang/dihitung → estimasi selesai & harga (per kg atau per item) → status berjalan sampai diambil
- Transaksi dibuat saat order/titipan masuk (belum tentu langsung lunas), status berjalan: **Diterima → Diproses → Siap Diambil/Diantar → Selesai & Dibayar**
- Struk/nota titipan tercetak saat barang diterima (jadi bukti klaim pelanggan), struk lunas tercetak terpisah saat pembayaran selesai
- Pembayaran bisa saat itu juga, saat pengantaran, atau digabung ke tagihan langganan bulanan (lihat poin langganan di bawah)
- **Deposit/Tukar Galon Kosong** — fitur khas depot air: lacak galon kosong yang dititip pelanggan sebagai "aset titipan" terpisah dari stok galon isi, supaya tidak tertukar dengan penjualan biasa

**Khusus FnB**
- **Mode "Prasmanan/Hitung Cepat"** — grid tombol besar per jenis lauk/menu (bukan scan barcode), kasir tinggal tap berapa kali sesuai lauk yang diambil pelanggan, total otomatis terhitung. Ini beda dari POS kebanyakan yang berasumsi semua produk pakai barcode.
- Nomor meja/nama pelanggan sederhana sebagai label bill (tanpa layout visual meja rumit — cukup daftar bill terbuka yang bisa dipilih kasir)
- Print struk dapur otomatis by printer thermal terpisah kalau ada station dapur (opsional, bisa dimatikan untuk warung kecil yang dapurnya satu meja dengan kasir)
- Modifier produk (misal: pedas/tidak, nasi banyak/sedikit) dengan harga tambahan (opsional)

**Khusus Toko Kelontong/Retail**
- Scan barcode cepat multi-item
- Manajemen produk dengan varian (ukuran, warna, satuan — pcs/kg/liter/dus)
- Konversi satuan (1 dus = 12 pcs, dan harga otomatis mengikuti satuan yang dipilih)
- Manajemen expired date (FEFO — First Expired First Out) untuk produk consumable

**Cash Management**
- Buka/tutup kasir (cash drawer) dengan pencatatan modal awal & rekonsiliasi kas akhir shift
- Pencatatan pengeluaran kas kecil (petty cash) langsung dari kasir (misal beli galon, ongkos kirim)

### B. Manajemen Stok/Inventory

- Master produk (nama, SKU, barcode, kategori, harga beli, harga jual, gambar)
- Stok per outlet (bukan digabung, tiap outlet punya stok sendiri, atau opsi stok terpusat)
- Stok masuk (purchase order ke supplier, penerimaan barang)
- Stok keluar otomatis saat transaksi penjualan
- Stok opname (stock take) — hitung fisik vs sistem, otomatis catat selisih
- Transfer stok antar outlet
- Alert stok minimum (low stock notification)
- Alert produk mendekati kadaluarsa
- Manajemen supplier (data supplier, riwayat pembelian)
- Bundling/paket produk (misal paket hemat gabungan beberapa item)
- Bahan baku & resep (**recipe/BOM - Bill of Materials**) khusus FnB — misal 1 porsi nasi goreng pakai 200gr beras, 1 butir telur, dst → stok bahan baku otomatis berkurang saat menu terjual (bukan stok produk jadi)

### C. Manajemen Pelanggan (CRM Ringan)

- Database pelanggan (nama, no HP, email, tanggal lahir)
- Riwayat transaksi per pelanggan
- Program loyalty/poin reward
- Member tier (opsional, biasanya tidak wajib untuk warteg/kelontong skala kecil)
- Voucher/kupon diskon
- **Kasbon/Utang Pelanggan** — catat pelanggan yang belum bayar, jumlah, tanggal, dan status lunas/belum. Dicetak via struk/rekap harian, bukan lewat notifikasi digital.

### D. Promosi & Harga

- Diskon berdasarkan periode waktu (happy hour, weekend promo)
- Harga khusus per outlet
- Bundling promo (beli 2 gratis 1, dsb)
- Price list berbeda untuk member vs non-member

### E. Manajemen Karyawan/Staff

**Role Staff Dibatasi (Bukan Role Bebas/Kompleks)**
- **FnB**: hanya 2 role staff — **Kasir** dan **Dapur** (perwakilan saja, bukan semua orang di dapur harus punya akun — cukup 1-2 akun dapur untuk terima order/tandai pesanan selesai)
- **Toko Kelontong, Laundry, Depot Air**: hanya 1 role staff — **Kasir**
- Owner tetap jadi role tertinggi di level merchant, staff tidak bisa lihat laporan keuangan/laba, hanya modul sesuai rolenya

**Akun Staff Dibuat Terpusat oleh Owner**
- Staff **tidak bisa mendaftar sendiri** — akun (username/PIN) dibuat oleh Owner (atau Manager Outlet yang diberi izin) dari dashboard pusat
- Staff tinggal terima kredensial (username/PIN) dari owner, lalu login di perangkat outlet

**Akun Staff Dikunci ke 1 Outlet Spesifik**
- Setiap akun staff di-assign ke **tepat 1 outlet** saat dibuat
- Kalau staff dari Outlet A mencoba login di perangkat/lokasi Outlet B, sistem **menolak login** — bukan sekadar warning, tapi hard block
- Deteksi outlet saat login bisa berbasis: perangkat/device yang sudah terdaftar ke outlet tertentu (device binding), dan/atau lokasi (opsional, kalau mau tambah validasi GPS untuk mencegah staff pura-pura di outlet lain)
- Kalau staff memang perlu pindah tugas ke outlet lain (mutasi), itu harus lewat perubahan assignment oleh Owner/Manager, bukan staff pilih sendiri saat login

**Penanganan Perangkat Rusak/Error (Device Recovery)**
- **Daftarkan 2 perangkat per outlet (utama + cadangan)** sejak awal setup — kalau perangkat utama rusak, outlet langsung pakai perangkat cadangan yang sudah ter-binding tanpa perlu proses darurat sama sekali. Ini pencegahan paling simpel.
- Kalau tidak ada cadangan dan perangkat rusak mendadak, **hanya Owner/Manager Outlet** (bukan staff biasa) yang bisa lakukan **"Reset/Ganti Perangkat Outlet"** dari dashboard pusat: nonaktifkan binding perangkat lama → daftarkan perangkat baru (HP/tablet pengganti) → outlet bisa transaksi lagi
- Kalau Owner sedang tidak di lokasi, proses reset ini tetap bisa dilakukan jarak jauh dari dashboard Owner (karena aksinya di sistem pusat, bukan di perangkat outlet itu sendiri)
- Setiap penggantian perangkat **tercatat di log audit** (kapan, oleh siapa, outlet mana, perangkat lama vs baru) — supaya bisa ditelusuri kalau ada penyalahgunaan
- **Mode Darurat Sementara** (opsional, untuk kasus ekstrem seperti dua-duanya rusak dan Owner sedang tidak bisa diakses cepat): Owner bisa generate **kode otorisasi sementara** yang berlaku untuk 1 outlet & masa waktu terbatas (misal 24 jam), dipakai staff untuk login di perangkat mana pun sebagai pengganti sesaat — otomatis expired dan tidak bisa dipakai ulang

**Lain-lain**
- Jadwal shift kerja per staff
- Log aktivitas per staff (siapa void transaksi apa, jam berapa, dari outlet mana)
- Perhitungan komisi/insentif penjualan (opsional)

### F. Multi-Outlet (1 Merchant, Banyak Cabang)

Skenario: 1 merchant (misal "Warung Makan Benjamin") punya banyak outlet tersebar di berbagai lokasi. Semua outlet tetap di bawah **1 akun/tenant yang sama**, bukan akun terpisah-pisah — supaya owner bisa pantau semua dari satu dashboard, tapi tiap outlet tetap punya data operasional sendiri (stok, kasir, transaksi harian).

**Hierarki User (4 Level)**
- **Owner** — akses & lihat semua outlet tanpa batas
- **Area/Regional Manager** (opsional) — di-assign hanya ke sekelompok outlet tertentu (misal wilayah Jakarta), tidak bisa lihat outlet di luar wilayahnya
- **Manager Outlet** — hanya akses 1 outlet yang dia pegang (laporan, stok, staff outlet itu saja)
- **Kasir** — hanya bisa input transaksi di outlet tempat dia login, tidak bisa lihat data outlet lain

**Manajemen Produk & Harga Antar Outlet**
- Master menu/produk dikelola terpusat oleh owner, otomatis tersedia di semua outlet
- Harga bisa **diseragamkan** semua outlet, atau **di-override per outlet** (misal outlet di mall harga sedikit lebih tinggi dari outlet pinggir jalan)
- Update menu/harga dari pusat bisa langsung tersebar ke semua outlet sekaligus (tanpa harus edit satu-satu)

**Model Stok — Pilih Salah Satu atau Kombinasi**
- **Stok mandiri per outlet** — tiap outlet belanja & catat stoknya sendiri, tidak saling terhubung
- **Stok terpusat (gudang pusat)** — barang/bahan baku dibeli terpusat, lalu didistribusikan ke tiap outlet lewat fitur **transfer stok**, dengan pencatatan otomatis siapa kirim apa ke outlet mana dan kapan

**Laporan & Analitik Konsolidasi**
- Dashboard gabungan seluruh outlet (total omzet, total transaksi, dsb)
- Perbandingan performa antar outlet (ranking omzet, produk terlaris per outlet vs across semua outlet)
- Drill-down dari laporan gabungan ke laporan per outlet tertentu
- Laporan tetap bisa difilter per outlet saja untuk Manager Outlet yang hanya butuh lihat cabangnya

**Terkait Paket Langganan**
- Jumlah outlet yang bisa dibuat mengikuti limit paket langganan (lihat bagian 5) — kalau Benjamin punya 10 outlet, otomatis butuh paket yang mendukung 10 outlet atau lebih

### G. Laporan & Analitik

- Laporan penjualan (harian/mingguan/bulanan, per outlet, per kasir)
- Laporan produk terlaris/kurang laku
- Laporan laba rugi sederhana (margin per produk)
- Laporan stok (kartu stok, mutasi stok)
- Laporan pajak (untuk keperluan lapor pajak UMKM)
- Export laporan ke Excel/PDF
- Dashboard visual (grafik tren penjualan, jam ramai, dsb)

### H. Integrasi Pembayaran & Eksternal

- QRIS (idealnya QRIS statis/dinamis terintegrasi otomatis rekonsiliasi)
- E-wallet (GoPay, OVO, DANA, ShopeePay)
- Kartu debit/kredit (EDC integrasi opsional)
- Integrasi marketplace/online order (GoFood, GrabFood, ShopeeFood) — sinkronisasi order masuk ke satu sistem POS (fitur sangat dicari FnB modern)
- Integrasi akuntansi (misal export ke Jurnal/Accurate) — opsional untuk paket lebih tinggi
- Integrasi printer thermal & cash drawer

### I. Pengaturan Toko

- Setting pajak (PPN) & service charge otomatis di struk
- Setting struk (logo, nama toko, footer promosi)
- Setting jam operasional
- Setting mata uang (jika ekspansi luar negeri, opsional)

---

## 3.3 STAFF (Kasir & Dapur)

- Role terbatas: **Kasir** (semua jenis usaha) dan **Dapur** (khusus FnB, perwakilan saja)
- Akun dibuat oleh Owner/Manager Outlet dari pusat — staff tidak bisa daftar sendiri
- **Login terkunci ke 1 outlet** — staff Outlet A tidak bisa login/transaksi di Outlet B, sistem menolak otomatis
- Kasir: akses terbatas hanya ke modul transaksi outlet-nya sendiri, tidak bisa lihat laporan keuangan/laba, hanya laporan shift-nya sendiri
- Dapur: akses terbatas hanya ke modul terima order/tandai status pesanan (untuk Mode B & Mode C), tidak bisa akses modul kasir
- Butuh approval Owner/Manager untuk aksi sensitif (void, diskon besar, buka laci kasir tanpa transaksi)

---

## 4. Fitur Tambahan yang Saya Rekomendasikan (Pembeda dari POS Lain)

Mengingat target Anda warteg, rumah makan sederhana, dan toko kelontong — kebanyakan POS SaaS besar (Moka, Pawoon, Majoo) justru dirancang untuk resto modern/retail formal, jadi terlalu "berat" fiturnya untuk segmen ini. Di sinilah ruang untuk jadi pembeda:

1. **Mode Offline-First** — kasir tetap bisa transaksi walau internet mati/tidak ada Wi-Fi (umum terjadi di warung), lalu auto-sync saat online kembali. Ini sering jadi keluhan terbesar pengguna POS existing di segmen warung.

2. **Buku Utang/Piutang Digital (Kasbon Pelanggan)** — fitur yang sangat khas kebutuhan warteg & kelontong: pelanggan langganan sering bayar belakangan/ngutang. Sistem mencatat siapa yang punya utang, berapa, sejak kapan, dan bisa cetak/tagih via struk. Ini fitur yang **jarang ada** di POS SaaS mainstream karena mereka fokus ke retail formal yang serba tunai/non-tunai langsung.

3. **Mode Kasir Super Simpel (Grid Tanpa Barcode)** — UI kasir didesain untuk pengguna non-teknis: tombol besar per menu/lauk, tanpa perlu hafal SKU atau scan apa pun. Tap-tap-selesai. Ini penting karena banyak pemilik warteg/kelontong bukan orang yang terbiasa pakai aplikasi rumit.

4. **Cetak Semua Kebutuhan via Printer Thermal** — bukan cuma struk, tapi juga: rekap tutup kasir harian, laporan stok menipis, rekap utang pelanggan — semua bisa dicetak langsung dari printer thermal yang sudah dimiliki merchant, tanpa perlu perangkat tambahan.

5. **Hitung Otomatis Bahan Baku Habis (Bukan Cuma Stok Barang Jadi)** — khusus warteg: sistem bisa estimasi kebutuhan bahan baku (beras, minyak, dsb) berdasarkan jumlah porsi yang terjual, membantu owner belanja lebih tepat — fitur BOM/resep sudah ada di rencana awal, ini tinggal disederhanakan UI-nya untuk warung kecil.

6. **Setoran Modal Harian & Rekap Kas Sangat Sederhana** — laporan akhir hari didesain seringkas mungkin (modal awal, total masuk, total keluar/kasbon, sisa kas), format yang langsung dipahami pemilik warung tanpa istilah akuntansi rumit.

7. **Harga Bertingkat/Custom per Pelanggan** — beberapa warteg punya harga langganan khusus (misal pelanggan tetap dapat harga beda). Sistem bisa simpan harga khusus per pelanggan tanpa ribet.

8. **Mode Multi-Bahasa Sederhana/Istilah Lokal** — UI pakai istilah yang familiar untuk pedagang kecil ("kasbon" bukan "credit", "setoran" bukan "cash reconciliation"), bukan istilah bisnis formal — pendekatan bahasa jadi pembeda UX yang signifikan.

9. **White-label / kustomisasi branding** untuk merchant di paket premium (nama & logo di struk bisa custom, atau bahkan branding aplikasi custom untuk merchant besar).

10. **API terbuka/Webhook** untuk merchant yang sudah lebih maju dan ingin integrasi dengan sistem lain.

11. **Onboarding super cepat** — target setup dari daftar sampai bisa transaksi pertama di bawah 15 menit, dengan wizard yang menuntun step-by-step tanpa istilah teknis. Banyak SaaS gagal retensi karena onboarding-nya berasumsi user paham teknologi.

12. **Referral program antar merchant** — merchant yang mengajak merchant lain (warteg sebelah, misalnya) dapat potongan biaya langganan — efektif karena komunitas pedagang kecil biasanya saling kenal dan cerita dari mulut ke mulut.

---

## 5. Struktur Paket Langganan (Contoh Model Bisnis)

**Dua Jalur Paket Berdasarkan Perangkat**
- **Sewa Sistem Saja (BYOD - Bring Your Own Device)** — merchant pakai tablet/HP, printer thermal, dan EDC miliknya sendiri, hanya bayar biaya langganan software
- **Sewa Sistem + Perangkat** — platform pinjamkan tablet, printer thermal, dan EDC sekaligus, biaya langganan lebih tinggi (mencakup sewa hardware) + wajib bayar deposit di awal (lihat bagian Manajemen Aset Perangkat di atas)

| Fitur | Basic | Pro | Enterprise |
|---|---|---|---|
| Jumlah outlet | 1 | Sampai 3 | Unlimited |
| Jumlah user kasir | 2 | 5 | Unlimited |
| Perangkat (device) | BYOD saja | BYOD atau +Device | BYOD atau +Device |
| Manajemen stok | Dasar | Lengkap + resep/BOM | Lengkap + forecasting |
| Laporan | Dasar | Lengkap + export | Lengkap + API akses |
| Integrasi marketplace/online order | ❌ | ✅ | ✅ |
| White-label | ❌ | ❌ | ✅ |
| Support | Email | Email + Chat | Dedicated support |
| Harga | Rp X/bulan | Rp Y/bulan (+device: Rp Y+Z/bulan) | Custom |

---

## 6. Catatan Teknis untuk Isolasi Data Antar Merchant

Karena ini poin krusial yang Anda tekankan, berikut checklist keamanan yang wajib dipastikan saat development:

- [ ] Setiap tabel data (produk, transaksi, stok, pelanggan, laporan) punya kolom `tenant_id`
- [ ] Middleware/auth wajib inject `tenant_id` dari session, **tidak boleh** dikirim dari client (hindari IDOR — Insecure Direct Object Reference)
- [ ] Row-Level Security (RLS) di database jika memakai PostgreSQL, sebagai lapisan proteksi tambahan selain filter di aplikasi
- [ ] Testing khusus: coba akses data merchant A pakai token merchant B → harus ditolak (403)
- [ ] File upload (gambar produk, logo) disimpan dengan path yang mengandung `tenant_id`, bukan folder shared
- [ ] Rate limiting per tenant supaya satu merchant tidak bisa membebani/mengganggu performa merchant lain
- [ ] Backup terpisah/dapat difilter per tenant untuk keperluan restore individual

---

*Dokumen ini bisa jadi dasar untuk membuat **PRD (Product Requirements Document)**, **ERD database**, atau **wireframe/UI** di tahap selanjutnya — beri tahu saya kalau ingin saya lanjutkan ke salah satu dari itu.*

# Rencana & progres — Nampan POS

Berkas ini **sumber tunggal** untuk laporan progres ke Telegram
(`php artisan lapor:telegram`). Agen `lead` memperbaruinya setiap selesai sesi kerja.

Bentuk barisnya harus tetap seperti ini supaya bisa dibaca mesin:

```
- [x] Judul pekerjaan | 2026-08-05 | area | 6j
```

- `[x]` selesai · `[~]` sedang dikerjakan · `[ ]` belum mulai
- kolom 2 **tanggal target** (`YYYY-MM-DD`), boleh kosong
- kolom 3 area: `kasir`, `owner`, `admin`, `data`, `infra`
- kolom 4 **estimasi jam kerja**; inilah yang dipakai menghitung tanggal siap deploy

Pekerjaan di bawah judul **"Wajib sebelum deploy"** yang dihitung untuk tanggal siap.
Yang di bawah **"Sesudah deploy"** tidak ikut — itu pembeda, bukan penghalang rilis.

## Kapasitas

Angka ini yang paling menentukan tanggalnya, dan hanya kamu yang tahu. **Ganti kalau
tidak sesuai** — sekarang masih perkiraan.

- jam per hari: 4
- hari kerja per pekan: 6

## Sudah jadi

- [x] Gerbang akses & peran (owner/kasir/dapur/admin) | 2026-07-20 | infra | 6j
- [x] Layar kasir: 3 mode transaksi, offline, antrean sinkronisasi | 2026-07-24 | kasir | 16j
- [x] Beranda kasir: riwayat, ringkasan shift, koreksi modal awal | 2026-07-26 | kasir | 6j
- [x] Kelola produk: daftar, formulir, gambar, soft delete | 2026-07-29 | owner | 10j
- [x] Barcode: pemindai USB, kamera, cadangan ZXing, foto | 2026-07-31 | owner | 8j
- [x] Pemindaian di layar kasir + suara/ucapan berhasil-gagal | 2026-08-01 | kasir | 6j
- [x] SKU otomatis per tenant | 2026-08-01 | owner | 2j
- [x] Laporan penjualan owner: omzet, grafik, terlaris, metode | 2026-07-30 | owner | 6j
- [x] Alat: agen tim, laporan Telegram, pemeriksa kerapian | 2026-08-01 | infra | 6j
- [x] Perbaikan uang kasir: bayar terpisah & validasi sinkronisasi | 2026-08-03 | kasir | 6j
- [x] Stok & opname: konversi satuan, ambang minimum, hitung fisik, status "belum dihitung" | 2026-08-04 | owner | 12j
- [x] Pagination 10 baris seluruh aplikasi (satu setelan + uji penjaga sumber) | 2026-08-04 | infra | 2j

## Wajib sebelum deploy

- [ ] Baris `stocks` kembar: `SiapkanBarisStokAction` tidak atomik | | data | 2j
- [ ] Sisa stok tampil di layar kasir (lencana di petak produk) | | kasir | 4j
- [ ] Pembelian: stok masuk, supplier, harga beli | | owner | 8j
- [ ] Kasbon: daftar piutang + pelunasan tercatat | | owner | 6j
- [ ] Pelanggan: daftar + formulir | | owner | 4j
- [ ] Impor produk dari Excel/CSV | | owner | 5j
- [ ] Karyawan | | owner | 5j
- [ ] Outlet & perangkat | | owner | 5j
- [ ] Bill terbuka (layar owner) | | owner | 4j
- [ ] Tutup kasir (layar owner) | | owner | 4j
- [ ] Service worker: layar kasir tetap terbuka saat dimuat ulang tanpa jaringan | | kasir | 5j
- [ ] Audit kerapian seluruh layar (7 angka nol di 390/768/1280) | | owner | 4j
- [ ] Infra rilis: domain, HTTPS, queue worker, cron, cadangan basis data | | infra | 6j

## Sesudah deploy (pembeda dari POS lain di pasaran)

- [ ] Laporan harian otomatis ke pemilik lewat WhatsApp/Telegram + ucapan | | infra | 4j
- [ ] Daftar kulakan otomatis: apa yang harus dibeli + perkiraan uangnya | | owner | 6j
- [ ] Jual ketengan: pecah dus jadi satuan kecil dengan harga sendiri | | owner | 6j
- [ ] Barang titipan (konsinyasi) + bagi hasil per penitip | | owner | 10j
- [ ] Buku kasbon + tagih lewat WhatsApp sekali tekan | | owner | 5j
- [ ] Cetak struk langsung ke printer bluetooth termal | | kasir | 6j
- [ ] Mode tombol besar untuk kasir lanjut usia | | kasir | 3j
- [ ] Jenis usaha FOTOKOPI: harga bertingkat per jumlah lembar + entri kuantitas cepat | | owner | 8j

## Catatan

Bagian ini ikut dikirim apa adanya ke Telegram. Tulis di sini hal yang tidak terbaca
dari uji maupun dari daftar di atas — keputusan yang masih menggantung, dan hal yang
menunggu jawaban.

- **HTTPS wajib sebelum deploy**, bukan sekadar praktik baik: peramban melarang kamera
  di luar konteks aman, jadi pemindaian barcode lewat kamera mati total di alamat biasa.
- ~~Menolak sinkronisasi yang pembayarannya tidak cocok~~ **SUDAH DIPUTUSKAN**: ditolak
  PER TRANSAKSI di dalam action, bukan sebagai 422 untuk seluruh batch. Satu muatan rusak
  tidak boleh menyandera penjualan sah di belakangnya. Hanya satu bentuk yang ditolak di
  gerbang (422): jumlah pembayaran MELEBIHI total, karena bentuk itu tidak punya wujud yang
  sah sama sekali — kelebihan uang tunai dinyatakan lewat jumlah_diterima.
- Yang belum: kalau server membalas 422, klien masih menahan paketnya di antrean dan
  mencoba lagi terus. Tidak bisa terjadi dari klien kita sendiri (bisaBayar menuntut
  jumlahnya pas), tapi belum ada karantina untuk muatan yang ditolak permanen.
- Estimasi jam di atas perkiraan saya berdasarkan pekerjaan sejenis yang sudah selesai di
  repo ini, sudah termasuk uji dan verifikasi tampilan — **belum termasuk** waktu kamu
  meninjau dan meminta perubahan.
- Zona waktu aplikasi sudah Asia/Jakarta. Data lama yang ditulis saat masih UTC terbaca
  bergeser +7 jam; data demo cukup disemai ulang.
- **Baris `stocks` bisa kembar** (temuan analis, belum diperbaiki, sudah masuk daftar
  wajib): `SiapkanBarisStokAction` memakai `first()` lalu `create`, bukan operasi atomik.
  Dua perangkat kasir yang menjual barang yang sama saat barangnya belum punya baris stok
  bisa membuat dua baris sekaligus, dan `LEFT JOIN` di layar stok akan menampilkannya
  **kembar**. Obatnya unique index `(outlet_id, product_id)` dan
  `(outlet_id, raw_material_id)` — bukan sekadar mengubah ke `firstOrCreate`, karena
  balapannya ada di tingkat basis data.
- **Status stok "belum dihitung" dipisahkan dari "habis"** (diputuskan 2026-08-04). Barang
  yang belum pernah punya baris `stocks` tidak lagi dicap merah "Habis" dan tidak lagi
  masuk blok "Harus belanja" — angkanya belum ada, jadi jumlah belanjanya tidak bisa
  dihitung. Alasannya bukan estetika: 300 lencana merah di warung baru membuat pemilik
  belajar mengabaikan warna merah, sehingga saat satu barang benar-benar kosong,
  peringatannya sudah tidak berarti apa-apa. Yang SENGAJA belum dibedakan: baris yang ada
  tapi belum pernah dihitung fisik (mis. lahir dari penyetelan ambang) — di situ pemilik
  sudah menyatakan harapannya sendiri, jadi meminta beli sebanyak ambang bukan mengarang.
- Yang QA jujur belum buktikan di lembar opname, jangan dianggap aman maupun cacat: angka
  fisik notasi ilmiah (`1e10`), dan `alasan`/`catatan` dikirim sebagai array lewat payload
  Livewire mentah.

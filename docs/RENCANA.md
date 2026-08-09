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
- [x] Pagination 10 baris seluruh aplikasi termasuk riwayat kartu stok | 2026-08-04 | infra | 2j
- [x] Opname terkunci ke cabang tempat angkanya dihitung + jejak audit jujur | 2026-08-04 | owner | 3j
- [x] Baris `stocks` kembar: unique index dijamin + balapan tidak menggagalkan penjualan | 2026-08-05 | data | 2j
- [x] Sisa stok di layar kasir: lencana keadaan + umur kabar 30 menit | 2026-08-05 | kasir | 4j
- [x] Pembelian sisi data: nota, batal, harga beli, kunci outlet | 2026-08-05 | owner | 5j
- [x] Pembelian: tampilan daftar + formulir + rincian nota | 2026-08-05 | owner | 3j
- [x] Pembelian sisi data: keadaan "barang belum datang", stok masuk saat ditandai datang | 2026-08-06 | data | 4j
- [x] Pembelian sisi data: foto bukti belanja (kwitansi/struk) — opsional, folder sendiri, terkunci saat nota dibatalkan | 2026-08-06 | data | 3j

## Wajib sebelum deploy

- [x] Kasbon: daftar piutang + pelunasan tercatat | 2026-08-09 | owner | 6j
      Tabel BARU `credit_payments`: tiap setoran satu baris. `jumlah_dibayar` di
      `credit_ledgers` dipertahankan sebagai angka turunan yang disimpan, dihitung
      ULANG dari SUM tabel itu — bukan ditambahkan, supaya pembatalan satu setoran
      otomatis mengembalikan sisa utang tanpa ada yang mengurangi dengan tangan.
      Seluruh perubahannya lewat CatatPelunasanAction, satu pintu.
      TEMUAN UJI MUTASI, dan ini jalan buntu sungguhan: kasbon bersen (kolomnya
      decimal(15,2), dan kasbon dari struk berpajak bisa membawa 100000.50) DULU
      tidak bisa dilunasi sama sekali — mengisi apa adanya ditolak Uang::baca()
      yang menolak desimal, dan round() menghasilkan 100001 yang ditolak aksinya
      sebagai melebihi sisa. Ditutup dengan dua hal: tombol "isi sisa" membulatkan
      KE BAWAH, dan sisa di bawah SATU rupiah dinyatakan lunas (bukan seratus —
      Rp 50 yang benar-benar terutang tetap utang, dan ada ujinya).
      Yang SENGAJA tidak ada: mengubah jumlah utang kasbon yang lahir dari struk
      (struk dan buku kasbon akan bercerita beda tentang belanja yang sama), dan
      menghapus kasbon (piutang yang bisa dihapus adalah piutang yang bisa
      dihilangkan oleh orang yang menerima uangnya).
- [ ] Kasbon: rekap "setoran masuk hari ini" | | owner | 2j
      Datanya sudah ada — `credit_payments.dibayar_pada` memang dipisahkan dari
      created_at justru untuk ini. Yang belum ada layarnya. Berguna saat pemilik
      menutup hari: uang penagihan tidak lewat laci kasir, jadi ia tidak muncul di
      Tutup kasir maupun di omzet.
- [x] Pelanggan: daftar + formulir | 2026-08-09 | owner | 4j
      Dikerjakan MENDAHULUI Kasbon meski urutan di sini sebaliknya:
      `credit_ledgers.customer_id` tidak nullable, jadi tanpa pintu ini kasbon cuma
      bisa dibangun di atas pelanggan hasil seeder dan gerbangnya tidak pernah
      teruji dengan data sungguhan.
      Yang lahir bersamanya: App\Support\NomorHp (penyeragam nomor telepon). Tanpa
      itu "0812-3456-7890" dan "+62 812 3456 7890" adalah dua teks berbeda bagi
      basis data, keduanya lolos aturan unik, dan utang SATU orang terpecah ke dua
      baris — pemilik menagih separuhnya tanpa satu pun galat di layar.
      Poin SENGAJA tidak dimunculkan di formulir: kolomnya ada sejak migrasi pertama
      tapi tidak ada satu baris kode pun yang menaikkan atau membacanya.
- [x] Impor produk dari CSV | 2026-08-09 | owner | 5j
      Dua langkah: PERIKSA dulu, simpan belakangan. Mengimpor 300 baris yang salah
      jauh lebih buruk daripada menolak berkasnya — yang telanjur masuk harus
      dihapus satu per satu, sebagian sudah dipakai transaksi sehingga tidak bisa
      dihapus lagi, dan harga yang salah sudah dipakai kasir hari itu.
      App\Support\BacaCsv lahir bersamanya, dan ia dibuat TIDAK tahu arti kolom apa
      pun supaya bisa dipakai ulang untuk impor pelanggan dan bahan baku nanti.
      Tiga jebakan yang dijaganya, semuanya sunyi: Excel Indonesia memakai pemisah
      titik koma, Excel menaruh BOM sehingga judul kolom PERTAMA saja yang gagal
      dicocokkan, dan Excel lama menyimpan dalam Windows-1252.
      Pencocokan HANYA lewat SKU, tidak pernah lewat nama: "Teh Manis" dan "Teh
      manis" adalah dua barang bagi orang yang mengetiknya, dan menimpakan harga
      baru ke barang yang salah tidak bersuara.
- [ ] Impor produk: terima berkas .xlsx langsung | | owner | 3j
      KEPUTUSAN PEMILIK yang belum diambil, dan itu sebabnya belum dikerjakan:
      membaca .xlsx butuh phpspreadsheet, sementara composer.json proyek ini cuma
      berisi empat paket dan kerampingan itu tampak disengaja. Sekarang pemilik
      harus "Save as CSV" dulu — satu butir menu, tapi setiap daftar harga yang
      beredar di WhatsApp berbentuk .xlsx, jadi sebagian orang akan tersandung di
      langkah pertama. Kalau nanti dikerjakan, yang ditambah HANYA satu pembaca:
      seluruh pipa periksa-pratinjau-simpan sudah tidak bergantung bentuk berkas.
- [x] Karyawan | 2026-08-09 | owner | 5j
      BERBEDA dari layar lain: `User` sengaja TIDAK memakai BelongsToTenant (global
      scope akan ikut membatasi kueri auth guard), jadi TIDAK ADA jaring pengaman —
      satu kueri yang lupa `where('tenant_id')` menyentuh karyawan warung lain.
      Seluruh akses karena itu lewat kueriDasar()/karyawanMilikSaya().
      Gerbang: super_admin tidak bisa dibuat dari sini; pemilik tidak bisa
      menonaktifkan/menurunkan/menghapus dirinya sendiri; pemilik aktif TERAKHIR
      tidak bisa dihapus; karyawan bersesi kas TERBUKA tidak bisa dihapus maupun
      dinonaktifkan (sesinya tidak akan bisa ditutup siapa pun, jadi uang laci hari
      itu tidak pernah dicocokkan).
      DUA TEMUAN UJI MUTASI. (1) Aturan Closure TIDAK dijalankan Laravel untuk nilai
      kosong, jadi gerbang "kasir wajib punya cabang" diam justru saat kolomnya
      kosong — diganti Rule::requiredIf yang implisit. (2) Gerbang "owner terakhir"
      tidak pernah teruji karena tertutup gerbang "tidak bisa hapus diri sendiri";
      menelusurinya memunculkan keadaan yang belum dipikirkan — manajer outlet juga
      boleh membuka layar ini, jadi DIA yang bisa menghapus pemilik atau mengangkat
      diri jadi pemilik. Ditutup dengan aturan "hanya pemilik yang boleh memberikan
      peran pemilik".
- [ ] Karyawan: tinjau ulang hak manajer outlet di seluruh back office | | owner | 2j
      Grup rute back office memberi manager_outlet dan regional_manager akses yang
      SAMA dengan pemilik ke hampir semua layar — termasuk Karyawan, Kasbon, dan
      Impor produk (yang bisa mengubah harga seluruh katalog sekali tekan). Dua
      celah paling tajam sudah ditutup di layar Karyawan, tapi itu tambalan per
      layar. Yang belum ada: keputusan pemilik tentang sampai mana manajer boleh
      pergi, lalu satu gerbang yang menegakkannya di satu tempat.
- [ ] Outlet & perangkat | | owner | 5j
- [ ] Bill terbuka (layar owner) | | owner | 4j
- [ ] Tutup kasir (layar owner) | | owner | 4j
- [ ] Service worker: layar kasir tetap terbuka saat dimuat ulang tanpa jaringan | | kasir | 5j
- [ ] Kontras lencana merah: 21 pemakaian masih 4,15:1 (butuh 4,5:1) | | owner | 1j
- [ ] Lampiran G1: keluar dari folder publik ke rute berpenjaga | | backend | 3j
      Dikerjakan LEBIH DULU, bukan terakhir, dan alasannya kesempatan: sekarang
      cuma 3 berkas yang perlu dipindah karena belum dirilis. Tiap gelombang yang
      menunda ini menambah berkas yang dipindah, dan menambah PDF — yang isinya
      DAFTAR harga, bukan satu harga. Sesudah rilis, pemindahannya tidak pernah
      murah lagi.
- [x] Lampiran G2 TAMPILAN: galeri + unggah banyak + kartu PDF | 2026-08-08 | owner | 4j
      Sisi datanya SUDAH selesai (tabel, aksi, rute per-id, aksi layar
      pasangLampiran/hapusLampiran — semuanya teruji). Yang kurang cuma galerinya.
      BLOKIR YANG HARUS DIBERESKAN DULU, dan ini temuan tersendiri:
      `resources/views/livewire/pages/owner/pembelian/pembelian.blade.php` TIDAK BISA
      DITAMBAHI apa pun. Menambahkan `@php $x = 1; @endphp` sepolos itu ke ujungnya
      membuat kompilasi Blade gagal dengan "syntax error, unexpected token endif" —
      sudah diuji, dan bukan soal blok yang ditambahkan. Berkasnya sekarang mengompilasi
      bersih apa adanya, jadi ada ketidakseimbangan struktural yang hanya tertutupi oleh
      posisi akhir berkasnya. Isolasi ITU dulu (bagi berkasnya jadi partial, atau telusuri
      direktif yang membuka tanpa menutup), baru pasang galerinya. Menambal galerinya di
      atas berkas yang rapuh cuma memindahkan letak ledakannya.
- [ ] Lampiran G2 DATA: SELESAI 2026-08-08 (150248f, 29905cb) — tabel polimorfik, batas
      10, PDF, rute berpenjaga per-id, mirror sementara dari bukti_path
- [x] Lampiran G2 penutup: kolom bukti_path dibuang | 2026-08-08 | backend | 1j
      PEMILIK MEMUTUSKAN 2026-08-07: batasnya 10, bukan 5 — ada nota grosir yang
      lembarnya banyak, dan tiap lembar mau difoto terpisah supaya terbaca.
      Akibatnya galerinya TIDAK muat sebaris di 1280px, jadi perlu berhalaman
      atau digulir mendatar; spesifikasi asli mengandaikan 5 dan bentuk galerinya
      harus disesuaikan. Batas 10 juga menaikkan risiko batas PHP terlampaui,
      jadi saringan sisi klien (saringPilihan) makin wajib, bukan opsional.
- [x] Lampiran G3: kamera dekstop | 2026-08-09 | owner | 3j
      resources/js/kamera.js + tests/js/kamera.test.mjs (20 uji). pemindai.js dan
      tests/js/pemindai.test.mjs TIDAK disentuh sama sekali, sesuai syaratnya.
      MENYIMPANG DARI RENCANA, dan disengaja: campuran kode bersamanya BELUM
      diekstrak dari pemindai.js — ekstraksi itu menyunting berkas layar kasir,
      dan itu pekerjaan sendiri, bukan yang diselundupkan ke fitur owner. Lihat
      butir "Kamera: satukan plumbing" di bawah. Sampai itu dikerjakan, gerbang
      konteks aman dan penerjemahan galat ada di DUA tempat.
- [ ] Kamera: satukan plumbing kamera.js dan pemindai.js | | kasir | 2j
      Yang kembar: gerbang konteks aman, nyalakan/matikan aliran, penerjemahan
      galat getUserMedia. Yang TIDAK boleh ikut disatukan: pemindai membaca
      terus-menerus dan memuat ZXing, kamera bukti memotret sekali lalu ditinjau
      — satu panel untuk keduanya akan penuh cabang. Syaratnya sama seperti G3:
      tests/js/pemindai.test.mjs wajib hijau TANPA disunting; kalau perlu
      disunting, ekstraksinya salah. Kerjakan bersama butir "Pesan galat kamera
      barcode kemungkinan tidak pernah terlihat" — keduanya menyentuh berkas yang
      sama, dan kamera.js sudah memakai bentuk yang benar (galat di LUAR panel).
- [ ] Lampiran: tautan berumur yang bisa dibagikan | | backend | 2j
      PEMILIK MEMUTUSKAN 2026-08-07: "belum tahu, jangan dikunci dulu". Jadi G1
      jalan apa adanya sekarang; ini ditambahkan HANYA kalau ternyata pemilik
      perlu mengirim tautan struk ke grosir. Menambahkannya kemudian tidak
      membongkar G1 — tautan bagi berdiri di atas rute berpenjaga, bukan
      menggantikannya. Jangan dibangun spekulatif: tiap jalur tanpa login
      menghidupkan lagi kebocoran yang G1 baru saja tutup.
- [ ] Pesan galat kamera barcode kemungkinan tidak pernah terlihat | | kasir | 1j
      `panel-pindai.blade.php:105` menaruh teks galat DI DALAM panel
      (`x-show="terbuka"`), sementara `pemindai.js:212` memanggil `tutup()`
      sesudah menyetel `galat` — dan cabang konteks-tidak-aman (`:184-186`) tidak
      pernah membuka panelnya. Jadi kasir yang izin kameranya ditolak menekan
      tombol, panelnya menutup, dan penjelasannya berada di tempat yang tidak
      dirender. BELUM dibuktikan di peramban hidup — buktikan dulu sebelum
      memperbaiki. Ditemukan analis saat merancang panel kamera bukti, dan itu
      sebabnya panel kamera yang baru menaruh teks galatnya di LUAR panel.
- [ ] Bahan berstok dihapus: sisanya lenyap dari layar | | analis | 1j
      Menghapus bahan yang masih punya sisa 10 kg membuat sisa itu hilang dari
      layar Stok (SoftDeletingScope), padahal barangnya masih ada di dapur.
      Ini sifat soft delete bahan SECARA UMUM — sama saja kalau sisanya datang
      dari Hitung stok, bukan dari nota — jadi tempatnya bukan di gerbang nota
      dan sengaja TIDAK ditambal diam-diam di sana. Yang perlu diputuskan
      pemilik: apakah bahan yang masih bersisa boleh dihapus sama sekali, dan
      kalau tidak, apa jalan keluarnya (hitung stok jadi nol dulu?).
- [ ] Bahan: tandai di daftar kalau ada nota belum datang | | owner | 1j
      Kolom "Dipakai di" sudah memberi tahu sebelum orang menekan Hapus; tanda
      serupa "ada nota belum datang (NB-…)" menghemat satu penolakan. Butuh data
      tambahan di kueriBahan() + perubahan Blade. Bukan pemblokir: gerbangnya
      sudah menahan di server dan pesannya menyebut jalan keluarnya.
- [ ] Sidebar: menu tidak muat di layar 800px, butir terbawah di bawah lipatan | | owner | 1j
      JUDUL DAN SEBABNYA DIKOREKSI 2026-08-09, catatan lama keliru. Diukur ulang di
      1280x800 saat menyalakan menu Pelanggan: dasar nav ada di y=636 dan kartu
      pengguna baru mulai di y=655, jadi TIDAK ADA yang tertutup kartu — nav adalah
      wadah gulir tersendiri (`flex-1 overflow-y-auto`) dan kartunya saudara di
      bawahnya. Yang benar: menunya 316px lebih panjang daripada ruang yang ada,
      jadi butir terbawah berada di bawah lipatan. elementFromPoint sesudah
      digulir menjawab `bisaDiklik=ya`, jadi bukan pemblokir.
      SUDAH DIKERJAKAN 2026-08-09: kabut gradien di dasar nav sebagai tanda "masih
      ada lanjutannya", karena Chrome dan Safari menyembunyikan bilah gulirnya
      sampai ada gulungan — orang yang yakin sudah melihat seluruh menu tidak akan
      menggulir untuk memeriksa. Terukur 300x32 di y=604..636, dan
      `pointer-events-none` terbukti tidak menelan klik (titik tengahnya mengenai
      isi menu, bukan kabutnya).
      SISANYA: kabut cuma memberi tahu, tidak membuat butirnya terlihat. Ruang atas
      sidebar memakan ~108px sebelum menu pertama (pt-[3.125rem] + mt-[3.625rem]);
      merapatkannya adalah yang benar-benar membuat menunya muat, dan itu menyentuh
      bahasa visual seluruh aplikasi — jadi keputusan pemilik, bukan tempelan.
- [ ] Pemeriksa tumpangTindih tidak mengerti penumpukan lapisan | | owner | 1j
      `tests/browser/periksa-rapi.js` menyaring lewat konteks berposisi, dan itu
      sudah menyelamatkannya dari menuduh dialog. Tapi pasangan di DALAM satu
      konteks yang saling menimpa secara sah — kartu sticky di atas daftar yang
      digulir — tetap dilaporkan. Pakai elementFromPoint sebagai wasit: yang
      dilaporkan hanya kalau ada yang benar-benar TERTUTUP.
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
- **Baris `stocks` kembar — selesai 2026-08-05, dengan satu koreksi atas temuan aslinya.**
  Kedua unique index yang disebut analis (`(outlet_id, product_id)` dan
  `(outlet_id, raw_material_id)`) TERNYATA SUDAH ADA sejak `create_stocks_table`;
  pemeriksaan skema membuktikannya. Artinya baris kembar sebenarnya tidak bisa lahir —
  yang benar-benar terjadi di jalur balapan justru lebih buruk dan tidak terpikirkan
  sebelumnya: `SiapkanBarisStokAction` melempar pelanggaran unique, dan karena aksi itu
  dipanggil dari jalur penjualan, PENJUALANNYA yang gagal (melanggar aturan 5). Sekarang
  pelanggarannya ditangani — baris pemenang dipungut, penjualan tetap tercatat. Kedua index
  dikunci uji skema supaya tidak bisa hilang diam-diam, dan ada migrasi penjamin yang
  menggabungkan baris kembar (menjumlahkan saldo, memindahkan mutasi sebelum menghapus)
  untuk basis data lama yang index-nya pernah hilang.
- **Status stok "belum dihitung" dipisahkan dari "habis"** (diputuskan 2026-08-04). Barang
  yang belum pernah punya baris `stocks` tidak lagi dicap merah "Habis" dan tidak lagi
  masuk blok "Harus belanja" — angkanya belum ada, jadi jumlah belanjanya tidak bisa
  dihitung. Alasannya bukan estetika: 300 lencana merah di warung baru membuat pemilik
  belajar mengabaikan warna merah, sehingga saat satu barang benar-benar kosong,
  peringatannya sudah tidak berarti apa-apa. Yang SENGAJA belum dibedakan: baris yang ada
  tapi belum pernah dihitung fisik (mis. lahir dari penyetelan ambang) — di situ pemilik
  sudah menyatakan harapannya sendiri, jadi meminta beli sebanyak ambang bukan mengarang.
- **Lembar opname terkunci ke cabang tempat angkanya dihitung** (selesai 2026-08-04).
  Sebelumnya angka hasil hitung Cabang A bisa tersimpan ke Cabang B lewat dropdown, dan
  catatan mutasinya bahkan memberi alasan yang terbaca wajar. Sekarang kunci lahir dari
  ANGKA yang diketik (bukan halaman yang dibuka), simpan menolak kalau layar menampilkan
  cabang lain, dan pemilik diberi tiga pilihan bernama. Yang menolak dibersihkan otomatis:
  `<select>` berpindah nilai hanya dengan satu tombol panah, jadi menghapus hasil hitung
  120 barang tanpa bertanya menukar cacat dengan cacat.
- **Yang masih BELUM terbukti** di lembar opname, jangan dianggap aman maupun cacat:
  kebocoran antar-tab (dua tab, dua cabang, owner yang sama), dan perilaku `wire:key` di
  peramban hidup — yang terbukti baru bahwa kuncinya berubah per outlet plus alasan
  morph-nya; sisanya butuh sesi login dan klik nyata.
- **Sisa stok di layar kasir menampilkan KEADAAN, bukan angka** (diputuskan 2026-08-05).
  "Sisa 2" yang salah membuat kasir menjanjikan angka yang tidak ada; "Menipis" tetap benar
  walau sudah lewat sepuluh transaksi. Kabarnya punya UMUR 30 menit
  (`NAMPAN_SISA_STOK_MENIT`) lalu lencananya hilang — tanpa itu, lencana "Habis" berusia
  enam jam membuat kasir menolak menjual barang yang kirimannya baru datang, yaitu kerugian
  yang sama persis hanya terbalik arahnya. Menu warteg mengambil keadaan BAHAN BAKUNYA
  (bahan terparah menentukan); bahan yang belum pernah dihitung DIABAIKAN, bukan dianggap
  habis. **Belum diukur kerapiannya** — pratinjau memotret layar kasir sebagai HTML statis
  sementara lencananya bergantung fetch hidup, jadi tangkapannya selalu tanpa lencana.
- **Pembelian: harga TERAKHIR menang, bukan rata-rata bergerak** (diputuskan 2026-08-05).
  Rata-rata butuh saldo sebagai pembagi, sedangkan saldo boleh minus dan boleh belum ada —
  rata-rata atas saldo −3 menghasilkan harga negatif tanpa satu pun galat. Akibat yang harus
  ditangani FE: seluruh stok lama ikut dinilai ulang dengan harga terbaru, jadi layar Stok
  WAJIB memuat satu kalimat "Dihitung dengan harga beli terakhir" di bawah nilai persediaan —
  angka yang melompat tanpa kalimat itu terbaca sebagai aplikasi yang salah hitung.
- **Pembelian: keadaan "barang belum datang" — sisi data selesai 2026-08-06.** Ini MENGGANTI
  kalimat "tanpa alur draf: disimpan berarti barang sudah datang" di catatan sebelumnya.
  Cacat yang diperbaiki: menyimpan nota langsung menambah stok, jadi belanja yang barangnya
  datang tujuh hari kemudian membuat saldo mengaku ada barang yang belum tiba di rak — dan
  layar kasir mengabari "Aman" untuk rak yang kosong, lalu kasir menjanjikannya ke pembeli.
  Sekarang stok HANYA bergerak lewat `TerimaPembelianAction` (aksi terpisah, bukan cabang
  `if`), dan harga beli master juga baru diperbarui di situ — memperbaruinya saat simpan
  berarti menilai ulang barang yang sudah di rak dengan harga barang yang belum dibeli.
  Bawaan formulir tetap "barangnya sudah saya terima", jadi belanja warung biasa tidak
  berubah perilakunya. Yang SENGAJA tidak dibangun, dan jangan pernah dibangun: apa pun yang
  menahan penjualan (aturan 5) — kasir cuma tidak dikabari, tidak dihalangi. Terima SEBAGIAN
  juga tidak dibangun; `qty_diterima` selalu penuh dan selisihnya lewat Hitung stok, kalimatnya
  disediakan sebagai `TerimaPembelianAction::CATATAN_TERIMA_SEBAGIAN`. Status `Draft` tetap
  TIDAK PERNAH ditulis aplikasi walaupun ia default kolomnya — itulah yang menjadikannya
  penanda anomali, dan ada uji yang menjaganya. Dua cacat lain ikut ditutup: membatalkan nota
  belum-datang tidak lagi menulis ulang harga beli master, dan pesan pembatalannya tidak lagi
  mengaku "stok dikembalikan" untuk barang yang belum pernah masuk.
- **Sisa sisi tampilan untuk "belum datang"** (FE, belum dikerjakan saat catatan ini ditulis):
  pilihan dua-cabang di formulir nota (`sudahDatang`, bawaan true, TANPA bintang wajib karena
  validatornya bukan required), tombol + blok konfirmasi "Tandai datang" yang memanggil
  `tandaiDatang($id)` dan memuat `$catatanTerimaSebagian`, pil saringan `status=belum`, dan
  kartu ringkasan "Menunggu datang" (`$ringkasan['menunggu']` → nilai, nota, umur_hari, tertua).
  Kartu "Belanja bulan ini" sekarang hanya nota yang sudah datang, jadi keterangan kecilnya
  perlu menyebutkannya — bukan lagi cuma "tanpa nota yang dibatalkan".
- **Pembelian: foto bukti belanja — sisi data selesai 2026-08-06.** Satu kolom
  `purchase_orders.bukti_path` (string nullable), SATU berkas per nota, dan **opsional
  selamanya**: warteg yang belanja di pasar pagi tidak berstruk, dan mewajibkannya berarti
  separuh pengguna berhenti mencatat belanja. Keputusan yang tidak boleh ditawar ulang:
  (1) **kegagalan unggah TIDAK PERNAH menggagalkan penyimpanan nota** — `bukti` tidak boleh
  masuk ke aturan validasi mana pun yang bisa melempar dari `simpan()`; `SimpanBuktiBelanjaAction`
  mengembalikan bool, dan toastnya berterus terang ("Nota PB-… tersimpan. Fotonya belum
  terpasang — pasang dari daftar nota kalau sinyal sudah bagus."); (2) foldernya
  `storage/app/public/bukti-belanja/{tenant_id}/`, **BUKAN `produk/`** — aturan keras nomor 1
  melindungi `produk/` dan pembersih "gambar produk yatim" kelak akan menghapus bukti belanja
  yang menumpang di situ; (3) bisa dipasang belakangan pada nota **berstatus apa pun** (belum
  datang maupun sudah datang) — itulah nilai utamanya: catat cepat di depan grosir, foto nanti;
  (4) nota **dibatalkan = buktinya TERKUNCI**: boleh dilihat, tidak boleh diganti/dihapus, dan
  berkasnya tidak pernah disentuh oleh pembatalan (nota batal berarti barangnya dikembalikan, dan
  struk itu justru bukti pengembaliannya — aturan keras 6). Batas ukuran
  `config('nampan.bukti_maks_kb')` (bawaan 4096 = dua kali batas gambar produk, karena gambar
  produk diunduh tablet kasir berkali-kali sedangkan bukti dibuka pemiliknya sekali), mimes
  `jpg,jpeg,png,webp`. URL berkas WAJIB `asset('storage/'.$path)`; ada penjaga sumber kode di
  `PembelianBuktiTest` yang menolak `Storage::url()` di berkas apa pun yang menyentuh
  `bukti_path`, termasuk Blade. Yang SENGAJA tidak dibangun: tabel lampiran, banyak berkas per
  nota, unggah dari layar kasir, dan pemangkasan/kompresi gambar di server.
- **Sisa sisi tampilan untuk bukti belanja** (FE): kotak pilih foto di formulir nota baru
  (`wire:model="bukti"`, TANPA bintang wajib — validatornya `nullable`; keterangan batas ambil
  dari `$batasBukti`, jangan mengetik "4 MB" sendiri), tombol buang pilihan
  (`buangBuktiPilihan()`), dan di panel rincian nota: pratinjau `$nota->urlBukti()` (null =
  tampilkan keadaan **netral** "Belum ada foto", BUKAN peringatan merah — 90% nota warteg tanpa
  bukti berarti 90% baris merah, lalu orang belajar mengabaikan merah), tombol pasang/ganti
  (`pasangBukti()`), tombol hapus bertint merah + konfirmasi SweetAlert (`hapusBukti()`), dan
  untuk nota **dibatalkan**: fotonya tetap ditampilkan tapi kedua tombolnya TIDAK dirender.
- **Kontras lencana merah belum selesai — 21 pemakaian masih gagal.** Token
  `--color-merah-tua` (#9b1c1c, 7,14:1) sudah ada dan sudah dipakai lencana kasir, tapi
  `text-merah-deep` di atas `bg-merah/…` masih 4,15:1 di 21 tempat. Perbaikan terbesar per
  baris kode: satu baris di `resources/views/components/lencana.blade.php:15`
  (`'merah' => 'bg-merah/10 text-merah-deep'`) menyelesaikan mayoritasnya sekaligus; sisanya
  kelas inline di `owner/stok.blade.php:433`, `owner/produk.blade.php:764`,
  `kasir/beranda.blade.php:361`. Yang di atas latar PUTIH sudah lolos dan tidak perlu diubah.
  Tiga teks galat di atas tint jingga/terracotta (4,35–4,46:1) polanya BERBEDA — bukan
  sekadar ganti token, perlu keputusan desain sendiri.
- **Catatan alat ukur (dua jebakan yang sudah ditutup, jangan diulang).** (1) Lebih dari satu
  Chrome di porta 9333 membuat lebar yang dilaporkan bergeser satu langkah — minta 390 dapat
  1280 — dan SEMUANYA tampak BERSIH karena asetnya termuat. `ukur.mjs` sekarang menolak kalau
  lebar diminta ≠ lebar terukur. (2) `ukur-pratinjau.sh` dulu memakai satu nama folder tetap
  dengan `trap EXIT` yang menghapusnya, jadi proses yang selesai lebih dulu menghapus folder
  milik yang masih berjalan; foldernya sekarang berakhiran PID. Urutan wajib: `npm run build`
  DULU, baru `PRATINJAU=1 … PratinjauTest` — build mengubah hash aset.
- Yang QA jujur belum buktikan di lembar opname, jangan dianggap aman maupun cacat: angka
  fisik notasi ilmiah (`1e10`), dan `alasan`/`catatan` dikirim sebagai array lewat payload
  Livewire mentah.

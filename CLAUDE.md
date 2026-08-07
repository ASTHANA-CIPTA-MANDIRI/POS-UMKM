# CLAUDE.md — Nampan POS (POS-UMKM)

Panduan untuk Claude Code di repo ini. **Baca sebelum menyentuh berkas apa pun.**
Setiap agen mulai tanpa ingatan; berkas ini yang menggantikannya.

## Apa yang sedang dibangun

POS multi-tenant (SaaS) untuk UMKM Indonesia: warteg, kelontong, depot air, laundry.
Dua area besar: **kasir** (layar transaksi, wajib jalan offline) dan **owner** (kelola
produk, laporan, stok, karyawan, keuangan).

Bahasa domain: **Indonesia**. Nama kelas, metode, variabel, kolom, rute, dan teks UI
memakai bahasa Indonesia. Jangan campur (`hitungTotal`, bukan `calculateTotal`).

## Tumpukan

Laravel 13 · Livewire 4 · Tailwind CSS 4 (`@theme`, tanpa berkas config) · Alpine (dibawa
Livewire) · Vite 8 · PHP 8.4 · MySQL untuk pengembangan, SQLite `:memory:` untuk uji.

## Perintah

```bash
php artisan serve          # aplikasi
npm run dev                # Vite (HMR)
npm run build              # aset produksi — WAJIB sebelum memverifikasi di peramban
php artisan test           # PHPUnit
npm run uji:js             # uji JS (node --test 'tests/js/*.test.mjs')
vendor/bin/pint            # format PHP; jalankan sebelum melapor selesai
```

## Aturan yang tidak boleh dilanggar

1. **JANGAN pernah menghapus `storage/app/public/produk/`.** Itu gambar unggahan
   sungguhan. Berkas pratinjau ditaruh di `storage/app/public/pratinjau/` dan hanya
   direktori itu yang boleh dihapus. (Pernah terjadi: gambar pengguna hilang permanen.)
2. **`tenant_id` tidak pernah fillable.** Diisi otomatis oleh trait `BelongsToTenant`.
   Setiap kueri lintas tenant di uji memakai `withoutGlobalScopes()` — ingat: itu juga
   melepas `SoftDeletingScope`, jadi baris terhapus ikut terhitung.
3. **Layar kasir tidak boleh bergantung jaringan.** Semua interaksi transaksi di Alpine,
   disimpan di `localStorage` (`nampan.*`), dikirim lewat endpoint sinkronisasi yang
   idempoten. Tidak ada aset/suara/pustaka dari CDN.
4. **Uang divalidasi ketat.** Harga wajib, tidak boleh negatif. Salah satu nol di sini
   berarti harga sepuluh kali lipat.
5. **Stok boleh minus, penjualan JANGAN pernah diblokir karena stok.** Penjualan offline
   yang masuk belakangan sudah benar-benar terjadi; selisih diselesaikan lewat opname.
6. **Jangan menghapus data permanen.** Produk memakai soft delete supaya laporan lama
   tetap utuh.
7. **JANGAN `git stash`, `git checkout <berkas>`, atau `git clean` selama ada pekerjaan
   orang/agen lain yang belum dikomit.** Pohon kerja ini sering dipakai beberapa agen
   sekaligus, dan ketiga perintah itu menyingkirkan perubahan yang belum tersimpan —
   milik siapa pun, tanpa bertanya. Sudah hampir terjadi: `git stash -u` dipakai untuk
   mengukur "patokan bersih", dan ikut mencabut satu layar baru beserta ujinya selama
   ±13 detik. Kali itu `stash pop` berhasil; kalau prosesnya mati di sela itu, pekerjaan
   satu putaran penuh hilang tanpa jejak di mana pun.
   Kalau butuh patokan uji tanpa perubahan orang lain: jalankan `--filter` pada uji
   fitur Anda sendiri, atau ukur di klon terpisah. JANGAN membersihkan pohon bersama.

## Susunan kode

- `app/Livewire/Pages/{Owner,Kasir,Admin}/*.php` — halaman penuh, bukan controller.
  Layout: `layouts.aplikasi` (owner/admin), `layouts.kasir`.
- `app/Actions/**` — satu aksi satu berkas untuk logika yang menyentuh uang/stok/kas
  (`ApplySaleToStockAction`, `AdjustStockAction`, `SyncOfflineTransactionsAction`, …).
  Logika seperti ini TIDAK ditulis di komponen Livewire.
- `resources/views/livewire/pages/**` — Blade sepadan dengan komponennya.
- `resources/views/components/**` — komponen bersama: `x-kartu-alat` (kepala + saringan),
  `x-kosong`, `x-lencana` (status berdenyut), `x-aksi` (tombol ikon 36/40px seragam),
  `x-panel-pindai` (panel kamera barcode).
- `resources/js/{kasir,pemindai,dekoder,bunyi,toast}.js` — klien kasir & pemindai.
- Trait Livewire: `TerikatTenant`, `MengirimToast` (`$this->toast('…')`).

## Kebiasaan yang sudah disepakati

- **Toast lewat event, bukan session flash**: `dispatch('toast', pesan: …, jenis: …)`.
  Flash tidak muncul pada pembaruan sebagian Livewire.
- **URL berkas memakai `asset('storage/'.$path)`, bukan `Storage::url()`.** `Storage::url()`
  selalu memakai APP_URL, jadi tablet yang membuka lewat alamat LAN kehilangan semua gambar.
- **Tanggal pakai `startOfMonth()`/`addMonthsNoOverflow()`**, jangan `subMonths()` mentah —
  luapan tanggal 31 pernah membuat nomor invoice kembar.
- **Jangan `@error="…"` di Blade untuk Alpine** — bentrok dengan direktif Blade. Pakai
  `x-on:error`.
- **`<template x-if>` tidak berfungsi di dalam `<svg>`** (bukan HTMLTemplateElement).
  Pakai `x-show`.
- **`x-cloak` butuh aturan CSS** — sudah ada di `app.css`. Jangan hapus.
- Kolom isian tinggi 48px, tombol aksi ikon 36px (meja) / 40px (sentuh), semua wadah di
  dalam formulir memakai garis rambut `border-line` — bukan latar berwarna.
- **SEMUA daftar berhalaman 10 baris, dan angkanya TIDAK pernah diketik di komponen**:
  pakai `config('nampan.per_halaman')`. Daftar yang panjangnya berbeda-beda membuat orang
  kehilangan pegangan — ia menghitung "tinggal satu halaman lagi" dari kebiasaan di layar
  sebelumnya lalu keliru di layar berikutnya. `PenjagaPerHalamanTest` memeriksa SUMBERNYA,
  jadi komponen baru yang menuliskan angkanya sendiri akan gagal di situ.
  Konsekuensi yang harus ditangani, bukan diabaikan: 10 baris berarti lembar panjang
  (opname 120 barang = 12 halaman), jadi nilai yang sudah diketik WAJIB bertahan
  antar-halaman.
  Berlaku juga untuk daftar di DALAM panel (mis. riwayat kartu stok), dan di situ
  `pageName`-nya harus SENDIRI — kalau ikut `page`, membuka riwayat di halaman 3 daftar
  akan melompatkan daftarnya. Reset ke halaman 1 saat panel dibuka, kalau tidak panelnya
  terbuka kosong dan terbaca sebagai "tidak ada data". `->links()` selalu dirender;
  `limit(n)` tanpa penunjuk halaman adalah pemotongan diam-diam.

### Konfirmasi hapus memakai SweetAlert — dan dialognya BUKAN pengamannya

Setiap tindakan yang menghapus atau membatalkan dikonfirmasi lewat SweetAlert2, memakai
pembungkus bersama di `resources/js/` (jangan memanggil `Swal.fire` mentah per layar —
teksnya, warnanya, dan urutan tombolnya akan bercabang).

- **Bundel, bukan CDN.** `sweetalert2` sudah ada di `package.json` dan diimpor di
  `resources/js/toast.js`. Aturan keras nomor 3 melarang aset dari CDN, dan layar kasir wajib
  jalan tanpa jaringan — dialog yang gagal dimuat berarti tombolnya tidak bisa dipakai.
- **Teks bahasa warung**, dan judulnya menyebut APA yang dihapus: "Hapus Kopi Sachet?" bukan
  "Apakah Anda yakin?". Dialog yang tidak menyebut namanya membuat orang menekan "Ya" untuk
  barang yang salah.
- **Tombol pembenar berwarna bahaya** dan menyebut tindakannya ("Ya, hapus"), bukan "OK".
  Tombol batal yang mendapat fokus awal — bukan tombol hapusnya.
- **Sebutkan akibatnya kalau tidak bisa dibatalkan**, dan sebutkan pula kalau bisa: nota yang
  dibatalkan tetap tersimpan, produk memakai soft delete. Peringatan yang lebih menakutkan
  daripada kenyataannya membuat orang berhenti memercayai peringatan berikutnya.
- **Dialog BUKAN pengaman.** Ia hanya mencegah salah-tekan. Wewenang tetap diperiksa di
  server pada setiap aksi (tenant, outlet, peran) — muatan Livewire bisa dikirim tanpa
  pernah melewati dialog apa pun. Uji keamanan memanggil aksinya langsung, tanpa dialog.
- Target sentuh tombolnya ≥44px, dan Esc harus menutup dialog.

### Tindakan merusak berwarna bahaya — dan merahnya terlihat TANPA disentuh

Hapus, batalkan, void: warnanya merah, dan merahnya ada sejak awal.

| Peran tombol | Pakai |
|---|---|
| Tindakan merusaknya sendiri ("Ya, hapus", "Buang N baris") | `.tombol-bahaya` (merah pekat, gradien) |
| Pemicu konfirmasi ("Batalkan nota…", "Hapus foto") | latar tint `bg-merah/10` + `text-merah-tua` |
| Tombol ikon di baris tabel | `<x-aksi warna="bahaya">` |

Dua hal yang menentukan, keduanya lahir dari cacat nyata:

1. **Jangan menunggu hover.** Bentuk lama tombol ikon hapus kelabu dan baru merah saat
   disorot, dengan alasan "supaya tidak memancing ditekan sambil lalu". Alasan itu sah untuk
   tetikus dan salah untuk aplikasi ini: layar owner dipakai di tablet dan HP, dan di sana
   hover TIDAK ADA — jadi tanda bahayanya tidak pernah muncul, tepat di tempat salah-tekan
   paling mungkin. Yang menahannya tetap tidak memancing bukan warnanya yang diredam,
   melainkan bobotnya: tanpa latar, tanpa garis.
2. **Merah yang dipakai `merah-tua`, bukan `merah-deep`.** Di atas tint `bg-merah/10`,
   merah-deep hanya 4,15:1 sementara merah-tua 7,14:1 — terukur, bukan dikira. Teks putih di
   atas merah-deep juga cuma 3,9:1. Tombol yang paling perlu dibaca sebelum ditekan tidak
   boleh jadi yang paling sulit dibaca.

`TombolBahayaTest` menjaganya, termasuk larangan mengetik warna merah pekat sendiri per layar
— kalau tiap layar memilih merahnya sendiri, "merah = merusak" berhenti terbaca sebagai
aturan. Bentuk bertint sengaja DIKECUALIKAN dari larangan itu.

Satu layar tetap punya SATU tindakan utama. `.tombol-bahaya` dipakai di dalam blok
konfirmasi, bukan berdiri sendiri di kepala halaman.

### Medan wajib ditandai bintang merah — tapi HANYA yang benar-benar wajib

Setiap medan yang validatornya `required` diberi `<x-wajib />` sesudah labelnya: bintang
merah `*` dengan keterangan pembaca layar. Pemilik proyek meminta ini untuk seluruh fitur.

**Yang menentukan bukan selera, melainkan aturan validasinya.** Bintang pada medan yang
sebenarnya `nullable` membuat orang mengisi hal yang tidak perlu, dan sesudah dua kali begitu
ia berhenti memercayai bintangnya — lalu melewatkan yang sungguh wajib. Jadi:

- `required` di validator → **selalu** berbintang
- `nullable` → **tidak pernah** berbintang, walaupun terasa penting
- wajib BERSYARAT (mis. alasan hanya wajib kalau ada beda) → bintangnya **muncul saat
  syaratnya aktif**, tidak sebelum itu. Alpine sudah tahu syaratnya di lembar hitung stok.

Keadaan yang mudah salah, dan sudah pernah dipertimbangkan: kolom jumlah di lembar hitung
stok **TIDAK berbintang**. Kosong di situ berarti "barangnya belum dihitung" — itu pernyataan
yang sah, bukan kelalaian, dan menandainya wajib akan memaksa orang mengetik nol yang berarti
"rak kosong".

### Uji mutasi pada berkas Blade: WAJIB `view:clear` sesudah memulihkan

Blade dikompilasi dan hasilnya di-cache. Kalau kamu merusak sebuah `.blade.php` untuk uji
mutasi lalu memulihkannya, **cache masih memegang versi yang dirusak** — dan uji berikutnya
memakai versi itu. Akibatnya bisa dua arah: uji yang seharusnya hijau tampak merah, atau
sebaliknya. Sudah terjadi: satu uji penjaga tampak merah padahal berkasnya sudah benar.

Urutannya: rusak → uji → pulihkan → **`php artisan view:clear`** → uji lagi.

## PATOKAN RESPONSIF — tiru layar Stok & hitung stok

Kedua layar itu sudah melewati belasan putaran koreksi pemilik proyek dan diukur di
390/768/1280. **Layar baru menyalin polanya, bukan mengarang sendiri.** Tiap butir di bawah
lahir dari cacat nyata, jadi menyimpang darinya berarti mengulang cacat yang sama.

### Tabel
- Lebar kolom **persen + `table-fixed`**, jumlahnya 100%. Kolom tanpa lebar akan menyerap
  SELURUH sisa lebar panel, dan lubangnya cuma berpindah tempat — sudah terjadi dua kali.
- Judul kolom rata **tengah**, isinya juga (keputusan pemilik proyek, ditandai di kode).
- Keterangan panjang jangan berkolom sendiri di ujung; jadikan baris kedua di kolom yang
  menjelaskannya.
- `<lg` pakai kartu, `≥lg` pakai tabel. Tabel yang dipaksa ke ponsel menuntut gulir mendatar.

### Kepala seksi (`x-kartu-alat`)
- Tombol aksi **sejajar judul**, seukuran isinya. `aksiPenuh` hanya untuk tindakan utama.
- Teks tombol panjang: sediakan bentuk pendek `sm:hidden` / `hidden sm:inline`. Ukur —
  tombol 211px di kartu 318px membuat judulnya pecah tiga baris.

### Saringan
- Pencarian `col-span-2` (lebar penuh), dropdown berbagi baris **dua-dua** di ponsel.
- Jangan paksa 3–4 kontrol sebaris di 390px: masing-masing tinggal 90–120px dan nama cabang
  tidak terbaca. Dropdown yang menentukan ke mana data TERSIMPAN dibuat lebar penuh.

### Pil saringan / tab
- Wadah `col-span-2` + `flex-wrap`, mengisi lebar penuh, membungkus ke baris berikutnya.
  Jangan digulir mendatar: pil yang harus dicari dengan menggeser sama saja tidak ada.
- Jangan `flex-1` di ponsel — tujuh pil dibagi 390px menyisakan ±50px dan ANGKA di sebelah
  namanya hilang, padahal itu isi utamanya.
- Angka pada pil aktif wajib kontras ≥4,5:1. `opacity-70` putih di atas terracotta hilang.

### Kartu ringkasan (angka)
- Dua-dua di ponsel HANYA kalau isinya muat. **Angka uang tidak boleh terpotong maupun pecah
  dua baris** — pembacanya menduga digit yang hilang dan memakainya untuk memutuskan belanja.
- Kalau tidak muat: perkecil ikon (36px) dan angkanya (0,9375rem) di `<sm`, JANGAN memotong.
- Ikon selalu **sebaris** dengan teks, tidak ditumpuk di atasnya.

### Kartu tindakan berderet (mis. "Harus belanja")
- `<lg`: satu jalur **geser mendatar**, kartu `w-[78%] shrink-0 snap-start` supaya kartu
  berikutnya menyembul — tanpa potongan itu tak ada yang tahu jalurnya bisa digeser.
- `scroll-pl-*` WAJIB menyertai `px-*`. Tanpa itu snap mengabaikan padding dan jalurnya
  menggeser sendiri saat dimuat (terukur `scrollLeft=20`), kartu pertama menempel tepi.

### Bar sticky
- Cadangkan padding bawah **≥ tinggi bar terukur**, di tiap breakpoint. Mengubah isi bar
  mengubah tingginya — ukur ulang, jangan pakai angka lama.
- Rata tengah hanya di ponsel; `≥sm` kembali kiri-kanan.

### Tombol
- `.tombol-utama` / `.tombol-kedua` / `.tombol-ikon` di `app.css`. Jangan bikin gaya baru.
- Tombol berlabel + ikon harus `flex`, BUKAN `grid` — dua anak dalam grid tanpa kolom
  tersusun ke bawah. Tidak terlihat selama isinya cuma ikon.
- Satu tindakan utama per layar. Dua tombol berwarna penuh membuat keduanya berhenti berarti.

### Keadaan kosong & halaman
- `<x-kosong>` (punya slot `aksi` dan pilihan `ikon`).
- `->links()` selalu dirender; jumlah baris dari `config('nampan.per_halaman')`.
  Daftar di dalam panel pakai `pageName` sendiri dan reset ke halaman 1 saat panel dibuka.

### Membuktikan
`tests/browser/ukur-pratinjau.sh` — tujuh angka 0 di 390/768/1280, tanpa baris `TIDAK SAH`.
**Angka nol tidak cukup**: buka PNG-nya dan LIHAT. Keluhan "kurang rapi"/"banyak yang kosong"
tidak tersentuh ketujuh angka itu. Untuk teks terpotong pakai probe
`scrollWidth > clientWidth` dengan `overflow !== 'visible'` — mata melewatkannya.

## Bahasa layar: pakai kata orang warung, bukan kata akuntansi

Pemilik proyek meminta ini tegas untuk layar Stok & hitung stok, dan berlaku untuk layar
berikutnya juga. Yang dilihat pengguna memakai kata sehari-hari; nama kolom basis data,
properti Livewire, dan identifier Alpine TETAP memakai istilah domain — menggantinya berarti
migrasi dan menyentuh uji JS tanpa menambah satu pun kejelasan bagi orang yang tidak pernah
melihat nama itu.

| Jangan tampilkan | Tampilkan |
|---|---|
| Ambang, ambang minimum | Batas minimal |
| Saldo, saldo sekarang | Sisa, Sisa sekarang |
| Sistem (angka menurut aplikasi) | Tercatat |
| Fisik, jumlah fisik | Hasil hitung |
| Selisih | Beda |
| Alasan selisih | Kenapa beda |
| Opname | Hitung stok / hitung fisik |
| Kartu stok | Riwayat barang |
| Mutasi | Pergerakan |
| Opname terakhir | Terakhir dihitung |

Kalau ragu: bacakan kalimatnya seolah kepada pemilik warteg yang belum pernah memakai
aplikasi kasir. Kalau ia perlu bertanya "itu apa", gantilah.

## Menulis uji

- PHP: `tests/Feature/*Test.php`, pakai `MembuatDataUji` + `RefreshDatabase`.
- JS: `tests/js/*.test.mjs` dengan `node:test`. Alpine dipalsukan lewat
  `window.Alpine.data`; lihat `tests/js/kasir.test.mjs` sebagai contoh.
- Uji harus menjelaskan **kenapa** lewat komentar, bukan hanya apa. Setiap uji yang
  lahir dari cacat nyata menyebutkan cacatnya.

## Verifikasi tampilan: UKUR, JANGAN DIKIRA

Klaim "rapi" dan "responsif" tidak diterima tanpa angka. Alurnya:

```bash
npm run build                                            # WAJIB kalau ada JS baru — lihat bawah
php artisan serve --port=8000 &                          # asetnya dari sini
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu \
  --no-sandbox --remote-debugging-port=9333 --user-data-dir=/tmp/ukur about:blank &
PRATINJAU=1 php artisan test --filter=PratinjauTest      # tulis HTML ke storage/pratinjau
tests/browser/ukur-pratinjau.sh owner-stok owner-opname  # tanpa argumen = semuanya
```

**Urutannya bukan selera: `npm run build` DULU, baru hasilkan tangkapannya.** Tangkapan
memuat nama berkas bundel yang berhak-hash; membangun SESUDAH tangkapan dibuat membuat
nama itu basi dan halamannya 404 (itu ketahuan, ditandai `TIDAK SAH`). Yang lebih berbahaya
kebalikannya: bundel lama yang termuat MULUS tapi belum memuat modul Alpine yang baru
ditambahkan. `x-data` melempar, bagian yang digerakkan Alpine tidak pernah terbentuk, dan
ketujuh angka melaporkan NOL — bukan karena rapi, tapi karena tidak ada apa pun untuk
diukur. Itu sudah benar-benar terjadi pada popup foto bukti dan sempat saya laporkan
sebagai "bersih". Sejak itu `ukur.mjs` menandai kekecualian tak tertangkap sebagai
`TIDAK SAH` juga, jadi pintu itu tertutup — tapi urutannya tetap harus benar.

`ukur-pratinjau.sh` TIDAK menyalakan Chrome; nyalakan sendiri di porta 9333. Sesudah
putaran mutasi, tab Chrome bisa masih memegang bundel yang rusak, sehingga halaman
PERTAMA pada putaran berikutnya ikut tertandai — ulangi sekali sebelum menyimpulkan.

**Jangan menyusun langkah itu sendiri, pakai skripnya.** `@vite` dan Livewire menulis URL
absolut dari APP_URL (`http://localhost`, tanpa porta), jadi tangkapannya harus ditulis
ulang ke porta server DAN disajikan dari origin yang sama — kalau tidak, skripnya
diblokir (porta salah, atau CORS kalau dibuka lewat `file://`), Alpine tidak jalan, dan
seluruh `x-show` terpotret salah.

Yang membuat ini berbahaya: halaman yang JS-nya mati **tetap melaporkan tujuh angka nol
dan lolos sebagai BERSIH**. Salah-lulus, bukan salah-gagal. Karena itu `ukur.mjs` sekarang
mencatat berkas yang gagal dimuat, menandai barisnya `TIDAK SAH — ` di DEPAN, dan keluar
dengan kode 2. Kalau tanda itu muncul, angkanya jangan dipakai — betulkan penyiapannya
dulu, jangan mulai memperbaiki tata letak yang sebenarnya tidak apa-apa.

Yang harus diukur, bukan dilihat: `scrollHeight > clientHeight` (menggulir?),
`documentElement.scrollWidth > innerWidth` (gulir mendatar?), ukuran & posisi tombol
(seragam? sejajar?), dan perpotongan kotak antar-elemen (ada teks tertimpa?).

Bersihkan sesudahnya: `rm -rf public/pratinjau storage/pratinjau storage/app/public/pratinjau`.

## Kecepatan: jangan berlama-lama di satu pekerjaan

Pemilik proyek meminta ini tegas — masih banyak fitur yang belum ada, dan kualitas satu
layar tidak menolong kalau sepuluh layar lain kosong.

- **Satu putaran QA per fitur.** Cacat yang tersisa dicatat di `docs/RENCANA.md` lalu
  LANJUT. Jangan menyelesaikan semuanya sekarang.
- Perbaiki cacat yang ditemukan; jangan melebar jadi penyempurnaan.
- Agen kena batas sesi? **Kerjakan sendiri, jangan menunggu reset** — dan sebutkan di
  laporan bahwa rantainya dipendekkan.
- Uji mutasi hanya untuk penjaga yang menyentuh uang/stok, bukan setiap uji.
- Jalankan seluruh berkas uji di AKHIR, bukan berkali-kali di tengah.

Yang TIDAK boleh dipotong, karena masing-masing sudah pernah merugikan: env uji eksplisit,
jangan hapus `storage/app/public/produk/`, uang divalidasi ketat, dan klaim "rapi" tetap
harus diukur.

## WAJIB: setiap permintaan lewat tim agen — tanpa pengecualian

Pemilik proyek meminta ini secara tegas, dan berlaku untuk **apa pun**: cacat, perbaikan
besar, perbaikan sepele, fitur baru, perubahan tampilan, pertanyaan desain.

```
Permintaan pemilik proyek
      ▼
LEAD      pecah pekerjaannya, tentukan urutan, pegang berkas bersama
      ▼
ANALIS    spesifikasi: yang sudah ada, kriteria terima, keadaan tepi, yang TIDAK dibangun
      ▼
BACKEND   model, migrasi, Action, komponen Livewire, uji PHPUnit
FRONTEND  Blade, Tailwind, Alpine, JS + BUKTI kerapian berupa angka
      ▼
QA        cari cacat & buktikan; sesudah diperbaiki, uji ULANG dengan langkah yang sama
      ▼
LEAD      tandai di docs/RENCANA.md + kabari Telegram
```

Yang TIDAK boleh: mengerjakan permintaan langsung tanpa melewati rantai ini, sekecil apa
pun perubahannya. Alasannya bukan formalitas — sesi ini sudah membuktikan tiga kali bahwa
pekerjaan yang dikerjakan langsung menyisakan cacat yang baru ketemu belakangan: nominal
bayar terpisah yang ditimpa sistem, sepuluh kegagalan uji palsu di salinan bersih, dan
tombol yang menimpa teks di kolom sempit. Ketiganya lolos dari mata dan dari PHPUnit;
ketiganya tertangkap oleh peran yang berbeda.

Untuk perubahan sepele (mis. satu kalimat teks UI), rantainya boleh dipendekkan menjadi
FRONTEND → QA — tapi **harus dikatakan** bahwa itu yang dilakukan, beserta alasannya.
Jangan melewatinya diam-diam.

## Alur perbaikan cacat (QA → lead → BE/FE/analis → QA)

Ini alur kerja tim agen di repo ini. Satu putaran, berulang sampai QA menyatakan hijau.

```
QA temukan cacat
      │  laporan berformat (lihat di bawah)
      ▼
LEAD triase  ──► kabar Telegram: "N cacat baru, ditugaskan ke …"
      │
      ├──► BACKEND   (uang, stok, kas, tenant, sinkronisasi)
      ├──► FRONTEND  (tata letak, Alpine, kerapian, responsif)
      └──► ANALIS    (kalau cacatnya sebenarnya salah paham kebutuhan)
      │
      ▼  selesai dikerjakan
LEAD  ──► kabar Telegram: "sudah dikerjakan, dikembalikan ke QA"
      ▼
QA uji ULANG dengan langkah yang sama
      │
      ├─ masih gagal ──► kembali ke LEAD (putaran berikutnya)
      └─ hijau ────────► LEAD tandai selesai di docs/RENCANA.md
                         └─► kabar Telegram + laporan lengkap
```

**Yang benar-benar otomatis** adalah pelaporannya dan urutan langkahnya. Yang TIDAK
otomatis: agen tidak bisa membangunkan dirinya sendiri — putarannya berjalan selama ada
sesi Claude Code yang menjalankannya. Jangan menjanjikan "berjalan sendiri 24 jam" ke
pemilik proyek.

### Format laporan QA (wajib, supaya lead bisa menugaskan tanpa membaca ulang semuanya)

```
[BERAT|SEDANG|RINGAN] berkas:baris — satu kalimat cacatnya
  Munculkan: langkah atau nama uji yang benar-benar dijalankan
  Terjadi:   apa yang terjadi sekarang
  Seharusnya: apa yang benar
  Rugi:      uang salah? data bocor? cuma kurang rapi?
  Untuk:     backend | frontend | analis
```

Urutan berat: uang & data bocor > data hilang > alur macet > tampilan. QA menambahkan
uji yang **gagal** di `tests/`, dan tidak menyentuh berkas aplikasi.

### Kabar Telegram di setiap tahap

```bash
php artisan lapor:telegram --kirim --pesan="QA: 3 cacat di layar kasir (2 BERAT). Ditugaskan: BE 2, FE 1."
php artisan lapor:telegram --kirim --pesan="BE selesai: bayar terpisah tidak lagi menimpa nominal kasir. Kembali ke QA."
php artisan lapor:telegram --kirim --pesan="QA uji ulang: 11/11 hijau. Ditutup."
php artisan lapor:telegram --kirim          # laporan lengkap di akhir sesi
```

Kabar singkat tidak menjalankan suite (cepat); laporan lengkap menjalankannya.

### Kerapian yang WAJIB diperiksa frontend sebelum melapor selesai

Bukan pendapat — dijalankan dan angkanya dicantumkan:

```bash
# Untuk tangkapan pratinjau, PAKAI SKRIPNYA — ia menulis ulang URL aset & menyajikan
# dari origin yang sama. Menyusun langkahnya sendiri membuat skrip halaman diblokir,
# Alpine mati, dan hasilnya lolos sebagai BERSIH padahal tidak terukur.
tests/browser/ukur-pratinjau.sh [nama-tangkapan ...]

# Untuk URL hidup biasa (server jalan), langsung saja:
node tests/browser/ukur.mjs <url> 390  /tmp/a.png tests/browser/periksa-rapi.js 844
node tests/browser/ukur.mjs <url> 768  /tmp/b.png tests/browser/periksa-rapi.js 900
node tests/browser/ukur.mjs <url> 1280 /tmp/c.png tests/browser/periksa-rapi.js 720

# Baris hasil yang berawalan "TIDAK SAH — " berarti alat ukurnya yang rusak, bukan
# tampilannya. Betulkan penyiapannya; jangan perbaiki tata letak berdasarkan angka itu.
```

Tujuh angka harus **0**, dan semuanya pernah lolos dari mata di proyek ini:

| Angka | Artinya kalau bukan 0 |
|---|---|
| `gulirMendatar` | halaman melebar; hampir selalu `min-width:auto` pada anak flex/grid → `min-w-0` |
| `panelMenggulir` | dialog lebih tinggi daripada layar; tombol simpan tak terjangkau |
| `tumpangTindih` | ada teks/tombol yang tertimpa |
| `cloakTersisa` | elemen `x-cloak` tersembunyi selamanya (Alpine tidak jalan) |
| `tombolTakSeragam` | tombol sebaris beda tinggi atau tidak sejajar |
| `wadahKosong` | ADA KOLOM/KOTAK KOSONG — lubang di tata letak |
| `selKosong` | sel tabel kosong; harus selalu ada penanda `—` |

Satu pengecualian yang diakui: **lembar-bawah di lebar ponsel (≤640px) boleh
menggulir** — sembilan medan tidak muat di layar 844px berapa pun kolomnya. Di ≥768px
dialog TIDAK boleh menggulir.

Estetika yang tidak terukur (irama jarak, keseimbangan kolom, hierarki) tetap dinilai
mata — tapi hanya SETELAH tujuh angka di atas nol. Rapi yang tidak terukur biasanya
berarti belum diperiksa.

## Melapor

Sebutkan hasil apa adanya: perintah yang dijalankan, angka yang keluar, dan apa yang
BELUM dikerjakan. Jangan menyatakan selesai tanpa `pint` + `php artisan test` +
`npm run uji:js` hijau.

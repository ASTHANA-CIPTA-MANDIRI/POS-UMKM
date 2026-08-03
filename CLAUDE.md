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

## Menulis uji

- PHP: `tests/Feature/*Test.php`, pakai `MembuatDataUji` + `RefreshDatabase`.
- JS: `tests/js/*.test.mjs` dengan `node:test`. Alpine dipalsukan lewat
  `window.Alpine.data`; lihat `tests/js/kasir.test.mjs` sebagai contoh.
- Uji harus menjelaskan **kenapa** lewat komentar, bukan hanya apa. Setiap uji yang
  lahir dari cacat nyata menyebutkan cacatnya.

## Verifikasi tampilan: UKUR, JANGAN DIKIRA

Klaim "rapi" dan "responsif" tidak diterima tanpa angka. Alurnya:

```bash
PRATINJAU=1 php artisan test --filter=PratinjauTest     # tulis HTML ke storage/pratinjau
rm -rf public/pratinjau && mkdir -p public/pratinjau
cp storage/pratinjau/*.html public/pratinjau/
sed -i '' -e 's|http://localhost/|/|g' -e 's|http:\\/\\/localhost\\/|\\/|g' public/pratinjau/*.html
php artisan serve --port=8123 &
node tests/browser/ukur.mjs <url> <lebar> <keluaran.png> [ukur.js] [tinggi]
```

`sed` itu wajib: `@vite` dan Livewire menulis URL absolut dari APP_URL, dan tanpa
penulisan ulang halaman pratinjau memuat aset dari host yang tidak melayani apa pun —
Alpine tidak jalan dan seluruh `x-show` terpotret salah.

Yang harus diukur, bukan dilihat: `scrollHeight > clientHeight` (menggulir?),
`documentElement.scrollWidth > innerWidth` (gulir mendatar?), ukuran & posisi tombol
(seragam? sejajar?), dan perpotongan kotak antar-elemen (ada teks tertimpa?).

Bersihkan sesudahnya: `rm -rf public/pratinjau storage/pratinjau storage/app/public/pratinjau`.

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
node tests/browser/ukur.mjs <url> 390  /tmp/a.png tests/browser/periksa-rapi.js 844
node tests/browser/ukur.mjs <url> 768  /tmp/b.png tests/browser/periksa-rapi.js 900
node tests/browser/ukur.mjs <url> 1280 /tmp/c.png tests/browser/periksa-rapi.js 720
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

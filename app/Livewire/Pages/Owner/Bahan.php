<?php

namespace App\Livewire\Pages\Owner;

use App\Enums\DocumentStatus;
use App\Enums\Satuan;
use App\Livewire\Concerns\MengirimToast;
use App\Livewire\Concerns\TerikatTenant;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\RawMaterial;
use App\Support\Uang;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kelola bahan baku: daftar, tambah, ubah, hapus.
 *
 * KENAPA layar ini ada sama sekali. Mesinnya sudah lengkap dan sudah benar sejak lama:
 * `recipe_items` menyimpan resep, `ApplySaleToStockAction` memotong bahan per porsi
 * terjual, `SusunBarisStokAction::barisBahan()` menampilkan bahan beserta satuannya di
 * layar Stok, dan nota belanja bisa membeli bahan. Yang TIDAK ADA adalah pintu untuk
 * membuat bahannya — sampai hari ini satu-satunya jalan adalah seeder. Jadi pemilik
 * warteg yang menjual "lele goreng" per porsi tapi membeli lele per kilogram tidak punya
 * cara memasukkan "Lele Segar" ke aplikasinya sendiri.
 *
 * Yang SENGAJA TIDAK ada di layar ini, dan masing-masing alasannya ditulis juga di Blade
 * supaya pemilik tahu ke mana harus mencari — bukan hanya di komentar kode:
 *
 * - BATAS MINIMAL. Kolomnya di `stocks`, dan angkanya berbeda per cabang: cabang yang
 *   ramai butuh stok pengaman lebih banyak. Menaruhnya di master bahan berarti satu angka
 *   untuk semua cabang, dan itu jawaban yang salah untuk pertanyaan yang benar.
 * - STOK AWAL. Memasukkan jumlah dari formulir master berarti jalur KEDUA yang mengubah
 *   saldo tanpa mutasi — dan kartu stok adalah satu-satunya bukti yang tersisa kalau nanti
 *   ada selisih yang harus dijelaskan. Sisa bahan diisi lewat Hitung stok atau nota belanja.
 * - SAKLAR AKTIF/NONAKTIF. `SusunBarisStokAction::barisBahan()` dan `SusunSisaStokAction`
 *   TIDAK menyaring `is_active` bahan (sengaja: resep yang masih menunjuknya tetap butuh
 *   bahannya), jadi saklar itu tidak mengubah apa pun yang kelihatan. Kontrol yang tidak
 *   berakibat mengajarkan orang bahwa saklar di aplikasi ini tidak berarti. Kolomnya
 *   dibiarkan di nilai bawaannya, true.
 */
#[Layout('layouts.aplikasi')]
class Bahan extends Component
{
    use MengirimToast, TerikatTenant, WithPagination;

    #[Url(as: 'cari')]
    public string $cari = '';

    /* ── Formulir ────────────────────────────────────────────────────────── */

    public bool $panel = false;

    /**
     * Baris mana yang sedang diubah — #[Locked] karena ia penentu TUJUAN penyimpanan.
     *
     * Diisi hanya oleh ubah()/tambah() di server. Tanpa #[Locked], muatan Livewire bisa
     * mengirim id apa pun: `TenantScope` memang tetap menahan tulisannya (findOrFail() di
     * simpan() melempar 404 untuk bahan tenant lain), tapi ia menahannya di langkah TERAKHIR
     * dan hanya selama scope-nya aktif — jalur tanpa tenant di TenantContext (perintah
     * artisan, job) tidak difilter sama sekali. Yang lebih dekat: id milik tenant SENDIRI
     * yang ditukar diam-diam membuat formulir "Gula Pasir" menimpa baris "Garam" tanpa satu
     * pun galat, karena semua pemeriksaannya lolos — barisnya memang ada dan memang miliknya.
     * Pola yang sama dengan Opname::$outletId dan PembelianBaru::$outletId.
     */
    #[Locked]
    public ?string $bahanId = null;

    public string $nama = '';

    public string $satuan = 'kg';

    /**
     * Harga beli terakhir, sebagai TEKS apa adanya yang diketik orang.
     *
     * Bukan `?float`, dan itu keputusan yang sudah pernah merugikan: `(float) '58.000'`
     * bernilai 58.0, jadi harga Rp 58.000 tersimpan sebagai Rp 58 tanpa satu pun galat.
     * Teksnya dibiarkan utuh sampai App\Support\Uang membacanya — di situlah titik ribuan
     * dibedakan dari titik desimal, dan bentuk yang ambigu DITOLAK alih-alih ditebak.
     */
    public string $hargaBeli = '';

    public function updated(string $properti): void
    {
        // Mengubah pencarian harus mengembalikan ke halaman pertama; kalau tidak, orang
        // melihat daftar kosong hanya karena masih berada di halaman 3.
        if ($properti === 'cari') {
            $this->resetPage();
        }
    }

    public function tambah(): void
    {
        $this->reset(['bahanId', 'nama', 'hargaBeli']);
        $this->satuan = 'kg';
        $this->panel = true;
        $this->resetValidation();
    }

    public function ubah(string $id): void
    {
        $bahan = RawMaterial::findOrFail($id);

        $this->bahanId = $bahan->getKey();
        $this->nama = $bahan->nama;
        $this->satuan = $bahan->satuan?->value ?? 'kg';
        // Rupiah yang diketik orang tidak punya sen, jadi yang ditampilkan kembali juga
        // tidak: kolom decimal(15,2) mengembalikan "58000.00", dan bentuk itu ditolak
        // Uang::baca() kalau disimpan ulang tanpa disentuh — penolakan yang tidak bisa
        // dijelaskan siapa pun.
        $this->hargaBeli = $bahan->harga_beli_terakhir !== null
            ? (string) (int) round((float) $bahan->harga_beli_terakhir)
            : '';
        $this->panel = true;
        $this->resetValidation();
    }

    public function tutupPanel(): void
    {
        $this->panel = false;
        $this->resetValidation();
    }

    public function simpan(): void
    {
        $data = $this->validate([
            /*
             * Unik PER TENANT, mengabaikan yang terhapus dan dirinya sendiri.
             *
             * Yang terhapus diabaikan karena bahan memakai soft delete: nama "Lele Segar"
             * yang pernah dihapus tetap tercatat di riwayat belanja lama, dan melarang
             * nama itu dipakai lagi berarti pemilik yang salah hapus tidak punya jalan
             * kembali selain mengarang nama baru ("Lele Segar 2") yang lalu ikut muncul di
             * seluruh laporan.
             */
            'nama' => [
                'required',
                'string',
                'max:255',
                Rule::unique('raw_materials', 'nama')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->whereNull('deleted_at')
                    ->ignore($this->bahanId),
            ],
            'satuan' => ['required', Rule::enum(Satuan::class), $this->aturanSatuanTerkunci()],
            'hargaBeli' => ['nullable', $this->aturanUang()],
        ], attributes: [
            'nama' => 'nama bahan',
            'satuan' => 'satuan',
            'hargaBeli' => 'harga beli terakhir',
        ]);

        $muatan = [
            'nama' => $data['nama'],
            'satuan' => $data['satuan'],
            'harga_beli_terakhir' => Uang::baca($data['hargaBeli'] ?? null),
        ];

        if ($this->bahanId !== null) {
            RawMaterial::findOrFail($this->bahanId)->update($muatan);
            $this->toast('Bahan "'.$data['nama'].'" diperbarui.');
        } else {
            /*
             * TIDAK ada baris `stocks` yang dibuat di sini, dan itu bukan kelalaian.
             *
             * Baris `stocks` bersaldo 0 berstatus 'habis' (Stock::statusStok()), sementara
             * bahan yang belum pernah dicatat berstatus 'belum_dihitung'
             * (SusunBarisStokAction::baris(), lewat `stok_id === null`). Bedanya menentukan
             * apa yang dilihat pemilik di hari pertama: warteg dengan 30 bahan akan melihat
             * 30 lencana MERAH "Habis" untuk barang yang raknya penuh. Hari kedua ia belajar
             * mengabaikan merah, dan hari bahan pertama benar-benar kosong, merah sudah
             * tidak berarti apa-apa lagi.
             *
             * Barisnya dibuat nanti oleh SiapkanBarisStokAction — saat bahannya benar-benar
             * dihitung, dibeli, atau terpakai penjualan.
             */
            RawMaterial::create($muatan);
            $this->toast('Bahan "'.$data['nama'].'" ditambahkan. Sisanya diisi lewat Hitung stok atau nota belanja.');
        }

        $this->panel = false;
    }

    /**
     * Satuan TIDAK BOLEH diubah kalau bahannya sudah punya catatan stok, sudah dipakai resep,
     * atau masih ditunggu di nota belanja yang barangnya belum datang.
     *
     * Ini cacat 1000× lewat pintu lain, dan pintunya tidak berbunyi sama sekali: angka lama
     * TIDAK dikonversi. Mengubah "Lele Segar" dari kg ke gram membuat 60 (kg) di kartu stok
     * terbaca 60 gram — enam puluh gram lele untuk warung yang menyimpan enam puluh kilo —
     * dan resep 0,25 kg per porsi menjadi 0,25 gram. Tidak ada galat, tidak ada uang yang
     * salah hari itu; yang salah adalah setiap keputusan belanja sesudahnya.
     *
     * Syarat KETIGA (nota belum datang) ditambahkan sesudah QA membuktikan lubangnya, dan
     * lubang itu lahir dari keputusan yang benar di tempat lain: nota yang belum ditandai
     * datang SENGAJA tidak membuat baris `stocks` (TerimaPembelianAction adalah satu-satunya
     * jalur pembelian menaikkan stok). Jadi bahan yang baru dibelanjakan 5 kg — belum punya
     * `stocks`, belum dipakai resep — dulu LOLOS kedua syarat pertama. Pemilik boleh
     * menggantinya ke gram, lalu notanya ditandai datang: `purchase_order_items.qty` bahan
     * baku tidak pernah dikonversi (CatatPembelianAction: "angkanya memang sudah dalam
     * satuan pencatatannya sendiri"), jadi angka 5 yang berarti 5 KILOGRAM masuk apa adanya
     * dan dibaca 5 GRAM di seluruh layar Stok dan riwayat barang.
     *
     * Jalan keluarnya disebutkan di pesannya: buat bahan BARU. Bahan lama tetap memegang
     * riwayatnya dalam satuan yang benar.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanSatuanTerkunci(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($this->bahanId === null) {
                return;
            }

            $bahan = RawMaterial::find($this->bahanId);

            if ($bahan === null || $bahan->satuan?->value === (string) $nilai) {
                return;
            }

            if ($bahan->stocks()->exists()) {
                $gagal('Satuan tidak bisa diubah karena '.$bahan->nama.' sudah punya catatan stok. '
                    .'Buat bahan baru kalau satuannya mau beda.');

                return;
            }

            if ($bahan->recipeItems()->exists()) {
                $gagal('Satuan tidak bisa diubah karena '.$bahan->nama.' sudah dipakai resep. '
                    .'Buat bahan baru kalau satuannya mau beda.');

                return;
            }

            $notaMenunggu = $this->notaBelumDatang($bahan);

            if ($notaMenunggu !== []) {
                $gagal('Satuan tidak bisa diubah karena '.$bahan->nama.' ada di nota belanja yang '
                    .'barangnya belum datang ('.implode(', ', $notaMenunggu).'). '
                    .'Buat bahan baru kalau satuannya mau beda.');
            }
        };
    }

    /**
     * Nomor nota belanja yang masih menunggu bahan ini datang.
     *
     * PENANDANYA STATUS NOTA, BUKAN `qty_diterima`, dan bedanya menentukan apakah gerbangnya
     * benar atau cuma galak. `qty_diterima` baris nota tetap 0 selamanya untuk nota yang
     * DIBATALKAN sebelum sempat datang — BatalkanPembelianAction tidak pernah menyentuhnya —
     * jadi gerbang yang membaca `qty_diterima = 0` akan mengunci satuan bahan itu untuk
     * selama-lamanya, karena tidak ada satu pun layar yang bisa membuat angka itu naik lagi.
     * Nota batal justru tidak menahan apa pun: ia dinyatakan tidak pernah terjadi, tidak
     * akan pernah menambah stok, dan angkanya tidak akan pernah dibaca dengan satuan baru.
     *
     * Dua status yang menahan (Draft dan Dikirim) adalah TEPAT dua status yang masih bisa
     * dilewati TerimaPembelianAction::execute() — aksi itu berhenti di Diterima dan
     * Dibatalkan. Disamakan dengan sengaja: yang berbahaya bukan "nota yang belum diterima",
     * melainkan "nota yang MASIH BISA diterima", karena penerimaanlah yang memasukkan angka
     * mentahnya ke kartu stok dengan satuan bahan yang berlaku SAAT ITU.
     *
     * Nomornya disebut SEMUA, bukan yang pertama saja: pemilik harus menyelesaikan
     * (menerima atau membatalkan) semuanya sebelum satuannya terbuka, dan pesan yang cuma
     * menyebut satu membuatnya membetulkan satu lalu tertahan lagi tanpa tahu berapa sisanya.
     *
     * @return array<int, string>
     */
    private function notaBelumDatang(RawMaterial $bahan): array
    {
        return PurchaseOrder::query()
            ->whereIn('status', [DocumentStatus::Draft->value, DocumentStatus::Dikirim->value])
            ->whereHas('items', fn ($q) => $q->where('raw_material_id', $bahan->getKey()))
            ->orderBy('tanggal')
            ->orderBy('nomor_po')
            ->pluck('nomor_po')
            ->all();
    }

    /**
     * Harga beli dibaca App\Support\Uang, bukan aturan `numeric`.
     *
     * `numeric|min:0` MELOLOSKAN "58.000" — `is_numeric()` menyatakannya sah dan `(float)`
     * membacanya 58. Itu cacat yang sudah benar-benar terjadi di kolom harga beli nota
     * belanja (komit 96d4844): nota Rp 116.000 tercatat Rp 115. Kolom ini menulis ke
     * `raw_materials.harga_beli_terakhir`, angka yang dipakai layar Stok untuk menghitung
     * nilai persediaan — salah seribu kali di situ tidak terlihat sampai pemilik menghitung
     * margin berbulan kemudian.
     *
     * @return Closure(string, mixed, Closure): void
     */
    private function aturanUang(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if ($nilai === null || $nilai === '') {
                return;
            }

            if (! Uang::sah($nilai)) {
                $gagal('Harga beli terakhir tidak terbaca. Tulis angkanya saja, mis. 58.000 — '
                    .'tanpa sen dan tanpa huruf.');
            }
        };
    }

    /**
     * Menghapus bahan — dengan GERBANG SERVER, bukan hanya dialog.
     *
     * `restrictOnDelete` pada `recipe_items.raw_material_id` TIDAK menolong di sini, dan
     * ini cacat sungguhan yang sedang menunggu: RawMaterial memakai SoftDeletes, jadi yang
     * terjadi hanya `deleted_at` terisi — kunci asingnya tidak pernah tersentuh dan
     * penolakan basis datanya tidak pernah terjadi.
     *
     * Akibat kalau bahan yang masih dipakai resep lolos terhapus, dan semuanya SENYAP:
     *
     *   1. `ApplySaleToStockAction` tetap memotong bahan itu tiap porsi terjual — ia
     *      membaca `recipeItems`, yang tidak tahu apa-apa tentang `deleted_at` bahan.
     *   2. `SiapkanBarisStokAction` MEMBUAT baris `stocks`-nya tanpa memeriksa apa pun.
     *   3. `SusunBarisStokAction` menyembunyikan barisnya lewat SoftDeletingScope, jadi
     *      barisnya tidak muncul di layar Stok maupun lembar Hitung stok.
     *   4. `SusunSisaStokAction` lalu membaca statusnya `belum_dihitung`, sehingga menunya
     *      tampak SEHAT SELAMANYA di kasir.
     *
     * Hasilnya: stok berjalan minus di baris yang tidak muncul di layar mana pun, dan tidak
     * ada satu pun tanda di aplikasi yang bisa dipakai orang untuk menemukannya.
     *
     * Dialog SweetAlert di Blade BUKAN pengamannya. Muatan Livewire bisa dikirim tanpa
     * pernah melewati dialog apa pun, jadi gerbangnya ada di sini — dan ujinya memanggil
     * metode ini langsung, tanpa dialog.
     */
    public function hapus(string $id): void
    {
        $bahan = RawMaterial::findOrFail($id);
        $nama = $bahan->nama;
        $menu = $this->menuPemakai($bahan);

        if ($menu !== []) {
            $this->toast(
                $nama.' masih dipakai resep: '.implode(', ', $menu).'. Keluarkan dulu dari resepnya.',
                'galat',
            );

            return;
        }

        $bahan->delete();

        $this->toast('Bahan "'.$nama.'" dihapus. Riwayat belanja dan hitungan lama tetap tersimpan.');
    }

    /**
     * Nama menu yang resepnya masih memakai bahan ini.
     *
     * Menu yang sudah dihapus (soft delete) TIDAK ikut menahan, dan itu keputusan sadar:
     * menu terhapus tidak muncul di kasir, jadi ia tidak bisa terjual, jadi tidak ada
     * pemotongan bahan yang bisa terjadi lewat baris resepnya. Kalau ia ikut menahan,
     * pesannya akan menyebut nama menu yang tidak ada di layar mana pun — dan pemiliknya
     * tidak punya cara mengeluarkannya dari resep yang tidak bisa dibuka.
     *
     * Konsekuensi yang harus diketahui kalau nanti ada layar pemulihan menu: memulihkan
     * menu yang resepnya menunjuk bahan terhapus menghidupkan kembali cacat senyap di
     * hapus(). Gerbangnya harus ikut dipasang di sana, bukan di sini.
     *
     * @return array<int, string>
     */
    private function menuPemakai(RawMaterial $bahan): array
    {
        return $bahan->products()
            ->orderBy('nama_produk')
            ->pluck('nama_produk')
            ->unique()
            ->values()
            ->all();
    }

    /** @return Builder<RawMaterial> */
    private function kueriBahan(): Builder
    {
        return RawMaterial::query()
            /*
             * Nama menu pemakainya diambil sekaligus (eager load), bukan per baris.
             *
             * Kolom "Dipakai di" menyebut NAMA menunya di baris kedua, bukan cuma
             * angkanya: "dipakai di 2 menu" tidak menjawab pertanyaan yang sebenarnya
             * dibawa orang ke kolom itu, yaitu "kalau saya hapus ini, apa yang rusak".
             */
            ->with(['products' => fn ($q) => $q->select('products.id', 'products.nama_produk')
                ->orderBy('products.nama_produk')])
            ->when($this->cari !== '', fn ($q) => $q->where('nama', 'like', '%'.$this->cari.'%'));
    }

    public function render()
    {
        return view('livewire.pages.owner.bahan', [
            'daftar' => $this->kueriBahan()
                ->orderBy('nama')
                ->paginate(config('nampan.per_halaman')),
            'satuanTersedia' => Satuan::cases(),
            'jumlahBahan' => RawMaterial::count(),
            /*
             * Jumlah menu berbasis resep, untuk kalimat pengantar. Nol berarti resepnya
             * belum diisi satu pun — dan tanpa resep, tidak ada satu gram bahan pun yang
             * pernah berkurang saat menu terjual. Pemilik berhak tahu itu SEBELUM ia
             * menyimpulkan aplikasinya tidak menghitung.
             */
            'jumlahMenuBerresep' => Product::query()->whereHas('recipeItems')->count(),
        ]);
    }
}

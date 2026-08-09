<?php

namespace App\Actions\Produk;

use App\Enums\Satuan;
use App\Models\Produk\Category;
use App\Models\Produk\Product;
use App\Support\BacaCsv;
use App\Support\Uang;
use Illuminate\Support\Facades\DB;

/**
 * Impor produk dari CSV: PERIKSA dulu, simpan belakangan.
 *
 * KENAPA DUA LANGKAH, dan kenapa ini bukan kerapian. Mengimpor 300 baris yang salah jauh
 * lebih buruk daripada menolak berkasnya: produk yang telanjur masuk harus dihapus satu per
 * satu, sebagian sudah dipakai transaksi sehingga tidak bisa dihapus lagi, dan harga yang
 * salah sudah dipakai kasir hari itu. `periksa()` mengembalikan apa yang AKAN terjadi tanpa
 * menyentuh basis data; `simpan()` mengerjakan hasil pemeriksaan yang sama persis.
 *
 * KOLOM MANA YANG WAJIB. Hanya `nama` dan `harga`. Sisanya boleh tidak ada sama sekali —
 * pemilik warung yang baru mulai punya daftar barang di Excel berisi dua kolom itu saja, dan
 * menuntut tujuh kolom membuat impor kalah cepat daripada mengetik manual. Kolom yang tidak
 * ada dibiarkan pada nilai bawaan modelnya, bukan diisi tebakan.
 *
 * NAMA KOLOM BOLEH BERBEDA-BEDA, dan padanannya ada DI SINI, bukan di App\Support\BacaCsv.
 * Pembaca CSV sengaja tidak tahu arti kolom apa pun supaya ia bisa dipakai ulang untuk impor
 * pelanggan dan bahan baku nanti.
 *
 * YANG SENGAJA TIDAK DILAKUKAN:
 *  - TIDAK mengubah produk yang sudah ada, kecuali SKU-nya sama persis. Mencocokkan dengan
 *    NAMA terasa pintar dan berbahaya: "Teh Manis" dan "Teh manis" adalah dua barang bagi
 *    orang yang mengetiknya, dan menimpakan harga baru ke barang yang salah tidak bersuara.
 *  - TIDAK membuat stok awal. Sama seperti layar Bahan: jalur kedua yang mengubah saldo
 *    tanpa mutasi membuat kartu stok berhenti jadi bukti.
 *  - TIDAK menolak seluruh berkas karena satu baris cacat. Baris yang sah tetap masuk, yang
 *    cacat dilaporkan berikut nomor barisnya. Berkas 300 baris yang ditolak seluruhnya
 *    karena satu salah ketik membuat orang menyerah pada impor.
 */
class ImporProdukAction
{
    /**
     * Padanan nama kolom → medan aplikasi.
     *
     * Ditulis dalam bentuk yang sudah dibakukan BacaCsv::bakukanJudul() (huruf kecil, spasi
     * jadi garis bawah, tanda baca dibuang), jadi "Harga Jual (Rp)" sudah menjadi
     * `harga_jual_rp` sebelum sampai ke sini.
     *
     * @var array<string, array<int, string>>
     */
    private const PADANAN = [
        'nama' => ['nama', 'nama_produk', 'nama_barang', 'produk', 'barang', 'item'],
        'harga' => ['harga', 'harga_jual', 'harga_rp', 'harga_jual_rp', 'harga_satuan', 'price'],
        'harga_beli' => ['harga_beli', 'harga_modal', 'modal', 'harga_beli_rp', 'hpp'],
        'sku' => ['sku', 'kode', 'kode_barang', 'kode_produk'],
        'barcode' => ['barcode', 'kode_batang', 'ean', 'upc'],
        'satuan' => ['satuan', 'unit', 'satuan_jual'],
        'kategori' => ['kategori', 'category', 'golongan', 'jenis'],
    ];

    /**
     * Memeriksa berkas tanpa menyentuh basis data.
     *
     * @return array{
     *     kolomTakDikenal: array<int, string>,
     *     kolomHilang: array<int, string>,
     *     terpotong: bool,
     *     siap: array<int, array{nomor: int, muatan: array<string, mixed>, kategori: ?string, menimpa: ?string}>,
     *     ditolak: array<int, array{nomor: int, nama: string, sebab: string}>,
     * }
     */
    public function periksa(string $isiCsv): array
    {
        $terbaca = BacaCsv::baca($isiCsv);
        $peta = $this->petakanKolom($terbaca['judul']);

        $hasil = [
            'kolomTakDikenal' => array_values(array_diff($terbaca['judul'], array_values($peta))),
            'kolomHilang' => array_values(array_diff(['nama', 'harga'], array_keys($peta))),
            'terpotong' => $terbaca['terpotong'],
            'siap' => [],
            'ditolak' => [],
        ];

        if ($hasil['kolomHilang'] !== []) {
            return $hasil;
        }

        /*
         * SKU yang sudah ada dibaca SEKALI, bukan satu kueri per baris.
         *
         * Bukan semata kecepatan: berkas 2.000 baris berarti 2.000 kueri, dan impor yang
         * memakan satu menit akan ditinggal pemiliknya — lalu ia menekan tombolnya lagi.
         */
        $skuAda = Product::query()->whereNotNull('sku')->pluck('id', 'sku');
        $barcodeAda = Product::query()->whereNotNull('barcode')->pluck('id', 'barcode');

        // Kembar DI DALAM berkas itu sendiri, yang tidak akan pernah tertangkap oleh
        // pemeriksaan terhadap basis data: dua baris ber-SKU sama akan lolos berdua, dan
        // yang kedua menimpa yang pertama tanpa satu pun tanda.
        $skuDiBerkas = [];
        $barcodeDiBerkas = [];

        foreach ($terbaca['baris'] as $baris) {
            $ambil = fn (string $medan) => isset($peta[$medan])
                ? trim((string) ($baris['isi'][$peta[$medan]] ?? ''))
                : '';

            $nama = $ambil('nama');
            $tolak = function (string $sebab) use (&$hasil, $baris, $nama): void {
                $hasil['ditolak'][] = ['nomor' => $baris['nomor'], 'nama' => $nama, 'sebab' => $sebab];
            };

            if ($nama === '') {
                $tolak('Nama barangnya kosong.');

                continue;
            }

            if (mb_strlen($nama) > 255) {
                $tolak('Nama barangnya kepanjangan (lebih dari 255 huruf).');

                continue;
            }

            $harga = $ambil('harga');

            if ($harga === '' || ! Uang::sah($harga)) {
                $tolak('Harganya tidak terbaca ("'.$harga.'"). Tulis angkanya saja, mis. 15.000.');

                continue;
            }

            $hargaBeli = $ambil('harga_beli');

            if ($hargaBeli !== '' && ! Uang::sah($hargaBeli)) {
                $tolak('Harga belinya tidak terbaca ("'.$hargaBeli.'").');

                continue;
            }

            $satuan = $this->bacaSatuan($ambil('satuan'));

            if ($satuan === null) {
                $tolak('Satuan "'.$ambil('satuan').'" tidak dikenal. Yang bisa dipakai: '
                    .implode(', ', array_map(fn (Satuan $s) => $s->value, Satuan::cases())).'.');

                continue;
            }

            $sku = $ambil('sku');
            $barcode = $ambil('barcode');

            if ($sku !== '' && isset($skuDiBerkas[$sku])) {
                $tolak('Kode "'.$sku.'" dipakai dua kali di berkas ini (baris '.$skuDiBerkas[$sku].').');

                continue;
            }

            if ($barcode !== '' && isset($barcodeDiBerkas[$barcode])) {
                $tolak('Barcode "'.$barcode.'" dipakai dua kali di berkas ini (baris '.$barcodeDiBerkas[$barcode].').');

                continue;
            }

            /*
             * Barcode yang sudah dipakai produk LAIN ditolak, tidak ditimpa.
             *
             * Barcode adalah yang dipindai kasir. Dua produk berbagi satu barcode berarti
             * kasir memindai dan mendapat barang yang salah — dan yang muncul di layar
             * memang barang yang ada, jadi tidak ada yang curiga sampai stoknya melenceng.
             */
            if ($barcode !== '' && isset($barcodeAda[$barcode])
                && ($sku === '' || ($skuAda[$sku] ?? null) !== $barcodeAda[$barcode])) {
                $tolak('Barcode "'.$barcode.'" sudah dipakai barang lain di aplikasi.');

                continue;
            }

            if ($sku !== '') {
                $skuDiBerkas[$sku] = $baris['nomor'];
            }

            if ($barcode !== '') {
                $barcodeDiBerkas[$barcode] = $baris['nomor'];
            }

            $muatan = [
                'nama_produk' => $nama,
                'harga_default' => Uang::baca($harga),
                'satuan' => $satuan->value,
            ];

            if ($hargaBeli !== '') {
                $muatan['harga_beli'] = Uang::baca($hargaBeli);
            }

            if ($sku !== '') {
                $muatan['sku'] = $sku;
            }

            if ($barcode !== '') {
                $muatan['barcode'] = $barcode;
            }

            $hasil['siap'][] = [
                'nomor' => $baris['nomor'],
                'muatan' => $muatan,
                'kategori' => $ambil('kategori') !== '' ? $ambil('kategori') : null,
                // Produk yang AKAN ditimpa, supaya pratinjaunya bisa memberi tahu berapa yang
                // diperbarui dan berapa yang baru — dua angka yang berbeda artinya bagi
                // pemilik, dan satu angka gabungan menyembunyikan yang penting.
                'menimpa' => $sku !== '' ? ($skuAda[$sku] ?? null) : null,
            ];
        }

        return $hasil;
    }

    /**
     * Menyimpan hasil pemeriksaan.
     *
     * Memanggil `periksa()` LAGI, bukan menerima hasil pratinjau dari klien: muatan yang
     * dikirim balik peramban bisa disunting, dan yang disunting di sini adalah harga barang.
     * Berkasnya yang dibawa, bukan kesimpulannya.
     *
     * @return array{baru: int, diperbarui: int, ditolak: int}
     */
    public function simpan(string $isiCsv): array
    {
        $periksa = $this->periksa($isiCsv);

        if ($periksa['kolomHilang'] !== []) {
            return ['baru' => 0, 'diperbarui' => 0, 'ditolak' => count($periksa['ditolak'])];
        }

        $baru = 0;
        $diperbarui = 0;

        /*
         * SATU transaksi untuk seluruh berkas.
         *
         * Kegagalan di baris ke-250 tidak boleh meninggalkan 249 produk masuk: pemilik yang
         * mengunggah ulang berkas yang sama akan mendapat 249 produk kembar, karena baris
         * tanpa SKU selalu dianggap barang baru.
         */
        DB::transaction(function () use ($periksa, &$baru, &$diperbarui) {
            $kategoriCache = [];

            foreach ($periksa['siap'] as $baris) {
                $muatan = $baris['muatan'];

                if ($baris['kategori'] !== null) {
                    $muatan['kategori_id'] = $kategoriCache[$baris['kategori']]
                        ??= $this->kategoriId($baris['kategori']);
                }

                if ($baris['menimpa'] !== null) {
                    Product::whereKey($baris['menimpa'])->firstOrFail()->update($muatan);
                    $diperbarui++;

                    continue;
                }

                Product::create($muatan);
                $baru++;
            }
        });

        return ['baru' => $baru, 'diperbarui' => $diperbarui, 'ditolak' => count($periksa['ditolak'])];
    }

    /**
     * Kategori dibuat kalau belum ada, dicocokkan tanpa peduli besar-kecil huruf.
     *
     * Tanpa itu, "Minuman" dan "minuman" jadi dua kategori — dan layar kasir menampilkan dua
     * tab bernama sama yang isinya terbelah.
     */
    private function kategoriId(string $nama): string
    {
        $ada = Category::query()->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])->first();

        return $ada?->getKey() ?? Category::create(['nama' => $nama])->getKey();
    }

    /**
     * Satuan yang diketik orang → enum. Kosong dianggap `pcs` (bawaan kolomnya).
     *
     * Menerima bentuk yang biasa ditulis di Excel ("Kg", "PCS", "buah", "botol") karena
     * menolak "Buah" pada berkas 300 baris berarti 300 penolakan untuk satu kata.
     */
    private function bacaSatuan(string $teks): ?Satuan
    {
        $teks = mb_strtolower(trim($teks));

        if ($teks === '') {
            return Satuan::Pcs;
        }

        $padanan = [
            'buah' => Satuan::Pcs, 'pc' => Satuan::Pcs, 'pieces' => Satuan::Pcs,
            'bungkus' => Satuan::Pcs, 'botol' => Satuan::Pcs, 'sachet' => Satuan::Pcs,
            'kilogram' => Satuan::Kg, 'kilo' => Satuan::Kg,
            'gr' => Satuan::Gram, 'g' => Satuan::Gram,
            'ltr' => Satuan::Liter, 'l' => Satuan::Liter,
            'mililiter' => Satuan::Ml, 'cc' => Satuan::Ml,
            'kardus' => Satuan::Dus, 'karton' => Satuan::Dus, 'box' => Satuan::Dus,
            'piring' => Satuan::Porsi, 'porsi' => Satuan::Porsi,
        ];

        return Satuan::tryFrom($teks) ?? $padanan[$teks] ?? null;
    }

    /**
     * Judul kolom di berkas → medan aplikasi.
     *
     * @param  array<int, string>  $judul
     * @return array<string, string> medan => judul asli di berkas
     */
    private function petakanKolom(array $judul): array
    {
        $peta = [];

        foreach (self::PADANAN as $medan => $namaYangDiterima) {
            foreach ($judul as $kolom) {
                if (in_array($kolom, $namaYangDiterima, true)) {
                    // Yang PERTAMA menang: berkas dengan dua kolom harga (mis. "harga" dan
                    // "harga_jual") memakai yang paling kiri, dan pratinjaunya menyebut kolom
                    // yang satunya sebagai tidak terpakai — jadi pemilik tahu mana yang dibaca.
                    $peta[$medan] = $kolom;

                    break;
                }
            }
        }

        return $peta;
    }

    /** Isi berkas templat, supaya pemilik tidak menebak nama kolomnya. */
    public static function templat(): string
    {
        return "nama,harga,harga_beli,sku,barcode,satuan,kategori\n"
            ."Nasi Goreng,15000,9000,,,porsi,Makanan\n"
            ."Es Teh Manis,5000,1500,,,pcs,Minuman\n"
            ."Beras Premium 5kg,72000,65000,BRS-5,8991234567890,pcs,Sembako\n";
    }
}

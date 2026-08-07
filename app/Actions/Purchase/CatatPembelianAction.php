<?php

namespace App\Actions\Purchase;

use App\Enums\DocumentStatus;
use App\Models\Bahan\RawMaterial;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Pembelian\PurchaseOrderItem;
use App\Models\Pembelian\Supplier;
use App\Models\Produk\Product;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;
use App\Support\Uang;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Mencatat satu nota pembelian: barisnya tersimpan, totalnya dihitung.
 *
 * Aksi ini TIDAK MENYENTUH STOK dan tidak menyentuh harga beli master — semua itu milik
 * TerimaPembelianAction, dan aksi ini hanya mendelegasikan ke sana kalau muatannya berkata
 * barangnya sudah datang (`sudah_datang`, bawaan true).
 *
 * Kenapa dipisah, bukan cabang `if` di sini: dengan aksi tersendiri, AdjustStockAction dan
 * SiapkanBarisStokAction punya TEPAT SATU pemanggil dari jalur pembelian. Cabang `if` di
 * tengah aksi sepanjang ini adalah cabang yang suatu hari dibalik oleh perbaikan yang tidak
 * berhubungan, tanpa satu pun galat saat itu terjadi.
 *
 * KEPUTUSAN YANG MENENTUKAN ANGKA, dan kenapa:
 *
 * 1. **Bawaannya "barangnya sudah saya terima".** Belanja warung yang biasa dicatat sesudah
 *    barang diturunkan dari motor, jadi bawaan sebaliknya akan mengubah setiap nota biasa
 *    menjadi nota menggantung yang stoknya tidak pernah masuk. Yang tidak bawaan adalah
 *    keadaan yang jarang: barang dipesan hari ini, datang tujuh hari kemudian.
 *
 * 2. **Nota yang belum datang berstatus `Dikirim` ("Masih di jalan"), BUKAN `Draft`.**
 *    Default kolom `status` di migrasi memang 'draft', dan justru karena itu aplikasi tidak
 *    pernah menulisnya: nota berstatus draft berarti ada baris yang lahir tanpa lewat aksi
 *    ini, dan itu anomali yang bisa dilihat, bukan keadaan sah yang harus ditebak-tebak.
 *
 * 3. **Harga beli master diperbarui saat DITERIMA, bukan saat nota disimpan.** Lihat
 *    TerimaPembelianAction: nilai persediaan = saldo × harga_beli, jadi memperbarui harga
 *    lebih awal menilai ulang barang yang sudah di rak dengan harga barang yang belum dibeli.
 *
 * 4. **Rata-rata bergerak DITOLAK.** Ia butuh saldo sebagai pembagi, sedangkan di POS ini
 *    saldo boleh minus dan boleh belum ada sama sekali. Rata-rata atas saldo −3
 *    menghasilkan harga negatif tanpa satu pun galat. Harga TERAKHIR yang menang.
 *
 * 5. **Diskon & ongkos kirim tidak dibagi ke harga barang.** Ongkir bukan nilai barang;
 *    membebankannya ke harga beli membuat nilai persediaan naik karena biaya angkut.
 *
 * 6. **Tidak menyentuh `cash_movements`.** Kolom itu menuntut `cash_session_id`, sedangkan
 *    belanja ke grosir terjadi di luar shift kasir — menempelkannya ke sembarang sesi
 *    membuat rekonsiliasi laci tidak pernah cocok lagi.
 *
 * 7. **Tidak menyentuh `opname_terakhir_pada` maupun `perlu_diperiksa`.** Barang masuk
 *    bukan barang yang dihitung; mematikan bendera "perlu diperiksa" karena ada barang
 *    datang berarti menghapus pengingat memeriksa selisih yang belum pernah diperiksa.
 */
class CatatPembelianAction
{
    /**
     * Berapa kali penyimpanan diulang saat nomor notanya bertabrakan.
     *
     * Ada `unique(['tenant_id','nomor_po'])`, dan nomor dihitung dari nomor terbesar yang
     * ada — dua perangkat yang menyimpan pada detik yang sama sama-sama membaca angka yang
     * sama. Tanpa coba-ulang, yang kalah mendapat 500 di depan pemilik yang baru saja
     * mengetik 12 baris, dan seluruh isiannya hilang.
     */
    private const MAKS_PERCOBAAN = 5;

    public function __construct(
        private TerimaPembelianAction $terima,
    ) {}

    /**
     * @param  array{beli_dari?: ?string, tanggal?: mixed, diskon?: mixed, ongkos_kirim?: mixed, catatan?: ?string, sudah_datang?: mixed, baris: array<int, array{product_id?: ?string, raw_material_id?: ?string, qty_beli?: mixed, harga_satuan?: mixed}>}  $muatan
     */
    public function execute(Outlet $outlet, User $oleh, array $muatan): PurchaseOrder
    {
        $baris = array_values($muatan['baris'] ?? []);

        if ($baris === []) {
            // Nota kosong bukan nota. Kalau dibiarkan lolos, daftar pembelian terisi
            // baris-baris tanpa barang yang tidak bisa dibedakan dari nota yang isinya
            // gagal tersimpan.
            throw new InvalidArgumentException('Nota pembelian harus berisi minimal satu barang.');
        }

        $diskon = $this->uang($muatan['diskon'] ?? 0, 'Diskon');
        $ongkir = $this->uang($muatan['ongkos_kirim'] ?? 0, 'Ongkos kirim');
        $tanggal = $this->tanggal($muatan['tanggal'] ?? null);

        for ($percobaan = 1; ; $percobaan++) {
            try {
                return DB::transaction(fn (): PurchaseOrder => $this->simpan(
                    $outlet,
                    $oleh,
                    $baris,
                    $tanggal,
                    $diskon,
                    $ongkir,
                    $this->teksBersih($muatan['beli_dari'] ?? null),
                    $this->teksBersih($muatan['catatan'] ?? null),
                    $this->sudahDatang($muatan['sudah_datang'] ?? null),
                ));
            } catch (UniqueConstraintViolationException $bentrok) {
                // Nomor notanya keburu dipakai perangkat lain di celah antara "hitung
                // nomor" dan "simpan". Transaksinya sudah digulung balik, jadi mengulang
                // dari awal aman: nomor dihitung ulang dan tidak ada baris setengah jadi.
                if ($percobaan >= self::MAKS_PERCOBAAN) {
                    throw $bentrok;
                }
            }
        }
    }

    /**
     * Seluruh nota dalam SATU transaksi.
     *
     * Berbeda dari opname (satu transaksi per baris) dan itu disengaja: lembar opname
     * adalah 120 pengamatan yang berdiri sendiri, sedangkan nota adalah satu dokumen.
     * Nota yang tersimpan separuh — tiga barang masuk, dua tidak, totalnya salah — lebih
     * buruk daripada nota yang gagal seluruhnya, karena tidak ada yang tahu bagian mana
     * yang hilang dan mengulanginya akan menggandakan tiga baris pertama.
     *
     * Penerimaannya ikut di dalam transaksi yang SAMA: nota yang tersimpan tapi stoknya
     * gagal masuk adalah bentuk lain dari nota separuh, dan bentuk itu paling menyesatkan
     * karena lencananya sudah berbunyi "Barang sudah datang".
     *
     * @param  array<int, array<string, mixed>>  $baris
     */
    private function simpan(
        Outlet $outlet,
        User $oleh,
        array $baris,
        Carbon $tanggal,
        float $diskon,
        float $ongkir,
        ?string $beliDari,
        ?string $catatan,
        bool $sudahDatang,
    ): PurchaseOrder {
        $tenantId = $outlet->tenant_id;

        $po = new PurchaseOrder([
            'outlet_id' => $outlet->getKey(),
            'supplier_id' => $this->supplierUntuk($tenantId, $beliDari),
            // Dihitung DI DALAM transaksi: di luar, jendela antara menghitung dan menulis
            // selebar seluruh sisa penyimpanan.
            'nomor_po' => $this->nomorNota($tenantId, $tanggal),
            'tanggal' => $tanggal->toDateString(),
            // Lahir SELALU sebagai "masih di jalan", bahkan untuk nota yang barangnya sudah
            // datang — yang mengubahnya jadi Diterima cuma TerimaPembelianAction, di bawah.
            // Dengan begitu tidak ada satu pun jalur yang bisa menulis "Diterima" tanpa
            // mutasi stok yang berpasangan dengannya.
            'status' => DocumentStatus::Dikirim,
            'diterima_pada' => null,
            'diskon' => $diskon,
            'ongkos_kirim' => $ongkir,
            'catatan' => $catatan,
            'dibuat_oleh' => $oleh->getKey(),
        ]);

        // tenant_id tidak pernah fillable; diisi eksplisit supaya aksi ini tetap benar
        // walau dipanggil dari jalur tanpa TenantContext (perintah artisan, seeder).
        $po->tenant_id = $tenantId;
        $po->save();

        $subtotalNota = 0.0;

        foreach ($baris as $satu) {
            $subtotalNota += $this->catatBaris($po, $satu);
        }

        /*
         * Diskon lebih besar daripada belanjaannya selalu salah ketik — dan sesudah titik
         * ribuan dibaca dengan benar, keadaan ini JADI MUNGKIN di jalur ini.
         *
         * Dulu "1.000.000" di kolom diskon berhenti lebih awal sebagai "harus berupa angka"
         * (is_numeric menolaknya), jadi tidak pernah sampai ke hitungan total. Sekarang ia
         * terbaca 1.000.000 dengan benar, dan tanpa penjaga ini nota Rp 116.000 akan
         * tersimpan bertotal −884.000: uang MASUK menurut catatan, padahal pemiliknya baru
         * saja membayar. Layar punya penjaga yang sama (PembelianBaru), tapi seeder dan
         * perintah artisan tidak melewatinya.
         *
         * Toleransi 0,005 supaya diskon yang persis sebesar belanjaannya (gratis total) tidak
         * ikut tertolak karena pembulatan sen.
         */
        if ($diskon > $subtotalNota + 0.005) {
            throw new InvalidArgumentException(
                'Diskon tidak boleh lebih besar daripada total belanjaannya — belanja Rp '
                .number_format($subtotalNota, 0, ',', '.').', diskon Rp '.number_format($diskon, 0, ',', '.').'.'
            );
        }

        // total = subtotal − diskon + ongkir. Diskon & ongkir TIDAK dibagi ke harga
        // barang; keduanya hanya menggeser uang yang keluar, bukan nilai barangnya.
        $po->total = round($subtotalNota - $diskon + $ongkir, 2);
        $po->save();

        if ($sudahDatang) {
            // Satu-satunya jalur stok dari pembelian. Barisnya sudah tersimpan, jadi aksi
            // itu membaca POTRET satuan dasarnya dari nota — bukan menghitung ulang dari
            // master, yang bisa sudah berubah nanti saat notanya baru ditandai datang.
            $this->terima->execute($po, $oleh);
        }

        return $po;
    }

    /**
     * Satu baris nota. TIDAK menyentuh stok maupun harga beli master.
     *
     * @param  array<string, mixed>  $satu
     * @return float subtotal baris ini
     */
    private function catatBaris(PurchaseOrder $po, array $satu): float
    {
        $productId = $satu['product_id'] ?? null;
        $rawMaterialId = $satu['raw_material_id'] ?? null;

        if (($productId === null) === ($rawMaterialId === null)) {
            throw new InvalidArgumentException('Satu baris pembelian harus menunjuk tepat satu produk ATAU satu bahan baku.');
        }

        $qtyBeli = $this->angka($satu['qty_beli'] ?? null, 'Jumlah beli');

        if ($qtyBeli <= 0) {
            // Nol berarti barisnya tidak diisi; negatif berarti salah ketik. Keduanya
            // tidak boleh menjadi mutasi stok — pembelian minus adalah retur, dan retur
            // punya jalur sendiri (pembatalan nota), bukan angka minus di nota masuk.
            throw new InvalidArgumentException('Jumlah beli harus lebih dari nol.');
        }

        $harga = $this->uang($satu['harga_satuan'] ?? 0, 'Harga beli');

        $barang = $productId !== null
            ? $this->produk($po->tenant_id, $productId)
            : $this->bahan($po->tenant_id, $rawMaterialId);

        // Konversi satuan HANYA lewat Product::keSatuanDasar() — satu-satunya tempat
        // faktor dus→pcs dipakai. Bahan baku belum punya kolom konversi, jadi angkanya
        // memang sudah dalam satuan pencatatannya sendiri.
        $qtyDasar = $barang instanceof Product
            ? $barang->keSatuanDasar($qtyBeli)
            : $qtyBeli;

        $subtotal = round($qtyBeli * $harga, 2);

        $item = new PurchaseOrderItem([
            'purchase_order_id' => $po->getKey(),
            'product_id' => $productId,
            'raw_material_id' => $rawMaterialId,
            // qty = satuan DASAR (yang masuk kartu stok); qty_beli = yang diketik pemilik.
            'qty' => $qtyDasar,
            'qty_beli' => $qtyBeli,
            // POTRET, bukan acuan hidup: master boleh berubah, nota lama tetap terbaca
            // dengan angka yang berlaku saat ia ditulis.
            'satuan_beli' => $barang->satuan?->value,
            'isi_per_satuan_beli' => $barang instanceof Product && $barang->isi_per_satuan !== null
                ? (float) $barang->isi_per_satuan
                : null,
            // NOL sampai barangnya benar-benar ditandai datang. Mengisinya penuh di sini
            // berarti nota yang barangnya masih di jalan mengaku sudah diterima seluruhnya,
            // dan tidak ada satu pun angka di aplikasi yang membantahnya.
            'qty_diterima' => 0,
            'harga_satuan' => $harga,
            'subtotal' => $subtotal,
        ]);

        $item->tenant_id = $po->tenant_id;
        $item->save();

        return $subtotal;
    }

    /**
     * Nomor nota `PB-YYYYMMDD-001`, berurut per tenant per tanggal.
     *
     * Awalannya PB (bukan PO) karena layar ini mencatat NOTA BELANJA yang barangnya sudah
     * datang, bukan pesanan pembelian — dan data demo yang sudah ada memakai PO-…, jadi
     * dua awalan yang berbeda menjaga nomor lama tidak pernah ikut terhitung.
     *
     * withoutGlobalScopes() melepas TenantScope (tenant sudah disaring manual) DAN
     * SoftDeletingScope: nomor milik nota yang dihapus tidak boleh dipakai ulang, karena
     * unique index tetap menghitungnya dan nomor kembar membuat dua nota berbeda menunjuk
     * satu identitas di mata pemiliknya.
     */
    private function nomorNota(string $tenantId, Carbon $tanggal): string
    {
        $awalan = 'PB-'.$tanggal->format('Ymd');

        $dasar = fn () => PurchaseOrder::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        $terakhir = $dasar()->where('nomor_po', 'like', $awalan.'-%')->max('nomor_po');
        $nomor = $terakhir === null
            ? 1
            : ((int) substr((string) $terakhir, strlen($awalan) + 1)) + 1;

        // Diperiksa satu per satu sampai bebas: nomor bisa melompat kalau ada nota yang
        // nomornya pernah diisi tangan dan tidak berbentuk angka.
        do {
            $kode = $awalan.'-'.str_pad((string) $nomor, 3, '0', STR_PAD_LEFT);
            $nomor++;
        } while ($dasar()->where('nomor_po', $kode)->exists());

        return $kode;
    }

    /**
     * "Beli dari" adalah teks bebas; tabel suppliers tetap dipakai sebagai daftar nama.
     *
     * Tidak ada layar kelola supplier, dan itu disengaja: pemilik warung tidak akan
     * mengisi formulir master supplier sebelum mencatat belanja. Nama yang sudah pernah
     * dipakai ditawarkan sebagai saran; nama baru membuat barisnya sendiri. Dikosongkan =
     * sah, `supplier_id` null — belanja di pasar tidak selalu punya nama toko.
     *
     * Pencocokan tanpa peduli besar-kecil huruf, supaya "Grosir Amanah" dan "grosir
     * amanah" tidak menjadi dua pemasok yang berbeda di daftar saran.
     */
    private function supplierUntuk(string $tenantId, ?string $nama): ?string
    {
        if ($nama === null) {
            return null;
        }

        $ada = Supplier::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->first();

        if ($ada !== null) {
            return $ada->getKey();
        }

        $baru = new Supplier(['nama' => $nama]);
        $baru->tenant_id = $tenantId;
        $baru->save();

        return $baru->getKey();
    }

    /**
     * Barang dicari dengan filter tenant EKSPLISIT, bukan mengandalkan global scope.
     *
     * Aksi ini bisa dipanggil dari jalur tanpa TenantContext (seeder, perintah artisan),
     * dan di keadaan itu scope-nya tidak memfilter apa pun — id produk milik merchant lain
     * akan lolos dan stoknya bertambah di warung yang salah.
     */
    private function produk(string $tenantId, string $productId): Product
    {
        return Product::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($productId)
            ->firstOrFail();
    }

    private function bahan(string $tenantId, string $rawMaterialId): RawMaterial
    {
        return RawMaterial::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($rawMaterialId)
            ->firstOrFail();
    }

    private function tanggal(mixed $nilai): Carbon
    {
        if (blank($nilai)) {
            return Carbon::today();
        }

        return Carbon::parse($nilai)->startOfDay();
    }

    /**
     * Uang: wajib angka rupiah yang bisa dibaca, tidak boleh negatif. Nol sah (bonus grosir).
     *
     * PENJAGANYA DI SINI, BUKAN CUMA DI LAYAR. Aksi ini juga dipanggil dari seeder, perintah
     * artisan, dan uji — jalur yang tidak pernah melewati satu pun aturan validasi Livewire.
     *
     * Kenapa BUKAN `is_numeric` + `(float)`, bentuk yang dulu ada di sini: pemilik warung
     * mengetik titik ribuan dengan sendirinya, dan `is_numeric('58.000')` bernilai true
     * sementara `(float) '58.000'` bernilai 58,0. Nota Rp 116.000 tersimpan Rp 116, diskon
     * "1.000" menjadi Rp 1, totalnya Rp 115, dan harga beli di master produk tertimpa jadi
     * Rp 58 — tanpa satu pun galat di layar. Lihat App\Support\Uang.
     *
     * Yang dibatasi hanya angka yang DIKETIK. Hasil hitungan tetap boleh berdesimal: harga
     * per satuan dasar (116.000 ÷ 24 pcs = 4.833,33) dihitung di TerimaPembelianAction dan
     * disimpan di kolom decimal(15,2). Konversi dus→pcs tidak lewat sini sama sekali.
     */
    private function uang(mixed $nilai, string $label): float
    {
        if (blank($nilai)) {
            return 0.0;
        }

        // Minus diperiksa LEBIH DULU supaya pesannya menyebut sebab yang sebenarnya.
        // Uang::baca juga menolak minus, tapi pesannya akan berbunyi "bentuknya tidak
        // terbaca" untuk angka yang bentuknya justru benar — cuma salah tanda.
        if (is_numeric($nilai) && (float) $nilai < 0) {
            throw new InvalidArgumentException($label.' tidak boleh negatif.');
        }

        if (! Uang::sah($nilai)) {
            throw new InvalidArgumentException(
                $label.' ditulis dengan angka rupiah saja — mis. 58000 atau 58.000. Yang tertulis: '.$this->mentah($nilai).'.'
            );
        }

        return (float) (Uang::baca($nilai) ?? 0);
    }

    /**
     * "Barangnya sudah saya terima" — BAWAAN true kalau muatannya tidak menyebutkannya.
     *
     * Bawaan ini yang menjaga belanja warung biasa tidak berubah jadi nota menggantung, dan
     * juga yang membuat seluruh pemanggil lama (seeder data demo, uji yang sudah ada) tetap
     * berarti hal yang sama seperti sebelum keadaan "belum datang" ada.
     *
     * Nilai yang tidak bisa ditafsirkan juga jatuh ke true dengan alasan yang sama: satu-
     * satunya cara nota menjadi menggantung adalah pemiliknya memilihnya dengan sadar.
     */
    private function sudahDatang(mixed $nilai): bool
    {
        if ($nilai === null) {
            return true;
        }

        return filter_var($nilai, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * KUANTITAS — aturannya kebalikan dari uang, dan itu disengaja.
     *
     * Pecahan SAH: pemilik warteg membeli 2,5 kg beras, dan koma adalah cara ia menulisnya.
     * Yang ditolak justru titik ribuan ("1.500"), karena kalau dibaca 1,5 orang yang baru
     * belajar "titik boleh di kolom harga" akan menerima 1,5 kg tanpa satu pun galat —
     * seribu kali lebih sedikit daripada yang ia maksud. Aturannya di App\Support\Uang.
     *
     * Nol dan minus lolos dari sini dengan sengaja: keduanya berbentuk angka yang benar,
     * dan yang menolaknya adalah aturan domain di catatBaris() ("harus lebih dari nol") yang
     * pesannya jauh lebih bisa dipahami daripada "bentuknya tidak terbaca".
     */
    private function angka(mixed $nilai, string $label): float
    {
        if (blank($nilai)) {
            throw new InvalidArgumentException($label.' wajib diisi — tulis angkanya, mis. 2 atau 2,5.');
        }

        try {
            return (float) (Uang::bacaJumlah($nilai) ?? 0);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(
                $label.' ditulis dengan angka saja — mis. 2 atau 2,5 untuk setengah. Yang tertulis: '.$this->mentah($nilai).'.'
            );
        }
    }

    /**
     * Nilai mentah untuk pesan galat, dikutip supaya spasi & titiknya kelihatan.
     *
     * Pesan yang tidak menyebut apa yang ditolak membuat orang mengetik ulang hal yang sama.
     */
    private function mentah(mixed $nilai): string
    {
        if (is_scalar($nilai)) {
            return '"'.(is_bool($nilai) ? ($nilai ? 'true' : 'false') : $nilai).'"';
        }

        return is_array($nilai) ? 'daftar nilai' : get_debug_type($nilai);
    }

    private function teksBersih(?string $teks): ?string
    {
        $bersih = trim((string) $teks);

        return $bersih === '' ? null : $bersih;
    }
}

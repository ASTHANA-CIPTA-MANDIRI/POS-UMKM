<?php

namespace Tests\Feature;

use App\Actions\Produk\ImporProdukAction;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Models\Produk\Category;
use App\Models\Produk\Product;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Impor produk dari CSV — periksa dulu, simpan belakangan.
 *
 * Yang dijaga berkas ini, dan tiap butirnya lahir dari cara berkas SUNGGUHAN sampai ke
 * aplikasi, bukan dari CSV ideal:
 *
 *  - HARGA "15.000" harus jadi lima belas ribu, bukan lima belas. `is_numeric` menyatakannya
 *    sah dan `(float)` membacanya 15 — dan harga itu dipakai kasir hari itu juga.
 *  - SATU BARIS CACAT tidak menggagalkan 299 baris yang benar. Berkas yang ditolak
 *    seluruhnya karena satu salah ketik membuat orang menyerah pada impor.
 *  - PERIKSA TIDAK MENYENTUH BASIS DATA. Pratinjau yang diam-diam menyimpan adalah cacat
 *    yang paling mahal di layar ini.
 *  - BARCODE KEMBAR ditolak. Kasir memindai dan mendapat barang yang salah — dan barang yang
 *    muncul memang ada, jadi tidak ada yang curiga sampai stoknya melenceng.
 */
class ImporProdukTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Kelontong Impor');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@impor.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function aksi(): ImporProdukAction
    {
        return app(ImporProdukAction::class);
    }

    /* ── Uang ────────────────────────────────────────────────────────────── */

    #[Test]
    public function harga_bertitik_ribuan_masuk_utuh(): void
    {
        /*
         * Cacat yang sudah benar-benar terjadi di kolom harga beli nota belanja (96d4844).
         * Di sini akibatnya berlipat: satu berkas bisa memasukkan 300 harga yang salah
         * seribu kali sekaligus, dan kasir memakainya hari itu juga.
         */
        $this->aksi()->simpan("nama,harga\nNasi Goreng,15.000\n");

        $this->assertSame(15000.0, (float) Product::firstOrFail()->harga_default);
    }

    #[Test]
    public function harga_berdesimal_ditolak_bukan_ditebak(): void
    {
        // "15.5" bisa berarti lima belas setengah atau lima belas ribu lima ratus. Menebak
        // uang orang lain tidak boleh pernah senyap.
        $hasil = $this->aksi()->periksa("nama,harga\nNasi Goreng,15.5\n");

        $this->assertCount(1, $hasil['ditolak']);
        $this->assertStringContainsString('Harganya tidak terbaca', $hasil['ditolak'][0]['sebab']);
    }

    /* ── Periksa vs simpan ───────────────────────────────────────────────── */

    #[Test]
    public function periksa_tidak_menyentuh_basis_data(): void
    {
        // Pratinjau yang diam-diam menyimpan adalah cacat paling mahal di layar ini: pemilik
        // menekan "Batal" lalu mendapati 300 barang sudah masuk.
        $this->aksi()->periksa("nama,harga\nNasi Goreng,15000\nEs Teh,5000\n");

        $this->assertSame(0, Product::count());
    }

    #[Test]
    public function periksa_melaporkan_berapa_yang_baru_dan_berapa_yang_ditimpa(): void
    {
        // Dua angka yang berbeda artinya bagi pemilik. Satu angka gabungan menyembunyikan
        // yang penting: bahwa harga barang yang sudah ada akan berubah.
        Product::create(['nama_produk' => 'Beras Lama', 'sku' => 'BRS-5', 'harga_default' => 70000]);

        $hasil = $this->aksi()->periksa("nama,harga,sku\nBeras Premium,72000,BRS-5\nGula,14000,\n");

        $this->assertCount(2, $hasil['siap']);
        $this->assertNotNull($hasil['siap'][0]['menimpa'], 'baris ber-SKU yang sudah ada harus ditandai menimpa');
        $this->assertNull($hasil['siap'][1]['menimpa']);
    }

    #[Test]
    public function simpan_memakai_berkasnya_lagi_bukan_kesimpulan_dari_klien(): void
    {
        // simpan() memanggil periksa() sendiri. Kalau ia menerima muatan hasil pratinjau,
        // muatan itu bisa disunting di peramban — dan yang disunting adalah harga barang.
        $csv = "nama,harga\nNasi Goreng,15000\n";

        $this->aksi()->periksa($csv);
        $hasil = $this->aksi()->simpan($csv);

        $this->assertSame(1, $hasil['baru']);
        $this->assertSame(15000.0, (float) Product::firstOrFail()->harga_default);
    }

    /* ── Baris cacat ─────────────────────────────────────────────────────── */

    #[Test]
    public function satu_baris_cacat_tidak_menggagalkan_baris_yang_benar(): void
    {
        /*
         * Berkas 300 baris yang ditolak seluruhnya karena satu salah ketik membuat orang
         * menyerah pada impor dan kembali mengetik manual — yaitu keadaan sebelum fitur ini
         * ada sama sekali.
         */
        $hasil = $this->aksi()->simpan("nama,harga\nNasi Goreng,15000\n,9000\nEs Teh,5000\n");

        $this->assertSame(2, $hasil['baru']);
        $this->assertSame(1, $hasil['ditolak']);
        $this->assertSame(2, Product::count());
    }

    #[Test]
    public function penolakan_menyebut_nomor_baris_yang_dilihat_orang_di_excel(): void
    {
        // Nomor yang meleset satu membuat pemilik memperbaiki baris yang salah — dan baris
        // yang benar tetap ditolak pada percobaan berikutnya.
        $hasil = $this->aksi()->periksa("nama,harga\nNasi Goreng,15000\nEs Teh,bukan angka\n");

        $this->assertSame(3, $hasil['ditolak'][0]['nomor']);
        $this->assertSame('Es Teh', $hasil['ditolak'][0]['nama']);
    }

    #[Test]
    public function baris_tanpa_nama_ditolak(): void
    {
        $hasil = $this->aksi()->periksa("nama,harga\n,15000\n");

        $this->assertStringContainsString('Nama barangnya kosong', $hasil['ditolak'][0]['sebab']);
    }

    /* ── Kolom ───────────────────────────────────────────────────────────── */

    #[Test]
    public function hanya_nama_dan_harga_yang_wajib(): void
    {
        /*
         * Pemilik yang baru mulai punya daftar barang berisi dua kolom itu saja. Menuntut
         * tujuh kolom membuat impor kalah cepat daripada mengetik manual — dan fitur yang
         * kalah cepat tidak dipakai.
         */
        $hasil = $this->aksi()->simpan("nama,harga\nNasi Goreng,15000\n");

        $this->assertSame(1, $hasil['baru']);

        $produk = Product::firstOrFail();

        $this->assertSame(Satuan::Pcs, $produk->satuan, 'satuan kosong jatuh ke bawaan kolomnya');
        $this->assertNotEmpty($produk->sku, 'SKU tetap dibuat otomatis oleh model');
    }

    #[Test]
    public function kolom_yang_hilang_dilaporkan_dan_tidak_ada_yang_disimpan(): void
    {
        $hasil = $this->aksi()->periksa("nama,keterangan\nNasi Goreng,enak\n");

        $this->assertSame(['harga'], $hasil['kolomHilang']);
        $this->assertSame([], $hasil['siap']);
    }

    #[Test]
    public function nama_kolom_boleh_berbeda_beda(): void
    {
        // Daftar barang yang beredar di WhatsApp ditulis orang yang berbeda-beda. Menolak
        // "Nama Barang" karena bukan "nama" membuat pemilik menyunting judul kolomnya dulu —
        // langkah tambahan untuk hasil yang persis sama.
        $hasil = $this->aksi()->simpan("Nama Barang,Harga Jual,Kode\nNasi Goreng,15000,NG-1\n");

        $this->assertSame(1, $hasil['baru']);
        $this->assertSame('NG-1', Product::firstOrFail()->sku);
    }

    #[Test]
    public function kolom_yang_tidak_dikenal_dilaporkan_bukan_diabaikan_diam_diam(): void
    {
        /*
         * Pemilik yang menaruh stok awal di kolom "stok" berhak tahu bahwa kolom itu TIDAK
         * dibaca. Kalau diabaikan diam-diam, ia menyimpulkan stoknya sudah masuk — dan baru
         * tahu tidak, saat kasir menjual barang yang saldonya nol.
         */
        $hasil = $this->aksi()->periksa("nama,harga,stok\nNasi Goreng,15000,20\n");

        $this->assertContains('stok', $hasil['kolomTakDikenal']);
    }

    /* ── Satuan & kategori ───────────────────────────────────────────────── */

    #[Test]
    public function satuan_yang_biasa_ditulis_orang_diterima(): void
    {
        // Menolak "Buah" pada berkas 300 baris berarti 300 penolakan untuk satu kata.
        $this->aksi()->simpan("nama,harga,satuan\nSabun,3000,buah\nBeras,72000,Kg\nMinyak,18000,LTR\n");

        $this->assertSame(Satuan::Pcs, Product::where('nama_produk', 'Sabun')->firstOrFail()->satuan);
        $this->assertSame(Satuan::Kg, Product::where('nama_produk', 'Beras')->firstOrFail()->satuan);
        $this->assertSame(Satuan::Liter, Product::where('nama_produk', 'Minyak')->firstOrFail()->satuan);
    }

    #[Test]
    public function satuan_yang_tidak_dikenal_ditolak_dan_menyebutkan_pilihannya(): void
    {
        // Penolakan tanpa daftar pilihan membuat orang menebak kata demi kata.
        $hasil = $this->aksi()->periksa("nama,harga,satuan\nKain,25000,meter\n");

        $this->assertStringContainsString('tidak dikenal', $hasil['ditolak'][0]['sebab']);
        $this->assertStringContainsString('kg', $hasil['ditolak'][0]['sebab']);
    }

    #[Test]
    public function kategori_dibuat_kalau_belum_ada_dan_tidak_kembar_karena_besar_kecil_huruf(): void
    {
        // "Minuman" dan "minuman" jadi dua kategori kalau dicocokkan mentah — dan layar kasir
        // menampilkan dua tab bernama sama yang isinya terbelah.
        $this->aksi()->simpan("nama,harga,kategori\nEs Teh,5000,Minuman\nKopi,8000,minuman\n");

        $this->assertSame(1, Category::count());
        $this->assertSame(2, Category::firstOrFail()->products()->count());
    }

    /* ── Kembar ──────────────────────────────────────────────────────────── */

    #[Test]
    public function sku_yang_sama_menimpa_produk_yang_ada_bukan_membuat_kembarannya(): void
    {
        // Impor ulang berkas yang sama adalah hal biasa — pemilik memperbaiki dua harga lalu
        // mengunggah lagi. Tanpa pencocokan SKU, ia mendapat 300 barang kembar.
        Product::create(['nama_produk' => 'Beras Lama', 'sku' => 'BRS-5', 'harga_default' => 70000]);

        $this->aksi()->simpan("nama,harga,sku\nBeras Premium,72000,BRS-5\n");

        $this->assertSame(1, Product::count());
        $this->assertSame('Beras Premium', Product::firstOrFail()->nama_produk);
        $this->assertSame(72000.0, (float) Product::firstOrFail()->harga_default);
    }

    #[Test]
    public function nama_yang_sama_tanpa_sku_tidak_dianggap_barang_yang_sama(): void
    {
        /*
         * Mencocokkan dengan NAMA terasa pintar dan berbahaya: "Teh Manis" dan "Teh manis"
         * adalah dua barang bagi orang yang mengetiknya, dan menimpakan harga baru ke barang
         * yang salah tidak bersuara. Pencocokan hanya lewat SKU — kode memang ada supaya
         * pertanyaan "ini barang yang mana" punya jawaban pasti.
         */
        Product::create(['nama_produk' => 'Teh Manis', 'harga_default' => 5000]);

        $this->aksi()->simpan("nama,harga\nTeh Manis,6000\n");

        $this->assertSame(2, Product::count());
    }

    #[Test]
    public function sku_kembar_di_dalam_berkas_ditolak(): void
    {
        /*
         * Kembar di dalam berkas TIDAK akan tertangkap pemeriksaan terhadap basis data: dua
         * baris ber-SKU sama lolos berdua, dan yang kedua menimpa yang pertama tanpa satu pun
         * tanda — pemilik melihat "2 produk masuk" padahal yang ada satu.
         */
        $hasil = $this->aksi()->periksa("nama,harga,sku\nBeras A,70000,BRS-5\nBeras B,72000,BRS-5\n");

        $this->assertCount(1, $hasil['siap']);
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertStringContainsString('dipakai dua kali', $hasil['ditolak'][0]['sebab']);
        $this->assertStringContainsString('baris 2', $hasil['ditolak'][0]['sebab'], 'sebutkan baris kembarannya');
    }

    #[Test]
    public function barcode_milik_barang_lain_ditolak(): void
    {
        /*
         * Barcode adalah yang dipindai kasir. Dua produk berbagi satu barcode berarti kasir
         * memindai dan mendapat barang yang salah — dan barang yang muncul memang ada, jadi
         * tidak ada yang curiga sampai stoknya melenceng.
         */
        Product::create([
            'nama_produk' => 'Beras Lama',
            'sku' => 'BRS-1',
            'barcode' => '8991234567890',
            'harga_default' => 70000,
        ]);

        $hasil = $this->aksi()->periksa("nama,harga,sku,barcode\nGula,14000,GUL-1,8991234567890\n");

        $this->assertCount(0, $hasil['siap']);
        $this->assertStringContainsString('sudah dipakai barang lain', $hasil['ditolak'][0]['sebab']);
    }

    #[Test]
    public function barcode_milik_produk_yang_memang_sedang_ditimpa_justru_diterima(): void
    {
        /*
         * Pagar untuk uji di atas, dan tanpanya fiturnya rusak untuk pemakaian yang paling
         * biasa: mengunggah ulang berkas yang sama. Barcode barang itu memang "sudah dipakai"
         * — oleh dirinya sendiri.
         */
        Product::create([
            'nama_produk' => 'Beras Lama',
            'sku' => 'BRS-1',
            'barcode' => '8991234567890',
            'harga_default' => 70000,
        ]);

        $hasil = $this->aksi()->periksa("nama,harga,sku,barcode\nBeras Baru,72000,BRS-1,8991234567890\n");

        $this->assertCount(1, $hasil['siap']);
        $this->assertSame([], $hasil['ditolak']);
    }

    /* ── Keutuhan & tenant ───────────────────────────────────────────────── */

    #[Test]
    public function produk_yang_diimpor_terikat_tenant_yang_sedang_aktif(): void
    {
        // tenant_id TIDAK PERNAH fillable — diisi BelongsToTenant. Produk yang lolos tanpa
        // tenant muncul di katalog warung lain.
        $this->aksi()->simpan("nama,harga\nNasi Goreng,15000\n");

        $this->assertSame($this->tenant->getKey(), Product::firstOrFail()->tenant_id);
    }

    #[Test]
    public function produk_bersku_sama_milik_tenant_lain_tidak_ikut_tertimpa(): void
    {
        /*
         * SKU hanya unik per tenant. Kalau pencocokannya bocor lintas tenant, impor satu
         * warung menimpa harga barang warung lain — cacat paling berat yang mungkin di sini,
         * dan tidak ada satu pun tanda di layar mana pun.
         */
        $lain = $this->buatTenant('Warung Sebelah');
        $asing = $this->konteks()->forTenant($lain->getKey(), fn () => Product::create([
            'nama_produk' => 'Beras Sebelah',
            'sku' => 'BRS-5',
            'harga_default' => 70000,
        ]));

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->aksi()->simpan("nama,harga,sku\nBeras Punyaku,99000,BRS-5\n");

        $this->assertSame(70000.0, (float) $asing->fresh()->harga_default);
        $this->assertSame('Beras Sebelah', $asing->fresh()->nama_produk);
    }

    #[Test]
    public function impor_tidak_membuat_baris_stok(): void
    {
        /*
         * Sama seperti layar Bahan: jalur kedua yang mengubah saldo tanpa mutasi membuat
         * kartu stok berhenti jadi bukti. Lagi pula baris bersaldo 0 berstatus "habis", jadi
         * kelontong yang mengimpor 300 barang akan melihat 300 lencana merah di hari pertama.
         */
        $this->aksi()->simpan("nama,harga\nNasi Goreng,15000\n");

        $this->assertSame(0, Product::firstOrFail()->stocks()->count());
    }

    #[Test]
    public function templatnya_sendiri_bisa_diimpor_tanpa_satu_pun_penolakan(): void
    {
        /*
         * Uji yang menjaga templat dan pembacanya tetap sepakat. Templat yang isinya ditolak
         * pembacanya sendiri adalah cara tercepat membuat orang berhenti memercayai fitur ini
         * — dan itu bisa terjadi hanya dengan satu nama kolom yang diganti di satu tempat.
         */
        $hasil = $this->aksi()->simpan(ImporProdukAction::templat());

        $this->assertSame(0, $hasil['ditolak']);
        $this->assertSame(3, $hasil['baru']);
    }
}

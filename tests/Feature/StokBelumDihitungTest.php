<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Stok\Opname;
use App\Livewire\Pages\Owner\Stok\Stok as LayarStok;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Status "belum dihitung" dibedakan dari "habis".
 *
 * Lahir dari temuan QA: barang yang belum pernah punya baris `stocks` diberi status
 * 'habis', sama dengan barang yang sudah dihitung dan memang nol. Warung kelontong yang
 * baru memasukkan 300 barang jadi melihat 300 lencana merah "Habis".
 *
 * Kenapa itu merugikan, dan bukan sekadar kurang enak dilihat: hari kedua pemiliknya
 * belajar mengabaikan warna merah, dan hari barang pertama benar-benar kosong,
 * peringatannya sudah tidak berarti apa-apa. Biaya arah sebaliknya justru hampir nol —
 * baris `stocks` tercipta sendiri lewat SiapkanBarisStokAction begitu ada penjualan
 * pertama atau ambang disetel, jadi jendela "benar-benar habis tapi tidak pernah merah"
 * tepat sama dengan "aplikasi belum punya satu pun fakta tentang barang itu". Di keadaan
 * itu tindakan yang benar sama-sama: hitung dulu, jangan belanja dulu.
 *
 * Batas yang dipakai di seluruh uji ini: **status mengikuti ada-tidaknya ANGKA, tindakan
 * mengikuti ada-tidaknya HARAPAN pemilik (ambang).**
 */
class StokBelumDihitungTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Belum Dihitung');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@belumdihitung.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function buatProduk(string $nama, array $atribut = []): Product
    {
        return Product::create(array_merge([
            'nama_produk' => $nama,
            'harga_default' => 5000,
            'satuan' => Satuan::Pcs,
        ], $atribut));
    }

    /** @return array<string, mixed> */
    private function barisUntuk(string $nama, ?string $outletId = null): array
    {
        $komponen = Livewire::actingAs($this->owner)->test(LayarStok::class);

        if ($outletId !== null) {
            $komponen->set('outletId', $outletId);
        }

        $baris = collect($komponen->viewData('daftar')->items())->firstWhere('nama', $nama);

        $this->assertNotNull($baris, "baris '{$nama}' tidak ada di daftar");

        return $baris;
    }

    /* ── Status ──────────────────────────────────────────────────────────── */

    public function test_barang_tanpa_baris_stok_berstatus_belum_dihitung(): void
    {
        $this->buatProduk('Sabun Colek');

        $baris = $this->barisUntuk('Sabun Colek');

        $this->assertFalse($baris['punya_baris']);
        $this->assertSame('belum_dihitung', $baris['status']);
    }

    /**
     * Pembanding yang WAJIB ada.
     *
     * Tanpa uji ini, "perbaikan" yang membuat SEMUA nol jadi 'belum_dihitung' akan lolos —
     * dan itu menghapus satu-satunya cara aplikasi memberi tahu bahwa rak benar-benar
     * kosong. Nol yang datang dari penghitungan sungguhan adalah pernyataan, bukan
     * ketidaktahuan.
     */
    public function test_barang_bersaldo_nol_dengan_baris_tetap_habis(): void
    {
        $produk = $this->buatProduk('Kerupuk');

        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 0,
            'stok_minimum' => 0,
        ]);

        $baris = $this->barisUntuk('Kerupuk');

        $this->assertTrue($baris['punya_baris']);
        $this->assertSame('habis', $baris['status']);

        // Dan sumber aturannya sendiri tidak boleh bergeser: Stock::statusStok() menjawab
        // "diberi sebuah angka, apa statusnya", dan 0 dengan baris nyata memang habis.
        $sementara = new Stock(['jumlah_saat_ini' => 0, 'stok_minimum' => 0]);
        $this->assertSame('habis', $sementara->statusStok());
    }

    public function test_barang_minus_yang_belum_pernah_diopname_tetap_minus(): void
    {
        $produk = $this->buatProduk('Beras 5kg');

        // Baris ada (lahir dari penjualan offline yang masuk belakangan) tapi belum pernah
        // diopname. Angkanya sudah nyata, jadi 'minus' — bukan 'belum_dihitung'.
        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => -3,
            'stok_minimum' => 0,
            'opname_terakhir_pada' => null,
        ]);

        $baris = $this->barisUntuk('Beras 5kg');

        $this->assertSame('minus', $baris['status']);
        $this->assertNull($baris['opname_terakhir_pada']);
    }

    /* ── Ringkasan & saringan ────────────────────────────────────────────── */

    public function test_barang_belum_dihitung_tidak_ikut_ringkasan_dan_filter_habis(): void
    {
        $this->buatProduk('Belum Pernah Dihitung');

        $kosong = $this->buatProduk('Sudah Dihitung Kosong');
        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $kosong->getKey(),
            'jumlah_saat_ini' => 0,
            'stok_minimum' => 0,
        ]);

        $komponen = Livewire::actingAs($this->owner)->test(LayarStok::class);
        $ringkasan = $komponen->viewData('ringkasan');

        $this->assertSame(1, $ringkasan['habis'], 'hanya yang sudah dihitung dan nol');
        $this->assertSame(1, $ringkasan['belum_dihitung']);

        $tersaring = collect($komponen->set('status', 'habis')->viewData('daftar')->items());

        $this->assertSame(['Sudah Dihitung Kosong'], $tersaring->pluck('nama')->all(),
            'saringan habis tidak boleh mencampur barang yang belum pernah dihitung');
    }

    public function test_saringan_belum_dihitung_membuka_daftarnya(): void
    {
        $this->buatProduk('Tanpa Angka');

        $aman = $this->buatProduk('Punya Angka');
        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $aman->getKey(),
            'jumlah_saat_ini' => 50,
            'stok_minimum' => 5,
        ]);

        $tersaring = collect(Livewire::actingAs($this->owner)->test(LayarStok::class)
            ->set('status', 'belum_dihitung')
            ->viewData('daftar')->items());

        $this->assertSame(['Tanpa Angka'], $tersaring->pluck('nama')->all());
    }

    /**
     * Chip "Semua" harus sama dengan isi tabelnya.
     *
     * Dulu angkanya dijumlahkan manual dari empat status. Status kelima membuatnya kurang
     * secara diam-diam — chip berkata empat, tabel menampilkan lima — dan chip yang tidak
     * cocok dengan isi tabel membuat orang berhenti mempercayai keduanya.
     */
    public function test_chip_semua_sama_dengan_total_daftar(): void
    {
        $this->buatProduk('Tak Berangka');

        foreach ([['Minus', -2, 0], ['Habis', 0, 0], ['Menipis', 3, 10], ['Aman', 99, 5]] as [$nama, $jumlah, $ambang]) {
            $p = $this->buatProduk($nama);
            Stock::create([
                'outlet_id' => $this->outlet->getKey(),
                'product_id' => $p->getKey(),
                'jumlah_saat_ini' => $jumlah,
                'stok_minimum' => $ambang,
            ]);
        }

        $komponen = Livewire::actingAs($this->owner)->test(LayarStok::class);
        $ringkasan = $komponen->viewData('ringkasan');

        $this->assertSame(5, $ringkasan['semua']);
        $this->assertSame($komponen->viewData('daftar')->total(), $ringkasan['semua']);

        // Kelima keadaan hadir masing-masing sekali — kalau salah satu bocor ke status
        // lain, jumlah totalnya tetap 5 dan hanya perbandingan per status yang menangkapnya.
        foreach (['minus', 'habis', 'menipis', 'aman', 'belum_dihitung'] as $status) {
            $this->assertSame(1, $ringkasan[$status], "ringkasan {$status}");
        }
    }

    /* ── Harus belanja ───────────────────────────────────────────────────── */

    /**
     * PENJAGA terhadap kemunduran senyap.
     *
     * Siapa pun yang nanti "menyederhanakan" harusBelanja() kembali menjadi
     * `status !== 'aman'` akan membanjiri lagi daftar belanja dengan barang yang belum
     * pernah dihitung — tanpa satu pun uji lain gagal. Blok itu cuma punya sembilan slot
     * kartu dan diurut `sistem − minimum` menaik, sementara barang belum-dihitung selalu
     * bernilai 0 pada kunci itu, jadi ia menyita slot paling depan dan menyingkirkan barang
     * yang benar-benar kurang.
     */
    public function test_barang_belum_dihitung_keluar_dari_harus_belanja(): void
    {
        $this->buatProduk('Belum Dihitung');

        $menipis = $this->buatProduk('Menipis Betul');
        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $menipis->getKey(),
            'jumlah_saat_ini' => 2,
            'stok_minimum' => 10,
        ]);

        $belanja = collect(Livewire::actingAs($this->owner)->test(LayarStok::class)
            ->viewData('harusBelanja'));

        $this->assertSame(['Menipis Betul'], $belanja->pluck('nama')->all(),
            'barang tanpa angka tidak bisa dibelanjakan — tindakannya menghitung, bukan membeli');
    }

    public function test_belum_dihitung_tidak_pernah_menyebut_jumlah_belanja(): void
    {
        // isi_per_satuan diisi supaya jelas: bukan konversinya yang membuat `beli` null,
        // melainkan tidak adanya angka untuk dikonversi.
        $this->buatProduk('Teh Kotak Dus', [
            'satuan' => Satuan::Pcs,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 24,
        ]);

        $baris = $this->barisUntuk('Teh Kotak Dus');

        $this->assertSame('belum_dihitung', $baris['status']);
        $this->assertSame(0.0, $baris['kekurangan']);
        $this->assertNull($baris['beli']);
    }

    /* ── Bahan baku (warteg) ─────────────────────────────────────────────── */

    /**
     * Warteg terkena lewat bahan baku, bukan produk.
     *
     * RawMaterial juga tidak punya baris `stocks` sampai diopname atau diberi ambang, jadi
     * ringkasan yang hanya menghitung produk akan berkata "0 belum dihitung" di dapur yang
     * belum satu pun bahannya dihitung.
     */
    public function test_bahan_baku_tanpa_baris_juga_belum_dihitung(): void
    {
        RawMaterial::create(['nama' => 'Cabai Merah', 'satuan' => Satuan::Kg]);

        $komponen = Livewire::actingAs($this->owner)->test(LayarStok::class);
        $baris = collect($komponen->viewData('daftar')->items())->firstWhere('nama', 'Cabai Merah');

        $this->assertNotNull($baris, 'bahan baku harus ikut di daftar stok');
        $this->assertSame('belum_dihitung', $baris['status']);
        $this->assertSame(1, $komponen->viewData('ringkasan')['belum_dihitung']);
    }

    /* ── Per outlet, bukan per tenant ────────────────────────────────────── */

    public function test_status_per_outlet_bukan_per_tenant(): void
    {
        $outletB = $this->buatOutlet($this->tenant, 'Outlet Baru');
        $produk = $this->buatProduk('Minyak Goreng');

        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 40,
            'stok_minimum' => 5,
        ]);

        $this->assertSame('aman', $this->barisUntuk('Minyak Goreng', $this->outlet->getKey())['status']);
        $this->assertSame('belum_dihitung', $this->barisUntuk('Minyak Goreng', $outletB->getKey())['status'],
            'outlet baru dibuka: barang yang sama belum pernah dihitung DI SITU');
    }

    /* ── Yang benar-benar terbaca di halaman ─────────────────────────────── */

    /**
     * Diperiksa dari HTML TERENDER, bukan dari nilai $baris['status'].
     *
     * Ini satu-satunya uji yang bisa menangkap risiko paling berbahaya dari perubahan ini:
     * `match` di Blade berakhir dengan `default`, jadi status yang lupa didaftarkan tampil
     * hijau "Aman" — 300 barang yang belum pernah dihitung dinyatakan aman. Uji yang hanya
     * memeriksa nilai status akan tetap hijau sementara layarnya berbohong.
     */
    public function test_lencana_belum_dihitung_bertulisan_indonesia_dan_bukan_aman(): void
    {
        $this->buatProduk('Sabun Cair');

        $html = Livewire::actingAs($this->owner)->test(LayarStok::class)->html();

        /*
         * Dihitung KEMUNCULANNYA, bukan sekadar ada-tidaknya.
         *
         * Dua percobaan sebelumnya tidak membuktikan apa pun, dan sebabnya sama: kata
         * "Aman", "Habis", dan "Belum dihitung" SELALU ada di halaman ini sebagai label
         * chip saringan. Jadi assertDontSee('Aman') mustahil lulus, dan
         * assertSee('Belum dihitung') tetap lulus walau lencana barisnya salah — sudah
         * dibuktikan dengan menghapus arm-nya: ujinya tetap hijau.
         *
         * Memeriksa warna lencana juga tidak cukup: $warnaStatus jatuh ke 'netral', jadi
         * status yang lupa didaftarkan tampil netral tapi BERTULISAN "Aman" — salah pada
         * teksnya, bukan pada warnanya.
         *
         * Angkanya yang membedakan. Satu barang di daftar, dua tata letak dirender (kartu
         * ponsel + tabel), jadi: "Aman" hanya boleh muncul SEKALI (chip saringan itu
         * sendiri), dan "Belum dihitung" harus muncul lebih dari sekali (chip + lencana).
         * Kalau arm-nya dihapus, angkanya bertukar — 3 dan 1 — dan uji ini merah.
         */
        $this->assertSame(1, substr_count($html, 'Aman'),
            '"Aman" hanya boleh muncul sebagai chip saringan; kemunculan kedua berarti ada '
            .'baris yang dinyatakan aman padahal belum pernah dihitung');

        $this->assertGreaterThan(1, substr_count($html, 'Belum dihitung'),
            'selain chip saringan, lencana barisnya juga harus bertulisan "Belum dihitung"');

        $this->assertStringNotContainsString('bg-merah/10', $html,
            'dan tidak boleh ada lencana merah "Habis" — itu cacat aslinya');
    }

    /**
     * Spanduk agregat menggantikan kartu belanja — dan harus menyediakan jalannya.
     *
     * Peringatan tanpa jalan keluar hanya memberi tahu ada pekerjaan tanpa memberi cara
     * mengerjakannya; itu berakhir diabaikan.
     */
    public function test_spanduk_menyebut_jumlah_dan_menautkan_ke_lembar_opname(): void
    {
        $this->buatProduk('Garam');
        $this->buatProduk('Gula Batu');

        $halaman = Livewire::actingAs($this->owner)->test(LayarStok::class);

        $halaman->assertSee('2 barang belum pernah dihitung');
        $halaman->assertSee('Hitung fisik');

        // Tautannya diperiksa atas HTML mentah, bukan lewat assertSee(route(...)): route()
        // menghasilkan '&' sementara Blade menuliskannya '&amp;', jadi perbandingan apa
        // adanya selalu gagal walau tautannya benar.
        $html = $halaman->html();

        $this->assertStringContainsString(route('owner.stok.opname'), $html);
        $this->assertStringContainsString('status=belum_pernah', $html,
            'tautannya harus langsung membuka daftar yang belum pernah dihitung, bukan lembar kosong');
        $this->assertStringContainsString('outlet='.$this->outlet->getKey(), $html,
            'dan harus membawa outlet yang sedang dilihat — opname selalu per outlet');
    }

    /** Spanduk tidak boleh tetap muncul saat pemilik SUDAH membuka daftarnya. */
    public function test_spanduk_hilang_saat_saringannya_sedang_aktif(): void
    {
        $this->buatProduk('Merica');

        Livewire::actingAs($this->owner)->test(LayarStok::class)
            ->set('status', 'belum_dihitung')
            ->assertDontSee('belum pernah dihitung di outlet ini');
    }

    /* ── Pintu ke pekerjaannya tidak boleh hilang ────────────────────────── */

    /**
     * Barang belum-dihitung keluar dari saringan 'habis' di layar stok. Kalau pintunya di
     * lembar opname juga hilang, ia jadi pekerjaan yang tidak punya jalan masuk sama sekali
     * — dan pekerjaan tanpa pintu tidak akan dikerjakan.
     */
    public function test_lembar_opname_belum_pernah_masih_memuat_barang_belum_dihitung(): void
    {
        $this->buatProduk('Belum Pernah Sama Sekali');

        $baris = collect(Livewire::actingAs($this->owner)->test(Opname::class)
            ->set('status', 'belum_pernah')
            ->viewData('daftar')->items());

        $this->assertContains('Belum Pernah Sama Sekali', $baris->pluck('nama')->all());
    }
}

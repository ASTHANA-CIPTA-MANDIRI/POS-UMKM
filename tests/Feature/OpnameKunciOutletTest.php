<?php

namespace Tests\Feature;

use App\Actions\Stock\AdjustStockAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Enums\AlasanOpname;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Stok\Opname;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Lembar opname terkunci ke cabang tempat angkanya DIHITUNG.
 *
 * DUA CACAT NYATA yang dijaga berkas ini, keduanya lolos dari seluruh uji stok yang sudah
 * ada karena keduanya menghasilkan ANGKA YANG TERLIHAT BENAR:
 *
 * CACAT A — hasil hitung masuk ke cabang yang salah. Kunci baris ($fisik) adalah
 * product_id/raw_material_id, dan barang bersifat TENANT: kunci yang sama ada di semua
 * cabang. Dulu updatedOutletId tidak ada dan `updated()` hanya memanggil resetPage() saat
 * outletId berubah — angkanya dibiarkan. Reproduksi: Beras A=100, B=20; ketik 50 di A,
 * ganti dropdown ke B, Simpan ⇒ B menjadi 50, mutasi +30 di cabang yang salah, A tidak
 * tersentuh. Lebih jahat lagi, catatan mutasinya berbunyi "Saldo bergerak selama
 * penghitungan: layar menunjukkan 100, saldo saat disimpan 20" — 100 itu saldo Cabang A.
 * Mutasi salah-cabang datang dengan alasan yang terbaca WAJAR.
 *
 * CACAT B — catatan audit karangan tanpa pemiliknya melakukan kesalahan apa pun. Buka
 * Cabang A (hanya MELIHAT, tidak mengetik), pindah ke Cabang B, hitung di B, Simpan.
 * rekamAngkaTerbaca() mengisi $sistemSaatDibuka saat RENDER, jadi saldo Cabang A sudah
 * masuk sebelum satu angka pun diketik, dan `??=` di updatedFisik() mempertahankannya.
 * Saldo B-nya benar — tidak ada satu uji stok pun yang gagal — tapi kartu stok B memuat
 * "layar menunjukkan 100" yang tidak pernah terjadi. Enam bulan kemudian keterangan itu
 * dipakai memutuskan siapa yang salah.
 */
class OpnameKunciOutletTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $cabangA;

    private Outlet $cabangB;

    private User $owner;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Dua Cabang');

        // Nama diurutkan supaya outletBawaan() (orderBy outlet_name) selalu memilih A —
        // lembar yang terbuka di cabang yang tidak pasti membuat ujinya bercerita lain
        // setiap kali dijalankan.
        $this->cabangA = $this->buatOutlet($this->tenant, 'Cabang A Pusat');
        $this->cabangB = $this->buatOutlet($this->tenant, 'Cabang B Ruko');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Dua Cabang',
            'email' => 'owner@duacabang.test',
            'password' => 'rahasia123',
        ]);

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir Cabang A',
            'username' => 'kasirduacabang',
            'pin_hash' => '123456',
            'outlet_id' => $this->cabangA->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── CACAT A: angka tidak boleh berpindah cabang ─────────────────────── */

    /**
     * CACAT A, langkah pertamanya: dropdown outlet diganti saat sudah ada angka terisi.
     *
     * Yang benar bukan "pindah lalu bawa angkanya" dan bukan juga "pindah lalu buang
     * angkanya diam-diam" — dua-duanya menghilangkan pekerjaan atau memindahkannya ke
     * cabang yang salah. Lembarnya TETAP di cabang tempat angkanya dihitung, dan
     * pemiliknya yang memutuskan lewat blok peringatan.
     */
    public function test_ganti_cabang_saat_ada_angka_terisi_tidak_memindahkan_lembar(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletTerkunci'), 'lembar kosong tidak mengunci apa pun');

        $komponen->set('fisik.'.$beras->getKey(), 50);

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'),
            'angka pertama yang diketik mengunci lembar ke cabang tempat ia dihitung');

        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'),
            'pilihan outlet dikembalikan: layar tidak boleh menampilkan cabang yang berbeda dari cabang tempat angkanya akan disimpan');
        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletDiminta'),
            'cabang yang tadi diminta disimpan supaya pemiliknya bisa memilih: buang lalu pindah, atau tetap di sini');
        $this->assertSame('50', (string) $komponen->get('fisik.'.$beras->getKey()),
            'hasil hitung tidak boleh hilang karena dropdown tersentuh');
        $this->assertSame($this->cabangA->getKey(), $komponen->viewData('outletDipakai'));
    }

    /**
     * CACAT A, akibatnya di database — reproduksi persis yang dilaporkan.
     *
     * Beras A=100, B=20. Ketik 50 di A, ganti ke B, Simpan. Dulu: B menjadi 50 dan A tidak
     * tersentuh. Sekarang: 50 masuk ke A, dan B tidak bergerak satu pun.
     */
    public function test_hasil_hitung_selalu_masuk_ke_cabang_tempat_angkanya_dihitung(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);
        $stokB = $this->buatStok($this->cabangB, $beras, 20);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50)
            ->set('alasan.'.$beras->getKey(), AlasanOpname::Hilang->value)
            ->set('outletId', $this->cabangB->getKey())
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::sole();

        $this->assertSame($stokA->getKey(), $mutasi->stock_id,
            'mutasinya menempel di baris stok Cabang A — tempat angkanya dihitung');
        $this->assertEqualsWithDelta(-50.0, (float) $mutasi->jumlah, 0.001, 'selisihnya 50 − 100');
        $this->assertEqualsWithDelta(50.0, (float) $stokA->fresh()->jumlah_saat_ini, 0.001);

        $this->assertEqualsWithDelta(20.0, (float) $stokB->fresh()->jumlah_saat_ini, 0.001,
            'Cabang B tidak pernah dihitung, jadi saldonya tidak boleh bergerak satu pun');
        $this->assertNull($stokB->fresh()->opname_terakhir_pada,
            'Cabang B tidak boleh ditandai sudah dihitung');

        // Dan catatannya tidak mengarang pergerakan: saldo A tidak bergerak selama
        // penghitungan, jadi kalimat "Saldo bergerak selama penghitungan" tidak boleh ada.
        $this->assertStringContainsString('sistem 100', (string) $mutasi->catatan);
        $this->assertStringNotContainsString('layar menunjukkan', (string) $mutasi->catatan);
    }

    /**
     * UJI KEAMANAN: penolakan di simpan() tetap bekerja walau hook UI dilewati sama sekali.
     *
     * Lapisan ini wajib ada terpisah dari updatedOutletId(), karena outletId ber-#[Url(as:
     * 'outlet')] — nilainya juga berubah dari tombol Back/Forward peramban (popstate), bukan
     * hanya dari <select>. Keadaannya di sini disusun lewat parameter mount (dijalankan
     * server, jadi hook pembaruan properti tidak pernah dipanggil), meniru muatan yang tiba
     * dengan outletId sudah berbeda dari $outletTerkunci.
     *
     * Yang WAJIB terjadi: MENOLAK. Bukan "menyimpan diam-diam ke outlet terkunci" —
     * menulis ke Cabang A sementara layar menampilkan Cabang B adalah rasa lain dari
     * penyakit yang sama, yaitu mutasi yang tidak disaksikan pemiliknya.
     */
    public function test_simpan_menolak_kalau_layar_menampilkan_cabang_lain_walau_hook_ui_dilewati(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);
        $stokB = $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class, [
            'outletId' => $this->cabangB->getKey(),
            'outletTerkunci' => $this->cabangA->getKey(),
            'fisik' => [$beras->getKey() => '50'],
            // Alasannya ikut diisi dengan sengaja: tanpa itu, lembar ini akan tertahan
            // validasi biasa ("selisih wajib punya alasan") dan penolakan outlet tidak
            // terbukti apa pun — ujinya lulus karena alasan yang salah. Dengan alasan
            // terisi, lembar ini benar-benar SIAP disimpan, jadi satu-satunya yang menahan
            // penulisan ke Cabang B adalah penjagaan yang sedang diuji.
            'alasan' => [$beras->getKey() => AlasanOpname::Hilang->value],
        ]);

        $komponen->call('simpan')->assertHasNoErrors();

        $this->assertSame(0, StockMovement::count(), 'tidak satu baris pun boleh ditulis');
        $this->assertEqualsWithDelta(100.0, (float) $stokA->fresh()->jumlah_saat_ini, 0.001,
            'menolak berarti Cabang A juga tidak disentuh — pemiliknya tidak sedang melihat cabang itu');
        $this->assertEqualsWithDelta(20.0, (float) $stokB->fresh()->jumlah_saat_ini, 0.001);

        $this->assertSame('50', (string) $komponen->get('fisik.'.$beras->getKey()),
            'penolakan tidak boleh menghapus satu angka pun — itu setengah jam penghitungan');
        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletDiminta'));
        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'),
            'layar dikembalikan ke cabang tempat angkanya dihitung');

        /*
         * Peringatannya TIDAK boleh dititipkan ke addError('outletId', …).
         *
         * Blok ringkasan galat di Blade memasangkan kunci galat dengan nama barang lewat
         * str($kunci)->after('.'), dan Str::after mengembalikan SELURUH string kalau titiknya
         * tidak ada — jadi kunci tanpa titik seperti 'outletId' tampil sebagai
         * "Baris lain: …". Itu kemunduran yang persis pernah diperbaiki di berkas ini:
         * pemilik diberi tahu ada yang salah tanpa diberi tahu barang apa, yang sama saja
         * dengan tidak diberi tahu.
         */
        $komponen->assertDontSee('Baris lain');
    }

    /**
     * $outletTerkunci adalah penentu tujuan penyimpanan, jadi ia tidak boleh bisa diubah
     * dari klien — kalau bisa, seluruh penjagaan di simpan() tinggal dilepas dari muatan
     * permintaan. Pola yang sama dengan penjaga $sistemSaatDibuka di OpnameQaTambahanTest.
     */
    public function test_outlet_terkunci_tidak_bisa_diubah_dari_klien(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50);

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'));

        // Server MENOLAK pembaruannya, bukan menerimanya lalu mengabaikan: penolakan yang
        // terlihat lebih baik daripada nilai yang diam-diam dibuang.
        try {
            $komponen->set('outletTerkunci', $this->cabangB->getKey());
            $this->fail('outletTerkunci seharusnya terkunci dari klien');
        } catch (CannotUpdateLockedPropertyException) {
            // inilah yang diharapkan
        }
    }

    /* ── CACAT B: catatan audit tidak boleh dikarang ─────────────────────── */

    /**
     * CACAT B, dan ini SATU-SATUNYA uji yang menangkapnya.
     *
     * Pemilik hanya MELIHAT Cabang A — tidak mengetik satu angka pun — lalu pindah ke
     * Cabang B dan menghitung di sana. Karena $sistemSaatDibuka diisi saat baris DIRENDER,
     * saldo Cabang A (100) sudah masuk sebelum ada angka apa pun, dan `??=` di
     * updatedFisik() mempertahankannya. Saldo B-nya tetap benar (selisih selalu dihitung
     * terhadap saldo saat simpan), jadi tidak ada satu pun uji stok yang gagal — yang rusak
     * adalah JEJAK AUDITNYA: kartu stok Cabang B memuat "layar menunjukkan 100" untuk layar
     * yang tidak pernah menunjukkan 100 kepada siapa pun.
     *
     * Penutupnya: updatedOutletId() membersihkan $sistemSaatDibuka pada perpindahan yang
     * diizinkan. Kalau pembersihan itu dihapus, uji ini harus MERAH.
     */
    public function test_melihat_cabang_lain_dulu_tidak_mencemari_catatan_kartu_stok(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);
        $stokB = $this->buatStok($this->cabangB, $beras, 20);

        // Cabang A dibuka dan DIRENDER — dilihat saja, tidak diketik.
        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        $this->assertEqualsWithDelta(
            100.0,
            (float) ($komponen->get('sistemSaatDibuka')[$beras->getKey()] ?? 0),
            0.001,
            'saldo Cabang A memang terekam saat dirender — dari sinilah pencemarannya berasal');

        // Pindah ke Cabang B. Lembarnya masih kosong, jadi perpindahannya diizinkan.
        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));

        // Baru sekarang dihitung, di Cabang B.
        $komponen
            ->set('fisik.'.$beras->getKey(), 15)
            ->set('alasan.'.$beras->getKey(), AlasanOpname::Rusak->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::sole();

        $this->assertSame($stokB->getKey(), $mutasi->stock_id);
        $this->assertEqualsWithDelta(-5.0, (float) $mutasi->jumlah, 0.001, 'selisihnya 15 − 20');
        $this->assertEqualsWithDelta(15.0, (float) $stokB->fresh()->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $stokA->fresh()->jumlah_saat_ini, 0.001);

        $catatan = (string) $mutasi->catatan;

        $this->assertStringNotContainsString('layar menunjukkan', $catatan,
            'saldo Cabang B tidak bergerak selama penghitungan, jadi tidak ada pergerakan yang boleh diceritakan');
        $this->assertStringNotContainsString('100', $catatan,
            'saldo Cabang A tidak boleh muncul di kartu stok Cabang B — layar B tidak pernah menunjukkannya');
    }

    /**
     * PAGAR MUNDUR untuk uji di atas: membersihkan $sistemSaatDibuka tidak boleh mematikan
     * gunanya.
     *
     * Kalau pembersihannya dipasang terlalu luas (mis. di setiap render, atau di setiap
     * pembaruan properti apa pun), keterangan yang membedakan "penghitungnya salah" dari
     * "barangnya terjual sesudah dihitung" hilang tanpa jejak — cacatnya berpindah, bukan
     * hilang. Di sini saldonya benar-benar bergerak, di outlet yang SAMA, dan kedua angka
     * wajib tetap tercatat.
     */
    public function test_pergerakan_saldo_asli_tetap_tercatat_di_catatan(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50)
            ->set('alasan.'.$beras->getKey(), AlasanOpname::Hilang->value);

        // Kasir menjual 30 kg di Cabang A saat lembar masih terbuka → saldo turun ke 70.
        app(AdjustStockAction::class)->execute(
            $stokA,
            StockMovementType::Keluar,
            -30,
            olehUserId: $this->kasir->getKey(),
        );

        $komponen->call('simpan')->assertHasNoErrors();

        $mutasi = StockMovement::query()->where('tipe', StockMovementType::Opname)->sole();

        $this->assertEqualsWithDelta(-20.0, (float) $mutasi->jumlah, 0.001,
            'selisihnya 50 − 70 (saldo saat simpan), bukan 50 − 100');
        $this->assertStringContainsString('layar menunjukkan 100', (string) $mutasi->catatan);
        $this->assertStringContainsString('saldo saat disimpan 70', (string) $mutasi->catatan);
    }

    /* ── Jalan keluar bagi pemiliknya ────────────────────────────────────── */

    /**
     * Satu-satunya jalur pindah yang MEMBUANG hasil hitung, dan ia harus disebut namanya —
     * bukan efek samping dari menyentuh sebuah dropdown.
     *
     * Membuang berarti benar-benar tidak menulis apa pun: tidak ada mutasi, dan tidak ada
     * saldo yang bergerak di cabang mana pun.
     */
    public function test_membuang_hitungan_memindahkan_lembar_dan_tidak_menulis_apa_pun(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);
        $stokB = $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50)
            ->set('alasan.'.$beras->getKey(), AlasanOpname::Hilang->value)
            ->set('outletId', $this->cabangB->getKey());

        $komponen->call('pindahOutlet', $this->cabangB->getKey());

        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletTerkunci'), 'lembar baru tidak terkunci ke mana pun');
        $this->assertNull($komponen->get('outletDiminta'), 'peringatannya sudah dijawab, jadi harus hilang');
        $this->assertSame([], $komponen->get('fisik'));
        $this->assertSame([], $komponen->get('alasan'));
        // Jejak angka layar cabang LAMA ikut dibuang — kalau tidak, ia akan mencemari catatan
        // cabang baru (lihat test_melihat_cabang_lain_dulu_...). Yang tersisa di sini adalah
        // angka Cabang B yang baru saja dirender pada permintaan yang sama, dan itu memang
        // angka yang sungguh tampil di layar.
        $this->assertEqualsWithDelta(
            20.0,
            (float) ($komponen->get('sistemSaatDibuka')[$beras->getKey()] ?? 0),
            0.001,
            'yang terekam harus saldo cabang baru (20), bukan saldo cabang lama (100)');
        $this->assertFalse($komponen->get('sebagianTersimpan'));
        $komponen->assertDispatched('toast');

        $this->assertSame(0, StockMovement::count());
        $this->assertEqualsWithDelta(100.0, (float) $stokA->fresh()->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $stokB->fresh()->jumlah_saat_ini, 0.001);
    }

    /**
     * Kunci dilepas begitu lembarnya benar-benar habis tersimpan.
     *
     * Kalau tidak, pemilik yang sudah selesai menghitung Cabang A akan ditolak saat pindah
     * ke Cabang B — ditolak untuk melindungi pekerjaan yang sudah tidak ada lagi, dan
     * satu-satunya jalan keluarnya adalah tombol yang berbunyi "buang hasil hitung".
     */
    public function test_kunci_lepas_setelah_seluruh_lembar_tersimpan(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50)
            ->set('alasan.'.$beras->getKey(), AlasanOpname::Hilang->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame([], $komponen->get('fisik'));
        $this->assertNull($komponen->get('outletTerkunci'));

        // Dan pindah cabang langsung diizinkan, tanpa peringatan apa pun.
        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletDiminta'));
    }

    /**
     * Baris yang GAGAL menahan kunci di cabang tempat angkanya dihitung — dan itu benar:
     * angka yang masih tertinggal di kolom itu masih milik cabang tersebut.
     *
     * Kegagalannya dibuat dengan mekanisme yang sama seperti OpnameQaTambahanTest: penjualan
     * offline disisipkan tepat setelah lembar membaca sistem = 10 lewat
     * SusunBarisStokAction, jadi saat CatatOpnameAction mengunci barisnya saldonya sudah
     * bergerak dan alasan (yang tidak pernah diminta ke pemilik karena syaratnya belum
     * terlihat saat ia mengetik) masih kosong.
     */
    public function test_baris_yang_gagal_menahan_kunci_di_cabang_tempat_dihitung(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $stokKopi = $this->buatStok($this->cabangA, $kopi, 5);

        $gula = $this->buatProduk('Gula Pasir');
        $stokGula = $this->buatStok($this->cabangA, $gula, 10);

        $racy = new class($this->kasir->getKey(), $stokGula) extends SusunBarisStokAction
        {
            private bool $sudahDipicu = false;

            public function __construct(
                private string $kasirId,
                private Stock $stokRacy,
            ) {}

            public function execute(string $outletId, string $jenis = 'semua', string $cari = ''): Collection
            {
                $baris = parent::execute($outletId, $jenis, $cari);

                if (! $this->sudahDipicu) {
                    $this->sudahDipicu = true;

                    app(AdjustStockAction::class)->execute(
                        $this->stokRacy,
                        StockMovementType::Keluar,
                        -4,
                        olehUserId: $this->kasirId,
                    );
                }

                return $baris;
            }
        };

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$kopi->getKey(), 3)
            ->set('alasan.'.$kopi->getKey(), AlasanOpname::Rusak->value)
            ->set('fisik.'.$gula->getKey(), 10);

        $this->app->instance(SusunBarisStokAction::class, $racy);

        $komponen->call('simpan')->assertHasErrors('fisik.'.$gula->getKey());

        $this->assertEqualsWithDelta(3.0, (float) $stokKopi->fresh()->jumlah_saat_ini, 0.001);
        $this->assertSame('10', (string) $komponen->get('fisik.'.$gula->getKey()),
            'baris yang gagal dibiarkan terisi supaya bisa dicoba lagi tanpa dihitung ulang');

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'),
            'masih ada angka Cabang A di lembar ini, jadi kuncinya belum boleh lepas');

        // Dan pergantian cabang masih ditolak, karena angka Gula itu masih milik Cabang A.
        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'));
        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletDiminta'));
    }

    /**
     * Mengosongkan baris terakhir membebaskan kunci.
     *
     * Kunci yang tidak melindungi apa pun tetap menghalangi, dan halangan tanpa alasan
     * mengajari pemiliknya bahwa peringatannya boleh diabaikan.
     */
    public function test_mengosongkan_baris_terakhir_membebaskan_kunci(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50);

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'));

        $komponen->set('fisik.'.$beras->getKey(), '');

        $this->assertSame(0, $komponen->viewData('jumlahTerisi'));
        $this->assertNull($komponen->get('outletTerkunci'));

        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletDiminta'));
    }

    /**
     * Lembar yang masih kosong bebas pindah cabang, dan itu sebabnya kuncinya TIDAK disetel
     * di render().
     *
     * Pemilik yang salah memilih cabang di dropdown belum kehilangan apa pun. Mengunci
     * lembar hanya karena halamannya dibuka akan menjebaknya: satu-satunya jalan keluarnya
     * adalah tombol yang berbunyi "buang hasil hitung" untuk pekerjaan yang belum ada.
     */
    public function test_lembar_kosong_bebas_pindah_cabang(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));
        $this->assertNull($komponen->get('outletTerkunci'));
        $this->assertNull($komponen->get('outletDiminta'));
        $this->assertSame($this->cabangB->getKey(), $komponen->viewData('outletDipakai'));

        $baris = collect($komponen->viewData('daftar')->items())->firstWhere('kunci', $beras->getKey());

        $this->assertEqualsWithDelta(20.0, (float) $baris['sistem'], 0.001,
            'angka yang tampil harus milik cabang yang baru dipilih');
    }

    /**
     * Blok peringatan tidak boleh menyebut nama outlet tenant lain.
     *
     * $outletDiminta lahir dari nilai yang dikirim klien, dan jalur penolakan sengaja TIDAK
     * memanggil pemeriksa akses — menolak tidak boleh berubah menjadi 403, karena pemilik
     * yang ditolak masih harus bisa melihat lembarnya dan memilih apa yang dilakukan. Kalau
     * namanya lalu dicari langsung dengan Outlet::find(), nama usaha tenant lain tercetak di
     * layar pemilik ini. Karena itu hanya id yang ada di outletTersedia() yang diakui.
     */
    public function test_blok_peringatan_tidak_pernah_menyebut_outlet_tenant_lain(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);

        $tenantLain = $this->buatTenant('Toko Tetangga');
        $outletTetangga = $this->buatOutlet($tenantLain, 'Gudang Rahasia Tetangga');

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50);

        $komponen->set('outletId', $outletTetangga->getKey());

        $komponen->assertOk();
        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'));
        $this->assertSame($outletTetangga->getKey(), $komponen->get('outletDiminta'));

        $this->assertNull($komponen->viewData('namaOutletDiminta'),
            'id di luar outletTersedia() tidak punya nama yang boleh dicetak');
        $komponen->assertDontSee('Gudang Rahasia Tetangga');
    }

    /**
     * pindahOutlet() adalah aksi baru, jadi gerbang aksesnya diuji sendiri — bukan
     * diasumsikan mewarisi penjagaan dropdown.
     *
     * Ia satu-satunya jalur yang MEMBUANG hasil hitung, jadi urutannya penting: pemeriksaan
     * akses berjalan SEBELUM satu angka pun dibuang. Kalau dibalik, id outlet tenant lain
     * yang dikirim ke aksi ini akan menghapus setengah jam penghitungan lebih dulu, lalu
     * menampilkan 403 — kerusakan yang dilakukan justru oleh penjaganya.
     */
    public function test_pindah_outlet_ke_tenant_lain_ditolak_dan_tidak_menulis_apa_pun(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);

        $tenantLain = $this->buatTenant('Toko Tetangga Pindah');
        $outletTetangga = $this->buatOutlet($tenantLain, 'Gudang Tetangga Pindah');

        // abort_unless(403) di outletTerpakai() dirender Livewire menjadi respons 403, bukan
        // dilempar sebagai exception ke pemanggil test — sama seperti
        // OpnameQaTambahanTest::test_owner_tidak_bisa_memilih_outlet_milik_tenant_lain.
        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50)
            ->call('pindahOutlet', $outletTetangga->getKey())
            ->assertForbidden();

        $this->assertSame(0, StockMovement::count());
        $this->assertEqualsWithDelta(100.0, (float) $stokA->fresh()->jumlah_saat_ini, 0.001);
    }

    /**
     * Peran yang terkunci ke satu outlet (manager outlet, kasir) tidak boleh terpengaruh.
     *
     * Untuk peran itu outletTerpakai() mengabaikan nilai dari klien sama sekali, jadi
     * kuncinya selalu sama dengan outletnya sendiri dan tidak ada penolakan yang mungkin
     * terjadi. Ujinya ada supaya kunci lembar tidak berubah menjadi alasan baru bagi peran
     * ini untuk gagal menyimpan hasil hitungnya.
     */
    public function test_peran_terkunci_satu_outlet_tidak_terpengaruh_kunci_lembar(): void
    {
        $manager = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manager Cabang A',
            'email' => 'manager@duacabang.test',
            'password' => 'rahasia123',
            'outlet_id' => $this->cabangA->getKey(),
        ]);

        $this->assertNotNull($manager->scopedOutletId(), 'prasyarat ujinya: perannya memang terkunci satu outlet');

        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);
        $stokB = $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($manager)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50)
            ->set('alasan.'.$beras->getKey(), AlasanOpname::Hilang->value);

        $this->assertSame([], $komponen->viewData('outletTersedia'), 'dropdown outlet memang tidak dirender untuk peran ini');
        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'));

        // Nilai outletId dari klien diabaikan untuk peran ini, jadi menyetelnya tidak boleh
        // menggagalkan penyimpanan.
        $komponen->set('outletId', $this->cabangB->getKey());

        $komponen->call('simpan')->assertHasNoErrors();

        $this->assertEqualsWithDelta(50.0, (float) $stokA->fresh()->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $stokB->fresh()->jumlah_saat_ini, 0.001);
        $this->assertSame($stokA->getKey(), StockMovement::sole()->stock_id);
    }

    /**
     * Bahan baku kena persoalan yang sama, dan lewat jalur inilah warteg terkena.
     *
     * `raw_materials` bersifat tenant (bukan per outlet), jadi raw_material_id juga sama di
     * kedua cabang — kunci baris "Beras" di Cabang A cocok sempurna di Cabang B. Kalau
     * kuncinya hanya dipasang untuk produk, dapur yang menghitung bahan bakunya tetap
     * kehilangan hasil hitung ke cabang yang salah.
     */
    public function test_bahan_baku_juga_terkunci_ke_cabang_tempat_dihitung(): void
    {
        $bahan = RawMaterial::create(['nama' => 'Beras Karungan', 'satuan' => Satuan::Kg]);

        $stokA = $this->buatStok($this->cabangA, $bahan, 100);
        $stokB = $this->buatStok($this->cabangB, $bahan, 20);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$bahan->getKey(), 50)
            ->set('alasan.'.$bahan->getKey(), AlasanOpname::Hilang->value);

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'));

        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'));

        $komponen->call('simpan')->assertHasNoErrors();

        $mutasi = StockMovement::sole();

        $this->assertSame($stokA->getKey(), $mutasi->stock_id);
        $this->assertSame($bahan->getKey(), $mutasi->stock->raw_material_id);
        $this->assertEqualsWithDelta(50.0, (float) $stokA->fresh()->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $stokB->fresh()->jumlah_saat_ini, 0.001,
            'Cabang B tidak pernah dihitung — bahan bakunya tidak boleh bergerak');
    }

    /**
     * Penolakan tidak boleh memindahkan halaman.
     *
     * Dengan 10 baris per halaman, lembar 120 barang punya 12 halaman. Pemilik yang salah
     * menyentuh dropdown saat sedang di halaman 7 lalu terlempar ke halaman 1 dihukum untuk
     * kesalahan yang sudah ditolak — dan ia harus mencari kembali tempatnya di lembar yang
     * sama. Karena itu 'outletId' dikeluarkan dari daftar reset di updated(), dan
     * updatedOutletId() TIDAK memanggil resetPage() di jalur penolakan.
     */
    public function test_penolakan_pindah_cabang_tidak_memindahkan_halaman(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $produk = $this->buatProduk('Barang '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->buatStok($this->cabangA, $produk, 10);
        }

        $penanda = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $penanda, 100);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$penanda->getKey(), 50)
            ->call('gotoPage', 2);

        $this->assertSame(2, $komponen->viewData('daftar')->currentPage());

        $komponen->set('outletId', $this->cabangB->getKey());

        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletId'), 'pergantiannya memang ditolak');
        $this->assertSame(2, $komponen->viewData('daftar')->currentPage(),
            'penolakan tidak boleh melempar pemiliknya ke halaman 1');
    }

    /* ── Yang harus TERBACA di layar ─────────────────────────────────────── */

    /**
     * Kunci baris memuat outlet, dan alasannya terukur — bukan kehati-hatian.
     *
     * Barisnya memakai x-data="barisOpname(…, @js((float) $b['sistem']), …)", dan Alpine
     * menangkap `sistem` SEKALI saat elemennya dihidupkan. Kalau kuncinya hanya id barang,
     * kunci itu identik di kedua cabang: morph Livewire memakai ulang elemen yang sama saat
     * outlet berganti, Alpine tidak pernah dihidupkan ulang, dan `sistem` tetap milik cabang
     * lama — kolom Selisih dan lencana "wajib pilih alasan" lalu berbicara tentang cabang
     * yang salah, di atas angka sistem yang dirender server dengan benar.
     *
     * Karena itu uji ini memeriksa KUNCINYA, bukan angkanya: server selalu merender angka
     * yang benar, jadi tidak ada assertion atas nilai yang bisa menangkap cacat ini. Nama
     * ikatan (fisik.<kunci>) sengaja TIDAK ikut berubah — kalau ikut, seluruh nilai yang
     * sudah diketik akan hilang setiap kali cabangnya berganti.
     */
    public function test_kunci_baris_memuat_outlet_supaya_alpine_tidak_memakai_sistem_cabang_lama(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $kunci = $beras->getKey();

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        $komponen->assertSeeHtml('wire:key="baris-'.$this->cabangA->getKey().'-'.$kunci.'"');
        $komponen->assertSeeHtml('wire:key="kartu-'.$this->cabangA->getKey().'-'.$kunci.'"');

        // Lembarnya masih kosong, jadi pindah cabang diizinkan — dan kuncinya WAJIB berubah,
        // supaya Alpine menghidupkan barisnya lagi dengan sistem cabang yang baru.
        $komponen->set('outletId', $this->cabangB->getKey());

        $komponen->assertSeeHtml('wire:key="baris-'.$this->cabangB->getKey().'-'.$kunci.'"');
        $komponen->assertSeeHtml('wire:key="kartu-'.$this->cabangB->getKey().'-'.$kunci.'"');
        $komponen->assertDontSeeHtml('wire:key="baris-'.$this->cabangA->getKey().'-'.$kunci.'"');

        /*
         * Nama ikatannya tetap id barang saja: nilai yang sudah diketik tidak boleh
         * bergantung pada cabang yang sedang tampil.
         *
         * Yang diperiksa NAMA ikatannya (`fisik.<kunci>`), bukan modifiernya. Versi lama
         * memaku `wire:model.blur=` dan langsung merah ketika modifiernya diganti ke
         * `.live.debounce` — padahal ikatannya tidak berubah sama sekali dan tidak ada yang
         * rusak. Uji yang merah untuk perubahan yang tidak merusak apa pun akan dilonggarkan
         * suatu hari, dan sesudah itu ia tidak menjaga apa-apa.
         */
        $this->assertMatchesRegularExpression(
            '/wire:model[^=]*="fisik\.'.preg_quote($kunci, '/').'"/',
            $komponen->html(),
            'ikatan fisik.<kunci> harus tetap memakai id barang, apa pun modifiernya');
    }

    /**
     * Bar simpan menyebut nama cabangnya.
     *
     * Bar itu satu-satunya elemen yang SELALU terlihat (sticky), sementara dropdown outlet
     * duduk di baris saringan paling atas dan sudah keluar dari layar begitu lembarnya
     * digulir. Jadi kalimat di bar inilah pertahanan terakhir sebelum seseorang menekan
     * Simpan tanpa pernah membaca cabang mana yang sedang dihitung.
     */
    public function test_bar_simpan_menyebut_nama_cabangnya(): void
    {
        $kunci = [];

        foreach (['Beras Premium', 'Gula Pasir', 'Kopi Sachet'] as $nama) {
            $produk = $this->buatProduk($nama);
            $this->buatStok($this->cabangA, $produk, 10);
            $this->buatStok($this->cabangB, $produk, 4);
            $kunci[] = $produk->getKey();
        }

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        foreach ($kunci as $satu) {
            $komponen->set('fisik.'.$satu, 10);
        }

        $this->assertSame(3, $komponen->viewData('jumlahTerisi'));

        // Angka DAN nama cabang, di satu kalimat: salah satunya saja tidak menjawab
        // pertanyaan "berapa baris ini, dan untuk cabang mana?".
        $komponen->assertSeeHtml('>3</span> baris terisi');
        $komponen->assertSeeInOrder(['baris terisi', 'Cabang A Pusat', 'belum disimpan']);
        $komponen->assertSeeHtml('Simpan hasil hitung — Cabang A Pusat');
    }

    /**
     * Blok penolakan harus benar-benar TERBACA: judul yang menyebut cabang tempat angkanya
     * dihitung, jumlah barisnya, dan ketiga tindakan yang bernama.
     *
     * Penolakan tanpa jalan keluar sama saja dengan macet: pemilik yang memang ingin pindah
     * ke cabang lain tidak punya cara apa pun selain mengosongkan kolomnya satu per satu —
     * 12 halaman untuk lembar 120 barang. Karena itu tombol "buang lalu pindah" wajib ada,
     * dan jumlah barisnya wajib tertulis di tombolnya: itu langkah konfirmasinya.
     */
    public function test_blok_penolakan_menyebut_cabang_jumlah_baris_dan_tiga_tindakannya(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $gula = $this->buatProduk('Gula Pasir');
        $this->buatStok($this->cabangA, $gula, 10);
        $this->buatStok($this->cabangB, $gula, 5);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$beras->getKey(), 50)
            ->set('fisik.'.$gula->getKey(), 9);

        $komponen->set('outletId', $this->cabangB->getKey());

        // Judul menyebut cabang tempat angkanya DIHITUNG, bukan cabang yang tadi dipilih:
        // yang harus dijawab pemiliknya adalah "angka ini milik siapa".
        $komponen->assertSee('Lembar ini masih berisi hitungan Cabang A Pusat');

        // Kedua nama cabang ada di kalimatnya, beserta akibat menyimpannya ke cabang salah.
        $komponen->assertSee('Cabang B Ruko');
        $komponen->assertSee('stok kedua cabang jadi salah');

        // Ketiga tindakan bernama — bukan "OK"/"Batal". Angka 2 di tombol buang adalah
        // konfirmasinya, jadi ia wajib ada di teks tombolnya.
        $komponen->assertSee('Simpan hitungan Cabang A Pusat dulu');
        $komponen->assertSee('Buang 2 baris, pindah ke Cabang B Ruko');
        $komponen->assertSee('Tetap di Cabang A Pusat');

        // Dan tombolnya benar-benar terhubung ke aksinya — teks tanpa aksi hanya kelihatan
        // seperti jalan keluar.
        $komponen->assertSeeHtml('wire:click="pindahOutlet(\''.$this->cabangB->getKey().'\')"');
        $komponen->assertSeeHtml('wire:click="tetapDiOutlet"');
    }

    /**
     * Blok itu TIDAK boleh ada saat tidak ada penolakan.
     *
     * Peringatan yang selalu tampak akan dilewati mata dalam dua hari, dan sesudah itu
     * peringatan yang sungguhan ikut terlewat. Tombol "buang hasil hitung" yang nongkrong
     * di lembar kosong juga hanya menakuti tanpa sebab.
     */
    public function test_blok_penolakan_tidak_muncul_saat_lembar_kosong(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        $komponen->assertDontSee('Lembar ini masih berisi hitungan');
        $komponen->assertDontSee('pindah ke Cabang B Ruko');

        // Pindah cabang di lembar kosong diizinkan, jadi sesudahnya pun tidak ada peringatan.
        $komponen->set('outletId', $this->cabangB->getKey());

        $komponen->assertDontSee('Lembar ini masih berisi hitungan');

        // Dan sesudah dijawab "tetap di sini", blok itu harus hilang lagi — kalau tidak,
        // pemiliknya menutup peringatan lalu tetap dipandangi olehnya.
        $komponen->set('fisik.'.$beras->getKey(), 15);
        $komponen->set('outletId', $this->cabangA->getKey());

        $komponen->assertSee('Lembar ini masih berisi hitungan Cabang B Ruko');

        $komponen->call('tetapDiOutlet');

        $komponen->assertDontSee('Lembar ini masih berisi hitungan');
    }

    /* ── Pembantu ────────────────────────────────────────────────────────── */

    private function buatProduk(string $nama): Product
    {
        return Product::create([
            'nama_produk' => $nama,
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);
    }

    private function buatStok(Outlet $outlet, Product|RawMaterial $item, float $jumlah): Stock
    {
        return Stock::create([
            'outlet_id' => $outlet->getKey(),
            'product_id' => $item instanceof Product ? $item->getKey() : null,
            'raw_material_id' => $item instanceof RawMaterial ? $item->getKey() : null,
            'jumlah_saat_ini' => $jumlah,
            'stok_minimum' => 0,
        ]);
    }
}

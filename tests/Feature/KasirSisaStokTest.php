<?php

namespace Tests\Feature;

use App\Actions\Kas\BukaSesiKasAction;
use App\Actions\Kasir\SusunSisaStokAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Models\Bahan\RawMaterial;
use App\Models\Bahan\RecipeItem;
use App\Models\Kasir\Transaction;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Sisa stok di layar kasir: lencana "Habis"/"Menipis" di petak produk.
 *
 * Fitur ini lahir dari satu keluhan berujung dua: kasir menjanjikan barang yang sudah
 * habis, ATAU menolak menjual barang yang sebenarnya ada. Kedua arah itu sama-sama
 * merugikan, jadi hampir seluruh berkas ini menguji apa yang TIDAK boleh dikabarkan —
 * bukan hanya apa yang dikabarkan.
 *
 * Dua aturan keras CLAUDE.md yang membentuknya, dan keduanya diuji di sini:
 * - aturan 3: layar kasir tidak boleh bergantung jaringan → katalog tetap tersusun
 *   walau jalur stok patah, dan kegagalan stok tidak menyentuh apa pun;
 * - aturan 5: stok boleh minus, penjualan JANGAN pernah diblokir → lencana ini
 *   memberi tahu, tidak menghalangi.
 */
class KasirSisaStokTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant();
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Pusat');
        $perangkat = $this->buatPerangkat($this->tenant, $this->outlet, 'TAB-SISA-1');

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Ani Kasir',
            'username' => 'ani-sisa',
            'pin_hash' => '123456',
            'outlet_id' => $this->outlet->getKey(),
            'device_id_terikat' => $perangkat->getKey(),
        ]);
    }

    /* ── Pembantu ──────────────────────────────────────────────────────────── */

    private function buatProduk(array $atribut = []): Product
    {
        return $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create(array_merge([
            'nama_produk' => 'Barang Uji',
            'harga_default' => 5000,
            'satuan' => Satuan::Pcs,
        ], $atribut)));
    }

    private function buatStok(array $atribut): Stock
    {
        return $this->konteks()->forTenant($this->tenant->getKey(), fn () => Stock::create(array_merge([
            'outlet_id' => $this->outlet->getKey(),
        ], $atribut)));
    }

    /** @return array<string, string> */
    private function sisa(?User $sebagai = null, array $parameter = []): array
    {
        return $this->actingAs($sebagai ?? $this->kasir)
            ->getJson(route('kasir.sisa-stok', $parameter))
            ->assertOk()
            ->json('sisa');
    }

    /* ── Apa yang dikabarkan ───────────────────────────────────────────────── */

    public function test_barang_bersaldo_nol_dikabarkan_habis(): void
    {
        $produk = $this->buatProduk(['nama_produk' => 'Kerupuk']);
        $this->buatStok(['product_id' => $produk->getKey(), 'jumlah_saat_ini' => 0]);

        $this->assertSame('habis', $this->sisa()[$produk->getKey()] ?? null);
    }

    /**
     * Saldo MINUS dikabarkan sebagai "Habis", bukan "Minus".
     *
     * Bedanya nyata dan tetap dipertahankan di layar Stok — minus itu masalah
     * pencatatan yang selesai lewat hitung stok, habis itu masalah pembelian — tapi
     * tindakan kasirnya persis sama: periksa rak dulu sebelum menjanjikan. Kata
     * "Minus" tidak bisa ditindaklanjuti siapa pun yang sedang melayani pembeli.
     */
    public function test_saldo_minus_dikabarkan_habis(): void
    {
        $produk = $this->buatProduk(['nama_produk' => 'Teh Kotak']);
        $this->buatStok(['product_id' => $produk->getKey(), 'jumlah_saat_ini' => -3]);

        $this->assertSame('habis', $this->sisa()[$produk->getKey()] ?? null);
    }

    public function test_barang_di_bawah_batas_minimal_dikabarkan_menipis(): void
    {
        $produk = $this->buatProduk(['nama_produk' => 'Gula']);
        $this->buatStok([
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 2,
            'stok_minimum' => 5,
        ]);

        $this->assertSame('menipis', $this->sisa()[$produk->getKey()] ?? null);
    }

    /**
     * Barang aman TIDAK ikut dikirim sama sekali.
     *
     * Bukan penghematan byte: absennya kunci adalah cara klien memutuskan "tidak ada
     * lencana". Kalau barang aman ikut dikirim dengan nilai 'aman', maka peta kosong
     * (jaringan gagal) dan peta penuh 'aman' menjadi dua hal yang harus dibedakan
     * klien — dan hari pertama yang lupa membedakannya, seluruh petak dapat lencana.
     */
    public function test_barang_aman_tidak_ikut_dikirim(): void
    {
        $produk = $this->buatProduk(['nama_produk' => 'Beras']);
        $this->buatStok([
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 40,
            'stok_minimum' => 5,
        ]);

        $this->assertArrayNotHasKey($produk->getKey(), $this->sisa());
    }

    /**
     * Barang yang BELUM PERNAH DIHITUNG bukan barang habis.
     *
     * Cacat nyata yang dijaga di sini (sudah pernah terjadi di layar Stok, lihat
     * StokBelumDihitungTest): produk tanpa baris `stocks` punya "jumlah" null yang
     * berubah jadi 0.0 begitu di-cast, lalu keluar sebagai 'habis'. Di kasir akibatnya
     * lebih parah daripada di layar owner: warung yang baru memasukkan 300 barang
     * melihat 300 petak merah, kasirnya menolak menjual barang yang jelas-jelas ada di
     * rak, dan dalam sehari ia belajar mengabaikan warna merah sepenuhnya.
     */
    public function test_produk_tanpa_baris_stok_tidak_dikabarkan_habis(): void
    {
        $produk = $this->buatProduk(['nama_produk' => 'Barang Baru']);

        $this->assertArrayNotHasKey($produk->getKey(), $this->sisa());
    }

    /**
     * Jasa & barang yang tidak dilacak tidak punya angka stok, jadi tidak punya kabar.
     *
     * Laundry kiloan dan potong rambut tidak pernah "habis". Lencana di petaknya
     * hanya bisa salah.
     */
    public function test_produk_tanpa_lacak_stok_tidak_pernah_dikabarkan(): void
    {
        $jasa = $this->buatProduk([
            'nama_produk' => 'Laundry Kiloan',
            'satuan' => Satuan::Kg,
            'lacak_stok' => false,
        ]);

        // Bahkan kalau ada baris stok bersaldo nol yang tertinggal dari masa lalu.
        $this->buatStok(['product_id' => $jasa->getKey(), 'jumlah_saat_ini' => 0]);

        $this->assertArrayNotHasKey($jasa->getKey(), $this->sisa());
    }

    /* ── Menu berbasis resep ───────────────────────────────────────────────── */

    /**
     * Menu warteg mengikuti BAHAN BAKUNYA, bukan stok produk jadinya.
     *
     * Baris stok "Ayam Goreng" tidak pernah bergerak — yang berkurang saat terjual
     * adalah ayamnya (ApplySaleToStockAction). Membaca stok produk jadi berarti
     * SELURUH menu tampil "Habis" selamanya, dan kasir berhenti mempercayai lencana
     * apa pun di layar ini.
     */
    public function test_menu_berbasis_resep_mengikuti_bahan_bakunya(): void
    {
        [$menu, $ayam] = $this->konteks()->forTenant($this->tenant->getKey(), function () {
            $menu = Product::create([
                'nama_produk' => 'Ayam Goreng',
                'harga_default' => 15000,
                'satuan' => Satuan::Porsi,
            ]);

            $ayam = RawMaterial::create(['nama' => 'Ayam Potong', 'satuan' => Satuan::Kg]);

            RecipeItem::create([
                'product_id' => $menu->getKey(),
                'raw_material_id' => $ayam->getKey(),
                'jumlah_terpakai' => 0.2,
            ]);

            return [$menu, $ayam];
        });

        $stok = $this->buatStok(['raw_material_id' => $ayam->getKey(), 'jumlah_saat_ini' => 8]);

        // Ayam masih banyak → menunya tidak dikabarkan apa-apa.
        $this->assertArrayNotHasKey($menu->getKey(), $this->sisa());

        // Ayam habis → menunya ikut habis, walau baris stok produk jadinya tidak ada.
        $stok->update(['jumlah_saat_ini' => 0]);

        $this->assertSame('habis', $this->sisa()[$menu->getKey()] ?? null);
    }

    /** Satu bahan habis sudah cukup: menunya tidak bisa dibuat, sepenuh apa pun bahan lain. */
    public function test_bahan_terparah_menentukan_kabar_menu(): void
    {
        [$menu, $ayam, $cabai] = $this->konteks()->forTenant($this->tenant->getKey(), function () {
            $menu = Product::create([
                'nama_produk' => 'Ayam Balado',
                'harga_default' => 17000,
                'satuan' => Satuan::Porsi,
            ]);

            $ayam = RawMaterial::create(['nama' => 'Ayam', 'satuan' => Satuan::Kg]);
            $cabai = RawMaterial::create(['nama' => 'Cabai', 'satuan' => Satuan::Kg]);

            foreach ([$ayam, $cabai] as $bahan) {
                RecipeItem::create([
                    'product_id' => $menu->getKey(),
                    'raw_material_id' => $bahan->getKey(),
                    'jumlah_terpakai' => 0.1,
                ]);
            }

            return [$menu, $ayam, $cabai];
        });

        $this->buatStok(['raw_material_id' => $ayam->getKey(), 'jumlah_saat_ini' => 20]);
        $stokCabai = $this->buatStok([
            'raw_material_id' => $cabai->getKey(),
            'jumlah_saat_ini' => 0.3,
            'stok_minimum' => 1,
        ]);

        $this->assertSame('menipis', $this->sisa()[$menu->getKey()] ?? null);

        $stokCabai->update(['jumlah_saat_ini' => 0]);

        $this->assertSame('habis', $this->sisa()[$menu->getKey()] ?? null);
    }

    /**
     * Warung yang belum pernah mencatat bahan bakunya tidak melihat lencana apa pun.
     *
     * Bahan tanpa baris `stocks` DIABAIKAN, bukan dianggap habis — kalau tidak, warteg
     * yang baru memasang daftar menunya melihat seluruh menu merah pada hari pertama,
     * padahal dapurnya penuh.
     */
    public function test_menu_dengan_bahan_belum_dihitung_tidak_dikabarkan(): void
    {
        $menu = $this->konteks()->forTenant($this->tenant->getKey(), function () {
            $menu = Product::create([
                'nama_produk' => 'Soto Ayam',
                'harga_default' => 13000,
                'satuan' => Satuan::Porsi,
            ]);

            $bahan = RawMaterial::create(['nama' => 'Bihun', 'satuan' => Satuan::Kg]);

            RecipeItem::create([
                'product_id' => $menu->getKey(),
                'raw_material_id' => $bahan->getKey(),
                'jumlah_terpakai' => 0.05,
            ]);

            return $menu;
        });

        $this->assertArrayNotHasKey($menu->getKey(), $this->sisa());
    }

    /* ── Outlet & tenant ───────────────────────────────────────────────────── */

    /**
     * Angkanya mengikuti OUTLET perangkat, bukan tenant.
     *
     * Stok dicatat per outlet. Kasir cabang B yang melihat kabar gabungan (atau kabar
     * cabang A) akan menolak menjual barang yang menumpuk di raknya sendiri — dan
     * angka gabungan tidak bisa dijual maupun dihitung fisik oleh siapa pun.
     */
    public function test_kabar_mengikuti_outlet_perangkat_bukan_tenant(): void
    {
        $outletB = $this->buatOutlet($this->tenant, 'Outlet Cabang');
        $perangkatB = $this->buatPerangkat($this->tenant, $outletB, 'TAB-SISA-2');

        $kasirB = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Budi Kasir',
            'username' => 'budi-sisa',
            'pin_hash' => '123456',
            'outlet_id' => $outletB->getKey(),
            'device_id_terikat' => $perangkatB->getKey(),
        ]);

        $produk = $this->buatProduk(['nama_produk' => 'Minyak Goreng']);

        // Habis di pusat, menumpuk di cabang.
        $this->buatStok(['product_id' => $produk->getKey(), 'jumlah_saat_ini' => 0]);
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Stock::create([
            'outlet_id' => $outletB->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 30,
        ]));

        $this->assertSame('habis', $this->sisa()[$produk->getKey()] ?? null);
        $this->assertArrayNotHasKey($produk->getKey(), $this->sisa($kasirB));
    }

    /**
     * Outlet TIDAK boleh datang dari URL.
     *
     * Kalau boleh, kasir tenant lain cukup mengubah satu nilai di alamat untuk
     * mengintip keadaan gudang orang. Outlet selalu diambil dari record kasir yang
     * login, jadi parameter apa pun di URL diabaikan begitu saja.
     */
    public function test_outlet_dari_url_diabaikan_termasuk_milik_tenant_lain(): void
    {
        $tenantLain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($tenantLain, 'Outlet Sebelah');

        $produkLain = $this->konteks()->forTenant($tenantLain->getKey(), function () use ($outletLain) {
            $produk = Product::create(['nama_produk' => 'Produk Sebelah', 'harga_default' => 9000]);

            Stock::create([
                'outlet_id' => $outletLain->getKey(),
                'product_id' => $produk->getKey(),
                'jumlah_saat_ini' => 0,
            ]);

            return $produk;
        });

        $milikSendiri = $this->buatProduk(['nama_produk' => 'Kopi Sachet']);
        $this->buatStok(['product_id' => $milikSendiri->getKey(), 'jumlah_saat_ini' => 0]);

        $balasan = $this->actingAs($this->kasir)
            ->getJson(route('kasir.sisa-stok', ['outlet' => $outletLain->getKey(), 'outlet_id' => $outletLain->getKey()]))
            ->assertOk();

        $balasan->assertJsonPath('outlet_id', $this->outlet->getKey());

        $sisa = $balasan->json('sisa');

        $this->assertArrayHasKey($milikSendiri->getKey(), $sisa);
        $this->assertArrayNotHasKey($produkLain->getKey(), $sisa);
    }

    public function test_akun_tanpa_outlet_ditolak_bukan_diberi_kabar_kosong(): void
    {
        $tanpaOutlet = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir Baru',
            'username' => 'baru-sisa',
            'pin_hash' => '123456',
        ]);

        $this->actingAs($tanpaOutlet)
            ->getJson(route('kasir.sisa-stok'))
            ->assertStatus(409);
    }

    public function test_owner_tidak_bisa_membuka_endpoint_kasir(): void
    {
        $owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'email' => 'owner-sisa@uji.test',
            'password' => 'rahasia123',
        ]);

        $this->actingAs($owner)
            ->get(route('kasir.sisa-stok'))
            ->assertRedirect(route('owner.dasbor'));
    }

    /* ── Aturan 5: memberi tahu, BUKAN menghalangi ─────────────────────────── */

    /**
     * UJI TERPENTING DI BERKAS INI.
     *
     * Lencana yang bisa menghalangi penjualan lebih merugikan daripada tidak ada
     * lencana sama sekali: penjualan offline yang masuk belakangan SUDAH benar-benar
     * terjadi — barangnya keluar dari rak dan uangnya masuk laci — jadi menolaknya
     * berarti mencatat kebohongan demi angka stok yang kelihatan rapi (aturan 5
     * CLAUDE.md). Selisihnya diselesaikan lewat hitung stok, bukan dengan menolak.
     *
     * Diuji lewat endpoint sinkronisasi karena itulah SATU-SATUNYA jalur masuk
     * penjualan, online maupun offline.
     */
    public function test_penjualan_tetap_berhasil_saat_saldo_nol_dan_saat_minus(): void
    {
        $habis = $this->buatProduk(['nama_produk' => 'Rokok', 'harga_default' => 20000]);
        $minus = $this->buatProduk(['nama_produk' => 'Sabun', 'harga_default' => 5000]);

        $this->buatStok(['product_id' => $habis->getKey(), 'jumlah_saat_ini' => 0]);
        $this->buatStok(['product_id' => $minus->getKey(), 'jumlah_saat_ini' => -2]);

        // Keduanya memang sedang dikabarkan "Habis" saat ini — dan itu tidak menahan apa pun.
        $sisa = $this->sisa();
        $this->assertSame('habis', $sisa[$habis->getKey()] ?? null);
        $this->assertSame('habis', $sisa[$minus->getKey()] ?? null);

        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);

        $this->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), [
                'outlet_id' => $this->outlet->getKey(),
                'transactions' => [[
                    'id' => (string) Str::uuid(),
                    'nomor_transaksi' => 'TRX-20260805-SISA-0001',
                    'mode' => 'langsung',
                    'status' => 'lunas',
                    'subtotal' => 25000,
                    'total' => 25000,
                    'origin' => 'offline',
                    'cash_session_id' => $sesi->getKey(),
                    'waktu_transaksi' => now()->toDateTimeString(),
                    'items' => [
                        [
                            'product_id' => $habis->getKey(),
                            'nama_produk' => 'Rokok',
                            'qty' => 1,
                            'harga_satuan' => 20000,
                            'subtotal' => 20000,
                        ],
                        [
                            'product_id' => $minus->getKey(),
                            'nama_produk' => 'Sabun',
                            'qty' => 1,
                            'harga_satuan' => 5000,
                            'subtotal' => 5000,
                        ],
                    ],
                    'payments' => [[
                        'metode' => 'cash',
                        'jumlah' => 25000,
                        'jumlah_diterima' => 25000,
                        'kembalian' => 0,
                    ]],
                ]],
            ])
            ->assertOk()
            ->assertJson(['jumlah_dibuat' => 1, 'jumlah_gagal' => 0]);

        $this->assertSame(1, Transaction::count());

        // Stok jatuh lebih dalam, apa adanya. Itu yang membuat selisihnya terlihat
        // saat hitung stok — bukan penjualan yang dibuang supaya angkanya rapi.
        $this->assertEqualsWithDelta(
            -1.0,
            (float) Stock::where('product_id', $habis->getKey())->sole()->jumlah_saat_ini,
            0.001,
        );
        $this->assertEqualsWithDelta(
            -3.0,
            (float) Stock::where('product_id', $minus->getKey())->sole()->jumlah_saat_ini,
            0.001,
        );
    }

    /* ── Aturan 3: layar kasir tidak bergantung jaringan ───────────────────── */

    /**
     * Katalog tetap tersusun walau jalur sisa stok patah total.
     *
     * Ini alasan sisa stok TIDAK ditempelkan ke muatan katalog. Kalau ia ikut di sana,
     * satu kueri stok yang gagal membuat seluruh grid produk kosong — kasir kehilangan
     * seluruh layar demi lencana yang cuma keterangan tambahan.
     */
    public function test_katalog_tetap_tersusun_walau_jalur_sisa_stok_patah(): void
    {
        $this->buatProduk(['nama_produk' => 'Nasi Rames', 'harga_default' => 12000]);

        $this->app->bind(SusunSisaStokAction::class, fn () => new class(app(SusunBarisStokAction::class)) extends SusunSisaStokAction
        {
            public function execute(string $outletId): array
            {
                throw new RuntimeException('Jalur stok sengaja dipatahkan.');
            }
        });

        $produk = $this->actingAs($this->kasir)
            ->getJson(route('kasir.katalog'))
            ->assertOk()
            ->json('produk');

        $this->assertContains('Nasi Rames', array_column($produk, 'nama'));
    }

    /**
     * Batas umur kabar ikut di setiap jawaban.
     *
     * Kebijakan "kapan kabar berhenti dipercaya" milik server, dan klien membacanya
     * dari sini. Kalau angkanya diketik di JavaScript, mengubahnya berarti membangun
     * ulang aset dan berharap semua tablet mengambil versi barunya — sedangkan
     * lencana yang tidak pernah kedaluwarsa membuat kasir menolak menjual barang
     * yang kirimannya sudah datang.
     */
    public function test_batas_umur_kabar_datang_dari_server(): void
    {
        config()->set('nampan.sisa_stok_kedaluwarsa_menit', 45);

        $this->actingAs($this->kasir)
            ->getJson(route('kasir.sisa-stok'))
            ->assertOk()
            ->assertJsonPath('kedaluwarsa_menit', 45)
            ->assertJsonStructure(['outlet_id', 'kedaluwarsa_menit', 'jam', 'sisa']);
    }
}

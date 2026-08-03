<?php

namespace Tests\Feature;

use App\Actions\Kas\BukaSesiKasAction;
use App\Actions\Kas\KoreksiModalAwalAction;
use App\Actions\Kas\TutupSesiKasAction;
use App\Enums\BillStatus;
use App\Enums\CashMovementType;
use App\Enums\CashSessionStatus;
use App\Enums\Satuan;
use App\Enums\TransactionOrigin;
use App\Enums\UserRole;
use App\Livewire\Pages\Kasir\Beranda;
use App\Livewire\Pages\Kasir\Transaksi;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Menguji fitur kasir: sesi kas, katalog, dan transaksi yang masuk lewat jalur
 * sinkronisasi (jalur yang sama dipakai online maupun offline).
 */
class KasirTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $kasir;

    private Product $nasi;

    private Product $kerupuk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant();
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Uji');
        $perangkat = $this->buatPerangkat($this->tenant, $this->outlet, 'TAB-UJI-1');

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Ani Kasir',
            'username' => 'ani',
            'pin_hash' => '123456',
            'outlet_id' => $this->outlet->getKey(),
            'device_id_terikat' => $perangkat->getKey(),
        ]);

        [$this->nasi, $this->kerupuk] = $this->konteks()->forTenant($this->tenant->getKey(), function () {
            $kategori = Category::create(['nama' => 'Makanan']);

            $nasi = Product::create([
                'kategori_id' => $kategori->getKey(),
                'nama_produk' => 'Nasi Rames',
                'harga_default' => 12000,
                'satuan' => Satuan::Porsi,
            ]);

            $kerupuk = Product::create([
                'nama_produk' => 'Kerupuk',
                'harga_default' => 2000,
                'satuan' => Satuan::Pcs,
            ]);

            Stock::create([
                'outlet_id' => $this->outlet->getKey(),
                'product_id' => $nasi->getKey(),
                'jumlah_saat_ini' => 50,
            ]);

            return [$nasi, $kerupuk];
        });
    }

    /* ── Sesi kas ──────────────────────────────────────────────────────────── */

    public function test_kasir_bisa_membuka_sesi_kas(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(Transaksi::class)
            ->set('modalAwal', 150000)
            ->call('bukaSesi')
            ->assertSet('galat', null);

        $sesi = CashSession::sole();

        $this->assertSame($this->kasir->getKey(), $sesi->staff_id);
        $this->assertSame(CashSessionStatus::Terbuka, $sesi->status);
        $this->assertEqualsWithDelta(150000.0, (float) $sesi->modal_awal, 0.01);
    }

    /**
     * Modal awal wajib diisi kasir, bukan diberi nilai bawaan.
     *
     * Angka ini adalah pernyataan kasir tentang uang fisik yang ia hitung. Kalau
     * aplikasi mengisinya sendiri, kasir bisa membuka laci tanpa pernah menghitung —
     * dan selisih di akhir shift kehilangan maknanya karena pembandingnya bukan
     * kenyataan. Tombol yang dimatikan di layar hanya kenyamanan; penolakan yang
     * benar-benar menjaga ada di sini.
     */
    public function test_sesi_tidak_bisa_dibuka_tanpa_modal_awal(): void
    {
        foreach ([Transaksi::class, Beranda::class] as $layar) {
            Livewire::actingAs($this->kasir)
                ->test($layar)
                ->assertSet('modalAwal', null)
                ->call('bukaSesi')
                ->assertSet('galat', 'Hitung uang di laci dulu, lalu isi jumlahnya.');

            $this->assertSame(0, CashSession::count(), "Sesi terbuka dari {$layar} tanpa modal awal.");
        }
    }

    /**
     * Koreksi modal awal HARUS meninggalkan jejak.
     *
     * Angka modal awal adalah pembanding yang menahan selisih akhir shift. Kalau ia
     * bisa ditimpa tanpa catatan, kekurangan kas bisa dihapus hanya dengan
     * "memperbaiki" angka pembukanya — dan seluruh mekanisme rekonsiliasi berhenti
     * menjaga apa pun.
     */
    public function test_koreksi_modal_awal_tercatat_di_audit_log(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 200000);

        Livewire::actingAs($this->kasir)
            ->test(Beranda::class)
            ->call('bukaKoreksi')
            ->assertSet('panelKoreksi', true)
            ->set('modalKoreksi', 273000)
            ->set('alasanKoreksi', 'salah hitung, ada uang di amplop terpisah')
            ->call('simpanKoreksi')
            ->assertSet('galatKoreksi', null)
            ->assertSet('panelKoreksi', false);

        $this->assertEqualsWithDelta(273000.0, (float) $sesi->fresh()->modal_awal, 0.01);

        $catatan = AuditLog::where('aksi', 'koreksi_modal_awal')->sole();

        $this->assertSame($this->kasir->getKey(), $catatan->user_id);
        $this->assertSame($sesi->getKey(), $catatan->entitas_id);
        $this->assertEqualsWithDelta(200000.0, (float) $catatan->detail_json['modal_awal_lama'], 0.01);
        $this->assertEqualsWithDelta(273000.0, (float) $catatan->detail_json['modal_awal_baru'], 0.01);
        $this->assertEqualsWithDelta(73000.0, (float) $catatan->detail_json['selisih'], 0.01);
        $this->assertSame('salah hitung, ada uang di amplop terpisah', $catatan->detail_json['alasan']);
    }

    /** Kas menurut sistem ikut bergeser: itulah gunanya koreksi. */
    public function test_koreksi_modal_awal_menggeser_kas_sistem(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 200000);
        $this->assertEqualsWithDelta(200000.0, $sesi->hitungKasSistem(), 0.01);

        app(KoreksiModalAwalAction::class)->execute($sesi, 150000, 'kelebihan hitung', $this->kasir);

        $this->assertEqualsWithDelta(150000.0, $sesi->fresh()->hitungKasSistem(), 0.01);
    }

    public function test_koreksi_ditolak_tanpa_alasan_atau_tanpa_perubahan(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 200000);
        $koreksi = app(KoreksiModalAwalAction::class);

        // Tanpa alasan: koreksi uang yang tidak bisa ditelusuri siapa pun.
        try {
            $koreksi->execute($sesi, 250000, '   ', $this->kasir);
            $this->fail('Koreksi tanpa alasan seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('alasan', $e->getMessage());
        }

        // Angka yang sama: tidak ada yang dikoreksi, tapi catatan audit akan lahir.
        try {
            $koreksi->execute($sesi, 200000, 'iseng', $this->kasir);
            $this->fail('Koreksi tanpa perubahan seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sama', $e->getMessage());
        }

        $this->assertSame(0, AuditLog::where('aksi', 'koreksi_modal_awal')->count());
        $this->assertEqualsWithDelta(200000.0, (float) $sesi->fresh()->modal_awal, 0.01);
    }

    /** Sesi yang sudah ditutup adalah catatan selesai; angkanya tidak boleh digeser. */
    public function test_sesi_tertutup_tidak_bisa_dikoreksi(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 200000);
        app(TutupSesiKasAction::class)->execute($sesi, 200000);

        $this->expectException(RuntimeException::class);
        app(KoreksiModalAwalAction::class)->execute($sesi->fresh(), 250000, 'terlambat', $this->kasir);
    }

    /** Laci yang benar-benar kosong adalah keadaan sah dan harus bisa dicatat. */
    public function test_modal_awal_nol_diterima(): void
    {
        Livewire::actingAs($this->kasir)
            ->test(Beranda::class)
            ->set('modalAwal', 0)
            ->call('bukaSesi')
            ->assertSet('galat', null);

        $this->assertEqualsWithDelta(0.0, (float) CashSession::sole()->modal_awal, 0.01);
    }

    /**
     * Dua sesi terbuka sekaligus membuat uang tunai dari transaksi yang sama bisa
     * masuk ke laci berbeda, dan rekonsiliasi akhir shift tidak akan pernah cocok.
     */
    public function test_tidak_bisa_membuka_dua_sesi_sekaligus(): void
    {
        $buka = app(BukaSesiKasAction::class);
        $buka->execute($this->kasir, 100000);

        $this->expectException(RuntimeException::class);
        $buka->execute($this->kasir, 100000);
    }

    public function test_tutup_kasir_menghitung_selisih_dari_mutasi_kas(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);

        // Uang masuk 40.000 dari penjualan tunai.
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => CashMovement::create([
            'cash_session_id' => $sesi->getKey(),
            'tipe' => CashMovementType::Penjualan,
            'jumlah' => 40000,
        ]));

        // Kasir menghitung 138.000 padahal sistem bilang 140.000 → kurang 2.000.
        $ditutup = app(TutupSesiKasAction::class)->execute($sesi, 138000);

        $this->assertEqualsWithDelta(140000.0, (float) $ditutup->kas_akhir_sistem, 0.01);
        $this->assertEqualsWithDelta(138000.0, (float) $ditutup->kas_akhir_fisik, 0.01);
        $this->assertEqualsWithDelta(-2000.0, (float) $ditutup->selisih, 0.01);
        $this->assertSame(CashSessionStatus::Ditutup, $ditutup->status);
    }

    /** Selisih adalah temuan untuk owner, bukan galat yang boleh dikoreksi sistem. */
    public function test_selisih_tidak_dikoreksi_otomatis(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);
        $ditutup = app(TutupSesiKasAction::class)->execute($sesi, 90000);

        $this->assertEqualsWithDelta(-10000.0, (float) $ditutup->selisih, 0.01);
        $this->assertEqualsWithDelta(90000.0, (float) $ditutup->kas_akhir_fisik, 0.01);
    }

    public function test_sesi_yang_sudah_ditutup_tidak_bisa_ditutup_lagi(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);
        app(TutupSesiKasAction::class)->execute($sesi, 100000);

        $this->expectException(RuntimeException::class);
        app(TutupSesiKasAction::class)->execute($sesi->fresh(), 100000);
    }

    /* ── Katalog ───────────────────────────────────────────────────────────── */

    public function test_katalog_hanya_berisi_produk_tenant_sendiri(): void
    {
        $tenantLain = $this->buatTenant('Warung Sebelah');
        $this->konteks()->forTenant($tenantLain->getKey(), fn () => Product::create([
            'nama_produk' => 'Produk Sebelah',
            'harga_default' => 9000,
        ]));

        $data = $this->actingAs($this->kasir)
            ->getJson(route('kasir.katalog'))
            ->assertOk()
            ->json();

        $nama = array_column($data['produk'], 'nama');

        $this->assertContains('Nasi Rames', $nama);
        $this->assertNotContains('Produk Sebelah', $nama);
    }

    public function test_katalog_menandai_satuan_pecahan(): void
    {
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Laundry Kiloan',
            'harga_default' => 7000,
            'satuan' => Satuan::Kg,
        ]));

        $produk = collect($this->actingAs($this->kasir)->getJson(route('kasir.katalog'))->json('produk'));

        $this->assertTrue($produk->firstWhere('nama', 'Laundry Kiloan')['pecahan']);
        $this->assertFalse($produk->firstWhere('nama', 'Kerupuk')['pecahan']);
    }

    /**
     * Barcode & SKU harus ada di katalog, bukan ditanyakan ke server saat memindai.
     *
     * Kalau pencocokan kodenya butuh permintaan ke server, memindai berhenti bekerja
     * persis saat jaringan mati — padahal itu keadaan yang paling harus tetap jalan di
     * kasir. Klien mencocokkannya dari katalog yang sudah tersimpan di perangkat.
     */
    public function test_katalog_membawa_barcode_dan_sku_untuk_pemindaian_offline(): void
    {
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Indomie Goreng',
            'harga_default' => 3500,
            'barcode' => '8998866200189',
            'sku' => 'MI-001',
        ]));

        $produk = collect($this->actingAs($this->kasir)->getJson(route('kasir.katalog'))->json('produk'))
            ->firstWhere('nama', 'Indomie Goreng');

        $this->assertSame('8998866200189', $produk['barcode']);
        $this->assertSame('MI-001', $produk['sku']);
    }

    /**
     * URL gambar katalog mengikuti alamat yang sedang dibuka, bukan APP_URL.
     *
     * Tablet kasir membuka aplikasi lewat alamat LAN (mis. 192.168.1.5). Dengan
     * Storage::url() yang selalu memakai APP_URL, tablet itu menerima URL ke
     * "localhost" — yaitu ke dirinya sendiri — dan SELURUH gambar produk gagal dimuat.
     * Kegagalan ini tidak pernah terlihat di mesin pengembang.
     */
    public function test_url_gambar_katalog_mengikuti_alamat_permintaan(): void
    {
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Teh Kotak',
            'harga_default' => 4000,
            'gambar_path' => 'produk/teh.jpg',
        ]));

        $produk = collect($this->actingAs($this->kasir)
            ->getJson('http://192.168.1.5:8000'.route('kasir.katalog', absolute: false))
            ->json('produk'))
            ->firstWhere('nama', 'Teh Kotak');

        $this->assertSame('http://192.168.1.5:8000/storage/produk/teh.jpg', $produk['gambar']);
    }

    public function test_katalog_tidak_bisa_diakses_owner_tanpa_outlet(): void
    {
        $owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'email' => 'o@u.test',
            'password' => 'rahasia123',
        ]);

        // Gerbang peran menolak lebih dulu: katalog hanya untuk kasir & dapur.
        $this->actingAs($owner)
            ->get(route('kasir.katalog'))
            ->assertRedirect(route('owner.dasbor'));
    }

    /* ── Transaksi lewat jalur sinkronisasi ────────────────────────────────── */

    /** @return array<string, mixed> */
    private function payloadTransaksi(string $sesiId, string $origin = 'online'): array
    {
        $total = 12000 * 2 + 2000;

        return [
            'outlet_id' => $this->outlet->getKey(),
            'transactions' => [[
                'id' => (string) Str::uuid(),
                'nomor_transaksi' => 'TRX-20260729-UJI1-0001',
                'mode' => 'langsung',
                'status' => 'lunas',
                'subtotal' => $total,
                'total' => $total,
                'origin' => $origin,
                'cash_session_id' => $sesiId,
                'waktu_transaksi' => now()->toDateTimeString(),
                'items' => [
                    [
                        'product_id' => $this->nasi->getKey(),
                        'nama_produk' => 'Nasi Rames',
                        'qty' => 2,
                        'harga_satuan' => 12000,
                        'subtotal' => 24000,
                    ],
                    [
                        'product_id' => $this->kerupuk->getKey(),
                        'nama_produk' => 'Kerupuk',
                        'qty' => 1,
                        'harga_satuan' => 2000,
                        'subtotal' => 2000,
                    ],
                ],
                'payments' => [[
                    'metode' => 'cash',
                    'jumlah' => $total,
                    'jumlah_diterima' => 30000,
                    'kembalian' => 4000,
                ]],
            ]],
        ];
    }

    public function test_transaksi_kasir_tersimpan_lengkap_dengan_kas_dan_stok(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);

        $this->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $this->payloadTransaksi($sesi->getKey()))
            ->assertOk()
            ->assertJson(['jumlah_dibuat' => 1, 'jumlah_gagal' => 0]);

        $trx = Transaction::sole();

        $this->assertEqualsWithDelta(26000.0, (float) $trx->total, 0.01);
        $this->assertCount(2, $trx->items);

        // Uang tunai masuk ke sesi kas yang sedang terbuka.
        $this->assertEqualsWithDelta(26000.0, (float) CashMovement::sole()->jumlah, 0.01);

        // Stok produk berkurang sesuai jumlah terjual.
        $stok = Stock::where('product_id', $this->nasi->getKey())->sole();
        $this->assertEqualsWithDelta(48.0, (float) $stok->jumlah_saat_ini, 0.001);
    }

    /**
     * Layar kasir memakai endpoint yang sama untuk online dan offline, jadi asal
     * transaksi harus dihormati apa adanya. Kalau semuanya dicap "offline",
     * laporan tidak bisa lagi membedakan yang sempat tertahan dari yang mulus.
     */
    public function test_asal_transaksi_dihormati_bukan_dipaksa_offline(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);

        $this->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $this->payloadTransaksi($sesi->getKey(), 'online'))
            ->assertOk();

        $this->assertSame(TransactionOrigin::Online, Transaction::sole()->origin);
    }

    public function test_tanpa_kolom_asal_dianggap_offline(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);

        $payload = $this->payloadTransaksi($sesi->getKey());
        unset($payload['transactions'][0]['origin']);

        $this->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $payload)
            ->assertOk();

        $this->assertSame(TransactionOrigin::Offline, Transaction::sole()->origin);
    }

    /** Antrean yang dikirim ulang (retry) tidak boleh menggandakan omzet & stok. */
    public function test_antrean_dikirim_ulang_tidak_menggandakan_penjualan(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);
        $payload = $this->payloadTransaksi($sesi->getKey());

        $this->actingAs($this->kasir)->postJson(route('sinkronisasi.transaksi'), $payload)->assertOk();
        $this->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $payload)
            ->assertOk()
            ->assertJson(['jumlah_dibuat' => 0, 'jumlah_duplikat' => 1]);

        $this->assertSame(1, Transaction::count());
        $this->assertEqualsWithDelta(48.0, (float) Stock::where('product_id', $this->nasi->getKey())->sole()->jumlah_saat_ini, 0.001);
    }

    /* ── Layar ─────────────────────────────────────────────────────────────── */

    public function test_layar_transaksi_menampilkan_gerbang_buka_kasir(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('kasir.transaksi'))
            ->assertOk()
            ->assertSee('Buka kasir')
            ->assertSee('Modal awal', false);
    }

    public function test_layar_transaksi_menampilkan_kasir_setelah_sesi_dibuka(): void
    {
        app(BukaSesiKasAction::class)->execute($this->kasir, 100000);

        $this->actingAs($this->kasir)
            ->get(route('kasir.transaksi'))
            ->assertOk()
            ->assertSee('Keranjang')
            ->assertSee('Tutup kasir');
    }

    /* ── Bill Mode B & C ───────────────────────────────────────────────────── */

    /** Bill dibuat di perangkat (id UUID buatan klien) lalu disinkronkan. */
    public function test_bill_mode_b_dibuat_lewat_sinkronisasi(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);
        $billId = (string) Str::uuid();

        $this->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), [
                'outlet_id' => $this->outlet->getKey(),
                'bills' => [[
                    'id' => $billId,
                    'mode' => 'open_bill',
                    'status' => 'terbuka',
                    'label' => 'Meja 4',
                    'dibuka_pada' => now()->toDateTimeString(),
                ]],
                'transactions' => [$this->transaksiDraftBill($billId)],
            ])
            ->assertOk()
            ->assertJson(['bill_dibuat' => 1, 'jumlah_dibuat' => 1]);

        $bill = Bill::sole();
        $this->assertSame('Meja 4', $bill->label);
        $this->assertSame(BillStatus::Terbuka, $bill->status);
        $this->assertSame($bill->getKey(), Transaction::sole()->bill_id);
        unset($sesi);
    }

    /**
     * Antrean offline bisa tiba tidak berurutan. Status yang memundurkan harus
     * ditolak, kalau tidak pelanggan diberi tahu cuciannya belum selesai padahal
     * sudah siap diambil.
     */
    public function test_status_bill_tidak_bisa_mundur(): void
    {
        $billId = (string) Str::uuid();

        $kirim = fn (string $status) => $this->actingAs($this->kasir)->postJson(
            route('sinkronisasi.transaksi'),
            [
                'outlet_id' => $this->outlet->getKey(),
                'bills' => [[
                    'id' => $billId,
                    'mode' => 'pesan_antar',
                    'status' => $status,
                    'label' => 'LDY-009',
                ]],
                'transactions' => [$this->transaksiDraftBill($billId, 'TRX-'.$status)],
            ],
        );

        $kirim('terbuka')->assertOk();
        $kirim('siap_diambil')->assertOk();

        // Paket lama menyusul: harus diabaikan.
        $kirim('diproses')->assertOk();

        $this->assertSame(BillStatus::SiapDiambil, Bill::sole()->status);
    }

    public function test_bill_selesai_dibayar_mencatat_waktu_tutup(): void
    {
        $billId = (string) Str::uuid();

        $this->actingAs($this->kasir)->postJson(route('sinkronisasi.transaksi'), [
            'outlet_id' => $this->outlet->getKey(),
            'bills' => [[
                'id' => $billId,
                'mode' => 'open_bill',
                'status' => 'selesai_dibayar',
                'label' => 'Meja 7',
            ]],
            'transactions' => [$this->transaksiDraftBill($billId)],
        ])->assertOk();

        $this->assertNotNull(Bill::sole()->ditutup_pada);
    }

    /** Bill yang dikirim ulang tidak digandakan. */
    public function test_bill_dikirim_ulang_tidak_dobel(): void
    {
        $billId = (string) Str::uuid();

        $payload = [
            'outlet_id' => $this->outlet->getKey(),
            'bills' => [[
                'id' => $billId,
                'mode' => 'open_bill',
                'status' => 'terbuka',
                'label' => 'Meja 9',
            ]],
            'transactions' => [$this->transaksiDraftBill($billId)],
        ];

        $this->actingAs($this->kasir)->postJson(route('sinkronisasi.transaksi'), $payload)->assertOk();
        $this->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $payload)
            ->assertOk()
            ->assertJson(['bill_dibuat' => 0, 'jumlah_duplikat' => 1]);

        $this->assertSame(1, Bill::count());
    }

    /** Split payment: tunai + QRIS dalam satu transaksi. */
    public function test_split_payment_tersimpan_sebagai_dua_baris(): void
    {
        $sesi = app(BukaSesiKasAction::class)->execute($this->kasir, 100000);

        $payload = $this->payloadTransaksi($sesi->getKey());
        $payload['transactions'][0]['payments'] = [
            ['metode' => 'cash', 'jumlah' => 20000, 'jumlah_diterima' => 20000, 'kembalian' => 0],
            ['metode' => 'qris', 'jumlah' => 6000],
        ];

        $this->actingAs($this->kasir)->postJson(route('sinkronisasi.transaksi'), $payload)->assertOk();

        $trx = Transaction::sole();
        $this->assertCount(2, $trx->payments);

        // Hanya bagian TUNAI yang masuk laci kas; QRIS tidak menyentuh uang fisik.
        $this->assertEqualsWithDelta(20000.0, (float) CashMovement::sole()->jumlah, 0.01);
    }

    /** @return array<string, mixed> */
    private function transaksiDraftBill(string $billId, string $nomor = 'TRX-BILL-1'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'nomor_transaksi' => $nomor,
            'mode' => 'open_bill',
            'status' => 'draft',
            'bill_id' => $billId,
            'subtotal' => 12000,
            'total' => 12000,
            'waktu_transaksi' => now()->toDateTimeString(),
            'items' => [[
                'product_id' => $this->nasi->getKey(),
                'nama_produk' => 'Nasi Rames',
                'qty' => 1,
                'harga_satuan' => 12000,
                'subtotal' => 12000,
            ]],
            'payments' => [],
        ];
    }
}

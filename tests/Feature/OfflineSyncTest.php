<?php

namespace Tests\Feature;

use App\Actions\Sync\SyncOfflineTransactionsAction;
use App\Enums\BusinessType;
use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\Satuan;
use App\Enums\TenantStatus;
use App\Enums\TransactionMode;
use App\Enums\TransactionOrigin;
use App\Enums\UserRole;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\CreditLedger;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\SyncLog;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Support\SyncResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Menguji Mode Offline-First: kasir berjualan tanpa internet, antreannya
 * disinkronkan saat online kembali.
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $kasir;

    private Customer $pelanggan;

    private Product $nasi;      // Berbasis resep: mengurangi bahan baku.

    private Product $kerupuk;   // Produk biasa: mengurangi stok produk.

    private RawMaterial $beras;

    private CashSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);

        $this->tenant = Tenant::create([
            'business_name' => 'Warung Uji',
            'business_type' => BusinessType::Fnb,
            'owner_name' => 'Uji',
            'owner_phone' => '0812',
            'status' => TenantStatus::Aktif,
        ]);

        $this->context->setTenant($this->tenant->getKey());

        $this->outlet = Outlet::create([
            'outlet_name' => 'Outlet Uji',
            'active_modes' => [TransactionMode::Langsung->value],
        ]);

        $device = Device::create([
            'outlet_id' => $this->outlet->getKey(),
            'device_type' => 'tablet',
            'serial_number' => 'TAB-UJI-1',
        ]);

        $this->kasir = new User([
            'name' => 'Kasir Uji',
            'username' => 'kasir.uji',
            'pin_hash' => '123456',
            'role' => UserRole::Kasir,
            'outlet_id' => $this->outlet->getKey(),
            'device_id_terikat' => $device->getKey(),
        ]);
        $this->kasir->tenant_id = $this->tenant->getKey();
        $this->kasir->save();

        $this->pelanggan = Customer::create(['nama' => 'Pak Uji']);

        $this->beras = RawMaterial::create(['nama' => 'Beras', 'satuan' => Satuan::Kg]);

        $this->nasi = Product::create([
            'nama_produk' => 'Nasi Putih',
            'harga_default' => 5000,
            'satuan' => Satuan::Porsi,
            'lacak_stok' => false,
        ]);

        RecipeItem::create([
            'product_id' => $this->nasi->getKey(),
            'raw_material_id' => $this->beras->getKey(),
            'jumlah_terpakai' => 0.2,
        ]);

        $this->kerupuk = Product::create([
            'nama_produk' => 'Kerupuk',
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);

        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'raw_material_id' => $this->beras->getKey(),
            'jumlah_saat_ini' => 10,
        ]);

        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $this->kerupuk->getKey(),
            'jumlah_saat_ini' => 20,
        ]);

        $this->sesi = CashSession::create([
            'outlet_id' => $this->outlet->getKey(),
            'staff_id' => $this->kasir->getKey(),
            'dibuka_pada' => now()->subHours(4),
            'modal_awal' => 100000,
        ]);
    }

    public function test_batch_offline_tersimpan_dan_ditandai_sebagai_offline(): void
    {
        $hasil = $this->sync($this->batch([$this->transaksi()]));

        $this->assertSame(1, $hasil->dibuat);
        $this->assertSame(0, $hasil->duplikat);
        $this->assertTrue($hasil->semuaBerhasil());

        $trx = Transaction::sole();
        $this->assertSame(TransactionOrigin::Offline, $trx->origin);
        $this->assertNotNull($trx->dibuat_offline_pada);
        $this->assertNotNull($trx->disinkronkan_pada);
        $this->assertCount(2, $trx->items);
        $this->assertCount(1, $trx->payments);
    }

    /**
     * Inti offline-first: koneksi bisa putus setelah server menyimpan tapi sebelum
     * perangkat menerima balasan, sehingga perangkat mengirim ulang batch yang sama.
     * Kalau ini tidak idempoten, omzet dan stok ikut dobel.
     */
    public function test_batch_yang_sama_dikirim_ulang_tidak_membuat_transaksi_dobel(): void
    {
        $batch = $this->batch([$this->transaksi()]);

        $pertama = $this->sync($batch);
        $kedua = $this->sync($batch);

        $this->assertSame(1, $pertama->dibuat);
        $this->assertSame(0, $pertama->duplikat);

        $this->assertSame(0, $kedua->dibuat);
        $this->assertSame(1, $kedua->duplikat);
        $this->assertTrue($kedua->semuaBerhasil());

        $this->assertSame(1, Transaction::count());

        // Stok hanya berkurang satu kali: 3 porsi x 0,2 kg = 0,6 kg.
        $this->assertEqualsWithDelta(9.4, $this->stokBeras(), 0.001);
        $this->assertEqualsWithDelta(18.0, $this->stokKerupuk(), 0.001);
    }

    public function test_menu_berbasis_resep_mengurangi_stok_bahan_baku(): void
    {
        $this->sync($this->batch([$this->transaksi()]));

        // Bahan baku turun, sedangkan produk jadi berbasis resep tidak punya stok.
        $this->assertEqualsWithDelta(9.4, $this->stokBeras(), 0.001);
        $this->assertEqualsWithDelta(18.0, $this->stokKerupuk(), 0.001);
    }

    public function test_pembayaran_tunai_masuk_ke_sesi_kas_yang_masih_terbuka(): void
    {
        $this->sync($this->batch([$this->transaksi()]));

        $movement = CashMovement::sole();

        $this->assertSame(CashMovementType::Penjualan, $movement->tipe);
        $this->assertEqualsWithDelta(19000.0, (float) $movement->jumlah, 0.001);
        $this->assertSame($this->sesi->getKey(), $movement->cash_session_id);
    }

    public function test_pembayaran_kasbon_membuat_piutang_pelanggan(): void
    {
        $this->sync($this->batch([
            $this->transaksi(metode: PaymentMethod::Kasbon, customerId: $this->pelanggan->getKey()),
        ]));

        $ledger = CreditLedger::sole();

        $this->assertSame($this->pelanggan->getKey(), $ledger->customer_id);
        $this->assertEqualsWithDelta(19000.0, (float) $ledger->jumlah_utang, 0.001);
        $this->assertEqualsWithDelta(19000.0, $ledger->sisaUtang(), 0.001);
    }

    /**
     * Satu transaksi bermasalah tidak boleh menjatuhkan transaksi lain di batch
     * yang sama — kasir tidak bisa memperbaiki struk yang sudah tercetak.
     */
    public function test_transaksi_gagal_tidak_menggagalkan_sisa_batch(): void
    {
        $hasil = $this->sync($this->batch([
            // Kasbon tanpa pelanggan: tidak bisa ditagih ke siapa pun.
            $this->transaksi(metode: PaymentMethod::Kasbon, nomor: 'TRX-OFF-GAGAL'),
            $this->transaksi(nomor: 'TRX-OFF-OK'),
        ]));

        $this->assertSame(1, $hasil->dibuat);
        $this->assertSame(1, $hasil->gagal);
        $this->assertFalse($hasil->semuaBerhasil());

        // Yang gagal di-rollback penuh, tidak menyisakan baris setengah jadi.
        $this->assertSame(1, Transaction::count());
        $this->assertSame('TRX-OFF-OK', Transaction::sole()->nomor_transaksi);
        $this->assertSame(0, CreditLedger::count());
    }

    public function test_batch_ditolak_kalau_outlet_bukan_milik_kasir(): void
    {
        $outletLain = Outlet::create([
            'outlet_name' => 'Outlet Lain',
            'active_modes' => [TransactionMode::Langsung->value],
        ]);

        $hasil = $this->sync([
            'outlet_id' => $outletLain->getKey(),
            'transactions' => [$this->transaksi()],
        ]);

        $this->assertSame(0, $hasil->dibuat);
        $this->assertSame(1, $hasil->gagal);
        $this->assertSame(0, Transaction::count());
    }

    public function test_setiap_upaya_sinkronisasi_dicatat(): void
    {
        $batch = $this->batch([$this->transaksi()]);

        $this->sync($batch);
        $this->sync($batch);

        $this->assertSame(2, SyncLog::count());

        // Kedua log bisa punya created_at yang sama persis (detik yang sama), jadi
        // yang diperiksa adalah keberadaan tiap jenis hasil, bukan urutannya.
        $this->assertTrue(SyncLog::where('jumlah_dibuat', 1)->where('jumlah_duplikat', 0)->exists());
        $this->assertTrue(SyncLog::where('jumlah_dibuat', 0)->where('jumlah_duplikat', 1)->exists());
    }

    public function test_endpoint_menerima_antrean_dan_mengembalikan_ringkasan(): void
    {
        $response = $this
            ->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $this->batch([$this->transaksi()]));

        $response->assertOk()->assertJson([
            'jumlah_dikirim' => 1,
            'jumlah_dibuat' => 1,
            'jumlah_duplikat' => 0,
            'jumlah_gagal' => 0,
        ]);

        $this->assertSame(1, Transaction::count());
    }

    /**
     * Rule exists bawaan Laravel tidak melihat global scope tenant, jadi rule di
     * FormRequest dikunci ke tenant_id. Tanpa itu perangkat bisa menempelkan
     * transaksi ke pelanggan milik merchant lain.
     */
    public function test_referensi_milik_tenant_lain_ditolak_validasi(): void
    {
        $tenantLain = Tenant::create([
            'business_name' => 'Warung Sebelah',
            'business_type' => BusinessType::Kelontong,
            'owner_name' => 'Sebelah',
            'owner_phone' => '0813',
            'status' => TenantStatus::Aktif,
        ]);

        $pelangganTenantLain = $this->context->forTenant(
            $tenantLain->getKey(),
            fn () => Customer::create(['nama' => 'Pelanggan Sebelah']),
        );

        $this
            ->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $this->batch([
                $this->transaksi(customerId: $pelangganTenantLain->getKey()),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('transactions.0.customer_id');

        $this->assertSame(0, Transaction::count());
    }

    private function sync(array $payload): SyncResult
    {
        return app(SyncOfflineTransactionsAction::class)->execute($this->kasir, $payload);
    }

    private function batch(array $transaksi): array
    {
        return [
            'outlet_id' => $this->outlet->getKey(),
            'device_id' => $this->kasir->device_id_terikat,
            'transactions' => $transaksi,
        ];
    }

    /**
     * Satu transaksi seperti yang dirakit perangkat saat offline: 3 nasi + 2
     * kerupuk = 19.000. id dibuat di perangkat.
     */
    private function transaksi(
        PaymentMethod $metode = PaymentMethod::Cash,
        ?string $customerId = null,
        ?string $nomor = null,
        ?string $id = null,
    ): array {
        return [
            'id' => $id ?? (string) Str::uuid(),
            'nomor_transaksi' => $nomor ?? 'TRX-OFF-001',
            'mode' => TransactionMode::Langsung->value,
            'status' => 'lunas',
            'subtotal' => 19000,
            'total' => 19000,
            'customer_id' => $customerId,
            'cash_session_id' => $this->sesi->getKey(),
            'waktu_transaksi' => now()->subHours(2)->toDateTimeString(),
            'dibuat_offline_pada' => now()->subHours(2)->toDateTimeString(),
            'items' => [
                [
                    'product_id' => $this->nasi->getKey(),
                    'nama_produk' => 'Nasi Putih',
                    'qty' => 3,
                    'harga_satuan' => 5000,
                    'subtotal' => 15000,
                ],
                [
                    'product_id' => $this->kerupuk->getKey(),
                    'nama_produk' => 'Kerupuk',
                    'qty' => 2,
                    'harga_satuan' => 2000,
                    'subtotal' => 4000,
                ],
            ],
            'payments' => [[
                'metode' => $metode->value,
                'jumlah' => 19000,
            ]],
        ];
    }

    private function stokBeras(): float
    {
        return (float) Stock::where('raw_material_id', $this->beras->getKey())->sole()->jumlah_saat_ini;
    }

    private function stokKerupuk(): float
    {
        return (float) Stock::where('product_id', $this->kerupuk->getKey())->sole()->jumlah_saat_ini;
    }
}

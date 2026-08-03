<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memverifikasi checklist isolasi data antar merchant (bagian 6 dokumen bisnis).
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $warteg;

    private Tenant $kelontong;

    private Product $nasiRames;

    private Product $indomie;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);

        $this->warteg = Tenant::create([
            'business_name' => 'Warung Makan Benjamin',
            'business_type' => BusinessType::Fnb,
            'owner_name' => 'Benjamin',
            'owner_phone' => '081200000001',
            'status' => TenantStatus::Aktif,
        ]);

        $this->kelontong = Tenant::create([
            'business_name' => 'Toko Sembako Sari',
            'business_type' => BusinessType::Kelontong,
            'owner_name' => 'Sari',
            'owner_phone' => '081200000002',
            'status' => TenantStatus::Aktif,
        ]);

        // tenant_id tidak fillable, jadi satu-satunya jalur pengisian adalah context.
        $this->nasiRames = $this->context->forTenant(
            $this->warteg->id,
            fn () => Product::create(['nama_produk' => 'Nasi Rames', 'harga_default' => 12000]),
        );

        $this->indomie = $this->context->forTenant(
            $this->kelontong->id,
            fn () => Product::create(['nama_produk' => 'Indomie Goreng', 'harga_default' => 3500]),
        );

        $this->context->forget();
    }

    public function test_tenant_id_terisi_otomatis_dari_context(): void
    {
        $this->assertSame($this->warteg->id, $this->nasiRames->tenant_id);
        $this->assertSame($this->kelontong->id, $this->indomie->tenant_id);
    }

    public function test_query_hanya_mengembalikan_data_tenant_aktif(): void
    {
        $this->context->setTenant($this->warteg->id);

        $products = Product::all();

        $this->assertCount(1, $products);
        $this->assertSame('Nasi Rames', $products->first()->nama_produk);
    }

    /** Inti checklist: data tenant lain harus tidak terjangkau (cegah IDOR). */
    public function test_data_tenant_lain_tidak_bisa_diakses_lewat_id(): void
    {
        $this->context->setTenant($this->kelontong->id);

        $this->assertNull(Product::find($this->nasiRames->id));
        $this->assertNotNull(Product::find($this->indomie->id));
    }

    public function test_tenant_id_tidak_bisa_ditimpa_lewat_mass_assignment(): void
    {
        $this->context->setTenant($this->warteg->id);

        $product = Product::create([
            'nama_produk' => 'Ayam Goreng',
            'harga_default' => 15000,
            'tenant_id' => $this->kelontong->id, // upaya menyusup, harus diabaikan
        ]);

        $this->assertSame($this->warteg->id, $product->tenant_id);
    }

    public function test_tanpa_tenant_di_context_query_tidak_difilter(): void
    {
        // Kondisi Super Admin, perintah artisan, dan queue job.
        $this->assertCount(2, Product::all());
    }

    public function test_without_scoping_membuka_akses_lintas_tenant(): void
    {
        $this->context->setTenant($this->warteg->id);
        $this->assertCount(1, Product::all());

        $semua = $this->context->withoutScoping(fn () => Product::all());
        $this->assertCount(2, $semua);

        // Keadaan sebelumnya harus dipulihkan setelah callback selesai.
        $this->assertCount(1, Product::all());
    }

    public function test_for_tenant_memulihkan_context_sebelumnya(): void
    {
        $this->context->setTenant($this->warteg->id);

        $hasil = $this->context->forTenant(
            $this->kelontong->id,
            fn () => Product::all()->pluck('nama_produk')->all(),
        );

        $this->assertSame(['Indomie Goreng'], $hasil);
        $this->assertSame($this->warteg->id, $this->context->tenantId());
    }
}

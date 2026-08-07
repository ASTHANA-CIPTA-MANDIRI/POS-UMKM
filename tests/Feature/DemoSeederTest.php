<?php

namespace Tests\Feature;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Models\Kas\CashSession;
use App\Models\Kasir\Bill;
use App\Models\Kasir\Transaction;
use App\Models\Langganan\Plan;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Produk\Product;
use App\Models\Sistem\SyncLog;
use App\Models\Stok\Stock;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menjaga agar data demo lokal tetap bisa di-seed dan tetap konsisten.
 * Seeder yang rusak diam-diam bikin pengembangan berhenti, jadi diuji di CI.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_menghasilkan_tiga_tenant_dan_paket_langganan(): void
    {
        $this->assertSame(3, Plan::count());
        $this->assertSame(3, Tenant::count());

        foreach (['Warung Makan Benjamin', 'Toko Sembako Sari', 'Depot Air & Laundry Bersih'] as $nama) {
            $this->assertTrue(Tenant::where('business_name', $nama)->exists(), "Tenant {$nama} tidak ada.");
        }
    }

    public function test_super_admin_tidak_terikat_tenant(): void
    {
        $superAdmin = User::where('email', 'superadmin@pos-umkm.test')->sole();

        $this->assertSame(UserRole::SuperAdmin, $superAdmin->role);
        $this->assertNull($superAdmin->tenant_id);
        $this->assertTrue($superAdmin->isPlatformLevel());
    }

    /**
     * Prinsip 1 ERD: tidak boleh ada data merchant tanpa tenant_id. Kalau trait
     * BelongsToTenant lumpuh (mis. karena WithoutModelEvents dipasang di seeder),
     * test ini yang jatuh lebih dulu.
     */
    public function test_semua_data_merchant_punya_tenant_id(): void
    {
        $tabel = [
            'outlets', 'devices', 'products', 'product_variants', 'categories',
            'raw_materials', 'recipe_items', 'stocks', 'stock_movements',
            'suppliers', 'purchase_orders', 'purchase_order_items',
            'customers', 'bills', 'transactions', 'transaction_items',
            'transaction_payments', 'credit_ledgers', 'cash_sessions',
            'cash_movements', 'subscriptions', 'invoices', 'payments', 'sync_logs',
        ];

        foreach ($tabel as $nama) {
            $this->assertSame(
                0,
                DB::table($nama)->whereNull('tenant_id')->count(),
                "Tabel {$nama} punya baris tanpa tenant_id.",
            );

            $this->assertGreaterThan(0, DB::table($nama)->count(), "Tabel {$nama} kosong.");
        }

        // Hanya Super Admin yang boleh tanpa tenant.
        $this->assertSame(1, User::whereNull('tenant_id')->count());
    }

    public function test_setiap_tenant_punya_outlet_produk_dan_transaksi(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->assertGreaterThan(0, Outlet::where('tenant_id', $tenant->getKey())->count());
            $this->assertGreaterThan(0, Product::where('tenant_id', $tenant->getKey())->count());
            $this->assertGreaterThan(0, Transaction::where('tenant_id', $tenant->getKey())->count());
        }
    }

    public function test_antrean_offline_ikut_tersinkron(): void
    {
        $offline = Transaction::where('origin', TransactionOrigin::Offline)->get();

        $this->assertCount(3, $offline);

        foreach ($offline as $trx) {
            $this->assertNotNull($trx->dibuat_offline_pada);
            $this->assertNotNull($trx->disinkronkan_pada);
        }

        $this->assertSame(3, (int) SyncLog::sum('jumlah_dibuat'));
        $this->assertSame(0, (int) SyncLog::sum('jumlah_gagal'));
    }

    public function test_bill_mode_b_dan_c_masih_terbuka_untuk_diuji_ui(): void
    {
        // Warteg: 2 bill meja; Depot: 3 pekerjaan laundry berjalan.
        $this->assertSame(5, Bill::terbuka()->count());

        // Pesanan di bill terbuka disimpan sebagai draft, belum jadi omzet.
        $draft = Transaction::where('status', TransactionStatus::Draft)->get();
        $this->assertGreaterThan(0, $draft->count());

        foreach ($draft as $trx) {
            $this->assertNotNull($trx->bill_id);
            $this->assertSame(0, $trx->payments()->count());
        }
    }

    public function test_omzet_tidak_menghitung_transaksi_draft(): void
    {
        $semua = (float) Transaction::sum('total');
        $omzet = (float) Transaction::omzet()->sum('total');

        $this->assertGreaterThan(0, $omzet);
        $this->assertLessThan($semua, $omzet);
    }

    public function test_kasbon_dan_kartu_stok_terisi(): void
    {
        $this->assertGreaterThan(0, CreditLedger::count());
        $this->assertGreaterThan(0, StockMovement::count());

        // Setiap mutasi stok punya saldo sesudah, supaya kartu stok bisa dibaca.
        $this->assertSame(0, StockMovement::whereNull('saldo_sesudah')->count());
    }

    public function test_ada_sesi_kas_terbuka_di_tiap_outlet(): void
    {
        $outletCount = Outlet::count();

        $this->assertSame(
            $outletCount,
            CashSession::where('status', 'terbuka')->count(),
            'Tiap outlet harus punya tepat satu sesi kas yang masih terbuka.',
        );
    }

    public function test_ada_stok_menipis_untuk_menguji_alert(): void
    {
        $menipis = Stock::all()->filter(fn (Stock $stock) => $stock->isLow());

        $this->assertGreaterThan(0, $menipis->count(), 'Tidak ada contoh stok menipis.');
    }

    /** Data demo tidak boleh saling terlihat antar tenant. */
    public function test_data_demo_tetap_terisolasi_antar_tenant(): void
    {
        $context = app(TenantContext::class);
        $warteg = Tenant::where('business_name', 'Warung Makan Benjamin')->sole();
        $kelontong = Tenant::where('business_name', 'Toko Sembako Sari')->sole();

        $produkWarteg = $context->forTenant(
            $warteg->getKey(),
            fn () => Product::pluck('nama_produk')->all(),
        );

        $produkKelontong = $context->forTenant(
            $kelontong->getKey(),
            fn () => Product::pluck('nama_produk')->all(),
        );

        $this->assertContains('Nasi Putih', $produkWarteg);
        $this->assertNotContains('Nasi Putih', $produkKelontong);
        $this->assertSame([], array_intersect($produkWarteg, $produkKelontong));
    }

    /**
     * Periode invoice harus tetap berbeda saat disemai pada tanggal 29-31.
     *
     * Pernah gagal: now()->subMonths(3) dari 31 Juli menjadi 1 Mei — 31 April tidak
     * ada, jadi Carbon menggesernya. Dua iterasi menghasilkan periode yang sama dan
     * nomor invoicenya bentrok. Bug seperti ini tidak pernah terlihat selama
     * pengujian hanya dijalankan pada tanggal muda.
     */
    public function test_periode_invoice_tetap_berbeda_di_akhir_bulan(): void
    {
        foreach (['2026-01-31', '2026-03-31', '2026-07-31', '2026-08-31', '2026-02-28'] as $tanggal) {
            Carbon::setTestNow($tanggal.' 08:00:00');

            $periode = collect(range(3, 0))
                ->map(fn (int $i) => now()->startOfMonth()->subMonths($i)->format('Ym'));

            $this->assertSame(
                4,
                $periode->unique()->count(),
                "Periode invoice bentrok saat disemai pada {$tanggal}: {$periode->join(', ')}",
            );
        }

        Carbon::setTestNow();
    }

    /**
     * Penjaga pola: aritmetika bulan tidak boleh langsung menempel pada now().
     *
     * now()->subMonths(n) meluap pada tanggal 29-31. Yang aman: normalkan dulu
     * (now()->startOfMonth()->subMonths) atau pakai varian NoOverflow. Tanpa penjaga
     * ini, pola yang sama akan kembali masuk lewat seeder berikutnya dan gagalnya
     * hanya muncul beberapa hari dalam sebulan.
     */
    public function test_seeder_tidak_memakai_aritmetika_bulan_yang_meluap(): void
    {
        $pelanggar = [];

        foreach (glob(database_path('seeders/**/*.php')) + glob(database_path('seeders/*.php')) as $berkas) {
            $isi = file_get_contents($berkas);

            foreach (explode("\n", $isi) as $nomor => $baris) {
                if (preg_match('/now\(\)->(add|sub)Months?\(/', $baris) === 1
                    && ! str_contains($baris, 'NoOverflow')) {
                    $pelanggar[] = basename($berkas).':'.($nomor + 1).' → '.trim($baris);
                }
            }
        }

        $this->assertSame([], $pelanggar, implode("\n", $pelanggar));
    }
}

<?php

namespace Database\Seeders;

use App\Actions\Purchase\CatatPembelianAction;
use App\Enums\BusinessType;
use App\Enums\DeviceOwnership;
use App\Enums\DeviceType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\Satuan;
use App\Enums\StockModel;
use App\Enums\TenantStatus;
use App\Enums\TransactionMode;
use App\Enums\UserRole;
use App\Models\Langganan\Invoice;
use App\Models\Langganan\Plan;
use App\Models\Langganan\Subscription;
use App\Models\Pelanggan\Customer;
use App\Models\Pembelian\Supplier;
use App\Models\Produk\Category;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Tenant\Device;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use App\Support\TenantContext;
use Database\Seeders\Support\DemoTransactionBuilder;
use Illuminate\Database\Seeder;

/**
 * Tenant demo 2 — "Toko Sembako Sari" (kelontong, 1 outlet, paket Basic BYOD).
 *
 * Menunjukkan kasus retail: barcode, konversi satuan dus→pcs, alert stok menipis
 * dan produk mendekati kadaluarsa, serta buku kasbon pelanggan langganan.
 */
class KelontongSeeder extends Seeder
{
    public function __construct(
        private TenantContext $context,
        private DemoTransactionBuilder $builder,
        private CatatPembelianAction $catatPembelian,
    ) {}

    public function run(): void
    {
        $tenant = Tenant::create([
            'business_name' => 'Toko Sembako Sari',
            'business_type' => BusinessType::Kelontong,
            'owner_name' => 'Sari Wulandari',
            'owner_phone' => '081234567002',
            'status' => TenantStatus::Aktif,
        ]);

        $this->context->forTenant($tenant->getKey(), fn () => $this->isiData($tenant));
    }

    private function isiData(Tenant $tenant): void
    {
        $plan = Plan::where('slug', 'basic')->firstOrFail();

        $subscription = Subscription::create([
            'plan_id' => $plan->getKey(),
            'device_bundle' => false, // BYOD: pakai HP & printer sendiri.
            'tanggal_mulai' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'tanggal_berakhir' => now()->startOfMonth()->addMonths(10)->endOfMonth()->toDateString(),
        ]);

        Invoice::create([
            'subscription_id' => $subscription->getKey(),
            'nomor_invoice' => 'INV-'.now()->format('Ym').'-SARI01',
            'periode_mulai' => now()->startOfMonth()->toDateString(),
            'periode_selesai' => now()->endOfMonth()->toDateString(),
            'jumlah_tagihan' => $plan->harga_bulanan,
            'status' => InvoiceStatus::Lunas,
            'jatuh_tempo' => now()->startOfMonth()->addDays(9)->toDateString(),
            'dibayar_pada' => now()->startOfMonth()->addDays(2),
        ]);

        $outlet = Outlet::create([
            'outlet_name' => 'Toko Sari',
            'address' => 'Jl. Godean KM 5, Sleman',
            'active_modes' => [TransactionMode::Langsung->value],
            'stock_model' => StockModel::Mandiri,
        ]);

        // BYOD: perangkat milik merchant, tanpa deposit.
        $tablet = Device::create([
            'outlet_id' => $outlet->getKey(),
            'device_type' => DeviceType::Tablet,
            'serial_number' => 'BYOD-SARI-0001',
            'ownership' => DeviceOwnership::MilikMerchant,
            'mendukung_pelacakan' => false,
        ]);

        Device::create([
            'outlet_id' => $outlet->getKey(),
            'device_type' => DeviceType::PrinterThermal,
            'serial_number' => 'BYOD-SARI-PRN1',
            'ownership' => DeviceOwnership::MilikMerchant,
            'mendukung_pelacakan' => false,
        ]);

        $owner = $this->buatUser($tenant, [
            'name' => 'Sari Wulandari',
            'email' => 'sari@kelontong.test',
            'password' => 'password',
            'role' => UserRole::Owner,
        ]);

        $kasir = $this->buatUser($tenant, [
            'name' => 'Dewi Kasir',
            'username' => 'dewi.sari',
            'pin_hash' => '123456',
            'role' => UserRole::Kasir,
            'outlet_id' => $outlet->getKey(),
            'device_id_terikat' => $tablet->getKey(),
        ]);

        $produk = $this->produk();
        $this->stok($outlet, $produk);
        $this->pembelian($outlet, $produk, $owner);

        $pelanggan = [
            'joko' => Customer::create(['nama' => 'Pak Joko', 'no_hp' => '081400011111', 'poin' => 80]),
            'wati' => Customer::create(['nama' => 'Bu Wati', 'no_hp' => '081400022222']),
        ];

        $this->penjualan($outlet, $kasir, $tablet, $produk, $pelanggan);
    }

    private function buatUser(Tenant $tenant, array $atribut): User
    {
        $user = new User($atribut);
        $user->tenant_id = $tenant->getKey();
        $user->save();

        return $user;
    }

    /** @return array<string, Product> */
    private function produk(): array
    {
        $sembako = Category::create(['nama' => 'Sembako', 'urutan' => 1]);
        $minuman = Category::create(['nama' => 'Minuman', 'urutan' => 2]);
        $snack = Category::create(['nama' => 'Snack', 'urutan' => 3]);

        return [
            'beras' => Product::create([
                'kategori_id' => $sembako->getKey(),
                'nama_produk' => 'Beras Premium 5kg',
                'sku' => 'SR-BRS5',
                'barcode' => '8991001000011',
                'harga_default' => 68000,
                'harga_beli' => 61000,
                'satuan' => Satuan::Pcs,
            ]),
            'minyak' => Product::create([
                'kategori_id' => $sembako->getKey(),
                'nama_produk' => 'Minyak Goreng 1L',
                'sku' => 'SR-MYK1',
                'barcode' => '8991001000028',
                'harga_default' => 19500,
                'harga_beli' => 17000,
                'satuan' => Satuan::Liter,
            ]),
            'indomie' => Product::create([
                'kategori_id' => $sembako->getKey(),
                'nama_produk' => 'Indomie Goreng',
                'sku' => 'SR-IDM',
                'barcode' => '8991001000035',
                'harga_default' => 3500,
                'harga_beli' => 2900,
                // Dibeli per dus isi 40, dijual per pcs. Stok dicatat dalam pcs
                // (satuan dasar), harga mengikuti satuan yang dipilih kasir.
                'satuan' => Satuan::Pcs,
                'satuan_dasar' => Satuan::Pcs,
                'isi_per_satuan' => 1,
                'pantau_kadaluarsa' => true,
            ]),
            'gula' => Product::create([
                'kategori_id' => $sembako->getKey(),
                'nama_produk' => 'Gula Pasir 1kg',
                'sku' => 'SR-GLA1',
                'barcode' => '8991001000042',
                'harga_default' => 16500,
                'harga_beli' => 14500,
                'satuan' => Satuan::Kg,
            ]),
            'kopi' => Product::create([
                'kategori_id' => $minuman->getKey(),
                'nama_produk' => 'Kopi Sachet',
                'sku' => 'SR-KPI',
                'barcode' => '8991001000059',
                'harga_default' => 2000,
                'harga_beli' => 1500,
                'satuan' => Satuan::Pcs,
            ]),
            'kerupuk' => Product::create([
                'kategori_id' => $snack->getKey(),
                'nama_produk' => 'Kerupuk Bawang Bungkus',
                'sku' => 'SR-KRP',
                'harga_default' => 6000,
                'harga_beli' => 4500,
                'satuan' => Satuan::Pcs,
                'pantau_kadaluarsa' => true,
            ]),
        ];
    }

    /** @param array<string, Product> $produk */
    private function stok(Outlet $outlet, array $produk): void
    {
        $daftar = [
            // [jumlah, minimum, tanggal_kadaluarsa]
            'beras' => [40, 10, null],
            'minyak' => [55, 12, null],
            'indomie' => [160, 40, now()->addMonthsNoOverflow(5)->toDateString()],
            'gula' => [8, 10, null],                                  // di bawah minimum
            'kopi' => [220, 50, null],
            'kerupuk' => [18, 6, now()->addDays(20)->toDateString()],  // mendekati kadaluarsa
        ];

        foreach ($daftar as $kunci => [$jumlah, $minimum, $kadaluarsa]) {
            Stock::create([
                'outlet_id' => $outlet->getKey(),
                'product_id' => $produk[$kunci]->getKey(),
                'jumlah_saat_ini' => $jumlah,
                'stok_minimum' => $minimum,
                'tanggal_kadaluarsa' => $kadaluarsa,
            ]);
        }
    }

    /**
     * Dua nota belanja, keduanya lewat CatatPembelianAction — jalur yang sama persis
     * dengan layar Pembelian.
     *
     * Dulu urutannya (PO → item → mutasi masuk → total) ditulis tangan di sini. Itu jalur
     * kedua untuk hal yang sama, dan jalur kedua cepat atau lambat menghasilkan angka yang
     * berbeda dari jalur yang dipakai pengguna.
     *
     * Dulu juga ada satu PO berstatus draft "untuk menguji tampilan status". Statusnya
     * dibuang: alur draf tidak ada di layar mana pun, jadi nota itu memamerkan keadaan yang
     * tidak punya satu pun tombol yang bisa mengeluarkannya — dan data demo yang
     * memperlihatkan jalan buntu mengajari pemakainya mencari tombol yang tidak ada.
     * Gantinya nota KEDUA yang diterima, sekaligus contoh diskon & ongkos kirim.
     *
     * @param  array<string, Product>  $produk
     */
    private function pembelian(Outlet $outlet, array $produk, User $owner): void
    {
        // Dibuat lebih dulu supaya pemasok langganan punya nomor telepon & alamat; aksi
        // pembelian hanya menyimpan namanya (medan "Beli dari" memang cuma nama), dan ia
        // akan memungut baris ini karena namanya sama.
        Supplier::create([
            'nama' => 'Grosir Amanah',
            'no_hp' => '081500099887',
            'alamat' => 'Pasar Beringharjo, Yogyakarta',
        ]);

        $this->catatPembelian->execute($outlet, $owner, [
            'beli_dari' => 'Grosir Amanah',
            'tanggal' => now()->subDays(4)->toDateString(),
            'baris' => [
                ['product_id' => $produk['indomie']->getKey(), 'qty_beli' => 80, 'harga_satuan' => 2900],
                ['product_id' => $produk['minyak']->getKey(), 'qty_beli' => 24, 'harga_satuan' => 17000],
            ],
        ]);

        // Nota kedua: pemasok yang BELUM ada di daftar — barisnya lahir dari teks yang
        // diketik, persis seperti di layar. Barangnya sengaja yang stoknya sudah aman,
        // supaya contoh "stok menipis" (gula) tetap ada untuk menguji peringatannya.
        $this->catatPembelian->execute($outlet, $owner, [
            'beli_dari' => 'Toko Berkah Jaya',
            'tanggal' => now()->toDateString(),
            'diskon' => 5000,
            'ongkos_kirim' => 20000,
            'catatan' => 'Diantar sore, ongkir dibayar tunai.',
            'baris' => [
                ['product_id' => $produk['kopi']->getKey(), 'qty_beli' => 100, 'harga_satuan' => 1500],
                ['product_id' => $produk['kerupuk']->getKey(), 'qty_beli' => 24, 'harga_satuan' => 4500],
            ],
        ]);
    }

    /**
     * @param  array<string, Product>  $produk
     * @param  array<string, Customer>  $pelanggan
     */
    private function penjualan(Outlet $outlet, User $kasir, Device $device, array $produk, array $pelanggan): void
    {
        for ($i = 8; $i >= 1; $i--) {
            $tanggal = now()->subDays($i)->setTime(6, 30);
            $sesi = $this->builder->bukaSesiKas($outlet, $kasir, $tanggal, modalAwal: 150000);

            for ($n = 0; $n < 5; $n++) {
                $baris = match ($n % 4) {
                    0 => [[$produk['indomie'], 5], [$produk['kopi'], 3]],
                    1 => [[$produk['beras'], 1], [$produk['gula'], 1]],
                    2 => [[$produk['minyak'], 2], [$produk['kerupuk'], 1]],
                    default => [[$produk['indomie'], 10], [$produk['minyak'], 1], [$produk['kopi'], 5]],
                };

                $metode = match ($n % 4) {
                    0, 1 => PaymentMethod::Cash,
                    2 => PaymentMethod::Qris,
                    default => PaymentMethod::Kasbon,
                };

                $this->builder->buat(
                    outlet: $outlet,
                    kasir: $kasir,
                    baris: $baris,
                    waktu: $tanggal->copy()->addHours(3 + $n * 2),
                    metode: $metode,
                    customer: $metode === PaymentMethod::Kasbon
                        ? ($n % 2 === 0 ? $pelanggan['joko'] : $pelanggan['wati'])
                        : null,
                    session: $sesi,
                    device: $device,
                );
            }

            $this->builder->tutupSesiKas($sesi, $tanggal->copy()->setTime(20, 30));
        }

        $this->builder->bukaSesiKas($outlet, $kasir, now()->setTime(6, 30), modalAwal: 150000);
    }
}

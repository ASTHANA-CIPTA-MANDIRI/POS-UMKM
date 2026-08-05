<?php

namespace Database\Seeders;

use App\Actions\Purchase\CatatPembelianAction;
use App\Enums\BillStatus;
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
use App\Models\Bill;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RawMaterial;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\Support\DemoTransactionBuilder;
use Illuminate\Database\Seeder;

/**
 * Tenant demo 1 — "Warung Makan Benjamin" (FnB, 2 outlet, paket Pro +device).
 *
 * Menunjukkan kasus terberat: multi-outlet, resep/BOM sehingga stok bahan baku
 * berkurang saat menu terjual, bill terbuka Mode B, kasbon pelanggan langganan,
 * dan sesi kas harian dengan selisih.
 */
class WartegSeeder extends Seeder
{
    public function __construct(
        private TenantContext $context,
        private DemoTransactionBuilder $builder,
        private CatatPembelianAction $catatPembelian,
    ) {}

    public function run(): void
    {
        $tenant = Tenant::create([
            'business_name' => 'Warung Makan Benjamin',
            'business_type' => BusinessType::Fnb,
            'owner_name' => 'Benjamin Saputra',
            'owner_phone' => '081234567001',
            'status' => TenantStatus::Aktif,
        ]);

        $this->context->forTenant($tenant->getKey(), fn () => $this->isiData($tenant));
    }

    private function isiData(Tenant $tenant): void
    {
        $this->langganan('pro', deviceBundle: true);

        [$pusat, $cabang] = $this->outlets();
        $perangkat = $this->perangkat($pusat, $cabang);
        $orang = $this->pengguna($tenant, $pusat, $cabang, $perangkat['tablet_pusat']);
        $bahan = $this->bahanBaku();
        $menu = $this->menu($bahan);

        $this->stokAwal($pusat, $cabang, $bahan, $menu);
        $this->pembelian($pusat, $bahan, $orang['owner']);

        $pelanggan = $this->pelanggan();

        $this->riwayatPenjualan($pusat, $orang['kasir_pusat'], $menu, $pelanggan, $perangkat['tablet_pusat']);
        $this->riwayatPenjualan($cabang, $orang['kasir_cabang'], $menu, $pelanggan, $perangkat['tablet_cabang'], hari: 5);
        $this->billTerbuka($pusat, $orang['kasir_pusat'], $menu, $pelanggan);
    }

    private function langganan(string $slugPaket, bool $deviceBundle): void
    {
        $plan = Plan::where('slug', $slugPaket)->firstOrFail();

        $subscription = Subscription::create([
            'plan_id' => $plan->getKey(),
            'device_bundle' => $deviceBundle,
            'tanggal_mulai' => now()->startOfMonth()->subMonths(4)->toDateString(),
            'tanggal_berakhir' => now()->startOfMonth()->addMonths(8)->endOfMonth()->toDateString(),
        ]);

        // Tiga bulan lunas, bulan berjalan belum bayar — supaya halaman tagihan
        // punya contoh kedua status.
        for ($i = 3; $i >= 0; $i--) {
            /*
             * startOfMonth DULU, baru geser bulannya. Urutan sebaliknya meluap pada
             * tanggal 29-31: 31 Juli dikurangi 3 bulan menjadi 31 April yang tidak ada,
             * dan Carbon menggesernya ke 1 Mei — dua iterasi menghasilkan periode yang
             * sama, lalu nomor invoicenya bentrok. Seeder ini pernah hanya bisa jalan
             * pada tanggal 1-28.
             */
            $periode = now()->startOfMonth()->subMonths($i);
            $lunas = $i > 0;
            $harga = $deviceBundle ? $plan->harga_bulanan_device : $plan->harga_bulanan;

            $invoice = Invoice::create([
                'subscription_id' => $subscription->getKey(),
                'nomor_invoice' => 'INV-'.$periode->format('Ym').'-'.substr($subscription->getKey(), 0, 6),
                'periode_mulai' => $periode->toDateString(),
                'periode_selesai' => $periode->copy()->endOfMonth()->toDateString(),
                'jumlah_tagihan' => $harga,
                'status' => $lunas ? InvoiceStatus::Lunas : InvoiceStatus::BelumBayar,
                'jatuh_tempo' => $periode->copy()->addDays(9)->toDateString(),
                'dibayar_pada' => $lunas ? $periode->copy()->addDays(3) : null,
            ]);

            if ($lunas) {
                Payment::create([
                    'invoice_id' => $invoice->getKey(),
                    'metode' => 'transfer_bank',
                    'jumlah' => $harga,
                    'tanggal_bayar' => $periode->copy()->addDays(3),
                    'bukti_transfer_path' => 'demo/bukti-transfer.jpg',
                ]);
            }
        }
    }

    /** @return array<int, Outlet> */
    private function outlets(): array
    {
        $modes = [TransactionMode::OpenBill->value, TransactionMode::Langsung->value];

        return [
            Outlet::create([
                'outlet_name' => 'Benjamin Pusat',
                'address' => 'Jl. Kaliurang No. 12, Sleman',
                'active_modes' => $modes,
                'stock_model' => StockModel::Mandiri,
            ]),
            Outlet::create([
                'outlet_name' => 'Benjamin Cabang Seturan',
                'address' => 'Jl. Seturan Raya No. 4, Sleman',
                'active_modes' => $modes,
                'stock_model' => StockModel::Mandiri,
            ]),
        ];
    }

    /** @return array<string, Device> */
    private function perangkat(Outlet $pusat, Outlet $cabang): array
    {
        $buat = fn (Outlet $outlet, DeviceType $tipe, string $serial, DeviceOwnership $milik, ?float $deposit, bool $lacak, bool $utama) => Device::create([
            'outlet_id' => $outlet->getKey(),
            'device_type' => $tipe,
            'serial_number' => $serial,
            'ownership' => $milik,
            'deposit_amount' => $deposit,
            'mendukung_pelacakan' => $lacak,
            'is_perangkat_utama' => $utama,
        ]);

        return [
            'tablet_pusat' => $buat($pusat, DeviceType::Tablet, 'TAB-BJM-0001', DeviceOwnership::MilikPlatform, 500000, true, true),
            // Printer thermal tidak punya GPS/koneksi sendiri → tidak bisa dilacak,
            // deposit jadi satu-satunya proteksi.
            'printer_pusat' => $buat($pusat, DeviceType::PrinterThermal, 'PRN-BJM-0001', DeviceOwnership::MilikPlatform, 150000, false, true),
            // Perangkat cadangan yang sudah ter-binding sejak awal setup.
            'tablet_pusat_cadangan' => $buat($pusat, DeviceType::Tablet, 'TAB-BJM-0002', DeviceOwnership::MilikMerchant, null, true, false),
            'tablet_cabang' => $buat($cabang, DeviceType::Tablet, 'TAB-BJM-0003', DeviceOwnership::MilikPlatform, 500000, true, true),
            'printer_cabang' => $buat($cabang, DeviceType::PrinterThermal, 'PRN-BJM-0002', DeviceOwnership::MilikPlatform, 150000, false, true),
        ];
    }

    /** @return array<string, User> */
    private function pengguna(Tenant $tenant, Outlet $pusat, Outlet $cabang, Device $tabletPusat): array
    {
        return [
            // Owner: outlet_id NULL = akses semua outlet.
            'owner' => $this->buatUser($tenant, [
                'name' => 'Benjamin Saputra',
                'email' => 'benjamin@warteg.test',
                'password' => 'password',
                'role' => UserRole::Owner,
            ]),
            'manager_pusat' => $this->buatUser($tenant, [
                'name' => 'Rina Manager',
                'email' => 'rina@warteg.test',
                'password' => 'password',
                'role' => UserRole::ManagerOutlet,
                'outlet_id' => $pusat->getKey(),
            ]),
            // Kasir & Dapur login pakai username + PIN, bukan email.
            'kasir_pusat' => $this->buatUser($tenant, [
                'name' => 'Ani Kasir',
                'username' => 'ani.pusat',
                'pin_hash' => '123456',
                'role' => UserRole::Kasir,
                'outlet_id' => $pusat->getKey(),
                'device_id_terikat' => $tabletPusat->getKey(),
            ]),
            'dapur_pusat' => $this->buatUser($tenant, [
                'name' => 'Dapur Pusat',
                'username' => 'dapur.pusat',
                'pin_hash' => '123456',
                'role' => UserRole::Dapur,
                'outlet_id' => $pusat->getKey(),
            ]),
            'kasir_cabang' => $this->buatUser($tenant, [
                'name' => 'Sri Kasir',
                'username' => 'sri.seturan',
                'pin_hash' => '123456',
                'role' => UserRole::Kasir,
                'outlet_id' => $cabang->getKey(),
            ]),
        ];
    }

    /**
     * User tidak memakai trait BelongsToTenant (agar query auth guard tidak
     * terfilter), jadi tenant_id-nya harus diisi eksplisit di sini.
     */
    private function buatUser(Tenant $tenant, array $atribut): User
    {
        $user = new User($atribut);
        $user->tenant_id = $tenant->getKey();
        $user->save();

        return $user;
    }

    /** @return array<string, RawMaterial> */
    private function bahanBaku(): array
    {
        $daftar = [
            'beras' => ['Beras', Satuan::Kg, 13000],
            'ayam' => ['Ayam Potong', Satuan::Kg, 38000],
            'telur' => ['Telur Ayam', Satuan::Pcs, 2500],
            'minyak' => ['Minyak Goreng', Satuan::Liter, 18000],
            'gula' => ['Gula Pasir', Satuan::Kg, 15000],
            'teh' => ['Teh Tubruk', Satuan::Kg, 45000],
        ];

        $hasil = [];

        foreach ($daftar as $kunci => [$nama, $satuan, $harga]) {
            $hasil[$kunci] = RawMaterial::create([
                'nama' => $nama,
                'satuan' => $satuan,
                'harga_beli_terakhir' => $harga,
            ]);
        }

        return $hasil;
    }

    /**
     * @param  array<string, RawMaterial>  $bahan
     * @return array<string, Product>
     */
    private function menu(array $bahan): array
    {
        $makanan = Category::create(['nama' => 'Makanan', 'urutan' => 1]);
        $minuman = Category::create(['nama' => 'Minuman', 'urutan' => 2]);
        $lainnya = Category::create(['nama' => 'Lain-lain', 'urutan' => 3]);

        $menu = [];

        // Menu berbasis resep: lacak_stok false karena yang dilacak bahan bakunya.
        $menu['nasi'] = $this->buatMenu($makanan, 'Nasi Putih', 5000, Satuan::Porsi, [
            [$bahan['beras'], 0.15],
        ]);

        $menu['ayam'] = $this->buatMenu($makanan, 'Ayam Goreng', 12000, Satuan::Porsi, [
            [$bahan['ayam'], 0.25],
            [$bahan['minyak'], 0.03],
        ]);

        $menu['telur'] = $this->buatMenu($makanan, 'Telur Dadar', 7000, Satuan::Porsi, [
            [$bahan['telur'], 1],
            [$bahan['minyak'], 0.02],
        ]);

        $menu['esteh'] = $this->buatMenu($minuman, 'Es Teh Manis', 4000, Satuan::Porsi, [
            [$bahan['teh'], 0.005],
            [$bahan['gula'], 0.02],
        ]);

        // Varian dengan harga override (paha lebih mahal dari dada).
        ProductVariant::create([
            'product_id' => $menu['ayam']->getKey(),
            'nama_varian' => 'Paha',
            'harga_override' => 13000,
        ]);
        ProductVariant::create([
            'product_id' => $menu['ayam']->getKey(),
            'nama_varian' => 'Dada',
            'harga_override' => null, // Pakai harga induk.
        ]);
        ProductVariant::create([
            'product_id' => $menu['esteh']->getKey(),
            'nama_varian' => 'Panas',
            'harga_override' => null,
        ]);

        // Barang dagang biasa: stok produk jadi yang dilacak, bukan bahan baku.
        $menu['kerupuk'] = Product::create([
            'kategori_id' => $lainnya->getKey(),
            'nama_produk' => 'Kerupuk',
            'sku' => 'BJM-KRP',
            'harga_default' => 2000,
            'harga_beli' => 1200,
            'satuan' => Satuan::Pcs,
        ]);

        $menu['air'] = Product::create([
            'kategori_id' => $minuman->getKey(),
            'nama_produk' => 'Air Mineral 600ml',
            'sku' => 'BJM-AIR',
            'barcode' => '8991002100015',
            'harga_default' => 4000,
            'harga_beli' => 2800,
            'satuan' => Satuan::Pcs,
            // Konversi satuan: beli per dus isi 24, jual per pcs.
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 1,
        ]);

        return $menu;
    }

    /**
     * @param  array<int, array{0: RawMaterial, 1: float}>  $resep
     */
    private function buatMenu(Category $kategori, string $nama, float $harga, Satuan $satuan, array $resep): Product
    {
        $product = Product::create([
            'kategori_id' => $kategori->getKey(),
            'nama_produk' => $nama,
            'harga_default' => $harga,
            'satuan' => $satuan,
            'lacak_stok' => false,
        ]);

        foreach ($resep as [$bahan, $jumlah]) {
            RecipeItem::create([
                'product_id' => $product->getKey(),
                'raw_material_id' => $bahan->getKey(),
                'jumlah_terpakai' => $jumlah,
            ]);
        }

        return $product;
    }

    /**
     * @param  array<string, RawMaterial>  $bahan
     * @param  array<string, Product>  $menu
     */
    private function stokAwal(Outlet $pusat, Outlet $cabang, array $bahan, array $menu): void
    {
        $stokBahan = [
            'beras' => [60, 15],
            'ayam' => [18, 5],
            'telur' => [200, 60],
            'minyak' => [25, 8],
            'gula' => [12, 4],
            'teh' => [3, 1],
        ];

        foreach ([$pusat, $cabang] as $outlet) {
            foreach ($stokBahan as $kunci => [$jumlah, $minimum]) {
                Stock::create([
                    'outlet_id' => $outlet->getKey(),
                    'raw_material_id' => $bahan[$kunci]->getKey(),
                    'jumlah_saat_ini' => $jumlah,
                    'stok_minimum' => $minimum,
                ]);
            }

            // Kerupuk sengaja dibuat mendekati stok minimum supaya alert stok
            // menipis ada datanya untuk diuji.
            Stock::create([
                'outlet_id' => $outlet->getKey(),
                'product_id' => $menu['kerupuk']->getKey(),
                'jumlah_saat_ini' => 12,
                'stok_minimum' => 10,
            ]);

            Stock::create([
                'outlet_id' => $outlet->getKey(),
                'product_id' => $menu['air']->getKey(),
                'jumlah_saat_ini' => 48,
                'stok_minimum' => 12,
            ]);
        }
    }

    /**
     * Nota belanja bahan baku, lewat CatatPembelianAction — jalur yang sama persis dengan
     * layar Pembelian.
     *
     * Dulu urutannya (PO → item → mutasi masuk → total) ditulis tangan di sini. Dua salinan
     * untuk satu alur berarti cepat atau lambat data demo dan data sungguhan bercerita
     * berbeda tentang hal yang sama.
     *
     * @param  array<string, RawMaterial>  $bahan
     */
    private function pembelian(Outlet $outlet, array $bahan, User $owner): void
    {
        // Dibuat lebih dulu supaya pemasoknya punya nomor telepon & alamat; aksi pembelian
        // hanya menyimpan namanya, dan ia akan memungut baris ini karena namanya sama.
        Supplier::create([
            'nama' => 'CV Sumber Pangan',
            'no_hp' => '081200011122',
            'alamat' => 'Pasar Kranggan, Yogyakarta',
        ]);

        $this->catatPembelian->execute($outlet, $owner, [
            'beli_dari' => 'CV Sumber Pangan',
            'tanggal' => now()->subDays(6)->toDateString(),
            'ongkos_kirim' => 25000,
            'baris' => [
                ['raw_material_id' => $bahan['beras']->getKey(), 'qty_beli' => 25, 'harga_satuan' => 13000],
                ['raw_material_id' => $bahan['ayam']->getKey(), 'qty_beli' => 10, 'harga_satuan' => 38000],
                ['raw_material_id' => $bahan['minyak']->getKey(), 'qty_beli' => 12, 'harga_satuan' => 18000],
            ],
        ]);
    }

    /** @return array<string, Customer> */
    private function pelanggan(): array
    {
        return [
            'budi' => Customer::create(['nama' => 'Pak Budi', 'no_hp' => '081300011111', 'poin' => 120]),
            'sinta' => Customer::create(['nama' => 'Bu Sinta', 'no_hp' => '081300022222', 'poin' => 45]),
            'anton' => Customer::create(['nama' => 'Mas Anton', 'no_hp' => '081300033333']),
        ];
    }

    /**
     * @param  array<string, Product>  $menu
     * @param  array<string, Customer>  $pelanggan
     */
    private function riwayatPenjualan(
        Outlet $outlet,
        User $kasir,
        array $menu,
        array $pelanggan,
        Device $device,
        int $hari = 10,
    ): void {
        for ($i = $hari; $i >= 1; $i--) {
            $tanggal = now()->subDays($i)->setTime(7, 0);
            $sesi = $this->builder->bukaSesiKas($outlet, $kasir, $tanggal);

            $jumlahTransaksi = 4 + ($i % 3);

            for ($n = 0; $n < $jumlahTransaksi; $n++) {
                $waktu = $tanggal->copy()->addHours(4 + $n)->addMinutes($n * 7);

                $baris = match ($n % 4) {
                    0 => [[$menu['nasi'], 1], [$menu['ayam'], 1], [$menu['esteh'], 1]],
                    1 => [[$menu['nasi'], 2], [$menu['telur'], 2], [$menu['kerupuk'], 2]],
                    2 => [[$menu['nasi'], 1], [$menu['telur'], 1], [$menu['air'], 1]],
                    default => [[$menu['nasi'], 3], [$menu['ayam'], 2], [$menu['esteh'], 3]],
                };

                $metode = match ($n % 5) {
                    0, 1, 2 => PaymentMethod::Cash,
                    3 => PaymentMethod::Qris,
                    default => PaymentMethod::Kasbon,
                };

                $this->builder->buat(
                    outlet: $outlet,
                    kasir: $kasir,
                    baris: $baris,
                    waktu: $waktu,
                    metode: $metode,
                    mode: TransactionMode::Langsung,
                    customer: $metode === PaymentMethod::Kasbon ? $pelanggan['budi'] : null,
                    session: $sesi,
                    device: $device,
                );
            }

            // Hari terakhir sengaja punya selisih kas -5000 (kasir kurang hitung).
            $this->builder->tutupSesiKas(
                $sesi,
                $tanggal->copy()->setTime(21, 0),
                selisihFisik: $i === 1 ? -5000 : 0,
            );
        }

        // Sesi hari ini dibiarkan terbuka supaya UI tutup kasir bisa diuji.
        $this->builder->bukaSesiKas($outlet, $kasir, now()->setTime(7, 0));
    }

    /**
     * @param  array<string, Product>  $menu
     * @param  array<string, Customer>  $pelanggan
     */
    private function billTerbuka(Outlet $outlet, User $kasir, array $menu, array $pelanggan): void
    {
        // Mode B: pesanan masuk bertahap, dibayar saat pelanggan mau pulang.
        $meja3 = Bill::create([
            'outlet_id' => $outlet->getKey(),
            'mode' => TransactionMode::OpenBill,
            'status' => BillStatus::Terbuka,
            'label' => 'Meja 3',
            'dibuka_oleh' => $kasir->getKey(),
            'dibuka_pada' => now()->subMinutes(35),
        ]);

        $this->builder->buat(
            outlet: $outlet,
            kasir: $kasir,
            baris: [[$menu['nasi'], 2], [$menu['ayam'], 2]],
            waktu: now()->subMinutes(30),
            mode: TransactionMode::OpenBill,
            bill: $meja3,
            sudahDibayar: false,
        );

        // Pesanan tambahan di bill yang sama.
        $this->builder->buat(
            outlet: $outlet,
            kasir: $kasir,
            baris: [[$menu['esteh'], 2]],
            waktu: now()->subMinutes(12),
            mode: TransactionMode::OpenBill,
            bill: $meja3,
            sudahDibayar: false,
        );

        $meja5 = Bill::create([
            'outlet_id' => $outlet->getKey(),
            'mode' => TransactionMode::OpenBill,
            'status' => BillStatus::Diproses,
            'label' => 'Meja 5 - Bu Sinta',
            'customer_id' => $pelanggan['sinta']->getKey(),
            'dibuka_oleh' => $kasir->getKey(),
            'dibuka_pada' => now()->subMinutes(8),
        ]);

        $this->builder->buat(
            outlet: $outlet,
            kasir: $kasir,
            baris: [[$menu['nasi'], 1], [$menu['telur'], 1], [$menu['esteh'], 1]],
            waktu: now()->subMinutes(6),
            mode: TransactionMode::OpenBill,
            bill: $meja5,
            sudahDibayar: false,
        );
    }
}

<?php

namespace Database\Seeders;

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
use App\Models\Plan;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\Support\DemoTransactionBuilder;
use Illuminate\Database\Seeder;

/**
 * Tenant demo 3 — "Depot Air & Laundry Bersih" (usaha campuran dalam satu outlet).
 *
 * Fokus Mode C (pesan/titip-ambil): pekerjaan diterima hari ini, statusnya
 * berjalan, dan pembayaran belum tentu terjadi saat barang dititipkan. Ini kasus
 * yang tidak tertangani POS yang mengasumsikan bayar-langsung-selesai.
 */
class DepotLaundrySeeder extends Seeder
{
    public function __construct(
        private TenantContext $context,
        private DemoTransactionBuilder $builder,
    ) {}

    public function run(): void
    {
        $tenant = Tenant::create([
            'business_name' => 'Depot Air & Laundry Bersih',
            'business_type' => BusinessType::Campuran,
            'owner_name' => 'Hendra Prasetyo',
            'owner_phone' => '081234567003',
            // Masih trial: untuk menguji tampilan status trial & sisa masa coba.
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->addDays(9),
        ]);

        $this->context->forTenant($tenant->getKey(), fn () => $this->isiData($tenant));
    }

    private function isiData(Tenant $tenant): void
    {
        $plan = Plan::where('slug', 'pro')->firstOrFail();

        $subscription = Subscription::create([
            'plan_id' => $plan->getKey(),
            'device_bundle' => false,
            'tanggal_mulai' => now()->subDays(5)->toDateString(),
            'tanggal_berakhir' => now()->addDays(9)->toDateString(),
        ]);

        Invoice::create([
            'subscription_id' => $subscription->getKey(),
            'nomor_invoice' => 'INV-'.now()->format('Ym').'-BRSH01',
            'periode_mulai' => now()->toDateString(),
            'periode_selesai' => now()->addMonthNoOverflow()->toDateString(),
            'jumlah_tagihan' => $plan->harga_bulanan,
            'status' => InvoiceStatus::BelumBayar,
            'jatuh_tempo' => now()->addDays(9)->toDateString(),
        ]);

        $outlet = Outlet::create([
            'outlet_name' => 'Depot Bersih Condongcatur',
            'address' => 'Jl. Condongcatur No. 88, Sleman',
            // Dua mode aktif: isi ulang bayar langsung, dan titip-ambil.
            'active_modes' => [
                TransactionMode::PesanAntar->value,
                TransactionMode::Langsung->value,
            ],
            'stock_model' => StockModel::Mandiri,
        ]);

        $tablet = Device::create([
            'outlet_id' => $outlet->getKey(),
            'device_type' => DeviceType::Tablet,
            'serial_number' => 'BYOD-BRSH-0001',
            'ownership' => DeviceOwnership::MilikMerchant,
            'mendukung_pelacakan' => false,
        ]);

        $this->buatUser($tenant, [
            'name' => 'Hendra Prasetyo',
            'email' => 'hendra@depot.test',
            'password' => 'password',
            'role' => UserRole::Owner,
        ]);

        $kasir = $this->buatUser($tenant, [
            'name' => 'Yuli Kasir',
            'username' => 'yuli.depot',
            'pin_hash' => '123456',
            'role' => UserRole::Kasir,
            'outlet_id' => $outlet->getKey(),
            'device_id_terikat' => $tablet->getKey(),
        ]);

        $produk = $this->produk();
        $this->stok($outlet, $produk);

        $pelanggan = [
            'rudi' => Customer::create(['nama' => 'Pak Rudi', 'no_hp' => '081600011111']),
            'maya' => Customer::create(['nama' => 'Mbak Maya', 'no_hp' => '081600022222', 'poin' => 30]),
            'kost' => Customer::create(['nama' => 'Kost Melati (langganan)', 'no_hp' => '081600033333', 'poin' => 210]),
        ];

        $this->isiUlangLangsung($outlet, $kasir, $tablet, $produk, $pelanggan);
        $this->pekerjaanBerjalan($outlet, $kasir, $produk, $pelanggan);
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
        $air = Category::create(['nama' => 'Depot Air', 'urutan' => 1]);
        $laundry = Category::create(['nama' => 'Laundry', 'urutan' => 2]);

        return [
            // Jasa isi ulang: tidak ada stok produk jadi yang dilacak.
            'isi_ulang' => Product::create([
                'kategori_id' => $air->getKey(),
                'nama_produk' => 'Isi Ulang Galon 19L',
                'sku' => 'BRSH-ISI19',
                'harga_default' => 6000,
                'satuan' => Satuan::Pcs,
                'lacak_stok' => false,
            ]),
            // Galon kosong dilacak sebagai barang, terpisah dari jasa isinya —
            // dasar untuk fitur deposit/tukar galon.
            'galon_kosong' => Product::create([
                'kategori_id' => $air->getKey(),
                'nama_produk' => 'Galon Kosong 19L',
                'sku' => 'BRSH-GLN19',
                'harga_default' => 55000,
                'harga_beli' => 42000,
                'satuan' => Satuan::Pcs,
            ]),
            'air_botol' => Product::create([
                'kategori_id' => $air->getKey(),
                'nama_produk' => 'Air Mineral Botol 600ml',
                'sku' => 'BRSH-BTL600',
                'barcode' => '8991003000017',
                'harga_default' => 4000,
                'harga_beli' => 2700,
                'satuan' => Satuan::Pcs,
            ]),
            // Laundry dihitung per kg — satuan pecahan, tanpa stok.
            'laundry_kiloan' => Product::create([
                'kategori_id' => $laundry->getKey(),
                'nama_produk' => 'Laundry Kiloan (cuci + setrika)',
                'sku' => 'BRSH-LNDKG',
                'harga_default' => 7000,
                'satuan' => Satuan::Kg,
                'lacak_stok' => false,
            ]),
            'bed_cover' => Product::create([
                'kategori_id' => $laundry->getKey(),
                'nama_produk' => 'Laundry Bed Cover',
                'sku' => 'BRSH-LNDBC',
                'harga_default' => 25000,
                'satuan' => Satuan::Pcs,
                'lacak_stok' => false,
            ]),
        ];
    }

    /** @param array<string, Product> $produk */
    private function stok(Outlet $outlet, array $produk): void
    {
        foreach ([['galon_kosong', 35, 10], ['air_botol', 120, 24]] as [$kunci, $jumlah, $minimum]) {
            Stock::create([
                'outlet_id' => $outlet->getKey(),
                'product_id' => $produk[$kunci]->getKey(),
                'jumlah_saat_ini' => $jumlah,
                'stok_minimum' => $minimum,
            ]);
        }
    }

    /**
     * @param  array<string, Product>  $produk
     * @param  array<string, Customer>  $pelanggan
     */
    private function isiUlangLangsung(Outlet $outlet, User $kasir, Device $device, array $produk, array $pelanggan): void
    {
        for ($i = 6; $i >= 1; $i--) {
            $tanggal = now()->subDays($i)->setTime(8, 0);
            $sesi = $this->builder->bukaSesiKas($outlet, $kasir, $tanggal, modalAwal: 100000);

            for ($n = 0; $n < 4; $n++) {
                $baris = match ($n % 3) {
                    0 => [[$produk['isi_ulang'], 2]],
                    1 => [[$produk['isi_ulang'], 1], [$produk['air_botol'], 2]],
                    default => [[$produk['isi_ulang'], 1], [$produk['galon_kosong'], 1]],
                };

                $this->builder->buat(
                    outlet: $outlet,
                    kasir: $kasir,
                    baris: $baris,
                    waktu: $tanggal->copy()->addHours(2 + $n * 3),
                    metode: $n % 3 === 1 ? PaymentMethod::Qris : PaymentMethod::Cash,
                    mode: TransactionMode::Langsung,
                    customer: $n === 0 ? $pelanggan['rudi'] : null,
                    session: $sesi,
                    device: $device,
                );
            }

            $this->builder->tutupSesiKas($sesi, $tanggal->copy()->setTime(19, 0));
        }

        $this->builder->bukaSesiKas($outlet, $kasir, now()->setTime(8, 0), modalAwal: 100000);
    }

    /**
     * Pekerjaan Mode C yang sedang berjalan: Diterima → Diproses → Siap Diambil,
     * lalu satu yang sudah Selesai & Dibayar sebagai pembanding.
     *
     * @param  array<string, Product>  $produk
     * @param  array<string, Customer>  $pelanggan
     */
    private function pekerjaanBerjalan(Outlet $outlet, User $kasir, array $produk, array $pelanggan): void
    {
        $titipan = [
            [
                'label' => 'LDY-001 / Pak Rudi',
                'status' => BillStatus::Terbuka,
                'customer' => $pelanggan['rudi'],
                'diterima' => now()->subHours(2),
                'estimasi' => now()->addDays(2),
                'baris' => [[$produk['laundry_kiloan'], 4.5]],
            ],
            [
                'label' => 'LDY-002 / Mbak Maya',
                'status' => BillStatus::Diproses,
                'customer' => $pelanggan['maya'],
                'diterima' => now()->subDay(),
                'estimasi' => now()->addDay(),
                'baris' => [[$produk['laundry_kiloan'], 3], [$produk['bed_cover'], 1]],
            ],
            [
                'label' => 'LDY-003 / Kost Melati',
                'status' => BillStatus::SiapDiambil,
                'customer' => $pelanggan['kost'],
                'diterima' => now()->subDays(2),
                'estimasi' => now()->subHours(3),
                'baris' => [[$produk['laundry_kiloan'], 12]],
            ],
        ];

        foreach ($titipan as $data) {
            $bill = Bill::create([
                'outlet_id' => $outlet->getKey(),
                'mode' => TransactionMode::PesanAntar,
                'status' => $data['status'],
                'label' => $data['label'],
                'customer_id' => $data['customer']->getKey(),
                'dibuka_oleh' => $kasir->getKey(),
                'dibuka_pada' => $data['diterima'],
                'estimasi_selesai' => $data['estimasi'],
                'catatan' => 'Nota titipan sudah dicetak saat barang diterima.',
            ]);

            // Belum dibayar: pembayaran terjadi saat barang diambil.
            $this->builder->buat(
                outlet: $outlet,
                kasir: $kasir,
                baris: $data['baris'],
                waktu: $data['diterima'],
                mode: TransactionMode::PesanAntar,
                customer: $data['customer'],
                bill: $bill,
                sudahDibayar: false,
            );
        }

        // Pekerjaan yang sudah tuntas & dibayar saat pengambilan.
        $selesai = Bill::create([
            'outlet_id' => $outlet->getKey(),
            'mode' => TransactionMode::PesanAntar,
            'status' => BillStatus::SelesaiDibayar,
            'label' => 'LDY-000 / Mbak Maya',
            'customer_id' => $pelanggan['maya']->getKey(),
            'dibuka_oleh' => $kasir->getKey(),
            'dibuka_pada' => now()->subDays(5),
            'ditutup_pada' => now()->subDays(3),
            'estimasi_selesai' => now()->subDays(3),
        ]);

        $this->builder->buat(
            outlet: $outlet,
            kasir: $kasir,
            baris: [[$produk['laundry_kiloan'], 5]],
            waktu: now()->subDays(3),
            metode: PaymentMethod::Cash,
            mode: TransactionMode::PesanAntar,
            customer: $pelanggan['maya'],
            bill: $selesai,
        );
    }
}

<?php

namespace Database\Seeders;

use App\Actions\Sync\SyncOfflineTransactionsAction;
use App\Enums\CashSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransactionMode;
use App\Models\Kas\CashSession;
use App\Models\Produk\Product;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Mensimulasikan kasir warteg yang tetap berjualan saat internet mati, lalu
 * antreannya tersinkron ketika koneksi kembali.
 *
 * Sengaja memanggil SyncOfflineTransactionsAction — action yang sama yang dipakai
 * endpoint sungguhan — bukan membuat baris transaksi langsung. Dengan begitu hasil
 * seeding membuktikan jalur offline benar-benar jalan, termasuk pengurangan stok
 * bahan baku dan pencatatan uang tunai ke sesi kas yang sedang terbuka.
 */
class OfflineQueueSeeder extends Seeder
{
    public function __construct(
        private TenantContext $context,
        private SyncOfflineTransactionsAction $sync,
    ) {}

    public function run(): void
    {
        $tenant = Tenant::where('business_name', 'Warung Makan Benjamin')->first();

        if ($tenant === null) {
            $this->command?->warn('OfflineQueueSeeder dilewati: tenant warteg belum ada.');

            return;
        }

        $this->context->forTenant($tenant->getKey(), fn () => $this->kirimAntrean());
    }

    private function kirimAntrean(): void
    {
        $outlet = Outlet::where('outlet_name', 'Benjamin Pusat')->firstOrFail();
        $kasir = User::where('username', 'ani.pusat')->firstOrFail();

        $sesi = CashSession::where('outlet_id', $outlet->getKey())
            ->where('status', CashSessionStatus::Terbuka)
            ->latest('dibuka_pada')
            ->first();

        $menu = Product::whereIn('nama_produk', [
            'Nasi Putih', 'Ayam Goreng', 'Es Teh Manis', 'Kerupuk',
        ])->get()->keyBy('nama_produk');

        $payload = [
            'outlet_id' => $outlet->getKey(),
            'device_id' => $kasir->device_id_terikat,
            'transactions' => [
                $this->transaksiOffline(
                    kasir: $kasir,
                    sesi: $sesi,
                    // Jam perangkat saat listrik/internet mati.
                    waktu: now()->subMinutes(95),
                    urutan: 901,
                    baris: [
                        [$menu['Nasi Putih'], 2],
                        [$menu['Ayam Goreng'], 2],
                        [$menu['Es Teh Manis'], 2],
                    ],
                    metode: PaymentMethod::Cash,
                ),
                $this->transaksiOffline(
                    kasir: $kasir,
                    sesi: $sesi,
                    waktu: now()->subMinutes(72),
                    urutan: 902,
                    baris: [
                        [$menu['Nasi Putih'], 1],
                        [$menu['Kerupuk'], 3],
                    ],
                    metode: PaymentMethod::Cash,
                ),
                $this->transaksiOffline(
                    kasir: $kasir,
                    sesi: $sesi,
                    waktu: now()->subMinutes(48),
                    urutan: 903,
                    baris: [
                        [$menu['Nasi Putih'], 3],
                        [$menu['Ayam Goreng'], 1],
                    ],
                    // QRIS saat offline: struk tercetak, rekonsiliasi menyusul.
                    metode: PaymentMethod::Qris,
                ),
            ],
        ];

        $hasil = $this->sync->execute($kasir, $payload);

        $this->command?->info(sprintf(
            'Antrean offline warteg tersinkron: %d dibuat, %d duplikat, %d gagal.',
            $hasil->dibuat,
            $hasil->duplikat,
            $hasil->gagal,
        ));
    }

    /**
     * @param  array<int, array{0: Product, 1: float}>  $baris
     */
    private function transaksiOffline(
        User $kasir,
        ?CashSession $sesi,
        CarbonInterface $waktu,
        int $urutan,
        array $baris,
        PaymentMethod $metode,
    ): array {
        $items = [];
        $total = 0.0;

        foreach ($baris as [$product, $qty]) {
            $subtotal = (float) $product->harga_default * $qty;
            $total += $subtotal;

            $items[] = [
                'product_id' => $product->getKey(),
                'nama_produk' => $product->nama_produk,
                'qty' => $qty,
                'harga_satuan' => (float) $product->harga_default,
                'subtotal' => $subtotal,
            ];
        }

        return [
            // Dibuat di perangkat, bukan server — inilah kunci idempotensi.
            'id' => (string) Str::uuid(),
            'nomor_transaksi' => sprintf('TRX-%s-OFF-%03d', $waktu->format('Ymd'), $urutan),
            'mode' => TransactionMode::Langsung->value,
            'status' => 'lunas',
            'subtotal' => $total,
            'total' => $total,
            'waktu_transaksi' => $waktu->toDateTimeString(),
            'dibuat_offline_pada' => $waktu->toDateTimeString(),
            'cash_session_id' => $sesi?->getKey(),
            'items' => $items,
            'payments' => [[
                'metode' => $metode->value,
                'jumlah' => $total,
            ]],
        ];
    }
}

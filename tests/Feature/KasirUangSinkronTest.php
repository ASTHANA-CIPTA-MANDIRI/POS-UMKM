<?php

namespace Tests\Feature;

use App\Actions\Sync\SyncOfflineTransactionsAction;
use App\Enums\PaymentMethod;
use App\Enums\Satuan;
use App\Enums\TransactionMode;
use App\Enums\UserRole;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Uji cacat: endpoint sinkronisasi tidak memeriksa apakah uang yang dikirim
 * perangkat masuk hitungan.
 *
 * Berkas ini lahir dari cacat — uji di sini GAGAL pada kode sekarang.
 *
 * Seluruh angka uang transaksi kasir dirakit di perangkat (resources/js/kasir.js)
 * dan diterima server apa adanya: SyncOfflineTransactionsRequest hanya memeriksa
 * tiap angka >= 0 satu per satu, tidak pernah membandingkan payments dengan total
 * maupun total dengan subtotal itemnya. Jadi satu-satunya penjaga uang adalah getter
 * bisaBayar di klien — dan getter itu memang sudah terbukti bisa keliru (lihat
 * tests/js/kasir-uang-offline.test.mjs). CLAUDE.md aturan 4: "Uang divalidasi ketat."
 */
class KasirUangSinkronTest extends TestCase
{
    use MembuatDataUji;
    use RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $kasir;

    private Product $nasi;

    private CashSession $sesi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Uang');
        $this->outlet = $this->buatOutlet($this->tenant);
        $perangkat = $this->buatPerangkat($this->tenant, $this->outlet, 'TAB-UANG-1');

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir Uang',
            'username' => 'kasir.uang',
            'pin_hash' => '123456',
            'outlet_id' => $this->outlet->getKey(),
            'device_id_terikat' => $perangkat->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->nasi = Product::create([
            'nama_produk' => 'Nasi Putih',
            'harga_default' => 5000,
            'satuan' => Satuan::Porsi,
        ]);

        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $this->nasi->getKey(),
            'jumlah_saat_ini' => 100,
        ]);

        $this->sesi = CashSession::create([
            'outlet_id' => $this->outlet->getKey(),
            'staff_id' => $this->kasir->getKey(),
            'dibuka_pada' => now()->subHour(),
            'modal_awal' => 100000,
        ]);
    }

    /**
     * Pembayaran 500.000 untuk tagihan 10.000 diterima tanpa keluhan, dan seluruh
     * 500.000 itu langsung menjadi kas masuk di sesi kasir yang sedang berjalan
     * (SyncOfflineTransactionsAction.php:265-278). Saat tutup kasir, sistem menuntut
     * uang yang tidak pernah ada di laci dan selisihnya ditagihkan ke kasir.
     */
    public function test_pembayaran_yang_melebihi_total_transaksi_ditolak(): void
    {
        $hasil = app(SyncOfflineTransactionsAction::class)->execute(
            $this->kasir,
            $this->batch(total: 10000, jumlahBayar: 500000),
        );

        $kasMasuk = (float) CashMovement::sum('jumlah');

        $this->assertSame(0.0, $kasMasuk, "kas masuk yang tercatat: {$kasMasuk} untuk tagihan 10.000");
        $this->assertSame(0, $hasil->dibuat, 'transaksi dengan uang yang tidak masuk hitungan harus ditolak');
        $this->assertSame(1, $hasil->gagal);
    }

    /**
     * Lapis validasi pun tidak menangkapnya: perangkat mana pun yang bisa membuka
     * layar kasir (termasuk peramban dengan devtools) bisa mengarang omzet.
     */
    public function test_validasi_menolak_pembayaran_yang_tidak_sama_dengan_total(): void
    {
        $this
            ->actingAs($this->kasir)
            ->postJson(route('sinkronisasi.transaksi'), $this->batch(total: 10000, jumlahBayar: 500000))
            ->assertStatus(422);
    }

    /**
     * Total juga tidak pernah diturunkan dari itemnya: 2 x 5.000 dikirim sebagai
     * total 1.000.000 dan tercatat sebagai omzet 1.000.000.
     */
    public function test_total_yang_tidak_sesuai_subtotal_item_ditolak(): void
    {
        $hasil = app(SyncOfflineTransactionsAction::class)->execute(
            $this->kasir,
            $this->batch(total: 1000000, jumlahBayar: 1000000),
        );

        $this->assertSame(0, $hasil->dibuat, 'total 1.000.000 untuk 2 x 5.000 harus ditolak');
        $this->assertSame(1, $hasil->gagal);
    }

    /** Satu transaksi: 2 x Nasi Putih @5.000 = 10.000, dibayar tunai. */
    private function batch(float $total, float $jumlahBayar): array
    {
        return [
            'outlet_id' => $this->outlet->getKey(),
            'device_id' => $this->kasir->device_id_terikat,
            'transactions' => [[
                'id' => (string) Str::uuid(),
                'nomor_transaksi' => 'TRX-UANG-001',
                'mode' => TransactionMode::Langsung->value,
                'status' => 'lunas',
                'subtotal' => $total,
                'total' => $total,
                'cash_session_id' => $this->sesi->getKey(),
                'waktu_transaksi' => now()->subMinutes(5)->toDateTimeString(),
                'items' => [[
                    'product_id' => $this->nasi->getKey(),
                    'nama_produk' => 'Nasi Putih',
                    'qty' => 2,
                    'harga_satuan' => 5000,
                    'subtotal' => 10000,
                ]],
                'payments' => [[
                    'metode' => PaymentMethod::Cash->value,
                    'jumlah' => $jumlahBayar,
                    'jumlah_diterima' => $jumlahBayar,
                ]],
            ]],
        ];
    }
}

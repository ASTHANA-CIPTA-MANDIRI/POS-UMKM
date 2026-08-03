<?php

namespace Tests\Feature;

use App\Actions\Stock\ApplySaleToStockAction;
use App\Enums\AlasanOpname;
use App\Enums\Satuan;
use App\Enums\TransactionMode;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Konversi satuan jual → satuan dasar, dan cara satu baris stok menjelaskan dirinya
 * (status, kekurangan, daftar belanja).
 *
 * Sebelum berkas ini TIDAK ADA SATU PUN uji dengan isi_per_satuan > 1 di repo ini —
 * seluruh jalur konversi Product::keSatuanDasar() hanya pernah dilewati dengan faktor
 * null. Artinya bug pada perkaliannya (lupa mengalikan, mengalikan dua kali, atau
 * mengalikan pada produk resep) tidak akan membuat satu uji pun merah, dan gejalanya di
 * lapangan cuma "stok kok tidak pernah cocok".
 */
class StokKonversiSatuanTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Konversi');
        $this->outlet = $this->buatOutlet($this->tenant, 'Toko Konversi');
        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir Konversi',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /**
     * Satuan jual dus, isi 12, terjual 2 dus → yang keluar 24 pcs, dan kartu stoknya
     * mencatat −24 (bukan −2).
     *
     * Dua angka diperiksa terpisah dengan sengaja: saldo dan mutasi bisa menyimpang satu
     * dari yang lain (mis. saldo dikurangi hasil konversi tapi mutasi mencatat qty mentah),
     * dan begitu itu terjadi kartu stok tidak bisa lagi merekonstruksi saldonya —
     * penjaga utama saat ada selisih yang harus dijelaskan.
     */
    public function test_jual_2_dus_isi_12_menurunkan_stok_24_dan_mutasinya_minus_24(): void
    {
        $produk = Product::create([
            'nama_produk' => 'Air Mineral 600ml',
            'harga_default' => 36000,
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $stok = Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 100,
        ]);

        $this->jual($produk, qty: 2, harga: 36000);

        $this->assertEqualsWithDelta(76.0, (float) $stok->fresh()->jumlah_saat_ini, 0.001,
            '100 pcs dikurangi 2 dus x 12 = 76 pcs');

        $mutasi = StockMovement::sole();

        $this->assertEqualsWithDelta(-24.0, (float) $mutasi->jumlah, 0.001,
            'mutasinya harus dalam satuan dasar, bukan 2 dus');
        $this->assertEqualsWithDelta(76.0, (float) $mutasi->saldo_sesudah, 0.001);
    }

    /** Tanpa konversi, qty dianggap sudah dalam satuan dasar — jangan ikut dikalikan. */
    public function test_produk_tanpa_isi_per_satuan_turun_apa_adanya(): void
    {
        $produk = Product::create([
            'nama_produk' => 'Kerupuk',
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);

        $stok = Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 20,
        ]);

        $this->jual($produk, qty: 3, harga: 2000);

        $this->assertEqualsWithDelta(17.0, (float) $stok->fresh()->jumlah_saat_ini, 0.001);
    }

    /* ── Status satu baris stok ──────────────────────────────────────────── */

    /**
     * Saldo −3 dengan ambang 5 adalah "minus", BUKAN "menipis".
     *
     * Keduanya benar secara aritmetika (−3 <= 5), jadi urutan pemeriksaannya yang
     * menentukan. Menyebutnya "menipis" menyamakan angka mustahil — barang tidak bisa
     * ada minus tiga di rak, itu catatan yang salah dan harus diopname — dengan barang
     * yang sekadar perlu dibeli lagi. Yang pertama tidak selesai dengan belanja.
     */
    public function test_stok_minus_3_dengan_minimum_5_statusnya_minus(): void
    {
        $stok = $this->stokDengan(jumlah: -3, minimum: 5);

        $this->assertSame('minus', $stok->statusStok());
    }

    public function test_status_stok_membedakan_habis_menipis_dan_aman(): void
    {
        $this->assertSame('habis', $this->stokDengan(0, 5)->statusStok());
        $this->assertSame('menipis', $this->stokDengan(5, 5)->statusStok(), 'tepat di ambang sudah menipis');
        $this->assertSame('menipis', $this->stokDengan(2, 5)->statusStok());
        $this->assertSame('aman', $this->stokDengan(6, 5)->statusStok());

        // Tanpa ambang tidak ada yang bisa disebut menipis; 3 pcs itu sekadar 3 pcs.
        $this->assertSame('aman', $this->stokDengan(3, 0)->statusStok());
        $this->assertSame('habis', $this->stokDengan(0, 0)->statusStok());
    }

    /* ── Kekurangan & daftar belanja ─────────────────────────────────────── */

    public function test_kekurangan_nol_kalau_ambangnya_belum_disetel(): void
    {
        $this->assertSame(0.0, $this->stokDengan(0, 0)->kekurangan(),
            'tanpa ambang, tidak ada yang bisa disebut kurang');
        $this->assertSame(0.0, $this->stokDengan(10, 5)->kekurangan());
        $this->assertEqualsWithDelta(8.0, $this->stokDengan(-3, 5)->kekurangan(), 0.001,
            'stok minus ikut dihitung: perlu 8 pcs masuk supaya kembali ke ambang 5');
    }

    /**
     * Kekurangan 29 pcs dengan 1 dus = 12 pcs → daftar belanja menyebut 3 dus.
     *
     * 29 / 12 = 2,4166… dan pembulatan ke bawah (2 dus = 24 pcs) berarti pulang dari
     * grosir masih kurang 5 pcs — perjalanan kedua untuk barang yang sudah diketahui
     * kurang sejak awal. Warung juga tidak bisa membeli 2,4 dus.
     */
    public function test_kekurangan_29_pcs_dengan_satu_dus_12_menjadi_3_dus_bukan_2_4(): void
    {
        $produk = Product::create([
            'nama_produk' => 'Air Mineral 600ml',
            'harga_default' => 36000,
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $stok = Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 1,
            'stok_minimum' => 30,
        ]);

        $this->assertEqualsWithDelta(29.0, $stok->kekurangan(), 0.001);

        $belanja = $stok->kekuranganDalamSatuanBeli();

        $this->assertNotNull($belanja);
        $this->assertSame(3.0, $belanja['jumlah'], 'dibulatkan ke ATAS: 2,4 dus tidak bisa dibeli');
        $this->assertSame(Satuan::Dus, $belanja['satuan']);
        $this->assertSame(Satuan::Pcs, $belanja['satuan_dasar']);
    }

    /** Tanpa konversi, tidak ada satuan beli yang bisa disebut — pemanggilnya jatuh ke satuan dasar. */
    public function test_kekurangan_dalam_satuan_beli_null_tanpa_konversi(): void
    {
        $produk = Product::create([
            'nama_produk' => 'Kerupuk',
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);

        $stok = Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 2,
            'stok_minimum' => 10,
        ]);

        $this->assertEqualsWithDelta(8.0, $stok->kekurangan(), 0.001);
        $this->assertNull($stok->kekuranganDalamSatuanBeli());

        // Yang cukup stok tidak pernah masuk daftar belanja.
        $stok->update(['jumlah_saat_ini' => 20]);
        $this->assertNull($stok->fresh()->kekuranganDalamSatuanBeli());
    }

    /* ── Alasan opname ───────────────────────────────────────────────────── */

    /**
     * Hanya "lainnya" yang mewajibkan catatan.
     *
     * Kalau semuanya mewajibkan, isiannya akan diisi "-" oleh orang yang sedang
     * menghitung 200 barang dan kolom catatan berhenti berarti. Sebaliknya "lainnya"
     * tanpa penjelasan tidak menyimpan informasi apa pun.
     */
    public function test_alasan_opname_hanya_lainnya_yang_butuh_catatan(): void
    {
        $this->assertTrue(AlasanOpname::Lainnya->butuhCatatan());

        foreach (AlasanOpname::cases() as $alasan) {
            if ($alasan === AlasanOpname::Lainnya) {
                continue;
            }

            $this->assertFalse($alasan->butuhCatatan(), $alasan->value.' seharusnya tidak butuh catatan');
            $this->assertNotSame('', $alasan->label());
        }
    }

    /** Alasan disimpan sebagai kode yang bisa dikelompokkan, bukan teks bebas. */
    public function test_alasan_mutasi_tersimpan_sebagai_kode_enum(): void
    {
        $stok = $this->stokDengan(0, 0);

        $mutasi = new StockMovement([
            'outlet_id' => $this->outlet->getKey(),
            'stock_id' => $stok->getKey(),
            'tipe' => 'opname',
            'alasan' => AlasanOpname::Rusak,
            'jumlah' => -2,
            'saldo_sesudah' => -2,
        ]);
        $mutasi->tenant_id = $this->tenant->getKey();
        $mutasi->save();

        $this->assertSame(AlasanOpname::Rusak, $mutasi->fresh()->alasan);
        $this->assertSame('rusak', $mutasi->fresh()->getRawOriginal('alasan'));
    }

    /**
     * Mutasi penjualan TIDAK punya alasan, dan itu memang benar: tipenya (keluar) sudah
     * menjelaskan sebabnya. Kalau kolom ini nanti dibuat wajib, seluruh sinkronisasi
     * offline berhenti — jadi nullable-nya adalah bagian dari kontraknya.
     */
    public function test_mutasi_penjualan_tidak_punya_alasan(): void
    {
        $produk = Product::create([
            'nama_produk' => 'Kerupuk',
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);

        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 10,
        ]);

        $this->jual($produk, qty: 1, harga: 2000);

        $this->assertNull(StockMovement::sole()->alasan);
    }

    /* ── Pembantu ────────────────────────────────────────────────────────── */

    private function stokDengan(float $jumlah, float $minimum): Stock
    {
        $produk = Product::create([
            'nama_produk' => 'Barang '.str()->random(5),
            'harga_default' => 1000,
            'satuan' => Satuan::Pcs,
        ]);

        return Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => $jumlah,
            'stok_minimum' => $minimum,
        ]);
    }

    private function jual(Product $produk, float $qty, float $harga): Transaction
    {
        $transaksi = Transaction::create([
            'outlet_id' => $this->outlet->getKey(),
            'staff_id' => $this->kasir->getKey(),
            'nomor_transaksi' => 'TRX-'.str()->random(6),
            'mode' => TransactionMode::Langsung,
            'subtotal' => $qty * $harga,
            'total' => $qty * $harga,
            'waktu_transaksi' => now(),
        ]);

        TransactionItem::create([
            'transaction_id' => $transaksi->getKey(),
            'product_id' => $produk->getKey(),
            'nama_produk' => $produk->nama_produk,
            'qty' => $qty,
            'harga_satuan' => $harga,
            'subtotal' => $qty * $harga,
        ]);

        app(ApplySaleToStockAction::class)->execute($transaksi->fresh(), $this->kasir->getKey());

        return $transaksi;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Pages\Owner\PembelianBaru;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Ketahanan layar Pembelian: nomor nota yang bertabrakan, tombol yang ditekan dua kali,
 * dan nota kosong.
 *
 * Ketiganya punya sifat yang sama — masing-masing menghancurkan pekerjaan yang SUDAH
 * SELESAI DIKETIK. Pemilik warung yang baru saja memasukkan 12 baris nota tidak akan
 * mengetiknya lagi; ia akan berhenti memakai fiturnya.
 */
class PembelianKetahananTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Tahan Banting');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Tahan');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Tahan',
            'email' => 'owner@tahan.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /**
     * Penjaga skema: nomor nota unik per merchant.
     *
     * Tanpa index ini, dua nota bernomor sama bisa hidup berdampingan dan pemiliknya tidak
     * punya cara membedakan keduanya — nomor itu satu-satunya pegangan yang ia sebut saat
     * mencocokkan dengan nota kertas dari grosir.
     */
    public function test_nomor_nota_unik_per_tenant_dijamin_basis_data(): void
    {
        $unik = collect(Schema::getIndexes('purchase_orders'))
            ->filter(fn (array $indeks) => $indeks['unique'] ?? false)
            ->pluck('columns')
            ->all();

        $this->assertContains(['tenant_id', 'nomor_po'], $unik);
    }

    /**
     * SIMULASI BALAPAN: nomor yang sama disisipkan TEPAT sebelum nota disimpan.
     *
     * Titik itu persis jendela cacatnya — nomor sudah dihitung dari nomor terbesar yang
     * ada, dan INSERT-nya belum mendarat. Perangkat kedua (HP pemilik dan tablet kasir,
     * dua-duanya bisa membuka layar ini) menyelesaikan insert-nya di celah itu.
     *
     * Yang diuji: pemiliknya TIDAK melihat galat. Tanpa coba-ulang, yang kalah balapan
     * mendapat 500 di depan nota 12 baris yang baru saja diketik, dan seluruh isiannya
     * hilang bersama halaman yang gagal itu.
     *
     * Uji mutasi: membuang blok catch UniqueConstraintViolationException di
     * CatatPembelianAction membuat uji ini merah dengan "UNIQUE constraint failed:
     * purchase_orders.tenant_id, purchase_orders.nomor_po" — bukan gagal assertion,
     * melainkan galat yang di lapangan berarti nota hilang.
     */
    public function test_nomor_nota_tidak_kembar_saat_dua_penyimpanan_bersamaan(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $pesaingDisisipkan = false;

        // Query builder, bukan Eloquent: supaya tidak memicu listener ini sendiri.
        PurchaseOrder::creating(function (PurchaseOrder $po) use (&$pesaingDisisipkan) {
            if ($pesaingDisisipkan) {
                return;
            }

            $pesaingDisisipkan = true;

            DB::table('purchase_orders')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $po->tenant_id,
                'outlet_id' => $po->outlet_id,
                'nomor_po' => $po->nomor_po,
                'tanggal' => now()->toDateString(),
                'total' => 0,
                'diskon' => 0,
                'ongkos_kirim' => 0,
                'status' => 'diterima',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 20, 1500)],
        ]);

        $this->assertTrue($pesaingDisisipkan, 'prasyarat: balapannya memang disimulasikan');

        // Notanya tersimpan, isinya utuh, dan stoknya bertambah sekali saja — percobaan
        // yang gagal digulung balik seluruhnya, jadi tidak ada baris setengah jadi.
        $this->assertNotNull(PurchaseOrder::query()->find($nota->getKey()));
        $this->assertSame(1, PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->count());
        $this->assertEqualsWithDelta(20.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(1, StockMovement::query()->count(),
            'percobaan yang gagal tidak boleh meninggalkan mutasi stok');
    }

    /** Nomor nota melompati nomor yang sudah dipakai perangkat lain. */
    public function test_nomor_nota_melanjutkan_dari_nomor_yang_sudah_ada(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        DB::table('purchase_orders')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'outlet_id' => $this->outlet->getKey(),
            'nomor_po' => 'PB-'.now()->format('Ymd').'-001',
            'tanggal' => now()->toDateString(),
            'total' => 0,
            'diskon' => 0,
            'ongkos_kirim' => 0,
            'status' => 'diterima',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nota = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 1, 1500)]]);

        $this->assertSame('PB-'.now()->format('Ymd').'-002', $nota->nomor_po);
    }

    /**
     * Nomor nota berdiri sendiri PER MERCHANT.
     *
     * Kalau nomornya global, merchant yang sibuk membuat nomor merchant lain melompat-lompat
     * — dan nomor yang melompat terbaca sebagai nota yang hilang.
     */
    public function test_nomor_nota_dua_tenant_boleh_sama(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $milikSendiri = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 1, 1500)]]);

        $tetangga = $this->buatTenant('Warung Tetangga Nomor');
        $outletTetangga = $this->buatOutlet($tetangga, 'Outlet Tetangga Nomor');
        $ownerTetangga = $this->buatUser($tetangga, UserRole::Owner, [
            'name' => 'Pemilik Tetangga Nomor',
            'email' => 'owner@tetangganomor.test',
            'password' => 'rahasia123',
        ]);

        $notaTetangga = $this->konteks()->forTenant($tetangga->getKey(), function () use ($outletTetangga, $ownerTetangga) {
            $produk = $this->buatProduk('Kopi Tetangga');

            return $this->catatNota($outletTetangga, $ownerTetangga, ['baris' => [$this->baris($produk, 1, 1500)]]);
        });

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->assertSame($milikSendiri->nomor_po, $notaTetangga->nomor_po);
    }

    /**
     * Tombol simpan yang ditekan dua kali.
     *
     * Bukan keadaan langka: jaringan warung lambat, tombolnya belum berubah, jarinya
     * menekan lagi. Penyimpanan yang berhasil MENGOSONGKAN isian, jadi tekanan kedua tidak
     * menemukan satu baris pun — dan itulah penjaganya. Kalau isiannya dibiarkan, nota
     * kembar tercatat lengkap dengan mutasi stoknya, dan tidak ada satu pun galat yang
     * memberi tahu bahwa stoknya sekarang dua kali lipat dari yang benar-benar dibeli.
     */
    public function test_menekan_simpan_dua_kali_tidak_menambah_stok_dua_kali(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 20)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('simpan')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(1, PurchaseOrder::query()->count(), 'hanya satu nota yang boleh tercatat');
        $this->assertEqualsWithDelta(20.0, $this->saldo($this->outlet, $kopi), 0.001,
            'stok bertambah sekali, bukan 40');
        $this->assertSame(1, StockMovement::query()->count());
    }

    /**
     * Nota tanpa satu pun baris ditolak.
     *
     * Kalau lolos, daftar pembelian terisi baris tanpa barang yang tidak bisa dibedakan
     * dari nota yang isinya gagal tersimpan — dan pemiliknya akan mengetik ulang nota yang
     * sebenarnya memang tidak pernah punya isi.
     */
    public function test_nota_tanpa_satu_pun_baris_ditolak(): void
    {
        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->call('simpan');

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    /** Lapisan aksinya sendiri — layar bukan satu-satunya pemanggil (seeder juga). */
    public function test_aksi_menolak_muatan_tanpa_baris(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->catatNota($this->outlet, $this->owner, ['baris' => []]);
    }

    /** Jumlah nol berarti barisnya tidak dibeli, jadi ia tidak boleh menjadi baris nota. */
    public function test_baris_berjumlah_nol_tidak_dihitung_sebagai_isi_nota(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 0)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('simpan');

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    /**
     * Angka yang sudah diketik bertahan saat berpindah halaman.
     *
     * Dengan 10 baris per halaman, nota belanja bulanan 40 barang butuh 4 halaman. Angka
     * yang hilang saat berpindah halaman berarti notanya diketik ulang dari awal — dan
     * simpan() memproses SEMUA baris terisi, bukan hanya yang sedang tampak di layar.
     */
    public function test_angka_bertahan_antar_halaman_dan_semua_baris_ikut_tersimpan(): void
    {
        $barang = [];

        for ($i = 1; $i <= 15; $i++) {
            $barang[] = $this->buatProduk('Barang '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $komponen = Livewire::actingAs($this->owner)->test(PembelianBaru::class);

        // Satu baris di halaman 1 …
        $komponen->set('jumlah.'.$barang[0]->getKey(), 2)
            ->set('harga.'.$barang[0]->getKey(), 1000);

        // … lalu satu lagi di halaman 2.
        $komponen->call('gotoPage', 2)
            ->set('jumlah.'.$barang[12]->getKey(), 3)
            ->set('harga.'.$barang[12]->getKey(), 2000);

        $this->assertCount(
            2,
            array_filter($komponen->get('jumlah'), fn (mixed $n) => $n !== null && $n !== ''),
            'angka dari halaman 1 tidak boleh hilang saat pindah ke halaman 2',
        );

        $komponen->call('simpan')->assertHasNoErrors();

        $nota = PurchaseOrder::query()->sole();

        $this->assertSame(2, $nota->items()->count(), 'baris di halaman lain tidak boleh tertinggal');
        $this->assertEqualsWithDelta(2.0, $this->saldo($this->outlet, $barang[0]), 0.001);
        $this->assertEqualsWithDelta(3.0, $this->saldo($this->outlet, $barang[12]), 0.001);
        $this->assertEqualsWithDelta(8000.0, (float) $nota->total, 0.01);
    }
}

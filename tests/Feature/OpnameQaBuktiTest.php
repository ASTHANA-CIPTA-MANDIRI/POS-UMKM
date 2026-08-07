<?php

namespace Tests\Feature;

use App\Actions\Stock\AdjustStockAction;
use App\Enums\AlasanOpname;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Stok\Opname;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * QA — pembuktian cacat pada "kunci outlet lembar opname" (bukan perbaikan, hanya bukti).
 *
 * Fokus: apakah $sistemSaatDibuka bisa memuat angka yang TIDAK PERNAH benar-benar tampil
 * di layar pada saat baris itu diketik ULANG, lewat jalur penggantian $fisik SECARA UTUH
 * (array-wide), bukan lewat wire:model.blur per baris.
 */
class OpnameQaBuktiTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $cabangA;

    private Outlet $cabangB;

    private User $owner;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko QA Bukti');
        $this->cabangA = $this->buatOutlet($this->tenant, 'Cabang A Pusat');
        $this->cabangB = $this->buatOutlet($this->tenant, 'Cabang B Ruko');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik QA',
            'email' => 'owner@qabukti.test',
            'password' => 'rahasia123',
        ]);

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir QA',
            'username' => 'kasirqabukti',
            'pin_hash' => '123456',
            'outlet_id' => $this->cabangA->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function buatProduk(string $nama): Product
    {
        return Product::create([
            'nama_produk' => $nama,
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ]);
    }

    private function buatStok(Outlet $outlet, Product $produk, float $jumlah): Stock
    {
        return Stock::create([
            'outlet_id' => $outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => $jumlah,
            'stok_minimum' => 0,
        ]);
    }

    /**
     * CACAT: mengosongkan $fisik lewat penggantian ARRAY UTUH (bukan per-baris lewat
     * wire:model.blur) melepas $outletTerkunci (benar) TAPI TIDAK membersihkan
     * $sistemSaatDibuka (salah) — beda dari mengosongkan satu-satu, yang per barisnya
     * ke-unset lewat updatedFisik($nilai, $kunci) karena $kunci-nya tidak null.
     *
     * Ini membuktikan bahwa `updatedFisik()` cacat untuk kasus $kunci === null: ia
     * memanggil segarkanKunciOutlet() (lepas kunci kalau kosong) tapi `return` sebelum
     * baris pembersihan $sistemSaatDibuka, yang hanya jalan kalau $kunci tidak null.
     *
     * Akibatnya: sistemSaatDibuka menyimpan angka SISA dari sesi pengisian SEBELUMNYA.
     * Kalau baris yang sama diketik ULANG (di outlet yang SAMA, tanpa pindah cabang sama
     * sekali — jadi bukan Cacat A/B versi lintas-cabang), `??=` di updatedFisik()
     * mempertahankan angka lama itu walau saldo sistemnya sudah bergerak SEBELUM
     * pengetikan ulang terjadi. Catatan kartu stok lalu mengarang "layar menunjukkan 100"
     * padahal layar, pada saat baris itu benar-benar diketik ulang, menunjukkan 70.
     */
    public function test_penggantian_fisik_array_utuh_ikut_membersihkan_sistem_saat_dibuka(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $stokA = $this->buatStok($this->cabangA, $beras, 100);

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        // Ketik 50 di Cabang A — sistemSaatDibuka[beras] terekam = 100 (saldo saat ini).
        $komponen->set('fisik.'.$beras->getKey(), 50);

        $this->assertEqualsWithDelta(
            100.0,
            (float) ($komponen->get('sistemSaatDibuka')[$beras->getKey()] ?? 0),
            0.001
        );
        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'));

        // Dikosongkan lewat penggantian ARRAY UTUH — bukan wire:model.blur per baris.
        // (Ini bukan interaksi UI biasa, tapi cacatnya ada di kode server, dan task ini
        // secara eksplisit meminta jalur ini diuji: "fisik diisi lalu dikosongkan lewat
        // array utuh".)
        $komponen->set('fisik', []);

        // Kunci memang lepas — itu benar.
        $this->assertNull($komponen->get('outletTerkunci'), 'kunci memang harus lepas: lembar kosong');
        $this->assertSame(0, $komponen->viewData('jumlahTerisi'));

        // sistemSaatDibuka ikut bersih: penggantian seluruh array datang TANPA $kunci, jadi
        // jalur per-baris tidak jalan dan harus diselaraskan sendiri.
        $this->assertArrayNotHasKey(
            $beras->getKey(),
            $komponen->get('sistemSaatDibuka'),
            'entri dari sesi pengisian sebelumnya harus lenyap saat $fisik dikosongkan'
        );

        // Sekarang saldo bergerak SEBELUM diketik ulang (kasir menjual 30, saldo 100→70).
        app(AdjustStockAction::class)->execute(
            $stokA,
            StockMovementType::Keluar,
            -30,
            olehUserId: $this->kasir->getKey(),
        );

        // Diketik ULANG, masih di Cabang A — tidak ada perpindahan cabang sama sekali.
        $komponen
            ->set('fisik.'.$beras->getKey(), 65)
            ->set('alasan.'.$beras->getKey(), AlasanOpname::Hilang->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::query()->where('tipe', StockMovementType::Opname)->sole();

        // Selisihnya tetap benar secara UANG (65 - 70 saldo saat simpan) — tidak ada uji
        // stok yang gagal karena ini, seperti Cacat B versi lintas-cabang.
        $this->assertEqualsWithDelta(-5.0, (float) $mutasi->jumlah, 0.001);

        $catatan = (string) $mutasi->catatan;

        /*
         * Catatannya tidak boleh menyebut 100. Angka itu berasal dari sesi pengisian SEBELUM
         * kolomnya dikosongkan; yang benar-benar tampil saat baris ini diketik ULANG adalah
         * 70 — sama dengan saldo saat disimpan, karena saldo tidak bergerak lagi di antara
         * ketik-ulang dan simpan. Jadi tidak ada pergerakan yang perlu dijelaskan.
         *
         * Uangnya tidak terpengaruh (selisih −5 sudah dipastikan di atas), jadi cacat ini
         * TIDAK akan pernah muncul sebagai uji stok yang gagal. Uji inilah satu-satunya
         * penjaganya.
         */
        $this->assertStringNotContainsString('layar menunjukkan 100', $catatan,
            'catatan audit tidak boleh menyebut angka dari sesi pengisian yang sudah dibuang');
        $this->assertStringNotContainsString('Saldo bergerak', $catatan,
            'tidak ada pergerakan antara ketik-ulang dan simpan, jadi jangan mengaku ada');
    }

    /**
     * CACAT LEBIH LUAS DARI YANG DIDUGA: bahkan mengosongkan SATU BARIS lewat
     * wire:model.blur biasa (bukan array utuh — ini jalur UI SUNGGUHAN, yang benar-benar
     * dipakai pengguna) tidak benar-benar membersihkan $sistemSaatDibuka.
     *
     * unset() di updatedFisik() memang jalan (kalau diperiksa PERSIS pada saat itu, kuncinya
     * hilang) — tapi Livewire selalu me-render ULANG sesudah setiap pembaruan properti, dan
     * render() memanggil rekamAngkaTerbaca() yang men-scan SEMUA baris yang sedang tampak di
     * halaman dan mengisi ulang $sistemSaatDibuka[$kunci] ??= (float) $satu['sistem'] — TANPA
     * peduli apakah baris itu sedang diisi $fisik atau tidak. Baris yang baru saja
     * dikosongkan MASIH tampak di halaman yang sama, jadi render berikutnya langsung mengisi
     * ulang entrinya. unset() di updatedFisik() jadi kerja sia-sia: nilainya kembali sebelum
     * pengguna sempat melakukan apa pun lagi.
     *
     * Ini persis mekanisme Cacat B (dari docblok Opname::sistemSaatDibuka), tapi terjadi
     * TANPA pindah cabang sama sekali — cukup dengan MELIHAT baris itu tetap tampak di
     * halaman yang sama sesudah dikosongkan.
     */
    public function test_mengosongkan_satu_baris_lewat_wire_model_blur_benar_benar_membersihkan_sistem_saat_dibuka(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        $komponen->set('fisik.'.$beras->getKey(), 50);
        $this->assertArrayHasKey($beras->getKey(), $komponen->get('sistemSaatDibuka'));

        // Dikosongkan PER KUNCI, tepat seperti wire:model.blur="fisik.{{kunci}}" di Blade
        // sungguhan mengirimkannya — ini BUKAN jalur sintetis.
        $komponen->set('fisik.'.$beras->getKey(), '');

        // Yang DIHARAPKAN pengguna (dan yang dijanjikan komentar kode di updatedFisik():
        // "baris terakhir yang dikosongkan membebaskannya kembali") adalah entrinya hilang.
        $this->assertArrayNotHasKey(
            $beras->getKey(),
            $komponen->get('sistemSaatDibuka'),
            'unset() harus BERTAHAN: Livewire merender sesudah setiap pembaruan, dan '.
            'rekamAngkaTerbaca() dulu mengisinya ulang pada siklus yang sama selama barisnya '.
            'masih tampak — sehingga pembersihannya tidak pernah benar-benar terjadi.'
        );
    }

    /**
     * CACAT — kunci yang PRAKTIS tidak pernah lepas: satu karakter SPASI dianggap "terisi"
     * oleh diisi() (nilai !== null && nilai !== ''), padahal blank() Laravel (dipakai di
     * updatedFisik() sendiri untuk melepas kunci) menganggap string berisi spasi sebagai
     * KOSONG (blank() memakai trim()). Dua fungsi yang seharusnya sepakat tentang "apa itu
     * kosong" tidak sepakat.
     *
     * Reproduksi realistis: pemilik mengetik lalu menghapus isian dengan tidak sengaja
     * menyisakan satu spasi (mis. menekan tombol panah/tab sesudah spasi, atau memang
     * mengetik spasi sebelum angka lalu menghapus angkanya saja) di SATU baris dari puluhan.
     * Kotaknya terlihat KOSONG di layar, tapi $fisik menganggapnya terisi, kuncinya tetap
     * menempel ke cabang itu, dan simpan() menolak seluruh lembar karena baris itu tidak lolos
     * validasi 'numeric'. Baris yang gagal validasi TIDAK dibersihkan (periksa() melempar
     * ValidationException sebelum baris manapun ditandai berhasil), jadi ia tetap
     * "terisi" selamanya sampai pemiliknya entah bagaimana menemukan baris yang mana, di
     * antara berapa pun halaman, dan menghapus SATU spasi yang tidak kelihatan.
     */
    public function test_satu_spasi_dihitung_kosong_dan_tidak_mengunci_lembar(): void
    {
        $beras = $this->buatProduk('Beras Premium');
        $this->buatStok($this->cabangA, $beras, 100);
        $this->buatStok($this->cabangB, $beras, 20);

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        // Kotak fisiknya diisi SATU SPASI — persis apa yang wire:model.blur kirim kalau
        // pemilik tidak sengaja menekan tombol spasi di kolom itu.
        $komponen->set('fisik.'.$beras->getKey(), ' ');

        /*
         * Spasi harus dihitung KOSONG, sama seperti blank() di updatedFisik() dan seperti
         * Alpine di resources/js/opname.js yang memakai .trim().
         *
         * Kalau tidak sepakat, akibatnya adalah kunci yang TERLIHAT lepas tapi tidak: server
         * berkata "1 baris sudah dihitung" sementara layar tidak menampilkan satu kotak
         * terisi pun — kolomnya tidak diwarnai dan lencana "wajib pilih alasan" tidak muncul,
         * karena Alpine sudah men-trim. Pemiliknya harus menyisir 12 halaman mencari karakter
         * yang tidak kelihatan, atau membuang seluruh hasil hitungnya.
         */
        $this->assertSame(0, $komponen->viewData('jumlahTerisi'),
            'satu spasi bukan angka; tiga tempat yang memutuskan "terisi" harus sepakat');

        $this->assertNull($komponen->get('outletTerkunci'),
            'lembar yang praktis kosong harus bebas pindah cabang');

        // Dan perpindahan cabang benar-benar berjalan, bukan ditolak dengan pesan yang
        // menyebut baris yang tidak bisa dilihat pemiliknya.
        $komponen->set('outletId', $this->cabangB->getKey());
        $this->assertSame($this->cabangB->getKey(), $komponen->get('outletId'));
        $komponen->assertDontSee('baris sudah dihitung');

        // Nol tetap ANGKA SAH — pernyataan "rak ini kosong", justru selisih terbesar yang
        // bisa ada. trim('0') tetap '0', jadi memangkas tidak boleh mengubah ini.
        $komponen->set('outletId', $this->cabangA->getKey())
            ->set('fisik.'.$beras->getKey(), '0');

        $this->assertSame(1, $komponen->viewData('jumlahTerisi'), 'nol harus terhitung terisi');
        $this->assertSame($this->cabangA->getKey(), $komponen->get('outletTerkunci'));
    }
}

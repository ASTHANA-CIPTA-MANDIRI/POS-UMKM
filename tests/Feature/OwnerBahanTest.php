<?php

namespace Tests\Feature;

use App\Actions\Stock\ApplySaleToStockAction;
use App\Enums\BusinessType;
use App\Enums\Satuan;
use App\Enums\TransactionMode;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Bahan as LayarBahan;
use App\Livewire\Pages\Owner\Stok as LayarStok;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RawMaterial;
use App\Models\RecipeItem;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Layar Bahan baku — satu-satunya pintu untuk membuat bahan mentah.
 *
 * Mesin resepnya sudah ada dan sudah benar sejak lama: `recipe_items` menyimpan resep,
 * `ApplySaleToStockAction` memotong bahan per porsi terjual, `SusunBarisStokAction`
 * menampilkan bahan beserta satuannya. Yang tidak ada adalah layar untuk MEMBUAT bahannya
 * — sampai layar ini dibangun, satu-satunya jalan adalah seeder. Jadi pemilik warteg yang
 * menjual "lele goreng" per porsi tapi membeli lele per kilogram tidak punya cara
 * memasukkan bahannya sendiri.
 *
 * Yang dijaga berkas ini, dan tiap butirnya lahir dari cacat yang bisa terjadi tanpa satu
 * pun galat di layar:
 *
 * - bahan baru TIDAK membuat baris `stocks` (baris bersaldo 0 berstatus 'habis', dan
 *   warteg 30 bahan akan melihat 30 lencana merah di hari pertama);
 * - "58.000" tidak tersimpan sebagai Rp 58;
 * - satuan bahan yang sudah punya angka TIDAK bisa diubah (kg→gram membuat 60 kg terbaca
 *   60 gram — cacat 1000× tanpa satu pun peringatan);
 * - bahan yang masih dipakai resep TIDAK bisa dihapus, dan gerbangnya di SERVER karena
 *   `restrictOnDelete` tidak pernah menyala untuk soft delete.
 */
class OwnerBahanTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warteg Bahan');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@bahan.test',
            'password' => 'rahasia123',
        ]);

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir',
            'username' => 'kasir-bahan',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Bantuan ─────────────────────────────────────────────────────────── */

    private function layar(): Testable
    {
        return Livewire::actingAs($this->owner)->test(LayarBahan::class);
    }

    private function buatBahan(string $nama, Satuan $satuan = Satuan::Kg, ?float $harga = null): RawMaterial
    {
        return RawMaterial::create([
            'nama' => $nama,
            'satuan' => $satuan,
            'harga_beli_terakhir' => $harga,
        ]);
    }

    /** Menu berbasis resep: lacak_stok mati karena yang dilacak bahan bakunya. */
    private function buatMenu(string $nama, RawMaterial $bahan, float $pakai = 0.25): Product
    {
        $menu = Product::create([
            'nama_produk' => $nama,
            'harga_default' => 15000,
            'satuan' => Satuan::Porsi,
            'lacak_stok' => false,
        ]);

        RecipeItem::create([
            'product_id' => $menu->getKey(),
            'raw_material_id' => $bahan->getKey(),
            'jumlah_terpakai' => $pakai,
        ]);

        return $menu;
    }

    /* ── 1 & 2. Bahan baru muncul di Stok, tanpa membuat baris stocks ────── */

    /**
     * Kriteria 1: bahan "Lele Segar" satuan kg disimpan → muncul di layar Stok SETIAP outlet
     * tenant itu berstatus `belum_dihitung`, BUKAN `habis`.
     *
     * "Setiap outlet" bukan hiasan: bahan adalah master milik tenant, sementara stok dicatat
     * per outlet. Bahan yang hanya muncul di outlet pertama berarti cabang kedua tidak bisa
     * menghitungnya, dan barang yang tidak muncul di layar adalah barang yang datanya tidak
     * akan pernah diperbaiki.
     */
    public function test_bahan_lele_segar_satuan_kg_disimpan_muncul_di_layar_stok_setiap_outlet_berstatus_belum_dihitung_bukan_habis(): void
    {
        $outletKedua = $this->buatOutlet($this->tenant, 'Outlet Kedua');

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Lele Segar')
            ->set('satuan', 'kg')
            ->call('simpan')
            ->assertHasNoErrors();

        foreach ([$this->outlet, $outletKedua] as $outlet) {
            $baris = collect(
                Livewire::actingAs($this->owner)
                    ->test(LayarStok::class)
                    ->set('outletId', $outlet->getKey())
                    ->viewData('daftar')
                    ->items()
            )->firstWhere('nama', 'Lele Segar');

            $this->assertNotNull($baris, "Lele Segar tidak muncul di layar Stok outlet {$outlet->outlet_name}");
            $this->assertSame('belum_dihitung', $baris['status'],
                'bahan yang belum pernah dicatat BUKAN "habis": nol adalah pernyataan bahwa '
                .'raknya kosong, dan bahan baru tidak pernah menyatakan itu');
            $this->assertFalse($baris['punya_baris']);
            $this->assertSame('kg', $baris['satuan']);
        }
    }

    /**
     * Kriteria 2: bahan baru disimpan → TIDAK ADA satu baris `stocks` pun yang dibuat.
     *
     * Baris bersaldo 0 berstatus 'habis' (Stock::statusStok()), jadi warteg dengan 30 bahan
     * akan melihat 30 lencana merah di hari pertama. Hari kedua pemiliknya belajar
     * mengabaikan merah, dan hari bahan pertama benar-benar kosong, merah sudah tidak
     * berarti apa-apa lagi.
     *
     * withoutGlobalScopes() supaya baris di outlet/tenant mana pun ikut terhitung — kalau
     * hanya menghitung di bawah scope, baris yang lahir di tempat yang salah justru
     * tersembunyi dari uji ini.
     */
    public function test_bahan_baru_disimpan_tidak_ada_satu_baris_stocks_pun_yang_dibuat(): void
    {
        $this->buatOutlet($this->tenant, 'Outlet Kedua');

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Lele Segar')
            ->set('satuan', 'kg')
            ->set('hargaBeli', '58.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(0, Stock::withoutGlobalScopes()->count(),
            'membuat bahan tidak boleh membuat baris stok — saldonya belum pernah dihitung '
            .'siapa pun, dan baris bersaldo 0 terbaca sebagai "habis"');
    }

    /* ── 3. Validasi wajib ───────────────────────────────────────────────── */

    /** Kriteria 3: nama dikosongkan / satuan bukan anggota enum → ditolak, pesannya menyebut medannya. */
    public function test_nama_dikosongkan_atau_satuan_bukan_anggota_enum_ditolak_dengan_pesan_menyebut_medannya(): void
    {
        $komponen = $this->layar()
            ->call('tambah')
            ->set('nama', '')
            ->set('satuan', 'kg')
            ->call('simpan')
            ->assertHasErrors(['nama' => 'required']);

        // Pesannya, bukan cuma keberadaan galatnya: "The nama field is required" tidak
        // memberi tahu medan MANA di formulir yang menahan tombol simpan.
        $this->assertStringContainsString('nama bahan',
            $komponen->errors()->first('nama'),
            'pesan galat harus menyebut nama medannya seperti tertulis di layar');

        $galatSatuan = $this->layar()
            ->call('tambah')
            ->set('nama', 'Lele Segar')
            // 'ekor' SENGAJA: ia terdengar wajar untuk lele, dan justru itu yang membuatnya
            // contoh yang benar — satuan yang tidak ada di enum harus ditolak, bukan
            // tersimpan sebagai teks bebas yang tidak dikenali satu pun hitungan.
            ->set('satuan', 'ekor')
            ->call('simpan')
            ->assertHasErrors('satuan')
            ->errors()->first('satuan');

        $this->assertStringContainsString('satuan', $galatSatuan);
        $this->assertSame(0, RawMaterial::count(), 'tidak ada yang boleh tersimpan saat ditolak');
    }

    /* ── 4. Nama unik per tenant ─────────────────────────────────────────── */

    /**
     * Kriteria 4: nama sama dengan bahan yang masih hidup → ditolak; sama dengan bahan
     * TERHAPUS → diterima.
     *
     * Arah kedua yang mudah dilupakan: bahan memakai soft delete, jadi nama yang pernah
     * dihapus masih ada di tabel. Melarangnya dipakai lagi berarti pemilik yang salah hapus
     * tidak punya jalan kembali selain mengarang "Lele Segar 2" — nama karangan yang lalu
     * ikut muncul di seluruh laporan.
     */
    public function test_nama_sama_dengan_bahan_yang_masih_hidup_ditolak_sama_dengan_bahan_terhapus_diterima(): void
    {
        $this->buatBahan('Lele Segar');

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Lele Segar')
            ->set('satuan', 'kg')
            ->call('simpan')
            ->assertHasErrors('nama');

        $this->assertSame(1, RawMaterial::count());

        // Sekarang bahan lamanya dihapus; nama yang sama harus BOLEH dipakai lagi.
        RawMaterial::query()->where('nama', 'Lele Segar')->firstOrFail()->delete();

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Lele Segar')
            ->set('satuan', 'kg')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(1, RawMaterial::count(), 'satu bahan hidup bernama Lele Segar');
        $this->assertSame(2, RawMaterial::withTrashed()->count(), 'yang lama tetap tersimpan');
    }

    /** Menyimpan tanpa mengubah nama tidak boleh ditolak oleh dirinya sendiri. */
    public function test_menyimpan_bahan_tanpa_mengubah_namanya_tidak_ditolak_oleh_dirinya_sendiri(): void
    {
        $bahan = $this->buatBahan('Lele Segar');

        $this->layar()
            ->call('ubah', $bahan->getKey())
            ->set('hargaBeli', '60.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(60000, (int) $bahan->fresh()->harga_beli_terakhir);
    }

    /**
     * Formulir "Ubah" harus MENCETAK nilai yang tersimpan ke dalam HTML-nya.
     *
     * Cacat nyata, dan yang sama sudah pernah terjadi di kolom batas minimal layar Stok:
     * Livewire TIDAK mencetak nilai awal untuk kolom ber-`wire:model`, jadi tanpa `value=`
     * dan `@selected` formulirnya terbuka dengan nama kosong, harga kosong, dan satuan
     * menunjuk pilihan PERTAMA (Pcs) — padahal server memegang "Lele Segar", 58000, kg.
     *
     * Yang membuatnya merugikan, bukan sekadar kurang enak: menyimpan dari keadaan itu
     * MENGHAPUS harga yang sudah ada, dan kolom satuan justru berbahaya ke arah sebaliknya —
     * orangnya membaca "Pcs", tidak menyentuh apa pun, lalu bahannya tetap kg. Tidak ada
     * galat di kedua arah.
     *
     * Diperiksa atas HTML terender, bukan atas properti komponennya: propertinya memang
     * sudah benar sejak awal — yang salah adalah apa yang sampai ke layar.
     */
    public function test_formulir_ubah_mencetak_nama_harga_dan_satuan_yang_tersimpan(): void
    {
        $bahan = $this->buatBahan('Minyak Goreng', Satuan::Liter, harga: 18000);

        $html = $this->layar()->call('ubah', $bahan->getKey())->html();

        $this->assertStringContainsString('value="Minyak Goreng"', $html);
        $this->assertStringContainsString('value="18000"', $html);
        $this->assertStringContainsString('value="liter" selected', $html,
            'tanpa @selected, <select> menampilkan Pcs sementara server memegang liter');

        // Dan yang BUKAN satuannya tidak boleh ikut tertandai — dua option selected membuat
        // peramban memilih yang terakhir, jadi penjaga ini harus memeriksa keduanya.
        $this->assertStringNotContainsString('value="pcs" selected', $html);
    }

    /* ── 5. Uang ─────────────────────────────────────────────────────────── */

    /**
     * Kriteria 5: harga beli `58.000` → tersimpan 58000; `58.5` → ditolak.
     *
     * Cacat yang sudah benar-benar terjadi di kolom uang lain (komit 96d4844):
     * `is_numeric('58.000')` bernilai true dan `(float) '58.000'` bernilai 58.0, jadi
     * `numeric|min:0` MELOLOSKANNYA dan harga Rp 58.000 tersimpan sebagai Rp 58 tanpa satu
     * pun galat di layar. Angka ini dipakai layar Stok untuk menghitung nilai persediaan;
     * salah seribu kali di situ baru terbaca berbulan kemudian.
     *
     * "58.5" DITOLAK, bukan dibulatkan: 58 atau 59 sama-sama menebak, dan rupiah yang
     * diketik orang tidak punya sen.
     */
    public function test_harga_beli_58_000_tersimpan_58000_dan_58_5_ditolak(): void
    {
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Lele Segar')
            ->set('satuan', 'kg')
            ->set('hargaBeli', '58.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(58000, (int) RawMaterial::query()->where('nama', 'Lele Segar')->value('harga_beli_terakhir'),
            '"58.000" adalah cara orang Indonesia menulis lima puluh delapan ribu, bukan 58');

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Ayam Potong')
            ->set('satuan', 'kg')
            ->set('hargaBeli', '58.5')
            ->call('simpan')
            ->assertHasErrors('hargaBeli');

        $this->assertNull(RawMaterial::query()->where('nama', 'Ayam Potong')->first(),
            'bentuk bersen tidak boleh tersimpan dengan angka yang ditebak');
    }

    /** Harga boleh dikosongkan: "belum diisi" bukan "salah ketik", dan validatornya nullable. */
    public function test_harga_beli_boleh_dikosongkan(): void
    {
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Kangkung')
            ->set('satuan', 'ikat')
            ->set('hargaBeli', '')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertNull(RawMaterial::query()->where('nama', 'Kangkung')->value('harga_beli_terakhir'));
    }

    /* ── 6 & 7. Hapus ───────────────────────────────────────────────────── */

    /**
     * Kriteria 6: bahan dipakai resep lalu hapus dipanggil LANGSUNG sebagai aksi tanpa
     * dialog → server menolak, pesannya menyebut nama menunya.
     *
     * Dipanggil LANGSUNG dan itu inti ujinya: dialog SweetAlert di Blade bukan pengaman —
     * muatan Livewire bisa dikirim tanpa pernah melewati dialog apa pun.
     *
     * `restrictOnDelete` pada `recipe_items.raw_material_id` TIDAK menolong: RawMaterial
     * memakai SoftDeletes, jadi yang terjadi hanya `deleted_at` terisi dan kunci asingnya
     * tidak pernah tersentuh. Penolakan basis datanya tidak pernah terjadi.
     */
    public function test_bahan_dipakai_resep_lalu_hapus_dipanggil_langsung_tanpa_dialog_ditolak_server_dengan_nama_menunya(): void
    {
        $bahan = $this->buatBahan('Lele Segar');
        $this->buatMenu('Lele Goreng', $bahan);
        $this->buatMenu('Pecel Lele', $bahan);

        $this->layar()
            ->call('hapus', $bahan->getKey())
            ->assertDispatched('toast', function (string $nama, array $muatan): bool {
                return $muatan['jenis'] === 'galat'
                    && str_contains($muatan['pesan'], 'Lele Segar masih dipakai resep')
                    // Nama MENUNYA, bukan cuma "masih dipakai": tanpa nama menunya pemilik
                    // tidak punya cara tahu resep mana yang harus dibuka.
                    && str_contains($muatan['pesan'], 'Lele Goreng')
                    && str_contains($muatan['pesan'], 'Pecel Lele')
                    && str_contains($muatan['pesan'], 'Keluarkan dulu dari resepnya');
            });

        $this->assertNull($bahan->fresh()->deleted_at, 'bahannya tidak boleh terhapus');
    }

    /**
     * Kriteria 7: bahan tidak dipakai resep → hapus berhasil, `deleted_at` terisi, nota
     * belanja lama yang menunjuknya tetap terbaca.
     *
     * Soft delete, bukan hapus permanen: `purchase_order_items` menunjuk `raw_material_id`
     * dengan `restrictOnDelete`, dan menghapus bahannya permanen akan mengubah — atau
     * menggagalkan — nota belanja bulan lalu hari ini.
     */
    public function test_bahan_tidak_dipakai_resep_hapus_berhasil_deleted_at_terisi_nota_belanja_lama_tetap_terbaca(): void
    {
        $bahan = $this->buatBahan('Lele Segar', harga: 58000);

        $nota = PurchaseOrder::create([
            'outlet_id' => $this->outlet->getKey(),
            'dibuat_oleh' => $this->owner->getKey(),
            'nomor_po' => 'NB-BAHAN-001',
            'tanggal' => today(),
            'total' => 58000,
        ]);

        $baris = PurchaseOrderItem::create([
            'purchase_order_id' => $nota->getKey(),
            'raw_material_id' => $bahan->getKey(),
            'qty' => 1,
            'qty_beli' => 1,
            'satuan_beli' => Satuan::Kg->value,
            'harga_satuan' => 58000,
            'subtotal' => 58000,
        ]);

        $this->layar()->call('hapus', $bahan->getKey())->assertHasNoErrors();

        $this->assertNotNull($bahan->fresh()->deleted_at);
        $this->assertSame(0, RawMaterial::count(), 'hilang dari daftar');

        $barisSegar = $baris->fresh();
        $this->assertNotNull($barisSegar, 'baris nota belanja lama tidak boleh ikut terhapus');
        $this->assertSame(58000, (int) $barisSegar->subtotal, 'angka notanya tidak berubah');
        $this->assertSame('kg', $barisSegar->satuan_beli, 'potret satuannya tetap di barisnya sendiri');

        // Namanya masih TERSIMPAN dan bisa dibaca — soft delete, jadi barisnya masih ada.
        //
        // TEMUAN yang dilaporkan ke lead, BUKAN dijaga di sini karena berkasnya bukan milik
        // gelombang ini: PurchaseOrderItem::namaItem() membaca relasi `rawMaterial` tanpa
        // withTrashed(), jadi layar Nota belanja menampilkan "-" untuk baris yang bahannya
        // sudah dihapus. Angkanya utuh, namanya yang hilang dari LAYAR. Ujinya sengaja tidak
        // menegaskan "-": menegaskannya berarti memaku cacat itu jadi perilaku resmi, dan
        // perbaikannya nanti akan terbaca sebagai kegagalan uji.
        $this->assertSame('Lele Segar',
            RawMaterial::withTrashed()->findOrFail($bahan->getKey())->nama);
    }

    /**
     * Kenapa gerbang di kriteria 6 harus ada — akibatnya kalau ia dilewati, dan semuanya
     * SENYAP.
     *
     * Uji ini SENGAJA melewati komponen dan menghapus bahannya langsung di model, seperti
     * yang akan terjadi kalau gerbang di Bahan::hapus() suatu hari dihilangkan. Rantai
     * kerusakannya:
     *
     *   1. `ApplySaleToStockAction` tetap memotong bahan itu — ia membaca `recipeItems`,
     *      yang tidak tahu apa pun tentang `deleted_at` bahan;
     *   2. `SiapkanBarisStokAction` MEMBUAT baris `stocks`-nya tanpa memeriksa apa pun;
     *   3. `SusunBarisStokAction` menyembunyikan barisnya lewat SoftDeletingScope, jadi ia
     *      tidak muncul di layar Stok maupun lembar Hitung stok.
     *
     * Hasilnya: stok berjalan MINUS di baris yang tidak muncul di layar mana pun, dan tidak
     * ada satu pun tanda di aplikasi yang bisa dipakai orang untuk menemukannya. Uji ini
     * mencatat kenyataan itu apa adanya — kalau suatu hari perilakunya berubah (mis. aksi
     * penjualan ikut menyaring bahan terhapus), uji ini yang akan memberi tahu bahwa
     * gerbang di komponen bukan lagi satu-satunya penahan.
     */
    public function test_menjual_menu_ber_bahan_terhapus_membuat_stok_minus_di_baris_yang_tidak_muncul_di_layar_mana_pun(): void
    {
        $bahan = $this->buatBahan('Lele Segar');
        $menu = $this->buatMenu('Lele Goreng', $bahan, pakai: 0.25);

        // Gerbang komponen DILEWATI dengan sengaja: inilah keadaan yang mau dibuktikan.
        $bahan->delete();

        $transaksi = Transaction::create([
            'outlet_id' => $this->outlet->getKey(),
            'staff_id' => $this->kasir->getKey(),
            'nomor_transaksi' => 'TRX-BAHAN-TERHAPUS',
            'mode' => TransactionMode::Langsung,
            'subtotal' => 30000,
            'total' => 30000,
            'waktu_transaksi' => now(),
        ]);

        TransactionItem::create([
            'transaction_id' => $transaksi->getKey(),
            'product_id' => $menu->getKey(),
            'nama_produk' => $menu->nama_produk,
            'qty' => 2,
            'harga_satuan' => 15000,
            'subtotal' => 30000,
        ]);

        app(ApplySaleToStockAction::class)->execute($transaksi->fresh(), $this->kasir->getKey());

        // withoutGlobalScopes() WAJIB: di bawah scope biasa barisnya memang terlihat (Stock
        // tidak memakai SoftDeletes), tapi relasi rawMaterial-nya null — dan itulah yang
        // membuatnya lenyap dari setiap layar.
        $baris = Stock::withoutGlobalScopes()
            ->where('raw_material_id', $bahan->getKey())
            ->sole();

        $this->assertEqualsWithDelta(-0.5, (float) $baris->jumlah_saat_ini, 0.001,
            'penjualan tetap memotong bahan yang sudah terhapus — 2 porsi × 0,25 kg');

        // Dan barisnya TIDAK muncul di layar Stok. Ini bagian yang membuat cacatnya senyap.
        $namaDiLayar = collect(
            Livewire::actingAs($this->owner)
                ->test(LayarStok::class)
                ->set('outletId', $this->outlet->getKey())
                ->viewData('daftar')
                ->items()
        )->pluck('nama');

        $this->assertFalse($namaDiLayar->contains('Lele Segar'),
            'baris minus itu tersembunyi SoftDeletingScope — pemilik tidak punya cara menemukannya, '
            .'jadi gerbang di Bahan::hapus() adalah satu-satunya penahan');
    }

    /* ── 8. Halaman ──────────────────────────────────────────────────────── */

    /**
     * Kriteria 8: 25 baris → halaman pertama 10 baris dan `->links()` terender.
     *
     * Angkanya dari config('nampan.per_halaman'), bukan diketik di komponen —
     * PenjagaPerHalamanTest memeriksa SUMBERNYA. Yang diperiksa di sini akibatnya di layar:
     * daftar tanpa navigasi halaman menyembunyikan 15 bahan tanpa memberi tahu siapa pun.
     */
    public function test_dua_puluh_lima_baris_halaman_pertama_sepuluh_baris_dan_links_terender(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->buatBahan('Bahan '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $komponen = $this->layar();
        $daftar = $komponen->viewData('daftar');

        $this->assertSame(10, $daftar->count());
        $this->assertSame(25, $daftar->total());
        $this->assertSame(3, $daftar->lastPage());

        // Navigasi halamannya benar-benar ada di HTML, bukan hanya di objek paginatornya.
        $komponen->assertSee('Bahan 01')
            ->assertDontSee('Bahan 11')
            ->assertSeeHtml('aria-label="Navigasi halaman"')
            ->assertSee('halaman 1/3');
    }

    /* ── 9. Sidebar per jenis usaha ─────────────────────────────────────── */

    /**
     * Kriteria 9: `business_type` kelontong → menu "Bahan & resep" TIDAK ada di sidebar;
     * fnb/campuran → ada.
     *
     * Aturannya BusinessType::supportsRecipe(), yang sudah ada di enum dan sebelum ini belum
     * dipakai di mana pun. Presedennya pakaiBarcode() di layar produk.
     *
     * Rutenya SENGAJA tidak diblokir — lihat uji di bawah. Menyembunyikan menu adalah soal
     * kerapian daftar, bukan keamanan, jadi ia tidak dijaga seperti keamanan.
     */
    public function test_business_type_kelontong_menu_bahan_tidak_ada_di_sidebar_fnb_dan_campuran_ada(): void
    {
        foreach ([
            [BusinessType::Fnb, true],
            [BusinessType::Campuran, true],
            [BusinessType::Kelontong, false],
            [BusinessType::DepotAir, false],
            [BusinessType::Laundry, false],
        ] as $nomor => [$jenis, $seharusnyaAda]) {
            $tenant = $this->buatTenant('Usaha '.$jenis->value, jenis: $jenis);
            $this->buatOutlet($tenant, 'Outlet '.$jenis->value);
            $pemilik = $this->buatUser($tenant, UserRole::Owner, [
                'name' => 'Pemilik '.$jenis->value,
                'email' => 'sidebar'.$nomor.'@bahan.test',
                'password' => 'rahasia123',
            ]);

            $this->konteks()->setTenant($tenant->getKey());

            $html = $this->actingAs($pemilik)->get(route('owner.dasbor'))->assertOk()->getContent();

            // Yang dicari TAUTANNYA, bukan cuma labelnya: label tanpa tautan adalah bentuk
            // "segera" yang lama, dan itu bukan menu yang hidup.
            $adaTautan = str_contains($html, 'href="'.route('owner.bahan').'"');

            $this->assertSame($seharusnyaAda, $adaTautan,
                $seharusnyaAda
                    ? "menu bahan harus ada untuk {$jenis->value}"
                    : "menu bahan tidak boleh ada untuk {$jenis->value} — usaha itu tidak menyusun menu dari bahan");
        }
    }

    /**
     * Rutenya TIDAK diblokir untuk jenis usaha lain, dan itu keputusan sadar.
     *
     * Memblokirnya berarti satu middleware baru plus satu uji 403 demi nol manfaat bagi
     * pemilik: yang paling buruk bisa terjadi adalah pemilik kelontong mengetik URL-nya
     * sendiri lalu melihat layar yang tidak berguna baginya — bukan data orang lain, bukan
     * uang yang salah.
     */
    public function test_rute_bahan_tidak_diblokir_untuk_jenis_usaha_yang_tidak_memakai_resep(): void
    {
        $tenant = $this->buatTenant('Kelontong Bahan', jenis: BusinessType::Kelontong);
        $this->buatOutlet($tenant, 'Outlet Kelontong');
        $pemilik = $this->buatUser($tenant, UserRole::Owner, [
            'name' => 'Pemilik Kelontong',
            'email' => 'kelontong@bahan.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($tenant->getKey());

        $this->actingAs($pemilik)->get(route('owner.bahan'))->assertOk();
    }

    /**
     * Kasir tidak boleh membuka layar ini: bahan adalah master, dan masternya milik pemilik.
     *
     * Dialihkan ke berandanya sendiri, bukan 403 telanjang — pola yang sama dengan seluruh
     * rute back office (lihat GerbangAksesTest): kasir yang salah membuka URL owner tidak
     * boleh terjebak di halaman mati tanpa jalan keluar.
     */
    public function test_kasir_tidak_boleh_membuka_layar_bahan(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('owner.bahan'))
            ->assertRedirect(route('kasir.beranda'));
    }

    /* ── 10. Bintang wajib ──────────────────────────────────────────────── */

    /**
     * Kriteria 10: bintang merah HANYA pada Nama bahan dan Satuan — TIDAK pada harga beli
     * terakhir.
     *
     * Arah kedua yang paling mudah dilupakan: bintang pada medan yang sebenarnya `nullable`
     * membuat orang mengisi hal yang tidak perlu, dan sesudah dua kali begitu ia berhenti
     * memercayai bintangnya — lalu melewatkan yang sungguh wajib.
     */
    public function test_bintang_merah_hanya_pada_nama_bahan_dan_satuan_tidak_pada_harga_beli_terakhir(): void
    {
        $html = $this->layar()->call('tambah')->html();

        foreach (['Nama bahan', 'Satuan'] as $label) {
            $this->assertTrue($this->berbintang($html, $label),
                "'{$label}' ber-required di validator, jadi wajib berbintang");
        }

        $this->assertFalse($this->berbintang($html, 'Harga beli terakhir per'),
            'harga beli terakhir nullable di validator — bintang di situ membuat bintangnya '
            .'berhenti dipercaya');

        // Keterangan yang WAJIB ada: tanpa ini pemilik menyimpulkan aplikasi melupakan
        // angka yang ia ketik, karena angkanya memang berubah sendiri saat nota diterima.
        $this->assertStringContainsString(
            'Angka ini ikut berubah sendiri setiap nota belanja ditandai barangnya datang.',
            $html,
        );
    }

    /**
     * Tiga hal yang sengaja tidak ada di formulir WAJIB dijelaskan di layar, bukan cuma di
     * komentar kode.
     *
     * Medan yang hilang tanpa penjelasan terbaca sebagai fitur yang belum jadi: pemilik lalu
     * mencari batas minimal di layar yang salah, atau menyimpulkan aplikasinya tidak
     * menghitung sisa bahan sama sekali.
     */
    public function test_formulir_menjelaskan_apa_yang_sengaja_tidak_ada_di_dalamnya(): void
    {
        $html = $this->layar()->call('tambah')->html();

        foreach ([
            'Batas minimal',          // → layar Stok, per cabang
            'per cabang',
            'Hitung stok',            // → dari mana sisa bahan diisi
            'berkurang sendiri tiap menu terjual',
        ] as $kalimat) {
            $this->assertStringContainsString($kalimat, $html,
                "layar harus menjelaskan '{$kalimat}' — penjelasan di komentar kode tidak "
                .'pernah dibaca pemilik warung');
        }

        // Saklar aktif/nonaktif TIDAK ada: SusunBarisStokAction::barisBahan() dan
        // SusunSisaStokAction tidak menyaringnya, jadi saklar itu tidak mengubah apa pun
        // yang kelihatan — dan kontrol yang tidak berakibat mengajarkan orang bahwa saklar
        // di aplikasi ini tidak berarti.
        $this->assertStringNotContainsString('wire:model="aktif"', $html);
        $this->assertStringNotContainsString('Stok awal', $html);
    }

    /* ── 11. Satuan terkunci ────────────────────────────────────────────── */

    /**
     * Kriteria 11: satuan bahan yang sudah punya `stocks` atau resep → tidak bisa diubah,
     * pesannya menyebut alasannya.
     *
     * Alasannya angka lama TIDAK dikonversi: kg→gram membuat 60 kg di kartu stok terbaca 60
     * gram, dan resep 0,25 kg per porsi menjadi 0,25 gram. Ini cacat 1000× lewat pintu lain,
     * dan pintunya tidak berbunyi sama sekali — tidak ada galat, tidak ada uang yang salah
     * hari itu; yang salah adalah setiap keputusan belanja sesudahnya.
     */
    public function test_satuan_bahan_yang_sudah_punya_stocks_atau_resep_tidak_bisa_diubah(): void
    {
        /* (a) sudah punya catatan stok */
        $berstok = $this->buatBahan('Lele Segar');
        Stock::create([
            'outlet_id' => $this->outlet->getKey(),
            'raw_material_id' => $berstok->getKey(),
            'jumlah_saat_ini' => 60,
        ]);

        $galat = $this->layar()
            ->call('ubah', $berstok->getKey())
            ->set('satuan', 'gram')
            ->call('simpan')
            ->assertHasErrors('satuan')
            ->errors()->first('satuan');

        $this->assertSame(
            'Satuan tidak bisa diubah karena Lele Segar sudah punya catatan stok. '
            .'Buat bahan baru kalau satuannya mau beda.',
            $galat,
        );
        $this->assertSame('kg', $berstok->fresh()->satuan->value);

        /* (b) belum punya stok, tapi sudah dipakai resep */
        $beresep = $this->buatBahan('Minyak Goreng', Satuan::Liter);
        $this->buatMenu('Ayam Goreng', $beresep, pakai: 0.05);

        $galatResep = $this->layar()
            ->call('ubah', $beresep->getKey())
            ->set('satuan', 'ml')
            ->call('simpan')
            ->assertHasErrors('satuan')
            ->errors()->first('satuan');

        $this->assertStringContainsString('sudah dipakai resep', $galatResep);
        $this->assertStringContainsString('Buat bahan baru', $galatResep);
        $this->assertSame('liter', $beresep->fresh()->satuan->value);
    }

    /**
     * Arah sebaliknya, dan ia perlu dijaga juga: bahan yang BELUM punya angka apa pun masih
     * boleh berganti satuan.
     *
     * Tanpa uji ini, "perbaikan" yang mengunci satuan untuk semua bahan akan lolos — dan
     * pemilik yang baru salah pilih satuan sedetik sebelumnya harus membuat bahan baru
     * beserta nama karangan untuk memperbaiki salah ketik.
     */
    public function test_satuan_masih_boleh_diubah_selama_bahannya_belum_punya_stok_maupun_resep(): void
    {
        $bahan = $this->buatBahan('Cabai Merah');

        $this->layar()
            ->call('ubah', $bahan->getKey())
            ->set('satuan', 'gram')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('gram', $bahan->fresh()->satuan->value);
    }

    /* ── Tiga satuan baru ───────────────────────────────────────────────── */

    /**
     * `gram`, `ml`, dan `ikat` ada, dan ketiganya TIDAK menerima pecahan.
     *
     * gram/ml adalah jalan keluar dari pecahan, bukan tambahan kosmetik: pemilik yang lebih
     * suka mengetik "250" daripada "0,25" cukup menyetel satuan BAHANNYA gram, lalu seluruh
     * rantai berbahasa gram tanpa satu pun konversi. Itu jawaban yang benar untuk risiko
     * salah 1000× — tanpa konversi, tidak ada tempat untuk salah kali seribu.
     *
     * allowsFraction() bukan hiasan: ia dipakai PembelianBaru (menolak 1,5 gram di nota) dan
     * SusunKatalogAction (kolom desimal di kasir).
     */
    public function test_tiga_satuan_baru_ada_dan_tidak_menerima_pecahan(): void
    {
        foreach (['gram', 'ml', 'ikat'] as $nilai) {
            $satuan = Satuan::tryFrom($nilai);

            $this->assertNotNull($satuan, "satuan '{$nilai}' harus ada di enum");
            $this->assertFalse($satuan->allowsFraction(),
                "'{$nilai}' tidak boleh menerima pecahan — kalau gram menerima 0,25 gram, "
                .'kolomnya kembali menerima bentuk yang justru mau dihindari');
            $this->assertNotSame('', $satuan->label());
        }

        // Daftarnya tetap PENDEK, dan itu batas yang harus dipertahankan: setiap case ikut
        // muncul di dropdown satuan PRODUK juga, dan dropdown yang harus digulir untuk
        // dicari sama saja tidak ada. `butir`/`ekor`/`tabung`/`bungkus` sudah ditolak —
        // semuanya nama lain untuk Pcs.
        $this->assertLessThanOrEqual(8, count(Satuan::cases()),
            'dropdown satuan di 390px tidak boleh sepanjang itu — lihat komentar di App\Enums\Satuan');

        foreach (['butir', 'ekor', 'tabung', 'bungkus'] as $ditolak) {
            $this->assertNull(Satuan::tryFrom($ditolak),
                "'{$ditolak}' adalah nama lain untuk Pcs; menambahkannya hanya memanjangkan dropdown");
        }
    }

    /** Ketiga satuan baru bisa benar-benar dipakai menyimpan bahan. */
    public function test_bahan_bisa_disimpan_dengan_satuan_gram_ml_dan_ikat(): void
    {
        foreach ([
            'Bumbu Halus' => 'gram',
            'Kecap Manis' => 'ml',
            'Kangkung' => 'ikat',
        ] as $nama => $satuan) {
            $this->layar()
                ->call('tambah')
                ->set('nama', $nama)
                ->set('satuan', $satuan)
                ->call('simpan')
                ->assertHasNoErrors();

            $this->assertSame($satuan, RawMaterial::query()->where('nama', $nama)->firstOrFail()->satuan->value);
        }
    }

    /* ── Daftar ─────────────────────────────────────────────────────────── */

    /** Kolom "Dipakai di" menyebut NAMA menunya, bukan cuma angkanya. */
    public function test_daftar_menyebut_menu_yang_memakai_bahannya(): void
    {
        $bahan = $this->buatBahan('Lele Segar', harga: 58000);
        $this->buatMenu('Lele Goreng', $bahan);
        $this->buatMenu('Pecel Lele', $bahan);
        $this->buatBahan('Kangkung', Satuan::Ikat);

        $this->layar()
            ->assertSee('Lele Segar')
            ->assertSee('Lele Goreng')
            ->assertSee('Pecel Lele')
            ->assertSee('Rp 58.000')
            // Bahan tanpa resep dan tanpa harga tetap punya penanda, bukan sel kosong:
            // kosong membuat pembacanya menebak "nol, atau belum diisi?".
            ->assertSee('belum ada resep')
            ->assertSee('belum diisi');
    }

    /** Pencarian menyaring per nama, dan mengembalikan daftar ke halaman pertama. */
    public function test_pencarian_menyaring_nama_bahan(): void
    {
        $this->buatBahan('Lele Segar');
        $this->buatBahan('Ayam Potong');

        $this->layar()
            ->set('cari', 'lele')
            ->assertSee('Lele Segar')
            ->assertDontSee('Ayam Potong');
    }

    /** Bahan milik tenant lain tidak pernah terlihat, dan tidak pernah bisa dihapus. */
    public function test_bahan_tenant_lain_tidak_terlihat_dan_tidak_bisa_dihapus(): void
    {
        $lain = $this->buatTenant('Warteg Sebelah');
        $bahanLain = $this->konteks()->forTenant($lain->getKey(), fn () => RawMaterial::create([
            'nama' => 'Lele Tetangga',
            'satuan' => Satuan::Kg,
        ]));

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->layar()->assertDontSee('Lele Tetangga');

        // Muatan Livewire yang menitipkan id bahan merchant lain berakhir 404 — gerbangnya
        // scope tenant di findOrFail(), bukan dialog di layar.
        $this->expectException(ModelNotFoundException::class);
        $this->layar()->call('hapus', $bahanLain->getKey());
    }

    /** Label + bintang harus berdampingan; potongan HTML di antaranya diabaikan. */
    private function berbintang(string $html, string $label): bool
    {
        return preg_match(
            '/'.preg_quote($label, '/').'(?:(?!<\/label>).){0,400}?text-merah-tua/s',
            $html,
        ) === 1;
    }
}

<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Produk;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Menguji kelola produk milik owner, terutama hal yang menyentuh uang dan berkas.
 */
class OwnerProdukTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant();
        $outlet = $this->buatOutlet($this->tenant, 'Outlet Uji');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Uji',
            'outlet_id' => $outlet->getKey(),
        ]);

        Storage::fake('public');

        /*
         * Disk BAWAAN juga dipalsukan — dan itu bukan kerapian, itu memperbaiki kegagalan
         * palsu yang benar-benar terjadi.
         *
         * Livewire menaruh unggahan sementara di disk bawaan ('local'), jadi tanpa baris ini
         * uji ini menulis berkas SUNGGUHAN ke storage/app/private/livewire-tmp setiap kali
         * dijalankan. Berkas itu menumpuk lintas hari, dan Livewire menjalankan
         * cleanupOldUploads() pada setiap unggahan selesai — yang membuang apa pun di
         * livewire-tmp yang berumur lebih dari 24 jam. Akibatnya suite bisa gagal dengan
         * "Unable to retrieve the file_size for file at location: livewire-tmp/…" tanpa ada
         * satu pun kode aplikasi yang berubah (terjadi 2026-08-06 pada
         * test_menyimpan_tanpa_menyentuh_gambar_mempertahankan_gambar_lama).
         *
         * Kegagalan palsu lebih berbahaya daripada tidak ada angka: laporan progres otomatis
         * menyebut cacat yang tidak ada, lalu orang belajar mengabaikan angkanya.
         */
        Storage::fake(config('filesystems.default'));
    }

    /**
     * Harga wajib.
     *
     * Produk tanpa harga akan tampil Rp 0 di kasir dan terjual gratis tanpa ada yang
     * menyadarinya sampai tutup kasir.
     */
    public function test_produk_tidak_bisa_disimpan_tanpa_harga(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Nasi Uduk')
            ->call('simpan')
            ->assertHasErrors(['harga' => 'required']);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_produk_baru_tersimpan_dengan_gambar(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Nasi Uduk')
            ->set('harga', 8000)
            ->set('gambar', UploadedFile::fake()->image('nasi.jpg', 400, 300))
            ->call('simpan')
            ->assertHasNoErrors();

        $produk = Product::withoutGlobalScopes()->sole();

        $this->assertSame('Nasi Uduk', $produk->nama_produk);
        $this->assertNotNull($produk->gambar_path);
        Storage::disk('public')->assertExists($produk->gambar_path);
    }

    /** Berkas lama tidak boleh tertinggal jadi sampah saat gambarnya diganti. */
    public function test_mengganti_gambar_membuang_berkas_lama(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Ayam Bakar')
            ->set('harga', 15000)
            ->set('gambar', UploadedFile::fake()->image('lama.jpg'))
            ->call('simpan');

        $lama = Product::withoutGlobalScopes()->sole()->gambar_path;
        Storage::disk('public')->assertExists($lama);

        $komponen->call('ubah', Product::withoutGlobalScopes()->sole()->getKey())
            ->set('gambar', UploadedFile::fake()->image('baru.jpg'))
            ->call('simpan')
            ->assertHasNoErrors();

        $baru = Product::withoutGlobalScopes()->sole()->gambar_path;

        $this->assertNotSame($lama, $baru);
        Storage::disk('public')->assertExists($baru);
        Storage::disk('public')->assertMissing($lama);
    }

    public function test_gambar_bisa_dihapus_tanpa_menghapus_produk(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Es Jeruk')
            ->set('harga', 5000)
            ->set('gambar', UploadedFile::fake()->image('jeruk.jpg'))
            ->call('simpan');

        $produk = Product::withoutGlobalScopes()->sole();
        $path = $produk->gambar_path;

        $komponen->call('ubah', $produk->getKey())
            ->call('tandaiHapusGambar')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertNull($produk->fresh()->gambar_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(1, Product::withoutGlobalScopes()->count(), 'produknya harus tetap ada');
    }

    /**
     * Membuka formulir tanpa menyentuh kolom gambar tidak boleh menghilangkan gambar
     * yang sudah ada — kesalahan yang mudah terjadi kalau path lamanya tidak disimpan.
     */
    public function test_menyimpan_tanpa_menyentuh_gambar_mempertahankan_gambar_lama(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Teh Manis')
            ->set('harga', 4000)
            ->set('gambar', UploadedFile::fake()->image('teh.jpg'))
            ->call('simpan');

        $produk = Product::withoutGlobalScopes()->sole();
        $path = $produk->gambar_path;

        $komponen->call('ubah', $produk->getKey())
            ->set('harga', 4500)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame($path, $produk->fresh()->gambar_path);
        Storage::disk('public')->assertExists($path);
        $this->assertEqualsWithDelta(4500.0, (float) $produk->fresh()->harga_default, 0.01);
    }

    public function test_berkas_bukan_gambar_ditolak(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Kerupuk')
            ->set('harga', 2000)
            ->set('gambar', UploadedFile::fake()->create('daftar.pdf', 100, 'application/pdf'))
            ->call('simpan')
            ->assertHasErrors('gambar');

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    /**
     * URL gambar harus mengikuti host permintaan, bukan APP_URL.
     *
     * Pernah gagal: Storage::url() selalu menyusun URL dari APP_URL (http://localhost),
     * sedangkan aplikasinya dibuka di 127.0.0.1:8000. Peramban mencari gambarnya di
     * host yang tidak melayani apa pun dan gambarnya tidak pernah muncul — tanpa pesan
     * galat apa pun, jadi gejalanya hanya "gambar tidak tampil".
     */
    public function test_url_gambar_mengikuti_host_permintaan(): void
    {
        // Berkasnya harus benar-benar ada: layar sengaja menolak merender path yang
        // berkasnya hilang, jadi tanpa ini yang teruji bukan URL-nya.
        Storage::disk('public')->put('produk/contoh.jpg', 'x');

        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Nasi Uduk',
            'harga_default' => 8000,
            'satuan' => 'porsi',
            'gambar_path' => 'produk/contoh.jpg',
        ]));

        $this->actingAs($this->owner)
            ->get('http://kasir.uji:8080/kelola/produk')
            ->assertOk()
            ->assertSee('http://kasir.uji:8080/storage/produk/contoh.jpg')
            ->assertDontSee('http://localhost/storage/produk/contoh.jpg');
    }

    /**
     * Hapus memakai soft delete: baris transaction_items menunjuk product_id, dan
     * membuangnya permanen akan mengubah laporan penjualan bulan lalu hanya karena
     * hari ini ada menu yang dihentikan.
     */
    public function test_hapus_produk_menyisakan_riwayat_dan_membuang_gambarnya(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Soto Ayam')
            ->set('harga', 14000)
            ->set('gambar', UploadedFile::fake()->image('soto.jpg'))
            ->call('simpan');

        $produk = Product::withoutGlobalScopes()->sole();
        $path = $produk->gambar_path;

        // Langkah pertama hanya menandai; belum ada yang terhapus.
        $komponen->call('tandaiHapus', $produk->getKey())
            ->assertSet('produkMauDihapus', $produk->getKey());

        $this->assertSame(1, Product::withoutGlobalScopes()->count());
        Storage::disk('public')->assertExists($path);

        $komponen->call('hapus', $produk->getKey())
            ->assertSet('produkMauDihapus', null);

        /*
         * deleted_at diperiksa langsung. withoutGlobalScopes() melepas SEMUA scope,
         * termasuk scope soft-delete — memakainya untuk memeriksa "sudah terhapus atau
         * belum" akan selalu menghitung baris terhapus dan ujinya jadi tidak berarti.
         */
        $this->assertSame(0, Product::withoutGlobalScopes()->whereNull('deleted_at')->count(),
            'produk seharusnya sudah hilang dari daftar');
        $this->assertSame(1, Product::withoutGlobalScopes()->whereNotNull('deleted_at')->count(),
            'barisnya harus tetap ada supaya riwayat penjualan tidak berubah');
        Storage::disk('public')->assertMissing($path);
    }

    public function test_batal_hapus_tidak_menghapus_apa_pun(): void
    {
        $produk = $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Bakso',
            'harga_default' => 12000,
            'satuan' => 'porsi',
        ]));

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tandaiHapus', $produk->getKey())
            ->call('batalHapus')
            ->assertSet('produkMauDihapus', null);

        $this->assertSame(1, Product::withoutGlobalScopes()->count());
    }

    /**
     * Kabar keberhasilan dikirim sebagai EVENT, bukan session flash.
     *
     * Flash message menuntut halaman dimuat ulang untuk terlihat. Livewire hanya
     * memperbarui sebagian halaman, jadi pesannya bisa muncul terlambat pada navigasi
     * berikutnya — atau tidak muncul sama sekali.
     */
    public function test_aksi_mengirim_toast_bukan_session_flash(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Es Cendol')
            ->set('harga', 6000)
            ->call('simpan');

        $komponen->assertDispatched('toast', pesan: 'Produk "Es Cendol" ditambahkan.', jenis: 'sukses');

        $this->assertNull(session('pesan'), 'seharusnya tidak memakai session flash lagi');

        $produk = Product::withoutGlobalScopes()->sole();

        $komponen->call('ubahAktif', $produk->getKey())
            ->assertDispatched('toast');

        $komponen->call('hapus', $produk->getKey())
            ->assertDispatched('toast');
    }

    /**
     * Path yang menggantung tidak boleh dirender sebagai gambar.
     *
     * Berkas bisa hilang di luar aplikasi — terhapus manual, disk dipindah, atau
     * salinan database dibawa ke mesin lain tanpa berkasnya. Kalau path tetap
     * dirender, layar menampilkan ikon gambar rusak yang tidak menjelaskan apa pun.
     */
    public function test_path_gambar_yang_berkasnya_hilang_tidak_dirender(): void
    {
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Nasi Goreng',
            'harga_default' => 15000,
            'satuan' => 'porsi',
            'gambar_path' => 'produk/sudah-hilang.jpg',
        ]));

        $komponen = Livewire::actingAs($this->owner)->test(Produk::class);

        $this->assertFalse($komponen->instance()->punyaGambar('produk/sudah-hilang.jpg'));
        $komponen->assertDontSee('produk/sudah-hilang.jpg');

        // Berkas yang benar-benar ada tetap dirender.
        Storage::disk('public')->put('produk/ada.jpg', 'x');
        $this->assertTrue($komponen->instance()->punyaGambar('produk/ada.jpg'));
    }

    /* ── SKU otomatis ────────────────────────────────────────────────────── */

    /**
     * SKU terisi sendiri kalau dikosongkan.
     *
     * Tanpa ini kolomnya kosong selamanya — tidak ada pemilik warung yang mengarang kode
     * untuk ratusan barang — dan setiap fitur yang butuh pegangan selain nama (opname,
     * pembelian, label rak, pencocokan barang tanpa barcode pabrik) kehilangan kuncinya.
     */
    public function test_sku_dibuat_otomatis_ketika_dikosongkan(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Nasi Uduk')
            ->set('harga', 8000)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('NAS-0001', Product::withoutGlobalScopes()->sole()->sku);
    }

    /** Kode yang diketik sendiri tidak boleh ditimpa. */
    public function test_sku_yang_diisi_sendiri_dipertahankan(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Nasi Uduk')
            ->set('harga', 8000)
            ->set('sku', 'PUNYAKU-9')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('PUNYAKU-9', Product::withoutGlobalScopes()->sole()->sku);
    }

    public function test_nomor_urut_sku_berjalan_per_awalan(): void
    {
        $buat = fn (string $nama) => $this->konteks()->forTenant(
            $this->tenant->getKey(),
            fn () => Product::create(['nama_produk' => $nama, 'harga_default' => 5000]),
        );

        $this->assertSame('NAS-0001', $buat('Nasi Putih')->sku);
        $this->assertSame('NAS-0002', $buat('Nasi Goreng')->sku);
        // Awalan berbeda punya deret sendiri; tidak ikut nomor tetangganya.
        // Spasi dibuang lebih dulu, jadi "Es Teh" jadi EST — tiga huruf, bukan dua.
        $this->assertSame('EST-0001', $buat('Es Teh')->sku);
    }

    /**
     * Nomor milik produk yang sudah dihapus TIDAK dipakai ulang.
     *
     * Kode yang dipakai dua barang berbeda membuat laporan lama menunjuk barang yang
     * salah — dan itu tidak akan terlihat sebagai galat, hanya sebagai angka yang aneh.
     */
    public function test_sku_tidak_memakai_ulang_nomor_produk_yang_dihapus(): void
    {
        $pertama = $this->konteks()->forTenant(
            $this->tenant->getKey(),
            fn () => Product::create(['nama_produk' => 'Nasi Putih', 'harga_default' => 5000]),
        );

        $this->assertSame('NAS-0001', $pertama->sku);
        $pertama->delete();

        $kedua = $this->konteks()->forTenant(
            $this->tenant->getKey(),
            fn () => Product::create(['nama_produk' => 'Nasi Kuning', 'harga_default' => 6000]),
        );

        $this->assertSame('NAS-0002', $kedua->sku);
    }

    /** Nomor urut dihitung per tenant: warung sebelah tidak menggeser nomor kita. */
    public function test_nomor_sku_dihitung_per_tenant(): void
    {
        $tenantLain = $this->buatTenant('Warung Sebelah');

        $milikLain = $this->konteks()->forTenant(
            $tenantLain->getKey(),
            fn () => Product::create(['nama_produk' => 'Nasi Rames', 'harga_default' => 9000]),
        );

        $milikSendiri = $this->konteks()->forTenant(
            $this->tenant->getKey(),
            fn () => Product::create(['nama_produk' => 'Nasi Putih', 'harga_default' => 5000]),
        );

        $this->assertSame('NAS-0001', $milikLain->sku);
        $this->assertSame('NAS-0001', $milikSendiri->sku);
    }

    /**
     * Mengganti nama TIDAK mengubah SKU.
     *
     * Kode yang berubah lebih buruk daripada tidak ada kode: label rak, lembar opname,
     * dan nota lama jadi menunjuk kode yang sudah tidak ada.
     */
    public function test_mengganti_nama_tidak_mengubah_sku(): void
    {
        $produk = $this->konteks()->forTenant(
            $this->tenant->getKey(),
            fn () => Product::create(['nama_produk' => 'Nasi Putih', 'harga_default' => 5000]),
        );

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->set('nama', 'Beras Pulen')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('NAS-0001', $produk->fresh()->sku);
    }

    /** Mengosongkan SKU saat mengubah itu keputusan pemiliknya, bukan galat. */
    public function test_mengosongkan_sku_saat_mengubah_tidak_diisi_ulang(): void
    {
        $produk = $this->konteks()->forTenant(
            $this->tenant->getKey(),
            fn () => Product::create(['nama_produk' => 'Nasi Putih', 'harga_default' => 5000]),
        );

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->set('sku', '')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertNull($produk->fresh()->sku);
    }

    /** Nama tanpa huruf sama sekali (mis. "12 + 3") tetap dapat kode yang sah. */
    public function test_nama_tanpa_huruf_tetap_mendapat_sku(): void
    {
        $produk = $this->konteks()->forTenant(
            $this->tenant->getKey(),
            fn () => Product::create(['nama_produk' => '+++', 'harga_default' => 1000]),
        );

        $this->assertSame('SKU-0001', $produk->sku);
    }

    /** Nomor terakhir yang bukan angka tidak boleh membuat kode kembar. */
    public function test_sku_manual_yang_tidak_berbentuk_angka_tidak_membuat_kode_kembar(): void
    {
        $this->konteks()->forTenant($this->tenant->getKey(), function () {
            Product::create(['nama_produk' => 'Nasi Putih', 'harga_default' => 5000, 'sku' => 'NAS-abc']);
            Product::create(['nama_produk' => 'Nasi Kuning', 'harga_default' => 6000]);
        });

        $daftar = Product::withoutGlobalScopes()->pluck('sku')->all();

        $this->assertCount(2, array_unique($daftar), 'SKU tidak boleh kembar: '.implode(', ', $daftar));
    }

    /* ── Barcode ─────────────────────────────────────────────────────────── */

    /**
     * Kolom barcode hanya untuk usaha yang memang menjual barang berbarcode.
     *
     * Menampilkannya di rumah makan berarti menambah medan kosong yang harus
     * dilewati setiap kali menambah menu — nasi dan ayam goreng tidak punya barcode.
     */
    public function test_kolom_barcode_hanya_untuk_kelontong(): void
    {
        $komponenFnb = Livewire::actingAs($this->owner)->test(Produk::class)->call('tambah');

        $this->assertFalse($komponenFnb->instance()->pakaiBarcode(), 'FnB tidak memakai barcode');
        $komponenFnb->assertDontSee('Pindai atau ketik');

        $tenantKelontong = $this->buatTenant('Toko Uji', jenis: BusinessType::Kelontong);
        $outlet = $this->buatOutlet($tenantKelontong, 'Toko Uji');
        $ownerKelontong = $this->buatUser($tenantKelontong, UserRole::Owner, [
            'name' => 'Pemilik Toko',
            'outlet_id' => $outlet->getKey(),
        ]);

        $komponenToko = Livewire::actingAs($ownerKelontong)->test(Produk::class)->call('tambah');

        $this->assertTrue($komponenToko->instance()->pakaiBarcode());
        $komponenToko->assertSee('Pindai atau ketik');
    }

    /**
     * Formulir barcode wajib menawarkan lebih dari satu cara mengisinya.
     *
     * Kamera langsung TIDAK bisa diandalkan sendirian: peramban melarangnya di alamat
     * yang bukan HTTPS — persis cara tablet dan ponsel di warung membuka aplikasi ini
     * lewat alamat LAN. Kalau markah cadangannya hilang dari formulir, satu-satunya
     * jalur yang tersisa di perangkat itu adalah mengetik 13 angka dengan tangan, dan
     * kegagalan itu tidak akan terlihat di mesin pengembang yang memakai localhost.
     */
    public function test_formulir_barcode_menyediakan_cadangan_selain_kamera(): void
    {
        $tenantKelontong = $this->buatTenant('Toko Cadangan', jenis: BusinessType::Kelontong);
        $outlet = $this->buatOutlet($tenantKelontong, 'Toko Cadangan');
        $ownerKelontong = $this->buatUser($tenantKelontong, UserRole::Owner, [
            'name' => 'Pemilik Cadangan',
            'outlet_id' => $outlet->getKey(),
        ]);

        $komponen = Livewire::actingAs($ownerKelontong)->test(Produk::class)->call('tambah');

        // Jalur foto: lewat kamera bawaan sistem, satu-satunya yang tetap boleh dipakai
        // ketika aliran kamera langsung ditolak peramban.
        $komponen->assertSee('dariBerkas($event)', escape: false);
        $komponen->assertSee('accept="image/*"', escape: false);

        // Jalur pemindai USB: ditangkap di tingkat jendela supaya memindai tanpa
        // mengeklik kolomnya lebih dulu tetap masuk.
        $komponen->assertSee('tangkap($event)', escape: false);

        // Jalur kamera langsung: tombolnya menghilang sendiri kalau tidak diizinkan,
        // jadi ia tidak boleh menjadi satu-satunya yang ada.
        $komponen->assertSee('bisaKamera', escape: false);
    }

    public function test_barcode_tersimpan_dan_bisa_dicari(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Indomie Goreng')
            ->set('harga', 3500)
            ->set('barcode', '8998866200189')
            ->set('sku', 'IDM-001')
            ->call('simpan')
            ->assertHasNoErrors();

        $produk = Product::withoutGlobalScopes()->sole();

        $this->assertSame('8998866200189', $produk->barcode);
        $this->assertSame('IDM-001', $produk->sku);

        // Memindai di kolom pencarian harus langsung menemukan barangnya.
        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->set('cari', '8998866200189')
            ->assertSee('Indomie Goreng');
    }

    /**
     * Barcode kembar dalam satu tenant ditolak: dua produk dengan barcode sama
     * membuat pemindaian di kasir tidak bisa menentukan barang yang mana.
     */
    public function test_barcode_kembar_dalam_satu_tenant_ditolak(): void
    {
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Indomie Goreng',
            'harga_default' => 3500,
            'satuan' => 'pcs',
            'barcode' => '8998866200189',
        ]));

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Indomie Rebus')
            ->set('harga', 3500)
            ->set('barcode', '8998866200189')
            ->call('simpan')
            ->assertHasErrors('barcode');

        $this->assertSame(1, Product::withoutGlobalScopes()->count());
    }

    /**
     * Tapi barcode yang sama WAJAR dipakai dua merchant berbeda — barcode pabrik
     * memang sama untuk barang yang sama, jadi keunikannya per tenant.
     */
    public function test_barcode_sama_boleh_di_tenant_berbeda(): void
    {
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Indomie Goreng',
            'harga_default' => 3500,
            'satuan' => 'pcs',
            'barcode' => '8998866200189',
        ]));

        $tenantLain = $this->buatTenant('Toko Lain', jenis: BusinessType::Kelontong);
        $outlet = $this->buatOutlet($tenantLain, 'Toko Lain');
        $ownerLain = $this->buatUser($tenantLain, UserRole::Owner, [
            'name' => 'Pemilik Lain',
            'outlet_id' => $outlet->getKey(),
        ]);

        Livewire::actingAs($ownerLain)
            ->test(Produk::class)
            ->call('tambah')
            ->set('nama', 'Indomie Goreng')
            ->set('harga', 3600)
            ->set('barcode', '8998866200189')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(2, Product::withoutGlobalScopes()->count());
    }

    /** Menyimpan tanpa mengubah barcodenya tidak boleh ditolak oleh dirinya sendiri. */
    public function test_menyimpan_ulang_produk_dengan_barcode_yang_sama_diterima(): void
    {
        $produk = $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Indomie Goreng',
            'harga_default' => 3500,
            'satuan' => 'pcs',
            'barcode' => '8998866200189',
        ]));

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubah', $produk->getKey())
            ->set('harga', 4000)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(4000.0, (float) $produk->fresh()->harga_default, 0.01);
        $this->assertSame('8998866200189', $produk->fresh()->barcode);
    }

    /** Nonaktif menyembunyikan dari kasir; produknya sendiri tidak dihapus. */
    public function test_menyembunyikan_produk_tidak_menghapusnya(): void
    {
        $produk = $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Kerupuk',
            'harga_default' => 2000,
            'satuan' => 'pcs',
        ]));

        Livewire::actingAs($this->owner)
            ->test(Produk::class)
            ->call('ubahAktif', $produk->getKey());

        $this->assertFalse((bool) $produk->fresh()->is_active);
        $this->assertSame(1, Product::withoutGlobalScopes()->count());
    }
}

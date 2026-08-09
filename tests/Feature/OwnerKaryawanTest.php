<?php

namespace Tests\Feature;

use App\Actions\Kas\BukaSesiKasAction;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Karyawan\Karyawan as LayarKaryawan;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Layar Karyawan — siapa yang bisa masuk, dan sampai mana.
 *
 * BERBEDA DARI LAYAR LAIN MANA PUN di aplikasi ini: `User` SENGAJA tidak memakai trait
 * BelongsToTenant, karena global scope tenant akan ikut membatasi kueri auth guard dan
 * membuat Super Admin tidak bisa mengelola user lintas tenant. Akibatnya TIDAK ADA jaring
 * pengaman di bawah layar ini — satu kueri yang lupa `where('tenant_id', ...)` menampilkan,
 * menyunting, atau menghapus karyawan warung lain tanpa satu pun lapisan yang menahannya.
 * Itu sebabnya berkas ini punya lebih banyak uji lintas-tenant daripada layar lain.
 *
 * Empat gerbang yang masing-masing mencegah bencana yang tidak bisa diperbaiki dari dalam
 * aplikasi:
 *
 *  1. Owner tidak bisa membuat super_admin — akun yang melihat SELURUH merchant.
 *  2. Owner tidak bisa mengunci dirinya sendiri (nonaktif / turun peran / hapus diri).
 *  3. Owner terakhir tidak bisa dihapus — warung tanpa owner aktif tidak bisa dibuka lagi
 *     tanpa seseorang menyunting basis data.
 *  4. Karyawan bersesi kas TERBUKA tidak bisa dihapus/dinonaktifkan: sesinya tidak akan bisa
 *     ditutup siapa pun, jadi uang laci hari itu tidak pernah dicocokkan.
 */
class OwnerKaryawanTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Karyawan');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@karyawan.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function layar(): Testable
    {
        return Livewire::actingAs($this->owner)->test(LayarKaryawan::class);
    }

    /* ── Membuat ─────────────────────────────────────────────────────────── */

    #[Test]
    public function kasir_baru_tersimpan_dengan_pin_yang_ter_hash_dan_terkunci_ke_satu_cabang(): void
    {
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Andi Saputra')
            ->set('peran', 'kasir')
            ->set('outletId', $this->outlet->getKey())
            ->set('username', 'andi-utama')
            ->set('rahasiaBaru', '123456')
            ->call('simpan')
            ->assertHasNoErrors();

        $andi = User::where('username', 'andi-utama')->firstOrFail();

        $this->assertSame($this->tenant->getKey(), $andi->tenant_id, 'karyawan tanpa tenant tidak muncul di daftar mana pun');
        $this->assertSame($this->outlet->getKey(), $andi->outlet_id);
        // PIN tidak pernah disimpan apa adanya — kalau lolos, satu kebocoran basis data
        // membuka seluruh laci kasir tiap warung.
        $this->assertNotSame('123456', $andi->pin_hash);
        $this->assertTrue(Hash::check('123456', $andi->pin_hash));
    }

    #[Test]
    public function pin_wajib_enam_angka(): void
    {
        // Empat angka bisa ditebak sambil berdiri di depan kasir; huruf tidak bisa diketik di
        // papan angka layar masuk kasir.
        $layar = $this->layar()->call('tambah')->set('peran', 'kasir')->set('nama', 'Andi')
            ->set('outletId', $this->outlet->getKey())->set('username', 'andi1');

        $layar->set('rahasiaBaru', '1234')->call('simpan')->assertHasErrors('rahasiaBaru');
        $layar->set('rahasiaBaru', 'abcdef')->call('simpan')->assertHasErrors('rahasiaBaru');
        $layar->set('rahasiaBaru', '123456')->call('simpan')->assertHasNoErrors();
    }

    #[Test]
    public function kasir_tanpa_cabang_ditolak(): void
    {
        /*
         * Kasir tanpa cabang adalah akun yang TIDAK BISA login sama sekali — gerbangnya di
         * jalur masuk kasir, jauh dari layar ini. Tanpa penolakan di sini, pemilik membuat
         * akun, memberikan PIN-nya, dan staffnya gagal masuk tanpa satu pun penjelasan yang
         * menunjuk kembali ke layar ini.
         */
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Andi')
            ->set('peran', 'kasir')
            ->set('username', 'andi1')
            ->set('rahasiaBaru', '123456')
            ->call('simpan')
            ->assertHasErrors('outletId');

        $this->assertNull(User::where('username', 'andi1')->first());
    }

    #[Test]
    public function karyawan_baru_wajib_punya_jalan_masuk(): void
    {
        // Akun tanpa username maupun email hanya menambah baris di daftar dan membuat pemilik
        // mengira staffnya sudah bisa masuk.
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Andi')
            ->set('peran', 'kasir')
            ->set('outletId', $this->outlet->getKey())
            ->set('rahasiaBaru', '123456')
            ->call('simpan')
            ->assertHasErrors('username');
    }

    #[Test]
    public function peran_multi_cabang_tidak_dikunci_ke_outlet(): void
    {
        /*
         * Manajer regional yang punya `outlet_id` akan dianggap terkunci oleh
         * scopedOutletId() — dan ia hanya melihat satu cabang, padahal justru itu bukan
         * pekerjaannya. Kolomnya harus benar-benar null.
         */
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Rina')
            ->set('peran', 'regional_manager')
            ->set('outletId', $this->outlet->getKey())
            ->set('email', 'rina@karyawan.test')
            ->set('rahasiaBaru', 'rahasia123')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertNull(User::where('email', 'rina@karyawan.test')->firstOrFail()->outlet_id);
    }

    #[Test]
    public function username_yang_sudah_dipakai_warung_lain_ditolak_dengan_alasan_yang_jujur(): void
    {
        /*
         * Kolomnya unik SE-APLIKASI, bukan se-warung, karena auth guard berjalan sebelum
         * tenant diketahui. Pesan "sudah dipakai di warung Anda" akan membuat pemilik
         * mencari-cari nama itu di daftarnya sendiri dan tidak pernah menemukannya.
         */
        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Sebelah');
        $this->buatUser($lain, UserRole::Kasir, [
            'name' => 'Kasir Sebelah',
            'username' => 'kasir1',
            'pin_hash' => '111111',
            'outlet_id' => $outletLain->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $galat = $this->layar()
            ->call('tambah')
            ->set('nama', 'Andi')
            ->set('peran', 'kasir')
            ->set('outletId', $this->outlet->getKey())
            ->set('username', 'kasir1')
            ->set('rahasiaBaru', '123456')
            ->call('simpan')
            ->errors()
            ->first('username');

        $this->assertStringContainsString('di aplikasi ini', (string) $galat);
    }

    /* ── Peran yang boleh diberikan ──────────────────────────────────────── */

    #[Test]
    public function owner_tidak_bisa_membuat_super_admin(): void
    {
        /*
         * Gerbang terpenting di layar ini. super_admin adalah peran pengelola platform: satu
         * akun seperti itu melihat SELURUH merchant, bukan cuma warung ini. Pilihannya memang
         * tidak ada di layar, tapi muatan Livewire bisa dikirim tanpa melewati layar sama
         * sekali — jadi ujinya menyetel nilainya langsung.
         */
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Penyusup')
            ->set('peran', 'super_admin')
            ->set('email', 'penyusup@karyawan.test')
            ->set('rahasiaBaru', 'rahasia123')
            ->call('simpan')
            ->assertHasErrors('peran');

        $this->assertSame(0, User::where('role', UserRole::SuperAdmin->value)->count());
    }

    /* ── Mengunci diri sendiri ───────────────────────────────────────────── */

    #[Test]
    public function pemilik_tidak_bisa_menonaktifkan_dirinya_sendiri_lewat_formulir(): void
    {
        $this->layar()
            ->call('ubah', $this->owner->getKey())
            ->set('aktif', false)
            ->call('simpan')
            ->assertHasErrors('aktif');

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    #[Test]
    public function pemilik_tidak_bisa_menonaktifkan_dirinya_sendiri_lewat_saklar(): void
    {
        // Jalur KEDUA ke keadaan yang sama. Gerbang yang cuma dipasang di formulir akan
        // dilewati begitu saja oleh tombol saklar di daftar.
        $this->layar()->call('saklarAktif', $this->owner->getKey());

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    #[Test]
    public function pemilik_tidak_bisa_menurunkan_peran_dirinya_sendiri(): void
    {
        // Menurunkan peran sendiri berarti kehilangan akses ke layar ini pada penyimpanan yang
        // sama — tidak ada langkah kedua untuk membatalkannya.
        $this->layar()
            ->call('ubah', $this->owner->getKey())
            ->set('peran', 'kasir')
            ->set('outletId', $this->outlet->getKey())
            ->call('simpan')
            ->assertHasErrors('peran');

        $this->assertSame(UserRole::Owner, $this->owner->fresh()->role);
    }

    #[Test]
    public function pemilik_tidak_bisa_menghapus_dirinya_sendiri(): void
    {
        $this->layar()->call('hapus', $this->owner->getKey());

        $this->assertNotSoftDeleted($this->owner);
    }

    #[Test]
    public function manajer_tidak_bisa_menghapus_satu_satunya_pemilik(): void
    {
        /*
         * DITULIS ULANG SESUDAH UJI MUTASI. Bentuk pertamanya HIJAU saat gerbang ini
         * dilumpuhkan — karena ia memakai owner kedua yang menghapus DIRINYA SENDIRI, dan
         * itu sudah ditahan gerbang lain lebih dulu. Gerbang yang tertutup gerbang lain
         * tidak pernah terbukti bekerja.
         *
         * Keadaan yang SEBENARNYA bisa sampai ke sini: manajer outlet juga boleh membuka
         * layar ini (lihat grup rute back office), jadi dialah yang bisa menghapus pemilik
         * tanpa terhalang gerbang "diri sendiri". Warung tanpa satu pun pemilik aktif tidak
         * bisa dibuka lagi tanpa seseorang menyunting basis data.
         */
        $manajer = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manajer',
            'email' => 'manajer@karyawan.test',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        Livewire::actingAs($manajer)->test(LayarKaryawan::class)
            ->call('hapus', $this->owner->getKey());

        $this->assertNotSoftDeleted($this->owner);
    }

    #[Test]
    public function manajer_tidak_bisa_mengangkat_dirinya_jadi_pemilik(): void
    {
        /*
         * Celah yang ikut terbuka saat menelusuri mutasi hijau di atas: kalau manajer bisa
         * menghapus pemilik, ia juga bisa mengangkat diri jadi pemilik — dan sesudah itu
         * tidak ada lagi yang bisa menurunkannya. Peran pemilik membawa akses lintas cabang
         * dan perlindungan "owner terakhir" yang tidak dimiliki peran lain.
         *
         * Pilihannya memang tidak ditawarkan di layar untuk manajer, tapi muatan Livewire
         * bisa dikirim tanpa melewati layar — jadi ujinya menyetel nilainya langsung.
         */
        $manajer = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manajer',
            'email' => 'manajer@karyawan.test',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        Livewire::actingAs($manajer)->test(LayarKaryawan::class)
            ->call('ubah', $manajer->getKey())
            ->set('peran', 'owner')
            ->call('simpan')
            ->assertHasErrors('peran');

        $this->assertSame(UserRole::ManagerOutlet, $manajer->fresh()->role);
    }

    #[Test]
    public function manajer_tidak_bisa_membuat_akun_pemilik_baru(): void
    {
        // Jalur kedua ke peran yang sama, dan lebih halus: bukan mengangkat diri, melainkan
        // membuat akun pemilik baru yang PIN-nya ia pegang sendiri.
        $manajer = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manajer',
            'email' => 'manajer@karyawan.test',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        Livewire::actingAs($manajer)->test(LayarKaryawan::class)
            ->call('tambah')
            ->set('nama', 'Pemilik Bayangan')
            ->set('peran', 'owner')
            ->set('email', 'bayangan@karyawan.test')
            ->set('rahasiaBaru', 'rahasia123')
            ->call('simpan')
            ->assertHasErrors('peran');

        $this->assertSame(1, User::where('role', UserRole::Owner->value)->count());
    }

    #[Test]
    public function pemilik_tetap_bisa_mengangkat_pemilik_lain(): void
    {
        // Pagar untuk ketiga uji di atas: gerbang yang menolak semua orang membuat warung
        // tidak bisa punya pemilik kedua sama sekali — dan itu justru satu-satunya cara
        // keluar dari gerbang "owner terakhir".
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Pemilik Kedua')
            ->set('peran', 'owner')
            ->set('email', 'owner2@karyawan.test')
            ->set('rahasiaBaru', 'rahasia123')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(2, User::where('role', UserRole::Owner->value)->count());
    }

    #[Test]
    public function pemilik_nonaktif_tidak_dihitung_sebagai_pemilik_yang_tersisa(): void
    {
        /*
         * DITAMBAHKAN SESUDAH UJI MUTASI: melepas saringan `is_active` dari hitungan pemilik
         * tidak membuat satu pun uji merah.
         *
         * Pembedanya nyata dan berakibat total. Warung punya pemilik A (aktif) dan pemilik B
         * (nonaktif, mis. sudah keluar dari usaha). Tanpa saringan, menghapus A dianggap aman
         * karena "masih ada dua pemilik" — padahal B TIDAK BISA LOGIN. Yang tersisa adalah
         * warung tanpa satu pun orang yang bisa masuk sebagai pemilik.
         */
        $pemilikNonaktif = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Lama',
            'email' => 'owner-lama@karyawan.test',
            'password' => 'rahasia123',
            'is_active' => false,
        ]);

        $manajer = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manajer',
            'email' => 'manajer@karyawan.test',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        Livewire::actingAs($manajer)->test(LayarKaryawan::class)
            ->call('hapus', $this->owner->getKey());

        $this->assertNotSoftDeleted($this->owner);
        $this->assertNotSoftDeleted($pemilikNonaktif);
    }

    #[Test]
    public function owner_masih_bisa_dihapus_kalau_ada_owner_aktif_lain(): void
    {
        // Pagar untuk uji di atas: gerbang yang selalu menolak juga akan lolos, dan pemilik
        // yang salah membuat akun owner tidak punya cara membersihkannya.
        $ownerKedua = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Kedua',
            'email' => 'owner2@karyawan.test',
            'password' => 'rahasia123',
        ]);

        $this->layar()->call('hapus', $ownerKedua->getKey());

        $this->assertSoftDeleted($ownerKedua);
    }

    /* ── Sesi kas terbuka ────────────────────────────────────────────────── */

    #[Test]
    public function kasir_bersesi_kas_terbuka_tidak_bisa_dihapus(): void
    {
        /*
         * Sesi yang menggantung tidak bisa ditutup siapa pun sesudah orangnya hilang, jadi
         * uang laci hari itu tidak pernah dicocokkan — dan selisihnya tidak akan pernah
         * ketahuan. Ini satu-satunya gerbang di layar ini yang menyentuh uang.
         */
        $kasir = $this->buatKasir('andi-utama');
        app(BukaSesiKasAction::class)->execute($kasir, 200000);

        $this->layar()->call('hapus', $kasir->getKey());

        $this->assertNotSoftDeleted($kasir);
    }

    #[Test]
    public function kasir_bersesi_kas_terbuka_tidak_bisa_dinonaktifkan(): void
    {
        // Jalur kedua ke akibat yang sama: kasir nonaktif tidak bisa masuk, jadi ia juga tidak
        // bisa menutup sesinya sendiri.
        $kasir = $this->buatKasir('andi-utama');
        app(BukaSesiKasAction::class)->execute($kasir, 200000);

        $this->layar()->call('saklarAktif', $kasir->getKey());

        $this->assertTrue($kasir->fresh()->is_active);
    }

    #[Test]
    public function kasir_tanpa_sesi_terbuka_bisa_dihapus(): void
    {
        // Pagar: gerbang yang menolak semua kasir membuat daftar tidak bisa dibersihkan.
        $kasir = $this->buatKasir('andi-utama');

        $this->layar()->call('hapus', $kasir->getKey());

        $this->assertSoftDeleted($kasir);
    }

    #[Test]
    public function username_dilepas_saat_dihapus_supaya_bisa_dipakai_lagi(): void
    {
        /*
         * Username unik global DAN aturan uniknya mengabaikan baris terhapus. Tanpa pelepasan
         * ini, "andi-utama" terpakai selamanya oleh baris yang tidak muncul di layar mana pun
         * — pemilik yang salah hapus lalu membuatnya lagi ditolak dengan alasan yang tidak
         * bisa ia lihat buktinya di daftar.
         */
        $kasir = $this->buatKasir('andi-utama');

        $this->layar()->call('hapus', $kasir->getKey());

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Andi Baru')
            ->set('peran', 'kasir')
            ->set('outletId', $this->outlet->getKey())
            ->set('username', 'andi-utama')
            ->set('rahasiaBaru', '654321')
            ->call('simpan')
            ->assertHasNoErrors();
    }

    /* ── Mengubah ────────────────────────────────────────────────────────── */

    #[Test]
    public function pin_kosong_saat_menyunting_berarti_pin_lama_dipertahankan(): void
    {
        /*
         * Kotaknya SELALU terbuka kosong, karena hash tidak bisa dibaca balik. Kalau kosong
         * diartikan "hapus PIN", tiap perbaikan nama karyawan diam-diam mengunci orangnya
         * keluar — dan yang menemukannya adalah kasir, di depan antrean.
         */
        $kasir = $this->buatKasir('andi-utama');
        $hashLama = $kasir->pin_hash;

        $this->layar()
            ->call('ubah', $kasir->getKey())
            ->set('nama', 'Andi Saputra')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame($hashLama, $kasir->fresh()->pin_hash);
        $this->assertSame('Andi Saputra', $kasir->fresh()->name);
    }

    #[Test]
    public function formulir_ubah_tidak_pernah_membawa_pin_lama_ke_layar(): void
    {
        // Hash-nya memang tidak bisa dibaca balik, tapi mengisi kotaknya dengan hash ITU
        // SENDIRI akan membocorkannya ke HTML — dan menyimpannya akan meng-hash hash.
        $kasir = $this->buatKasir('andi-utama');

        $this->layar()
            ->call('ubah', $kasir->getKey())
            ->assertSet('rahasiaBaru', '');
    }

    /* ── Tenant ──────────────────────────────────────────────────────────── */

    #[Test]
    public function karyawan_warung_lain_tidak_terlihat_di_daftar(): void
    {
        /*
         * TIDAK ADA global scope yang menahan ini — lihat docblock kelas. Kalau uji ini
         * pernah merah, artinya satu kueri di komponennya tidak lewat kueriDasar().
         */
        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Sebelah');
        $this->buatUser($lain, UserRole::Kasir, [
            'name' => 'Kasir Sebelah',
            'username' => 'kasir-sebelah',
            'pin_hash' => '111111',
            'outlet_id' => $outletLain->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->layar()->assertDontSee('Kasir Sebelah');
    }

    #[Test]
    public function karyawan_warung_lain_tidak_bisa_diubah(): void
    {
        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Sebelah');
        $asing = $this->buatUser($lain, UserRole::Kasir, [
            'name' => 'Kasir Sebelah',
            'username' => 'kasir-sebelah',
            'pin_hash' => '111111',
            'outlet_id' => $outletLain->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->expectException(ModelNotFoundException::class);
        $this->layar()->call('ubah', $asing->getKey());
    }

    #[Test]
    public function karyawan_warung_lain_tidak_bisa_dihapus(): void
    {
        // Jalur terpisah dari ubah(), dan yang paling merusak: satu muatan Livewire
        // menghilangkan akses masuk seluruh staf warung lain.
        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Sebelah');
        $asing = $this->buatUser($lain, UserRole::Kasir, [
            'name' => 'Kasir Sebelah',
            'username' => 'kasir-sebelah',
            'pin_hash' => '111111',
            'outlet_id' => $outletLain->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->expectException(ModelNotFoundException::class);
        $this->layar()->call('hapus', $asing->getKey());
    }

    #[Test]
    public function jumlah_karyawan_hanya_menghitung_warung_sendiri(): void
    {
        // Angka di kepala halaman ikut bocor kalau kueri hitungannya tidak tersaring — dan
        // angka yang bocor adalah petunjuk pertama bahwa kueri lain juga bocor.
        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Sebelah');
        $this->buatUser($lain, UserRole::Kasir, [
            'name' => 'Kasir Sebelah',
            'username' => 'kasir-sebelah',
            'pin_hash' => '111111',
            'outlet_id' => $outletLain->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->layar()->assertViewHas('jumlahKaryawan', 1);
    }

    /* ── Peran pembuka layar ─────────────────────────────────────────────── */

    #[Test]
    public function kasir_tidak_boleh_membuka_layar_ini(): void
    {
        // Kasir yang bisa membuka layar ini bisa mengangkat dirinya sendiri jadi pemilik.
        $kasir = $this->buatKasir('kasir-biasa');

        $this->actingAs($kasir)
            ->get(route('owner.karyawan'))
            ->assertRedirect(route('kasir.beranda'));
    }

    private function buatKasir(string $username): User
    {
        return $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Andi',
            'username' => $username,
            'pin_hash' => '123456',
            'outlet_id' => $this->outlet->getKey(),
        ]);
    }
}

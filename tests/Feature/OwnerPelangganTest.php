<?php

namespace Tests\Feature;

use App\Enums\CreditStatus;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pelanggan\Pelanggan as LayarPelanggan;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Pelanggan\Customer;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Layar Pelanggan — pintu untuk membuat orang yang namanya ditempeli kasbon.
 *
 * Dibangun SEBELUM layar Kasbon meskipun urutan di RENCANA sebaliknya:
 * `credit_ledgers.customer_id` tidak nullable, jadi tanpa pintu ini kasbon hanya bisa
 * dibangun di atas pelanggan hasil seeder.
 *
 * Yang dijaga berkas ini, dan tiap butirnya lahir dari cacat yang terjadi tanpa satu pun
 * galat di layar:
 *
 *  - NOMOR YANG SAMA DITULIS BEDA tidak boleh melahirkan dua baris. Kalau lolos, utang satu
 *    orang terpecah dan pemilik menagih kurang.
 *  - PELANGGAN BERUTANG tidak boleh dihapus. Soft delete menyembunyikan orangnya tanpa
 *    melunasi utangnya, dan dasbor tetap menjumlahkan piutang yang tidak bisa ditelusuri ke
 *    satu pun baris yang terlihat.
 *  - SARINGAN "masih berutang" harus menyaring di BASIS DATA, bukan sesudah halaman dipotong.
 */
class OwnerPelangganTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Kasbon');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@pelanggan.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Bantuan ─────────────────────────────────────────────────────────── */

    private function layar(): Testable
    {
        return Livewire::actingAs($this->owner)->test(LayarPelanggan::class);
    }

    private function buatPelanggan(string $nama, ?string $noHp = null): Customer
    {
        return Customer::create(['nama' => $nama, 'no_hp' => $noHp]);
    }

    private function buatKasbon(Customer $pelanggan, float $utang, float $dibayar = 0): CreditLedger
    {
        return CreditLedger::create([
            'outlet_id' => $this->outlet->getKey(),
            'customer_id' => $pelanggan->getKey(),
            'jumlah_utang' => $utang,
            'jumlah_dibayar' => $dibayar,
            'status' => $dibayar >= $utang ? CreditStatus::Lunas : CreditStatus::BelumLunas,
        ]);
    }

    /* ── Menyimpan ───────────────────────────────────────────────────────── */

    #[Test]
    public function pelanggan_baru_tersimpan_dengan_nomor_yang_sudah_dibakukan(): void
    {
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Budi Santoso')
            ->set('noHp', '+62 812-3456-7890')
            ->call('simpan')
            ->assertHasNoErrors();

        $budi = Customer::where('nama', 'Budi Santoso')->firstOrFail();

        // Dibakukan SAAT DISIMPAN, bukan saat ditampilkan. Kalau bentuk mentahnya yang masuk,
        // gerbang nomor unik di bawah tidak pernah menemukan kembarannya.
        $this->assertSame('081234567890', $budi->no_hp);
    }

    #[Test]
    public function nomor_yang_sama_ditulis_beda_ditolak_sebagai_orang_yang_sama(): void
    {
        /*
         * Inti seluruh berkas ini.
         *
         * Tanpa penyeragaman, "0812-3456-7890" dan "+62 812 3456 7890" adalah dua teks yang
         * berbeda bagi basis data — keduanya lolos, dan utang Budi terpecah ke dua baris.
         * Pemilik membuka salah satunya, melihat separuh utangnya, dan menagih segitu.
         */
        $this->buatPelanggan('Budi Santoso', '081234567890');

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Budi S.')
            ->set('noHp', '+62 812-3456-7890')
            ->call('simpan')
            ->assertHasErrors('noHp');

        $this->assertSame(1, Customer::count(), 'baris kedua untuk orang yang sama tidak boleh lahir');
    }

    #[Test]
    public function pesan_penolakan_menyebut_nama_pemilik_nomornya(): void
    {
        // "Nomor sudah dipakai" membuat pemilik mencari sendiri di daftar 300 baris. Menyebut
        // namanya membuat ia langsung tahu ini orang yang sama, dan berhenti membuat baris kedua.
        $this->buatPelanggan('Budi Santoso', '081234567890');

        $galat = $this->layar()
            ->call('tambah')
            ->set('nama', 'Budi S.')
            ->set('noHp', '081234567890')
            ->call('simpan')
            ->errors()
            ->first('noHp');

        $this->assertStringContainsString('Budi Santoso', (string) $galat);
    }

    #[Test]
    public function nama_kembar_justru_diizinkan(): void
    {
        /*
         * Kebalikan dari nomor, dan disengaja: ada tiga "Budi" di buku kasbon mana pun.
         * Melarang nama kembar memaksa pemilik mengarang "Budi 2", yang lalu ikut muncul di
         * setiap laporan dan tidak pernah bisa dirapikan.
         */
        $this->buatPelanggan('Budi', '081100000001');

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Budi')
            ->set('noHp', '081100000002')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(2, Customer::where('nama', 'Budi')->count());
    }

    #[Test]
    public function dua_pelanggan_tanpa_nomor_tidak_saling_menabrak(): void
    {
        /*
         * Kalau nomor kosong tersimpan sebagai teks kosong (bukan NULL), pelanggan KEDUA yang
         * nomornya memang tidak diketahui ditolak dengan pesan "nomor ini sudah dipakai Budi"
         * — untuk nomor yang tidak pernah ia isi. Tidak masuk akal bagi siapa pun yang membacanya.
         */
        $this->buatPelanggan('Budi');

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Siti')
            ->set('noHp', '')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertNull(Customer::where('nama', 'Siti')->firstOrFail()->no_hp);
    }

    #[Test]
    public function mengubah_pelanggan_tidak_menabrak_nomornya_sendiri(): void
    {
        // Gerbang yang mengabaikan dirinya sendiri: tanpa whereKeyNot, membuka "Ubah" lalu
        // menyimpan tanpa menyentuh apa pun akan ditolak — dan pemiliknya tidak punya cara
        // memperbaiki namanya sama sekali.
        $budi = $this->buatPelanggan('Budi', '081234567890');

        $this->layar()
            ->call('ubah', $budi->getKey())
            ->set('nama', 'Budi Santoso')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('Budi Santoso', $budi->fresh()->nama);
    }

    #[Test]
    public function tanggal_lahir_di_masa_depan_ditolak(): void
    {
        // Bukan salah ketik yang lucu: kolom ini akan dipakai fitur ucapan ulang tahun, dan
        // tanggal 2087 berarti pelanggan itu tidak pernah masuk daftar ucapan sekali pun.
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Siti')
            ->set('tanggalLahir', now()->addYear()->toDateString())
            ->call('simpan')
            ->assertHasErrors('tanggalLahir');
    }

    #[Test]
    public function nomor_kependekan_ditolak(): void
    {
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Siti')
            ->set('noHp', '0812')
            ->call('simpan')
            ->assertHasErrors('noHp');
    }

    #[Test]
    public function formulir_ubah_terisi_termasuk_tanggal_dalam_bentuk_yang_dibaca_kotak_date(): void
    {
        /*
         * Kotak <input type="date"> hanya menerima Y-m-d. Format tampilan Indonesia membuat
         * kotaknya terbuka KOSONG untuk pelanggan yang tanggalnya sudah terisi — dan menyimpan
         * dari keadaan itu MENGHAPUS tanggalnya, tanpa satu pun galat.
         */
        $budi = Customer::create([
            'nama' => 'Budi',
            'no_hp' => '081234567890',
            'tanggal_lahir' => '1990-05-17',
        ]);

        $this->layar()
            ->call('ubah', $budi->getKey())
            ->assertSet('nama', 'Budi')
            ->assertSet('noHp', '081234567890')
            ->assertSet('tanggalLahir', '1990-05-17');
    }

    /* ── Gerbang hapus ───────────────────────────────────────────────────── */

    #[Test]
    public function pelanggan_yang_masih_berutang_tidak_bisa_dihapus(): void
    {
        /*
         * Gerbang terpenting di layar ini.
         *
         * Customer memakai SoftDeletes, jadi menghapus hanya mengisi `deleted_at`. Baris
         * `credit_ledgers`-nya tidak ikut terhapus dan tidak ikut lunas, sementara daftar ini
         * dan layar Kasbon berhenti menampilkan orangnya. Dasbor TETAP menjumlahkan
         * piutangnya. Hasilnya: piutang Rp 500.000 yang tidak bisa ditelusuri ke satu pun
         * baris yang terlihat di layar mana pun.
         *
         * Dipanggil LANGSUNG, tanpa dialog: dialog SweetAlert bukan pengamannya — muatan
         * Livewire bisa dikirim tanpa pernah melewatinya.
         */
        $budi = $this->buatPelanggan('Budi', '081234567890');
        $this->buatKasbon($budi, 500000);

        $this->layar()->call('hapus', $budi->getKey());

        $this->assertNotSoftDeleted($budi);
    }

    #[Test]
    public function penolakan_hapus_menyebut_jumlah_utangnya(): void
    {
        // Penolakan tanpa angka membuat pemilik menekan tombol yang sama sekali lagi. Angkanya
        // juga yang memberi tahu ke mana ia harus pergi: melunasi Rp 350.000 di layar Kasbon.
        $budi = $this->buatPelanggan('Budi', '081234567890');
        $this->buatKasbon($budi, 500000, 150000);

        $this->layar()
            ->call('hapus', $budi->getKey())
            ->assertDispatched('toast', function (string $nama, array $data) {
                return $data['jenis'] === 'galat'
                    && str_contains($data['pesan'], 'Rp 350.000');
            });
    }

    #[Test]
    public function kasbon_yang_sudah_lunas_tidak_menahan_penghapusan(): void
    {
        // Kalau yang lunas ikut menahan, setiap pelanggan yang pernah berutang sekali jadi
        // tidak-bisa-dihapus selamanya — dan itu hampir semua pelanggan di buku kasbon.
        $budi = $this->buatPelanggan('Budi', '081234567890');
        $this->buatKasbon($budi, 500000, 500000);

        $this->layar()->call('hapus', $budi->getKey());

        $this->assertSoftDeleted($budi);
    }

    #[Test]
    public function pelanggan_tanpa_utang_bisa_dihapus(): void
    {
        $siti = $this->buatPelanggan('Siti', '081200000001');

        $this->layar()->call('hapus', $siti->getKey());

        $this->assertSoftDeleted($siti);
    }

    /* ── Daftar & saringan ───────────────────────────────────────────────── */

    #[Test]
    public function kolom_kasbon_menampilkan_sisa_utang_bukan_utang_awal(): void
    {
        // Rp 500.000 yang sudah dicicil Rp 150.000 adalah utang Rp 350.000. Menampilkan angka
        // awalnya membuat pemilik menagih uang yang sudah ia terima.
        $budi = $this->buatPelanggan('Budi', '081234567890');
        $this->buatKasbon($budi, 500000, 150000);

        $this->layar()
            ->assertSee('Rp 350.000')
            ->assertDontSee('Rp 500.000');
    }

    #[Test]
    public function saringan_masih_berutang_menyembunyikan_yang_lunas(): void
    {
        $budi = $this->buatPelanggan('Budi', '081200000001');
        $this->buatKasbon($budi, 100000);

        $siti = $this->buatPelanggan('Siti', '081200000002');
        $this->buatKasbon($siti, 100000, 100000);

        $lina = $this->buatPelanggan('Lina', '081200000003');

        $this->layar()
            ->set('hanyaBerutang', true)
            ->assertSee('Budi')
            ->assertDontSee('Siti')
            ->assertDontSee('Lina');

        // Kontrol: tanpa saringan ketiganya terlihat. Tanpa ini, saringan yang kebetulan
        // menyembunyikan SEMUA orang juga akan lolos uji di atas.
        $this->layar()
            ->assertSee('Budi')
            ->assertSee('Siti')
            ->assertSee('Lina');
    }

    #[Test]
    public function saringan_menyaring_di_basis_data_bukan_sesudah_halaman_dipotong(): void
    {
        /*
         * 12 pelanggan lunas dibuat dengan nama yang mendahului "Zulkifli" secara alfabet,
         * jadi kalau penyaringannya terjadi di PHP SESUDAH paginate(), halaman pertama berisi
         * 10 baris lunas yang semuanya dibuang — dan pemilik melihat daftar KOSONG untuk
         * saringan yang seharusnya menemukan satu orang.
         */
        for ($i = 1; $i <= 12; $i++) {
            $lunas = $this->buatPelanggan('Ahmad '.$i, '0812000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $this->buatKasbon($lunas, 50000, 50000);
        }

        $zul = $this->buatPelanggan('Zulkifli', '081299999999');
        $this->buatKasbon($zul, 75000);

        $this->layar()
            ->set('hanyaBerutang', true)
            ->assertSee('Zulkifli')
            ->assertSee('Rp 75.000');
    }

    #[Test]
    public function pencarian_menemukan_nomor_walau_diketik_dengan_tanda_hubung(): void
    {
        // Yang tersimpan "081234567890" tanpa tanda hubung. Orang yang menyalin nomor dari
        // kontaknya mengetiknya berikut tanda hubung, dan pencarian atas teks mentah tidak
        // menemukan apa pun — pemilik menyimpulkan pelanggannya belum pernah dimasukkan, lalu
        // membuat baris kedua untuk orang yang sama.
        $this->buatPelanggan('Budi Santoso', '081234567890');
        $this->buatPelanggan('Siti Aminah', '081999999999');

        $this->layar()
            ->set('cari', '0812-3456-7890')
            ->assertSee('Budi Santoso')
            ->assertDontSee('Siti Aminah');
    }

    #[Test]
    public function total_piutang_hanya_menjumlah_yang_belum_lunas(): void
    {
        $budi = $this->buatPelanggan('Budi', '081200000001');
        $this->buatKasbon($budi, 300000, 100000);

        $siti = $this->buatPelanggan('Siti', '081200000002');
        $this->buatKasbon($siti, 900000, 900000);

        // 300.000 - 100.000 = 200.000. Yang lunas tidak ikut, dan angka awal 300.000 tidak ikut.
        $this->layar()->assertViewHas('totalPiutang', 200000.0);
    }

    /* ── Tenant ──────────────────────────────────────────────────────────── */

    #[Test]
    public function pelanggan_tenant_lain_tidak_terlihat_dan_tidak_bisa_diubah(): void
    {
        $lain = $this->buatTenant('Warung Sebelah');
        $asing = $this->konteks()->forTenant(
            $lain->getKey(),
            fn () => Customer::create(['nama' => 'Pelanggan Sebelah', 'no_hp' => '081777777777']),
        );

        $this->layar()->assertDontSee('Pelanggan Sebelah');

        // findOrFail() lewat TenantScope menjawab 404, bukan menyunting diam-diam.
        $this->expectException(ModelNotFoundException::class);
        $this->layar()->call('ubah', $asing->getKey());
    }

    #[Test]
    public function nomor_kembar_di_tenant_lain_tidak_menahan(): void
    {
        /*
         * Dua warung yang berbeda boleh punya pelanggan dengan nomor yang sama — mereka memang
         * orang yang sama, tapi buku kasbonnya berbeda. Gerbang yang bocor lintas tenant
         * membocorkan keberadaan pelanggan warung lain lewat pesan galatnya, berikut namanya.
         */
        $lain = $this->buatTenant('Warung Sebelah');
        $this->konteks()->forTenant(
            $lain->getKey(),
            fn () => Customer::create(['nama' => 'Pelanggan Sebelah', 'no_hp' => '081234567890']),
        );
        $this->konteks()->setTenant($this->tenant->getKey());

        $this->layar()
            ->call('tambah')
            ->set('nama', 'Budi')
            ->set('noHp', '081234567890')
            ->call('simpan')
            ->assertHasNoErrors();
    }

    #[Test]
    public function kasir_tidak_boleh_membuka_layar_ini(): void
    {
        /*
         * Yang ditahan bukan pengetahuan tentang pelanggan, melainkan kemampuan MENGUBAHNYA.
         * Nomor HP adalah penanda orang yang dipakai kasbon; kasir yang bisa menyuntingnya
         * bisa memindahkan utang dari satu orang ke orang lain hanya dengan mengganti nomor,
         * dan tidak ada satu pun jejak yang menunjukkan itu terjadi.
         */
        $kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir',
            'username' => 'kasir-pelanggan',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        // Dipulangkan ke berandanya sendiri, bukan 403 — perilaku yang sudah berlaku untuk
        // seluruh area back office (GerbangAksesTest). Halaman galat di tengah antrean tidak
        // menolong siapa pun; yang dibutuhkan kasir adalah kembali ke layar jualannya.
        $this->actingAs($kasir)
            ->get(route('owner.pelanggan'))
            ->assertRedirect(route('kasir.beranda'));
    }
}

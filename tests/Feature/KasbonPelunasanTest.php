<?php

namespace Tests\Feature;

use App\Actions\Kasbon\CatatPelunasanAction;
use App\Enums\CreditStatus;
use App\Enums\UserRole;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Pelanggan\CreditPayment;
use App\Models\Pelanggan\Customer;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Pelunasan kasbon — satu-satunya jalan `jumlah_dibayar` berubah.
 *
 * Yang dijaga berkas ini, dan tiap butirnya menyentuh uang pelanggan secara langsung:
 *
 *  - SISA UTANG selalu sama dengan jumlah riwayat setorannya. Angka turunan yang bisa
 *    berbeda dari riwayatnya membuat perdebatan tidak punya wasit.
 *  - SETORAN BERLEBIH DITOLAK, bukan dipotong diam-diam. Salah ketik satu angka nol dan
 *    pelanggan yang memang menyerahkan uang lebih adalah dua keadaan berbeda; pemotongan
 *    senyap membuat keduanya berakhir sama, yaitu uang yang menguap dari catatan.
 *  - PEMBATALAN mengembalikan sisa utang ke angka yang benar tanpa ada yang mengurangi apa
 *    pun dengan tangan, dan barisnya TETAP ADA sebagai bukti pernah ada catatan keliru.
 *  - STATUS dan `dilunasi_pada` tidak pernah bercerita dua hal sekaligus.
 */
class KasbonPelunasanTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    private Customer $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Kasbon');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@kasbon.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->budi = Customer::create(['nama' => 'Pak Budi', 'no_hp' => '081234567890']);
    }

    private function aksi(): CatatPelunasanAction
    {
        return app(CatatPelunasanAction::class);
    }

    private function buatKasbon(float $utang = 500000): CreditLedger
    {
        return CreditLedger::create([
            'outlet_id' => $this->outlet->getKey(),
            'customer_id' => $this->budi->getKey(),
            'jumlah_utang' => $utang,
        ]);
    }

    /* ── Mencatat setoran ────────────────────────────────────────────────── */

    #[Test]
    public function setoran_mengurangi_sisa_utang_dan_meninggalkan_barisnya(): void
    {
        $kasbon = $this->buatKasbon(500000);

        $setoran = $this->aksi()->execute($kasbon, 150000, $this->owner);

        $kasbon->refresh();

        $this->assertSame(350000.0, $kasbon->sisaUtang());
        $this->assertSame('150000.00', $kasbon->jumlah_dibayar);
        $this->assertSame($this->owner->getKey(), $setoran->diterima_oleh, 'penerimanya harus tercatat');
        $this->assertSame(1, $kasbon->payments()->count());
    }

    #[Test]
    public function beberapa_cicilan_dijumlah_bukan_saling_menimpa(): void
    {
        // Cicilan adalah bentuk normal kasbon warung, bukan kasus tepi. Kalau setoran kedua
        // menimpa yang pertama, pelanggan yang sudah membayar dua kali tetap tertagih penuh.
        $kasbon = $this->buatKasbon(500000);

        $this->aksi()->execute($kasbon, 100000, $this->owner);
        $this->aksi()->execute($kasbon, 250000, $this->owner);

        $kasbon->refresh();

        $this->assertSame(150000.0, $kasbon->sisaUtang());
        $this->assertSame(2, $kasbon->payments()->count());
    }

    #[Test]
    public function setoran_sebesar_sisa_utang_membuatnya_lunas_dan_bertanggal(): void
    {
        $kasbon = $this->buatKasbon(500000);

        $this->aksi()->execute($kasbon, 500000, $this->owner);

        $kasbon->refresh();

        $this->assertSame(CreditStatus::Lunas, $kasbon->status);
        $this->assertNotNull($kasbon->dilunasi_pada, 'kasbon lunas tanpa tanggal tidak bisa dilaporkan');
        $this->assertSame(0.0, $kasbon->sisaUtang());
    }

    #[Test]
    public function angka_turunannya_selalu_sama_dengan_jumlah_riwayatnya(): void
    {
        /*
         * Inti seluruh rancangan ini. `jumlah_dibayar` adalah angka turunan yang disimpan
         * demi kecepatan daftar; begitu ia berbeda dari SUM riwayatnya, tidak ada cara
         * memutuskan mana yang benar — dan yang diperdebatkan adalah uang pelanggan.
         */
        $kasbon = $this->buatKasbon(500000);

        $this->aksi()->execute($kasbon, 75000, $this->owner);
        $this->aksi()->execute($kasbon, 125000, $this->owner);
        $satuLagi = $this->aksi()->execute($kasbon, 50000, $this->owner);
        $this->aksi()->batalkan($satuLagi);

        $kasbon->refresh();

        $this->assertSame(
            round((float) $kasbon->payments()->sum('jumlah'), 2),
            round((float) $kasbon->jumlah_dibayar, 2),
        );
    }

    /* ── Penolakan ───────────────────────────────────────────────────────── */

    #[Test]
    public function setoran_lebih_besar_daripada_sisa_utang_ditolak_bukan_dipotong(): void
    {
        $kasbon = $this->buatKasbon(500000);
        $this->aksi()->execute($kasbon, 400000, $this->owner);

        $this->expectException(RuntimeException::class);
        // Pesannya menyebut sisa utangnya, supaya pemilik tahu angka yang benar tanpa
        // menutup panel dan menghitung sendiri.
        $this->expectExceptionMessageMatches('/Rp 100\.000/');

        $this->aksi()->execute($kasbon->refresh(), 150000, $this->owner);
    }

    #[Test]
    public function setoran_berlebih_yang_ditolak_tidak_meninggalkan_baris(): void
    {
        // Penolakan yang setengah jalan lebih buruk daripada tidak ada penolakan: riwayatnya
        // bertambah, angka utangnya tidak, dan keduanya berbeda sejak saat itu.
        $kasbon = $this->buatKasbon(100000);

        try {
            $this->aksi()->execute($kasbon, 500000, $this->owner);
        } catch (RuntimeException) {
            // memang diharapkan
        }

        $this->assertSame(0, CreditPayment::count());
        $this->assertSame(100000.0, $kasbon->refresh()->sisaUtang());
    }

    #[Test]
    public function kasbon_yang_sudah_lunas_tidak_bisa_disetor_lagi(): void
    {
        $kasbon = $this->buatKasbon(100000);
        $this->aksi()->execute($kasbon, 100000, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sudah lunas/');

        $this->aksi()->execute($kasbon->refresh(), 10000, $this->owner);
    }

    #[Test]
    public function setoran_nol_atau_negatif_ditolak(): void
    {
        $kasbon = $this->buatKasbon(100000);

        $this->expectException(RuntimeException::class);

        $this->aksi()->execute($kasbon, 0, $this->owner);
    }

    #[Test]
    public function tanggal_setor_di_masa_depan_ditolak(): void
    {
        /*
         * Layar penagihan menjumlah setoran "hari ini". Satu baris bertanggal bulan depan
         * membuat uang yang sudah ada di laci tidak muncul di rekap hari mana pun — hilang
         * dari pandangan tanpa hilang dari basis data.
         */
        $kasbon = $this->buatKasbon(100000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/masa depan/');

        $this->aksi()->execute($kasbon, 50000, $this->owner, now()->addDays(3));
    }

    #[Test]
    public function tanggal_setor_kemarin_justru_diterima(): void
    {
        // Kebalikan yang wajib ikut diuji: pemilik sering baru mencatat malam hari setoran
        // yang diterima siang, atau menyusulkan catatan kemarin. Penolakan di sini membuat
        // orang mengarang tanggal hari ini — dan riwayatnya berhenti bisa dipercaya.
        $kasbon = $this->buatKasbon(100000);

        $setoran = $this->aksi()->execute($kasbon, 50000, $this->owner, now()->subDay());

        $this->assertTrue($setoran->dibayar_pada->isYesterday());
    }

    /* ── Pembatalan ──────────────────────────────────────────────────────── */

    #[Test]
    public function membatalkan_setoran_mengembalikan_sisa_utang(): void
    {
        $kasbon = $this->buatKasbon(500000);
        $setoran = $this->aksi()->execute($kasbon, 200000, $this->owner);

        $this->aksi()->batalkan($setoran);

        $this->assertSame(500000.0, $kasbon->refresh()->sisaUtang());
    }

    #[Test]
    public function setoran_yang_dibatalkan_tetap_terbaca_sebagai_bukti(): void
    {
        // Yang dicari orang saat angka kasbon tidak cocok dengan ingatannya justru catatan
        // keliru itu sendiri. Baris yang lenyap tanpa bekas membuat pemilik dan pelanggan
        // berdebat tanpa satu pun bukti di tengah.
        $kasbon = $this->buatKasbon(500000);
        $setoran = $this->aksi()->execute($kasbon, 200000, $this->owner);

        $this->aksi()->batalkan($setoran);

        $this->assertSoftDeleted($setoran);
        $this->assertSame(1, CreditPayment::withTrashed()->count());
    }

    #[Test]
    public function membatalkan_setoran_pelunas_mencabut_status_lunas_dan_tanggalnya(): void
    {
        /*
         * Kasbon berstatus belum lunas TAPI memegang tanggal pelunasan adalah baris yang
         * bercerita dua hal sekaligus, dan laporan mana pun yang membacanya akan memilih
         * salah satu — diam-diam.
         */
        $kasbon = $this->buatKasbon(300000);
        $setoran = $this->aksi()->execute($kasbon, 300000, $this->owner);

        $this->assertSame(CreditStatus::Lunas, $kasbon->refresh()->status);

        $this->aksi()->batalkan($setoran);

        $kasbon->refresh();

        $this->assertSame(CreditStatus::BelumLunas, $kasbon->status);
        $this->assertNull($kasbon->dilunasi_pada);
    }

    #[Test]
    public function membatalkan_satu_dari_beberapa_setoran_hanya_mengurangi_yang_itu(): void
    {
        $kasbon = $this->buatKasbon(500000);
        $pertama = $this->aksi()->execute($kasbon, 100000, $this->owner);
        $this->aksi()->execute($kasbon, 250000, $this->owner);

        $this->aksi()->batalkan($pertama);

        $this->assertSame(250000.0, $kasbon->refresh()->sisaUtang());
    }

    /* ── Tenant ──────────────────────────────────────────────────────────── */

    #[Test]
    public function setoran_ikut_terikat_tenant_yang_sedang_aktif(): void
    {
        // tenant_id TIDAK PERNAH fillable — diisi BelongsToTenant. Baris setoran yang lolos
        // tanpa tenant terlihat di buku kasbon warung lain.
        $kasbon = $this->buatKasbon(100000);

        $setoran = $this->aksi()->execute($kasbon, 50000, $this->owner);

        $this->assertSame($this->tenant->getKey(), $setoran->tenant_id);
    }
}

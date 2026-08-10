<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Istilah\Istilah as LayarIstilah;
use App\Livewire\Pages\Owner\Pengaturan\Pengaturan as LayarPengaturan;
use App\Livewire\Pages\Owner\Produk\Produk;
use App\Models\Produk\Product;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use App\Support\Istilah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Pengaturan & Arti istilah — dua layar yang lahir dari keluhan pemilik (2026-08-10).
 *
 * KELUHANNYA, dan ia menentukan seluruh bentuk kedua layar ini: target untung dulu disetel di
 * layar Biaya operasional padahal yang terpengaruh layar Produk. Angka yang DIPAKAI di satu
 * layar tapi DISETEL di layar lain memaksa orang berpindah-pindah untuk mengerti satu hal —
 * dan orang yang harus berpindah tiga layar untuk mengubah satu angka akan berhenti
 * mengubahnya sama sekali.
 *
 * Jawabannya DUA jalan, dan berkas ini menjaga keduanya tetap sepakat:
 *  1. di TEMPAT ia dipakai (formulir Produk), supaya tidak ada perpindahan layar;
 *  2. di HALAMAN PENGATURAN, supaya pertanyaan "di mana saya mengubah ini?" punya satu
 *     jawaban yang bisa diingat.
 *
 * Dua jalan ke satu angka berarti keduanya HARUS menulis ke tempat yang sama. Kalau tidak,
 * pemilik mengubahnya di satu layar dan melihat angka lama di layar lain — persis kebingungan
 * yang mau dihilangkan.
 */
class OwnerPengaturanTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Pengaturan');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@pengaturan.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Target untung: dua jalan, satu angka ────────────────────────────── */

    #[Test]
    public function target_untung_tersimpan_dari_halaman_pengaturan(): void
    {
        Livewire::actingAs($this->owner)->test(LayarPengaturan::class)
            ->set('targetMargin', '35')
            ->call('simpanTargetMargin')
            ->assertHasNoErrors();

        $this->assertSame(35.0, (float) $this->tenant->fresh()->target_margin);
    }

    #[Test]
    public function target_untung_tersimpan_dari_formulir_produk_tanpa_pindah_layar(): void
    {
        /*
         * INTI dari keluhan pemiliknya. Kalau jalur ini tidak ada, satu-satunya cara mengubah
         * target adalah meninggalkan formulir yang sedang diisi, pergi ke layar lain, lalu
         * kembali dan mengisi ulang — dan pekerjaan yang belum disimpan hilang di tengah jalan.
         */
        Livewire::actingAs($this->owner)->test(Produk::class)
            ->call('tambah')
            ->set('targetMarginBaru', '40')
            ->call('ubahTargetMargin')
            ->assertHasNoErrors();

        $this->assertSame(40.0, (float) $this->tenant->fresh()->target_margin);
    }

    #[Test]
    public function kedua_jalan_menulis_ke_angka_yang_sama(): void
    {
        /*
         * Dua pintu ke satu angka HARUS menulis ke tempat yang sama. Kalau tidak, pemilik
         * mengubahnya di formulir produk lalu membuka Pengaturan dan melihat angka lama —
         * dan sesudah itu ia tidak tahu lagi mana yang sedang dipakai aplikasi.
         */
        Livewire::actingAs($this->owner)->test(Produk::class)
            ->call('tambah')
            ->set('targetMarginBaru', '45')
            ->call('ubahTargetMargin');

        Livewire::actingAs($this->owner)->test(LayarPengaturan::class)
            ->assertSet('targetMargin', '45');
    }

    #[Test]
    public function target_yang_diubah_langsung_mengubah_saran_harganya(): void
    {
        // Kalau sarannya tidak ikut berubah, orangnya menyimpulkan angkanya tidak tersimpan
        // lalu mengubahnya berkali-kali.
        $layar = Livewire::actingAs($this->owner)->test(Produk::class)
            ->call('tambah')
            ->set('hargaBeli', 10000.0);

        // 30% bawaan → 10.000 / 0,7 = 14.285,71 → dibulatkan ke atas jadi 14.500.
        $layar->assertSee('14.500');

        // 50% → 10.000 / 0,5 = 20.000 tepat.
        $layar->set('targetMarginBaru', '50')->call('ubahTargetMargin')->assertSee('20.000');
    }

    #[Test]
    public function target_di_luar_batas_ditolak_di_kedua_jalan(): void
    {
        /*
         * Batas yang cuma dipasang di satu pintu adalah batas yang bisa dilewati lewat pintu
         * satunya. Pada 100% rumus saran harga membagi dengan NOL.
         */
        Livewire::actingAs($this->owner)->test(LayarPengaturan::class)
            ->set('targetMargin', '100')
            ->call('simpanTargetMargin')
            ->assertHasErrors('targetMargin');

        Livewire::actingAs($this->owner)->test(Produk::class)
            ->call('tambah')
            ->set('targetMarginBaru', '100')
            ->call('ubahTargetMargin')
            ->assertHasErrors('targetMarginBaru');

        $this->assertSame(30.0, (float) $this->tenant->fresh()->target_margin);
    }

    #[Test]
    public function halaman_pengaturan_menunjukkan_contoh_berangka_bukan_cuma_persen(): void
    {
        /*
         * Persentase adalah bentuk yang paling sering salah dipahami. "30%" baru berarti
         * begitu ia berwujud "modal Rp 10.000 jadi dijual Rp 14.500" — dan itu satu-satunya
         * alasan halaman ini lebih berguna daripada satu kotak isian.
         */
        Livewire::actingAs($this->owner)->test(LayarPengaturan::class)
            ->assertSee('10.000')
            ->assertSee('14.500');
    }

    /* ── Arti istilah ────────────────────────────────────────────────────── */

    #[Test]
    public function halaman_istilah_memuat_seluruh_istilah(): void
    {
        Livewire::actingAs($this->owner)->test(LayarIstilah::class)
            ->assertViewHas('jumlah', count(Istilah::semua()))
            ->assertSee('Modal')
            ->assertSee('Kasbon');
    }

    #[Test]
    public function istilah_bisa_dicari_lewat_kata_yang_ada_di_penjelasannya(): void
    {
        /*
         * Orang yang bingung TIDAK tahu nama istilahnya — ia tahu gejalanya. Mengetik "sewa"
         * harus menemukan "Biaya operasional" walaupun kata "sewa" tidak ada di judulnya.
         * Pencarian yang cuma membaca judul akan menjawab "tidak ditemukan" untuk kata yang
         * paling mungkin diketik orang.
         */
        Livewire::actingAs($this->owner)->test(LayarIstilah::class)
            ->set('cari', 'sewa')
            ->assertSee('Biaya operasional');
    }

    #[Test]
    public function pencarian_yang_tidak_ketemu_tidak_menyalahkan_orangnya(): void
    {
        // Layar bantuan yang membuat orang merasa bodoh adalah layar bantuan yang tidak
        // dibuka lagi.
        Livewire::actingAs($this->owner)->test(LayarIstilah::class)
            ->set('cari', 'zzzz')
            ->assertViewHas('jumlah', 0)
            ->assertSee('bukan kekurangan Anda');
    }

    #[Test]
    public function tiap_istilah_punya_arti_dan_contoh_berangka(): void
    {
        /*
         * Dijaga di sini, bukan dipercaya: istilah yang ditambahkan nanti tanpa contoh akan
         * lolos diam-diam, dan contoh berangka rupiah itulah penjelasan yang sebenarnya —
         * kalimat di atasnya cuma pengantar.
         */
        foreach (Istilah::semua() as $kunci => $isi) {
            $this->assertNotSame('', trim($isi['arti']), "istilah {$kunci} tanpa arti");
            $this->assertNotNull($isi['contoh'], "istilah {$kunci} tanpa contoh");
            /*
             * Yang dituntut cuma ADA ANGKANYA, bukan harus berupa rupiah atau satuan tertentu.
             * Aturan yang lebih ketat sempat dipasang dan langsung menolak contoh yang sudah
             * baik ("10 Agt masuk 20 · keluar 3") — dan aturan yang menolak contoh bagus akan
             * membuat orang berikutnya menulis contoh yang buruk supaya ujinya hijau.
             */
            $this->assertMatchesRegularExpression(
                '/\d/',
                $isi['contoh'],
                "contoh istilah {$kunci} tidak memuat satu pun angka — contoh tanpa angka cuma kalimat kedua",
            );
        }
    }

    #[Test]
    public function tautan_lihat_juga_selalu_menunjuk_istilah_yang_ada(): void
    {
        // Tautan ke istilah yang tidak ada akan diam-diam hilang dari layar (Blade
        // melewatinya), jadi pembacanya tidak pernah tahu ada penjelasan yang dijanjikan.
        $semua = Istilah::semua();

        foreach ($semua as $kunci => $isi) {
            foreach ($isi['lihatJuga'] as $lain) {
                $this->assertArrayHasKey($lain, $semua, "istilah {$kunci} menunjuk {$lain} yang tidak ada");
            }
        }
    }

    #[Test]
    public function penjelasan_di_layar_memakai_sumber_yang_sama_dengan_halaman_istilah(): void
    {
        /*
         * Arti yang ditulis dua kali akan bercabang pada perbaikan pertama, dan yang bercabang
         * di sini adalah penjelasan tentang uang. Diuji lewat layar Produk yang memasang
         * <x-jelaskan kunci="untung">: kalimatnya harus SAMA PERSIS dengan yang di kamus.
         */
        $this->konteks()->forTenant($this->tenant->getKey(), fn () => Product::create([
            'nama_produk' => 'Kopi Sachet',
            'harga_default' => 3000,
            'harga_beli' => 2000,
            'satuan' => Satuan::Pcs,
        ]));

        Livewire::actingAs($this->owner)->test(Produk::class)
            ->assertSee(Istilah::ambil('untung')['arti']);
    }

    /* ── Peran ───────────────────────────────────────────────────────────── */

    #[Test]
    public function kasir_tidak_boleh_membuka_pengaturan(): void
    {
        // Target untung menyusun saran harga SELURUH katalog.
        $kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir',
            'username' => 'kasir-pengaturan',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->actingAs($kasir)->get(route('owner.pengaturan'))->assertRedirect(route('kasir.beranda'));
    }
}

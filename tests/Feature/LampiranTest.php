<?php

namespace Tests\Feature;

use App\Actions\Lampiran\SimpanLampiranAction;
use App\Actions\Pembelian\BatalkanPembelianAction;
use App\Actions\Pembelian\SimpanBuktiBelanjaAction;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\Pembelian;
use App\Models\Lampiran\Lampiran;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Lampiran banyak (foto struk, kwitansi, PDF invoice) untuk satu dokumen.
 *
 * Sifat yang paling menentukan di berkas ini, dan ia mengatur seluruh bentuk aksinya:
 * **kegagalan lampiran TIDAK PERNAH menggagalkan dokumennya, dan tidak pernah
 * menggagalkan lampiran lain yang sudah baik.** Nota belanja adalah catatan uang keluar;
 * fotonya penguat. Satu berkas 9 MB yang membuang nota 12 baris di depan grosir membuat
 * orang berhenti mencatat sama sekali — dan yang hilang jauh lebih mahal daripada fotonya.
 *
 * `Storage::fake` dipakai untuk KEDUA disk: 'lampiran' (tujuan) dan disk bawaan (tempat
 * Livewire menaruh unggahan sementara). Memalsukan yang salah tidak pernah menggagalkan
 * apa pun — ia cuma memindahkan tulisannya ke cakram sungguhan, dan itu sudah terjadi
 * sekali di repo ini: enam berkas nyata tertinggal sementara ujinya tetap hijau.
 */
class LampiranTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Lampiran');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Lampiran');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Lampiran',
            'email' => 'owner@lampiran.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        Storage::fake('lampiran');
        Storage::fake(config('filesystems.default'));
    }

    /**
     * PDF dengan ISI PDF sungguhan.
     *
     * `UploadedFile::fake()->create('x.pdf', 120, 'application/pdf')` menghasilkan berkas
     * yang isinya KOSONG — finfo membacanya `application/x-empty`, dan penjaga isi berkas
     * menolaknya dengan benar. Memakainya di uji berarti mengukur berkas yang tidak pernah
     * ada di dunia nyata; yang mau diuji adalah PDF yang memang PDF.
     *
     * `$kb` mengembang isinya supaya batas ukuran per jenis bisa diuji sungguhan.
     */
    private function pdfAsli(string $nama, int $kb = 1): UploadedFile
    {
        $isi = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $isi .= str_repeat(' ', max(0, $kb * 1024 - strlen($isi) - 20));
        $isi .= "\ntrailer\n<< >>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($nama, $isi);
    }

    private function nota(): PurchaseOrder
    {
        return $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($this->buatProduk('Kopi Lampiran'), 5, 12000)],
        ]);
    }

    /* ── Banyak berkas sekaligus ─────────────────────────────────────────── */

    public function test_tiga_lampiran_tersimpan_berurutan_sesuai_urutan_pilih(): void
    {
        $nota = $this->nota();

        $hasil = app(SimpanLampiranAction::class)->execute($nota, [
            UploadedFile::fake()->image('struk-1.jpg'),
            UploadedFile::fake()->image('struk-2.jpg'),
            UploadedFile::fake()->image('struk-3.jpg'),
        ], $this->owner->getKey());

        $this->assertSame(3, $hasil['masuk']);
        $this->assertSame([], $hasil['ditolak']);

        $urut = $nota->lampiran()->get();

        $this->assertCount(3, $urut);
        $this->assertSame(['struk-1.jpg', 'struk-2.jpg', 'struk-3.jpg'], $urut->pluck('nama_asli')->all());
        $this->assertSame([1, 2, 3], $urut->pluck('urutan')->all());

        foreach ($urut as $l) {
            Storage::disk('lampiran')->assertExists($l->path);
            $this->assertStringNotContainsString('produk/', $l->path,
                'JANGAN menulis ke folder produk: dilindungi aturan keras nomor 1');
        }
    }

    /**
     * Batas 10 menolak SISANYA — yang muat tetap masuk, dan jumlahnya disebut.
     *
     * Membatalkan seluruh unggahan karena yang ke-11 tidak muat berarti sepuluh foto yang
     * sudah baik ikut hilang, dan orangnya harus memilih ulang semuanya di sinyal warung.
     */
    public function test_batas_sepuluh_menolak_sisanya_tanpa_membuang_yang_muat(): void
    {
        $nota = $this->nota();

        $berkas = [];

        for ($i = 1; $i <= 12; $i++) {
            $berkas[] = UploadedFile::fake()->image('lembar-'.$i.'.jpg');
        }

        $hasil = app(SimpanLampiranAction::class)->execute($nota, $berkas, $this->owner->getKey());

        $this->assertSame(SimpanLampiranAction::MAKS, $hasil['masuk']);
        $this->assertCount(2, $hasil['ditolak']);
        $this->assertSame('lembar-11.jpg', $hasil['ditolak'][0]['nama']);
        $this->assertStringContainsString('paling banyak 10', $hasil['ditolak'][0]['sebab']);
        $this->assertSame(SimpanLampiranAction::MAKS, $nota->lampiran()->count());
    }

    public function test_batas_dihitung_dari_yang_sudah_ada_bukan_dari_unggahan_ini_saja(): void
    {
        $nota = $this->nota();

        app(SimpanLampiranAction::class)->execute($nota, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ], $this->owner->getKey());

        $berkas = [];

        for ($i = 1; $i <= 9; $i++) {
            $berkas[] = UploadedFile::fake()->image('lagi-'.$i.'.jpg');
        }

        $hasil = app(SimpanLampiranAction::class)->execute($nota, $berkas, $this->owner->getKey());

        $this->assertSame(8, $hasil['masuk'], 'sudah ada 2, jadi yang muat tinggal 8');
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertSame(10, $nota->lampiran()->count());
    }

    /* ── Jenis & ukuran ──────────────────────────────────────────────────── */

    public function test_pdf_diterima_dan_dikenali_sebagai_pdf(): void
    {
        $nota = $this->nota();

        $hasil = app(SimpanLampiranAction::class)->execute($nota, [
            $this->pdfAsli('invoice-grosir.pdf', 120),
        ], $this->owner->getKey());

        $this->assertSame(1, $hasil['masuk']);

        $l = $nota->lampiran()->sole();

        $this->assertTrue($l->pdf());
        $this->assertFalse($l->gambar());
        $this->assertSame('application/pdf', $l->mime);
        $this->assertStringEndsWith('.pdf', $l->path);
    }

    /**
     * Berkas yang MENGAKU PDF tapi isinya bukan: ditolak dari ISINYA.
     *
     * `getClientMimeType()` datang dari peramban dan bisa dikarang — itulah cara berkas HTML
     * mengaku PDF lalu dijalankan di origin kita sendiri, dengan sesi pemiliknya.
     */
    public function test_berkas_yang_mengaku_pdf_tapi_bukan_ditolak(): void
    {
        $nota = $this->nota();

        $palsu = UploadedFile::fake()->createWithContent(
            'invoice.pdf',
            '<html><script>alert(1)</script></html>',
        );

        $hasil = app(SimpanLampiranAction::class)->execute($nota, [$palsu], $this->owner->getKey());

        $this->assertSame(0, $hasil['masuk']);
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertSame(0, $nota->lampiran()->count());
        $this->assertSame([], Storage::disk('lampiran')->allFiles(SimpanLampiranAction::FOLDER),
            'berkas yang ditolak tidak boleh mendarat di disk sama sekali');
    }

    /**
     * Batas ukurannya BERBEDA untuk PDF dan foto, dan itu disengaja.
     *
     * Foto di atas 4 MB berarti kamera yang salah setelan; PDF pindaian tiga halaman dari
     * grosir memang wajar 5–6 MB. Satu batas untuk keduanya salah ke dua arah sekaligus.
     */
    public function test_batas_ukuran_berbeda_untuk_pdf_dan_foto(): void
    {
        $nota = $this->nota();

        $hasil = app(SimpanLampiranAction::class)->execute($nota, [
            $this->pdfAsli('invoice-besar.pdf', 6144),
            UploadedFile::fake()->image('foto-besar.jpg')->size(6144),
        ], $this->owner->getKey());

        $this->assertSame(1, $hasil['masuk'], 'PDF 6 MB diterima, foto 6 MB tidak');
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertSame('foto-besar.jpg', $hasil['ditolak'][0]['nama']);
        $this->assertStringContainsString('Foto paling besar 4 MB', $hasil['ditolak'][0]['sebab']);

        $this->assertTrue($nota->lampiran()->sole()->pdf());
    }

    /**
     * Satu berkas gagal TIDAK membatalkan yang lain, dan namanya disebut.
     *
     * "Sebagian gagal" tanpa menyebut berkas mana membuat orang mengunggah ulang semuanya —
     * termasuk yang sudah masuk, yang lalu jadi ganda.
     */
    public function test_satu_berkas_gagal_tidak_membatalkan_yang_lain(): void
    {
        $nota = $this->nota();

        $hasil = app(SimpanLampiranAction::class)->execute($nota, [
            UploadedFile::fake()->image('baik-1.jpg'),
            UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->image('baik-2.jpg'),
        ], $this->owner->getKey());

        $this->assertSame(2, $hasil['masuk']);
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertSame('virus.exe', $hasil['ditolak'][0]['nama']);
        $this->assertSame(['baik-1.jpg', 'baik-2.jpg'], $nota->lampiran()->pluck('nama_asli')->all());
    }

    /* ── Isolasi tenant ──────────────────────────────────────────────────── */

    public function test_tenant_id_diisi_otomatis_dan_tidak_bisa_ditimpa_dari_muatan(): void
    {
        $nota = $this->nota();
        $lain = $this->buatTenant('Tenant Sebelah');

        Lampiran::create([
            'lampirable_type' => $nota->getMorphClass(),
            'lampirable_id' => $nota->getKey(),
            'path' => 'lampiran/x/y.jpg',
            'mime' => 'image/jpeg',
            'ukuran' => 10,
            'urutan' => 1,
            // Sengaja dikirim: harus DIABAIKAN, bukan dipakai.
            'tenant_id' => $lain->getKey(),
        ]);

        $this->assertSame($this->tenant->getKey(), Lampiran::query()->sole()->tenant_id,
            'tenant_id tidak pernah fillable — diisi BelongsToTenant, bukan muatan');
    }

    /* ── Masa peralihan: bukti_path dan lampiran harus SEPAKAT ───────────── */

    /**
     * Selama layarnya belum berpindah, kolom lama dan tabel baru wajib sepakat.
     *
     * Tabel yang diisi sekali lewat migrasi lalu tidak ikut diperbarui akan melenceng diam-
     * diam sejak hari pertama — dan peralihan berikutnya memindahkan data yang sudah tidak
     * lengkap. Arahnya SATU: bukti_path kebenarannya, lampiran mengikutinya.
     */
    public function test_unggah_bukti_lama_ikut_membuat_baris_lampiran(): void
    {
        $nota = $this->nota();

        $this->assertTrue(
            app(SimpanBuktiBelanjaAction::class)->execute($nota, UploadedFile::fake()->image('struk.jpg')),
        );

        $segar = $nota->fresh();
        $lampiran = $segar->lampiran()->get();

        $this->assertCount(1, $lampiran);
        $this->assertSame($segar->bukti_path, $lampiran->first()->path,
            'baris lampiran harus menunjuk berkas yang SAMA dengan kolom bukti_path');
    }

    public function test_hapus_bukti_lama_ikut_membuang_baris_lampiran(): void
    {
        $nota = $this->nota();

        app(SimpanBuktiBelanjaAction::class)->execute($nota, UploadedFile::fake()->image('struk.jpg'));
        $this->assertSame(1, $nota->fresh()->lampiran()->count());

        app(SimpanBuktiBelanjaAction::class)->hapus($nota->fresh());

        $this->assertSame(0, $nota->fresh()->lampiran()->count(),
            'baris lampiran yang tertinggal menunjuk berkas yang sudah tidak ada');
    }

    /** Mengganti foto tidak boleh meninggalkan DUA baris. */
    public function test_ganti_bukti_menyisakan_tepat_satu_baris_lampiran(): void
    {
        $nota = $this->nota();

        app(SimpanBuktiBelanjaAction::class)->execute($nota, UploadedFile::fake()->image('lama.jpg'));
        app(SimpanBuktiBelanjaAction::class)->execute($nota->fresh(), UploadedFile::fake()->image('baru.jpg'));

        $segar = $nota->fresh();

        $this->assertSame(1, $segar->lampiran()->count());
        $this->assertSame($segar->bukti_path, $segar->lampiran()->sole()->path);
    }

    /* ── Membuang ────────────────────────────────────────────────────────── */

    public function test_hapus_lampiran_membuang_berkasnya_juga(): void
    {
        $nota = $this->nota();

        app(SimpanLampiranAction::class)->execute($nota, [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.jpg'),
        ], $this->owner->getKey());

        $pertama = $nota->lampiran()->first();
        $path = $pertama->path;

        app(SimpanLampiranAction::class)->hapus($pertama);

        Storage::disk('lampiran')->assertMissing($path);
        $this->assertSame(1, $nota->lampiran()->count(), 'yang lain tidak ikut terbawa');
        $this->assertSame('b.jpg', $nota->lampiran()->sole()->nama_asli);
    }

    /* ── Lewat layar (komponen) ──────────────────────────────────────────── */

    public function test_layar_memasang_beberapa_lampiran_sekaligus(): void
    {
        $nota = $this->nota();

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->set('lampiranBaru', [
                UploadedFile::fake()->image('lembar-1.jpg'),
                UploadedFile::fake()->image('lembar-2.jpg'),
            ])
            ->call('pasangLampiran');

        $this->assertSame(2, $nota->fresh()->lampiran()->count());
    }

    /**
     * Nota yang DIBATALKAN: lampirannya terkunci, dipanggil langsung sekalipun.
     *
     * Nota batal biasanya berarti barangnya dikembalikan ke grosir, dan struk itu justru
     * satu-satunya bukti pengembaliannya.
     */
    public function test_nota_dibatalkan_menolak_lampiran_baru_walau_dipanggil_langsung(): void
    {
        $nota = $this->nota();

        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->set('lampiranBaru', [UploadedFile::fake()->image('nekat.jpg')])
            ->call('pasangLampiran');

        $this->assertSame(0, $nota->fresh()->lampiran()->count());
    }

    /**
     * Membuang lampiran hasil salinan kolom lama ikut MENGOSONGKAN kolom itu.
     *
     * Tanpa ini kolomnya menunjuk berkas yang sudah dihapus, dan layar mana pun yang masih
     * membacanya akan mengaku notanya masih berfoto padahal tidak — kebohongan yang baru
     * ketahuan saat pemilik membukanya untuk berdebat dengan grosir.
     */
    public function test_membuang_lampiran_salinan_ikut_mengosongkan_kolom_lama(): void
    {
        $nota = $this->nota();

        app(SimpanBuktiBelanjaAction::class)->execute($nota, UploadedFile::fake()->image('struk.jpg'));

        $segar = $nota->fresh();
        $this->assertNotNull($segar->bukti_path, 'pramis: kolom lamanya memang terisi');

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->call('hapusLampiran', $segar->lampiran()->sole()->getKey());

        $akhir = $nota->fresh();

        $this->assertSame(0, $akhir->lampiran()->count());
        $this->assertNull($akhir->bukti_path,
            'kolom lama yang menunjuk berkas terhapus membuat notanya MENGAKU masih berfoto');
    }

    public function test_layar_tidak_bisa_membuang_lampiran_nota_lain(): void
    {
        [$notaA, $lampiranA] = $this->notaBerlampiran('punya-a.jpg');
        $notaB = $this->nota();

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $notaB->getKey())
            ->call('hapusLampiran', $lampiranA->getKey());

        $this->assertSame(1, $notaA->fresh()->lampiran()->count(),
            'lampiran nota lain tidak boleh terbuang lewat rincian nota ini');
    }

    /* ── Rute berpenjaga ─────────────────────────────────────────────────── */

    private function notaBerlampiran(string $nama = 'struk.jpg'): array
    {
        $nota = $this->nota();

        app(SimpanLampiranAction::class)->execute($nota, [
            UploadedFile::fake()->image($nama),
        ], $this->owner->getKey());

        return [$nota->fresh(), $nota->lampiran()->sole()];
    }

    public function test_pemilik_bisa_membuka_lampirannya_dan_jenisnya_dari_kolom_mime(): void
    {
        [$nota, $l] = $this->notaBerlampiran();

        $balasan = $this->actingAs($this->owner)->get($nota->urlLampiran($l));

        $balasan->assertOk();
        $balasan->assertHeader('Content-Type', $l->mime);
        $balasan->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', (string) $balasan->headers->get('Content-Disposition'));
    }

    /**
     * Id lampiran milik NOTA LAIN → 404, walaupun tenant dan outletnya sama.
     *
     * Ini gerbang yang paling mudah terlewat: gerbang outlet di atasnya sudah lolos, jadi
     * tanpa pencarian LEWAT RELASI notanya, satu id yang bocor membuka lampiran nota mana
     * pun di tenant itu — termasuk harga beli dari pemasok yang berbeda.
     */
    public function test_id_lampiran_nota_lain_balas_404_walau_satu_tenant(): void
    {
        [$notaA, $lampiranA] = $this->notaBerlampiran('punya-a.jpg');
        $notaB = $this->nota();

        $this->actingAs($this->owner)
            ->get(route('owner.lampiran.lihat', ['nota' => $notaB->getKey(), 'penanda' => $lampiranA->getKey()]))
            ->assertNotFound();
    }

    public function test_tamu_tidak_bisa_membuka_lampiran(): void
    {
        [$nota, $l] = $this->notaBerlampiran();

        $balasan = $this->get($nota->urlLampiran($l));

        $balasan->assertRedirect();
        $this->assertStringNotContainsString('JFIF', $balasan->getContent() ?: '',
            'isi berkasnya tidak boleh ikut terkirim ke yang belum masuk');
    }

    /** PDF dikirim sebagai UNDUHAN, bukan dibuka sebaris. */
    public function test_pdf_dikirim_sebagai_unduhan_dengan_nama_aslinya(): void
    {
        $nota = $this->nota();

        app(SimpanLampiranAction::class)->execute($nota, [
            $this->pdfAsli('invoice-grosir-agustus.pdf', 8),
        ], $this->owner->getKey());

        $l = $nota->lampiran()->sole();
        $balasan = $this->actingAs($this->owner)->get($nota->urlLampiran($l));

        $balasan->assertOk();
        $disposisi = (string) $balasan->headers->get('Content-Disposition');

        $this->assertStringContainsString('attachment', $disposisi,
            'penampil PDF sebaris menuntut Range yang tidak kita layani, dan attachment+nosniff '
            .'menutup peluang berkas lolos di-sniff sebagai HTML di origin kita sendiri');
        $this->assertStringContainsString('invoice-grosir-agustus.pdf', $disposisi,
            'nama aslinya jauh lebih berguna di folder Unduhan daripada nomor nota + uuid');
    }

    public function test_lampiran_yang_berkasnya_hilang_balas_404_bukan_500(): void
    {
        [$nota, $l] = $this->notaBerlampiran();

        Storage::disk('lampiran')->delete($l->path);

        $this->actingAs($this->owner)->get($nota->urlLampiran($l))->assertNotFound();
    }

    /* ── Bentuk yang dibaca orang ────────────────────────────────────────── */

    public function test_nama_panjang_dipotong_di_tengah_bukan_di_ujung(): void
    {
        $l = new Lampiran(['nama_asli' => 'invoice-grosir-agustus-2026-cabang-utama.pdf']);

        $tampil = $l->namaTampil(24);

        $this->assertStringContainsString('…', $tampil);
        $this->assertStringStartsWith('invoice', $tampil);
        // Bagian yang membedakannya dari invoice bulan lain ada di EKOR namanya.
        $this->assertStringEndsWith('.pdf', $tampil);
        $this->assertLessThanOrEqual(24, mb_strlen($tampil));
    }
}

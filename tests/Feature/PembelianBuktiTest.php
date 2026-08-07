<?php

namespace Tests\Feature;

use App\Actions\Purchase\BatalkanPembelianAction;
use App\Actions\Purchase\SimpanBuktiBelanjaAction;
use App\Enums\DocumentStatus;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian;
use App\Livewire\Pages\Owner\PembelianBaru;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Foto bukti belanja (kwitansi/struk) pada nota pembelian.
 *
 * Fiturnya kecil, tapi tiga keputusannya menentukan dan masing-masing bisa merugikan kalau
 * dibalik oleh orang yang "merapikan" kode ini nanti:
 *
 * 1. **OPSIONAL selamanya, dan kegagalannya TIDAK PERNAH menggagalkan nota.** Nota belanja
 *    adalah catatan uang keluar; fotonya penguat. Kalau `bukti` masuk ke aturan yang bisa
 *    melempar ValidationException dari simpan(), satu foto 6 MB dari kamera ponsel membuang
 *    nota 12 baris yang sudah diketik di depan grosir — dan orang yang kehilangan isian
 *    sekali akan berhenti mencatat. Itu uji INTI di berkas ini
 *    (test_bukti_yang_gagal_diunggah_tidak_menggagalkan_penyimpanan_nota); mutasi sudah
 *    dijalankan untuk membuktikan ia benar-benar merah kalau aturannya dipindah ke sana.
 *
 * 2. **Foldernya `bukti-belanja/{tenant_id}/`, BUKAN `produk/`.** Aturan keras nomor 1
 *    CLAUDE.md melindungi `produk/` (gambar pengguna pernah hilang permanen), dan mencampur
 *    berkas non-produk ke situ membuat pembersih "gambar produk yatim" kelak menghapus bukti
 *    belanja — berkas yang tidak punya pengganti. `tenant_id` ada di path karena bukti memuat
 *    nama pemasok dan harga beli, bukan sekadar foto sabun.
 *
 * 3. **Nota dibatalkan = buktinya TERKUNCI, dan berkasnya tidak pernah disentuh.** Nota batal
 *    biasanya berarti barangnya dikembalikan ke grosir, dan struk itu justru satu-satunya
 *    bukti pengembaliannya (aturan keras nomor 6: jangan menghapus data permanen).
 *
 * `Storage::fake` dipakai untuk KEDUA disk: 'lampiran' (tujuan akhir) dan disk bawaan
 * 'local' (tempat Livewire menaruh unggahan sementara). Tanpa yang kedua, uji ini menulis
 * berkas sungguhan ke `storage/app/private/livewire-tmp` setiap kali dijalankan.
 *
 * Disk tujuannya 'lampiran' — DULU 'public'. Disk lampiran tidak punya `url` dan tidak
 * disajikan web server, jadi bukti belanja tidak bisa lagi dibuka tanpa login: satu-satunya
 * jalannya rute berpenjaga `owner.lampiran.lihat`. Nama disknya sengaja diketik apa adanya di
 * uji ini, bukan lewat SimpanBuktiBelanjaAction::DISK — kalau disknya diganti diam-diam
 * kembali ke 'public', uji ini yang harus MERAH, bukan ikut berpindah tanpa suara.
 */
class PembelianBuktiTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Bukti Belanja');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Bukti');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Bukti',
            'email' => 'owner@buktibelanja.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        Storage::fake('lampiran');
        // Disk bawaan: ke sinilah Livewire menaruh berkas unggahan sementara.
        Storage::fake(config('filesystems.default'));
    }

    /* ── Opsional ────────────────────────────────────────────────────────── */

    /**
     * Nota tanpa foto adalah keadaan yang PALING SERING, bukan keadaan tepi.
     *
     * Warteg yang belanja di pasar pagi tidak dapat struk sama sekali. Mewajibkan fotonya
     * berarti separuh pengguna berhenti mencatat belanja — dan nota yang tidak dicatat
     * merugikan jauh lebih banyak daripada nota tanpa foto.
     */
    public function test_nota_bisa_disimpan_tanpa_bukti(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 10)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('simpan')
            ->assertHasNoErrors();

        $nota = PurchaseOrder::query()->sole();

        $this->assertNull($nota->bukti_path, 'nota tanpa foto tetap tersimpan, kolomnya kosong');
        $this->assertFalse($nota->punyaBukti());
        $this->assertNull($nota->urlBukti());

        // Dan tidak ada satu pun berkas yang lahir dari nota tanpa foto.
        $this->assertSame([], Storage::disk('lampiran')->allFiles(SimpanBuktiBelanjaAction::FOLDER));
    }

    /* ── Folder ──────────────────────────────────────────────────────────── */

    /**
     * Foldernya `bukti-belanja/{tenant_id}/` — dan JANGAN `produk/`.
     *
     * Dua kerugian yang dicegah di sini, keduanya sudah pernah nyata di repo ini atau
     * dilarang tegas olehnya: (a) `storage/app/public/produk/` dilindungi aturan keras
     * nomor 1 karena gambar pengguna pernah hilang permanen dari situ, dan (b) berkas
     * non-produk yang menumpang di folder itu akan ikut terhapus oleh pembersih "gambar
     * produk yatim" — struk belanja tidak punya salinan kedua.
     *
     * `tenant_id` di path bukan hiasan: bukti belanja memuat nama pemasok dan harga beli.
     */
    public function test_bukti_tersimpan_di_folder_bukti_belanja_per_tenant_bukan_di_folder_produk(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 10)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->set('bukti', UploadedFile::fake()->image('struk-grosir.jpg', 800, 1200))
            ->call('simpan')
            ->assertHasNoErrors();

        $nota = PurchaseOrder::query()->sole();
        $path = (string) $nota->bukti_path;

        $this->assertNotSame('', $path, 'fotonya harus terpasang');
        $this->assertStringStartsWith('bukti-belanja/'.$this->tenant->getKey().'/', $path,
            'bukti belanja wajib di folder sendiri per tenant, bukan di folder bersama');
        $this->assertStringNotContainsString('produk/', $path,
            'JANGAN menulis ke produk/: dilindungi aturan keras nomor 1, dan pembersih '
            .'"gambar produk yatim" akan menghapus bukti belanja yang menumpang di situ');

        Storage::disk('lampiran')->assertExists($path);
        $this->assertTrue($nota->punyaBukti());

        // Tidak ada satu pun berkas yang mendarat di folder produk.
        $this->assertSame([], Storage::disk('lampiran')->allFiles('produk'),
            'folder produk/ tidak boleh ikut terisi oleh bukti belanja');
        Storage::disk('lampiran')->assertDirectoryEmpty('produk');
    }

    /* ── Batas & jenis berkas ────────────────────────────────────────────── */

    /**
     * Pesan galatnya BERBAHASA INDONESIA dan menyebut batasnya.
     *
     * Pesan bawaan Laravel di repo ini berbahasa Inggris (APP_LOCALE=en, tanpa lang/id),
     * jadi tanpa blok pesan di aksinya pemilik warung membaca "The foto bukti must not be
     * greater than 4096 kilobytes." di layarnya sendiri — kalimat yang tidak memberi tahu
     * apa yang harus ia lakukan.
     *
     * 9000 KB sengaja: di bawah batas unggahan sementara Livewire (12 MB) supaya yang
     * menolaknya benar-benar aturan KITA, bukan penjaga di lapisan lain.
     */
    public function test_bukti_melebihi_batas_ditolak_dengan_pesan_berbahasa_indonesia(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 10)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->set('bukti', UploadedFile::fake()->image('struk-raksasa.jpg')->size(9000));

        $komponen->assertHasErrors(['bukti' => 'max']);

        $pesan = (string) $komponen->instance()->getErrorBag()->first('bukti');

        $this->assertStringContainsString('kelewat besar', $pesan,
            'pesannya memakai kata orang warung, bukan istilah validator');
        $this->assertStringContainsString(SimpanBuktiBelanjaAction::labelBatas(), $pesan,
            'batasnya disebut dengan angka yang benar-benar dipakai validatornya ('
            .SimpanBuktiBelanjaAction::labelBatas().'), supaya keterangan di layar tidak pernah '
            .'berbeda dari kenyataannya');
        $this->assertStringNotContainsString('must not be greater', $pesan,
            'pesan bawaan berbahasa Inggris tidak boleh sampai ke layar pemilik warung');
    }

    /**
     * PDF, dokumen, video: bukan foto.
     *
     * Bukan soal selera format — layar menampilkannya sebagai <img>, jadi berkas non-gambar
     * hanya menghasilkan ikon rusak yang tidak menjelaskan apa pun.
     */
    public function test_berkas_bukan_gambar_ditolak(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 10)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->set('bukti', UploadedFile::fake()->create('nota-grosir.pdf', 200, 'application/pdf'));

        $komponen->assertHasErrors('bukti');

        // Dan tetap: notanya boleh tersimpan, hanya fotonya yang tidak.
        $komponen->call('simpan');

        $nota = PurchaseOrder::query()->sole();

        $this->assertNull($nota->bukti_path, 'berkas bukan gambar tidak boleh masuk ke kolomnya');
        $this->assertSame([], Storage::disk('lampiran')->allFiles(SimpanBuktiBelanjaAction::FOLDER),
            'berkas yang ditolak tidak boleh tertulis ke disk sama sekali');
    }

    /* ── UJI INTI ────────────────────────────────────────────────────────── */

    /**
     * UJI INTI BERKAS INI. Foto yang gagal diunggah TIDAK BOLEH membuang notanya.
     *
     * Kalau `bukti` dimasukkan ke aturan validasi di PembelianBaru::periksa() — hal yang
     * tampak "lebih rapi" dan pasti akan diusulkan seseorang — maka satu foto kelewat besar
     * melempar ValidationException SEBELUM CatatPembelianAction dipanggil, dan seluruh nota
     * (12 baris yang diketik berdiri di depan grosir, dengan harga per barang) hilang tanpa
     * jejak. Yang menanggungnya adalah orang yang paling rajin mencatat.
     *
     * Mutasi yang sudah dijalankan untuk membuktikan uji ini bergigi: menambahkan
     * `'bukti' => ['nullable','image','max:...']` ke validator di periksa() membuat uji ini
     * MERAH pada baris "notanya wajib tetap tersimpan" — bukan pada galat lain yang lebih
     * dulu menahan.
     *
     * Yang diperiksa ada tiga, dan ketiganya perlu:
     *  - notanya ADA, lengkap dengan uangnya dan stoknya;
     *  - kolom buktinya kosong, bukan berisi path yang berkasnya tidak ada;
     *  - toastnya BERTERUS TERANG dan menyebutkan apa yang bisa dilakukan sekarang. "Nota
     *    tersimpan" saja adalah kebohongan kecil yang mahal: pemiliknya baru menyadarinya
     *    berbulan-bulan kemudian, saat ia membuka nota untuk mencari struk yang tidak pernah
     *    ada di sana.
     */
    public function test_bukti_yang_gagal_diunggah_tidak_menggagalkan_penyimpanan_nota(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 4);

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 10)
            ->set('harga.'.$kopi->getKey(), 1500)
            // Berkas yang PASTI ditolak aturan kita, dan sengaja TIDAK dinolkan lebih dulu:
            // kalau propertinya dikosongkan saat ditolak, uji ini hijau karena alasan yang
            // salah (tidak ada berkas sama sekali saat simpan) dan penjagaan sesungguhnya
            // berhenti teruji.
            ->set('bukti', UploadedFile::fake()->image('struk-raksasa.jpg')->size(9000));

        $komponen->call('simpan');

        $nota = PurchaseOrder::query()->first();

        $this->assertNotNull($nota,
            'notanya wajib tetap tersimpan walau fotonya gagal diunggah: nota belanja adalah '
            .'catatan uang keluar, fotonya cuma penguat');
        $this->assertNull($nota->bukti_path,
            'kolomnya tetap kosong — path yang berkasnya tidak ada hanya menghasilkan ikon rusak');
        $this->assertEqualsWithDelta(15000.0, (float) $nota->total, 0.01,
            'uang di notanya tidak boleh terpengaruh sama sekali oleh urusan foto');
        $this->assertEqualsWithDelta(14.0, $this->saldo($this->outlet, $kopi), 0.001,
            'stoknya tetap masuk: 4 + 10');

        $komponen->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], $nota->nomor_po)
            && str_contains($data['pesan'], 'tersimpan')
            && str_contains($data['pesan'], 'belum terpasang'));

        // Dan berkasnya tidak meninggalkan sampah di disk.
        $this->assertSame([], Storage::disk('lampiran')->allFiles(SimpanBuktiBelanjaAction::FOLDER));
    }

    /**
     * Kegagalan MENULIS ke disk (bukan validasi) juga tidak boleh menyentuh notanya.
     *
     * Disk penuh, izin folder salah, kartu memori dilepas: semuanya berakhir di jalur yang
     * sama, dan yang menjaganya adalah try/catch di aksinya plus pemeriksaan nilai kembalian
     * put(). Disimulasikan dengan menaruh BERKAS di tempat foldernya seharusnya, sehingga
     * penulisan di dalamnya tidak mungkin berhasil.
     *
     * Kenapa penting dipisah dari uji di atas: kalau aksinya memakai $berkas->store(), ia
     * mengembalikan path yang disusunnya sendiri tanpa memeriksa apakah penulisannya berhasil
     * — kolom database berisi path yang tampak sah sementara berkasnya tidak ada di mana pun.
     */
    public function test_kegagalan_menulis_ke_disk_tidak_mengubah_nota_maupun_kolom_buktinya(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 10, 1500)],
        ]);

        // Berkas, bukan folder — jadi 'bukti-belanja/{tenant}/apa-pun.jpg' tidak bisa ditulis.
        Storage::disk('lampiran')->put(
            SimpanBuktiBelanjaAction::FOLDER.'/'.$this->tenant->getKey(),
            'ini berkas, bukan folder',
        );

        $berhasil = app(SimpanBuktiBelanjaAction::class)
            ->execute($nota, UploadedFile::fake()->image('struk.jpg'));

        $this->assertFalse($berhasil,
            'aksinya harus MENGATAKAN gagal lewat nilai kembalian, bukan melempar — pemanggilnya '
            .'sudah berada sesudah notanya tersimpan');
        $this->assertNull($nota->fresh()->bukti_path,
            'kolom bukti hanya diisi kalau berkasnya benar-benar tertulis');
        $this->assertSame(DocumentStatus::Diterima, $nota->fresh()->status,
            'notanya tidak berubah apa pun karena urusan berkas');
    }

    /* ── Ganti & hapus ───────────────────────────────────────────────────── */

    /**
     * Berkas lama dibuang saat fotonya diganti — tapi SESUDAH yang baru tersimpan.
     *
     * Pola yang sama dengan Produk::buangBerkas(). Dibuang lebih dulu berarti unggahan yang
     * gagal meninggalkan nota tanpa foto sama sekali: kehilangan yang tidak diminta siapa pun.
     */
    public function test_mengganti_bukti_membuang_berkas_lama(): void
    {
        $nota = $this->notaBerbukti('lama.jpg');
        $lama = (string) $nota->bukti_path;

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->set('bukti', UploadedFile::fake()->image('baru.jpg'))
            ->call('pasangBukti')
            ->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'diganti'));

        $baru = (string) $nota->fresh()->bukti_path;

        $this->assertNotSame($lama, $baru);
        Storage::disk('lampiran')->assertExists($baru);
        Storage::disk('lampiran')->assertMissing($lama);

        // SATU berkas per nota, tegas: berkas lama yang tertinggal berarti disk warung penuh
        // oleh foto yang tidak pernah dibuka lagi.
        $this->assertCount(1, Storage::disk('lampiran')->allFiles(
            SimpanBuktiBelanjaAction::FOLDER.'/'.$this->tenant->getKey(),
        ));
    }

    /* ── Nota dibatalkan: buktinya TERKUNCI ──────────────────────────────── */

    /**
     * Membatalkan nota TIDAK menyentuh berkas buktinya.
     *
     * Nota batal biasanya berarti barangnya dikembalikan ke grosir — dan struk itu justru
     * satu-satunya bukti pengembaliannya. Aturan keras nomor 6: jangan menghapus data
     * permanen. Pembatalan yang "membersihkan" berkasnya membuang satu-satunya kertas yang
     * bisa dipakai pemilik kalau grosirnya menagih ulang.
     */
    public function test_membatalkan_nota_tidak_menghapus_berkas_buktinya(): void
    {
        $nota = $this->notaBerbukti('struk-dikembalikan.jpg');
        $path = (string) $nota->bukti_path;

        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);

        $segar = $nota->fresh();

        $this->assertSame(DocumentStatus::Dibatalkan, $segar->status);
        $this->assertSame($path, $segar->bukti_path,
            'kolom buktinya tidak boleh dikosongkan oleh pembatalan');
        Storage::disk('lampiran')->assertExists($path);
        $this->assertTrue($segar->punyaBukti(), 'buktinya tetap BISA DILIHAT sesudah nota dibatalkan');
        $this->assertNotNull($segar->urlBukti());
    }

    /**
     * Bukti pada nota yang DIBATALKAN tidak bisa diganti maupun dihapus.
     *
     * Diuji dengan MEMANGGIL AKSINYA LANGSUNG, tanpa lewat dialog: dialog konfirmasi dan
     * tombol yang disembunyikan bukan pengaman — muatan Livewire bisa dikirim tanpa pernah
     * melewati layar mana pun (CLAUDE.md, "Dialog BUKAN pengaman").
     */
    public function test_bukti_pada_nota_dibatalkan_tidak_bisa_diganti_maupun_dihapus(): void
    {
        $nota = $this->notaBerbukti('struk-asli.jpg');
        $path = (string) $nota->bukti_path;

        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);
        $nota = $nota->fresh();

        $aksi = app(SimpanBuktiBelanjaAction::class);

        $this->assertFalse($aksi->bolehDiubah($nota));
        $this->assertTrue($nota->buktiTerkunci());

        $this->assertFalse($aksi->execute($nota, UploadedFile::fake()->image('struk-pengganti.jpg')),
            'nota dibatalkan: fotonya tidak boleh diganti');
        $this->assertFalse($aksi->hapus($nota),
            'nota dibatalkan: fotonya tidak boleh dihapus');

        $this->assertSame($path, $nota->fresh()->bukti_path);
        Storage::disk('lampiran')->assertExists($path);

        // Dan berkas PENGGANTINYA tidak boleh ikut tertulis: satu berkas, yang asli.
        $this->assertCount(1, Storage::disk('lampiran')->allFiles(
            SimpanBuktiBelanjaAction::FOLDER.'/'.$this->tenant->getKey(),
        ));

        // Lewat layar pun sama, dan pesannya mengatakan KENAPA — bukan "gagal" begitu saja.
        $komponen = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->set('bukti', UploadedFile::fake()->image('struk-pengganti-2.jpg'))
            ->call('pasangBukti');

        $komponen->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'dibatalkan') && str_contains($data['pesan'], 'dikunci'));

        $komponen->call('hapusBukti')
            ->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'dikunci'));

        $this->assertSame($path, $nota->fresh()->bukti_path);
        Storage::disk('lampiran')->assertExists($path);
    }

    /* ── Dipasang belakangan, pada status APA PUN ────────────────────────── */

    /**
     * Nota yang barangnya BELUM DATANG tetap bisa dipasangi foto.
     *
     * Inilah keadaan yang paling sering di lapangan, dan pemilik proyek menegaskannya dua
     * kali: pemilik membayar di depan grosir, struknya dipegang, barangnya menyusul besok.
     * Membatasi pemasangan foto ke nota yang sudah diterima mematikan justru nilai utama
     * fiturnya — catat cepat sekarang, foto belakangan.
     *
     * Dan memasang foto TIDAK BOLEH menggerakkan stok: satu-satunya jalur stok dari
     * pembelian ada di TerimaPembelianAction.
     */
    public function test_bukti_bisa_dipasang_pada_nota_yang_belum_datang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 7);

        $nota = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 10, 1500)],
        ]);

        $this->assertSame(DocumentStatus::Dikirim, $nota->status);

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->set('bukti', UploadedFile::fake()->image('struk-belum-datang.jpg'))
            ->call('pasangBukti')
            ->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'tersimpan'));

        $segar = $nota->fresh();

        $this->assertNotNull($segar->bukti_path, 'nota belum datang WAJIB bisa dipasangi foto');
        Storage::disk('lampiran')->assertExists($segar->bukti_path);
        $this->assertSame(DocumentStatus::Dikirim, $segar->status,
            'memasang foto tidak boleh mengubah status notanya');
        $this->assertEqualsWithDelta(7.0, $this->saldo($this->outlet, $kopi), 0.001,
            'memasang foto tidak boleh menggerakkan stok satu pun');
    }

    /** Dan nota yang SUDAH datang pun tetap bisa dipasangi foto belakangan. */
    public function test_bukti_bisa_dipasang_belakangan_pada_nota_yang_sudah_datang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 10, 1500)],
        ]);

        $this->assertSame(DocumentStatus::Diterima, $nota->status);
        $this->assertNull($nota->bukti_path, 'notanya memang lahir tanpa foto');

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->set('bukti', UploadedFile::fake()->image('struk-menyusul.jpg'))
            ->call('pasangBukti')
            ->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'tersimpan'));

        $segar = $nota->fresh();

        $this->assertNotNull($segar->bukti_path);
        Storage::disk('lampiran')->assertExists($segar->bukti_path);
        $this->assertSame(DocumentStatus::Diterima, $segar->status);
    }

    /* ── Isolasi tenant ──────────────────────────────────────────────────── */

    /**
     * Bukti nota merchant lain tidak bisa dibuka, diganti, maupun dihapus.
     *
     * Bukti belanja memuat nama pemasok dan harga beli — dua hal yang paling tidak boleh
     * bocor ke warung sebelah. `rincianId` datang dari klien dan boleh datang dari klien:
     * yang menentukan bukan nilainya, melainkan kueri yang membatasi apa yang bisa ditemukan
     * dengan nilai itu. Diuji dengan MENYETEL properti langsung, tanpa melewati satu pun
     * tombol di layar.
     */
    public function test_bukti_nota_merchant_lain_tidak_bisa_dibuka_maupun_diganti(): void
    {
        $lain = $this->buatTenant('Warung Sebelah');
        $outletLain = $this->buatOutlet($lain, 'Cabang Sebelah');
        $ownerLain = $this->buatUser($lain, UserRole::Owner, [
            'name' => 'Pemilik Sebelah',
            'email' => 'owner@sebelah.test',
            'password' => 'rahasia123',
        ]);

        $notaLain = $this->konteks()->forTenant($lain->getKey(), function () use ($outletLain, $ownerLain) {
            $barang = $this->buatProduk('Gula Sebelah');

            return $this->catatNota($outletLain, $ownerLain, [
                'beli_dari' => 'Grosir Rahasia',
                'baris' => [$this->baris($barang, 5, 14000)],
            ]);
        });

        $pathLain = SimpanBuktiBelanjaAction::FOLDER.'/'.$lain->getKey().'/struk-sebelah.jpg';
        Storage::disk('lampiran')->put($pathLain, 'struk milik warung sebelah');
        $notaLain->bukti_path = $pathLain;
        $notaLain->save();

        $komponen = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            // Panel rincian nota merchant lain: nomornya tidak pernah muncul di daftar ini,
            // tapi propertinya tetap bisa disetel dari klien.
            ->set('rincianId', $notaLain->getKey())
            ->set('bukti', UploadedFile::fake()->image('struk-sisipan.jpg'))
            ->call('pasangBukti');

        $komponen->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'tidak ditemukan'));

        $komponen->call('hapusBukti')
            ->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'tidak ditemukan'));

        $segar = PurchaseOrder::withoutGlobalScopes()->findOrFail($notaLain->getKey());

        $this->assertSame($pathLain, $segar->bukti_path,
            'bukti merchant lain tidak boleh tergantikan maupun terhapus');
        Storage::disk('lampiran')->assertExists($pathLain);

        // Berkas sisipan tidak boleh mendarat di mana pun: tidak di folder tenant sendiri,
        // dan terutama tidak di folder tenant lain.
        $this->assertSame([], Storage::disk('lampiran')->allFiles(
            SimpanBuktiBelanjaAction::FOLDER.'/'.$this->tenant->getKey(),
        ));
        $this->assertCount(1, Storage::disk('lampiran')->allFiles(
            SimpanBuktiBelanjaAction::FOLDER.'/'.$lain->getKey(),
        ));

        // Nomor nota merchant lain juga tidak boleh terbaca di daftar layar ini.
        $this->assertStringNotContainsString($notaLain->nomor_po,
            Livewire::actingAs($this->owner)->test(Pembelian::class)->html());

        /*
         * Dan bagian "tidak bisa DIBUKA" pada nama uji ini — yang sampai gelombang lampiran
         * ini BELUM PERNAH benar-benar diuji, karena memang tidak mungkin: selama berkasnya
         * disajikan langsung oleh web server, tidak ada satu pun kode kita yang dilewati saat
         * seseorang membuka URL-nya. Yang menahannya cuma nama UUID yang tidak bisa ditebak.
         *
         * Sekarang ada penjaganya, jadi diuji: owner tenant sendiri (tenant "Toko Bukti
         * Belanja") membuka URL lampiran nota WARUNG SEBELAH.
         *
         * 404, bukan 403. 403 mengatakan "berkasnya ada, tapi bukan milikmu", dan konfirmasi
         * itu sendiri sudah kebocoran — jumlah nota warung sebelah bisa dihitung hanya dari
         * beda 403 dan 404. Nomor notanya juga tidak boleh muncul di badan balasan.
         */
        $urlLain = route('owner.lampiran.lihat', [
            'nota' => $notaLain->getKey(),
            'penanda' => PurchaseOrder::PENANDA_BUKTI,
        ]);

        $balasan = $this->actingAs($this->owner)->get($urlLain);

        $balasan->assertNotFound();
        $this->assertStringNotContainsString($notaLain->nomor_po, $balasan->getContent(),
            'nomor nota merchant lain tidak boleh ikut terbaca lewat halaman galatnya');
        $this->assertStringNotContainsString('struk milik warung sebelah', $balasan->getContent(),
            'dan isi berkasnya tentu tidak boleh ikut terkirim');
    }

    /* ── Penjaga sumber kode: rute berpenjaga, bukan penyajian statis ────── */

    /**
     * URL buktinya mengikuti HOST PERMINTAAN, dan tidak pernah lewat /storage/ lagi.
     *
     * Bagian PERILAKUNYA dipertahankan apa adanya dari uji sebelumnya, karena cacat yang
     * melahirkannya masih berlaku sepenuhnya: Storage::url() selalu menyusun alamat dari
     * APP_URL, jadi tablet yang membuka aplikasi lewat alamat LAN (192.168.x.x:8000) mencari
     * gambarnya di host yang tidak melayani apa pun — tanpa satu pun pesan galat, jadi
     * gejalanya cuma "fotonya tidak muncul" dan tidak ada yang tahu kenapa. route() menjawab
     * itu sama baiknya dengan asset(): keduanya memakai host permintaan.
     *
     * Yang DITAMBAHKAN: URL-nya tidak boleh memuat `/storage/` sama sekali. Itulah yang
     * menutup jalan pulang ke penyajian statis secara diam-diam — berkas di bawah
     * `public/storage` dibuka siapa pun yang punya URL-nya, tanpa login, tanpa tenant, dan
     * satu tautan yang tersalin ke WhatsApp membocorkan harga beli beserta nama pemasok
     * SELAMANYA.
     *
     * Dijaga dua lapis, dan keduanya perlu:
     *  - perilakunya: URL-nya mengikuti host permintaan dan menunjuk rute berpenjaga;
     *  - SUMBERNYA (pola PenjagaPerHalamanTest): berkas apa pun yang menyentuh bukti_path
     *    tidak boleh memakai Storage::url()/->url() — larangan LAMA, tidak dilonggarkan —
     *    dan sekarang juga tidak boleh menyentuh disk 'public' untuk berkas bukti. Itu
     *    satu-satunya cara menahan layar yang BELUM ADA.
     */
    public function test_url_lampiran_mengikuti_host_permintaan_bukan_app_url(): void
    {
        $nota = $this->notaBerbukti('struk-lan.jpg');

        // Tablet membuka aplikasi lewat alamat LAN, sementara APP_URL tetap localhost.
        config(['app.url' => 'http://localhost']);
        URL::forceRootUrl('http://192.168.1.7:8000');

        $url = (string) $nota->fresh()->urlBukti();

        $this->assertStringStartsWith('http://192.168.1.7:8000/', $url,
            'URL bukti harus mengikuti host permintaan; Storage::url() tidak pernah bisa '
            .'menghasilkan alamat LAN ini karena ia selalu memakai APP_URL');
        $this->assertStringNotContainsString('localhost', $url);
        $this->assertStringNotContainsString('/storage/', $url,
            'buktinya TIDAK BOLEH lagi disajikan dari folder publik: berkas di bawah '
            .'public/storage terbuka untuk siapa pun yang punya URL-nya, dan tautan yang '
            .'sudah tersalin tidak bisa dicabut');
        $this->assertSame(
            route('owner.lampiran.lihat', [
                'nota' => $nota->getKey(),
                'penanda' => PurchaseOrder::PENANDA_BUKTI,
            ]),
            $url,
            'satu-satunya penyusun URL bukti adalah rute berpenjaga owner.lampiran.lihat',
        );

        URL::forceRootUrl(null);

        // Lapis kedua: sumbernya.
        $pelanggarUrl = [];
        $pelanggarDisk = [];

        foreach ($this->berkasSumber() as $berkas) {
            $isi = (string) file_get_contents($berkas);

            if (! str_contains($isi, 'bukti_path') && ! str_contains($isi, 'urlBukti')) {
                continue;
            }

            // Komentar dibuang DULU, karena penjaga ini pernah menuduh peringatannya sendiri:
            // komentar yang berbunyi "jangan pakai Storage::url()" ikut tertangkap. Kalau
            // dibiarkan, satu-satunya cara menghijaukannya adalah menulis komentar yang tidak
            // menyebut hal yang dilarangnya — dan peringatan yang tidak boleh menyebut namanya
            // sendiri berhenti mengajari siapa pun.
            $kode = $this->tanpaKomentar($berkas, $isi);
            $nama = str_replace(base_path().'/', '', $berkas);

            if (preg_match('/Storage::url\(|Storage::disk\([^)]*\)->url\(/', $kode) === 1) {
                $pelanggarUrl[] = $nama;
            }

            /*
             * Perintah pemindahan disk adalah SATU-SATUNYA berkas yang memang harus membaca
             * disk 'public': tugasnya justru mengosongkannya. Dikecualikan dengan menyebut
             * namanya, bukan dengan melonggarkan polanya — supaya berkas kedua yang menyentuh
             * disk publik untuk bukti tetap tertangkap.
             */
            if ($nama === 'app/Console/Commands/PindahkanLampiranDisk.php') {
                continue;
            }

            if (str_contains($kode, "Storage::disk('public')")) {
                $pelanggarDisk[] = $nama;
            }
        }

        $this->assertSame([], $pelanggarUrl,
            'Jangan Storage::url(): ia selalu memakai APP_URL, jadi tablet di alamat LAN '
            .'kehilangan gambarnya. '.implode(', ', $pelanggarUrl));

        $this->assertSame([], $pelanggarDisk,
            "Bukti belanja TIDAK boleh menyentuh disk 'public' — berkas di situ disajikan "
            .'web server tanpa pemeriksaan apa pun. Pakai disk lampiran: '
            .implode(', ', $pelanggarDisk));

        // Dan modelnya sendiri memang memakai rutenya, bukan asset('storage/…').
        $sumberModel = (string) file_get_contents(app_path('Models/PurchaseOrder.php'));

        $this->assertStringContainsString("route('owner.lampiran.lihat'", $sumberModel,
            'PurchaseOrder::urlBukti() adalah satu-satunya penyusun URL bukti; kalau ia berhenti '
            .'memakai rute berpenjaga, semua layar ikut bocor sekaligus');
        $this->assertStringNotContainsString("asset('storage/'.\$this->bukti_path)", $sumberModel,
            'bentuk lama (penyajian statis) tidak boleh kembali');
    }

    /* ── Bantuan ─────────────────────────────────────────────────────────── */

    /** Nota yang sudah punya foto bukti, lewat jalur aksinya (bukan disusun tangan). */
    private function notaBerbukti(string $namaBerkas): PurchaseOrder
    {
        $kopi = $this->buatProduk('Kopi '.$namaBerkas);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 10, 1500)],
        ]);

        $this->assertTrue(
            app(SimpanBuktiBelanjaAction::class)->execute($nota, UploadedFile::fake()->image($namaBerkas)),
            'penyiapan uji: fotonya harus benar-benar terpasang',
        );

        return $nota->fresh();
    }

    /**
     * Berkas sumber yang mungkin menyusun URL berkas: PHP aplikasi + Blade.
     *
     * Blade ikut disisir karena di situlah URL gambar biasanya diketik ulang, dan uji ini
     * tidak menyentuh berkasnya — hanya membacanya.
     *
     * @return array<int, string>
     */
    /**
     * Isi berkas tanpa komentar, supaya penjaga sumber membaca KODE dan bukan prosa.
     *
     * Blade: `{{-- … --}}` dibuang dengan regex, karena berkas Blade bukan PHP yang sah
     * sehingga token_get_all tidak bisa dipakai atasnya.
     *
     * PHP: lewat token_get_all, bukan regex. Regex `//.*` akan memotong separuh alamat
     * `https://…` di tengah string dan bisa membuang kode yang sebenarnya ada.
     */
    private function tanpaKomentar(string $berkas, string $isi): string
    {
        if (str_ends_with($berkas, '.blade.php')) {
            return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $isi);
        }

        $bersih = '';

        foreach (token_get_all($isi) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $bersih .= is_array($token) ? $token[1] : $token;
        }

        return $bersih;
    }

    private function berkasSumber(): array
    {
        $hasil = [];

        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $berkas) {
                if ($berkas->isFile() && $berkas->getExtension() === 'php') {
                    $hasil[] = $berkas->getPathname();
                }
            }
        }

        return $hasil;
    }
}

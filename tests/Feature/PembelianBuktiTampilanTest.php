<?php

namespace Tests\Feature;

use App\Actions\Purchase\BatalkanPembelianAction;
use App\Actions\Purchase\SimpanBuktiBelanjaAction;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\Pembelian;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
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
 * Sisi TAMPILAN foto bukti belanja: kotak unggah di formulir nota, dan blok kwitansi di
 * panel rincian nota.
 *
 * Sisi datanya sudah dijaga PembelianBuktiTest. Yang dijaga DI SINI adalah hal-hal yang
 * hanya bisa salah di Blade, dan tiap butirnya lahir dari aturan yang sudah pernah dibayar
 * mahal di repo ini:
 *
 * 1. **Batas ukuran & jenis berkas tertulis SEBELUM orang memilih**, dan angkanya dari
 *    $batasBukti — bukan "4 MB" yang diketik ulang di Blade lalu ketinggalan saat setelannya
 *    diubah. Keterangan batas yang salah membuat orang mencoba berkali-kali dengan foto yang
 *    memang akan ditolak.
 *
 * 2. **TANPA bintang wajib.** Validatornya `nullable`; bintang pada medan yang tidak wajib
 *    membuat bintang di seluruh formulir berhenti dipercaya, lalu yang sungguh wajib
 *    dilewatkan (CLAUDE.md).
 *
 * 3. **Nota dibatalkan: tombol pasang/hapus TIDAK dirender.** Tombol yang aksinya pasti
 *    menolak membuat orang menekannya berkali-kali lalu menyimpulkan aplikasinya rusak.
 *
 * 4. **Tombol hapus foto merah SEJAK ISTIRAHAT dan berkonfirmasi SweetAlert bersama.** Di
 *    tablet & HP tidak ada hover, jadi merah yang menunggu disorot tidak pernah muncul.
 *
 * `Storage::fake` dipakai untuk KEDUA disk — 'lampiran' (tujuan akhir) dan disk bawaan (tempat
 * Livewire menaruh unggahan sementara). Tanpa yang kedua, uji ini menulis berkas sungguhan ke
 * `storage/app/private/livewire-tmp`, dan cleanupOldUploads() Livewire pernah menghapus
 * berkas uji lain di tengah jalan sehingga ujinya kelap-kelip.
 *
 * Disk tujuannya 'lampiran', dan sempat SALAH: berkas ini masih memalsukan 'public' sesudah
 * aksinya dipindah ke disk lampiran, jadi ujinya menulis SUNGGUHAN ke
 * `storage/app/private/bukti-belanja/` — enam berkas 695 byte tertinggal di sana sebelum
 * ketahuan. Yang membuatnya tidak terasa: uji tetap HIJAU. Memalsukan disk yang salah tidak
 * pernah menggagalkan apa pun, ia cuma memindahkan tulisannya ke cakram sungguhan; dan
 * `local` yang ikut dipalsukan berakar di direktori yang SAMA dengan lampiran, jadi
 * kebocorannya makin tidak kelihatan. Nama disknya sengaja diketik apa adanya di sini, bukan
 * lewat konstanta — kalau disknya berpindah lagi, uji inilah yang harus merah.
 */
class PembelianBuktiTampilanTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Tampilan Bukti');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Tampilan');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Tampilan',
            'email' => 'owner@tampilanbukti.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        Storage::fake('lampiran');
        Storage::fake(config('filesystems.default'));
    }

    /* ── Formulir nota baru ──────────────────────────────────────────────── */

    /**
     * Batas ukuran & jenis berkas terbaca SEBELUM berkasnya dipilih.
     *
     * Pemilik yang baru tahu batasnya dari pesan penolakan sudah kehilangan satu menit
     * menunggu unggahan 8 MB lewat sinyal warung — dan dua kali begitu, ia berhenti memasang
     * foto sama sekali. Angkanya dibandingkan dengan labelBatas() supaya tidak ada dua
     * kebenaran: kalau config('nampan.bukti_maks_kb') diubah, layarnya ikut berubah sendiri.
     */
    public function test_formulir_menyebut_batas_ukuran_dan_jenis_berkas_sebelum_memilih(): void
    {
        $html = Livewire::actingAs($this->owner)->test(PembelianBaru::class)->html();

        $this->assertStringContainsString('Foto kwitansi atau struk', $html);
        $this->assertStringContainsString('Pilih foto struk', $html);
        $this->assertStringContainsString(SimpanBuktiBelanjaAction::labelBatas(), $html,
            'batas ukurannya harus tertulis, dan angkanya dari labelBatas() — bukan diketik di Blade');
        $this->assertStringContainsString('JPG, PNG, atau WEBP', $html);

        // Kotak berkasnya benar-benar terikat ke properti komponennya.
        $this->assertMatchesRegularExpression('/<input[^>]+wire:model="bukti"/', $html);
    }

    /**
     * TIDAK berbintang wajib — validatornya `nullable`, selamanya.
     *
     * Diperiksa dari markupnya, bukan dari kata: <x-wajib /> merender sebuah <span> berisi
     * bintang, dan yang dicari di sini adalah bintang itu di dekat label fotonya.
     */
    public function test_kotak_foto_tidak_pernah_berbintang_wajib(): void
    {
        $html = Livewire::actingAs($this->owner)->test(PembelianBaru::class)->html();

        $posisi = strpos($html, 'Foto kwitansi atau struk');

        $this->assertNotFalse($posisi);

        // Potongan setelah labelnya: kalau <x-wajib /> terpasang, bintangnya ada persis di
        // sini (pola yang sama dengan label "Tanggal nota<x-wajib />" di layar ini).
        $sesudahLabel = substr($html, $posisi, 120);

        $this->assertStringNotContainsString('wajib diisi', $sesudahLabel,
            'foto bukti nullable: bintang wajib di sini membuat bintang di formulir ini berhenti dipercaya');
    }

    /**
     * Foto yang ditolak TIDAK ikut ke ringkasan "N hal harus dibetulkan dulu".
     *
     * Cacat yang dicegah: ringkasan itu berdiri di bawah kalimat "belum ada satu barang pun
     * yang masuk stok", jadi memasukkan galat foto ke sana membuat pemilik menyangka notanya
     * batal tersimpan gara-gara fotonya — padahal foto tidak pernah menahan simpan. Pesannya
     * tetap muncul, tapi menempel di kotak fotonya.
     */
    public function test_galat_foto_tidak_masuk_ringkasan_yang_menahan_simpan(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            // Berkas yang bukan gambar: ditolak updatedBukti(), tapi tidak boleh mengubah
            // status nota apa pun.
            ->set('bukti', UploadedFile::fake()->create('struk.pdf', 20, 'application/pdf'));

        $komponen->assertHasErrors('bukti');

        $html = $komponen->html();

        $this->assertStringNotContainsString('hal harus dibetulkan dulu', $html,
            'galat foto tidak boleh muncul sebagai penahan simpan — ia tidak menahan apa pun');

        // Kalimat pertama pesannya berlaku untuk SEMUA berkas yang bukan foto, termasuk PDF ini.
        // Bagian iPhone/HEIC-nya bersyarat ("kalau ini foto dari iPhone…") dengan sengaja: satu
        // pesan yang berlaku di dua keadaan lebih baik daripada dua pesan yang mirip, dan
        // MessageBag membuang duplikat kalau aturan `image` dan `mimes` gagal bersamaan.
        $this->assertStringContainsString('yang bisa cuma JPG, PNG, atau WEBP', $html,
            'pesannya tetap harus terbaca, menempel di kotak fotonya');

        // Dan jalan keluarnya ada: pilihannya bisa dibuang tanpa menyentuh isian nota.
        $this->assertStringContainsString('Tidak pakai foto ini', $html);

        $komponen->call('buangBuktiPilihan')
            ->assertHasNoErrors()
            ->assertSet('bukti', null);
    }

    /**
     * CACAT NYATA yang dilaporkan pemilik: kamera HP/tablet TIDAK muncul saat memilih foto
     * struk — yang keluar cuma galeri dan berkas.
     *
     * Sebabnya `accept="image/jpeg,image/png,image/webp"`. Banyak peramban Android tidak
     * menawarkan kamera kalau accept tidak memuat `image/*`, karena aplikasi kameranya
     * mendaftarkan diri sebagai penghasil `image/*` dan tersaring keluar oleh daftar jenis yang
     * spesifik; di iOS pilihan "Ambil Foto" ikut hilang. Struk difoto di tempat — kamera yang
     * tidak muncul berarti fiturnya tidak ada, dan pemiliknya menyimpulkan aplikasinya rusak.
     *
     * `capture` DILARANG di sini, dan itu bukan kerapian: `capture="environment"` memang
     * memaksa kamera muncul, tapi ia MENGHAPUS pilihan galeri di banyak peramban Android —
     * struk yang sudah difoto kemarin jadi tidak bisa dipilih lagi, dan orangnya dipaksa
     * memfoto ulang struk yang sudah kusut. Yang dibutuhkan dua-duanya, jadi "perbaikan" yang
     * menambahkan capture adalah pertukaran satu cacat dengan cacat lain.
     *
     * Dibaca dari HTML yang BENAR-BENAR dirender kedua layar, dan diurai DOMDocument — bukan
     * regex atas berkas Blade. Dua alasan: nilai atribut Livewire memuat `>` (mis. panah objek
     * di dalam wire:key), dan penjaga yang membaca sumber tidak tahu apakah kotaknya memang
     * ikut dirender. `assertNotEmpty` + jumlah yang ditegaskan wajib ada: penjaga pemindai di
     * repo ini sudah tiga kali berbohong dengan cara yang sama — pola yang tidak cocok dengan
     * apa pun melaporkan "tidak ada pelanggar" dan lulus hijau tanpa memeriksa apa pun.
     */
    public function test_kotak_unggah_bukti_menawarkan_kamera_tanpa_menghilangkan_galeri(): void
    {
        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($this->buatProduk('Teh Celup'), 4, 6500)],
        ]);

        $halaman = [
            'formulir nota baru' => Livewire::actingAs($this->owner)
                ->test(PembelianBaru::class)->html(),
            'panel rincian nota' => Livewire::actingAs($this->owner)
                ->test(Pembelian::class)->call('bukaRincian', $nota->getKey())->html(),
        ];

        $diperiksa = 0;

        foreach ($halaman as $nama => $html) {
            $kotak = $this->kotakUnggahBukti($html);

            $this->assertNotEmpty($kotak,
                "kotak unggah foto bukti tidak ditemukan di {$nama} — penjaga ini buta, bukan "
                .'layarnya yang bersih');
            $this->assertCount(1, $kotak,
                "{$nama} punya tepat satu kotak unggah bukti; jumlah yang berubah berarti penjaga "
                .'ini hanya memeriksa sebagian');

            foreach ($kotak as $atribut) {
                $this->assertStringContainsString('image/*', $atribut['accept'],
                    "accept di {$nama} wajib memuat image/* — tanpa itu peramban Android tidak "
                    .'menawarkan kamera sama sekali (accept sekarang: "'.$atribut['accept'].'")');

                $this->assertFalse($atribut['ada_capture'],
                    "jangan pasang capture di {$nama}: ia memaksa kamera TAPI menghapus pilihan "
                    .'galeri, sehingga struk yang sudah difoto kemarin tidak bisa dipilih lagi');
            }

            $diperiksa += count($kotak);
        }

        $this->assertSame(2, $diperiksa,
            'kedua layar owner yang mengunggah bukti harus ikut terperiksa: formulir nota baru '
            .'DAN panel rincian nota. Yang ketinggalan akan diperbaiki belakangan sendirian.');
    }

    /**
     * HEIC dari iPhone ditolak — tapi pesannya bisa dikerjakan orangnya.
     *
     * Ini harga langsung dari accept="image/*" di atas: kotak yang menerima `image/*` membuat
     * iPhone bisa mengirim HEIC/HEIF, dan tumpukan ini TIDAK BISA membacanya (GD tanpa libheif;
     * getimagesize() dan imagecreatefromstring() sama-sama gagal atas berkas HEIC sungguhan,
     * dan ekstensi Imagick tidak terpasang). Menerimanya berarti menyimpan bukti yang tidak
     * bisa dipratinjau di formulir dan tidak bisa dibuka di peramban Android maupun dekstop —
     * persis pada satu-satunya saat bukti itu dibutuhkan, yaitu waktu grosirnya menagih ulang.
     *
     * Jadi ditolak, dan yang menanggung penolakan itu pesannya: ia WAJIB menyebut HEIC dan
     * jalan keluarnya di HP-nya sendiri. "Harus JPG, PNG, atau WEBP" tidak bisa dikerjakan oleh
     * orang yang tidak tahu fotonya berformat apa — HEIC bawaan iPhone sejak iOS 11, jadi ia
     * tidak pernah memilihnya dan tidak punya alasan untuk mengenal namanya.
     *
     * Berkasnya dipalsukan lewat mime `image/heic`: yang menolak adalah aturan `image` +
     * `mimes`, dan keduanya membaca jenis berkasnya, bukan namanya.
     */
    public function test_foto_heic_iphone_ditolak_dengan_pesan_yang_menyebut_jalan_keluarnya(): void
    {
        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('bukti', UploadedFile::fake()->create('IMG_0042.HEIC', 200, 'image/heic'));

        $komponen->assertHasErrors('bukti');

        $html = $komponen->html();

        $this->assertStringContainsString('HEIC', $html,
            'pesannya harus menyebut HEIC: pemilik iPhone tidak tahu fotonya berformat apa');
        $this->assertStringContainsString('Paling Kompatibel', $html,
            'sebut jalan keluarnya persis seperti tertulis di HP-nya — Pengaturan > Kamera > Format');
        $this->assertStringContainsString('JPG, PNG, atau WEBP', $html,
            'yang diterima tetap harus tertulis, supaya kalimatnya bisa dikerjakan');

        // Dan notanya tidak ikut tertahan: foto TIDAK PERNAH menahan tombol simpan.
        $this->assertStringNotContainsString('hal harus dibetulkan dulu', $html);
    }

    /* ── Panel rincian: sudah ada fotonya ────────────────────────────────── */

    /**
     * Foto yang ada bisa DIBUKA (ukuran penuh), diganti, dan dibuang.
     *
     * URL-nya wajib lewat urlBukti(), dan urlBukti() sekarang memakai rute berpenjaga
     * `owner.lampiran.lihat` — bukan lagi `asset('storage/…')`.
     *
     * Yang dijaga di sini TIDAK berubah, cuma alamatnya: Blade tidak boleh menyusun alamat
     * fotonya sendiri. Dulu bahayanya `Storage::url()`, yang selalu memakai APP_URL sehingga
     * tablet di alamat LAN kehilangan seluruh gambar tanpa satu pun pesan galat. Sekarang
     * bahayanya menyusun path `/storage/…` dengan tangan — yang akan 404 karena berkasnya
     * sudah tidak ada di folder publik, dan 404-nya sunyi: kotaknya cuma kosong.
     *
     * Dibandingkan dengan `route()` yang dihitung ulang di uji, BUKAN dengan potongan teks
     * seperti '/kelola/lampiran'. Potongan teks tetap hijau kalau Blade menuliskan alamat
     * yang mirip tapi salah id; `route()` tidak.
     */
    public function test_panel_rincian_menampilkan_foto_yang_bisa_dibuka_besar(): void
    {
        $nota = $this->notaBerbukti();

        $html = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->html();

        $url = (string) $nota->fresh()->urlBukti();

        $this->assertStringContainsString($url, $html, 'fotonya harus benar-benar dirender');

        $this->assertSame(
            route('owner.lampiran.lihat', ['nota' => $nota->getKey(), 'penanda' => 'bukti']),
            $url,
            'alamat fotonya harus rute berpenjaga untuk nota INI — bukan alamat statis dan '
            .'bukan rute nota lain',
        );
        $this->assertStringNotContainsString('/storage/', $url,
            'jangan pulang ke penyajian statis: berkasnya sudah tidak ada di folder publik, '
            .'jadi alamat /storage/ akan 404 dengan sunyi dan kotaknya cuma kosong');
        $this->assertStringContainsString('target="_blank"', $html,
            'struk difoto dengan kamera ponsel dan tulisannya kecil: harus bisa dibuka ukuran penuh');
        $this->assertStringContainsString('Ganti fotonya', $html);
        $this->assertStringContainsString('Hapus foto', $html);
    }

    /**
     * Tombol hapus foto: merah SEJAK ISTIRAHAT, dan konfirmasinya lewat pembungkus bersama.
     *
     * Dua cacat nyata yang dijaga sekaligus. (a) Merah yang hanya muncul lewat `hover:` tidak
     * pernah muncul di tablet & HP — tempat layar owner benar-benar dipakai. (b) `Swal.fire`
     * mentah per layar membuat teks, warna, dan urutan tombolnya bercabang; pembungkusnya ada
     * di resources/js/toast.js dan dipasang sebagai window.konfirmasiNampan.
     */
    public function test_tombol_hapus_foto_merah_dan_berkonfirmasi_sweetalert(): void
    {
        $nota = $this->notaBerbukti();

        $html = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->html();

        $cocok = [];
        preg_match('/<button(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>\s*(?:<[^>]*>|\s)*Hapus foto\s*<\/button>/s', $html, $cocok);

        $this->assertNotEmpty($cocok, 'tombol "Hapus foto" tidak ditemukan di panel rincian');

        $tombol = $cocok[0];

        $this->assertStringContainsString('text-merah-tua', $tombol,
            'merahnya harus ada tanpa hover: di HP hover TIDAK ADA');
        $this->assertStringContainsString('bg-merah/10', $tombol);
        $this->assertStringContainsString('konfirmasiNampan', $tombol,
            'pakai pembungkus SweetAlert bersama, bukan Swal.fire mentah per layar');
        $this->assertStringContainsString('hapusBukti', $tombol);

        // Judul dialognya MENYEBUT nomor notanya: dialog yang tidak menyebut apa yang dihapus
        // membuat orang menekan "Ya" untuk nota yang salah.
        $this->assertStringContainsString($nota->nomor_po, $tombol);
    }

    /* ── Panel rincian: belum ada fotonya ────────────────────────────────── */

    /**
     * Keadaan kosong yang MENOLONG, dan NETRAL — bukan peringatan merah.
     *
     * 90% nota warteg memang tanpa struk; kalau keadaan itu digambar merah, 90% panel jadi
     * merah dan orang belajar mengabaikan merah — termasuk merah yang penting. Yang harus ada
     * bukan kalimat "belum ada bukti", melainkan alasan kenapa menyimpannya berguna: tanpa itu
     * tidak ada sebab untuk memotret apa pun.
     */
    public function test_keadaan_belum_ada_foto_menjelaskan_gunanya_dan_tidak_merah(): void
    {
        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($this->buatProduk('Gula Pasir'), 5, 14000)],
        ]);

        $html = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->html();

        $blok = $this->blokBukti($html);

        $this->assertStringContainsString('Belum ada fotonya', $blok);
        $this->assertStringContainsString('menagih ulang', $blok,
            'keadaan kosong harus menyebut KENAPA menyimpan struk itu berguna');
        $this->assertStringContainsString('Pilih foto struk', $blok);
        $this->assertStringContainsString(SimpanBuktiBelanjaAction::labelBatas(), $blok);

        $this->assertStringNotContainsString('text-merah-tua', $blok,
            'belum ada foto adalah keadaan NETRAL, bukan galat');
        $this->assertStringNotContainsString('Hapus foto', $blok,
            'tidak ada yang bisa dihapus kalau fotonya belum ada');
    }

    /* ── Panel rincian: nota dibatalkan (terkunci) ───────────────────────── */

    /**
     * Nota dibatalkan: fotonya tetap TAMPIL, kedua tombolnya TIDAK dirender, dan alasannya
     * tertulis dengan bahasa orang warung.
     *
     * Aksinya di server sudah menolak (SimpanBuktiBelanjaAction::bolehDiubah), jadi tombol
     * yang tetap dirender hanya akan ditekan berkali-kali sampai orangnya menyimpulkan
     * aplikasinya rusak. Dan fotonya sengaja tidak disembunyikan: nota batal biasanya berarti
     * barangnya dikembalikan ke grosir, dan struk itu justru buktinya.
     */
    public function test_nota_dibatalkan_fotonya_tampil_tanpa_tombol_yang_pasti_menolak(): void
    {
        $nota = $this->notaBerbukti();
        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);
        $nota = $nota->fresh();

        $this->assertTrue($nota->buktiTerkunci());

        $html = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey())
            ->html();

        $blok = $this->blokBukti($html);

        $this->assertStringContainsString((string) $nota->urlBukti(), $html,
            'foto nota yang dibatalkan tetap boleh dilihat');
        $this->assertStringContainsString('dikunci', $blok,
            'alasannya harus tertulis, bukan tombol yang diam-diam hilang');
        $this->assertStringContainsString('dikembalikan ke', $blok);

        $this->assertStringNotContainsString('Hapus foto', $blok);
        $this->assertStringNotContainsString('Ganti fotonya', $blok);
        $this->assertStringNotContainsString('wire:model="bukti"', $blok,
            'kotak pilih berkas pun tidak dirender: tidak ada yang bisa dipasang di nota terkunci');
    }

    /* ── Bantuan ─────────────────────────────────────────────────────────── */

    /**
     * Potongan HTML yang berisi HANYA blok foto kwitansi di panel rincian.
     *
     * Batas atasnya labelnya, batas bawahnya blok tindakan nota (`data-blok="tindakan-nota"`).
     * Batas bawah itu bukan kerapian: tombol "Batalkan nota" di bawahnya MEMANG merah dan
     * memang harus merah, jadi jendela yang kelewat lebar membuat uji "belum ada foto tidak
     * merah" gagal karena warna milik tombol lain. Uji pertama saya begitu.
     *
     * Penandanya dulu string Alpine `tanya: null`, dan itu terbukti rapuh: begitu pembatalan
     * nota pindah ke dialog SweetAlert bersama, keadaan Alpine-nya berubah dan penandanya
     * lenyap — batas bawahnya jatuh ke panjang tetap 4000 karakter, ikut menelan tombol
     * "Batalkan nota", dan uji "belum ada foto tidak merah" gagal karena warna milik tombol
     * lain. `data-blok` ada di Blade khusus sebagai seam uji, jadi ia tidak berubah karena
     * gaya atau keadaan Alpine berubah.
     */
    private function blokBukti(string $html): string
    {
        $awal = strpos($html, 'Foto kwitansi atau struk');

        $this->assertNotFalse($awal, 'blok foto kwitansi tidak ada di panel rincian');

        $akhir = strpos($html, 'data-blok="tindakan-nota"', $awal);

        // Nota yang dibatalkan tidak punya blok tindakan sama sekali (tidak ada yang bisa
        // ditandai datang maupun dibatalkan lagi), jadi batas bawahnya jatuh ke panjang tetap.
        return substr($html, $awal, $akhir === false ? 4000 : $akhir - $awal);
    }

    /**
     * Kotak `<input type="file">` yang terikat ke properti `bukti`, beserta atribut yang
     * menentukan apakah kamera & galeri muncul.
     *
     * Diurai DOMDocument, BUKAN regex: nilai atribut di layar ini memuat `>` (panah objek di
     * dalam wire:key dan panah fungsi di atribut Alpine tetangga), dan pola `<input[^>]*>`
     * memotong tagnya di tengah — cacat yang sudah pernah membuat penjaga di repo ini buta
     * sambil melaporkan hijau.
     *
     * XPath sengaja hanya menyaring `type="file"`; ikatan `wire:model`-nya dibaca lewat
     * getAttribute karena XPath memperlakukan titik dua sebagai awalan namespace dan tidak
     * akan pernah cocok.
     *
     * @return list<array{accept: string, ada_capture: bool}>
     */
    private function kotakUnggahBukti(string $html): array
    {
        $dokumen = new \DOMDocument;

        $sebelumnya = libxml_use_internal_errors(true);
        $dokumen->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        $hasil = [];

        foreach ((new \DOMXPath($dokumen))->query('//input[@type="file"]') as $elemen) {
            /** @var \DOMElement $elemen */
            if ($elemen->getAttribute('wire:model') !== 'bukti') {
                continue;
            }

            $hasil[] = [
                'accept' => $elemen->getAttribute('accept'),
                // hasAttribute, bukan nilainya: `capture` boleh berdiri tanpa nilai sama sekali.
                'ada_capture' => $elemen->hasAttribute('capture'),
            ];
        }

        return $hasil;
    }

    /** Nota yang sudah berfoto, lewat jalur aksinya — bukan kolom yang diisi tangan. */
    private function notaBerbukti(): PurchaseOrder
    {
        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($this->buatProduk('Kopi Sachet'), 10, 1500)],
        ]);

        $this->assertTrue(
            app(SimpanBuktiBelanjaAction::class)->execute($nota, UploadedFile::fake()->image('struk.jpg')),
            'penyiapan uji: fotonya harus benar-benar terpasang',
        );

        return $nota->fresh();
    }
}

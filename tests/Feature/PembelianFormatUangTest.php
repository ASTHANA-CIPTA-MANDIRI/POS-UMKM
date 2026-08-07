<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\PembelianBaru;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Kolom nominal di nota belanja: yang DIKETIK orang, dan yang tersimpan.
 *
 * Keluhan pemiliknya berbunyi "format belum rupiah", tapi yang dijaga berkas ini lebih dari
 * kosmetik. Urutan cacatnya, dan tiap tahap sudah benar-benar terjadi:
 *
 * 1. Kotaknya teks telanjang, jadi pemilik menulis titik ribuannya sendiri: "58.000".
 * 2. `is_numeric('58.000')` true dan `(float) '58.000'` = 58,0 — nota Rp 116.000 tersimpan
 *    Rp 116, harga beli di master jadi Rp 58, TANPA satu pun galat di layar.
 * 3. Sesudah App\Support\Uang memperketat sisi server, cacatnya berubah bentuk: aturan
 *    `numeric` di layar MENOLAK "58.000" lebih dulu, jadi orang yang mengetik seperti
 *    kebiasaannya cuma mendapat "harus berupa angka" — ia tidak bisa mencatat notanya sama
 *    sekali dan tidak tahu apa yang harus diubah.
 *
 * Jadi yang diuji di sini dua arah sekaligus: bentuk yang WAJAR harus diterima dengan skala
 * yang benar, dan bentuk yang MENEBAK ("12.5") harus ditolak dengan pesan yang menyebut
 * contoh benarnya. Ditambah dua penjaga sumber, karena dua keputusan di layar ini tidak bisa
 * dibuktikan dari perilaku: kotak uang tidak boleh punya `wire:model` (ia akan mengirim teks
 * berformat), dan kolom jumlah WAJIB tetap punya (kunci outlet lahir dari updatedJumlah()).
 */
class PembelianFormatUangTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Format Uang');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Format');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Format',
            'email' => 'owner@formatuang.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Bentuk yang WAJAR harus diterima ────────────────────────────────── */

    /**
     * "58.000" × 2 = Rp 116.000 — bukan Rp 116.
     *
     * Inilah kriteria terima nomor satu. Yang membuatnya berbahaya: sebelum ini angkanya
     * TIDAK PERNAH salah dengan cara yang kelihatan. Notanya tersimpan, stoknya benar,
     * totalnya konsisten — hanya skalanya seribu kali lebih kecil, dan itu baru terbaca
     * berbulan kemudian saat pemilik memakai harga belinya untuk menetapkan harga jual.
     */
    public function test_titik_ribuan_diterima_dan_skalanya_benar(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            // Sengaja TEKS, bukan angka: begitulah bentuknya saat sampai dari peramban.
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set('harga.'.$kopi->getKey(), '58.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $item = PurchaseOrder::query()->sole()->items()->sole();

        $this->assertEqualsWithDelta(58000.0, (float) $item->harga_satuan, 0.01,
            '"58.000" harus terbaca lima puluh delapan ribu, bukan lima puluh delapan');
        $this->assertEqualsWithDelta(116000.0, (float) $item->subtotal, 0.01);
        $this->assertEqualsWithDelta(116000.0, (float) $item->purchaseOrder->total, 0.01);
    }

    /**
     * Potongan "1.000" pada nota Rp 116.000 → total Rp 115.000, bukan Rp 115.999.
     *
     * Kriteria terima nomor dua. Kolom potongan punya sifat khusus: salah skala di situ tidak
     * pernah menghasilkan angka yang mencurigakan — Rp 115.999 tetap terlihat seperti nota
     * yang wajar.
     */
    public function test_potongan_bertitik_ribuan_mengurangi_seribu_bukan_serupiah(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set('harga.'.$kopi->getKey(), '58.000')
            ->set('diskon', '1.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $nota = PurchaseOrder::query()->sole();

        $this->assertEqualsWithDelta(1000.0, (float) $nota->diskon, 0.01);
        $this->assertEqualsWithDelta(115000.0, (float) $nota->total, 0.01);
    }

    /**
     * Ongkir bertitik ribuan MENAMBAH total sesuai skalanya.
     *
     * Diuji terpisah dari potongan karena tandanya berlawanan: salah skala di sini membuat
     * total nota lebih KECIL daripada seharusnya, dan nota yang kekecilan tidak pernah
     * memicu kecurigaan siapa pun.
     */
    public function test_ongkos_kirim_bertitik_ribuan_menambah_sesuai_skalanya(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set('harga.'.$kopi->getKey(), '58.000')
            ->set('ongkosKirim', '25.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $nota = PurchaseOrder::query()->sole();

        $this->assertEqualsWithDelta(25000.0, (float) $nota->ongkos_kirim, 0.01);
        $this->assertEqualsWithDelta(141000.0, (float) $nota->total, 0.01);
    }

    /**
     * Nominal berawalan "Rp" dan berspasi ribuan juga diterima.
     *
     * Bentuk ini lahir dari tempel-salin (WhatsApp, spreadsheet) dan dari kotak yang memang
     * MENAMPILKAN "Rp 58.000" — kalau isinya ikut terkirim apa adanya lewat jalur yang bukan
     * Alpine, ia tidak boleh ditolak.
     */
    public function test_awalan_rp_dan_spasi_ribuan_diterima(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '1')
            ->set('harga.'.$kopi->getKey(), 'Rp 58.000')
            ->set('ongkosKirim', '10 000')
            ->call('simpan')
            ->assertHasNoErrors();

        $nota = PurchaseOrder::query()->sole();

        $this->assertEqualsWithDelta(58000.0, (float) $nota->items()->sole()->harga_satuan, 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $nota->ongkos_kirim, 0.01);
    }

    /**
     * Jumlah "2,5" kg diterima — koma adalah cara orang di Indonesia menulis desimal.
     *
     * Kolom jumlah aturannya KEBALIKAN dari kolom uang, dan itu disengaja: yang di sana
     * ditolak (titik) di sini justru berbahaya, dan yang di sana berbahaya (koma) di sini
     * sah. Menolak koma berarti pemilik warteg yang membeli 2,5 kg beras tidak bisa mencatat
     * notanya sama sekali.
     */
    public function test_jumlah_berkoma_diterima_untuk_satuan_yang_bisa_dipecah(): void
    {
        $beras = $this->buatBahan('Beras', ['satuan' => Satuan::Kg]);

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$beras->getKey(), '2,5')
            ->set('harga.'.$beras->getKey(), '14.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(2.5, $this->saldo($this->outlet, $beras), 0.001,
            'stok harus bertambah 2,5 kg — bukan 25, dan bukan gagal sama sekali');
        $this->assertEqualsWithDelta(35000.0, (float) PurchaseOrder::query()->sole()->total, 0.01);
    }

    /**
     * Harga "0" tetap tersimpan dan TIDAK dibaca sebagai kosong.
     *
     * Nol adalah pernyataan "bonus grosir"; kosong berarti belum diisi. Perilaku ini sudah ada
     * sebelum kotak berformat, dan ia harus bertahan — kotak yang memformat sendiri adalah
     * tempat paling mudah nol berubah menjadi kosong ("Rp 0" terlihat seperti placeholder).
     */
    public function test_harga_nol_tersimpan_sedangkan_harga_kosong_ditolak(): void
    {
        $kopi = $this->buatProduk('Kopi Bonus', ['harga_beli' => 1500]);

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '3')
            ->set('harga.'.$kopi->getKey(), '0')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(0.0, (float) PurchaseOrder::query()->sole()->items()->sole()->harga_satuan, 0.01);

        // Dan kosong tetap ditolak, dengan pesan "wajib diisi" — bukan diam-diam jadi nol.
        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '3')
            ->set('harga.'.$kopi->getKey(), '')
            ->call('simpan')
            ->assertHasErrors('harga.'.$kopi->getKey());

        $this->assertStringContainsString('wajib diisi', $komponen->errors()->first('harga.'.$kopi->getKey()));
        $this->assertSame(1, PurchaseOrder::query()->count(), 'nota kedua tidak boleh tersimpan');
    }

    /* ── Bentuk yang MENEBAK harus ditolak ───────────────────────────────── */

    /**
     * Harga "12.5" ditolak, dan pesannya menyebut contoh yang benar.
     *
     * "12.5" bisa berarti dua belas setengah ATAU 12.500 yang kehilangan dua nol — beda
     * seribu kali, dan tidak ada jawaban yang benar tanpa bertanya kepada orangnya. Menebak
     * di sini berarti menebak uang orang lain, dan itu tidak boleh pernah senyap.
     *
     * Pesannya HARUS memuat contoh: penolakan yang tidak menunjukkan bentuk yang benar
     * membuat orang mengetik ulang hal yang sama, lalu berhenti memakai layarnya.
     */
    public function test_harga_bersen_ditolak_dan_pesannya_menyebut_contoh_benar(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);
        $kunci = 'harga.'.$kopi->getKey();

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set($kunci, '12.5')
            ->call('simpan')
            ->assertHasErrors($kunci);

        $pesan = $komponen->errors()->first($kunci);

        $this->assertStringContainsString('58000', $pesan, 'pesannya harus menunjukkan bentuk yang benar');
        $this->assertStringContainsString('58.000', $pesan);
        $this->assertStringContainsString('12.5', $pesan, 'pesannya harus menyebut apa yang ditolak');

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertEqualsWithDelta(1500.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'harga beli di master tidak boleh tersentuh oleh nota yang gagal');
    }

    /**
     * Jumlah "1.500" ditolak dengan pesan yang menyebut TITIK, bukan "harus berupa angka".
     *
     * Orang yang baru belajar "titik boleh di kolom harga" akan menulis 1.500 di kolom jumlah.
     * Kalau dibaca 1,5 ia menerima 1,5 kg tanpa satu pun peringatan — seribu kali lebih
     * sedikit dari yang ia maksud, dan selisihnya baru muncul di hitung stok bulan depan.
     */
    public function test_jumlah_bertitik_ribuan_ditolak_dengan_pesan_menyebut_titik(): void
    {
        $beras = $this->buatBahan('Beras', ['satuan' => Satuan::Kg]);
        $kunci = 'jumlah.'.$beras->getKey();

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set($kunci, '1.500')
            ->set('harga.'.$beras->getKey(), '14.000')
            ->call('simpan')
            ->assertHasErrors($kunci);

        $this->assertStringContainsString('tanpa titik', $komponen->errors()->first($kunci));

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertNull($this->saldo($this->outlet, $beras));
    }

    /**
     * Potongan "1.000.000" ditolak karena LEBIH BESAR dari belanjaannya — bukan karena
     * "harus berupa angka".
     *
     * Bedanya menentukan, dan inilah keadaan yang membuktikan kedua penjaga bekerja
     * bersama-sama: potongannya terbaca dengan benar (satu juta), lalu ditolak oleh aturan
     * yang benar. Sebelum ini ia berhenti lebih awal sebagai galat bentuk, jadi pemiliknya
     * mendapat pesan yang menyuruhnya memperbaiki angka yang bentuknya sudah benar.
     */
    public function test_potongan_kelewat_besar_ditolak_dengan_alasan_yang_benar(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set('harga.'.$kopi->getKey(), '58.000')
            ->set('diskon', '1.000.000')
            ->call('simpan')
            ->assertHasErrors('diskon');

        $pesan = $komponen->errors()->first('diskon');

        $this->assertStringContainsString('lebih besar daripada belanjaannya', $pesan);
        $this->assertStringNotContainsString('harus berupa angka', $pesan);
        // Nominal keduanya ikut disebut supaya pemiliknya bisa membandingkannya dengan mata.
        $this->assertStringContainsString('Rp 116.000', $pesan);
        $this->assertStringContainsString('Rp 1.000.000', $pesan);

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    /**
     * Potongan Rp 5.000 pada nota Rp 116.000 DITERIMA — dan itu bukan uji yang sepele.
     *
     * Cacatnya lahir dari KOMBINASI, bukan dari satu kolom: dengan harga "58.000" dibaca
     * `(float)` sebagai 58, subtotal 2 dus terbaca 116, jadi potongan Rp 5.000 yang sah
     * ditolak keliru sebagai "lebih besar daripada belanjaannya". Pemiliknya lalu memperbaiki
     * kolom yang tidak salah, dan tidak ada satu pun pesan yang mengarahkannya ke kolom harga.
     */
    public function test_potongan_yang_sah_tidak_tertolak_karena_subtotal_salah_skala(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set('harga.'.$kopi->getKey(), '58.000')
            ->set('diskon', '5.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(111000.0, (float) PurchaseOrder::query()->sole()->total, 0.01);
    }

    /* ── Bar ringkasan di layar ──────────────────────────────────────────── */

    /**
     * Bar bawah memakai SKALA YANG SAMA dengan nota yang akan tersimpan.
     *
     * Bar itu satu-satunya tempat pemilik memeriksa notanya sebelum menekan Simpan (kartu
     * keterangan sudah tergulir ke atas). Kalau ia berkata "Rp 111" untuk nota yang akan
     * tersimpan Rp 111.000, yang salah bukan cuma tampilannya: pemilik yang melihat angka
     * sekecil itu akan menyangka harganya belum masuk lalu mengetiknya lagi.
     */
    public function test_bar_ringkasan_membaca_titik_ribuan_dengan_skala_yang_sama(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set('harga.'.$kopi->getKey(), '58.000')
            ->set('diskon', '5.000')
            ->set('ongkosKirim', '10.000')
            ->assertSee('Rp 116.000')   // belanja barangnya
            ->assertSee('Rp 121.000')   // total = 116.000 − 5.000 + 10.000
            ->assertSee('Rp 5.000')
            ->assertSee('Rp 10.000');
    }

    /* ── Sesudah nota tersimpan ──────────────────────────────────────────── */

    /**
     * Kotak uang DIBUAT ULANG sesudah nota tersimpan.
     *
     * Yang tampak di kotak harga dimiliki Alpine (kotaknya memformat sendiri, tanpa
     * wire:model), jadi mengosongkan properti di server saja TIDAK menghapus apa pun dari
     * peramban: harga nota yang baru tersimpan masih terpampang di nota berikutnya lalu ikut
     * tersimpan sebagai belanja yang tidak pernah terjadi. Belanja di dua grosir dalam satu
     * hari itu biasa.
     *
     * Yang membuat kotaknya lahir kembali kosong: wire:key-nya berubah, sehingga Livewire
     * membuang elemen lamanya dan Alpine berjalan ulang dengan nilai awal kosong.
     */
    public function test_kunci_kotak_uang_berubah_sesudah_nota_tersimpan(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $kunci = $kopi->getKey();

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kunci, '2')
            ->set('harga.'.$kunci, '58.000');

        $komponen->assertSee('uang-harga-0-'.$kunci, false)
            ->assertSee('uang-diskon-0', false);

        $komponen->call('simpan')->assertHasNoErrors();

        $komponen->assertSee('uang-harga-1-'.$kunci, false)
            ->assertDontSee('uang-harga-0-'.$kunci, false)
            ->assertSee('uang-diskon-1', false);

        // Dan nilai awal yang ditulis Alpine memang kosong — bukan angka nota sebelumnya.
        $this->assertStringNotContainsString('kotakUang(\'58000\'', $komponen->html());
    }

    /* ── Penjaga sumber ──────────────────────────────────────────────────── */

    /**
     * Kotak uang TIDAK BOLEH punya `wire:model` — dan penjaganya membaca DOM, bukan regex.
     *
     * Kenapa ini penjaga sumber dan bukan uji perilaku: `wire:model` yang dipasang kembali
     * TIDAK membuat satu pun uji lain merah. Ia membaca `element.value`, dan yang ada di situ
     * teks BERFORMAT ("Rp 58.000") — persis bentuk yang membuat nota Rp 116.000 tersimpan
     * Rp 116. Di uji PHPUnit nilainya disetel lewat ->set(), jadi kotaknya tidak pernah
     * berformat dan cacatnya tidak pernah muncul. Hanya orang yang mengetik di peramban yang
     * kena, dan ia tidak akan pernah melihat galat.
     *
     * Atribut `value=` ikut dilarang dengan alasan tetangganya: morph Livewire akan menimpa
     * kotaknya dengan angka mentah di tengah orang mengetik.
     *
     * DIURAI dengan DOMDocument, bukan regex. Penjaga pemindai di repo ini sudah tiga kali
     * berbohong: dua kali menuduh komentar yang menyebut kata terlarang, sekali karena
     * `[^>]*` patah pada `>` di dalam `=>` sebuah fungsi panah Alpine. Dan `assertNotEmpty`
     * di bawah bukan hiasan: tanpa itu, hari ketika pemindainya berhenti menemukan apa pun
     * adalah hari ia berhenti menjaga — dan tidak ada yang tahu.
     */
    public function test_kotak_uang_tidak_boleh_punya_wire_model_maupun_value(): void
    {
        $kotak = $this->kotakUang($this->halamanNotaBaru());

        $this->assertNotEmpty($kotak, 'tidak ada satu pun kotak uang yang ditemukan — '
            .'pemindainya buta, bukan layarnya bersih');

        // Potongan + ongkir + harga (kartu HP & tabel dekstop untuk tiap barang). Jumlah
        // minimalnya ditegaskan supaya pemindai yang cuma menemukan separuh layar ikut merah.
        $this->assertGreaterThanOrEqual(4, count($kotak),
            'harga di kartu HP, harga di tabel, potongan, dan ongkir semuanya kotak uang; '
            .'yang terbaca cuma '.count($kotak));

        foreach ($kotak as ['id' => $id, 'atribut' => $atribut]) {
            foreach (array_keys($atribut) as $nama) {
                $this->assertStringStartsNotWith('wire:model', $nama,
                    "kotak uang #{$id} punya {$nama}: ikatan Livewire membaca element.value, "
                    .'dan yang ada di situ teks berformat — "Rp 58.000" akan terkirim dan '
                    .'tersimpan sebagai Rp 58. Yang mengirim nilainya $wire.set() di uang.js.');
            }

            $this->assertArrayNotHasKey('value', $atribut,
                "kotak uang #{$id} punya atribut value=: morph Livewire akan menimpanya dengan "
                .'angka mentah di tengah orang mengetik. Nilai awalnya lewat kotakUang(...).');

            $this->assertSame('numeric', $atribut['inputmode'] ?? null,
                "kotak uang #{$id} harus inputmode=numeric — rupiah yang diketik orang bulat");
        }
    }

    /**
     * Setiap kotak uang menunjuk properti Livewire yang BENAR-BENAR ada.
     *
     * Ini penutup satu-satunya mata rantai yang tidak bisa dibuktikan uji lain: nama tujuan
     * itu argumen kedua `kotakUang(...)`, dan Alpine memakainya di `$wire.set(nama, digit)`.
     * Salah nama TIDAK menghasilkan galat apa pun — kotaknya tetap memformat, layarnya tetap
     * terlihat benar, dan yang tersimpan adalah nota tanpa harga sama sekali. Uji PHPUnit lain
     * menyetel propertinya lewat ->set() jadi tidak pernah lewat jalur ini, dan uji JS memakai
     * $wire palsu yang menerima nama apa pun.
     */
    public function test_setiap_kotak_uang_menunjuk_properti_yang_ada(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $html = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->html();

        $kotak = $this->kotakUang($html);

        $this->assertNotEmpty($kotak, 'tidak ada kotak uang yang ditemukan — pemindainya buta');

        $diharapkan = ['diskon', 'ongkosKirim', 'harga.'.$kopi->getKey()];
        $ditemukan = [];

        foreach ($kotak as ['id' => $id, 'atribut' => $atribut]) {
            $cocok = preg_match(
                '/^kotakUang\(\s*(?:\'[^\']*\'|"[^"]*")\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)$/',
                (string) ($atribut['x-data'] ?? ''),
                $bagian,
            );

            $this->assertSame(1, $cocok,
                "x-data kotak #{$id} tidak berbentuk kotakUang(nilaiAwal, namaProperti): "
                .($atribut['x-data'] ?? '(tidak ada)'));

            $this->assertContains($bagian[1], $diharapkan,
                "kotak #{$id} menunjuk properti \"{$bagian[1]}\" yang tidak ada di komponen. "
                .'$wire.set() ke nama yang salah tidak melempar apa pun — notanya tersimpan '
                .'tanpa harga, dan layarnya tetap terlihat benar.');

            $ditemukan[] = $bagian[1];
        }

        // Ketiganya harus benar-benar ada di layar, bukan cuma "yang ada sudah benar".
        foreach ($diharapkan as $nama) {
            $this->assertContains($nama, $ditemukan, "kotak untuk \"{$nama}\" hilang dari layar");
        }
    }

    /**
     * Kolom JUMLAH wajib TETAP `wire:model.live.debounce` — ini yang paling berbahaya.
     *
     * Kunci outlet lahir dari updatedJumlah(): angka pertama yang masuk mengunci nota ke
     * cabang tempat ia diketik. Kalau kolom ini ikut ditangguhkan seperti kotak uang, kuncinya
     * baru terpasang pada permintaan BERIKUTNYA — dan dropdown outlet yang diganti di celah
     * itu memasukkan SELURUH belanjaan ke cabang yang salah tanpa satu pun galat. Stok kedua
     * cabang jadi salah, dan tidak ada catatan yang menunjukkan kenapa.
     */
    public function test_kolom_jumlah_tetap_terikat_live_debounce(): void
    {
        $jumlah = $this->kotakJumlah($this->halamanNotaBaru());

        $this->assertNotEmpty($jumlah, 'tidak ada kolom jumlah yang ditemukan — pemindainya buta');
        $this->assertGreaterThanOrEqual(2, count($jumlah),
            'kartu HP dan tabel dekstop masing-masing punya kolom jumlah; yang terbaca cuma '
            .count($jumlah));

        foreach ($jumlah as ['id' => $id, 'atribut' => $atribut]) {
            $ikatan = array_values(array_filter(
                array_keys($atribut),
                fn (string $nama) => str_starts_with($nama, 'wire:model'),
            ));

            $this->assertNotEmpty($ikatan,
                "kolom jumlah #{$id} kehilangan wire:model: kunci outlet lahir dari "
                .'updatedJumlah(), dan tanpa ikatan hidup seluruh belanjaan bisa masuk ke '
                .'cabang yang salah tanpa satu pun galat');

            $this->assertContains('wire:model.live.debounce.600ms', $ikatan,
                "kolom jumlah #{$id} harus .live.debounce.600ms, bukan ".implode(', ', $ikatan)
                .' — dengan .blur atau .live tanpa jeda, kuncinya terlambat atau tiap huruf '
                .'menjadi satu perjalanan ke server');

            $this->assertSame('decimal', $atribut['inputmode'] ?? null,
                "kolom jumlah #{$id} tetap inputmode=decimal: ini satu-satunya kolom yang "
                .'boleh pecahan (2,5 kg beras)');
        }
    }

    /**
     * $diskon & $ongkosKirim WAJIB tetap `?string`.
     *
     * Terukur, bukan dikira: properti Livewire ber-tipe float DICOR saat hidrasi, yaitu
     * SEBELUM satu pun aturan validasi berjalan. Muatan "58.000" menjadi 58.0 di situ, dan
     * seluruh penjagaan uang berubah menjadi kode mati yang tetap hijau di semua uji — yang
     * tersimpan Rp 58 dari nota Rp 58.000, tanpa satu pun galat di layar.
     */
    public function test_properti_nominal_tetap_bertipe_teks(): void
    {
        $sumber = $this->tanpaKomentar(
            (string) file_get_contents(app_path('Livewire/Pages/Owner/PembelianBaru.php'))
        );

        foreach (['diskon', 'ongkosKirim'] as $properti) {
            $this->assertMatchesRegularExpression(
                '/public\s+\?string\s+\$'.$properti.'\b/',
                $sumber,
                "\${$properti} harus ?string. Dengan ?float, \"58.000\" dicor menjadi 58.0 saat "
                .'hidrasi — sebelum validator jalan — dan penjaga uangnya jadi kode mati.'
            );
        }
    }

    /* ── Bantuan ─────────────────────────────────────────────────────────── */

    /** Layar nota belanja yang sudah berisi baris, supaya kotak per barang ikut terurai. */
    private function halamanNotaBaru(): string
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        return Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), '2')
            ->set('harga.'.$kopi->getKey(), '58000')
            ->html();
    }

    /**
     * Kotak uang: elemen ber-x-data kotakUang(...), beserta SELURUH atributnya.
     *
     * Ditandai dari x-data, bukan dari id/nama kolom: itu satu-satunya penanda yang ikut
     * berpindah kalau kotaknya dipindah tempat, dan yang mustahil ada di kolom lain.
     *
     * @return list<array{id: string, atribut: array<string, string>}>
     */
    private function kotakUang(string $html): array
    {
        return $this->pindai($html, '//input[starts-with(@x-data, "kotakUang(")]');
    }

    /**
     * @return list<array{id: string, atribut: array<string, string>}>
     */
    private function kotakJumlah(string $html): array
    {
        // id, karena kolom jumlah dikenali dari ikatannya — dan ikatan itulah yang sedang
        // diperiksa. Pemindai yang mencari wire:model untuk memeriksa wire:model akan hijau
        // tepat pada hari ikatannya hilang.
        return $this->pindai($html, '//input[starts-with(@id, "jumlah-")]');
    }

    /** @return list<array{id: string, atribut: array<string, string>}> */
    private function pindai(string $html, string $xpath): array
    {
        $dokumen = new \DOMDocument;

        // Atribut Alpine memuat karakter yang bukan entitas HTML sah, jadi libxml mengeluh.
        // Dibungkam LOKAL supaya galat libxml di uji lain tetap terlihat.
        $sebelumnya = libxml_use_internal_errors(true);
        $dokumen->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        $hasil = [];

        foreach ((new \DOMXPath($dokumen))->query($xpath) as $elemen) {
            /** @var \DOMElement $elemen */
            $atribut = [];

            foreach ($elemen->attributes as $satu) {
                $atribut[$satu->nodeName] = $satu->nodeValue;
            }

            $hasil[] = ['id' => $elemen->getAttribute('id'), 'atribut' => $atribut];
        }

        return $hasil;
    }

    /**
     * Komentar DIBUANG sebelum sumbernya dipindai.
     *
     * Bentuk lama penjaga di repo ini menuduh komentar yang menyebut kata terlarang — dua
     * kali. Penjaga yang menuduh kode yang benar akan dimatikan orang, dan sesudah itu
     * temuan sungguhannya ikut hilang.
     */
    private function tanpaKomentar(string $isi): string
    {
        $bersih = '';

        foreach (token_get_all($isi) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $bersih .= is_array($token) ? $token[1] : $token;
        }

        return $bersih;
    }
}

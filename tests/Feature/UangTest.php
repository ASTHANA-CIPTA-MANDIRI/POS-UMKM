<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Uang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Penerjemah angka uang & jumlah — dan buktinya di jalur yang benar-benar menyimpan uang.
 *
 * CACAT NYATA YANG MELAHIRKAN BERKAS INI (dibuktikan dengan uji sekali-pakai sebelum
 * perbaikan, dan LOLOS tanpa satu pun galat):
 *
 *   harga "58.000", jumlah "2", diskon "1.000" → simpan
 *   → harga_satuan 58,00 · subtotal 116,00 · diskon 1,00 · total 115,00
 *   → harga_beli di master produk tertimpa jadi 58,00
 *
 * Nota Rp 116.000 tercatat Rp 115. Sebabnya `is_numeric('58.000') === true` dan
 * `(float) '58.000' === 58.0`, jadi nilainya lolos `numeric|min:0`. Orang warung mengetik
 * titik ribuan dengan sendirinya — ini kebiasaan menulis rupiah, bukan kemungkinan teoretis.
 *
 * Dua bagian di berkas ini, dan keduanya perlu:
 *   1. tabel nilai — aturan penerjemahnya, termasuk yang DITOLAK beserta alasannya;
 *   2. jalur aksi (CatatPembelianAction) — karena penjaganya harus di sana, bukan hanya di
 *      layar: seeder, perintah artisan, dan uji memanggil aksinya tanpa lewat Livewire.
 */
class UangTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Angka Rupiah');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Angka');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Angka',
            'email' => 'owner@angkarupiah.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── 1. Tabel nilai: UANG ────────────────────────────────────────────── */

    /** @return array<string, array{mixed, ?int}> */
    public static function uangDiterima(): array
    {
        return [
            'kosong berarti belum diisi, bukan nol' => ['', null],
            'null sama artinya dengan kosong' => [null, null],
            'spasi saja tetap kosong' => ['   ', null],
            'NBSP saja tetap kosong' => ["\u{00A0}", null],
            'bulat tanpa pemisah' => ['58000', 58000],
            'nol sah — bonus grosir' => ['0', 0],
            'titik ribuan satu golongan' => ['58.000', 58000],
            'titik ribuan dua golongan' => ['1.000.000', 1000000],
            'titik ribuan golongan pertama satu digit' => ['1.500', 1500],
            'awalan Rp diserap' => ['Rp58000', 58000],
            'awalan Rp berspasi + titik ribuan' => ['Rp 58.000', 58000],
            'awalan Rp bertitik' => ['Rp. 58.000', 58000],
            'NBSP sesudah Rp (dari number_format & tempel WhatsApp)' => ["Rp\u{00A0}58.000", 58000],
            'spasi sebagai pemisah ribuan — spasi tidak pernah berarti desimal' => ['58 000', 58000],
            'int' => [58000, 58000],
            'int nol' => [0, 0],
            'float yang nilainya bulat' => [58000.0, 58000],
        ];
    }

    #[DataProvider('uangDiterima')]
    public function test_uang_yang_diterima(mixed $masuk, ?int $harap): void
    {
        $this->assertTrue(Uang::sah($masuk), 'seharusnya sah: '.var_export($masuk, true));
        $this->assertSame($harap, Uang::baca($masuk));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function uangDitolak(): array
    {
        return [
            // Sen yang diketik tidak ada di kolom harga barang warung. Membacanya sebagai
            // 58 (dipotong) atau 59 (dibulatkan) sama-sama menebak.
            'koma desimal' => ['58,5'],
            'titik desimal' => ['58.5'],
            // "58.00" bisa berarti 58 (format dua desimal dari spreadsheet) ATAU 58.000 yang
            // kehilangan satu nol saat diketik. Beda seribu kali; tidak ada jawaban yang
            // benar tanpa bertanya kepada orangnya.
            'dua desimal gaya spreadsheet' => ['58.00'],
            'nol desimal' => ['58.0'],
            // Golongan tidak beraturan = salah ketik. Membacanya 123456 "memperbaiki" angka
            // yang tidak pernah dimaksudkan siapa pun.
            'golongan tengah dua digit' => ['1.23.456'],
            'golongan empat digit' => ['1.0000'],
            // Uang negatif tidak punya arti di aplikasi ini; retur punya jalurnya sendiri.
            'minus teks' => ['-58000'],
            'minus bertitik ribuan' => ['-58.000'],
            'minus int' => [-1],
            'minus float' => [-0.5],
            // Huruf & pemisah lain: menerjemahkannya berarti menebak.
            'singkatan warung' => ['58rb'],
            'huruf' => ['lima puluh ribu'],
            'campur angka & huruf' => ['58000 rupiah'],
            'pemisah lain' => ['58-000'],
            'koma ribuan gaya Inggris' => ['58,000'],
            // Float berpecahan di kolom uang berarti ada `(float)` di tempat yang seharusnya
            // memakai kelas ini — menerima lalu membulatkan menyembunyikannya.
            'float berpecahan' => [58.5],
            // true bukan Rp 1.
            'boolean' => [true],
            'array' => [['58000']],
            // (int) '99999999999999999999' menjadi PHP_INT_MAX tanpa satu pun galat: angka
            // yang dikarang mesin lebih buruk daripada penolakan yang bisa dibaca.
            'digit kelewat panjang (keluapan senyap)' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('uangDitolak')]
    public function test_uang_yang_ditolak(mixed $masuk): void
    {
        $this->assertFalse(Uang::sah($masuk), 'seharusnya DITOLAK: '.var_export($masuk, true));

        $this->expectException(InvalidArgumentException::class);

        Uang::baca($masuk);
    }

    /**
     * Pesan penolakan menyebut nilai MENTAHNYA.
     *
     * Pesan tanpa nilainya ("format tidak sah") membuat orang mengetik ulang hal yang sama.
     */
    public function test_pesan_penolakan_menyebut_nilai_mentahnya(): void
    {
        try {
            Uang::baca('58,5');
            $this->fail('seharusnya melempar');
        } catch (InvalidArgumentException $galat) {
            $this->assertStringContainsString('58,5', $galat->getMessage());
        }
    }

    /* ── 2. Tabel nilai: JUMLAH (aturannya KEBALIKAN) ────────────────────── */

    /** @return array<string, array{mixed, ?float}> */
    public static function jumlahDiterima(): array
    {
        return [
            'kosong' => ['', null],
            'null' => [null, null],
            // KOMA WAJIB JALAN: sebelum ini koma ditolak, dan itu memblokir pemilik warteg
            // yang membeli 2,5 kg beras — notanya tidak bisa dicatat sama sekali.
            'koma desimal — 2,5 kg beras' => ['2,5', 2.5],
            'koma desimal di bawah satu' => ['0,25', 0.25],
            // Titik desimal SUDAH jalan sebelum perbaikan; jangan sampai hilang.
            'titik desimal tetap jalan' => ['2.5', 2.5],
            'bulat' => ['2', 2.0],
            'nol (artinya barisnya tidak dibeli — yang menolaknya aturan domain)' => ['0', 0.0],
            'int' => [2, 2.0],
            'float pecahan' => [2.5, 2.5],
            'spasi terbuang' => [' 2,5 ', 2.5],
        ];
    }

    #[DataProvider('jumlahDiterima')]
    public function test_jumlah_yang_diterima(mixed $masuk, ?float $harap): void
    {
        $this->assertSame($harap, Uang::bacaJumlah($masuk));
    }

    /**
     * "1.500" di kolom JUMLAH ditolak tegas — ini butir yang paling mudah salah.
     *
     * Kalau diterima sebagai 1,5 (karena pemeriksaan desimal jalan lebih dulu), orang yang
     * baru belajar "titik boleh dipakai di kolom harga" akan menulis 1.500 di kolom jumlah
     * lalu menerima 1,5 kg beras — tanpa satu pun galat, seribu kali lebih sedikit daripada
     * yang ia maksud. Karena itu bentuk BERGOLONGAN diperiksa LEBIH DULU daripada desimal.
     *
     * @return array<string, array{mixed}>
     */
    public static function jumlahDitolak(): array
    {
        return [
            'titik ribuan tidak bisa dibedakan dari pecahan' => ['1.500'],
            'titik ribuan dua golongan' => ['1.000.000'],
            'titik ribuan tiga digit di depan' => ['999.999'],
            'dua pemisah' => ['2,5,5'],
            'campur ribuan & desimal' => ['1.500,5'],
            'huruf' => ['dua setengah'],
            'Rp di kolom jumlah berarti isinya tertukar kolom' => ['Rp2'],
            'boolean' => [true],
            'array' => [[2]],
        ];
    }

    #[DataProvider('jumlahDitolak')]
    public function test_jumlah_yang_ditolak(mixed $masuk): void
    {
        $this->expectException(InvalidArgumentException::class);

        Uang::bacaJumlah($masuk);
    }

    /**
     * Jumlah minus BERBENTUK benar dan lewat dari penerjemah — yang menolaknya aturan domain.
     *
     * Arti "jumlah minus" berbeda per layar (di nota belanja salah ketik, di koreksi stok
     * sah), jadi keputusannya milik pemanggil. Yang penting: nilainya tidak berubah tanda
     * atau besarnya saat lewat.
     */
    public function test_jumlah_minus_lewat_penerjemah_dengan_nilai_utuh(): void
    {
        $this->assertSame(-2.0, Uang::bacaJumlah('-2'));
        $this->assertSame(-2.5, Uang::bacaJumlah('-2,5'));
    }

    /* ── 3. Jalur aksi: angka yang tersimpan, bukan yang ditebak ─────────── */

    /**
     * CACAT UTAMANYA, di jalur yang menyimpan: "58.000" × 2 = 116.000, bukan 116.
     *
     * Aksinya dipanggil langsung (bukan lewat layar) karena di situlah penjaganya harus
     * berada: seeder, perintah artisan, dan sinkronisasi tidak pernah melewati aturan
     * validasi Livewire.
     */
    public function test_harga_bertitik_ribuan_tersimpan_penuh_bukan_dipotong(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [[
                'product_id' => $kopi->getKey(),
                'qty_beli' => '2',
                'harga_satuan' => '58.000',
            ]],
        ]);

        $item = PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->sole();

        $this->assertSame('58000.00', $item->harga_satuan, 'harga per satuan beli utuh');
        $this->assertSame('116000.00', $item->subtotal, '2 × 58.000');
        $this->assertEqualsWithDelta(116000.0, (float) $nota->fresh()->total, 0.01);

        // Bentuk cacatnya yang lama, disebut eksplisit supaya tidak pernah kembali sebagai
        // "pembulatan" yang kelihatan wajar.
        $this->assertNotEqualsWithDelta(58.0, (float) $item->harga_satuan, 0.01);
        $this->assertNotEqualsWithDelta(116.0, (float) $item->subtotal, 0.01);

        // Master produk ikut tertimpa oleh nota — itu memang jalurnya (harga terakhir yang
        // menang), jadi angkanya harus benar di sana juga.
        $this->assertEqualsWithDelta(58000.0, (float) $kopi->fresh()->harga_beli, 0.01);
    }

    /** Diskon "1.000" adalah seribu rupiah, dan total nota mengikutinya. */
    public function test_diskon_bertitik_ribuan_terbaca_seribu_bukan_satu(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'diskon' => '1.000',
            'baris' => [[
                'product_id' => $kopi->getKey(),
                'qty_beli' => '2',
                'harga_satuan' => '58.000',
            ]],
        ]);

        $this->assertSame('1000.00', $nota->fresh()->diskon);
        // 116.000 − 1.000. Bentuk lamanya: 116 − 1 = 115.
        $this->assertEqualsWithDelta(115000.0, (float) $nota->fresh()->total, 0.01);
    }

    /** Ongkos kirim lewat penerjemah yang sama — kolom uang, aturan uang. */
    public function test_ongkos_kirim_bertitik_ribuan_terbaca_penuh(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'ongkos_kirim' => '20.000',
            'baris' => [[
                'product_id' => $kopi->getKey(),
                'qty_beli' => '2',
                'harga_satuan' => '58.000',
            ]],
        ]);

        $this->assertSame('20000.00', $nota->fresh()->ongkos_kirim);
        $this->assertEqualsWithDelta(136000.0, (float) $nota->fresh()->total, 0.01);
    }

    /**
     * Diskon 1.000.000 di nota Rp 116.000 gagal karena ALASAN YANG BENAR.
     *
     * Sebelum perbaikan ia berhenti sebagai "harus berupa angka" — is_numeric menolak
     * "1.000.000", jadi pesannya menyalahkan bentuk angka yang sebenarnya ditulis dengan
     * benar. Sesudah perbaikan angkanya terbaca 1.000.000 dan yang menolaknya adalah
     * kenyataan: diskonnya lebih besar daripada belanjaannya. Tanpa penjaga itu notanya
     * tersimpan bertotal −884.000 — uang MASUK menurut catatan, padahal pemiliknya membayar.
     */
    public function test_diskon_lebih_besar_daripada_total_ditolak_karena_alasan_yang_benar(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        try {
            $this->catatNota($this->outlet, $this->owner, [
                'diskon' => '1.000.000',
                'baris' => [[
                    'product_id' => $kopi->getKey(),
                    'qty_beli' => '2',
                    'harga_satuan' => '58.000',
                ]],
            ]);

            $this->fail('diskon lebih besar daripada belanjaannya seharusnya ditolak');
        } catch (InvalidArgumentException $galat) {
            $this->assertStringContainsString('lebih besar daripada total', $galat->getMessage());
            $this->assertStringNotContainsString('harus berupa angka', $galat->getMessage());
            $this->assertStringNotContainsString('angka rupiah saja', $galat->getMessage());
        }

        // Notanya tidak tersimpan separuh: transaksinya digulung balik seluruhnya.
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, PurchaseOrderItem::query()->count());
        $this->assertNull($this->saldo($this->outlet, $kopi));
    }

    /**
     * Harga yang bentuknya menebak-nebak ditolak DI AKSI, dan pesannya menyebut yang ditulis.
     *
     * "58,5" tidak dibaca 58 maupun 59: rupiah yang diketik orang tidak punya sen, dan
     * memilih salah satu berarti aplikasi memutuskan uang orang lain secara senyap.
     */
    public function test_harga_bersen_ditolak_di_aksi_dengan_pesan_bahasa_warung(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        try {
            $this->catatNota($this->outlet, $this->owner, [
                'baris' => [[
                    'product_id' => $kopi->getKey(),
                    'qty_beli' => '2',
                    'harga_satuan' => '58,5',
                ]],
            ]);

            $this->fail('harga bersen seharusnya ditolak');
        } catch (InvalidArgumentException $galat) {
            $this->assertStringContainsString('Harga beli', $galat->getMessage());
            $this->assertStringContainsString('58000 atau 58.000', $galat->getMessage(),
                'pesannya mencontohkan bentuk yang benar, bukan menyebut "format tidak sah"');
            $this->assertStringContainsString('58,5', $galat->getMessage(),
                'menyebut yang benar-benar ditulis, supaya orang tahu apa yang ditolak');
        }

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertEqualsWithDelta(1500.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'harga master tidak boleh tersentuh oleh nota yang gagal');
    }

    /** Harga minus tetap ditolak dengan pesannya sendiri — bukan "bentuknya tidak terbaca". */
    public function test_harga_negatif_ditolak_dengan_pesan_negatif(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        try {
            $this->catatNota($this->outlet, $this->owner, [
                'baris' => [[
                    'product_id' => $kopi->getKey(),
                    'qty_beli' => '2',
                    'harga_satuan' => '-58.000',
                ]],
            ]);

            $this->fail('harga negatif seharusnya ditolak');
        } catch (InvalidArgumentException $galat) {
            $this->assertStringContainsString('tidak boleh negatif', $galat->getMessage());
        }

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    /**
     * Jumlah "2,5" kg beras masuk sebagai 2,5 — bukan ditolak, bukan jadi 25.
     *
     * Ini kemampuan yang HILANG sebelum perbaikan: koma ditolak, jadi pemilik warteg tidak
     * bisa mencatat 2,5 kg sama sekali.
     */
    public function test_jumlah_berkoma_diterima_di_aksi_untuk_satuan_yang_bisa_dipecah(): void
    {
        $beras = $this->buatProduk('Beras', ['satuan' => Satuan::Kg]);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [[
                'product_id' => $beras->getKey(),
                'qty_beli' => '2,5',
                'harga_satuan' => '13.000',
            ]],
        ]);

        $item = PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->sole();

        $this->assertEqualsWithDelta(2.5, (float) $item->qty_beli, 0.001);
        $this->assertSame('32500.00', $item->subtotal, '2,5 × 13.000');
        $this->assertEqualsWithDelta(2.5, $this->saldo($this->outlet, $beras), 0.001);
    }

    /**
     * "1.500" di kolom JUMLAH ditolak di aksi juga — dan inilah kerugian kalau tidak.
     *
     * Dibaca 1,5 berarti 1,5 kg beras masuk untuk nota yang menyebut 1.500 kg. Dibaca 1.500
     * juga bukan hak aplikasi memutuskan: yang tahu maksudnya cuma yang mengetik.
     */
    public function test_jumlah_bertitik_ribuan_ditolak_di_aksi(): void
    {
        $beras = $this->buatProduk('Beras', ['satuan' => Satuan::Kg]);

        try {
            $this->catatNota($this->outlet, $this->owner, [
                'baris' => [[
                    'product_id' => $beras->getKey(),
                    'qty_beli' => '1.500',
                    'harga_satuan' => '13.000',
                ]],
            ]);

            $this->fail('jumlah bertitik ribuan seharusnya ditolak');
        } catch (InvalidArgumentException $galat) {
            $this->assertStringContainsString('Jumlah beli', $galat->getMessage());
            $this->assertStringContainsString('1.500', $galat->getMessage());
        }

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertNull($this->saldo($this->outlet, $beras));
    }

    /**
     * Konversi dus→pcs TIDAK ikut terbatasi: hasil HITUNGAN memang boleh berdesimal.
     *
     * Yang dibatasi penerjemah adalah angka yang DIKETIK. 2 dus @58.000 isi 12 = 116.000
     * untuk 24 pcs = 4.833,33 per pcs, dan angka bersen itu wajib tetap tersimpan — kalau
     * ikut ditolak/dibulatkan, nilai persediaan seluruh barang berkemasan jadi salah.
     */
    public function test_harga_per_satuan_dasar_tetap_boleh_bersen(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [[
                'product_id' => $susu->getKey(),
                'qty_beli' => '2',
                'harga_satuan' => '58.000',
            ]],
        ]);

        $this->assertEqualsWithDelta(4833.33, (float) $susu->fresh()->harga_beli, 0.01,
            '116.000 ÷ 24 pcs — hasil hitungan, bukan angka yang diketik');
        $this->assertEqualsWithDelta(24.0, $this->saldo($this->outlet, $susu), 0.001);
    }
}

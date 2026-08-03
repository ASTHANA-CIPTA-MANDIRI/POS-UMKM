<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menjaga agar suite TIDAK PERNAH menunjuk database sungguhan.
 *
 * Ini lahir dari kerusakan nyata: sebuah perintah artisan menjalankan `php artisan test`
 * sebagai anak proses, anaknya mewarisi DB_CONNECTION=mysql dari induknya, <env> di
 * phpunit.xml tidak menimpanya karena tanpa force="true", dan RefreshDatabase menjalankan
 * migrate:fresh pada database pengembangan — seluruh data hilang dan tidak bisa dipulihkan.
 *
 * Uji ini berjalan lebih dulu daripada uji mana pun yang menyentuh data. Kalau ia gagal,
 * HENTIKAN suite dan periksa lingkungannya sebelum menjalankan apa pun lagi.
 */
class PenjagaDatabaseUjiTest extends TestCase
{
    public function test_suite_memakai_sqlite_di_memori_bukan_database_sungguhan(): void
    {
        $koneksi = DB::connection();

        $this->assertSame('sqlite', $koneksi->getDriverName(),
            'Suite menunjuk driver "'.$koneksi->getDriverName().'". JANGAN dilanjutkan: RefreshDatabase akan menghapus database itu.');

        $this->assertSame(':memory:', $koneksi->getDatabaseName(),
            'Suite menunjuk berkas database "'.$koneksi->getDatabaseName().'", bukan :memory:.');
    }

    public function test_lingkungan_yang_aktif_adalah_testing(): void
    {
        $this->assertSame('testing', app()->environment());
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Uji TIDAK boleh bergantung pada hasil build aset.
     *
     * `public/build` diabaikan git — sebagaimana seharusnya, itu keluaran build, bukan
     * sumber. Akibatnya di setiap salinan bersih (dan di setiap runner CI) `@vite` melempar
     * "Vite manifest not found", dan SEPULUH uji yang cuma merender halaman ikut gagal.
     *
     * Itu kegagalan palsu, dan kegagalan palsu lebih berbahaya daripada tidak ada angka:
     * laporan progres otomatis akan menyebut 10 cacat yang tidak ada, lalu orang belajar
     * mengabaikan angkanya — dan sesudah itu cacat sungguhan ikut terabaikan.
     *
     * PratinjauTest sengaja menyalakannya kembali: ia memang butuh URL aset yang nyata
     * karena hasilnya dibuka di peramban untuk diukur.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}

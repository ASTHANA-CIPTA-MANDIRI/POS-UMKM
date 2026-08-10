<?php

namespace App\Livewire\Pages\Owner\Istilah;

use App\Livewire\Concerns\TerikatTenant;
use App\Support\Istilah as KamusIstilah;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Halaman "Istilah": arti tiap kata yang dipakai aplikasi, dalam bahasa warung.
 *
 * KENAPA HALAMAN SENDIRI, padahal tiap istilah sudah bisa dibuka penjelasannya di tempat.
 * Penjelasan di tempat menjawab "apa arti kata INI", dan itu butuh orangnya sudah berada di
 * layar yang memuatnya. Yang tidak terjawab: "aplikasi ini pakai kata apa saja, dan yang mana
 * yang perlu saya pahami dulu". Halaman ini yang menjawabnya — bisa dibaca sekali di awal,
 * sebelum menyentuh apa pun.
 *
 * Isinya dari App\Support\Istilah, sumber yang SAMA dengan gelembung penjelasan di layar.
 */
#[Layout('layouts.aplikasi')]
class Istilah extends Component
{
    use TerikatTenant;

    #[Url(as: 'cari')]
    public string $cari = '';

    public function render()
    {
        $kata = trim(mb_strtolower($this->cari));

        $kelompok = KamusIstilah::perKelompok();

        if ($kata !== '') {
            /*
             * Dicari di ISTILAH, ARTI, dan CONTOHnya sekaligus.
             *
             * Orang yang bingung biasanya tidak tahu nama istilahnya — ia tahu gejalanya.
             * Mengetik "sewa" harus menemukan "Biaya operasional", dan mengetik "utang" harus
             * menemukan "Kasbon", walaupun kata itu tidak ada di judulnya.
             */
            foreach ($kelompok as $namaKelompok => $isi) {
                $tersaring = array_filter($isi, fn (array $b) => str_contains(mb_strtolower(
                    $b['istilah'].' '.$b['arti'].' '.($b['contoh'] ?? '')
                ), $kata));

                if ($tersaring === []) {
                    unset($kelompok[$namaKelompok]);
                } else {
                    $kelompok[$namaKelompok] = $tersaring;
                }
            }
        }

        return view('livewire.pages.owner.istilah.istilah', [
            'kelompok' => $kelompok,
            'jumlah' => array_sum(array_map('count', $kelompok)),
            'jumlahSemua' => count(KamusIstilah::semua()),
        ]);
    }
}

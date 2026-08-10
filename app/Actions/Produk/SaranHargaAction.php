<?php

namespace App\Actions\Produk;

/**
 * Menyusun SARAN harga jual dari HPP dan target margin.
 *
 * SARAN, bukan penetapan — dan kata itu menentukan seluruh rancangannya. Harga adalah
 * keputusan pemilik yang tahu harga warung sebelah, tahu pelanggannya, dan tahu kapan boleh
 * mahal. Aplikasi yang MENIMPA harga dengan hasil hitungannya akan salah pada barang yang
 * paling menentukan, dan pemilik akan berhenti memercayai seluruh layarnya.
 *
 * RUMUSNYA: harga = HPP / (1 - margin), bukan HPP x (1 + margin).
 *
 * Bedanya bukan gaya. Yang kedua menghasilkan MARKUP, bukan margin: modal 10.000 dengan
 * "30%" jadi 13.000, dan marginnya cuma 23% — pemilik yang menargetkan 30% mendapat 23%
 * tanpa pernah tahu. Rumus pertama menghasilkan 14.286, yang marginnya benar-benar 30%.
 * HitungHppAction::margin() memakai pembagi yang sama (harga jual), jadi angka yang
 * ditampilkan sesudah harga ini dipakai akan cocok dengan targetnya.
 */
class SaranHargaAction
{
    /**
     * Margin yang mustahil dipenuhi.
     *
     * Pada 100% harga jual harus tak terhingga (pembaginya nol), dan di atas 100% hasilnya
     * NEGATIF — aplikasi akan menyarankan harga minus tanpa satu pun galat. Dibatasi 95%,
     * yang sudah jauh di luar apa pun yang masuk akal untuk warung.
     */
    public const MAKS_MARGIN = 95.0;

    /**
     * @return array{harga: ?float, hargaBulat: ?float, marginNyata: ?float}
     */
    public function untuk(?float $hpp, float $targetMarginPersen): array
    {
        if ($hpp === null || $hpp <= 0 || $targetMarginPersen < 0 || $targetMarginPersen > self::MAKS_MARGIN) {
            return ['harga' => null, 'hargaBulat' => null, 'marginNyata' => null];
        }

        $harga = round($hpp / (1 - ($targetMarginPersen / 100)), 2);
        $bulat = $this->bulatkan($harga);

        return [
            'harga' => $harga,
            'hargaBulat' => $bulat,
            /*
             * Margin yang BENAR-BENAR didapat sesudah dibulatkan, bukan targetnya.
             *
             * Pembulatan selalu menggeser marginnya sedikit, dan pemilik yang menargetkan 30%
             * lalu melihat "30%" di layar padahal nyatanya 31,2% akan menyimpulkan aplikasinya
             * menyembunyikan sesuatu begitu ia menghitung sendiri. Angka yang jujur lebih
             * berguna daripada angka yang rapi.
             */
            'marginNyata' => $bulat > 0 ? round((($bulat - $hpp) / $bulat) * 100, 1) : null,
        ];
    }

    /**
     * Membulatkan KE ATAS ke pecahan yang benar-benar ditulis orang di daftar harga.
     *
     * KE ATAS, selalu. Pembulatan ke bawah memakan marginnya diam-diam: target 30% pada modal
     * Rp 10.000 menghasilkan Rp 14.286, dan membulatkannya ke Rp 14.000 menurunkan margin ke
     * 28,6% pada SETIAP barang sekaligus.
     *
     * Pecahannya bertingkat menurut besarnya, karena satu pecahan tetap salah di dua ujung:
     * membulatkan ke Rp 500 pada barang Rp 2.140 menaikkannya 17%, sementara membulatkan ke
     * Rp 100 pada barang Rp 72.350 menghasilkan Rp 72.400 — angka yang tidak pernah ditulis
     * di daftar harga mana pun.
     */
    private function bulatkan(float $harga): float
    {
        $pecahan = match (true) {
            $harga < 5000 => 100,
            $harga < 50000 => 500,
            default => 1000,
        };

        return (float) (ceil($harga / $pecahan) * $pecahan);
    }
}

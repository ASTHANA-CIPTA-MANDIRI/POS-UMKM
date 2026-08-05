<?php

namespace App\Http\Controllers\Kasir;

use App\Actions\Kasir\SusunSisaStokAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kabar sisa stok untuk petak produk di layar kasir.
 *
 * KENAPA endpoint sendiri, bukan ikut muatan katalog:
 *
 * Katalog dimuat sekali lalu dipakai berjam-jam — itu memang bentuk yang benar untuk
 * nama, harga, barcode, dan gambar, yang jarang berubah dan yang penyegarannya sengaja
 * dibuat manual supaya harga tidak berganti diam-diam di tengah transaksi. Sisa stok
 * berlawanan: ia berubah setiap satu transaksi, termasuk transaksi kasir lain di
 * perangkat lain. Menempelkannya ke katalog berarti memilih salah satu dari dua
 * kerugian — angka stok yang berumur berjam-jam, atau seluruh katalog (beserta
 * harganya) ikut tersegarkan tiap beberapa menit.
 *
 * Muatannya juga sengaja kecil: hanya barang yang perlu dikabarkan, hanya keadaannya,
 * tanpa angka. Katalog 300 produk dengan gambar tidak layak dikirim ulang tiap beberapa
 * menit lewat sinyal warung; peta berisi belasan kunci pendek layak.
 *
 * ATURAN 3 CLAUDE.md — layar kasir tidak boleh bergantung jaringan:
 * endpoint ini TIDAK pernah menghalangi apa pun. Klien memanggilnya lepas dari alur
 * transaksi dan mengabaikan kegagalannya; kalau tidak terjawab, petaknya tampil apa
 * adanya seperti sebelum fitur ini ada. Tidak ada satu pun jalur penjualan yang
 * menunggunya.
 *
 * Outlet TIDAK diambil dari parameter request — selalu dari record kasir yang login,
 * sama seperti katalog. Kalau outlet boleh dikirim dari layar, kasir Outlet A bisa
 * mengintip keadaan gudang Outlet B (bahkan milik tenant lain) hanya dengan mengubah
 * satu nilai di URL.
 */
class SisaStokController extends Controller
{
    public function __invoke(Request $request, SusunSisaStokAction $susun): JsonResponse
    {
        $user = $request->user();

        if ($user->outlet_id === null) {
            return response()->json([
                'pesan' => 'Akun ini belum ditugaskan ke outlet mana pun.',
            ], 409);
        }

        return response()->json([
            'outlet_id' => $user->outlet_id,
            /*
             * Umur kabar ini dibatasi, dan batasnya ditentukan SERVER, bukan diketik
             * ulang di klien.
             *
             * Lencana yang tidak pernah kedaluwarsa adalah bentuk kebohongan yang paling
             * merugikan di layar ini: perangkat yang offline sejak pagi akan terus
             * menampilkan "Habis" untuk barang yang kirimannya sudah datang jam sepuluh,
             * dan kasir menolak menjual barang yang ada di rak — persis masalah yang
             * fitur ini seharusnya selesaikan, hanya terbalik arahnya. Lewat batas ini
             * lencananya hilang dan petaknya kembali seperti sebelum ada fitur ini:
             * tidak tahu lebih jujur daripada tahu yang sudah kedaluwarsa.
             */
            'kedaluwarsa_menit' => (int) config('nampan.sisa_stok_kedaluwarsa_menit'),
            // Jam menurut server (Asia/Jakarta), untuk ditulis di keterangan lencana.
            // Jam perangkat tidak dipakai untuk ini: tablet warung sering salah setel,
            // dan "menurut catatan pukul …" harus menunjuk waktu yang sama bagi semua.
            'jam' => now()->format('H.i'),
            'sisa' => $susun->execute($user->outlet_id),
        ]);
    }
}

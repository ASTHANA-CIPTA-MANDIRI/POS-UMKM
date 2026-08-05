<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lima kolom untuk fitur Pembelian. TIDAK ada tabel baru — skemanya sudah ada sejak
 * create_purchase_orders_table & create_purchase_order_items_table.
 *
 * Makna kolom LAMA sengaja tidak berubah, supaya nota yang sudah tercatat (seeder demo,
 * dan nanti data merchant) tetap terbaca sama:
 * - `qty`          = jumlah dalam satuan DASAR (yang benar-benar masuk kartu stok)
 * - `harga_satuan` = harga per satuan BELI (angka yang tertulis di nota grosir)
 * - `subtotal`     = qty_beli × harga_satuan
 *
 * Kolom baru di baris item adalah POTRET, bukan acuan hidup: `isi_per_satuan_beli`
 * merekam faktor konversi yang berlaku SAAT nota disimpan. Master boleh berubah (grosir
 * ganti kemasan dari isi 12 jadi isi 10), dan kalau nota lama membaca master, nota
 * kemarin akan menceritakan jumlah yang tidak pernah dibeli siapa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $tabel) {
            // Diskon & ongkir TIDAK dibagi ke harga barang. Ongkir bukan nilai barang —
            // memasukkannya ke harga beli membuat nilai persediaan naik karena biaya
            // angkut, dan harga jual yang dihitung dari situ ikut melar tanpa sebab.
            $tabel->decimal('diskon', 15, 2)->default(0)->after('total');
            $tabel->decimal('ongkos_kirim', 15, 2)->default(0)->after('diskon');
        });

        Schema::table('purchase_order_items', function (Blueprint $tabel) {
            // Nullable: baris nota lama (seeder demo) tidak punya potret ini, dan
            // mengisinya dengan tebakan berarti mengarang isi nota yang sudah tercatat.
            $tabel->decimal('qty_beli', 15, 3)->nullable()->after('qty');
            $tabel->string('satuan_beli')->nullable()->after('qty_beli');
            $tabel->decimal('isi_per_satuan_beli', 15, 3)->nullable()->after('satuan_beli');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $tabel) {
            $tabel->dropColumn(['diskon', 'ongkos_kirim']);
        });

        Schema::table('purchase_order_items', function (Blueprint $tabel) {
            $tabel->dropColumn(['qty_beli', 'satuan_beli', 'isi_per_satuan_beli']);
        });
    }
};

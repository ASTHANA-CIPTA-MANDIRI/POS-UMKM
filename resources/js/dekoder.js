/**
 * Pembaca barcode: satu bawaan peramban, satu cadangan yang diunduh saat dibutuhkan.
 *
 * Kenapa perlu cadangan sama sekali? Karena BarcodeDetector bawaan peramban TIDAK ada
 * di mana-mana: Safari (semua iPhone), Firefox, dan Chrome di Windows/Linux tidak
 * memilikinya. Kalau kamera hanya mengandalkan yang bawaan, separuh pemilik warung
 * membuka layar ini dan tombol pindainya tidak muncul tanpa penjelasan.
 *
 * Cadangannya (ZXing WebAssembly, ±1 MB) dimuat MALAS — hanya saat orangnya benar-benar
 * menekan pindai DAN peramban itu tidak punya pembaca bawaan. Halaman kelola produk
 * yang tidak memindai tidak menanggung satu byte pun.
 *
 * Berkas .wasm-nya diambil dari server kita sendiri, bukan CDN. Bawaan zxing-wasm
 * menunjuk jsdelivr; itu berarti warung tanpa internet stabil gagal memindai justru
 * ketika pemindai USB juga tidak ada, dan alamat barang yang dipindai ikut terkirim ke
 * pihak ketiga.
 */

import wasmLokal from 'zxing-wasm/reader/zxing_reader.wasm?url';

/**
 * Format yang dipindai. Dibatasi sengaja: makin banyak format, makin lama tiap frame
 * diperiksa. EAN/UPC menutup barang bermerek pabrik, CODE_128/39 dan ITF menutup label
 * cetakan grosir dan dus.
 */
export const FORMAT = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'];

let janjiCadangan = null;

function muatCadangan() {
    janjiCadangan ??= import('barcode-detector/pure').then(({ BarcodeDetector, setZXingModuleOverrides }) => {
        setZXingModuleOverrides({
            locateFile: (berkas, awalan) => (berkas.endsWith('.wasm') ? wasmLokal : awalan + berkas),
        });

        return BarcodeDetector;
    });

    return janjiCadangan;
}

/**
 * Apakah pembaca bawaan peramban benar-benar bisa dipakai.
 *
 * Keberadaan `window.BarcodeDetector` saja TIDAK cukup. Chrome di beberapa sistem
 * memaparkan kelasnya tapi tidak punya mesin pemindainya, dan getSupportedFormats()
 * mengembalikan daftar kosong — detect() baru gagal setelah kamera menyala dan orangnya
 * sudah menunggu sambil memegang barang.
 */
export async function bawaanSanggup() {
    if (typeof window === 'undefined' || ! ('BarcodeDetector' in window)) {
        return false;
    }

    try {
        const didukung = await window.BarcodeDetector.getSupportedFormats();

        return FORMAT.some((format) => didukung.includes(format));
    } catch {
        return false;
    }
}

/**
 * Pembaca siap pakai.
 *
 * @returns {Promise<{detektor: {detect: Function}, cadangan: boolean}>}
 *          `cadangan: true` berarti yang dipakai ZXing, bukan bawaan peramban —
 *          dipakai untuk memberi tahu orangnya bahwa pemindaiannya sedikit lebih berat.
 */
export async function buatDetektor() {
    if (await bawaanSanggup()) {
        return { detektor: new window.BarcodeDetector({ formats: FORMAT }), cadangan: false };
    }

    const Cadangan = await muatCadangan();

    return { detektor: new Cadangan({ formats: FORMAT }), cadangan: true };
}

/**
 * Membaca barcode dari satu berkas gambar (foto atau tangkapan kamera bawaan sistem).
 *
 * Jalur ini penting di iPhone dan di peramban dalam aplikasi (WhatsApp, Instagram):
 * di sana `capture="environment"` membuka aplikasi kamera bawaan yang selalu boleh
 * dipakai, bahkan ketika aliran kamera langsung ditolak atau alamatnya bukan HTTPS.
 *
 * @returns {Promise<string|null>} null berarti fotonya terbaca tapi tidak ada barcode
 *          di dalamnya — bukan galat sistem.
 */
export async function bacaBerkas(berkas) {
    const { detektor } = await buatDetektor();
    const sumber = await jadikanGambar(berkas);

    try {
        const hasil = await detektor.detect(sumber);

        return hasil.find((baris) => baris.rawValue)?.rawValue ?? null;
    } finally {
        sumber.close?.();
    }
}

/** createImageBitmap lebih murah; <img> dipakai kalau perambannya belum punya. */
async function jadikanGambar(berkas) {
    if (typeof createImageBitmap === 'function') {
        return createImageBitmap(berkas);
    }

    const alamat = URL.createObjectURL(berkas);

    try {
        const gambar = new Image();
        gambar.src = alamat;
        await gambar.decode();

        return gambar;
    } finally {
        // Dilepas setelah decode() selesai: pikselnya sudah ada di memori, dan URL objek
        // yang tidak dilepas menahan berkasnya sampai tab ditutup.
        URL.revokeObjectURL(alamat);
    }
}

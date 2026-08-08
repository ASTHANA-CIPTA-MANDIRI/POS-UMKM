/**
 * Laravel Echo di atas Reverb — DIMUAT SAAT DIBUTUHKAN, bukan di setiap halaman.
 *
 * Bentuk bawaan `install:broadcasting` menaruh `import './echo'` di app.js dan membuat
 * `new Echo(...)` seketika. Di aplikasi ini itu melanggar aturan keras nomor 3: layar kasir
 * tidak boleh bergantung jaringan. Tiga akibatnya nyata, bukan teoretis:
 *
 *  1. pusher-js (±100 KB) ikut masuk bundel yang HARUS diunduh kasir sebelum bisa berjualan,
 *     padahal kasir tidak butuh satu pun siaran.
 *  2. Echo membuka koneksi seketika lalu mencoba ulang terus-menerus saat offline — di warung
 *     dengan sinyal buruk itu berarti radio dan baterai terpakai sepanjang shift.
 *  3. Kegagalan koneksi jadi derau di konsol pada layar yang justru paling sering dipakai
 *     offline, dan derau yang normal membuat galat yang sungguhan berhenti terbaca.
 *
 * Karena `import()` di sini dinamis, Vite memecahnya jadi chunk terpisah: berkasnya baru
 * diunduh kalau ada layar yang benar-benar memanggil pasangEcho(). Layar kasir tidak.
 *
 * Dipanggil dari layar yang memang butuh realtime:
 *
 *     const echo = await window.pasangEcho();
 *     echo?.private(`outlet.${id}`).listen('PesananMasuk', (e) => …);
 *
 * `echo?.` bukan kelalaian — pasangEcho() SENGAJA mengembalikan null kalau Reverb tidak
 * disetel atau gagal dimuat, dan pemanggilnya harus tetap jalan tanpa realtime. Fitur
 * realtime di sini selalu penambah, tidak pernah syarat.
 */

/** Satu instance untuk seluruh halaman; percobaan kedua memakai yang sama. */
let echo = null;

/** Menahan pemanggilan berbarengan supaya tidak membuat dua koneksi. */
let sedangMemuat = null;

/**
 * Reverb dianggap tidak tersedia kalau kuncinya tidak ada.
 *
 * Diperiksa LEBIH DULU, sebelum apa pun diunduh: tanpa kunci, Echo tetap terbentuk lalu
 * gagal berulang-ulang tanpa pernah bisa berhasil — mencoba ulang sesuatu yang mustahil
 * hanya menghabiskan baterai dan mengotori konsol.
 */
function disetel() {
    return typeof import.meta.env.VITE_REVERB_APP_KEY === 'string'
        && import.meta.env.VITE_REVERB_APP_KEY !== '';
}

export async function pasangEcho() {
    if (echo !== null) {
        return echo;
    }

    if (! disetel()) {
        return null;
    }

    if (sedangMemuat !== null) {
        return sedangMemuat;
    }

    sedangMemuat = (async () => {
        try {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([
                import('laravel-echo'),
                import('pusher-js'),
            ]);

            // Echo versi ini mencari Pusher di window, bukan menerimanya sebagai argumen.
            window.Pusher = Pusher;

            const skema = import.meta.env.VITE_REVERB_SCHEME ?? 'https';

            echo = new Echo({
                broadcaster: 'reverb',
                key: import.meta.env.VITE_REVERB_APP_KEY,
                wsHost: import.meta.env.VITE_REVERB_HOST,
                wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
                wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
                forceTLS: skema === 'https',
                enabledTransports: ['ws', 'wss'],
            });

            return echo;
        } catch (e) {
            /*
             * Ditelan dengan sengaja, dan hanya di sini.
             *
             * Yang bisa melempar: chunk gagal diunduh (offline), atau setelan yang tidak
             * dipahami Echo. Dua-duanya TIDAK boleh mematikan halaman — kalau `pasangEcho()`
             * melempar dari layar owner, seluruh Alpine di halaman itu berhenti dan yang
             * hilang bukan realtime-nya, melainkan tombol-tombolnya.
             */
            console.warn('Realtime tidak aktif:', e?.message ?? e);
            echo = null;

            return null;
        } finally {
            sedangMemuat = null;
        }
    })();

    return sedangMemuat;
}

export function pasangEchoKeWindow() {
    window.pasangEcho = pasangEcho;
}

/**
 * Memotret & MENGUKUR satu halaman pada ukuran tertentu lewat DevTools Protocol.
 *
 * Dipakai untuk membuktikan klaim "rapi" dan "responsif" dengan angka, bukan dengan
 * melihat. Cacat yang hanya bisa ditemukan begini dan tidak pernah muncul di PHPUnit:
 * panel yang menggulir di layar pendek, tombol yang ukurannya tidak seragam, teks yang
 * tertimpa tombol di kolom sempit, dan elemen yang tersembunyi selamanya karena Alpine
 * tidak jalan.
 *
 * Jalankan Chrome lebih dulu:
 *   "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new \
 *     --disable-gpu --no-sandbox --remote-debugging-port=9333 \
 *     --user-data-dir=/tmp/ukur about:blank &
 *
 * Lalu: node tests/browser/ukur.mjs <url> <lebar> <keluaran.png> [berkas-ekspresi.js] [tinggi]
 *
 * Berkas ekspresi berisi SATU ekspresi JavaScript (boleh async IIFE) yang mengembalikan
 * string; hasilnya dicetak ke stdout bersama lebar layarnya.
 *
 * Emulation.setDeviceMetricsOverride mengubah lebar TATA LETAK, bukan hanya ukuran
 * jendela — Chrome headless mengabaikan --window-size untuk tata letak, sehingga
 * pengukuran responsif lewat jendela selalu menipu.
 *
 * Pemakaian: node potret.mjs <url> <lebar> <berkas.png> [berkas-ekspresi.js] [tinggi]
 */
const [alamat, lebar, berkas, jalurUkur, tinggi] = process.argv.slice(2);
const { readFileSync, writeFileSync } = await import('node:fs');
const ukur = jalurUkur ? readFileSync(jalurUkur, 'utf8') : null;

const daftar = await (await fetch('http://127.0.0.1:9333/json/list')).json();
const sasaran = daftar.find((t) => t.type === 'page');
const ws = new WebSocket(sasaran.webSocketDebuggerUrl);
let nomor = 0;
const menunggu = new Map();
const alamatPermintaan = new Map();
const galat = [];

/*
 * Berkas yang gagal dimuat dicatat TERPISAH dan membatalkan pengukurannya.
 *
 * Ini bukan kehati-hatian berlebihan. Kalau app.js atau livewire.js tidak sampai ke
 * peramban, Alpine tidak pernah jalan — dan halaman yang Alpine-nya mati tetap
 * menghasilkan tujuh angka yang kelihatan sah. Angka-angka itu lalu dibaca sebagai
 * "cacat tata letak" padahal yang rusak alat ukurnya. Dua sebab yang sudah benar-benar
 * terjadi di proyek ini: tangkapan pratinjau memuat aset dari http://localhost TANPA
 * porta (server ada di :8000, jadi ditolak), dan halaman dibuka lewat file:// sehingga
 * skrip dari origin lain diblokir CORS.
 *
 * Karena itu Script/Stylesheet/Document yang gagal membuat baris hasilnya diberi tanda
 * TIDAK SAH dan proses keluar dengan kode 2 — supaya tidak bisa disangka hasil bersih,
 * dan supaya tidak bisa hilang tersapu grep. Gambar yang gagal hanya diperingatkan:
 * ia menggeser tata letak, tapi tidak mematikan seluruh halaman.
 */
const gagalMuat = [];

ws.addEventListener('message', (e) => {
    const pesan = JSON.parse(e.data);

    if (pesan.method === 'Runtime.exceptionThrown') {
        galat.push('KECUALI: ' + JSON.stringify(pesan.params?.exceptionDetails).slice(0, 600));
    }

    if (pesan.method === 'Network.loadingFailed') {
        const p = pesan.params ?? {};
        gagalMuat.push({
            jenis: p.type ?? 'Other',
            sebab: p.corsErrorStatus?.corsError ?? p.blockedReason ?? p.errorText ?? 'tidak diketahui',
            url: alamatPermintaan.get(p.requestId) ?? '(url tidak terlacak)',
        });
    }

    // loadingFailed tidak menyertakan URL-nya, jadi dicatat saat permintaan dikirim.
    if (pesan.method === 'Network.requestWillBeSent') {
        alamatPermintaan.set(pesan.params?.requestId, pesan.params?.request?.url);
    }

    // 404/500 atas skrip tidak memicu loadingFailed — permintaannya berhasil, isinya salah.
    if (pesan.method === 'Network.responseReceived' && (pesan.params?.response?.status ?? 0) >= 400) {
        gagalMuat.push({
            jenis: pesan.params?.type ?? 'Other',
            sebab: 'HTTP ' + pesan.params.response.status,
            url: pesan.params?.response?.url ?? '(url tidak terlacak)',
        });
    }

    if (pesan.method === 'Log.entryAdded' && pesan.params?.entry?.level === 'error') {
        galat.push('LOG: ' + pesan.params.entry.text);
    }

    if (pesan.id && menunggu.has(pesan.id)) {
        menunggu.get(pesan.id)(pesan);
        menunggu.delete(pesan.id);
    }
});

await new Promise((r) => ws.addEventListener('open', r));

function kirim(method, params = {}) {
    const id = ++nomor;
    ws.send(JSON.stringify({ id, method, params }));

    return new Promise((r) => menunggu.set(id, r));
}

await kirim('Page.enable');
await kirim('Network.enable');
await kirim('Runtime.enable');
await kirim('Log.enable');
await kirim('Emulation.setDeviceMetricsOverride', {
    width: Number(lebar),
    height: Number(tinggi ?? 900),
    deviceScaleFactor: 1,
    mobile: Number(lebar) < 640,
});
await kirim('Page.navigate', { url: alamat });
await new Promise((r) => setTimeout(r, 2500));

// Skrip/gaya/dokumen yang gagal membuat seluruh pengukuran tidak bisa dipercaya.
const fatal = gagalMuat.filter((g) => ['Script', 'Stylesheet', 'Document'].includes(g.jenis));

for (const g of gagalMuat) {
    const awalan = fatal.includes(g) ? 'GAGAL MUAT' : 'gagal muat (tidak fatal)';
    console.log(`${awalan}: ${g.jenis} ${g.sebab} — ${g.url}`);
}

if (ukur) {
    const balasan = await kirim('Runtime.evaluate', { expression: ukur, returnByValue: true, awaitPromise: true });

    const nilai = balasan.result?.result?.value;
    // Tandanya di DEPAN baris, bukan di belakang: baris hasil sering dipotong `head`.
    const tanda = fatal.length > 0 ? 'TIDAK SAH — ' : '';
    console.log(tanda + `${lebar}px | ` + (typeof nilai === 'string' ? nilai : JSON.stringify(balasan.result).slice(0, 700)));
}

if (galat.length) {
    console.log(galat.slice(0, 5).join('\n'));
}

if (fatal.length > 0) {
    console.log(`TIDAK SAH: ${fatal.length} berkas penting gagal dimuat, jadi Alpine/Livewire mungkin tidak jalan. Angka di atas JANGAN dipakai.`);
    process.exitCode = 2;
}

const gambar = await kirim('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });

if (gambar.result?.data) {
    writeFileSync(berkas, Buffer.from(gambar.result.data, 'base64'));
}

ws.close();

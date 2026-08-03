@props(['judul' => 'Arahkan ke barcode'])

{{--
    Panel kamera. Dipakai bersama oleh kolom cari dan formulir produk supaya keduanya
    berperilaku sama — panel pindai yang berbeda di dua tempat membuat orang harus
    belajar dua kali.

    Wajib berada di dalam komponen Alpine `pemindaiBarcode`, yang menyediakan seluruh
    keadaan yang dibaca di sini (terbuka, menyiapkan, galat, banyakKamera).
--}}
<div x-show="terbuka" x-cloak
     class="fixed inset-0 z-[60] flex items-center justify-center bg-navy-900/80 p-4"
     @click.self="selesai()" @keydown.escape.window="selesai()"
     role="dialog" aria-modal="true" aria-label="{{ $judul }}">
    <div class="w-full max-w-md overflow-hidden rounded-[20px] bg-white shadow-[0_24px_60px_-20px_rgb(11_20_55/0.45)]">
        <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
            <p class="text-[0.9375rem] font-bold text-ink">{{ $judul }}</p>

            <div class="flex items-center gap-1">
                {{-- Lampu: hanya muncul kalau kameranya memang punya. Keadaan nyala
                     ditandai warna, bukan hanya ikon, supaya terlihat sekali lihat. --}}
                <button type="button" @click="gantiLampu()" x-show="punyaLampu" x-cloak
                        x-bind:aria-pressed="lampuNyala ? 'true' : 'false'"
                        x-bind:aria-label="lampuNyala ? 'Matikan lampu' : 'Nyalakan lampu'"
                        x-bind:title="lampuNyala ? 'Matikan lampu' : 'Nyalakan lampu'"
                        x-bind:class="lampuNyala
                            ? 'bg-amber-glow text-ink'
                            : 'text-umber hover:bg-cream hover:text-ink'"
                        class="grid size-10 cursor-pointer place-items-center rounded-lg transition-colors">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M10 2.5v1.8M4.6 4.6l1.3 1.3M2.5 10h1.8M15.4 4.6l-1.3 1.3M17.5 10h-1.8"
                              stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        <circle cx="10" cy="11.5" r="3.6" stroke="currentColor" stroke-width="1.6" />
                    </svg>
                </button>

                {{-- Ganti kamera hanya muncul kalau memang ada lebih dari satu; tombol
                     yang tidak mengubah apa pun lebih buruk daripada tidak ada. --}}
                <button type="button" @click="gantiKamera()" x-show="banyakKamera" x-cloak
                        aria-label="Ganti kamera" title="Ganti kamera"
                        class="grid size-10 cursor-pointer place-items-center rounded-lg text-umber transition-colors hover:bg-cream hover:text-ink">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M3.5 8.5A6.5 6.5 0 0 1 15 5.5M16.5 11.5A6.5 6.5 0 0 1 5 14.5"
                              stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        <path d="M14.5 2.5v3.2h-3.2M5.5 17.5v-3.2h3.2"
                              stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <button type="button" @click="selesai()" aria-label="Tutup"
                        class="grid size-10 cursor-pointer place-items-center rounded-lg text-umber transition-colors hover:bg-cream hover:text-ink">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="relative bg-navy-900">
            <video x-ref="video" class="aspect-[4/3] w-full object-cover" muted playsinline></video>

            {{-- Bingkai pemandu: memberi tahu ke mana barang diarahkan. --}}
            <div class="pointer-events-none absolute inset-0 grid place-items-center">
                <div class="h-24 w-4/5 rounded-xl border-2 border-white/80 shadow-[0_0_0_9999px_rgb(11_20_55/0.35)]"></div>
            </div>

            {{-- Pembaca cadangan ±1 MB: tanpa keterangan ini, 1–2 detik layar hitam
                 terlihat seperti aplikasi yang menggantung. --}}
            <div x-show="menyiapkan" x-cloak
                 class="absolute inset-0 grid place-items-center bg-navy-900/85 px-6 text-center">
                <div>
                    <div class="mx-auto size-7 animate-spin rounded-full border-2 border-white/25 border-t-white"></div>
                    <p class="mt-3 text-[0.8125rem] font-semibold text-white">Menyiapkan pemindai…</p>
                    <p class="mt-1 text-[0.75rem] text-white/70">Sekali saja, sesudah ini langsung siap.</p>
                </div>
            </div>
        </div>

        {{--
            Hasil pindaian terakhir, DI DALAM panel.

            Di mode berkelanjutan panelnya tidak menutup, jadi seluruh kabar tentang
            pindaian harus muat di sini: pemberitahuan biasa muncul di belakang panel dan
            tidak akan pernah terbaca. Yang mengisi slot ini yang tahu artinya — panel ini
            hanya menyediakan tempatnya.
        --}}
        @isset($umpan)
            {{ $umpan }}
        @endisset

        <div class="px-5 py-3 text-center text-[0.8125rem] text-umber">
            <p x-show="! berkelanjutan">Panel menutup sendiri begitu barcode terbaca.</p>

            <p x-show="berkelanjutan" x-cloak>
                Terus arahkan barang berikutnya — panel ini tidak menutup sendiri.
                <span class="tabular block text-umber-soft" x-show="jumlahPindai > 0" x-cloak
                      x-text="jumlahPindai + ' pindaian'"></span>
            </p>

            <p x-show="memakaiCadangan" x-cloak class="text-umber-soft">
                Peramban ini memakai pembaca cadangan — tahan barangnya sebentar lebih lama.
            </p>
        </div>

        <p x-show="galat" x-cloak class="border-t border-line px-5 py-3 text-center text-[0.8125rem] text-jingga-tua" x-text="galat"></p>

        {{-- Tombol keluar yang jelas untuk mode berkelanjutan: ikon X di pojok cukup untuk
             panel yang menutup sendiri, tapi panel yang menetap butuh jalan keluar yang
             terlihat tanpa dicari. --}}
        <div x-show="berkelanjutan" x-cloak class="border-t border-line px-4 py-3">
            <button type="button" @click="selesai()"
                    class="h-11 w-full cursor-pointer rounded-xl bg-ink text-[0.875rem] font-bold text-white transition hover:brightness-125">
                Selesai memindai
            </button>
        </div>
    </div>
</div>

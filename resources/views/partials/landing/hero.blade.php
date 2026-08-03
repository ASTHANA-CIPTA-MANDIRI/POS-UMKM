{{--
    Hero. Kolom kanan adalah panggung 3D CSS asli: wadah [data-scene] memberi
    perspective, [data-stage] diputar mengikuti kursor, dan tiap [data-layer]
    didorong ke kedalaman berbeda lewat data-depth (dipakai motion sebagai nilai
    translateZ).

    Komposisinya sengaja dirampingkan jadi SATU objek utama (terminal kasir) plus dua
    pendukung. Versi sebelumnya berisi lima kartu mengapung yang terbaca sebagai
    tumpukan acak, bukan sebagai satu ruang.

    Seluruh panggung ditandai aria-hidden karena isinya tiruan antarmuka — bagi
    pembaca layar itu hanya derau. Informasi yang sama sudah tersedia sebagai teks
    di kolom kiri dan di halaman berikutnya.
--}}
<section class="relative grain overflow-hidden pt-28 pb-16 sm:pt-32 sm:pb-20 lg:pt-40 lg:pb-24">
    {{-- Cahaya hangat di latar; menahan bidang krem agar tidak terasa kosong. --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 -right-32 size-[38rem] rounded-full bg-[radial-gradient(circle_at_center,rgb(117_81_255/0.34),transparent_64%)] blur-2xl"></div>
        <div class="absolute top-1/4 -left-44 size-[32rem] rounded-full bg-[radial-gradient(circle_at_center,rgb(66_42_251/0.13),transparent_66%)] blur-2xl"></div>
        {{-- Garis horizon tipis: memberi kesan ada permukaan tempat objek berdiri. --}}
        <div class="absolute inset-x-0 bottom-0 h-56 bg-gradient-to-b from-transparent to-cream-deep/70"></div>
    </div>

    <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-5 sm:px-6 lg:grid-cols-[1.02fr_1fr] lg:gap-12 lg:px-8">
        {{-- ── Kolom teks ── --}}
        <div class="max-w-xl">
            <p class="eyebrow flex items-center gap-2.5 text-terracotta">
                <span aria-hidden="true" class="inline-block size-1.5 rounded-full bg-terracotta"></span>
                POS untuk warung &amp; toko kecil
            </p>

            <h1 class="mt-5 text-[2.5rem] font-semibold text-balance text-ink sm:text-[3.25rem] lg:text-[3.75rem]">
                Kasir yang ngerti cara warung jualan.
            </h1>

            <p class="mt-5 text-[1.0625rem] text-pretty text-umber sm:text-lg">
                Buka bill per meja, catat kasbon langganan, dan tetap berjualan waktu internet mati.
                Dibuat untuk warteg, kelontong, depot air, dan laundry — bukan untuk resto mal.
            </p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                <a
                    href="{{ route('harga') }}"
                    data-tap
                    class="group inline-flex min-h-12 items-center justify-center gap-2.5 rounded-full bg-gradient-to-br from-terracotta-soft to-terracotta-deep px-7 text-[0.9375rem] font-semibold text-cream shadow-[0_12px_28px_-10px_rgb(66_42_251/0.65)] transition-shadow hover:shadow-[0_16px_34px_-10px_rgb(66_42_251/0.78)]"
                >
                    Coba 14 hari gratis
                    <svg viewBox="0 0 16 16" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" aria-hidden="true">
                        <path d="M2.5 8h11M9.5 4l4 4-4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </a>

                <a
                    href="{{ route('cara-jualan') }}"
                    data-tap
                    class="inline-flex min-h-12 items-center justify-center gap-2.5 rounded-full border border-line bg-paper/70 px-6 text-[0.9375rem] font-medium text-ink transition-colors hover:border-terracotta-soft/50 hover:bg-paper"
                >
                    <svg viewBox="0 0 16 16" class="size-4 text-terracotta" fill="none" aria-hidden="true">
                        <circle cx="8" cy="8" r="6.4" stroke="currentColor" stroke-width="1.5" />
                        <path d="M6.8 5.7l3.4 2.3-3.4 2.3V5.7Z" fill="currentColor" />
                    </svg>
                    Lihat cara kerjanya
                </a>
            </div>

            {{-- Fakta pendek, bukan klaim jumlah pengguna yang tidak bisa dibuktikan. --}}
            <dl class="mt-10 grid max-w-md grid-cols-3 gap-5 rule pt-6">
                @foreach ([
                    ['nilai' => 39, 'satuan' => 'rb/bln', 'label' => 'Mulai dari'],
                    ['nilai' => 14, 'satuan' => 'hari', 'label' => 'Coba gratis'],
                    ['nilai' => 3, 'satuan' => 'mode', 'label' => 'Cara jualan'],
                ] as $fakta)
                    <div>
                        <dd class="flex items-baseline gap-1.5">
                            <span data-hitung="{{ $fakta['nilai'] }}" class="tabular font-display text-3xl font-semibold text-ink">0</span>
                            <span class="font-mono text-[0.625rem] tracking-wider text-umber-soft uppercase">{{ $fakta['satuan'] }}</span>
                        </dd>
                        <dt class="mt-1 text-[0.8125rem] text-umber">{{ $fakta['label'] }}</dt>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- ── Panggung 3D ── --}}
        <div data-scene aria-hidden="true" class="relative mx-auto w-full max-w-[30rem] lg:max-w-none">
            <div data-stage class="relative aspect-square w-full sm:aspect-[3/2]">
                {{-- Paling belakang: cahaya hangat --}}
                <div data-layer data-depth="-140" class="inset-[8%] rounded-[40%] bg-[radial-gradient(circle_at_45%_38%,rgb(117_81_255/0.55),transparent_68%)] blur-2xl"></div>

                {{-- Objek utama: terminal kasir. Dipusatkan lewat flex, bukan
                     translate — motion memegang transform lapisan ini. --}}
                <div data-layer data-depth="0" class="inset-x-0 top-[6%] flex justify-center">
                    @include('partials.landing.pos-terminal')
                </div>

                {{-- Lencana mode offline: inti pembeda produk. --}}
                <div data-layer data-depth="175" class="top-0 left-[4%] rounded-full border border-amber-warm/30 bg-ink px-3 py-1.5 shadow-[0_12px_26px_-10px_rgb(11_20_55/0.75)] sm:left-[2%]">
                    <p class="flex items-center gap-1.5 font-mono text-[0.5625rem] tracking-[0.12em] text-amber-glow uppercase">
                        <span class="inline-block size-1.5 rounded-full bg-amber-glow"></span>
                        Offline &middot; 3 menunggu
                    </p>
                </div>

                {{-- Struk thermal dengan tepi bergerigi. Hanya dari 1024px ke atas:
                     di bawah itu panggung masih satu kolom dan struk menimpa terminal. --}}
                <div data-layer data-depth="135" class="hidden right-[2%] bottom-[5%] w-[27%] lg:block">
                    <div class="rounded-t-md bg-paper px-2.5 pt-3 pb-2 shadow-[0_16px_34px_-14px_rgb(11_20_55/0.4)]">
                        <p class="text-center font-mono text-[0.5rem] tracking-[0.1em] text-ink uppercase">Nampan</p>
                        <div class="mt-2 space-y-1">
                            @foreach ([['Nasi x2', '10.000'], ['Ayam x2', '24.000'], ['Es Teh x2', '8.000']] as [$item, $nilai])
                                <div class="flex justify-between gap-1">
                                    <span class="font-mono text-[0.5rem] text-umber">{{ $item }}</span>
                                    <span class="tabular font-mono text-[0.5rem] text-ink">{{ $nilai }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-1.5 flex justify-between gap-1 border-t border-dashed border-line pt-1.5">
                            <span class="font-mono text-[0.5rem] font-medium text-ink">TOTAL</span>
                            <span class="tabular font-mono text-[0.5rem] font-medium text-ink">42.000</span>
                        </div>
                    </div>
                    <svg viewBox="0 0 100 6" preserveAspectRatio="none" class="block h-1.5 w-full drop-shadow-[0_6px_10px_rgb(11_20_55/0.16)]" fill="#FFFFFF" aria-hidden="true">
                        <path d="M0 0h100v1L95 6 90 1 85 6 80 1 75 6 70 1 65 6 60 1 55 6 50 1 45 6 40 1 35 6 30 1 25 6 20 1 15 6 10 1 5 6 0 1Z" />
                    </svg>
                </div>
            </div>
        </div>
    </div>
</section>

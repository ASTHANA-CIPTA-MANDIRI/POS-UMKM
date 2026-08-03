@extends('layouts.publik')

@section('judul', 'Nampan')
@section('deskripsi', 'Aplikasi kasir untuk warteg, kelontong, depot air, dan laundry. Buka bill per meja, kasbon langganan, stok bahan baku otomatis, dan tetap jalan saat internet mati. Mulai Rp 39.000/bulan.')

@section('konten')
    @include('partials.landing.hero')

    {{-- Deretan jenis usaha --}}
    <section aria-label="Jenis usaha yang didukung" class="border-y border-line-soft bg-cream-deep/60 py-5">
        <div class="mask-fade-x mx-auto flex max-w-7xl items-center gap-8 overflow-x-auto px-5 sm:gap-12 sm:px-6 lg:justify-center lg:overflow-visible lg:px-8 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            @foreach (['Warteg', 'Rumah makan', 'Toko kelontong', 'Depot air isi ulang', 'Laundry kiloan', 'Katering'] as $usaha)
                <span class="eyebrow shrink-0 whitespace-nowrap text-umber-soft">{{ $usaha }}</span>
            @endforeach
        </div>
    </section>

    {{-- Ringkasan tiga mode. Versi lengkapnya ada di halaman Cara Jualan. --}}
    <section class="relative grain py-20 sm:py-28">
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
            <div class="max-w-2xl" data-reveal>
                <p class="eyebrow text-terracotta">Cara jualan</p>
                <h2 class="mt-5 text-[2rem] font-semibold text-balance text-ink sm:text-[2.75rem]">
                    Tiga cara jualan, satu kasir.
                </h2>
                <p class="mt-5 text-[1.0625rem] text-pretty text-umber">
                    Warung yang juga jual sembako tidak harus memilih salah satu. Mode dipilih kasir tiap kali
                    mulai transaksi, bukan dikunci satu untuk seluruh outlet.
                </p>
            </div>

            <div class="mt-14 grid gap-5 lg:grid-cols-3">
                @foreach ([
                    ['01', 'Bayar langsung', 'Kelontong, retail', 'Ambil barang, scan atau tap, bayar, struk cetak. Selesai saat itu juga.'],
                    ['02', 'Buka bill dulu', 'Warteg, rumah makan', 'Pesanan masuk bertahap ke satu bill per meja. Struk final saat pelanggan pulang.'],
                    ['03', 'Titip &amp; ambil', 'Depot air, laundry', 'Barang dititip, nota titipan tercetak, status berjalan sampai diambil.'],
                ] as [$no, $judul, $untuk, $isi])
                    <article data-tilt-in class="rounded-2xl border border-line-soft bg-paper p-6 transition-shadow duration-300 hover:shadow-[0_18px_44px_-22px_rgb(66_42_251/0.28)] sm:p-7">
                        <div class="flex items-baseline justify-between">
                            <span class="tabular font-mono text-xs tracking-[0.14em] text-terracotta-soft">{{ $no }}</span>
                            <span class="eyebrow text-umber-soft">{{ $untuk }}</span>
                        </div>
                        <h3 class="mt-5 text-xl font-semibold text-ink">{!! $judul !!}</h3>
                        <p class="mt-3 text-[0.9375rem] text-umber">{{ $isi }}</p>
                    </article>
                @endforeach
            </div>

            <div class="mt-10" data-reveal>
                <a
                    href="{{ route('cara-jualan') }}"
                    class="group inline-flex items-center gap-2 text-[0.9375rem] font-medium text-terracotta transition-colors hover:text-terracotta-deep"
                >
                    Lihat detail tiap mode
                    <svg viewBox="0 0 16 16" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" aria-hidden="true">
                        <path d="M2.5 8h11M9.5 4l4 4-4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </a>
            </div>
        </div>
    </section>

    {{-- Pengantar mode offline; detailnya di halaman tersendiri. --}}
    <section class="relative grain overflow-hidden bg-ink py-20 text-cream sm:py-24">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-20 right-1/4 size-[28rem] rounded-full bg-[radial-gradient(circle_at_center,rgb(117_81_255/0.20),transparent_65%)] blur-3xl"></div>
        </div>

        <div class="relative mx-auto max-w-3xl px-5 text-center sm:px-6" data-reveal>
            <p class="eyebrow text-amber-glow">Saat internet mati</p>
            <h2 class="mt-5 text-[2rem] font-semibold text-balance text-cream sm:text-[2.75rem]">
                Listrik mati. Kasir tetap jalan.
            </h2>
            <p class="mx-auto mt-5 max-w-xl text-[1.0625rem] text-pretty text-cream/70">
                Transaksi disimpan dulu di perangkat, lalu dikirim sendiri begitu jaringan kembali.
                Dikirim ulang berkali-kali pun tidak akan tercatat dobel.
            </p>
            <a
                href="{{ route('mode-offline') }}"
                class="group mt-8 inline-flex items-center gap-2 text-[0.9375rem] font-medium text-amber-glow transition-colors hover:text-cream"
            >
                Bagaimana cara kerjanya
                <svg viewBox="0 0 16 16" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" aria-hidden="true">
                    <path d="M2.5 8h11M9.5 4l4 4-4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>
        </div>
    </section>
@endsection

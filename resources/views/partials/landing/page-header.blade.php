{{--
    Kepala halaman untuk halaman dalam. Sejak landing dipecah menjadi halaman
    terpisah, tiap halaman butuh pembuka sendiri — pengunjung bisa mendarat di sini
    langsung dari mesin pencari, tanpa pernah melihat beranda.

    @param string $eyebrow
    @param string $judul
    @param string|null $intro
--}}
<section class="relative grain overflow-hidden border-b border-line-soft pt-28 pb-14 sm:pt-36 sm:pb-20">
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
        <div class="absolute -top-40 right-0 size-[30rem] rounded-full bg-[radial-gradient(circle_at_center,rgb(117_81_255/0.24),transparent_66%)] blur-2xl"></div>
    </div>

    <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
        <div class="max-w-3xl" data-reveal>
            <p class="eyebrow text-terracotta">{{ $eyebrow }}</p>
            <h1 class="mt-5 text-[2.25rem] font-semibold text-balance text-ink sm:text-[3rem]">
                {!! $judul !!}
            </h1>
            @isset($intro)
                <p class="mt-6 max-w-2xl text-[1.0625rem] text-pretty text-umber sm:text-lg">{{ $intro }}</p>
            @endisset
        </div>
    </div>
</section>

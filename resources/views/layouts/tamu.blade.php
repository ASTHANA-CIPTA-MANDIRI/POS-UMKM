<!DOCTYPE html>
<html lang="id" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F4F7FE">
    <title>@yield('judul', 'Masuk') &mdash; Nampan</title>
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400..700;1,9..40,400&family=Poppins:wght@500;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    Kerangka halaman masuk mengikuti DefaultAuthLayout template: form di kolom kiri
    dengan lebar maksimum 420px, dan panel gradien di kanan selebar 49vw (44vw pada
    layar sangat lebar) yang sudut kiri-bawahnya membulat ekstrem — 120px di lg,
    200px di xl.

    Panel kanan disembunyikan di bawah 1024px karena di layar sempit ia hanya
    mendorong form ke bawah lipatan.
--}}
<body class="app-font min-h-dvh overflow-x-hidden bg-cream">
    <div class="flex min-h-dvh">
        <div class="flex w-full flex-col px-5 py-8 sm:px-10 lg:w-[51vw] lg:pl-[4.375rem] 2xl:w-[56vw]">
            <a
                href="{{ route('beranda') }}"
                class="inline-flex w-fit items-center gap-2 text-[0.8125rem] font-medium text-umber transition-colors hover:text-terracotta"
            >
                <svg viewBox="0 0 16 16" class="size-3.5" fill="none" aria-hidden="true">
                    <path d="M13.5 8h-11M6.5 4l-4 4 4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Kembali ke halaman utama
            </a>

            <main class="flex flex-1 items-start py-10 lg:pt-[10vh]">
                <div class="w-full max-w-[26.25rem]">
                    {{ $slot }}
                </div>
            </main>

            <p class="font-mono text-[0.6875rem] tracking-wider text-umber-soft">
                &copy; {{ now()->year }} Nampan &middot; Asthana Cipta Mandiri
            </p>
        </div>

        {{-- Panel gradien kanan --}}
        <div class="relative hidden overflow-hidden rounded-bl-[120px] bg-gradient-to-br from-terracotta-soft via-terracotta to-terracotta-deep lg:block lg:w-[49vw] xl:rounded-bl-[200px] 2xl:w-[44vw]">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
                <div class="absolute -top-24 -right-16 size-96 rounded-full bg-[radial-gradient(circle_at_center,rgb(117_81_255/0.45),transparent_65%)] blur-2xl"></div>
                <div class="absolute -bottom-32 -left-20 size-96 rounded-full bg-[radial-gradient(circle_at_center,rgb(11_20_55/0.30),transparent_66%)] blur-2xl"></div>
            </div>

            <div class="relative flex h-full flex-col items-center justify-center px-12 text-center">
                <span class="grid size-16 place-items-center rounded-3xl bg-cream/15 backdrop-blur-sm">
                    <svg viewBox="0 0 24 24" class="size-8" fill="none" aria-hidden="true">
                        <path d="M4 8.4 12 4l8 4.4-8 4.4-8-4.4Z" fill="#FFFFFF" fill-opacity="0.95" />
                        <path d="M4 12.6 12 17l8-4.4" stroke="#FFFFFF" stroke-opacity="0.7" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M4 16.6 12 21l8-4.4" stroke="#FFFFFF" stroke-opacity="0.4" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>

                <p class="mt-7 text-[1.75rem] leading-tight font-bold text-cream">Nampan</p>
                <p class="mt-3 max-w-sm text-[0.9375rem] text-cream/80">
                    Kasir untuk warteg, kelontong, depot air, dan laundry. Tetap jalan
                    waktu internet mati.
                </p>

                <dl class="mt-10 grid w-full max-w-sm grid-cols-3 gap-4 border-t border-cream/20 pt-6">
                    @foreach ([['3', 'mode jualan'], ['14', 'hari coba'], ['39rb', 'per bulan']] as [$angka, $label])
                        <div>
                            <dd class="tabular text-[1.25rem] font-bold text-cream">{{ $angka }}</dd>
                            <dt class="mt-0.5 text-[0.6875rem] text-cream/70">{{ $label }}</dt>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>
</body>
</html>

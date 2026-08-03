{{-- Penutup yang dipakai di semua halaman publik. --}}
<section class="relative grain border-t border-line-soft py-20 sm:py-28">
    <div class="relative mx-auto max-w-3xl px-5 text-center sm:px-6" data-reveal>
        <h2 class="text-[2rem] font-semibold text-balance text-ink sm:text-[2.75rem]">
            Dari daftar sampai transaksi pertama, di bawah 15 menit.
        </h2>
        <p class="mx-auto mt-5 max-w-xl text-[1.0625rem] text-pretty text-umber">
            Tanpa pelatihan khusus. Isi profil warung, tambah menu atau barang, buat akun kasir — sudah bisa jualan.
        </p>
        <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a
                href="{{ route('harga') }}"
                data-tap
                class="group inline-flex min-h-12 w-full items-center justify-center gap-2.5 rounded-full bg-gradient-to-br from-terracotta-soft to-terracotta-deep px-7 text-[0.9375rem] font-semibold text-cream shadow-[0_12px_28px_-10px_rgb(66_42_251/0.65)] sm:w-auto"
            >
                Coba 14 hari gratis
                <svg viewBox="0 0 16 16" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" aria-hidden="true">
                    <path d="M2.5 8h11M9.5 4l4 4-4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>
            <a
                href="{{ route('cara-jualan') }}"
                data-tap
                class="inline-flex min-h-12 w-full items-center justify-center rounded-full border border-line bg-paper px-6 text-[0.9375rem] font-medium text-ink transition-colors hover:border-terracotta-soft/50 sm:w-auto"
            >
                Lihat cara kerjanya
            </a>
        </div>
    </div>
</section>

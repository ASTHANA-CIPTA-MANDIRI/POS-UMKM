<div>
    <h1 class="mb-2.5 text-[2.25rem] leading-tight font-bold text-ink">Masuk</h1>
    <p class="mb-9 ml-1 text-[1rem] text-umber">
        Masukkan email dan password untuk mengelola usaha Anda.
    </p>

    <form wire:submit="masuk" class="mt-8 space-y-5">
        <div>
            <label for="email" class="ml-1.5 block text-[0.875rem] font-medium text-ink">
                Email <span class="text-terracotta">*</span>
            </label>
            <input
                id="email"
                type="email"
                autocomplete="email"
                required
                wire:model="email"
                placeholder="nama@usaha.test"
                class="mt-2 block h-12 w-full rounded-xl border border-line bg-transparent p-3 text-[0.875rem] text-ink transition-colors placeholder:text-umber-soft focus:border-terracotta-soft focus:outline-none"
            >
            @error('email')
                {{-- Error ditaruh di bawah field terkait, bukan dikumpulkan di atas. --}}
                <p class="mt-2 text-[0.8125rem] text-terracotta" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="ml-1.5 block text-[0.875rem] font-medium text-ink">
                Password <span class="text-terracotta">*</span>
            </label>
            <input
                id="password"
                type="password"
                autocomplete="current-password"
                required
                wire:model="password"
                placeholder="Minimal 8 karakter"
                class="mt-2 block h-12 w-full rounded-xl border border-line bg-transparent p-3 text-[0.875rem] text-ink transition-colors placeholder:text-umber-soft focus:border-terracotta-soft focus:outline-none"
            >
            @error('password')
                <p class="mt-2 text-[0.8125rem] text-terracotta" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center px-2">
            <label class="flex cursor-pointer items-center gap-2.5 text-[0.875rem] font-medium text-ink">
                <input type="checkbox" wire:model="ingatSaya" class="size-4 rounded-md border-line text-terracotta focus:ring-terracotta">
                Ingat saya
            </label>

        </div>

        <button
            type="submit"
            data-tap
            wire:loading.attr="disabled"
            class="inline-flex w-full cursor-pointer items-center justify-center rounded-xl bg-terracotta py-3 text-[1rem] font-medium text-cream transition-colors hover:bg-terracotta-deep active:bg-terracotta-deep disabled:opacity-60"
        >
            {{-- Tombol dinonaktifkan selama proses; tanpa ini submit bisa ganda. --}}
            <span wire:loading.remove wire:target="masuk">Masuk</span>
            <span wire:loading wire:target="masuk">Memeriksa…</span>
        </button>
    </form>

    <div class="mt-7 rounded-2xl bg-cream-deep/50 px-5 py-4">
        <p class="text-[0.8125rem] text-umber">
            Kasir atau dapur?
            <a href="{{ route('masuk.kasir') }}" wire:navigate class="font-bold text-terracotta transition-colors hover:text-terracotta-deep">
                Masuk lewat halaman kasir
            </a>
        </p>
    </div>
</div>

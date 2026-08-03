# Menjalankan laporan progres secara otomatis

Tiga pilihan, dari yang paling tidak bisa diandalkan sampai yang paling.

## 1. cron di laptop — TIDAK jalan kalau laptop mati atau tidur

`cron` hanya menjalankan tugas kalau mesinnya menyala dan terjaga. Jadwal yang terlewat
karena laptop tidur **tidak** dikejar setelah bangun; jadwal saat laptop mati hilang
begitu saja. Telegram tidak mengubah apa pun soal ini — Telegram cuma kurir, pengirimnya
tetap harus hidup.

```bash
crontab -e
0 18 * * * cd "/Users/bertojunikrisnanto/Documents/MY WORK/htdocs/POS-UMKM" && /usr/bin/php artisan lapor:telegram --kirim >> storage/logs/lapor.log 2>&1
```

## 2. launchd (macOS) — mengejar jadwal yang terlewat saat bangun

Di macOS, `launchd` lebih baik daripada `cron` untuk kasus ini: kalau jadwalnya terlewat
karena Mac tidur, tugasnya dijalankan **begitu Mac bangun**. Tetap tidak jalan kalau Mac
dimatikan.

Simpan sebagai `~/Library/LaunchAgents/id.nampan.lapor.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>id.nampan.lapor</string>
  <key>WorkingDirectory</key><string>/Users/bertojunikrisnanto/Documents/MY WORK/htdocs/POS-UMKM</string>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/bin/php</string>
    <string>artisan</string>
    <string>lapor:telegram</string>
    <string>--kirim</string>
  </array>
  <key>StartCalendarInterval</key>
  <dict><key>Hour</key><integer>18</integer><key>Minute</key><integer>0</integer></dict>
  <key>StandardOutPath</key><string>/tmp/nampan-lapor.log</string>
  <key>StandardErrorPath</key><string>/tmp/nampan-lapor.err</string>
</dict>
</plist>
```

```bash
launchctl load ~/Library/LaunchAgents/id.nampan.lapor.plist
launchctl start id.nampan.lapor    # uji sekarang, tanpa menunggu jam 18
```

Kalau mau Mac-nya dibangunkan sendiri (harus tersambung daya):

```bash
sudo pmset repeat wake MTWRFSU 17:55:00
```

## 3. GitHub Actions — jalan walau laptop mati

Ini satu-satunya pilihan yang benar-benar tidak bergantung pada laptop. Alurnya sudah ada
di `.github/workflows/lapor-progres.yml`.

Bisa begini karena `lapor:telegram` **tidak menyentuh database MySQL pengembangan**:
sumbernya `docs/RENCANA.md`, suite uji (sqlite di memori), dan riwayat git — semuanya di
dalam repo. Sudah diuji dengan database dev sengaja dibuat tidak terjangkau, dan
laporannya tetap tersusun lengkap.

Yang harus dilakukan sekali:

1. Komit & dorong repo ke GitHub.
2. Settings → Secrets and variables → Actions → tambahkan `TELEGRAM_BOT_TOKEN` dan
   `TELEGRAM_CHAT_ID`.
3. Tab Actions → "Laporan progres" → **Run workflow** untuk menguji sekarang.

Batasnya: jadwal memakai UTC (11:00 UTC = 18:00 WIB), bisa tertunda beberapa menit, dan
GitHub menonaktifkan jadwal otomatis setelah repo 60 hari tanpa aktivitas.

## Kalau nanti sudah ada server

Sesudah aplikasi ini dideploy (yang memang wajib, karena kamera barcode menuntut HTTPS),
cron di server itu pilihan paling sederhana — servernya toh menyala 24 jam.

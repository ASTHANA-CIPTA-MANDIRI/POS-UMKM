<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Dijalankan pagi hari sebelum warung mulai buka. Kalau dijalankan tengah hari,
 * merchant yang trial-nya habis bisa kehilangan kemampuan bertransaksi di tengah
 * jam ramai — dan itu memutus penjualannya, bukan cuma mengganggu.
 */
Schedule::command('nampan:periksa-trial')
    ->dailyAt('05:00')
    ->timezone('Asia/Jakarta')
    ->onOneServer();

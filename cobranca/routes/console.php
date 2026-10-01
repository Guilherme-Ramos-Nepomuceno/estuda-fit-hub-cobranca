<?php

use Illuminate\Support\Facades\Schedule;

// Em produção, o agendador roda `php artisan schedule:run` a cada minuto.
Schedule::command('faturas:marcar-vencidas')->dailyAt('00:05')->timezone(config('cobranca.fuso'));

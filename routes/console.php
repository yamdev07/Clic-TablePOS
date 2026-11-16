<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('platform:create-admin {email} {--name=Super Admin} {--password=}', function (string $email) {
    $password = $this->option('password') ?: $this->secret('Mot de passe (8 caractères min.)');

    if (strlen((string) $password) < 8) {
        $this->error('Le mot de passe doit contenir au moins 8 caractères.');

        return 1;
    }

    $user = User::updateOrCreate(['email' => $email], [
        'id' => User::where('email', $email)->value('id') ?? (string) Str::uuid(),
        'restaurant_id' => null,
        'name' => $this->option('name'),
        'password' => Hash::make($password),
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    $this->info("Super admin prêt : {$user->email}");

    return 0;
})->purpose('Créer ou promouvoir un administrateur de la plateforme SaaS');

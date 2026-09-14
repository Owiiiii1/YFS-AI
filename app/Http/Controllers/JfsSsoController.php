<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class JfsSsoController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $ticket = trim((string) $request->query('ticket', ''));
        if ($ticket === '') {
            return redirect()->route('login');
        }

        $ticketKey = 'yfs_ai_sso_used_'.sha1($ticket);
        if (Cache::has($ticketKey)) {
            return redirect()->route('login');
        }

        $token = (string) config('services.young_fashion_show.incoming_api_token');
        $endpoint = (string) config('services.young_fashion_show.sso_verify_endpoint');
        if ($token === '' || $endpoint === '') {
            return redirect()->route('login');
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->withHeaders([
                'X-Incoming-Api-Token' => $token,
                'Content-Type' => 'application/json',
            ])
            ->timeout(15)
            ->post($endpoint, [
                'ticket' => $ticket,
            ]);

        if (! $response->ok()) {
            return redirect()->route('login');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return redirect()->route('login');
        }

        $isAllowed = (bool) (($payload['allowed'] ?? null) ?? ($payload['ok'] ?? false));
        if (! $isAllowed) {
            return redirect()->route('login');
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return redirect()->route('login');
        }

        $name = trim((string) ($payload['name'] ?? Str::headline(Str::before($email, '@'))));
        if ($name === '') {
            $name = 'AI Assistant User';
        }

        $user = User::query()->firstWhere('email', $email);
        if (! $user) {
            $user = new User;
            $user->email = $email;
            $user->name = $name;
            $user->password = Str::random(40);
            $user->save();
        }

        Cache::put($ticketKey, true, now()->addMinutes(5));

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}

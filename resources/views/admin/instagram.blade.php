@php
    $pageTitle = __('client.instagram.title');
    $status = $overview['status'];
    $flow = $overview['oauth_flow'] ?? config('services.meta.oauth_flow', 'instagram_login');
    $isInstagramFlow = $flow === 'instagram_login';
    $tokenStatus = $overview['token_status'] ?? 'missing';
    $tokenStatusClass = match ($tokenStatus) {
        'ok' => 'success',
        'refresh_due' => 'warn',
        'failed', 'expired' => 'warn',
        default => 'gray',
    };
    $statusClass = match ($status) {
        'connected' => 'success',
        'failed', 'disabled', 'needs_reconnect' => 'warn',
        default => 'gray',
    };
@endphp

@extends('admin.layout-minimal', ['title' => $pageTitle])

@section('content')
    @if (session('instagram_status'))
        <div class="cp-widget" style="margin-bottom:10px;">
            <p style="margin:0; color:#166534; font-size:13px;">{{ session('instagram_status') }}</p>
        </div>
    @endif

    @if ($errors->instagramSettings->any())
        <div class="cp-widget" style="margin-bottom:10px;">
            <p style="margin:0; color:#b91c1c; font-size:13px;">{{ $errors->instagramSettings->first() }}</p>
        </div>
    @endif

    <div class="cp-grid" style="margin-top:0;">
        <div class="cp-widget">
            <h3>{{ __('client.instagram.overview_title') }}</h3>
            <p style="margin: 8px 0 0;">
                <span class="cp-badge {{ $statusClass }}">{{ __("client.statuses.{$status}") }}</span>
            </p>
            <div class="cp-empty" style="margin:8px 0 0; line-height:1.55;">
                <div>{{ __('client.instagram.connected_username') }}: <strong>{{ $overview['instagram_username'] ?: '-' }}</strong></div>
                @if (! $isInstagramFlow)
                    <div>{{ __('client.instagram.connected_page') }}: <strong>{{ $overview['facebook_page_name'] ?: '-' }}</strong></div>
                    <div>{{ __('client.instagram.connected_page_id') }}: <strong>{{ $overview['facebook_page_id'] ?: '-' }}</strong></div>
                @endif
                <div>{{ __('client.instagram.last_success') }}: <strong>{{ $overview['last_success']?->format('Y-m-d H:i') ?: '-' }}</strong></div>
                <div>
                    {{ __('client.instagram.token_status') }}:
                    <span class="cp-badge {{ $tokenStatusClass }}">{{ __('client.instagram.token_states.' . $tokenStatus) }}</span>
                </div>
                <div>{{ __('client.instagram.token_valid_until') }}: <strong>{{ $overview['token_expires_at']?->format('Y-m-d H:i') ?: '-' }}</strong></div>
                <div>{{ __('client.instagram.token_last_refreshed') }}: <strong>{{ $overview['token_refreshed_at']?->format('Y-m-d H:i') ?: '-' }}</strong></div>
            </div>
            @if ($isInstagramFlow)
                <p class="cp-empty" style="margin:8px 0 0;">{{ __('client.instagram.instagram_login_page_optional') }}</p>
            @endif
            @if ($overview['last_error'])
                <p class="cp-empty" style="margin:8px 0 0; color:#b91c1c;">{{ $overview['last_error'] }}</p>
            @endif
            @if (!empty($overview['outbound_warning']))
                <p class="cp-empty" style="margin:8px 0 0; color:#b45309;">{{ $overview['outbound_warning'] }}</p>
            @endif
        </div>
        <div class="cp-widget">
            <h3>{{ __('client.instagram.bot_status_title') }}</h3>
            <div class="cp-empty" style="margin:0; line-height:1.55;">
                <div>
                    {{ __('client.instagram.bot_enabled') }}:
                    <strong>{{ $overview['bot_enabled'] ? __('client.instagram.setting_enabled') : __('client.instagram.setting_disabled') }}</strong>
                </div>
                <div>
                    {{ __('client.instagram.auto_reply_enabled') }}:
                    <strong>{{ $overview['auto_reply_enabled'] ? __('client.instagram.setting_enabled') : __('client.instagram.setting_disabled') }}</strong>
                </div>
                <div>
                    {{ __('client.instagram.handoff_stops_ai') }}:
                    <strong>{{ $overview['handoff_stops_ai'] ? __('client.instagram.setting_enabled') : __('client.instagram.setting_disabled') }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="cp-table-wrap" style="padding:12px; margin-top:10px;">
        <h3 style="margin:0 0 10px;">{{ __('client.instagram.primary_actions') }}</h3>
        @if ($status === 'connected' && !in_array($tokenStatus, ['expired', 'failed'], true))
            <div class="cp-meta-connect-spotlight">
                <a class="cp-btn cp-btn-primary cp-meta-connect-spotlight-btn" href="{{ route('instagram.meta.redirect') }}">
                    {{ __('client.instagram.reconnect_meta') }}
                </a>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:center; margin-top:10px;">
                <form method="post" action="{{ route('instagram.disconnect') }}">
                    @csrf
                    <button class="cp-btn cp-btn-secondary" type="submit">{{ __('client.instagram.disconnect') }}</button>
                </form>
                <form method="post" action="{{ route('instagram.test') }}">
                    @csrf
                    <button class="cp-btn cp-btn-secondary" type="submit">{{ __('client.instagram.test_connection') }}</button>
                </form>
            </div>
        @else
            <div class="cp-meta-connect-spotlight">
                <a class="cp-btn cp-btn-primary cp-meta-connect-spotlight-btn" href="{{ route('instagram.meta.redirect') }}">
                    {{ __('client.instagram.connect_meta') }}
                </a>
            </div>
        @endif
    </div>

    <form method="post" action="{{ route('instagram.update') }}" class="cp-table-wrap" style="padding:12px; margin-top:10px;">
        @csrf
        <h3 style="margin:0 0 10px;">{{ __('client.instagram.safety_settings_title') }}</h3>
        <div class="cp-grid">
            <label class="cp-remember-row" style="margin:0;">
                <input type="hidden" name="bot_enabled" value="0">
                <input type="checkbox" name="bot_enabled" value="1" {{ old('bot_enabled', $overview['bot_enabled']) ? 'checked' : '' }}>
                <span>{{ __('client.instagram.bot_enabled') }}</span>
            </label>
            <label class="cp-remember-row" style="margin:0;">
                <input type="hidden" name="auto_reply_enabled" value="0">
                <input type="checkbox" name="auto_reply_enabled" value="1" {{ old('auto_reply_enabled', $overview['auto_reply_enabled']) ? 'checked' : '' }}>
                <span>{{ __('client.instagram.auto_reply_enabled') }}</span>
            </label>
            <label class="cp-remember-row" style="margin:0;">
                <input type="hidden" name="handoff_stops_ai" value="0">
                <input type="checkbox" name="handoff_stops_ai" value="1" {{ old('handoff_stops_ai', $overview['handoff_stops_ai']) ? 'checked' : '' }}>
                <span>{{ __('client.instagram.handoff_stops_ai') }}</span>
            </label>
            <div class="cp-empty" style="margin:0; grid-column: 1 / -1; line-height:1.45;">
                {{ __('client.instagram.handoff_stops_ai_hint') }}
            </div>
            <div>
                <label style="display:block; font-size:12px; margin-bottom:6px;">{{ __('client.instagram.max_ai_replies_per_conversation') }}</label>
                <input class="cp-input" type="number" min="1" name="max_ai_replies_per_conversation" value="{{ old('max_ai_replies_per_conversation', data_get($account?->settings, 'max_ai_replies_per_conversation')) }}">
            </div>
            <div>
                <label style="display:block; font-size:12px; margin-bottom:6px;">{{ __('client.instagram.max_ai_replies_per_day') }}</label>
                <input class="cp-input" type="number" min="1" name="max_ai_replies_per_day" value="{{ old('max_ai_replies_per_day', data_get($account?->settings, 'max_ai_replies_per_day')) }}">
            </div>
        </div>
        <div style="margin-top:12px;">
            <button class="cp-btn cp-btn-primary" style="width:auto; height:38px; padding:0 14px;" type="submit">{{ __('client.instagram.save_settings') }}</button>
        </div>
    </form>
@endsection

@if (filled($error))
    <div class="banner banner-error" role="alert">
        {{ $error }}
        {{-- Set only while APP_DEBUG is on: what AUB actually answered, for whoever is testing. --}}
        @if (filled($debug ?? null))
            <span class="banner-debug"><b>Debug</b>{{ $debug }}</span>
        @endif
    </div>
@endif

@if (filled($notice))
    <div class="banner banner-notice" role="status">{{ $notice }}</div>
@endif

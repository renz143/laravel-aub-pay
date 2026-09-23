@if (filled($error))
    <div class="banner banner-error" role="alert">{{ $error }}</div>
@endif

@if (filled($notice))
    <div class="banner banner-notice" role="status">{{ $notice }}</div>
@endif

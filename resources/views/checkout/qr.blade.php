@extends('aub-pay::checkout.layout')

@section('title', 'Scan to pay')

@php($seconds = $attempt->secondsRemaining())

@section('panel')
    <section class="panel qr-panel" aria-labelledby="qr-title">
        <a class="back-link" href="{{ $urls->session($session) }}">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Choose another payment method
        </a>

        <h2 id="qr-title" class="qr-title">Scan @include('aub-pay::checkout.partials.qrph-mark') code to pay</h2>
        <p class="qr-lead">Open your banking or e-wallet app and scan the code below.</p>

        {{-- AUB's own image of the code (code_img_url). The countdown adds `is-expired` when it lapses;
             the server has already added it if the page was opened after that. --}}
        <div class="qr-frame {{ $seconds > 0 ? '' : 'is-expired' }}" data-qr>
            @if (filled($attempt->qr_image_url))
                <img src="{{ $attempt->qr_image_url }}" alt="QR Ph code for {{ $session->money($attempt->amount) }}" width="232" height="232">
            @else
                <p class="qr-missing">This QR code could not be displayed. Please choose another payment method.</p>
            @endif

            <div class="qr-expired">
                <p>This QR code has expired.</p>
                <form method="POST" action="{{ $urls->session($session, 'pay') }}">
                    @csrf
                    <input type="hidden" name="method" value="{{ $method->key() }}">
                    <button type="submit" class="cta">Generate a new QR code</button>
                </form>
            </div>
        </div>

        <dl class="qr-facts">
            <div class="row"><dt>Amount to pay</dt><dd>{{ $session->money($attempt->amount) }}</dd></div>
            <div class="row"><dt>Expires in</dt><dd><span data-countdown="{{ $seconds }}">{{ intdiv($seconds, 60) }}:{{ str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) }}</span></dd></div>
        </dl>

        @if (filled($attempt->qr_image_url) && $seconds > 0)
            <a class="cta cta-secondary" href="{{ $urls->session($session, 'download') }}" data-download>Download QR code</a>
        @endif

        <ol class="steps">
            <li>Open your banking or e-wallet app.</li>
            <li>Scan this code, or upload it after downloading.</li>
            <li>Confirm the payment. This page updates on its own.</li>
        </ol>

        <p class="waiting" role="status" aria-live="polite" data-waiting><span class="pulse" aria-hidden="true"></span><span>Waiting for your payment…</span></p>
    </section>
@endsection

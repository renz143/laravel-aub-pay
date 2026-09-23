@extends('aub-pay::checkout.layout')

@section('title', 'Payment successful')
@section('summary-title', 'Order Summary')

@section('panel')
    <section class="panel result" aria-labelledby="result-title">
        <span class="result-icon result-ok" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </span>

        <h2 id="result-title">Payment successful</h2>

        {{-- Philippine time: AUB is a Philippine acquirer, and so is everyone paying through it. --}}
        <p class="result-text">
            {{ $session->money($session->amount) }} paid{{ filled($method) ? ' via ' . $method : '' }}{{ $session->paid_at ? ' on ' . $session->paid_at->copy()->timezone('Asia/Manila')->format('j M Y, g:i A') : '' }}.
        </p>

        <a class="cta" href="{{ $session->success_url }}">Continue to {{ $merchant['name'] }}</a>
    </section>
@endsection

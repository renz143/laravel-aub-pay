@extends('aub-pay::checkout.layout')

@section('title', 'Checkout expired')

@section('panel')
    <section class="panel result" aria-labelledby="result-title">
        <span class="result-icon result-muted" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        </span>

        <h2 id="result-title">This checkout has expired</h2>
        <p class="result-text">It is no longer accepting payments. Head back to {{ $merchant['name'] }} to start a new one.</p>

        <a class="cta" href="{{ $session->cancel_url }}">Back to {{ $merchant['name'] }}</a>
    </section>
@endsection

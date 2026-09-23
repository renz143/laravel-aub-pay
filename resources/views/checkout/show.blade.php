@extends('aub-pay::checkout.layout')

@section('title', 'Complete your order')

@section('panel')
    <section class="panel" aria-labelledby="method-title">
        <h2 id="method-title">Payment Method</h2>

        @include('aub-pay::checkout.partials.banners')

        {{-- Continue starts enabled and the script disables it until a method is chosen, so the form
             still works without JavaScript — the server answers an empty choice with a message. --}}
        <form method="POST" action="{{ $urls->session($session, 'pay') }}" data-methods>
            @csrf

            <div class="methods" role="radiogroup" aria-labelledby="method-title">
                @foreach ($methods as $key => $method)
                    <label class="method">
                        <input type="radio" name="method" value="{{ $key }}" @checked(old('method') === $key)>

                        @if ($key === 'qrph')
                            <span class="method-qr">Scan @include('aub-pay::checkout.partials.qrph-mark') code to pay</span>
                        @elseif ($key === 'card')
                            <span>Card</span>
                            <span class="marks" role="img" aria-label="Mastercard and Visa">
                                <svg class="mc" viewBox="0 0 24 15" aria-hidden="true"><circle cx="7.5" cy="7.5" r="7.5" fill="#eb001b"/><circle cx="16.5" cy="7.5" r="7.5" fill="#f79e1b"/><path d="M12 1.5a7.5 7.5 0 0 1 0 12 7.5 7.5 0 0 1 0-12z" fill="#ff5f00"/></svg>
                                <span class="visa" aria-hidden="true">VISA</span>
                            </span>
                        @else
                            <span>{{ $method->label() }}</span>
                        @endif
                    </label>
                @endforeach
            </div>

            <button type="submit" class="cta">Continue</button>
        </form>

        @if (filled($merchant['privacy_url']))
            <p class="fine">
                By completing your purchase, you agree to {{ $merchant['name'] }}'s
                <a href="{{ $merchant['privacy_url'] }}" target="_blank" rel="noopener">Privacy Policy</a>.
            </p>
        @endif
    </section>
@endsection

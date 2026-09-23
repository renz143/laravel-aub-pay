{{-- The order, as the merchant described it. Amounts are unit prices; the total is the session's own. --}}
<h1 id="summary-title">@yield('summary-title', 'Complete Your Order')</h1>

<ul class="items">
    @foreach ($session->line_items as $item)
        <li class="item">
            @if (! empty($item['image_url']))
                <span class="thumb has-image"><img src="{{ $item['image_url'] }}" alt="" loading="lazy"></span>
            @else
                <span class="thumb" aria-hidden="true">{{ mb_strtoupper(mb_substr($item['name'], 0, 1)) }}</span>
            @endif

            <div class="item-body">
                <p class="item-name">{{ $item['name'] }}</p>
                @if (! empty($item['description']))
                    <p class="item-desc">{{ $item['description'] }}</p>
                @endif
                <p class="item-qty">Quantity: {{ $item['quantity'] ?? 1 }}</p>
                <p class="item-price">{{ $session->money((int) $item['amount']) }}</p>
            </div>
        </li>
    @endforeach
</ul>

@php($billedTo = implode(', ', array_filter([$session->billing['name'] ?? null, $session->billing['email'] ?? null])))

@if ($billedTo !== '')
    <p class="billed">Billed to {{ $billedTo }}</p>
@endif

@if (filled($session->description))
    <hr class="rule">
    <p class="memo">{{ $session->description }}</p>
@endif

<hr class="rule">
<dl class="totals">
    <div class="row"><dt>Subtotal</dt><dd>{{ $session->money($session->amount) }}</dd></div>
    <div class="row"><dt>Fees</dt><dd>Free</dd></div>
</dl>

<hr class="rule">
<dl class="totals">
    <div class="row total"><dt>Total Due</dt><dd>{{ $session->money($session->amount) }}</dd></div>
</dl>

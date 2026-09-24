{{--
    The hosted checkout's frame: merchant header, order summary on the left, a panel on the right.

    Self-contained on purpose — inline styles and script, no fonts or libraries from elsewhere. It is
    a payment page, and every third-party script on one is something that could swap the QR code.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Checkout') · {{ $merchant['name'] }}</title>
    <style>
        :root {
            --header: {{ $theme['header'] }};
            --accent: {{ $theme['accent'] }};
            --on-accent: {{ $theme['accent_text'] }};
            --ink: #20252c;
            --muted: #5b7282;
            --line: #dce3e8;
            --soft: #f2f5f7;
            --danger: #b42318;
            --danger-soft: #fef3f2;
            --danger-line: #fecdca;
            --notice: #0b5a7a;
            --notice-soft: #f0f9ff;
            --notice-line: #b9e3f9;
            color-scheme: light;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            background: #fff;
            color: var(--ink);
            font: 400 16px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        p, h1, h2, dl, dd, ol, ul { margin: 0; }
        ul, ol { padding: 0; }
        img { display: block; max-width: 100%; }
        .wrap { width: 100%; max-width: 1552px; margin: 0 auto; padding: 0 24px; }

        /* Header */
        .bar { background: var(--header); color: #fff; }
        .bar-inner { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 62px; padding-top: 8px; padding-bottom: 8px; }
        .brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .back { display: inline-flex; flex: none; align-items: center; justify-content: center; width: 28px; height: 28px; margin-left: -4px; border-radius: 6px; color: #fff; }
        .back:hover { background: rgba(255, 255, 255, .1); }
        .back:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
        .logo { display: inline-flex; flex: none; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; background: rgba(255, 255, 255, .16); color: #fff; font-size: 14px; font-weight: 700; object-fit: cover; }
        .brand-name { margin-left: -4px; overflow: hidden; font-size: 16px; font-weight: 700; letter-spacing: .01em; text-overflow: ellipsis; white-space: nowrap; }
        .ref { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.3; text-align: right; }
        .ref-label { color: rgba(255, 255, 255, .72); font-size: 14px; }
        .ref-value { font-size: 16px; font-weight: 600; }

        /* Two columns, stacked on narrow screens */
        .main { padding-top: 33px; padding-bottom: 48px; }
        .grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 32px; align-items: start; }

        /* Order summary */
        .summary h1 { margin-bottom: 14px; color: var(--ink); font-size: 24px; font-weight: 600; line-height: 32px; }
        .items { display: grid; gap: 16px; list-style: none; }
        .item { display: flex; align-items: flex-start; gap: 16px; }
        .thumb { display: grid; flex: none; place-items: center; width: 92px; height: 92px; overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: var(--soft); color: var(--ink); font-size: 16px; font-weight: 600; }
        .thumb.has-image { width: 128px; height: 128px; background: #fff; }
        .thumb img { width: 100%; height: 100%; object-fit: contain; }
        .item-body { min-width: 0; padding-top: 2px; }
        .item-name { color: var(--ink); font-size: 18px; font-weight: 600; line-height: 26px; }
        .item-desc { margin-top: 4px; color: var(--muted); font-size: 14px; line-height: 20px; }
        .item-qty { margin-top: 8px; color: var(--muted); font-size: 14px; line-height: 20px; }
        .item-price { margin-top: 2px; color: var(--ink); font-size: 16px; font-weight: 600; line-height: 24px; }
        .billed { margin-top: 20px; overflow-wrap: anywhere; color: var(--muted); font-size: 14px; line-height: 20px; }
        .rule { margin: 18px 0; border: 0; border-top: 1px dashed var(--line); }
        .memo { overflow-wrap: anywhere; color: var(--ink); font-size: 16px; line-height: 24px; }
        .totals { display: grid; }
        .row { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; color: var(--muted); font-size: 16px; line-height: 24px; }
        .row dd { text-align: right; }
        .row.total { color: var(--ink); font-weight: 600; }
        .row.total dd { font-weight: 700; }

        /* The right-hand panel */
        .panel { padding: 24px; border: 1px solid var(--line); border-radius: 12px; background: #fff; box-shadow: 0 1px 3px rgba(16, 24, 40, .06), 0 1px 2px rgba(16, 24, 40, .04); }
        .panel h2 { margin-bottom: 16px; color: var(--ink); font-size: 20px; font-weight: 600; line-height: 28px; }
        .methods { display: grid; gap: 16px; }
        .method { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 56px; padding: 12px 16px; border: 1px solid var(--line); border-radius: 6px; background: #fff; color: var(--ink); cursor: pointer; font-size: 14px; font-weight: 600; transition: border-color .15s, box-shadow .15s, background-color .15s; }
        .method:hover { border-color: #b9c4cc; }
        .method input { position: absolute; width: 1px; height: 1px; margin: 0; opacity: 0; pointer-events: none; }
        .method.is-selected { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 6%, #fff); box-shadow: 0 0 0 1px var(--accent); }
        .method:has(input:checked) { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 6%, #fff); box-shadow: 0 0 0 1px var(--accent); }
        .method:has(input:focus-visible) { outline: 2px solid var(--accent); outline-offset: 2px; }
        .method-qr { display: inline-flex; flex: 1; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px; }

        .qrph { display: inline-flex; align-items: center; gap: 2px; font-size: 17px; font-weight: 800; letter-spacing: -.02em; line-height: 1; }
        .qrph-glyph { width: 14px; height: 14px; margin-right: 3px; }
        .qrph-q { color: #d8283a; }
        .qrph-p { color: #1f3f95; }
        .marks { display: inline-flex; flex: none; align-items: center; gap: 10px; }
        .mc { width: 22px; height: 14px; }
        .visa { color: #1a1f71; font: italic 800 11px/1 Arial, Helvetica, sans-serif; letter-spacing: .03em; }

        /* Colours mixed rather than faded with opacity: the disabled button is the accent and its label
           each half-way to white, as PayMongo draws it, without promoting the button to its own layer. */
        .cta { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 40px; margin-top: 24px; padding: 8px 16px; border: 0; border-radius: 6px; background: var(--accent); color: var(--on-accent); cursor: pointer; font: inherit; font-size: 14px; font-weight: 500; text-decoration: none; transition: background-color .15s; }
        .cta:hover { background: color-mix(in srgb, var(--accent) 90%, #000); }
        .cta:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
        .cta:disabled { background: color-mix(in srgb, var(--accent) 50%, #fff); color: color-mix(in srgb, var(--on-accent) 50%, #fff); cursor: not-allowed; }
        @supports not (color: color-mix(in srgb, red 50%, white)) { .cta:disabled { opacity: .5; } }
        .cta.is-busy { cursor: progress; }
        .cta-secondary { border: 1px solid var(--line); background: #fff; color: var(--ink); }
        .cta-secondary:hover { background: var(--soft); }

        .fine { max-width: 440px; margin: 24px auto 0; color: var(--muted); font-size: 14px; line-height: 20px; text-align: center; }
        .fine a { color: var(--accent); font-weight: 500; text-decoration: none; }
        .fine a:hover { text-decoration: underline; }
        .powered { margin-top: 26px; color: var(--muted); font-size: 14px; text-align: center; }
        .powered strong { color: var(--ink); font-weight: 600; }

        .banner { margin-bottom: 16px; padding: 12px 14px; border: 1px solid; border-radius: 6px; font-size: 14px; line-height: 20px; }
        .banner-error { border-color: var(--danger-line); background: var(--danger-soft); color: var(--danger); }
        .banner-notice { border-color: var(--notice-line); background: var(--notice-soft); color: var(--notice); }
        .banner-debug { display: block; margin-top: 8px; padding-top: 8px; overflow-wrap: anywhere; border-top: 1px dashed var(--danger-line); font: 12px/18px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        .banner-debug b { margin-right: 6px; padding: 1px 5px; border-radius: 4px; background: var(--danger); color: #fff; font-weight: 600; }

        /* QR Ph */
        .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 16px; color: var(--muted); font-size: 14px; font-weight: 500; text-decoration: none; }
        .back-link:hover { color: var(--ink); }
        .qr-title { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; text-align: center; }
        .panel .qr-title { margin-bottom: 4px; }
        .qr-lead { color: var(--muted); font-size: 14px; line-height: 20px; text-align: center; }
        .qr-frame { position: relative; width: 264px; max-width: 100%; margin: 20px auto 0; padding: 16px; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
        .qr-frame img { width: 100%; height: auto; aspect-ratio: 1; }
        .qr-missing { padding: 48px 8px; color: var(--muted); font-size: 14px; text-align: center; }
        .qr-expired { position: absolute; inset: 0; display: none; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 20px; border-radius: 12px; background: rgba(255, 255, 255, .94); color: var(--ink); font-size: 14px; font-weight: 600; text-align: center; }
        .qr-frame.is-expired .qr-expired { display: flex; }
        .qr-frame.is-expired img { filter: blur(3px); opacity: .3; }
        .qr-expired .cta { width: auto; margin-top: 0; }
        .qr-facts { display: grid; gap: 4px; max-width: 400px; margin: 20px auto 0; }
        .qr-facts .row dd { color: var(--ink); font-variant-numeric: tabular-nums; font-weight: 600; }
        .qr-panel > .cta-secondary { max-width: 400px; margin: 20px auto 0; }
        .steps { display: grid; gap: 6px; max-width: 400px; margin: 20px auto 0; padding-left: 20px; color: var(--muted); font-size: 14px; line-height: 20px; }
        .waiting { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 20px; padding-top: 16px; border-top: 1px dashed var(--line); color: var(--muted); font-size: 14px; }
        .waiting.is-done { color: var(--ink); font-weight: 600; }
        .pulse { flex: none; width: 8px; height: 8px; border-radius: 50%; background: var(--accent); animation: pulse 1.4s ease-in-out infinite; }
        .waiting.is-done .pulse { animation: none; }
        @keyframes pulse { 0%, 100% { opacity: .25; transform: scale(.8); } 50% { opacity: 1; transform: scale(1); } }

        /* Paid / expired */
        .result { padding: 40px 24px; text-align: center; }
        .panel.result h2 { margin: 16px 0 8px; }
        .result-icon { display: inline-grid; place-items: center; width: 56px; height: 56px; border-radius: 50%; }
        .result-ok { background: color-mix(in srgb, var(--accent) 14%, #fff); color: var(--accent); }
        .result-muted { background: var(--soft); color: var(--muted); }
        .result-text { max-width: 380px; margin: 0 auto; color: var(--muted); font-size: 14px; line-height: 20px; }
        .result .cta { max-width: 360px; margin: 24px auto 0; }

        @media (max-width: 960px) {
            .grid { grid-template-columns: minmax(0, 1fr); gap: 24px; }
            .main { padding-top: 24px; }
        }

        @media (max-width: 560px) {
            /* Sides only: `.wrap` is also `.main`, whose top padding a shorthand would wipe out. */
            .wrap { padding-right: 16px; padding-left: 16px; }
            .bar-inner { min-height: 56px; }
            .brand { gap: 10px; }
            .brand-name { font-size: 15px; }
            .ref-label { font-size: 12px; }
            .ref-value { font-size: 14px; }
            .summary h1 { font-size: 20px; line-height: 28px; }
            .thumb, .thumb.has-image { width: 64px; height: 64px; border-radius: 6px; }
            .item { gap: 12px; }
            .item-name { font-size: 16px; line-height: 24px; }
            .panel { padding: 20px 16px; }
            .result { padding: 32px 16px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .pulse { animation: none; }
            .method, .cta { transition: none; }
        }
    </style>
</head>
<body @if (! empty($watching)) data-status-url="{{ $urls->session($session, 'status') }}" @endif>
    <header class="bar">
        <div class="wrap bar-inner">
            <div class="brand">
                <a class="back" href="{{ $session->isPaid() ? $session->success_url : $session->cancel_url }}" aria-label="Back to {{ $merchant['name'] }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                </a>
                @if (filled($merchant['logo_url']))
                    <img class="logo" src="{{ $merchant['logo_url'] }}" alt="" width="32" height="32">
                @else
                    <span class="logo" aria-hidden="true">{{ mb_strtoupper(mb_substr($merchant['name'], 0, 1)) }}</span>
                @endif
                <span class="brand-name">{{ $merchant['name'] }}</span>
            </div>

            @if (filled($session->reference_number))
                <div class="ref">
                    <span class="ref-label">Reference Number</span>
                    <span class="ref-value">{{ $session->reference_number }}</span>
                </div>
            @endif
        </div>
    </header>

    <main class="wrap main">
        <div class="grid">
            <section class="summary" aria-labelledby="summary-title">
                @include('aub-pay::checkout.partials.summary')
            </section>

            <div class="side">
                @yield('panel')
                <p class="powered">Payments processed by <strong>AUB</strong></p>
            </div>
        </div>
    </main>

    <script>
        (function () {
            // Continue waits for a choice, and a choice shows as selected even where :has() does not.
            var form = document.querySelector('[data-methods]');

            if (form) {
                var button = form.querySelector('[type=submit]');
                var sync = function () {
                    var chosen = form.querySelector('input[name=method]:checked');

                    button.disabled = !chosen;
                    button.classList.remove('is-busy');
                    form.querySelectorAll('.method').forEach(function (label) {
                        label.classList.toggle('is-selected', !!chosen && label.contains(chosen));
                    });
                };

                form.addEventListener('change', sync);
                form.addEventListener('submit', function (event) {
                    if (button.classList.contains('is-busy')) {
                        event.preventDefault();
                        return;
                    }
                    button.classList.add('is-busy');
                });
                // Coming back from AUB's card page restores this page from the back/forward cache.
                window.addEventListener('pageshow', sync);
                sync();
            }

            // Counted against a deadline rather than decremented, so a throttled background tab
            // still shows the right time when the customer comes back to it.
            var clock = document.querySelector('[data-countdown]');

            if (clock) {
                var deadline = Date.now() + parseInt(clock.getAttribute('data-countdown'), 10) * 1000;
                var frame = document.querySelector('[data-qr]');
                var download = document.querySelector('[data-download]');
                var tick = function () {
                    var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));

                    clock.textContent = Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2);

                    if (left === 0) {
                        if (frame) { frame.classList.add('is-expired'); }
                        if (download) { download.hidden = true; }
                        return;
                    }

                    setTimeout(tick, 1000);
                };

                tick();
            }

            // The page never decides a payment happened; it asks the server, which only knows what
            // AUB has confirmed.
            var statusUrl = document.body.getAttribute('data-status-url');

            if (statusUrl) {
                var waiting = document.querySelector('[data-waiting]');
                var poll = function () {
                    fetch(statusUrl, { headers: { 'Accept': 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
                        .then(function (response) { return response.ok ? response.json() : null; })
                        .then(function (data) {
                            if (data && data.status === 'paid' && data.redirect_url) {
                                if (waiting) {
                                    waiting.classList.add('is-done');
                                    waiting.lastElementChild.textContent = 'Payment received. Taking you back…';
                                }
                                setTimeout(function () { window.location.assign(data.redirect_url); }, 1200);
                                return;
                            }

                            if (data && data.status === 'expired') {
                                window.location.reload();
                                return;
                            }

                            setTimeout(poll, 4000);
                        })
                        .catch(function () { setTimeout(poll, 4000); });
                };

                setTimeout(poll, 4000);
            }
        })();
    </script>
</body>
</html>

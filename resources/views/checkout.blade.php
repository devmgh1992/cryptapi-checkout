{{-- cryptapi-checkout :: self-hosted, no hosted redirect --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pay with crypto – {{ $reference }}</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
@verbatim
  :root{--ink:#0E0F12;--lime:#C8F560;--bg:#F1F1EC;--paper:#fff;--muted:#73747C;--line:#E2E2DC}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:"Plus Jakarta Sans",system-ui,sans-serif;font-size:14px;line-height:1.5;min-height:100vh;padding:26px 18px;display:flex;flex-direction:column;align-items:center;gap:14px}
  .card{background:var(--paper);border:1px solid var(--line);border-radius:22px;padding:24px;max-width:520px;width:100%}
  .logo{display:flex;align-items:center;gap:9px;font-weight:800;font-size:16px;margin-bottom:18px}
  .logo i{width:24px;height:24px;border-radius:8px;background:var(--ink);display:grid;place-items:center}
  .logo i::after{content:"";border-left:7px solid var(--lime);border-top:4px solid transparent;border-bottom:4px solid transparent;margin-left:2px}
  .pill{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;padding:4px 10px;border-radius:999px;background:#F1F1EC;border:1px solid var(--line);margin-bottom:12px}
  .pill .dot{width:6px;height:6px;border-radius:50%;background:#E0A800}
  .pill.ok{background:#F3FBDD;border-color:#D7EDA6}
  .pill.ok .dot{background:#5C8F00}
  h1{font-size:19px;font-weight:800;letter-spacing:-.03em;margin:0 0 6px}
  p.sub{color:var(--muted);font-size:12.5px;margin:0 0 18px}
  .amount{display:flex;justify-content:space-between;align-items:baseline;padding:14px 16px;border:1.5px solid var(--line);border-radius:14px;margin-bottom:14px}
  .amount b{font-size:24px;letter-spacing:-.04em;font-variant-numeric:tabular-nums}
  .amount span{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
  .qr{display:flex;justify-content:center;padding:10px 0 14px}
  .qr img,.qr svg{width:190px;height:190px;border-radius:12px;background:#fff}
  .addr{display:flex;gap:8px;align-items:center;margin-bottom:10px}
  .addr code{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:#F7F7F3;border:1.5px dashed var(--line);border-radius:12px;padding:11px 12px;font-size:12.5px;font-weight:700;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;direction:ltr}
  .btn{border:0;border-radius:12px;height:42px;padding:0 16px;background:var(--ink);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer}
  .btn.ghost{background:#fff;color:var(--ink);border:1.5px solid var(--line)}
  .btn.lime{background:var(--lime);color:var(--ink)}
  .btn:disabled{opacity:.55;cursor:not-allowed}
  .fld{display:flex;gap:8px;margin-top:16px}
  .fld input{flex:1;height:42px;border:1.5px solid var(--line);border-radius:12px;padding:0 12px;font-size:13px;font-weight:600;outline:none}
  .fld input:focus{border-color:var(--ink)}
  .note{margin-top:14px;padding:12px 14px;border-radius:12px;background:#FFF8E6;border:1px solid #FFE3A3;color:#8A5A00;font-size:12px;font-weight:700}
  .note.bad{background:#FFF1F0;border-color:#FFC9C2;color:#B42318}
  .note.good{background:#F3FBDD;border-color:#D7EDA6;color:#3C6B00}
  .rows{display:grid;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
  .r{display:flex;justify-content:space-between;font-size:12.5px;color:var(--muted)}
  .r b{color:var(--ink);font-variant-numeric:tabular-nums}
  .fine{max-width:520px;color:var(--muted);font-size:11.5px;text-align:center;margin:0}
  .toast{position:fixed;left:50%;bottom:20px;transform:translate(-50%,10px);background:var(--ink);color:#fff;padding:9px 13px;border-radius:11px;font-size:12px;font-weight:700;opacity:0;transition:all .3s;pointer-events:none}
  .toast.show{opacity:1;transform:translate(-50%,0)}
  .pick-h{display:flex;align-items:center;gap:8px;margin:18px 0 9px}
  .pick-h span{font-size:10.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--muted)}
  .pick-h i{font-style:normal;font-size:10px;font-weight:800;color:var(--muted);background:#EFEFE9;border-radius:999px;padding:1px 7px}
  .pick-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}
  .coin{display:flex;flex-direction:column;gap:9px;text-decoration:none;color:var(--ink);border:1.5px solid var(--line);border-radius:16px;padding:12px;background:#fff;transition:border-color .18s,transform .15s var(--ease),box-shadow .18s}
  .coin:hover{border-color:var(--ink);transform:translateY(-2px);box-shadow:0 10px 22px -18px rgba(0,0,0,.55)}
  .coin-top{display:flex;align-items:center;gap:10px;min-width:0}
  .coin-bot{display:flex;align-items:center;justify-content:space-between;gap:8px}
  .lg{width:32px;height:32px;border-radius:50%;object-fit:cover;background:#F4F4EE;border:1px solid var(--line);flex:0 0 32px}
  .lg.ph{display:grid;place-items:center;font-weight:800;font-size:13px;color:var(--muted)}
  .lg-xl{width:44px;height:44px;flex:0 0 44px;border-radius:14px}
  .lg-xl.ph{font-size:17px}
  .coin .nm{display:flex;flex-direction:column;min-width:0;line-height:1.25}
  .coin .nm b{font-size:13.5px;font-weight:800;letter-spacing:-.01em}
  .coin .nm small{font-size:10.5px;color:var(--muted);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .coin .amt{font-size:13px;font-weight:800;font-variant-numeric:tabular-nums}
  .coin .fee{font-size:10px;font-weight:800;color:#8A5A00;background:#FFF8E6;border:1px solid #FFE3A3;border-radius:999px;padding:2px 7px;white-space:nowrap}
  .coin .fee.free{color:#3C6B00;background:#F3FBDD;border-color:#D7EDA6}
  .payhead{display:flex;align-items:center;gap:12px;margin:0 0 6px}
  .payhead h1{margin:0 0 2px}
  .links{display:flex;justify-content:space-between;align-items:center;margin-top:12px;font-size:11.5px;font-weight:700}
  .links a{color:var(--ink)}
  .fade{animation:viewIn .22s ease both}
  @keyframes viewIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
  .coin.is-loading{opacity:.45;pointer-events:none;filter:grayscale(.25)}
  .strip-wrap{margin:0 0 14px}
  .strip{display:flex;gap:8px;overflow-x:auto;scroll-snap-type:x mandatory;padding:2px 2px 8px;scrollbar-width:none}
  .strip::-webkit-scrollbar{display:none}
  .chip{flex:0 0 auto;scroll-snap-align:center;display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 13px 0 6px;border-radius:999px;border:1.5px solid var(--line);background:#fff;color:var(--muted);font:inherit;font-size:11.5px;font-weight:800;cursor:pointer;transition:all .18s}
  .chip img,.chip .lg-s{width:24px;height:24px;border-radius:50%;object-fit:cover;background:#F4F4EE;border:1px solid var(--line);flex:0 0 24px}
  .chip .lg-s{display:grid;place-items:center;font-size:10px}
  .chip small{font-size:9.5px;font-weight:800;opacity:.65;margin-left:2px}
  .chip.on{border-color:var(--ink);background:var(--ink);color:#fff;box-shadow:0 10px 20px -16px rgba(0,0,0,.9)}
  .chip.on small{opacity:.8}
  .chip.is-loading{opacity:.45;pointer-events:none}
  .strip-hint{margin:0;font-size:10px;font-weight:700;color:var(--muted);text-align:center;letter-spacing:.02em}
  .amount.big{flex-direction:column;align-items:flex-start;gap:2px;background:#FAFAF5;border-color:#EFEFE8}
  .amount.big b{font-size:26px;line-height:1.15}
  .amount.big b em{font-style:normal;font-size:13px;font-weight:800;color:var(--muted);margin-left:3px;letter-spacing:0}
  .amount.big small{font-size:11.5px;font-weight:700;color:var(--muted)}
  .pick-grid.out{animation:pickOut .16s ease both}
  @keyframes pickOut{to{opacity:0;transform:scale(.985)}}
  .pill.timer{background:#FFF8E6;border-color:#FFE3A3;color:#8A5A00}
  .pill.timer .dot{background:#E0A800}
  .pill.timer.warn{background:#FFF1F0;border-color:#FFC9C2;color:#B42318}
  .pill.timer.warn .dot{background:#D92D20}
  .expired h1{margin-bottom:4px}
  .expired form{margin-top:14px}
  @media (max-width:420px){.amount.big b{font-size:22px}}
@endverbatim
</style>
</head>
<body>

@php
  $brand = config('cryptapi-checkout.brand.name', config('app.name', 'Checkout'));
  $windowMinutes = $windowMinutes ?? 30;
  $fmtCoin = fn ($v) => rtrim(rtrim(number_format((float) $v, 8, '.', ''), '0'), '.');
  $chipTk = function ($t) { $parts = explode('_', (string) $t); return strtoupper((string) end($parts)); };
@endphp

<div class="card">
  <div class="logo"><i></i>{{ $brand }}</div>

  <span class="pill {{ ($payment->status ?? '') === 'paid' ? 'ok' : '' }}" id="statusPill">
    <span class="dot"></span><span id="statusText">{{ $mode === 'expired' ? 'Expired' : ((($payment->status ?? 'pending') === 'paid') ? 'Paid' : 'Waiting for payment') }}</span>
  </span>

  @if (! empty($expiresAt) && in_array($mode, ['api', 'manual'], true))
    <span class="pill timer" id="timerPill"><span class="dot"></span><span id="timerText">--:--</span></span>
  @endif

  @if (! empty($error))
    <div class="note bad">{{ $error }}</div>
  @endif

  @if (! empty($message))
    <div class="note good">{{ $message }}</div>
  @endif

  @if ($mode === 'select')
    <div id="pickerView">
      <h1>Choose your network</h1>
      <p class="sub">Pick a coin and the address appears right here — no page reload, no waiting.</p>

      @foreach (['usdt' => 'USDT', 'network' => 'Networks', 'token' => 'Other tokens'] as $groupKey => $groupLabel)
        @php $rows = array_values(array_filter($coins, fn ($c) => ($c['group'] ?? '') === $groupKey)); @endphp
        @if ($rows)
          <div class="pick-h"><span>{{ $groupLabel }}</span><i>{{ count($rows) }}</i></div>
          <div class="pick-grid">
            @foreach ($rows as $c)
              @php
                $feePct = (float) $c['fee_pct'];
                $feeText = rtrim(rtrim(number_format($feePct, 2, '.', ''), '0'), '.');
                $lg = $logos[$c['ticker']] ?? null;
                $lb = ($c['group'] ?? '') === 'usdt' ? 'USDT' : $chipTk($c['ticker']);
                $net = strcasecmp((string) $c['network'], $lb) === 0 ? '' : (string) $c['network'];
              @endphp
              <a class="coin" role="button" href="{{ route('cryptapi-checkout.show', ['reference' => $reference, 'coin' => $c['ticker']]) }}" data-ticker="{{ $c['ticker'] }}">
                <span class="coin-top">
                  @if ($lg)
                    <img class="lg" src="{{ $lg }}" alt="{{ $c['name'] }}" decoding="async" referrerpolicy="no-referrer">
                  @else
                    <span class="lg ph">{{ mb_substr($c['name'], 0, 1) }}</span>
                  @endif
                  <span class="nm">
                    <b>{{ $lb }}</b>
                    @if ($net) <small>{{ $net }}</small> @endif
                  </span>
                </span>
                <span class="coin-bot">
                  <span class="fee {{ $feePct <= 0 ? 'free' : '' }}">{{ $feePct > 0 ? '+' . $feeText . '%' : 'no fee' }}</span>
                  <span class="amt">${{ number_format($baseTotal * (1 + $feePct / 100), 2) }}</span>
                </span>
              </a>
            @endforeach
          </div>
        @endif
      @endforeach
      <p class="note">Amount to pay is ${{ number_format($baseTotal, 2) }} — the fee of the network you pick is added on top.</p>
    </div>

    {{-- The JS swaps this in as soon as a coin is picked, so it must exist even
         while the picker is on screen. Rendering it only in the non-select
         branch made `payView.hidden = false` throw on a null element and left
         the page blank after a click. --}}
    <div id="payView" hidden>
      <div id="panelBody"></div>
    </div>
  @else
    <div id="payView">

      @if (count($coins) > 1 && $mode !== 'expired')
        <div class="strip-wrap">
          <div class="strip" id="coinStrip">
            @foreach ($coins as $c)
              @php
                $lb = ($c['group'] ?? '') === 'usdt' ? 'USDT' : $chipTk($c['ticker']);
                $net = strcasecmp((string) $c['network'], $lb) === 0 ? '' : (string) $c['network'];
                $lg = $logos[$c['ticker']] ?? null;
              @endphp
              <button type="button" class="chip {{ ($coin['ticker'] ?? '') === $c['ticker'] ? 'on' : '' }}" data-ticker="{{ $c['ticker'] }}">
                @if ($lg)
                  <img src="{{ $lg }}" alt="" referrerpolicy="no-referrer" decoding="async">
                @else
                  <span class="lg-s ph">{{ mb_substr($lb, 0, 1) }}</span>
                @endif
                <span>{{ $lb }}@if ($net) <small>{{ $net }}</small>@endif</span>
              </button>
            @endforeach
          </div>
          <p class="strip-hint">swipe — tap another network to switch instantly</p>
        </div>
      @endif

      <div id="panelBody">
        @if ($mode === 'expired')
          <div class="expired" id="expiredBox">
            <h1>This payment window closed</h1>
            <p class="sub">The {{ $windowMinutes }}-minute window ended, so this payment was cancelled automatically and its addresses are no longer monitored.</p>
            <div class="amount big">
              <span>Order</span>
              <b style="font-size:19px">{{ $reference }}</b>
              <small>${{ number_format($baseTotal, 2) }} · expired</small>
            </div>
            <form method="POST" action="{{ route('cryptapi-checkout.renew', $reference) }}">
              @csrf
              <button class="btn lime" type="submit" style="width:100%;height:46px">Start a new payment ({{ $windowMinutes }} min)</button>
            </form>
          </div>
        @elseif ($mode === 'unconfigured' || $mode === 'unknown')
          <div class="note bad">
            {{ $mode === 'unknown' ? 'We could not find that order.' : 'Crypto payments are not configured yet — no coin has a wallet address.' }}
          </div>
        @else
          @php $address = $payment->address_in ?? null; @endphp

          <div class="payhead">
            @if (! empty($coinLogo))
              <img class="lg lg-xl" src="{{ $coinLogo }}" alt="{{ $coinLabel }}" referrerpolicy="no-referrer">
            @else
              <span class="lg lg-xl ph">{{ mb_substr((string) $coinLabel, 0, 1) }}</span>
            @endif
            <div>
              <h1>Send {{ $coinLabel }}</h1>
              <p class="sub" style="margin-bottom:0">Pay the exact amount to the address below. We keep this page live and confirm automatically.</p>
            </div>
          </div>

          <div class="amount big">
            <span>Amount to send</span>
            @if ($coinAmount)
              <b id="amountDue">{{ $fmtCoin($coinAmount) }} <em id="amountSym">{{ $coinSymbol }}</em></b>
              <small id="amountSub">≈ ${{ number_format((float) $amountDue, 2) }}@if ($feeAmount > 0) · base ${{ number_format((float) $baseTotal, 2) }} + {{ rtrim(rtrim(number_format((float) $coin['fee_pct'], 2, '.', ''), '0'), '.') }}% network fee @endif</small>
            @else
              <b id="amountDue">${{ number_format((float) $amountDue, 2) }}</b>
              <small id="amountSub">Total incl. the {{ $coinSymbol ?? 'network' }} fee</small>
            @endif
          </div>

          @if ($mode === 'manual' && ! empty($payment->payload['notice']))
            <div class="note bad">{{ $payment->payload['notice'] }}</div>
          @endif

          @if ($address)
            <div class="qr">
              @if (! empty($payment->qr_code))
                <img src="{{ $payment->qr_code }}" alt="Payment QR">
              @elseif (! empty($qrSvg))
                {!! $qrSvg !!}
              @endif
            </div>

            <div class="addr">
              <code id="addr">{{ $address }}</code>
              <button class="btn ghost" type="button" id="copyBtn">Copy</button>
            </div>

            <div class="rows">
              <div class="r"><span>Network</span><b>{{ $coinLabel }}</b></div>
              @if ($rate)
                <div class="r"><span>Rate</span><b>1 USD ≈ {{ $fmtCoin($rate) }} {{ $coinSymbol }}</b></div>
              @endif
              <div class="r"><span>Order</span><b>{{ $reference }}</b></div>
            </div>

            @if ($mode === 'manual')
              <form class="fld" method="POST" action="{{ route('cryptapi-checkout.txid', $reference) }}">
                @csrf
                <input name="txid" placeholder="Paste the transaction ID (TXID)" required minlength="32" maxlength="130">
                <button class="btn lime" type="submit">I have paid</button>
              </form>
              <p class="note">We verify the transaction on-chain — destination wallet, amount, confirmations and time — before anything is activated. A wrong or reused TXID is rejected.</p>
            @else
              <p class="note" id="pollNote">Waiting for the network… this page updates by itself.</p>
            @endif
          @endif
        @endif
      </div>
    </div>
  @endif
</div>

<p class="fine" id="fineNote">@if (in_array($mode, ['api', 'manual'], true) && $coinLabel)Send only {{ $coinLabel }} to this address. A different coin or network cannot be recovered.@endif</p>

<div class="toast" id="toast"></div>

<script>
  window.CRYPTO = {
    reference: @json($reference),
    statusUrl: @json(route('cryptapi-checkout.status', $reference)),
    manualUrl: @json(route('cryptapi-checkout.txid', $reference)),
    coinUrl: @json(url(config('cryptapi-checkout.prefix', 'pay/crypto') . '/' . $reference . '/coin')),
    renewUrl: @json(route('cryptapi-checkout.renew', $reference)),
    csrf: @json(csrf_token()),
    expiresAt: @json($expiresAt ?? null),
    windowMinutes: {{ (int) $windowMinutes }},
    canPick: {{ $mode === 'select' ? 'true' : 'false' }}
  };
</script>
<script>
@verbatim
(function () {
  const C = window.CRYPTO || {};
  const picker = document.getElementById('pickerView');
  const payView = document.getElementById('payView');
  const panel = document.getElementById('panelBody');
  const strip = document.getElementById('coinStrip');
  const fine = document.getElementById('fineNote');
  const pill = document.getElementById('statusPill');
  const statusText = document.getElementById('statusText');
  const toast = document.getElementById('toast');

  const mem = Object.create(null);
  const TTL = 30 * 60 * 1000;
  let pollTimer = null;
  let deadline = C.expiresAt ? new Date(C.expiresAt).getTime() : null;

  const MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => MAP[c]);
  const money = n => '$' + Number(n).toFixed(2);
  const pct = n => Number(n).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
  const coinFmt = n => Number(n).toFixed(8).replace(/0+$/, '').replace(/\.$/, '');

  function toastShow(msg) {
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 1600);
  }

  function copyText(text) {
    const done = () => toastShow('Address copied');
    const fallback = () => {
      const ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', '');
      ta.style.position = 'fixed'; ta.style.top = '-1000px'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      ta.remove();
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(fallback);
    } else {
      fallback();
    }
  }

  function cacheKey(t) { return 'crypto:' + C.reference + ':' + t; }
  function fromCache(t) {
    if (mem[t]) return mem[t];
    try {
      const raw = sessionStorage.getItem(cacheKey(t));
      if (raw) {
        const o = JSON.parse(raw);
        if (o && (Date.now() - o.t) < TTL) { mem[t] = o.d; return o.d; }
      }
    } catch (e) {}
    return null;
  }
  function toCache(t, d) {
    mem[t] = d;
    try { sessionStorage.setItem(cacheKey(t), JSON.stringify({ t: Date.now(), d: d })); } catch (e) {}
  }

  async function getCoin(t) {
    const hit = fromCache(t);
    if (hit) return hit;
    const res = await fetch(C.coinUrl + '/' + encodeURIComponent(t), { headers: { 'Accept': 'application/json' } });
    const data = await res.json().catch(() => null);
    if (!data || data.ok === false) {
      if (data && data.paid && data.thanks) { window.location.href = data.thanks; return null; }
      if (data && data.expired) { showExpired(data.renew); return null; }
      throw new Error((data && data.message) || 'This network is not available right now.');
    }
    toCache(t, data);
    return data;
  }

  function ensureTimerPill() {
    let pillT = document.getElementById('timerPill');
    if (!pillT) {
      pillT = document.createElement('span');
      pillT.className = 'pill timer';
      pillT.id = 'timerPill';
      pillT.innerHTML = '<span class="dot"></span><span id="timerText">--:--</span>';
      const anchor = document.getElementById('statusPill');
      if (anchor && anchor.parentElement) anchor.insertAdjacentElement('afterend', pillT);
    }
    return pillT;
  }

  function startTimer(expiresAt) {
    if (expiresAt) deadline = new Date(expiresAt).getTime();
    if (!deadline) return;
    const pillT = ensureTimerPill();
    const txt = document.getElementById('timerText');
    if (!pillT || !txt) return;

    const tick = () => {
      const left = Math.max(0, Math.floor((deadline - Date.now()) / 1000));
      const m = Math.floor(left / 60);
      const s = left % 60;
      txt.textContent = '⏳ ' + m + ':' + String(s).padStart(2, '0') + ' left';
      if (left <= 300) pillT.classList.add('warn');
      if (left <= 0) { showExpired(); return; }
      setTimeout(tick, 1000);
    };
    tick();
  }

  function showExpired(renewUrl) {
    stopPoll();
    const url = renewUrl || C.renewUrl;
    const mins = C.windowMinutes || 30;
    if (picker) picker.hidden = true;
    if (strip && strip.closest('.strip-wrap')) strip.closest('.strip-wrap').hidden = true;
    payView.hidden = false;
    panel.innerHTML = '<div class="expired"><h1>This payment window closed</h1>'
      + '<p class="sub">The ' + mins + '-minute window ended, so this payment was cancelled automatically.</p>'
      + '<form method="POST" action="' + esc(url) + '"><input type="hidden" name="_token" value="' + esc(C.csrf) + '">'
      + '<button class="btn lime" type="submit" style="width:100%;height:46px">Start a new payment (' + mins + ' min)</button></form></div>';
    const tp = document.getElementById('timerPill');
    if (tp) tp.remove();
    if (fine) fine.textContent = '';
    if (statusText) statusText.textContent = 'Expired';
  }

  function amountBlock(d) {
    const total = money(d.total);
    const sub = '≈ ' + total + (d.fee > 0 ? ' · base ' + money(d.base) + ' + ' + pct(d.coin.fee_pct) + '% network fee' : '');
    const head = '<div class="amount big"><span>Amount to send</span>';
    if (d.value_coin) {
      return head + '<b id="amountDue">' + coinFmt(d.value_coin) + ' <em id="amountSym">' + esc(d.ticker_label) + '</em></b>'
        + '<small id="amountSub">' + sub + '</small></div>';
    }
    return head + '<b id="amountDue">' + total + '</b><small id="amountSub">' + sub + '</small></div>';
  }

  function build(d) {
    const logo = d.coin.logo
      ? '<img class="lg lg-xl" src="' + esc(d.coin.logo) + '" alt="" referrerpolicy="no-referrer">'
      : '<span class="lg lg-xl ph">' + esc((d.coin.name || '?').slice(0, 1)) + '</span>';

    let h = '<div class="payhead">' + logo
      + '<div><h1>Send ' + esc(d.coin.label) + '</h1>'
      + '<p class="sub" style="margin-bottom:0">Pay the exact amount to the address below. We keep this page live and confirm automatically.</p></div></div>'
      + amountBlock(d)
      + '<button type="button" class="chip" id="switchNet" style="margin:2px 0 14px">\u21ba Change network</button>';

    if (d.mode === 'manual' && d.notice) h += '<div class="note bad">' + esc(d.notice) + '</div>';

    if (d.address) {
      h += '<div class="qr">' + (d.qr_img ? '<img src="' + esc(d.qr_img) + '" alt="Payment QR">' : (d.qr_svg || '')) + '</div>';
      h += '<div class="addr"><code id="addr">' + esc(d.address) + '</code>'
        + '<button class="btn ghost" type="button" id="copyBtn">Copy</button></div>';
      h += '<div class="rows"><div class="r"><span>Network</span><b>' + esc(d.coin.label) + '</b></div>';
      if (d.rate) h += '<div class="r"><span>Rate</span><b>1 USD ≈ ' + coinFmt(d.rate) + ' ' + esc(d.ticker_label) + '</b></div>';
      h += '<div class="r"><span>Order</span><b>' + esc(C.reference) + '</b></div></div>';

      if (d.mode === 'manual') {
        h += '<form class="fld" method="POST" action="' + esc(C.manualUrl) + '">'
          + '<input type="hidden" name="_token" value="' + esc(C.csrf) + '">'
          + '<input name="txid" placeholder="Paste the transaction ID (TXID)" required minlength="32" maxlength="130">'
          + '<button class="btn lime" type="submit">I have paid</button></form>'
          + '<p class="note">We verify the transaction on-chain before activating anything.</p>';
      } else {
        h += '<p class="note" id="pollNote">Waiting for the network… this page updates by itself.</p>';
      }
    } else {
      h += '<div class="note bad">This network could not be prepared. Please pick another one.</div>';
    }
    return h;
  }

  function setChip(ticker) {
    if (!strip) return;
    let active = null;
    strip.querySelectorAll('.chip').forEach(chip => {
      const on = chip.dataset.ticker === ticker;
      chip.classList.toggle('on', on);
      if (on) active = chip;
    });
    if (active) {
      const center = () => {
        const left = active.offsetLeft - (strip.clientWidth - active.offsetWidth) / 2;
        strip.scrollLeft = Math.max(0, left);
      };
      center();
      requestAnimationFrame(center);
      setTimeout(center, 260);
    }
  }

  function wire(d) {
    const sw = document.getElementById('switchNet');
    if (sw) sw.addEventListener('click', () => window.location.reload());
    const copy = document.getElementById('copyBtn');
    if (copy) copy.addEventListener('click', () => {
      const a = document.getElementById('addr');
      if (a) copyText(a.textContent.trim());
    });
    if (fine) fine.textContent = d.address
      ? 'Send only ' + d.coin.label + ' to this address. A different coin or network cannot be recovered.'
      : '';
    if (d.expires_at) deadline = new Date(d.expires_at).getTime();
    startTimer();
    startPoll(d);
  }

  function showError(msg) {
    if (picker) picker.hidden = true;
    payView.hidden = false;
    panel.innerHTML = '<div class="note bad">' + esc(msg) + '</div>';
  }

  async function go(ticker, trigger) {
    if (!ticker) return;
    if (trigger) trigger.classList.add('is-loading');
    let d;
    try {
      d = await getCoin(ticker);
    } catch (err) {
      if (trigger) trigger.classList.remove('is-loading');
      showError(err && err.message ? err.message : 'Something went wrong. Try again.');
      return;
    }
    if (trigger) trigger.classList.remove('is-loading');
    if (!d) return;
    picker.classList.add('out');
    setTimeout(() => {
      picker.hidden = true;
      payView.hidden = false;
      panel.innerHTML = build(d);
      panel.classList.remove('fade'); void panel.offsetWidth; panel.classList.add('fade');
      setChip(d.coin.ticker);
      wire(d);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }, 140);
  }

  document.querySelectorAll('.pick-grid .coin').forEach(card => {
    card.addEventListener('click', ev => {
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey) return;
      ev.preventDefault();
      go(card.dataset.ticker, card);
    });
  });

  if (strip) {
    strip.querySelectorAll('.chip').forEach(chip => {
      chip.addEventListener('click', ev => {
        ev.preventDefault();
        if (chip.classList.contains('on')) return;
        go(chip.dataset.ticker, chip);
      });
    });
  }

  function stopPoll() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  }

  function startPoll(d) {
    stopPoll();
    if (!(d.mode === 'api' || d.mode === 'manual')) return;
    const url = d.status_url || C.statusUrl;

    const tick = async () => {
      try {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const s = await res.json();
        if (s.paid) {
          if (pill) pill.classList.add('ok');
          if (statusText) statusText.textContent = 'Paid — activating';
          const n = document.getElementById('pollNote');
          if (n) n.textContent = 'Payment confirmed. Redirecting…';
          setTimeout(() => { window.location.href = s.thanks; }, 2000);
          return;
        }
        if (s.expired || s.status === 'expired') { showExpired(s.renew); return; }
        if (s.status === 'confirming') {
          if (statusText) statusText.textContent = 'Confirming on chain…';
          const n = document.getElementById('pollNote');
          if (n) n.textContent = 'We see your transaction and are waiting for confirmations.';
        }
      } catch (e) { /* keep polling */ }
      pollTimer = setTimeout(tick, 12000);
    };
    pollTimer = setTimeout(tick, 6000);
  }

  if (payView && !payView.hidden) {
    const copy = document.getElementById('copyBtn');
    if (copy) copy.addEventListener('click', () => {
      const a = document.getElementById('addr');
      if (a) copyText(a.textContent.trim());
    });
    const pre = strip && strip.querySelector('.chip.on');
    if (pre) setChip(pre.dataset.ticker);
    if (C.expiresAt) startTimer();
    if (document.getElementById('addr')) startPoll({ mode: 'api', status_url: C.statusUrl });
  }
})();
@endverbatim
</script>
</body>
</html>

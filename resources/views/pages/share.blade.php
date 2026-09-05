@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>Share your news with Nearbypost</h1>
    <p class="page-intro">If you publish local news, events or notices, we will show them to readers
      near you. It costs nothing, and every story links back to you.</p>
  </div>

  @if(session('shared'))
    <div class="mp-notice" style="border-left-color:#2f6b3a;background:#f0f8f2">
      <strong>Thank you — we have it.</strong> We will look at your site, work out the best way to
      read it, and get in touch if we need anything. There is nothing else you need to do.
    </div>
  @endif

  @if($errors->any())
    <div class="mp-notice" style="border-left-color:#b3541e;background:#fdf3ec">
      <strong>{{ $errors->first() }}</strong>
    </div>
  @endif

  <div class="prose-card">
    <h2 class="share-h2">How we can read your site</h2>
    <p class="share-p">You do not have to build anything. Pick whichever already exists — if you are
      not sure, just give us the address and we will look.</p>

    <ul class="share-ways">
      <li><b>You already have a feed.</b> Most websites do, often at <code>/feed/</code>, whether or
        not anyone told you. We will find it.</li>
      <li><b>You use an events calendar.</b> The common WordPress one publishes a proper list we can
        read, dates and venue included.</li>
      <li><b>You just have a page.</b> A "what's on" or "news" page is usually enough — we read the
        listings, not the whole site.</li>
      <li><b>You post mostly on social media.</b> Tell us where. See the note below, because this one
        is harder than it sounds.</li>
    </ul>
  </div>

  <form class="form-card share-form" method="post" action="{{ \App\Support\Loc::route('share.submit') }}">
    @csrf

    <label class="share-label" for="website_url">Your website <span class="req">required</span></label>
    <input class="share-input" id="website_url" name="website_url" type="text" inputmode="url"
           placeholder="yoursite.com" maxlength="500" value="{{ old('website_url') }}" required>
    <p class="share-hint">The address of the site itself. We will find the feed or the news page.</p>

    <label class="share-label" for="site_name">What it is called</label>
    <input class="share-input" id="site_name" name="site_name" type="text" maxlength="160"
           value="{{ old('site_name') }}" placeholder="Kepong Community News">

    <label class="share-label" for="describes">What you publish</label>
    <textarea class="share-input" id="describes" name="describes" rows="4" maxlength="2000"
              placeholder="Neighbourhood news for Kepong — council notices, school events, road closures. Updated two or three times a week.">{{ old('describes') }}</textarea>
    <p class="share-hint">A sentence or two. It helps us decide how often to look.</p>

    <label class="share-label" for="social_0">Social media, if that is where you post</label>
    <input class="share-input" id="social_0" name="social_links[]" type="text" maxlength="300"
           value="{{ old('social_links.0') }}" placeholder="facebook.com/yourpage">
    <input class="share-input" name="social_links[]" type="text" maxlength="300"
           value="{{ old('social_links.1') }}" placeholder="instagram.com/yourpage or tiktok.com/@you">

    <label class="share-label" for="contact_name">Your name</label>
    <input class="share-input" id="contact_name" name="contact_name" type="text" maxlength="120"
           value="{{ old('contact_name') }}">

    <label class="share-label" for="contact_email">Email, if we need to ask something</label>
    <input class="share-input" id="contact_email" name="contact_email" type="email" maxlength="160"
           value="{{ old('contact_email') }}">
    <p class="share-hint">Only used to answer you about this. Nothing else, ever.</p>

    <label class="share-label">What we may show</label>
    <p class="share-hint">Every story links back to you, whichever you pick.</p>

    <label class="share-check">
      <input type="checkbox" checked disabled>
      <span>Your headline and a link. <em>Always — there is nothing to show without it.</em></span>
    </label>
    <label class="share-check">
      <input type="checkbox" name="allow_excerpt" value="1" {{ old('allow_excerpt', true) ? 'checked' : '' }}>
      <span>A short extract from your own opening lines.</span>
    </label>
    <label class="share-check">
      <input type="checkbox" name="allow_ai_summary" value="1" {{ old('allow_ai_summary', true) ? 'checked' : '' }}>
      <span>A one or two sentence summary we write ourselves.</span>
    </label>
    <label class="share-check">
      <input type="checkbox" name="allow_image" value="1" {{ old('allow_image') ? 'checked' : '' }}>
      <span>One image from the article. <em>Off by default — photographs are often licensed to you rather than owned by you.</em></span>
    </label>

    <label class="share-check">
      <input type="checkbox" name="speaks_for_site" value="1" {{ old('speaks_for_site') ? 'checked' : '' }} required>
      <span>{{ \App\Services\Ingest\SourcePermissions::CONSENT_TEXT }}</span>
    </label>
    <p class="share-hint">We will also ask you to prove you control the domain — one tag, one file or
      one DNS record. It takes a minute and it is what stops anyone signing your site up.</p>

    <button class="share-submit" type="submit">Send it to us</button>
  </form>

  <div class="prose-card">
    <h2 class="share-h2">About social media</h2>
    <p class="share-p">Facebook, Instagram and TikTok do not let anyone read a page's posts without
      their permission, and we will not go around that. If that is where your news lives, there are
      two honest routes:</p>
    <ul class="share-ways">
      <li><b>Give us a page as well.</b> Even a simple one. It is the only way we can read you
        reliably, and it belongs to you rather than to a platform.</li>
      <li><b>Post the link.</b> If your posts already link to a page of yours, we read the page.</li>
    </ul>
    <p class="share-p">Tell us your handles anyway — it helps us understand what you cover, and we
      will say plainly if there is nothing we can do yet.</p>
  </div>

  <div class="prose-card">
    <h2 class="share-h2">What happens next</h2>
    <ol class="share-steps">
      <li>We look at your site and work out how to read it.</li>
      <li>We decide how often to check — usually weekly, daily if you publish most days.</li>
      <li>A person reads it before anything goes live. We will tell you either way.</li>
    </ol>
    <p class="share-p share-small">We only ever show a headline, a short summary and a link back to
      you. We do not republish your articles.</p>
  </div>
@endsection

@section('aside')
  <div class="info-card">
    <h3>Who this is for</h3>
    <p class="share-small">Local newspapers · councils and agencies · malls and venues · event
      organisers · schools · community and residents' groups · anyone publishing something a
      neighbour would want to know.</p>
  </div>
@endsection

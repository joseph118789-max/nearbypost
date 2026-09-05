{{-- The post form: readers at /contribute/new, the admin at /admin/post/{official|unofficial}. Same fields, same map. --}}
  <div class="form-card">
    @include('partials.form-errors')

    <form method="post" action="{{ $action }}" enctype="multipart/form-data">
      @csrf
      @if($editing)
        @method('PUT')
      @endif

      @if(config('services.community.enabled'))
        {{-- Owner, 3 Sep: "Only allow to post nearby post. remove where it belongs." --}}
        <input type="hidden" name="section" value="nearme">
      @else
      <fieldset class="field-set">
        <legend class="field-label">{{ __('site.where_it_goes') }}</legend>

        <div class="filter-chips">
          @foreach($sections as $section)
            <label class="chip-radio">
              <input type="radio" name="section" value="{{ $section }}"
                     {{ old('section', $post->section ?? 'nearme') === $section ? 'checked' : '' }}>
              <span>{{ __('site.section_' . $section) }}</span>
            </label>
          @endforeach
        </div>

        <p class="field-hint">{{ __('site.where_it_goes_hint') }}</p>
      </fieldset>
      @endif

      @if(($adminKind ?? null) === 'official')
        <label class="field-label" for="publisher">Publisher (shown as the source)</label>
        <input class="modal-input" id="publisher" type="text" name="publisher" value="{{ old('publisher', 'Nearbypost') }}" maxlength="120">
        <p class="field-hint">Bernama, The Star, a council's own notice - or Nearbypost when it is ours.</p>
      @endif

      <label class="field-label" for="title">{{ __('site.field_title') }}</label>
      <input class="modal-input" id="title" type="text" name="title"
             value="{{ old('title', $post->title) }}" required minlength="8" maxlength="200">
      <p class="field-hint">{{ __('site.title_hint') }}</p>

      <label class="field-label" for="body">{{ __('site.field_story') }}</label>
      <textarea class="modal-input modal-textarea" id="body" name="body" rows="10"
                required minlength="40" maxlength="5000">{{ old('body', $post->body) }}</textarea>
      <p class="field-hint">{{ __('site.body_hint') }}</p>

      <label class="field-label" for="place">{{ __('site.field_place') }}</label>

      @if(config('services.community.enabled'))
        {{-- Community Reports: the pin. GPS places it, the reader can drag it, the
             place under it is read from our own map engine. What the phone said
             and what the reader chose are both kept, privately. --}}
        <div class="pin-box">
          <div id="pinMap" class="pin-map" aria-label="{{ __('site.pin_map') }}"></div>
          <p class="field-hint" id="pinStatus">{{ __('site.pin_locating') }}</p>
          <div class="pin-actions">
            <button type="button" class="chip-btn" id="pinLocate">{{ __('site.pin_use_gps') }}</button>
            <span class="field-hint">{{ __('site.pin_drag_hint') }}</span>
          </div>
        </div>
        <input type="hidden" name="pin_lat" id="pin_lat" value="{{ old('pin_lat', $post->latitude) }}">
        <input type="hidden" name="pin_lng" id="pin_lng" value="{{ old('pin_lng', $post->longitude) }}">
        <input type="hidden" name="gps_lat" id="gps_lat" value="{{ old('gps_lat') }}">
        <input type="hidden" name="gps_lng" id="gps_lng" value="{{ old('gps_lng') }}">
        <input type="hidden" name="gps_accuracy" id="gps_accuracy" value="{{ old('gps_accuracy') }}">
        <input type="hidden" name="pin_adjusted" id="pin_adjusted" value="{{ old('pin_adjusted', '0') }}">
        <input type="hidden" name="location_source" id="location_source" value="{{ old('location_source', 'unavailable') }}">
        <input type="hidden" name="pin_label" id="pin_label" value="{{ old('pin_label') }}">
      @endif

      <input class="modal-input" id="place" type="text" name="place"
             value="{{ old('place', $post->main_place_text) }}" maxlength="160"
             placeholder="e.g. Kepong, Kuala Lumpur">
      <p class="field-hint">{{ __('site.place_hint') }}</p>

      <label class="field-label" for="image">{{ __('site.field_image') }}</label>

      @if($post->image_path)
        <div class="image-current">
          <img src="{{ asset($post->image_path) }}" alt="">
          <label class="field-check">
            <input type="checkbox" name="remove_image" value="1">
            {{ __('site.remove_image') }}
          </label>
        </div>
      @endif

      <input class="modal-input" id="image" type="file" name="image"
             accept="image/jpeg,image/png,image/webp,image/gif">
      <p class="field-hint">{{ __('site.image_hint') }}</p>

      <button class="desktop-action-btn primary" type="submit">
        {{ $submitLabel ?? ($editing ? __('site.resubmit') : __('site.submit_for_review')) }}
      </button>

      @if(empty($adminKind))
        <p class="field-hint">{{ __('site.review_wait_hint') }}</p>
      @endif
    </form>
  </div>

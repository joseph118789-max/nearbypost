{{-- Everything wrong with the submission, in one place above the form, so a
     person fixing three problems finds all three at once. --}}
@if($errors->any())
  <div class="notice notice--warn" role="alert">
    <ul class="error-list">
      @foreach($errors->all() as $error)
        <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
@endif

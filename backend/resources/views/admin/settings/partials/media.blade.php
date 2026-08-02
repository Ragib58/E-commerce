{{--
    One uploadable brand asset (logo, light logo, dark logo, favicon).

    Each asset posts to its own endpoint rather than joining the bulk-save form:
    file uploads need multipart encoding, and uploading one asset should not
    resubmit — or risk clobbering — the others.

    `typedValue()` has already expanded the stored path into an absolute URL, so
    the preview works identically on the local disk and on S3/MinIO.
--}}

@php
    $url = $setting->typedValue();
    $isFavicon = str_contains($setting->key, 'favicon');
    // The dark-mode logo is previewed on a dark plate; otherwise a white logo
    // would be invisible against the panel's light surface.
    $isDark = str_contains($setting->key, 'dark');
@endphp

<div class="media-card">
    <div class="media-card-head">
        <h4>
            {{ $setting->label ?? $setting->key }}
            @if ($setting->is_locked)
                <span class="badge badge-locked">Required</span>
            @endif
        </h4>

        @if ($setting->description)
            <p class="field-hint">{{ $setting->description }}</p>
        @endif
    </div>

    <div @class(['media-preview', 'is-dark' => $isDark, 'is-favicon' => $isFavicon])>
        @if ($url)
            <img src="{{ $url }}" alt="{{ $setting->label }} preview" loading="lazy">
        @else
            <span class="media-empty">Not set</span>
        @endif
    </div>

    <form method="POST"
          action="{{ route('admin.settings.media.upload', ['key' => $setting->key]) }}"
          enctype="multipart/form-data"
          class="media-form">
        @csrf

        <input type="file"
               name="file"
               id="media-{{ str_replace('.', '-', $setting->key) }}"
               accept="{{ collect($acceptedMimes)->map(fn ($mime) => '.' . $mime)->implode(',') }}"
               required>

        <div class="media-actions">
            <button type="submit" class="btn btn-primary btn-sm">
                {{ $url ? 'Replace' : 'Upload' }}
            </button>
        </div>
    </form>

    @if ($url)
        <form method="POST"
              action="{{ route('admin.settings.media.destroy', ['key' => $setting->key]) }}"
              onsubmit="return confirm('Remove this asset? The file will be deleted.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
        </form>
    @endif

    @error('file')
        <p class="field-error">{{ $message }}</p>
    @enderror

    <p class="field-key"><code>{{ $setting->key }}</code></p>
</div>

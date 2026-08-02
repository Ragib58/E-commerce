{{--
    Renders one editable setting.

    The control is chosen from the setting's declared `type`, not from a
    hardcoded field map — a setting added by a later phase gets an appropriate
    input automatically. `old()` is preferred over the stored value so a failed
    validation round-trip preserves what the admin typed.
--}}

@php
    use App\Enums\SettingType;

    $name = "settings[{$setting->key}]";
    $id = 'setting-' . str_replace('.', '-', $setting->key);
    $errorKey = "settings.{$setting->key}";
    $current = old("settings.{$setting->key}", $setting->typedValue());
@endphp

<div @class(['field', 'has-error' => $errors->has($errorKey)])>
    <label for="{{ $id }}">
        {{ $setting->label ?? $setting->key }}

        @if ($setting->is_locked)
            <span class="badge badge-locked" title="Required by the storefront; the value is editable but the setting cannot be deleted.">
                Required
            </span>
        @endif
    </label>

    @if ($setting->description)
        <p class="field-hint">{{ $setting->description }}</p>
    @endif

    @switch($setting->type)
        @case(SettingType::Boolean)
            <label class="switch">
                {{-- Hidden companion so an unchecked box still submits a value.
                     Without it the controller could not distinguish "off" from
                     "not present in this form". --}}
                <input type="hidden" name="{{ $name }}" value="0">
                <input type="checkbox"
                       id="{{ $id }}"
                       name="{{ $name }}"
                       value="1"
                       @checked((bool) $current)>
                <span>Enabled</span>
            </label>
            @break

        @case(SettingType::Color)
            <div class="color-field">
                {{-- The two inputs are bound to each other in the browser: the
                     swatch is for picking, the text box for pasting an exact
                     brand hex. Only the text input is submitted. --}}
                <input type="color"
                       class="color-swatch"
                       value="{{ $current ?: '#000000' }}"
                       data-color-for="{{ $id }}"
                       aria-label="{{ $setting->label }} colour picker">
                <input type="text"
                       id="{{ $id }}"
                       name="{{ $name }}"
                       value="{{ $current }}"
                       placeholder="#2563eb"
                       spellcheck="false"
                       autocomplete="off"
                       pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$"
                       class="color-input">
            </div>
            @break

        @case(SettingType::Text)
            <textarea id="{{ $id }}" name="{{ $name }}" rows="3">{{ $current }}</textarea>
            @break

        @case(SettingType::Integer)
            <input type="number" step="1" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}">
            @break

        @case(SettingType::Float)
            <input type="number" step="0.01" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}">
            @break

        @case(SettingType::Email)
            <input type="email" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}" autocomplete="off">
            @break

        @case(SettingType::Url)
            <input type="url" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}"
                   placeholder="https://" autocomplete="off" spellcheck="false">
            @break

        @case(SettingType::Json)
            <textarea id="{{ $id }}" name="{{ $name }}" rows="5" spellcheck="false" class="mono">{{ is_string($current) ? $current : json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
            @break

        @default
            <input type="text" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}">
    @endswitch

    @error($errorKey)
        <p class="field-error">{{ $message }}</p>
    @enderror

    <p class="field-key"><code>{{ $setting->key }}</code></p>
</div>

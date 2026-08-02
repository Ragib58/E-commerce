@extends('admin.layouts.app')

@section('title', $activeGroup->label())

@section('content')
    <div class="admin-header">
        <h1>Store Settings</h1>
        <p>
            Everything the storefront renders as branding lives here. Changes are
            applied immediately &mdash; the Next.js site is revalidated on save.
        </p>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>The settings were not saved.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Group tabs. Generated from the enum, so a new group appears here with
         no change to this view. --}}
    <nav class="settings-tabs" aria-label="Settings groups">
        @foreach ($groups as $group)
            <a href="{{ route('admin.settings.index', ['group' => $group->value]) }}"
               @class(['settings-tab', 'is-active' => $group === $activeGroup])
               @if ($group === $activeGroup) aria-current="page" @endif>
                {{ $group->label() }}
                @unless ($group->isPubliclyExposable())
                    <span class="badge badge-private" title="Never sent to the storefront">Private</span>
                @endunless
            </a>
        @endforeach
    </nav>

    <div class="card settings-panel">
        <header class="settings-panel-header">
            <div>
                <h2>{{ $activeGroup->label() }}</h2>
                <p>{{ $activeGroup->description() }}</p>
            </div>

            <form method="POST" action="{{ route('admin.settings.cache.flush') }}">
                @csrf
                <button type="submit" class="btn btn-ghost" title="Drop cached payloads and revalidate the storefront">
                    Clear cache
                </button>
            </form>
        </header>

        @if ($settings->isEmpty())
            <p class="empty">No settings are defined in this group yet.</p>
        @else
            {{-- Media settings post to their own endpoints (multipart, one file
                 at a time), so they are rendered outside the bulk-save form. --}}
            @php
                $mediaSettings = $settings->filter(fn ($setting) => $setting->type->isFileReference());
                $valueSettings = $settings->reject(fn ($setting) => $setting->type->isFileReference());
            @endphp

            @if ($mediaSettings->isNotEmpty())
                <section class="settings-section">
                    <h3>Assets</h3>

                    <div class="media-grid">
                        @foreach ($mediaSettings as $setting)
                            @include('admin.settings.partials.media', ['setting' => $setting])
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($valueSettings->isNotEmpty())
                <form method="POST"
                      action="{{ route('admin.settings.update', ['group' => $activeGroup->value]) }}"
                      class="settings-form">
                    @csrf
                    @method('PUT')

                    @foreach ($valueSettings as $setting)
                        @include('admin.settings.partials.field', ['setting' => $setting])
                    @endforeach

                    <div class="settings-actions">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                        <a href="{{ route('admin.settings.index', ['group' => $activeGroup->value]) }}" class="btn btn-ghost">
                            Discard
                        </a>
                    </div>
                </form>
            @endif
        @endif
    </div>
@endsection

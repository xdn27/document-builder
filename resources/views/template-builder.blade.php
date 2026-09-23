@push('script')
    <script>
        (function() {
            {!! \Maqiis\DocumentBuilder\Asset\AssetLoader::bundledJs() !!}

            function start() {
                const handle = initBuilder({
                    onSelect: (id) => emit('selectBlock', id),
                    onReorder: (zone, ids) => emit('moveBlock', zone, ids),
                    // Satu nilai final per region saat blur; server menulis ke
                    // schema lalu mengirim pratinjau baru seperti perubahan lain.
                    onInlineEdit: (edit) => emit('applyInlineEdit', edit),
                    onRendered: (result) => {
                        const status = document.getElementById('db-page-status');

                        if (!status || !result) return;

                        let text = `${result.pageCount} halaman`;

                        if (result.overflow.length) {
                            text += ` · ${result.overflow.length} blok melimpah`;
                        }

                        if (result.truncated) {
                            text += ' · dipotong pada batas halaman';
                        }

                        status.textContent = text;
                        status.classList.toggle('text-danger', result.overflow.length > 0 || result.truncated);
                    },
                });

                // Tiga baris di bawah ini satu-satunya tempat di package yang tahu
                // perbedaan Livewire 2 vs 3. Livewire 3 mengekspos Livewire.dispatch/
                // Livewire.on; Livewire 2 memakai livewire.emit dan browser event biasa.
                if (isV3()) {
                    window.Livewire.on('document-preview-updated', (payload) => handle.applyPreview(unwrap(payload)));
                    window.Livewire.on('document-builder-notify', (payload) => notify(unwrap(payload)));
                } else {
                    window.addEventListener('document-preview-updated', (event) => handle.applyPreview(event.detail));
                    window.addEventListener('document-builder-notify', (event) => notify(event.detail));
                }
            }

            function isV3() {
                return typeof window.Livewire?.dispatch === 'function';
            }

            function emit(event, ...params) {
                isV3() ? window.Livewire.dispatch(event, params) : window.livewire.emit(event, ...params);
            }

            // Livewire 3 menyerahkan parameter event sebagai array; Livewire 2
            // memberikan detail apa adanya.
            function unwrap(payload) {
                return Array.isArray(payload) ? payload[0] : payload;
            }

            // Package tidak memaksa toaster tertentu (lihat spec §5). Aplikasi
            // mendefinisikan window.documentBuilderNotify untuk menyambungkannya
            // ke toaster miliknya; tanpa itu, pesan jatuh ke console.
            function notify(detail) {
                if (!detail) return;

                if (typeof window.documentBuilderNotify === 'function') {
                    window.documentBuilderNotify(detail);

                    return;
                }

                console[detail.level === 'error' ? 'error' : 'log'](detail.message);
            }

            document.addEventListener('livewire:load', start);   // Livewire 2
            document.addEventListener('livewire:init', start);    // Livewire 3
        })();
    </script>
@endpush

<div class="row g-4">
    <div class="position-absolute top-50 start-50 translate-middle" style="z-index: 9999;" wire:loading>
        <div class="card px-5 shadow">
            <div class="card-body text-center text-body">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="spinner-border spinner-border-sm me-2" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    Loading...
                </div>
            </div>
        </div>
    </div>

    {{-- PANEL KIRI: palet blok, zona, urutan, pengaturan halaman --}}
    <div class="col-12 col-xl-3">
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Zona Aktif</h6>
                <small class="text-muted">Blok baru ditambahkan ke zona ini</small>
            </div>
            <div class="card-body">
                <div class="btn-group w-100" role="group">
                    @foreach (['header' => 'Kop', 'body' => 'Isi', 'footer' => 'Kaki'] as $zone => $label)
                        <button type="button" wire:click="$set('selectedZone', '{{ $zone }}')"
                            class="btn {{ $selectedZone === $zone ? 'btn-primary' : 'btn-label-secondary' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @if ($selectedZone !== 'body')
                    <div class="row g-2 mt-2">
                        <div class="col-7">
                            <label class="form-label small mb-1">Tampil di</label>
                            <select class="form-select form-select-sm"
                                wire:model="schema.zones.{{ $selectedZone }}.repeat">
                                <option value="all">Semua halaman</option>
                                <option value="first-only">Halaman pertama saja</option>
                                <option value="except-first">Selain halaman pertama</option>
                            </select>
                        </div>
                        <div class="col-5">
                            <label class="form-label small mb-1">Tinggi (mm)</label>
                            <input type="number" min="0" max="150" step="1" placeholder="auto"
                                class="form-control form-control-sm"
                                value="{{ is_numeric($schema['zones'][$selectedZone]['height'] ?? null) ? $schema['zones'][$selectedZone]['height'] : '' }}"
                                wire:change="$set('schema.zones.{{ $selectedZone }}.height', $event.target.value)">
                        </div>
                        <div class="col-12">
                            <small class="text-muted">
                                Tinggi tetap membuat PDF paling mendekati hasil cetak browser; kosongkan untuk
                                mengikuti isi.
                            </small>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Tambah Blok</h6>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($blockTypes as $type)
                        <button type="button" class="btn btn-sm btn-label-primary"
                            wire:click="addBlock('{{ $type->value }}', '{{ $selectedZone }}')">
                            <i class="ti ti-plus me-1"></i>{{ $type->label($labelTranslator) }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Urutan Blok</h6>
                <small class="text-muted">Seret untuk menyusun ulang</small>
            </div>
            <ul class="list-group list-group-flush" data-outline-zone="{{ $selectedZone }}">
                @forelse ($schema['zones'][$selectedZone]['blocks'] ?? [] as $block)
                    @php($blockType = \Maqiis\DocumentBuilder\Schema\BlockType::tryFrom($block['type'] ?? ''))
                    <li wire:key="outline-{{ $block['id'] }}" draggable="true" data-outline-id="{{ $block['id'] }}"
                        wire:click="selectBlock('{{ $block['id'] }}')" role="button"
                        class="list-group-item d-flex justify-content-between align-items-center {{ ($block['id'] ?? null) === $selectedId ? 'active' : '' }}">
                        <span>{{ $blockType?->label($labelTranslator) ?? ($block['type'] ?? '?') }}</span>
                        <i class="ti ti-grip-vertical"></i>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Zona ini masih kosong.</li>
                @endforelse
            </ul>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Halaman</h6>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-1">Ukuran</label>
                        <select class="form-select form-select-sm" wire:model="schema.page.size">
                            @foreach (['A4', 'F4', 'Letter', 'Legal'] as $size)
                                <option value="{{ $size }}">{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Orientasi</label>
                        <select class="form-select form-select-sm" wire:model="schema.page.orientation">
                            <option value="portrait">Potret</option>
                            <option value="landscape">Lanskap</option>
                        </select>
                    </div>
                    @foreach (['top' => 'Atas', 'right' => 'Kanan', 'bottom' => 'Bawah', 'left' => 'Kiri'] as $side => $label)
                        <div class="col-6">
                            <label class="form-label small mb-1">Margin {{ $label }} (mm)</label>
                            <input type="number" min="0" max="50" step="1" class="form-control form-control-sm"
                                wire:model.debounce.500ms="schema.page.margin.{{ $side }}">
                        </div>
                    @endforeach
                    <div class="col-12">
                        <label class="form-label small mb-1">Huruf</label>
                        <select class="form-select form-select-sm" wire:model="schema.style.fontFamily">
                            @foreach ($fonts as $key => $font)
                                <option value="{{ $key }}">{{ $font['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Ukuran (pt)</label>
                        <input type="number" min="8" max="24" step="0.5" class="form-control form-control-sm"
                            wire:model.debounce.500ms="schema.style.fontSize">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Tinggi baris</label>
                        <input type="number" min="1" max="3" step="0.1" class="form-control form-control-sm"
                            wire:model.debounce.500ms="schema.style.lineHeight">
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- KANVAS: di luar jangkauan Livewire, dikelola sepenuhnya oleh builder.mjs --}}
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <div>
                    <h6 class="mb-0">
                        {{ $templateName }}
                        @if ($dirty)
                            <span class="badge bg-label-warning ms-1">belum disimpan</span>
                        @endif
                    </h6>
                    @if ($indexRoute)
                        <small class="text-muted">
                            <a href="{{ route($indexRoute) }}">
                                <i class="ti ti-arrow-narrow-left"></i> Daftar template
                            </a>
                        </small>
                    @endif
                </div>
                <div class="d-flex gap-2">
                    @if ($printRoute && (! $printAbility || auth()->user()?->can($printAbility)))
                        <a class="btn btn-sm btn-label-secondary" target="_blank"
                            href="{{ route($printRoute, $template) }}"
                            title="Mencetak versi yang sudah tersimpan">
                            <i class="ti ti-printer me-1"></i>Cetak
                        </a>
                    @endif
                    @if (! $updateAbility || auth()->user()?->can($updateAbility))
                        <button type="button" class="btn btn-sm btn-primary" wire:click="save">
                            <i class="ti ti-device-floppy me-1"></i>Simpan
                        </button>
                    @endif
                </div>
            </div>

            @if ($schemaErrors)
                <div class="alert alert-danger rounded-0 mb-0">
                    <strong>Template belum valid.</strong> Kanvas menampilkan keadaan sah terakhir.
                    <ul class="mb-0 mt-1">
                        @foreach ($schemaErrors as $field => $message)
                            <li><code>{{ $field }}</code> — {{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div wire:ignore>
                <style id="db-document-css">{!! $initialPreview['css'] ?? '' !!}</style>
                <div class="px-3 py-2 border-bottom small text-muted" id="db-page-status">Memaginasi…</div>
                <div class="bg-lighter p-4" style="overflow:auto;max-height:80vh">
                    <div id="db-canvas">{!! $initialPreview['html'] ?? '' !!}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- PANEL KANAN: inspektor blok terpilih dan daftar variabel --}}
    <div class="col-12 col-xl-3">
        @include('document-builder::partials.inspector')
    </div>
</div>

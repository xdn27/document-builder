@php
    $block = $selectedBlock;
    $blockType = $selectedType;
    $path = "schema.zones.{$selectedZone}.blocks.{$selectedIndex}.props";

    // Token variabel dirakit di sini, bukan ditulis literal: kurung kurawal ganda
    // di teks Blade akan dibaca sebagai echo dan merusak kompilasi view.
    $token = fn (string $name): string => '{' . '{ ' . $name . ' }' . '}';
@endphp

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h6 class="mb-0">{{ $blockType?->label($labelTranslator) ?? 'Inspektor' }}</h6>
            <small class="text-muted">
                {{ $block ? 'Zona ' . ['header' => 'kop', 'body' => 'isi', 'footer' => 'kaki'][$selectedZone] : 'Pilih blok untuk menyunting' }}
            </small>
        </div>
        @if ($block)
            <button type="button" class="btn btn-sm btn-label-danger" wire:click="removeSelected" title="Hapus blok">
                <i class="ti ti-trash"></i>
            </button>
        @endif
    </div>

    <div class="card-body">
        @unless ($block)
            <p class="text-muted mb-0">
                Klik sebuah blok di kanvas atau di daftar urutan untuk menyunting propertinya.
            </p>
        @else
            @php
                // Id tab memuat selectedId supaya memilih blok lain selalu mulai
                // dari tab pertama. wire:ignore.self di tombol tab dan panel
                // sengaja dipasang: kelas "active"/"show" di-toggle murni oleh JS
                // Bootstrap, dan tanpa ini Livewire menimpanya balik ke tab pertama
                // tiap kali inspektor dirender ulang (mis. setelah field diketik).
                $groupIds = [];

                foreach (array_keys($describedProps) as $groupName) {
                    $groupIds[$groupName] = 'insp-group-'.md5($selectedId.$groupName);
                }

                $firstGroupName = array_key_first($describedProps);
            @endphp

            <ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" role="tablist">
                @foreach ($groupIds as $groupName => $groupId)
                    @php $isOpen = $groupName === $firstGroupName; @endphp
                    <li class="nav-item" role="presentation" wire:key="tab-{{ $groupId }}">
                        <button type="button" id="{{ $groupId }}-tab" class="nav-link text-nowrap{{ $isOpen ? ' active' : '' }}"
                            wire:ignore.self data-bs-toggle="tab" data-bs-target="#{{ $groupId }}" role="tab"
                            aria-controls="{{ $groupId }}" aria-selected="{{ $isOpen ? 'true' : 'false' }}">
                            {{ $groupName }}
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content p-0">
                @foreach ($describedProps as $groupName => $groupDefinitions)
                    @php
                        $groupId = $groupIds[$groupName];
                        $isOpen = $groupName === $firstGroupName;
                    @endphp
                    <div id="{{ $groupId }}" class="tab-pane fade{{ $isOpen ? ' show active' : '' }}" role="tabpanel"
                        aria-labelledby="{{ $groupId }}-tab" wire:ignore.self wire:key="pane-{{ $groupId }}">
                        <div class="row g-3">
                            @foreach ($groupDefinitions as $key => $definition)
                                @php
                                    // Field ringkas (centang, angka, pilihan) ditata dua kolom
                                    // berdampingan supaya panel tidak jadi tumpukan satu kolom yang
                                    // panjang; field yang butuh ruang (teks bebas, daftar baris,
                                    // gambar) tetap penuh lebar.
                                    $isCompact = in_array($definition['type'], ['bool', 'float', 'enum'], true);
                                @endphp
                                <div class="{{ $isCompact ? 'col-12 col-sm-6' : 'col-12' }}" wire:key="prop-{{ $selectedId }}-{{ $key }}">
                                    @if ($definition['type'] === 'bool')
                                        <div class="form-check pt-1">
                                            <input id="prop-{{ $key }}" type="checkbox" class="form-check-input"
                                                wire:model="{{ $path }}.{{ $key }}">
                                            <label class="form-check-label" for="prop-{{ $key }}">{{ $definition['label'] }}</label>
                                        </div>
                                    @else
                                        <label class="form-label small mb-1" for="prop-{{ $key }}">{{ $definition['label'] }}</label>

                                        @switch($definition['type'])
                                            @case('float')
                                                <input id="prop-{{ $key }}" type="number" class="form-control form-control-sm"
                                                    step="0.1" min="{{ $definition['min'] }}" max="{{ $definition['max'] }}"
                                                    wire:model.debounce.500ms="{{ $path }}.{{ $key }}">
                                            @break

                                            @case('enum')
                                                <select id="prop-{{ $key }}" class="form-select form-select-sm"
                                                    wire:model="{{ $path }}.{{ $key }}">
                                                    @foreach ($definition['valueLabels'] as $value => $valueLabel)
                                                        <option value="{{ $value }}">{{ $valueLabel }}</option>
                                                    @endforeach
                                                </select>
                                            @break

                                            @case('rows')
                                                @php
                                                    // widthPercent/level/align ditata berdampingan (ringkas,
                                                    // lebar tetap) di bawah field teks bebas, yang selalu
                                                    // penuh lebar karena isinya bisa panjang.
                                                    $compactRowKeys = array_intersect(['widthPercent', 'level', 'align'], array_keys($definition['keys']));
                                                @endphp
                                                @foreach ($block['props'][$key] ?? [] as $rowIndex => $row)
                                                    <div class="border rounded p-2 mb-2" wire:key="row-{{ $selectedId }}-{{ $key }}-{{ $rowIndex }}">
                                                        @foreach ($definition['keys'] as $rowKey => $default)
                                                            @continue(in_array($rowKey, ['widthPercent', 'level', 'align', 'signature'], true))
                                                            <input type="text" class="form-control form-control-sm mb-2"
                                                                placeholder="{{ $rowKey }}"
                                                                wire:model.debounce.500ms="{{ $path }}.{{ $key }}.{{ $rowIndex }}.{{ $rowKey }}">
                                                        @endforeach

                                                        @if ($compactRowKeys !== [])
                                                            <div class="d-flex gap-2 mb-2">
                                                                @foreach ($compactRowKeys as $rowKey)
                                                                    <div style="width:6.5rem">
                                                                        @if ($rowKey === 'align')
                                                                            <select class="form-select form-select-sm"
                                                                                wire:model="{{ $path }}.{{ $key }}.{{ $rowIndex }}.{{ $rowKey }}">
                                                                                @foreach (['left', 'center', 'right'] as $value)
                                                                                    <option value="{{ $value }}">{{ $catalog->valueLabel($value) }}</option>
                                                                                @endforeach
                                                                            </select>
                                                                        @else
                                                                            <input type="number" class="form-control form-control-sm"
                                                                                min="0" max="{{ $rowKey === 'level' ? 2 : 100 }}"
                                                                                placeholder="{{ $rowKey === 'level' ? 'Tingkat' : 'Lebar %' }}"
                                                                                wire:model.debounce.500ms="{{ $path }}.{{ $key }}.{{ $rowIndex }}.{{ $rowKey }}">
                                                                        @endif
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @endif

                                                        @if (array_key_exists('signature', $definition['keys']))
                                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                                @if (! empty($row['signature']))
                                                                    <img src="{{ $row['signature'] }}" alt=""
                                                                        style="height:2rem;max-width:5rem;object-fit:contain"
                                                                        class="border rounded bg-light p-1">
                                                                @endif
                                                                <label for="prop-{{ $key }}-{{ $rowIndex }}-signature" class="btn btn-sm btn-label-primary mb-0" tabindex="0">
                                                                    <i class="ti ti-upload me-1"></i>Gambar tanda tangan
                                                                    <input id="prop-{{ $key }}-{{ $rowIndex }}-signature" type="file" hidden
                                                                        accept="image/png,image/jpeg,image/webp"
                                                                        wire:model="imageUpload.{{ $key }}.{{ $rowIndex }}.signature">
                                                                </label>
                                                                <span wire:loading wire:target="imageUpload.{{ $key }}.{{ $rowIndex }}.signature" class="spinner-border spinner-border-sm"></span>
                                                            </div>
                                                            @error('imageUpload.'.$key.'.'.$rowIndex.'.signature')
                                                                <div class="text-danger small mb-2">{{ $message }}</div>
                                                            @enderror
                                                            <input type="text" class="form-control form-control-sm mb-2"
                                                                placeholder="atau tempel data URI / URL gambar"
                                                                wire:model.debounce.500ms="{{ $path }}.{{ $key }}.{{ $rowIndex }}.signature">
                                                        @endif

                                                        <button type="button" class="btn btn-sm btn-text-danger p-0"
                                                            wire:click="removeRow('{{ $key }}', {{ $rowIndex }})">Hapus</button>
                                                    </div>
                                                @endforeach
                                                <button type="button" class="btn btn-sm btn-label-primary"
                                                    wire:click="addRow('{{ $key }}')">
                                                    <i class="ti ti-plus me-1"></i>Tambah
                                                </button>
                                            @break

                                            @case('image')
                                                <div class="d-flex align-items-center gap-2 mb-2">
                                                    @if (! empty($block['props'][$key]))
                                                        <img src="{{ $block['props'][$key] }}" alt=""
                                                            style="height:2.5rem;max-width:6rem;object-fit:contain"
                                                            class="border rounded bg-light p-1">
                                                    @endif
                                                    <label for="prop-{{ $key }}-upload" class="btn btn-sm btn-label-primary mb-0" tabindex="0">
                                                        <i class="ti ti-upload me-1"></i>Unggah gambar
                                                        <input id="prop-{{ $key }}-upload" type="file" hidden
                                                            accept="image/png,image/jpeg,image/webp"
                                                            wire:model="imageUpload.{{ $key }}">
                                                    </label>
                                                    <span wire:loading wire:target="imageUpload.{{ $key }}" class="spinner-border spinner-border-sm"></span>
                                                </div>
                                                @error('imageUpload.'.$key)
                                                    <div class="text-danger small mb-2">{{ $message }}</div>
                                                @enderror
                                                <input id="prop-{{ $key }}" type="text" class="form-control form-control-sm"
                                                    placeholder="atau tempel data URI / URL gambar"
                                                    wire:model.debounce.500ms="{{ $path }}.{{ $key }}">
                                                <small class="text-muted">PNG, JPG, atau WEBP, maksimal 2 MB.</small>
                                            @break

                                            @case('matrix')
                                                @foreach ($block['props'][$key] ?? [] as $rowIndex => $row)
                                                    <div class="d-flex gap-2 mb-2" wire:key="cell-{{ $selectedId }}-{{ $rowIndex }}">
                                                        @foreach ($row as $cellIndex => $cell)
                                                            <input type="text" class="form-control form-control-sm"
                                                                wire:model.debounce.500ms="{{ $path }}.{{ $key }}.{{ $rowIndex }}.{{ $cellIndex }}">
                                                        @endforeach
                                                        <button type="button" class="btn btn-sm btn-text-danger px-1"
                                                            wire:click="removeRow('{{ $key }}', {{ $rowIndex }})" title="Hapus baris">
                                                            <i class="ti ti-x"></i>
                                                        </button>
                                                    </div>
                                                @endforeach
                                                <button type="button" class="btn btn-sm btn-label-primary"
                                                    wire:click="addRow('{{ $key }}')">
                                                    <i class="ti ti-plus me-1"></i>Tambah baris
                                                </button>
                                            @break

                                            @default
                                                @if (in_array($key, ['text', 'payload'], true))
                                                    <textarea id="prop-{{ $key }}" class="form-control form-control-sm" rows="4"
                                                        wire:model.debounce.500ms="{{ $path }}.{{ $key }}"></textarea>
                                                    @if ($key === 'text')
                                                        <small class="text-muted">Boleh memakai &lt;b&gt;, &lt;i&gt;, &lt;u&gt;, &lt;br&gt;.</small>
                                                    @endif
                                                @else
                                                    <input id="prop-{{ $key }}" type="text" class="form-control form-control-sm"
                                                        wire:model.debounce.500ms="{{ $path }}.{{ $key }}">
                                                @endif
                                        @endswitch
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endunless
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Variabel</h6>
        <small class="text-muted">Salin ke teks blok; nilai contoh dipakai di kanvas</small>
    </div>
    <div class="card-body" style="max-height:45vh;overflow:auto">
        @foreach ($variables as $group => $entries)
            <div class="mb-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">{{ $group }}</div>
                @foreach ($entries as $entry)
                    <div class="d-flex justify-content-between align-items-start gap-2 py-1 border-bottom">
                        <span class="small">{{ $entry['label'] }}</span>
                        <code class="small text-nowrap user-select-all">{{ $token($entry['path']) }}</code>
                    </div>
                @endforeach
            </div>
        @endforeach
        <div class="small text-muted">
            Nomor halaman di zona kaki: <code class="user-select-all">{{ $token('page') }}</code> dari
            <code class="user-select-all">{{ $token('pages') }}</code>.
        </div>
    </div>
</div>

<?php

namespace Maqiis\DocumentBuilder\Laravel\Livewire;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;
use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Laravel\Concerns\TemplateRecord;
use Maqiis\DocumentBuilder\Laravel\DocumentRenderer;
use Maqiis\DocumentBuilder\Laravel\ImageUploadStorage;
use Maqiis\DocumentBuilder\Schema\BlockPropSchema;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Schema\LabelTranslator;
use Maqiis\DocumentBuilder\Schema\PropCatalog;
use Maqiis\DocumentBuilder\Schema\SchemaValidationException;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;

/**
 * Halaman penyusun tiga panel. Livewire mengurus panel; kanvas berada di dalam
 * wire:ignore dan sepenuhnya dikelola builder.mjs, karena paginator mengganti
 * DOM kanvas dan akan bertabrakan dengan diffing Livewire.
 *
 * Komponen ini netral terhadap tiga hal sekaligus: model konsumen (lewat
 * TemplateRecord), versi Livewire (lewat dispatchToBrowser()), dan toaster yang
 * dipakai aplikasi (lewat event document-builder-notify). Tidak ada nama route
 * maupun ability yang ditulis di sini — semuanya dari config.
 */
class TemplateBuilder extends Component
{
    use WithFileUploads;

    public const ZONES = ['header', 'body', 'footer'];

    public TemplateRecord $template;

    public array $schema = [];

    public ?string $selectedId = null;

    public string $selectedZone = 'body';

    /** @var array<string,string> */
    public array $schemaErrors = [];

    public bool $dirty = false;

    /**
     * Nomor urut pratinjau. Klien membuang payload yang revision-nya lebih kecil
     * dari yang terakhir dilukis, supaya dua perubahan beruntun yang selesai di
     * luar urutan tidak memundurkan kanvas (spec §9.1).
     */
    public int $revision = 0;

    /**
     * Berkas yang baru dipilih dari tombol unggah di inspektor, berbentuk pohon
     * sesuai jalur propertinya sendiri — "src" untuk kop gambar, atau
     * "columns.0.signature" untuk gambar tanda tangan kolom pertama. Diproses
     * lalu dibuang di applyImageUpload(); ini bukan bagian dari schema, hanya
     * wadah sementara berkas yang diunggah.
     *
     * Sengaja tanpa tipe berkas Livewire: kelas TemporaryUploadedFile berpindah
     * namespace antara Livewire 2 dan 3, dan induknya (Illuminate\Http\
     * UploadedFile) sudah cukup untuk ImageUploadStorage.
     *
     * @var array<string,mixed>
     */
    public array $imageUpload = [];

    /**
     * Berkas JSON schema yang baru dipilih dari tombol impor di toolbar.
     * Wadah sementara seperti $imageUpload di atas — dibaca lalu dibuang di
     * applySchemaImport(); tidak pernah menjadi bagian dari $schema sendiri.
     * Sengaja tanpa tipe, dengan alasan yang sama dengan $imageUpload.
     */
    public $importFile;

    protected $listeners = ['selectBlock', 'moveBlock', 'applyInlineEdit'];

    /**
     * Hanya terisi pada request pertama: dicetak langsung ke kanvas. Request
     * berikutnya mengirim pratinjau lewat browser event, bukan lewat HTML komponen.
     */
    protected ?array $initialPreview = null;

    public function mount(TemplateRecord $template): void
    {
        $template->authorizeTemplateView();

        $this->template = $template;
        $this->schema = $template->getTemplateSchema() ?: Template::blank()->toArray();
        $this->initialPreview = $this->preview();
    }

    /**
     * Satu-satunya tempat di komponen ini yang tahu soal versi Livewire.
     * Livewire 2 mengirim browser event lewat dispatchBrowserEvent(); Livewire 3
     * menghapus method itu dan memakai dispatch(), yang juga sampai ke JS lewat
     * Livewire.on(). Sisi Blade menyambungkan keduanya (lihat view).
     */
    protected function dispatchToBrowser(string $event, array $payload): void
    {
        if (method_exists($this, 'dispatchBrowserEvent')) {
            $this->dispatchBrowserEvent($event, $payload);

            return;
        }

        $this->dispatch($event, ...$payload);
    }

    /**
     * Pengganti netral untuk paket toaster: package tidak boleh memaksa dua
     * aplikasi memakai toaster yang sama (spec §5). Aplikasi menyambungkan
     * event ini ke toaster pilihannya di view yang sudah publishable.
     */
    protected function notify(string $level, string $message): void
    {
        $this->dispatchToBrowser('document-builder-notify', ['level' => $level, 'message' => $message]);
    }

    public function updated($name, $value): void
    {
        if (str_starts_with((string) $name, 'imageUpload.')) {
            $this->applyImageUpload(substr((string) $name, strlen('imageUpload.')));

            return;
        }

        if ($name === 'importFile') {
            $this->applySchemaImport();

            return;
        }

        if (! str_starts_with((string) $name, 'schema.')) {
            return;
        }

        // Kolom tinggi zona dibiarkan kosong berarti "auto".
        if (preg_match('/^schema\.zones\.(header|footer)\.height$/', (string) $name) && trim((string) $value) === '') {
            data_set($this->schema, substr((string) $name, strlen('schema.')), 'auto');
        }

        $this->schemaChanged();
    }

    /**
     * Bentuk src yang dihasilkan (data URI, URL disk lokal, atau URL S3)
     * ditentukan ImageUploadStorage yang terpasang di container — lihat
     * config('document-builder.images.upload_strategy'). Komponen ini tidak
     * perlu tahu bedanya, hanya menulis apa pun yang dikembalikan ke schema.
     *
     * $propPath boleh berjenjang (mis. "columns.0.signature" untuk gambar tanda
     * tangan di kolom tertentu), bukan cuma nama properti langsung seperti "src"
     * pada kop gambar — dot-notation dipakai supaya kedua kasus lewat kode yang sama.
     */
    private function applyImageUpload(string $propPath): void
    {
        $index = $this->selectedBlockIndex();
        $file = data_get($this->imageUpload, $propPath);

        if ($index === null || ! $file) {
            return;
        }

        $this->validate([
            "imageUpload.{$propPath}" => 'image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $src = app(ImageUploadStorage::class)->store($file);

        data_set($this->schema, "zones.{$this->selectedZone}.blocks.{$index}.props.{$propPath}", $src);
        Arr::forget($this->imageUpload, $propPath);

        $this->schemaChanged();
    }

    /**
     * Ganti $schema dari berkas JSON yang diunggah lewat tombol Impor.
     * Lewat jalur validasi + migrasi yang sama dengan save() (Template::
     * fromArray()), supaya schema versi lama tetap dinaikkan dan schema yang
     * cacat/terlalu besar ditolak sebelum sempat menimpa kanvas — kanvas
     * tetap menampilkan keadaan sah terakhir kalau impor gagal, persis
     * seperti perilaku save() dan preview().
     */
    private function applySchemaImport(): void
    {
        if (! $this->importFile) {
            return;
        }

        $this->validate([
            'importFile' => 'file|mimetypes:application/json,text/plain|max:2048',
        ]);

        $decoded = json_decode((string) file_get_contents($this->importFile->getRealPath()), true);
        $this->importFile = null;

        if (! is_array($decoded)) {
            $this->notify('error', 'Berkas bukan JSON schema yang valid.');

            return;
        }

        try {
            $validated = Template::fromArray($decoded, SchemaValidator::MAX_BYTES);
        } catch (SchemaValidationException $e) {
            $this->schemaErrors = $e->errors();
            $this->notify('error', 'Schema yang diimpor tidak valid.');

            return;
        }

        $this->schema = $validated->toArray();
        $this->selectedId = null;
        $this->notify('success', 'Schema berhasil diimpor. Klik Simpan untuk menyimpan perubahan.');
        $this->schemaChanged();
    }

    public function addBlock(string $type, string $zone): void
    {
        $blockType = BlockType::tryFrom($type);

        if ($blockType === null || ! in_array($zone, self::ZONES, true)) {
            return;
        }

        $id = (string) Str::uuid();

        $this->schema['zones'][$zone]['blocks'][] = [
            'id' => $id,
            'type' => $blockType->value,
            'props' => array_replace(BlockPropSchema::defaults($blockType), $this->starterProps($blockType)),
        ];

        $this->selectedZone = $zone;
        $this->selectedId = $id;

        $this->schemaChanged();
    }

    public function selectBlock(string $id): void
    {
        foreach (self::ZONES as $zone) {
            foreach ($this->schema['zones'][$zone]['blocks'] ?? [] as $block) {
                if (($block['id'] ?? null) === $id) {
                    $this->selectedZone = $zone;
                    $this->selectedId = $id;

                    return;
                }
            }
        }
    }

    /**
     * Commit sunting inline dari kanvas (builder.mjs → onInlineEdit). Alamat
     * region datang dari atribut data-edit-* yang dicetak RenderContext::editAttr():
     * prop bertipe string diisi langsung, rows lewat indeks baris + kunci, dan
     * matrix lewat indeks baris + kolom. Alamat yang tidak cocok dengan schema
     * diabaikan — isi template tidak pernah bertambah struktur dari sini.
     *
     * @param  array{blockId?:mixed,prop?:mixed,row?:mixed,col?:mixed,key?:mixed,value?:mixed}  $edit
     */
    public function applyInlineEdit(array $edit): void
    {
        $blockId = $edit['blockId'] ?? null;
        $prop = $edit['prop'] ?? null;
        $value = $edit['value'] ?? null;

        if (! is_string($blockId) || ! is_string($prop) || ! is_string($value)) {
            return;
        }

        foreach (self::ZONES as $zone) {
            foreach ($this->schema['zones'][$zone]['blocks'] ?? [] as $index => $block) {
                if (($block['id'] ?? null) !== $blockId) {
                    continue;
                }

                $path = $this->inlineEditPath($block, $prop, $edit);

                if ($path === null) {
                    return;
                }

                data_set($this->schema, "zones.{$zone}.blocks.{$index}.props.{$path}", $value);
                $this->selectBlock($blockId);
                $this->schemaChanged();

                return;
            }
        }
    }

    /** Jalur dot-notation di dalam props, atau null bila alamat tidak sah. */
    private function inlineEditPath(array $block, string $prop, array $edit): ?string
    {
        $blockType = BlockType::tryFrom((string) ($block['type'] ?? ''));
        $definition = $blockType ? BlockPropSchema::for($blockType)[$prop] ?? null : null;
        $current = $block['props'][$prop] ?? null;
        $row = $edit['row'] ?? null;

        if ($definition === null) {
            return null;
        }

        if ($definition['type'] === 'string') {
            return $prop;
        }

        if (! is_int($row) || ! is_array($current) || ! is_array($current[$row] ?? null)) {
            return null;
        }

        if ($definition['type'] === 'rows') {
            $key = $edit['key'] ?? null;

            // Hanya kunci bertipe teks (mis. bukan 'level' milik daftar).
            return is_string($key) && is_string($definition['keys'][$key] ?? null) ? "{$prop}.{$row}.{$key}" : null;
        }

        if ($definition['type'] === 'matrix') {
            $col = $edit['col'] ?? null;

            return is_int($col) && array_key_exists($col, $current[$row]) ? "{$prop}.{$row}.{$col}" : null;
        }

        return null;
    }

    public function removeSelected(): void
    {
        if ($this->selectedId === null) {
            return;
        }

        $this->schema['zones'][$this->selectedZone]['blocks'] = array_values(array_filter(
            $this->schema['zones'][$this->selectedZone]['blocks'] ?? [],
            fn (array $block): bool => ($block['id'] ?? null) !== $this->selectedId,
        ));

        $this->selectedId = null;

        $this->schemaChanged();
    }

    /** @param  list<string>  $orderedIds */
    public function moveBlock(string $zone, array $orderedIds): void
    {
        if (! in_array($zone, self::ZONES, true)) {
            return;
        }

        $byId = [];

        foreach ($this->schema['zones'][$zone]['blocks'] ?? [] as $block) {
            $byId[$block['id']] = $block;
        }

        $reordered = [];

        foreach ($orderedIds as $id) {
            if (is_string($id) && isset($byId[$id])) {
                $reordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        // Blok yang tidak disebut urutan baru tetap dipertahankan di belakang,
        // supaya permintaan yang cacat tidak pernah menghilangkan isi template.
        $this->schema['zones'][$zone]['blocks'] = array_merge($reordered, array_values($byId));

        $this->schemaChanged();
    }

    public function addRow(string $propKey): void
    {
        $index = $this->selectedBlockIndex();

        if ($index === null) {
            return;
        }

        $block = $this->schema['zones'][$this->selectedZone]['blocks'][$index];
        $blockType = BlockType::from($block['type']);
        $definition = BlockPropSchema::for($blockType)[$propKey] ?? null;

        if ($definition === null) {
            return;
        }

        if ($definition['type'] === 'rows') {
            $this->schema['zones'][$this->selectedZone]['blocks'][$index]['props'][$propKey][] = $definition['keys'];

            // Tabel: 'columns' dan 'rows' (data) disimpan terpisah tapi sel tiap
            // baris data diindeks mengikuti posisi kolom, jadi kolom baru butuh
            // sel kosong baru di setiap baris data supaya keduanya tetap selaras.
            if ($blockType === BlockType::Table && $propKey === 'columns') {
                $this->syncTableRowsToColumnCount($index);
            }
        } elseif ($definition['type'] === 'matrix') {
            $columns = count($block['props']['columns'] ?? []);
            $this->schema['zones'][$this->selectedZone]['blocks'][$index]['props'][$propKey][] = array_fill(0, max(1, $columns), '');
        } else {
            return;
        }

        $this->schemaChanged();
    }

    public function removeRow(string $propKey, int $rowIndex): void
    {
        $index = $this->selectedBlockIndex();

        if ($index === null) {
            return;
        }

        $block = $this->schema['zones'][$this->selectedZone]['blocks'][$index];
        $rows = $block['props'][$propKey] ?? [];

        if (! is_array($rows) || ! array_key_exists($rowIndex, $rows)) {
            return;
        }

        unset($rows[$rowIndex]);
        $this->schema['zones'][$this->selectedZone]['blocks'][$index]['props'][$propKey] = array_values($rows);

        // Lihat catatan yang sama di addRow(): kolom yang dihapus harus membuang
        // sel pada posisi yang sama di setiap baris data, bukan cuma di 'columns'.
        if (BlockType::from($block['type']) === BlockType::Table && $propKey === 'columns') {
            $this->removeTableCellAt($index, $rowIndex);
        }

        $this->schemaChanged();
    }

    private function syncTableRowsToColumnCount(int $index): void
    {
        $columnCount = count($this->schema['zones'][$this->selectedZone]['blocks'][$index]['props']['columns'] ?? []);

        foreach ($this->schema['zones'][$this->selectedZone]['blocks'][$index]['props']['rows'] ?? [] as $rowIndex => $row) {
            $cells = array_pad($row, $columnCount, '');
            $this->schema['zones'][$this->selectedZone]['blocks'][$index]['props']['rows'][$rowIndex] = array_slice($cells, 0, $columnCount);
        }
    }

    private function removeTableCellAt(int $index, int $columnIndex): void
    {
        foreach ($this->schema['zones'][$this->selectedZone]['blocks'][$index]['props']['rows'] ?? [] as $rowIndex => $row) {
            unset($row[$columnIndex]);
            $this->schema['zones'][$this->selectedZone]['blocks'][$index]['props']['rows'][$rowIndex] = array_values($row);
        }
    }

    public function save(): void
    {
        $this->template->authorizeTemplateUpdate();

        try {
            // save() adalah jalur TULIS — batas ukuran ditegakkan eksplisit di
            // sini. preview() di bawah sengaja TIDAK menyebutkan $maxBytes,
            // supaya template yang SUDAH tersimpan di atas batas (dari versi
            // package sebelumnya) tetap bisa dibuka dan disunting, bukan
            // sekadar diam-diam ditolak setiap kali dibuka.
            $validated = Template::fromArray($this->schema, SchemaValidator::MAX_BYTES);
        } catch (SchemaValidationException $e) {
            $this->schemaErrors = $e->errors();
            $this->notify('error', 'Template belum bisa disimpan karena ada isian yang tidak valid.');

            return;
        }

        $this->template->setTemplateSchema($validated->toArray());

        // Simpan bentuk yang sudah dinormalkan validator, supaya angka dari input
        // teks tersimpan sebagai angka dan properti asing tidak ikut terbawa.
        $this->schema = $validated->toArray();
        $this->schemaErrors = [];
        $this->dirty = false;
        $this->notify('success', 'Template berhasil disimpan.');
    }

    /**
     * Kirim schema saat ini ke klien sebagai berkas unduhan. Ini jalur BACA
     * seperti preview() — sengaja TIDAK menyebutkan $maxBytes. Template lama
     * yang sudah tersimpan di atas batas (dari versi package sebelumnya)
     * justru paling butuh diekspor (mis. untuk dipangkas manual atau
     * dipindah), jadi export tidak boleh ikut menegakkan batas tulis yang
     * hanya masuk akal saat MENERIMA schema baru (lihat save() dan
     * Template::fromArray()). Tetap lewat fromArray()/toArray() supaya yang
     * diunduh berbentuk normal (angka sebagai angka, properti asing dibuang).
     */
    public function exportSchema(): void
    {
        try {
            $validated = Template::fromArray($this->schema);
        } catch (SchemaValidationException $e) {
            $this->schemaErrors = $e->errors();
            $this->notify('error', 'Template belum bisa diekspor karena ada isian yang tidak valid.');

            return;
        }

        $this->dispatchToBrowser('document-schema-exported', [
            'schema' => $validated->toArray(),
            'filename' => Str::slug($this->template->getTemplateName() ?: 'template').'.json',
        ]);
    }

    public function selectedBlockIndex(): ?int
    {
        foreach ($this->schema['zones'][$this->selectedZone]['blocks'] ?? [] as $index => $block) {
            if (($block['id'] ?? null) === $this->selectedId) {
                return $index;
            }
        }

        return null;
    }

    private function schemaChanged(): void
    {
        $this->dirty = true;

        $preview = $this->preview();

        if ($preview === null) {
            return;
        }

        $this->revision++;

        $this->dispatchToBrowser('document-preview-updated', $preview + [
            'selectedId' => $this->selectedId,
            'revision' => $this->revision,
        ]);
    }

    /** @return array{html:string,css:string}|null null bila schema belum valid */
    private function preview(): ?array
    {
        try {
            // Mode editable: region teks ditandai data-edit-* untuk sunting inline.
            $document = app(DocumentRenderer::class)->render(Template::fromArray($this->schema), editable: true);
        } catch (SchemaValidationException $e) {
            // Kanvas ditahan pada keadaan sah terakhir; panel menampilkan galatnya.
            $this->schemaErrors = $e->errors();

            return null;
        }

        $this->schemaErrors = [];

        return ['html' => $document->flowHtml(), 'css' => $document->css()];
    }

    /** Isi awal yang langsung terlihat, supaya blok baru tidak muncul sebagai penanda kosong. */
    private function starterProps(BlockType $type): array
    {
        $palette = config('document-builder.livewire.block_palette', []);

        return is_array($palette[$type->value] ?? null) ? $palette[$type->value] : [];
    }

    public function render()
    {
        $catalog = app(PropCatalog::class);
        $selectedIndex = $this->selectedBlockIndex();
        $blocks = $this->schema['zones'][$this->selectedZone]['blocks'] ?? [];
        $selectedBlock = $selectedIndex !== null ? $blocks[$selectedIndex] ?? null : null;
        $selectedType = $selectedBlock ? BlockType::tryFrom($selectedBlock['type'] ?? '') : null;

        return view('document-builder::template-builder', [
            'catalog' => $catalog,
            'labelTranslator' => app(LabelTranslator::class),
            'blockTypes' => BlockType::cases(),
            'variables' => app(VariableRegistry::class)->groups(),
            'fonts' => FontRegistry::all(),
            'selectedIndex' => $selectedIndex,
            'selectedBlock' => $selectedBlock,
            'selectedType' => $selectedType,
            'describedProps' => $selectedType ? $catalog->describe($selectedType) : [],
            'initialPreview' => $this->initialPreview,
            'templateName' => $this->template->getTemplateName(),
            'indexRoute' => config('document-builder.livewire.index_route'),
            'printRoute' => config('document-builder.livewire.print_route'),
            'printAbility' => config('document-builder.livewire.print_ability'),
            'updateAbility' => config('document-builder.livewire.update_ability'),
        ]);
    }
}

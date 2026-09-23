# Contoh

Berkas di sini **disalin** ke aplikasi Anda — tidak di-autoload dan tidak ikut dijalankan package.
Strukturnya meniru letak berkas di aplikasi Laravel, jadi jalur di dalamnya adalah jalur tujuan.

| Folder | Untuk |
|---|---|
| `livewire/` | Aplikasi Blade + Livewire: model, katalog variabel, route, controller pembungkus, view |
| `inertia-react/` | Aplikasi Inertia + React: controller (props, pratinjau, simpan), halaman React, alias Vite |

Keduanya memakai tabel dari `php artisan document-builder:install` (kolom `name` dan `schema`).
Test package (`ExamplesTest`, `tests/js/examples.test.mjs`) menjaga contoh ini tetap ter-parse dan
setiap impor ke paket tetap menunjuk kelas atau fungsi yang ada.

{{--
    Layout minimal. View builder memakai kelas Bootstrap 5 — muat stylesheet
    Bootstrap lewat layout aplikasi Anda.

    Yang TIDAK boleh terlewat: @stack('script') sesudah @livewireScripts. View
    builder mendorong skrip kanvasnya ke stack bernama 'script' (tunggal); layout
    yang hanya punya @stack('scripts') membuat kanvas tidak pernah hidup, tanpa
    error apa pun di console.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Susun Surat — {{ $template->getTemplateName() }}</title>
    @livewireStyles
</head>
<body class="p-4">
    @livewire('document-builder::template-builder', ['template' => $template])

    @livewireScripts
    @stack('script')

    <script>
        // Package tidak memaksa toaster tertentu: sambungkan event notifikasinya
        // ke toaster aplikasi Anda di sini.
        window.documentBuilderNotify = (detail) => window.alert(detail.message);
    </script>
</body>
</html>

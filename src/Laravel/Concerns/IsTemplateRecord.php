<?php

namespace Maqiis\DocumentBuilder\Laravel\Concerns;

/**
 * Jalur Eloquent untuk TemplateRecord. Model konsumen cukup `use` trait ini;
 * kolom dan nama ability bisa dioverride lewat kedua property di bawah.
 *
 * authorizeTemplateView() SENGAJA tidak ada di sini. Trait ini tidak tahu apakah
 * aplikasinya memakai scoping cabang, tenant, atau tidak sama sekali — dan
 * default no-op akan menghilangkan scoping itu secara diam-diam pada aplikasi
 * yang lupa mengisinya. Karena method itu ada di interface tapi tidak di trait,
 * PHP menolak kelas yang tidak menuliskannya: badan kosong yang disengaja masih
 * boleh, kelalaian tidak.
 */
trait IsTemplateRecord
{
    protected string $templateSchemaColumn = 'schema';

    protected string $templateNameColumn = 'name';

    protected string $templateUpdateAbility = 'update-document-template';

    public function getTemplateSchema(): array
    {
        $schema = $this->{$this->templateSchemaColumn} ?? [];

        return is_array($schema) ? $schema : [];
    }

    public function setTemplateSchema(array $schema): void
    {
        $this->update([$this->templateSchemaColumn => $schema]);
    }

    public function getTemplateName(): string
    {
        return (string) ($this->{$this->templateNameColumn} ?? '');
    }

    public function authorizeTemplateUpdate(): void
    {
        abort_unless(auth()->user()?->can($this->templateUpdateAbility) ?? false, 403);
    }
}

import { useEffect, useRef, useState } from 'react';
import { paginateDocument } from '@document-builder/paginate-dom.mjs';

const SUPPORTED_CONTRACT = 1;

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function send(url, method, body, signal) {
    const response = await fetch(url, {
        method,
        signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
    });

    return { ok: response.ok, data: await response.json() };
}

function defaultsFor(described) {
    const props = {};

    Object.values(described ?? {}).forEach((group) => {
        Object.entries(group).forEach(([key, definition]) => {
            props[key] = definition.default;
        });
    });

    return props;
}

function Field({ name, definition, value, onChange }) {
    if (definition.type === 'bool') {
        return (
            <label className="form-check d-block">
                <input type="checkbox" className="form-check-input" checked={!!value} onChange={(e) => onChange(name, e.target.checked)} />
                {' '}{definition.label}
            </label>
        );
    }

    if (definition.type === 'enum') {
        return (
            <label className="d-block">
                {definition.label}
                <select className="form-select form-select-sm" value={value ?? ''} onChange={(e) => onChange(name, e.target.value)}>
                    {definition.values.map((v) => <option key={v} value={v}>{definition.valueLabels[v]}</option>)}
                </select>
            </label>
        );
    }

    if (definition.type === 'float') {
        return (
            <label className="d-block">
                {definition.label}
                <input type="number" className="form-control form-control-sm" min={definition.min} max={definition.max} step="0.1"
                    value={value ?? ''} onChange={(e) => onChange(name, Number(e.target.value))} />
            </label>
        );
    }

    if (definition.type === 'string' || definition.type === 'image') {
        return (
            <label className="d-block">
                {definition.label}
                <input className="form-control form-control-sm" value={value ?? ''} onChange={(e) => onChange(name, e.target.value)} />
            </label>
        );
    }

    // rows & matrix butuh editor sendiri; contoh ini sengaja tidak mengurusnya.
    return <p className="text-muted small">{definition.label}: butuh editor khusus.</p>;
}

export default function Builder({ template, schema: initialSchema, contract }) {
    if (contract.contract !== SUPPORTED_CONTRACT) {
        // Gagal keras, jangan merender inspektor separuh jadi.
        throw new Error(`Kontrak document-builder ${contract.contract} belum didukung halaman ini.`);
    }

    const [schema, setSchema] = useState(initialSchema);
    const [selected, setSelected] = useState(null);
    const [errors, setErrors] = useState({});
    const canvasRef = useRef(null);
    const styleRef = useRef(null);
    const revision = useRef(0);
    const painted = useRef(0);

    useEffect(() => {
        const current = ++revision.current;
        const controller = new AbortController();

        // Debounce + batalkan request yang masih terbang + buang respons yang
        // lebih tua dari yang terakhir dilukis.
        const timer = setTimeout(async () => {
            try {
                const { ok, data } = await send(`/letter-templates/${template.id}/preview`, 'POST', { schema, revision: current }, controller.signal);

                if (data.revision < painted.current) return;
                if (!ok) { setErrors(data.errors ?? {}); return; }

                painted.current = data.revision;
                setErrors({});
                styleRef.current.textContent = data.css;
                canvasRef.current.innerHTML = data.html;

                await paginateDocument({ document, root: canvasRef.current.querySelector('.doc-root') });
            } catch (error) {
                if (error.name !== 'AbortError') throw error;
            }
        }, 400);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [schema]);

    const blocks = schema.zones.body.blocks;
    const block = blocks.find((b) => b.id === selected);
    const described = block ? contract.blockPropSchema[block.type] : null;

    function updateBody(update) {
        setSchema((s) => ({ ...s, zones: { ...s.zones, body: { ...s.zones.body, blocks: update(s.zones.body.blocks) } } }));
    }

    function addBlock(type) {
        const created = { id: crypto.randomUUID(), type, props: defaultsFor(contract.blockPropSchema[type]) };
        updateBody((list) => [...list, created]);
        setSelected(created.id);
    }

    function setProp(key, value) {
        updateBody((list) => list.map((b) => (b.id === selected ? { ...b, props: { ...b.props, [key]: value } } : b)));
    }

    async function save() {
        const { ok, data } = await send(`/letter-templates/${template.id}/schema`, 'PUT', { schema });
        ok ? setSchema(data.schema) : setErrors(data.errors ?? {});
    }

    return (
        <div className="row g-4">
            <aside className="col-3">
                <h6>Tambah blok</h6>
                {contract.blockTypes.map((t) => (
                    <button key={t.value} type="button" className="btn btn-sm btn-outline-primary m-1" onClick={() => addBlock(t.value)}>{t.label}</button>
                ))}
                <h6 className="mt-3">Urutan</h6>
                <ul className="list-group">
                    {blocks.map((b) => {
                        const known = contract.blockTypes.find((t) => t.value === b.type);

                        // Tipe blok dari versi paket yang lebih baru: tampilkan, jangan crash.
                        return (
                            <li key={b.id} className={`list-group-item ${b.id === selected ? 'active' : ''}`} onClick={() => setSelected(b.id)}>
                                {known?.label ?? `Blok tak dikenal: ${b.type}`}
                            </li>
                        );
                    })}
                </ul>
            </aside>

            <main className="col-6">
                <div className="d-flex justify-content-between mb-2">
                    <h5>{template.name}</h5>
                    <button type="button" className="btn btn-primary btn-sm" onClick={save}>Simpan</button>
                </div>
                {Object.keys(errors).length > 0 && (
                    <ul className="alert alert-danger">
                        {Object.entries(errors).map(([field, message]) => <li key={field}><code>{field}</code> — {message}</li>)}
                    </ul>
                )}
                <style ref={styleRef} />
                <div ref={canvasRef} />
            </main>

            <aside className="col-3">
                {described
                    ? Object.entries(described).map(([group, props]) => (
                        <fieldset key={group} className="mb-3">
                            <legend className="h6">{group}</legend>
                            {Object.entries(props).map(([key, definition]) => (
                                <Field key={key} name={key} definition={definition} value={block.props[key]} onChange={setProp} />
                            ))}
                        </fieldset>
                    ))
                    : <p className="text-muted">Pilih blok untuk menyunting.</p>}
            </aside>
        </div>
    );
}

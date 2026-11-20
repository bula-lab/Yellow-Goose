import { useState } from 'react';
import { DataTable, Dialog, Form } from '@yellow-goose/ui';
import { navigate } from '@yellow-goose/react/navigation';
import Frame from '../../components/Frame';
import { post } from '../../components/http';

export default function Materials({ csrfToken, listing, fields }) {
    const [editing, setEditing] = useState(null);
    const editable = editing?.id ? { title: fields.title, color: fields.color } : fields;
    async function save(values) {
        const updating = Boolean(editing?.id);
        const data = updating ? { ...values, id: editing.id, expectedRevision: editing.revision } : values;
        await post('/api/resources/materials/actions/' + (updating ? 'save' : 'create'), data, csrfToken);
        setEditing(null);
        await navigate('/editor/materials', { replace: true });
    }
    return <Frame editor csrfToken={csrfToken}><main><div className="heading"><h1>Materials</h1>
        <button onClick={() => setEditing({})}>Add material</button></div>
        <DataTable fields={fields} sortable={['id','title']} filterable={['title']} endpoint="/api/resources/materials" initialPage={listing}
            renderCell={(field, row) => field === 'title' ? <button onClick={() => setEditing(row)}>{row.title}</button> : row[field]} />
        <Dialog open={editing !== null} title={editing?.id ? 'Edit material' : 'New material'} onClose={() => setEditing(null)}>
            {editing && <Form key={editing.id ?? 'new'} fields={editable} initialValues={Object.fromEntries(Object.keys(editable).map(k => [k, editing[k] ?? '']))}
                onSubmit={save} submitLabel="Save material" />}
        </Dialog>
    </main></Frame>;
}

import { DataTable } from '@yellow-goose/ui/table';
import Frame from '../../components/Frame';

export default function Products({ csrfToken, listing, fields }) {
    const columns = { id: fields.id, title: fields.title, revision: { label: 'Revision', type: 'integer' },
        publishedRevision: { label: 'Published revision', type: 'integer' } };
    return <Frame editor csrfToken={csrfToken}><main><div className="heading"><h1>Products</h1>
        <a className="button" href="/editor/new">New product</a></div>
        <DataTable endpoint="/api/resources/products" fields={columns} sortable={['id','title']} filterable={['title']} initialPage={listing}
            renderCell={(field, row) => field === 'title' ? <a href={'/editor/products/' + encodeURIComponent(row.id)}>{row.title}</a>
                : (row[field] ?? 'Draft only')} />
    </main></Frame>;
}

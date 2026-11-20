import { createContext, useContext, useEffect, useState } from 'react';
import { navigate } from '@yellow-goose/react/navigation';
import { WorkspaceShell } from '@yellow-goose/react/workspace';
import { BabylonViewport } from '@yellow-goose/babylon';
import { Dialog, Form } from '@yellow-goose/ui';
import Frame from './Frame';
import { money, post } from './http';

const GarageConfiguration = createContext(null);

function OfferLayout({ product, configuration, preview, csrfToken, children }) {
    const [current, setCurrent] = useState(configuration);
    const [confirmed, setConfirmed] = useState(null);
    const [viewer, setViewer] = useState(null);
    function change(values) { setCurrent(values); setConfirmed(null); }
    return <Frame editor={preview} csrfToken={csrfToken}>
        <GarageConfiguration.Provider value={{ current, change, confirmed, setConfirmed, viewer }}>
            <WorkspaceShell className="offer-workspace" viewport={product.asset
                ? <BabylonViewport asset={product.asset} configuration={current ?? undefined} viewportRef={setViewer} />
                : <p>Choose a ready model and poster to preview this garage.</p>}>
                {children}
            </WorkspaceShell>
        </GarageConfiguration.Provider>
    </Frame>;
}

export default function Offer({ product, preview, panel, details, fields, exampleQuote, csrfToken }) {
    const { current, change, confirmed, setConfirmed, viewer } = useContext(GarageConfiguration);
    const [measured, setMeasured] = useState(null);
    useEffect(() => {
        if (details) setMeasured(viewer?.viewState().dimensionsMeters ?? null);
    }, [details, viewer, current]);
    const [enquiry, setEnquiry] = useState(null);
    const path = (preview ? '/preview/' : '/garages/') + encodeURIComponent(product.id);
    const panels = path + '?panel=' + panel;
    const api = '/api/garage/offers/' + encodeURIComponent(product.id);
    async function confirm(values) {
        const endpoint = preview ? '/api/garage/preview/' + encodeURIComponent(product.id) + '/quote' : api + '/confirm';
        const result = await post(endpoint, { expectedRevision: product.revision, ...values }, csrfToken);
        setEnquiry(null);
        setConfirmed(result);
    }
    async function enquire(values) {
        const result = await post(api + '/enquire', { confirmationId: confirmed.confirmationId, email: values.email }, csrfToken);
        setEnquiry(result.enquiryId);
    }
    return <main><h1>{product.title}</h1><p className="description">{product.description}</p>
        {preview && <p>Draft preview · <a href={'/editor/products/' + encodeURIComponent(product.id)}>Edit product</a></p>}
        {exampleQuote && <p className="example-price">Demo configuration <strong>{money(exampleQuote)}</strong></p>}
        <nav className="tabs" aria-label="Garage panels"><a aria-current={panel === 'configure' ? 'page' : undefined}
            href={path + '?panel=configure'}>Configure</a><a aria-current={panel === 'finishes' ? 'page' : undefined}
            href={path + '?panel=finishes'}>Finishes</a><a id="model-details" aria-haspopup="dialog" href={panels + '&dialog=details'}>Model details</a></nav>
        {panel === 'finishes' && <table><caption>Offered finishes</caption><thead><tr><th>Material</th><th>Color</th></tr></thead>
            <tbody>{product.materials.map(material => <tr key={material.id}><td>{material.title}</td><td>{material.color}</td></tr>)}</tbody></table>}
        {panel === 'configure' && exampleQuote && <>
            <Form fields={fields} initialValues={current} onChange={values => { change(values); setEnquiry(null); }}
                onSubmit={confirm} submitLabel={preview ? 'Estimate draft price' : 'Confirm configuration'} />
            <p>Dimensions in centimeters. The studio uses fictional demo prices in PLN.</p>
            {confirmed && <section aria-live="polite"><h2>{preview ? 'Draft estimate' : 'Confirmed configuration'}</h2>
                <p>{confirmed.configuration.widthMm / 10} × {confirmed.configuration.depthMm / 10} × {confirmed.configuration.heightMm / 10} cm
                    {' · '}{confirmed.configuration.color}{' · '}<strong>{money(confirmed)}</strong></p>
                {!preview && (enquiry ? <p role="status">Enquiry saved. Reference {enquiry}.</p> :
                    <Form fields={{ email: { type: 'string', required: true, label: 'Email', maxLength: 254 } }}
                        onSubmit={enquire} submitLabel="Save enquiry" />)}
            </section>}
        </>}
        <Dialog open={details} title="Model details" onClose={() => navigate(panels, { replace: true })}>
            {product.asset ? <dl><dt>Model version</dt><dd>{product.asset.assetVersionId}</dd><dt>Model size</dt><dd>{product.asset.bytes} bytes</dd>
                {measured && <><dt>Viewer dimensions (width × depth × height)</dt>
                    <dd>{[measured[0], measured[2], measured[1]].map(value => Number(value.toFixed(3))).join(' × ')} m</dd></>}
            </dl>
                : <p>No model selected.</p>}
        </Dialog>
    </main>;
}

Offer.getLayout = (page, props) => <OfferLayout key={[props.projectId, props.product.id, props.preview, props.product.revision].join('/')}
    {...props}>{page}</OfferLayout>;

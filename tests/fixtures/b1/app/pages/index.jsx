import { createContext, useContext, useRef, useState } from 'react';
import { WorkspaceShell, useWorkspace, useWorkspaceLeaveGuard } from '@yellow-goose/react/workspace';
import { navigate } from '@yellow-goose/react/navigation';
import { BabylonViewport } from '@yellow-goose/babylon';
import { Form, DataTable, Dialog } from '@yellow-goose/ui';

const Config = createContext(null);
const config = { widthMm: 3000, depthMm: 5000, heightMm: 2130, color: '#445566' };
const fields = { heightMm: { type: 'integer', label: 'Height', required: true, choices: [2000, 2130, 2400], unit: { label: 'cm', factor: 10 } } };
const tableFields = { name: { label: 'Name' } };
const capture = port => { window.fixturePort = port; };

function Layout({ asset, children }) {
    const [dirty, setDirty] = useState(false);
    const [prompt, setPrompt] = useState(false);
    const answer = useRef(null);
    useWorkspaceLeaveGuard({ dirty, contains: page => page.props?.projectId === asset.projectId,
        choose: ({ signal }) => new Promise(resolve => {
            const cancel = () => choose('cancel');
            function choose(value) { signal.removeEventListener('abort', cancel); setPrompt(false); resolve(value); }
            answer.current = choose; signal.addEventListener('abort', cancel, { once: true }); setPrompt(true);
        }),
        save: () => new Promise(resolve => { window.fixtureFinishSave = () => { setDirty(false); resolve(); }; }),
    });
    const [configuration, setConfiguration] = useState(config);
    return <Config.Provider value={setConfiguration}><WorkspaceShell viewport={<BabylonViewport asset={asset}
        configuration={configuration} viewportRef={capture} onSelect={id => { window.fixtureSelection = id; }} />}>
        {children}<button id="dirty" onClick={() => setDirty(true)}>Mark draft dirty</button>
        <Dialog open={prompt} title="Unsaved draft" onClose={() => answer.current('cancel')}>
            {['save','discard','cancel'].map(choice => <button key={choice} onClick={() => answer.current(choice)}>{choice}</button>)}
        </Dialog></WorkspaceShell></Config.Provider>;
}

export default function TestPage({ projectId, modal, csrfToken }) {
    const scope = useWorkspace();
    const setConfig = useContext(Config);
    return <main><h1>Project {projectId}</h1>
        <a id="opener" aria-haspopup="dialog" href={'/?project=' + projectId + '&dialog=1'}>Inspect</a>{' '}
        <a href={'/?project=' + (projectId === 'a' ? 'b' : 'a')}>Switch project</a>
        <Form fields={fields} initialValues={config} action="/api/config" csrfToken={csrfToken} submitLabel="Confirm"
            onChange={values => setConfig({ ...config, ...values })} onSuccess={r => { window.confirmed = r; }} />
        <DataTable fields={tableFields} filterable={['name']} sortable={['name']} endpoint="/api/table" state={scope.state} limit={1} />
        <Dialog open={modal} title="Inspect model" onClose={() => navigate('/?project=' + projectId)}>
            <label>Note<input /></label>
        </Dialog><div style={{ height: 1200 }} /></main>;
}
TestPage.getLayout = (page, props) => <Layout key={props.projectId} asset={props.asset}>{page}</Layout>;

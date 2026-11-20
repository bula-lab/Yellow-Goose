import { modelBytes } from './modelBytes.js';
import { Engine } from '@babylonjs/core/Engines/engine.js';
import { Scene } from '@babylonjs/core/scene.js';
import { Quaternion, Vector3, Matrix } from '@babylonjs/core/Maths/math.vector.js';
import { Color3, Color4 } from '@babylonjs/core/Maths/math.color.js';
import { ArcRotateCamera } from '@babylonjs/core/Cameras/arcRotateCamera.js';
import { HemisphericLight } from '@babylonjs/core/Lights/hemisphericLight.js';
import { TransformNode } from '@babylonjs/core/Meshes/transformNode.js';
import { CreateBox } from '@babylonjs/core/Meshes/Builders/boxBuilder.js';
import { CreateLineSystem } from '@babylonjs/core/Meshes/Builders/linesBuilder.js';
import { GizmoManager } from '@babylonjs/core/Gizmos/gizmoManager.js';
import { PointerEventTypes } from '@babylonjs/core/Events/pointerEvents.js';
import { LoadAssetContainerAsync } from '@babylonjs/core/Loading/sceneLoader.js';
import '@babylonjs/loaders/glTF/2.0/glTFLoader.js';
import '@babylonjs/core/Materials/standardMaterial.js';
import '@babylonjs/core/Rendering/outlineRenderer.js';
import '@babylonjs/core/Culling/ray.js';

const step = .25;
const gizmoNames = { translate: 'positionGizmo', rotate: 'rotationGizmo', scale: 'scaleGizmo' };
const round = value => Math.round(value * 1e6) / 1e6;
const readTransform = node => ({ position: node.position.asArray(), rotation: node.rotationQuaternion.asArray(), scale: node.scaling.asArray() });

function modelBounds(meshes) {
    let min, max;
    for (const mesh of meshes) {
        mesh.computeWorldMatrix(true);
        const box = mesh.getBoundingInfo().boundingBox;
        if (!min) { min = box.minimumWorld.clone(); max = box.maximumWorld.clone(); }
        else { min.minimizeInPlace(box.minimumWorld); max.maximizeInPlace(box.maximumWorld); }
    }
    return min ? { min, max } : null;
}

function gridLines(scene) {
    const lines = [];
    for (let i = -20; i <= 20; ++i) {
        const p = i * step;
        lines.push([new Vector3(p, 0, -5), new Vector3(p, 0, 5)], [new Vector3(-5, 0, p), new Vector3(5, 0, p)]);
    }
    const mesh = CreateLineSystem('grid', { lines }, scene);
    mesh.color = new Color3(.63, .68, .7); mesh.isPickable = false; mesh.setEnabled(false);
    return mesh;
}

async function loadContainer(projectId, id, descriptor, resolveAsset, scene, signal) {
    const bytes = await modelBytes(projectId, id, descriptor, resolveAsset, signal);
    signal.throwIfAborted();
    const container = await LoadAssetContainerAsync(bytes, scene, { pluginExtension: '.glb', name: 'asset.glb' });
    if (signal.aborted) { container.dispose(); signal.throwIfAborted(); }
    return container;
}

export function mount(canvas, { onStatus, onSelect, onTransform, resolveAsset } = {}) {
    const engine = new Engine(canvas, true, { stencil: true, loseContextOnDispose: true });
    const scene = new Scene(engine);
    scene.useRightHandedSystem = true;
    scene.clearColor = new Color4(.91, .94, .95, 1);
    const camera = new ArcRotateCamera('editor', -Math.PI / 3, Math.PI / 3, 6, Vector3.Zero(), scene);
    camera.minZ = .01; camera.lowerRadiusLimit = .1; camera.wheelPrecision = 45; camera.attachControl(canvas, true);
    new HemisphericLight('sky', Vector3.Up(), scene).intensity = 1.3;
    const grid = gridLines(scene), gizmos = new GizmoManager(scene);
    gizmos.usePointerToAttachGizmos = false;
    const records = new Map(), assets = new Map(), watched = new Set();
    let selected = null, tool = null, snap = false, dragging = null;
    let active = true, lost = false, visible = true, running = false, framed = false;
    let status;
    function updateStatus() {
        if (!active) return;
        const entries = [...records.values()];
        const next = lost ? 'lost' : entries.some(record => record.failed) ? 'error'
            : entries.some(record => !record.content) ? 'loading' : 'ready';
        if (next !== status) { status = next; onStatus?.(next); }
        if (!framed && next === 'ready' && entries.some(record => record.meshes.length && record.node.isEnabled())) {
            focus(null); framed = true;
        }
    }
    const render = () => scene.render();
    function visibility() {
        const next = active && visible && !document.hidden && !lost;
        if (running === next) return;
        running = next;
        if (running) engine.runRenderLoop(render); else engine.stopRenderLoop(render);
    }
    const resize = new ResizeObserver(() => { if (active) engine.resize(); }); resize.observe(canvas);
    const intersection = new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; visibility(); }); intersection.observe(canvas);
    document.addEventListener('visibilitychange', visibility);
    engine.onContextLostObservable.add(() => { lost = true; dragging = null; updateStatus(); visibility(); });
    engine.onContextRestoredObservable.add(() => { lost = false; updateStatus(); visibility(); });
    visibility();

    function focus(id = selected) {
        const list = id ? [records.get(id)].filter(Boolean) : [...records.values()].filter(record => record.node.isEnabled());
        const bounds = modelBounds(list.flatMap(record => record.meshes));
        if (!bounds) return;
        camera.setTarget(bounds.min.add(bounds.max).scale(.5));
        camera.radius = Math.max(.5, bounds.max.subtract(bounds.min).length() * 1.5);
    }
    function attachGizmo() {
        const record = records.get(selected);
        const target = record?.node.isEnabled() && tool ? record.node : null;
        if (gizmos.attachedNode !== target) gizmos.attachToNode(target);
    }
    function highlight(record) {
        for (const mesh of record.meshes) { mesh.renderOverlay = record.entity.entityId === selected; mesh.overlayColor = new Color3(1, .65, .09); }
    }
    function select(id) { selected = records.has(id) ? id : null; for (const record of records.values()) highlight(record); attachGizmo(); }
    scene.onPointerObservable.add(event => {
        if (event.type !== PointerEventTypes.POINTERPICK || dragging || gizmos.isHovered) return;
        const id = event.pickInfo?.pickedMesh?.metadata?.ygEntityId ?? null;
        onSelect?.(id);
    });
    function writeTransform(record) {
        const { transform } = record.entity;
        record.node.position.copyFromFloats(...transform.position);
        record.node.rotationQuaternion.copyFromFloats(...transform.rotation);
        record.node.scaling.copyFromFloats(...transform.scale);
    }
    function dragEnd(mode) {
        const record = dragging; dragging = null;
        if (!record || !active || records.get(record.entity.entityId) !== record) return;
        const key = { translate: 'position', rotate: 'rotation', scale: 'scale' }[mode];
        const values = readTransform(record.node)[key].map(round);
        if (mode === 'translate' && snap) values.forEach((value, i) => { values[i] = Math.round(value / step) * step; });
        if (mode === 'rotate') { const length = Math.hypot(...values); values.forEach((value, i) => { values[i] = value / length; }); }
        writeTransform(record);
        if (values.every(Number.isFinite) && (mode !== 'scale' || values.every(value => value > 0))
            && values.some((value, index) => Math.abs(value - record.entity.transform[key][index]) > 1e-6)) {
            onTransform?.(record.entity.entityId, { [key]: values });
        }
    }
    function setTool(mode, useSnap) {
        tool = mode; snap = Boolean(useSnap); grid.setEnabled(snap);
        for (const [name, key] of Object.entries(gizmoNames)) {
            gizmos[key + 'Enabled'] = name === mode;
            const gizmo = gizmos.gizmos[key];
            if (!gizmo) continue;
            if (!watched.has(gizmo)) {
                watched.add(gizmo);
                gizmo.onDragStartObservable.add(() => { dragging = records.get(selected) ?? null; });
                gizmo.onDragEndObservable.add(() => dragEnd(name));
            }
            if (name === 'scale') gizmo.incrementalSnap = true;
            gizmo.snapDistance = snap ? { translate: step, rotate: Math.PI / 12, scale: .1 }[name] : 0;
        }
        attachGizmo();
    }
    function sizeContent(record) {
        const height = record.entity.type === 'garage' ? record.entity.properties.heightMm : null;
        if (record.content) record.content.scaling.y = height > 0 && record.sourceHeight > 0 ? height / 1000 / record.sourceHeight : 1;
    }
    function install(record, container) {
        const id = record.entity.entityId;
        const content = record.content = new TransformNode(id + '/geometry', scene);
        if (container) {
            // Mesh clones have independent selection overlays and retain the source geometry and material.
            record.instances = container.instantiateModelsToScene(name => id + '/' + name, false, { doNotInstantiate: true });
            for (const root of record.instances.rootNodes) root.parent = content;
        } else {
            CreateBox(id + '/box', { size: 1 }, scene).parent = content;
        }
        record.meshes = content.getChildMeshes().filter(mesh => mesh.getTotalVertices() > 0);
        for (const mesh of record.meshes) mesh.metadata = { ...mesh.metadata, ygEntityId: id };
        const bounds = modelBounds(record.meshes);
        record.sourceHeight = bounds ? bounds.max.y - bounds.min.y : 0;
        content.parent = record.node; sizeContent(record); highlight(record);
    }
    function clearContent(record) {
        record.instances?.dispose(); record.content?.dispose();
        record.instances = null; record.content = null; record.meshes = []; record.failed = false;
    }
    function discardAsset(entry) { entry.controller.abort(); entry.container?.dispose(); }
    function ensureAsset(projectId, id, descriptor) {
        let entry = assets.get(id);
        if (entry) return entry;
        entry = { controller: new AbortController(), container: null, failed: false };
        assets.set(id, entry);
        loadContainer(projectId, id, descriptor, resolveAsset, scene, entry.controller.signal).then(container => {
            if (!active || assets.get(id) !== entry) { container.dispose(); return; }
            entry.container = container;
            for (const record of records.values()) if (record.entity.assetVersionId === id && !record.content) install(record, container);
            updateStatus();
        }).catch(() => {
            if (!active || entry.controller.signal.aborted) return;
            entry.failed = true;
            for (const record of records.values()) if (record.entity.assetVersionId === id) record.failed = true;
            updateStatus();
        });
        return entry;
    }
    function setDocument(doc, descriptors) {
        if (!active) return;
        const ids = new Set(doc.entities.map(entity => entity.entityId));
        const neededAssets = new Set(doc.entities.map(entity => entity.assetVersionId).filter(Boolean));
        const byVersion = new Map(descriptors.map(asset => [asset.assetVersionId, asset]));
        // A fresh snapshot can reverse a parent/child relationship in one update.
        // Release changed edges first so Babylon never observes an intermediate cycle.
        for (const entity of doc.entities) {
            const existing = records.get(entity.entityId);
            if (existing?.node.parent && existing.entity.parentId !== entity.parentId) existing.node.parent = null;
        }
        for (const [id, record] of records) {
            if (ids.has(id)) continue;
            if (dragging === record) dragging = null;
            if (selected === id) { selected = null; attachGizmo(); }
            clearContent(record); record.node.dispose(true); records.delete(id);
        }
        for (const entity of doc.entities) {
            let record = records.get(entity.entityId);
            if (!record) {
                const node = new TransformNode(entity.entityId, scene);
                node.rotationQuaternion = Quaternion.Identity();
                record = { node, content: null, meshes: [], entity };
                records.set(entity.entityId, record);
            } else if (entity.assetVersionId !== record.entity.assetVersionId) clearContent(record);
            record.entity = entity;
            if (dragging !== record) writeTransform(record);
            record.node.setEnabled(entity.visible);
        }
        for (const record of records.values()) {
            const { parentId, assetVersionId } = record.entity;
            record.node.parent = parentId ? records.get(parentId).node : null;
            if (record.content) { sizeContent(record); continue; }
            if (!assetVersionId) { install(record, null); continue; }
            const descriptor = byVersion.get(assetVersionId);
            const entry = ensureAsset(doc.projectId, assetVersionId, descriptor);
            record.failed = entry.failed;
            if (entry.container) install(record, entry.container);
        }
        for (const [id, entry] of assets) if (!neededAssets.has(id)) { assets.delete(id); discardAsset(entry); }
        attachGizmo(); updateStatus();
    }
    return { setDocument, select, setTool, focus,
        viewState() {
            const viewport = camera.viewport.toGlobal(engine.getRenderWidth(), engine.getRenderHeight());
            return { entityIds: [...records.keys()], selected, assetVersionIds: [...assets.keys()], rightHanded: scene.useRightHandedSystem, running,
                camera: { alpha: camera.alpha, beta: camera.beta, radius: camera.radius, target: camera.target.asArray() },
                entities: [...records.values()].map(record => {
                    const bounds = modelBounds(record.meshes);
                    const screen = bounds && Vector3.Project(bounds.min.add(bounds.max).scale(.5), Matrix.Identity(), scene.getTransformMatrix(), viewport);
                    record.node.computeWorldMatrix(true);
                    return { entityId: record.entity.entityId, parentId: record.entity.parentId, assetVersionId: record.entity.assetVersionId,
                        visible: record.node.isEnabled(), transform: readTransform(record.node), worldPosition: record.node.getAbsolutePosition().asArray(),
                        dimensionsMeters: bounds ? bounds.max.subtract(bounds.min).asArray() : null, geometryIds: record.meshes.map(mesh => mesh.geometry?.uniqueId),
                        screenCenter: screen ? [screen.x / engine.getRenderWidth(), screen.y / engine.getRenderHeight()] : null };
                }) };
        },
        dispose() {
            if (!active) return;
            active = false; visibility(); resize.disconnect(); intersection.disconnect(); document.removeEventListener('visibilitychange', visibility);
            dragging = null; gizmos.dispose(); camera.detachControl();
            for (const record of records.values()) clearContent(record);
            for (const entry of assets.values()) discardAsset(entry);
            records.clear(); assets.clear(); scene.dispose(); engine.dispose();
        },
    };
}

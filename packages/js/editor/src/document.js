export const identityTransform = () => ({ position: [0, 0, 0], rotation: [0, 0, 0, 1], scale: [1, 1, 1] });

function object(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function keys(value, allowed) {
    if (!object(value) || Object.keys(value).some(key => !allowed.includes(key))) throw new Error('Unexpected scene field.');
}
export function validId(value) {
    return typeof value === 'string' && /^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/.test(value) && !['constructor', 'prototype'].includes(value);
}
function utf8Length(value) {
    let length = 0;
    // Bare V8Js SSR does not provide TextEncoder.
    for (const char of value) {
        const code = char.codePointAt(0);
        if (code >= 0xd800 && code <= 0xdfff) return Infinity;
        length += code < 128 ? 1 : code < 2048 ? 2 : code < 65536 ? 3 : 4;
    }
    return length;
}
function name(value) {
    if (typeof value !== 'string' || !value.replace(/ /g, '') || /[\x00-\x1f\x7f]/.test(value) || utf8Length(value) > 160) {
        throw new Error('Enter a name within 160 UTF-8 bytes, without control characters.');
    }
    return value.replace(/^ +| +$/g, '');
}
function properties(value) {
    if (!object(value) || Object.keys(value).length > 32) throw new Error('Use up to 32 scalar properties.');
    for (const [key, item] of Object.entries(value)) {
        if (!/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/.test(key) || ['constructor', 'prototype'].includes(key)) throw new Error('Invalid property name.');
        if (item !== null && !['number', 'boolean', 'string'].includes(typeof item)) throw new Error('Each property must contain a scalar value.');
        if (typeof item === 'string' && utf8Length(item) > 512) throw new Error('Property text must fit in 512 UTF-8 bytes.');
        if (typeof item === 'number' && (!Number.isFinite(item) || Math.abs(item) > 1e9)) throw new Error('Property number out of range.');
        if (key === 'heightMm' && (!Number.isInteger(item) || item < 1 || item > 10000000)) throw new Error('Height must be 1 to 10000000 millimetres.');
    }
    return { ...value };
}
function transform(input, previous) {
    keys(input, ['position', 'rotation', 'scale']);
    const next = { ...previous };
    for (const [key, vector] of Object.entries(input)) {
        if (!Array.isArray(vector) || vector.length !== (key === 'rotation' ? 4 : 3)
            || Array.from(vector).some(value => !Number.isFinite(value) || Math.abs(value) > 1000000)) throw new Error(`Invalid ${key} component.`);
        if (key === 'scale' && vector.some(value => value < .000001)) throw new Error('Scale must be positive and at least 0.000001.');
        const length = key === 'rotation' ? Math.hypot(...vector) : 1;
        if (length < .000001) throw new Error('Rotation quaternion is too small.');
        next[key] = Math.abs(length - 1) > 1e-12 ? vector.map(value => value / length) : [...vector];
    }
    return next;
}
const editable = ['name', 'parentId', 'visible', 'assetVersionId', 'properties'];
function entity(entityId, input) {
    keys(input, [...editable, 'type', 'transform']);
    const fields = { name: entityId, parentId: null, visible: true, assetVersionId: null, properties: {}, transform: {}, ...input };
    if (!validId(entityId) || typeof fields.type !== 'string' || !/^[a-z][a-z0-9.-]{0,39}$/.test(fields.type)
        || typeof fields.visible !== 'boolean' || (fields.parentId !== null && !validId(fields.parentId))
        || (fields.assetVersionId !== null && !validId(fields.assetVersionId))) throw new Error('Invalid scene entity.');
    return { ...fields, entityId, name: name(fields.name), transform: transform(fields.transform, identityTransform()), properties: properties(fields.properties) };
}
export function checkDocument(document, previous) {
    const coords = document?.coordinateSystem;
    if (document?.schemaVersion !== 1 || coords?.handedness !== 'right' || coords.up !== 'Y' || coords.unit !== 'm'
        || !Number.isSafeInteger(document.revision) || document.revision < 0 || !Array.isArray(document.entities) || document.entities.length > 512
        || !validId(document.projectId) || !validId(document.sceneId) || (previous && (previous.projectId !== document.projectId || previous.sceneId !== document.sceneId))) {
        throw new Error('Unsupported scene document.');
    }
    const entries = document.entities.map(({ entityId, ...fields }) => entity(entityId, fields));
    if (new Set(entries.map(item => item.entityId)).size !== entries.length || entries.some(item => !validParent(entries, item.entityId, item.parentId))) {
        throw new Error('Invalid entity hierarchy.');
    }
    return { ...document, name: name(document.name), entities: entries };
}

export function applyChanges(document, changes, revision = document.revision) {
    const entities = new Map(document.entities.map(entity => [entity.entityId, entity]));
    for (const id of changes.remove) entities.delete(id);
    for (const entity of changes.upsert) entities.set(entity.entityId, entity);
    return { ...document, entities: [...entities.values()], revision };
}

export function validParent(entities, id, parentId) {
    if (parentId === null) return true;
    const byId = new Map(entities.map(entity => [entity.entityId, entity]));
    const seen = new Set([id]);
    while (parentId != null) {
        if (seen.has(parentId) || !byId.has(parentId)) return false;
        seen.add(parentId);
        parentId = byId.get(parentId).parentId;
    }
    return true;
}

export function projectCommand(document, { type, entityId, payload }) {
    const current = document.entities.find(item => item.entityId === entityId);
    let next;
    if (type === 'entity.add' || type === 'entity.duplicate') {
        if (current || document.entities.length >= 512) throw new Error('Entity exists or scene is full.');
        if (type === 'entity.add') next = entity(entityId, payload);
        else {
            keys(payload, ['sourceId', 'name', 'transform']);
            const source = document.entities.find(item => item.entityId === payload.sourceId);
            if (!source) throw new Error('The source entity no longer exists.');
            const { entityId: sourceId, ...fields } = source;
            next = entity(entityId, { ...fields, name: 'name' in payload ? payload.name : source.name,
                transform: 'transform' in payload ? transform(payload.transform, source.transform) : source.transform });
        }
    } else {
        if (!current) throw new Error('The entity no longer exists.');
        if (!object(payload) || (!Object.keys(payload).length && type !== 'entity.remove')) throw new Error('Missing command fields.');
        switch (type) {
        case 'entity.remove':
            keys(payload, []);
            if (document.entities.some(item => item.parentId === entityId)) throw new Error('Move or delete the children first.');
            return applyChanges(document, { upsert: [], remove: [entityId] });
        case 'entity.transform': next = { ...current, transform: transform(payload, current.transform) }; break;
        case 'entity.update': {
            keys(payload, editable);
            const { entityId: id, ...fields } = current;
            next = entity(id, { ...fields, ...payload }); break;
        }
        default: throw new Error('Unknown scene command.');
        }
    }
    if (!validParent(document.entities, entityId, next.parentId)) throw new Error('Choose a parent outside the entity’s descendants in this scene.');
    return applyChanges(document, { upsert: [next], remove: [] });
}

// Intrinsic XYZ Euler angles in degrees are only used in the inspector.
export function quaternionFromDegrees(angles) {
    const [x, y, z] = angles.map(value => value * Math.PI / 360);
    const [a, b, c] = [x, y, z].map(Math.cos);
    const [i, j, k] = [x, y, z].map(Math.sin);
    return [i*b*c + a*j*k, a*j*c - i*b*k, a*b*k + i*j*c, a*b*c - i*j*k];
}

export function degreesFromQuaternion([x, y, z, w]) {
    const sinY = Math.max(-1, Math.min(1, 2 * (w*y + x*z)));
    const angles = Math.abs(sinY) < .9999999
        ? [Math.atan2(2*(w*x-y*z), 1-2*(x*x+y*y)), Math.asin(sinY), Math.atan2(2*(w*z-x*y), 1-2*(y*y+z*z))]
        : [Math.atan2(2*(w*x+y*z), 1-2*(x*x+z*z)), Math.asin(sinY), 0];
    return angles.map(value => Number((value * 180 / Math.PI).toFixed(6)));
}

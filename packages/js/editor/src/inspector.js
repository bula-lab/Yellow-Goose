import { degreesFromQuaternion, quaternionFromDegrees } from './document.js';

const axes = ['X', 'Y', 'Z'];
export const fields = [
    ...axes.map((axis, index) => ({ key: 'position' + index, label: `Position ${axis} (m)` })),
    ...axes.map((axis, index) => ({ key: 'rotation' + index, label: `Rotation ${axis} (°)` })),
    ...axes.map((axis, index) => ({ key: 'scale' + index, label: `Scale ${axis}` })),
];

export function inspectorValues(entity) {
    const values = { name: entity.name, parentId: entity.parentId ?? '', height: (entity.properties.heightMm ?? 1000) / 10 };
    const { position, rotation, scale } = entity.transform;
    for (const [key, vector] of Object.entries({ position, rotation: degreesFromQuaternion(rotation), scale })) {
        vector.forEach((value, index) => { values[key + index] = value; });
    }
    return values;
}

export function inspectorCommands(entity, edits) {
    const values = { ...inspectorValues(entity), ...edits };
    const update = {}, transform = {};
    const number = key => {
        if (String(values[key]).trim() === '' || !Number.isFinite(Number(values[key]))) throw new Error('Enter all numeric values.');
        return Number(values[key]);
    };
    if ('name' in edits) update.name = values.name;
    if ('parentId' in edits) update.parentId = values.parentId || null;
    if ('height' in edits) {
        const height = number('height') * 10;
        if (Math.abs(height - Math.round(height)) > 1e-8) throw new Error('Enter height to the nearest millimetre.');
        update.properties = { ...entity.properties, heightMm: Math.round(height) };
    }
    for (const key of ['position', 'rotation', 'scale']) {
        if (axes.some((_, index) => key + index in edits)) {
            const vector = axes.map((_, index) => number(key + index));
            transform[key] = key === 'rotation' ? quaternionFromDegrees(vector) : vector;
        }
    }
    return [['entity.update', update], ['entity.transform', transform]].filter(([, payload]) => Object.keys(payload).length)
        .map(([type, payload]) => ({ type, payload, entityId: entity.entityId }));
}

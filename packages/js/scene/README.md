# @yellow-goose/scene

`Viewport` renders the same poster in PHP SSR and during hydration. The poster fits within the same 16:9 surface as the canvas, including square and portrait images. It imports a supplied browser adapter after mount. Use `asset` for a ready version descriptor and `loadAdapter` for a stable function that returns a module with `mount(canvas, {onStatus,onSelect})`.

The mounted port exposes `loadAsset(descriptor, signal)`, `select(id)`, `focus(id)`, `applyChanges(configuration)`, `capturePreview()` and `dispose()`. `viewportRef` is optional. Changes to `selectedId` update selection, and `onSelect(id)` receives user picks. Include the project in the persistent layout key. Rotating URLs for the same immutable version preserves the loaded scene and camera.

A ready model displays its canvas after loading. Errors and lost contexts display the poster and a status message. The error view can retry initialization. Disposal cancels in-flight loading.

## Multi-entity scenes

`@yellow-goose/scene/multi` exports `SceneViewport`. Pass `document`, `assets`, a stable `loadAdapter`, `selectedId`, `tool` (`translate`, `rotate`, `scale` or `null`) and `snap`. The component renders a poster during SSR and imports the adapter after hydration. `onSelect(entityId)` receives a pick and `onTransform(entityId, partialTransform)` receives the committed gesture.

The adapter's `mount(canvas, { onStatus, onSelect, onTransform, resolveAsset })` returns a port with `setDocument(document, assets)`, `select(id)`, `setTool(mode, snap)`, `focus(id)` and `dispose()`. `viewportRef` exposes the port for the mounted workspace. The optional asset resolver receives the project ID, immutable version ID and an abort signal. Adapter status is `loading`, `ready`, `lost` or `error`.

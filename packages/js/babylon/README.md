# @yellow-goose/babylon

`BabylonViewport` implements the scene port using Babylon.js 8.26.0 and its glTF 2.0 loader. Import the component from `@yellow-goose/babylon`. The engine and its shaders are lazy browser chunks. `frameworkConfig` replaces `@yellow-goose/babylon/engine` with the SSR stub when building for PHP V8Js.

The scene uses right-handed coordinates, metres and Y up. Optional configuration accepts `widthMm`, `heightMm`, `depthMm` and `color` in #rrggbb format. Dimensions scale the original world bounds along X, Y and Z respectively. For input height 2130 mm the configured geometry measures 2.13 m. A flat source axis retains its original scale. Camera state belongs to the mounted viewport.

A version-scoped mesh ID drives pointer selection and focus. `capturePreview()` resolves to a PNG Blob. `viewState()` returns the camera, selected IDs, right-handed flag, animation status and measured world dimensions in metres. Context restoration uses Babylon's resources, and the render loop pauses when the canvas is offscreen or the document is hidden.

## Multi-entity editor

`@yellow-goose/babylon/multi` exports `BabylonSceneViewport`. Each entity owns a transform node in its parent's local space. Instances of one immutable asset use one downloaded container, shared mesh geometry and materials, and independent transform and selection state. Removing the last reference releases the asset. A garage without a model uses a unit box with its height derived from `properties.heightMm`. For a garage with a model, the same field scales the model's original local height before applying the entity transform.

The adapter resolves an absent or expired descriptor through its `resolveAsset` callback. HTTP 401, 403 or 404 during a model download also triggers one descriptor refresh. The optional port's `viewState()` reports local transforms, world positions, visible bounds in metres, projected screen centres and shared geometry IDs. `setTool` draws the selected entity's translate, rotate or scale gizmo. Turning on snap displays a 0.25 m ground grid.

# @yellow-goose/babylon

`BabylonViewport` implements the scene port using Babylon.js 8.26.0 and its glTF 2.0 loader. Import the component from `@yellow-goose/babylon`. The engine and its shaders are lazy browser chunks. `frameworkConfig` replaces `@yellow-goose/babylon/engine` with the SSR stub when building for PHP V8Js.

The scene uses right-handed coordinates, metres and Y up. Optional configuration accepts `widthMm`, `heightMm`, `depthMm` and `color` in #rrggbb format. Dimensions scale the original world bounds along X, Y and Z respectively. For input height 2130 mm the configured geometry measures 2.13 m. A flat source axis retains its original scale. Camera state belongs to the mounted viewport.

A version-scoped mesh ID drives pointer selection and focus. `capturePreview()` resolves to a PNG Blob. `viewState()` returns the camera, selected IDs, right-handed flag, animation status and measured world dimensions in metres. Context restoration uses Babylon's resources, and the render loop pauses when the canvas is offscreen or the document is hidden.

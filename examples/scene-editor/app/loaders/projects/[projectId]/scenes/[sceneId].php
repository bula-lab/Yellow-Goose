<?php
return static fn ($request, $params, $site) => $site->scene($request, $params['projectId'], $params['sceneId']);

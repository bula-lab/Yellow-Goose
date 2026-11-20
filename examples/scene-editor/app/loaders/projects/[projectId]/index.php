<?php
return static fn ($request, $params, $site) => $site->project($request, $params['projectId']);

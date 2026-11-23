<?php
return static fn ($request, $params, $site) => $site->game($request, $params['id']);

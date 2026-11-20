<?php
return fn ($request, $params, Garage\Site $site) => $site->edit($request, $params['id']);

<?php
return fn ($request, $params, Garage\Site $site) => $site->offer($request, $params['id'], false);
